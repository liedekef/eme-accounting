<?php
namespace EMEAccounting;

defined( 'ABSPATH' ) || exit;

use EMEAccounting\Capabilities;

/**
 * Bank statement import (CSV / CODA) and reconciliation.
 *
 * Supported formats:
 *  - Generic CSV  (configurable column mapping)
 *  - BNP Paribas Fortis CSV export
 *  - KBC CSV export
 *  - ING CSV export
 *  - Belfius CSV export
 *  - CODA v2 (raw structured Belgian bank format)
 */
class Import {

    public static function init(): void {
        add_action( 'wp_ajax_eme_accounting_import_upload',         [ __CLASS__, 'ajax_upload' ] );
        add_action( 'wp_ajax_eme_accounting_reconcile_line',        [ __CLASS__, 'ajax_reconcile_line' ] );
        add_action( 'wp_ajax_eme_accounting_ignore_line',           [ __CLASS__, 'ajax_ignore_line' ] );
        add_action( 'wp_ajax_eme_accounting_auto_reconcile',        [ __CLASS__, 'ajax_auto_reconcile' ] );
        add_action( 'wp_ajax_eme_accounting_create_entry_from_line',[ __CLASS__, 'ajax_create_from_line' ] );
        add_action( 'wp_ajax_eme_accounting_delete_import_log',    [ __CLASS__, 'ajax_delete_log' ] );
        add_action( 'wp_ajax_eme_accounting_list_import_lines',    [ __CLASS__, 'ajax_list_lines' ] );
        add_action( 'wp_ajax_eme_accounting_list_import_logs',     [ __CLASS__, 'ajax_list_logs' ] );
        add_action( 'wp_ajax_eme_accounting_get_logline_stats',    [ __CLASS__, 'ajax_get_logline_stats' ] );
        add_action( 'wp_ajax_eme_accounting_bulk_action_lines',     [ __CLASS__, 'ajax_bulk_action_lines' ] );
    }

    /**
     * Bulk action on import lines.
     * POST params:
     *   action_type  : 'ignore' | 'create'
     *   line_ids     : comma-separated import line IDs
     *   coa_id       : required for action_type 'create'
     */
    public static function ajax_bulk_action_lines(): void {
        check_ajax_referer( 'eme_accounting_nonce', 'nonce' );
        Capabilities::require_manage_ajax( 'import' );
        global $wpdb;

        $action_type = eme_sanitize_request( $_POST['action_type'] ?? '' );
        $coa_id      = (int) ( $_POST['coa_id'] ?? 0 );
        $eme_event_id= (int) ( $_POST['eme_event_id'] ?? 0 );
        $raw_ids     = eme_sanitize_request( $_POST['line_ids'] ?? '' );

        if ( ! in_array( $action_type, [ 'ignore', 'create' ], true ) ) {
            wp_send_json_error( 'Ongeldige actie.' );
        }

        // Parse and validate IDs (integers only)
        $line_ids = array_filter(
            array_map( 'intval', explode( ',', $raw_ids ) ),
            fn( $id ) => $id > 0
        );
        if ( empty( $line_ids ) ) {
            wp_send_json_error( 'Geen geldige regels opgegeven.' );
        }
        if ( $action_type === 'create' && ! $coa_id ) {
            wp_send_json_error( 'Grootboekrekening vereist voor nieuwe boeking.' );
        }

        $records   = [];
        $processed = 0;
        $errors    = [];

        foreach ( $line_ids as $line_id ) {
            $line = $wpdb->get_row( $wpdb->prepare(
                "SELECT * FROM `{$wpdb->prefix}emeacc_import_lines` WHERE id = %d AND status = 'pending'",
                $line_id
            ) );
            if ( ! $line ) continue; // skip non-pending or not found

            if ( $action_type === 'ignore' ) {
                $wpdb->update(
                    $wpdb->prefix . 'emeacc_import_lines',
                    [ 'status' => 'ignored' ],
                    [ 'id' => $line_id ],
                    [ '%s' ], [ '%d' ]
                );
                $records[] = self::get_line( $line_id );
                $processed++;

            } elseif ( $action_type === 'create' ) {
                $journal_id = Journal::save( [
                    'entry_date'        => $line->transaction_date,
                    'booking_date'      => $line->value_date,
                    'description'       => $line->description,
                    'amount'            => abs( (float) $line->amount ),
                    'direction'         => (float) $line->amount >= 0 ? 'in' : 'out',
                    'account_id'        => $line->account_id,
                    'bank_statement_ref'=> $line->bank_ref ?: $line->structured_ref,
                    'counterparty_name' => $line->counterparty_name ?? '',
                    'eme_event_id'      => $eme_event_id,
                    'import_line_id'    => $line->id,
                    'is_reconciled'     => 1,
                    'coa_id'            => $coa_id,
                ] );
                if ( is_wp_error( $journal_id ) ) {
                    $errors[] = sprintf( 'Regel #%d: %s', $line_id, $journal_id->get_error_message() );
                    continue;
                }
                $wpdb->update(
                    $wpdb->prefix . 'emeacc_import_lines',
                    [ 'status' => 'manual', 'journal_id' => $journal_id ],
                    [ 'id' => $line_id ],
                    [ '%s', '%d' ], [ '%d' ]
                );
                $records[] = self::get_line( $line_id );
                $processed++;
            }
        }

        $message = sprintf(
            _n( '%d regel verwerkt.', '%d regels verwerkt.', $processed, 'eme-accounting' ),
            $processed
        );
        if ( ! empty( $errors ) ) {
            $message .= ' ' . implode( ' ', $errors );
        }

        wp_send_json_success( [
            'processed' => $processed,
            'records'   => $records,
            'message'   => $message,
        ] );
    }

    /**
     * Lightweight status counters for a single import log.
     * Used by refreshLogCounters() in import.js after each reconciliation action.
     */
    public static function ajax_get_logline_stats(): void {
        check_ajax_referer( 'eme_accounting_nonce', 'nonce' );
        Capabilities::require_manage_ajax( 'import' );
        $log_id = (int) ( $_POST['import_log_id'] ?? 0 );
        if ( ! $log_id ) {
            wp_send_json_error( 'Missing import_log_id' );
        }
        $c = self::get_status_counters( $log_id );
        wp_send_json_success( [
            'matched' => (int) ( $c->matched ?? 0 ),
            'manual'  => (int) ( $c->manual  ?? 0 ),
            'pending' => (int) ( $c->pending  ?? 0 ),
            'ignored' => (int) ( $c->ignored  ?? 0 ),
        ] );
    }

    /**
     * ftable listAction for the import-logs overview table.
     */
    public static function ajax_list_logs(): void {
        check_ajax_referer( 'eme_accounting_nonce', 'nonce' );
        Capabilities::require_manage_ajax( 'import' );
        global $wpdb;

        $rows = $wpdb->get_results(
            "SELECT il.*, a.name as account_name FROM `{$wpdb->prefix}emeacc_import_log` il
             LEFT JOIN `{$wpdb->prefix}emeacc_accounts` a ON a.id = il.account_id
             ORDER BY il.import_date DESC LIMIT 50"
        );

        foreach ( $rows as $row ) {
            $c = self::get_status_counters( (int) $row->id );
            $row->cnt_matched  = (int) ( $c->matched  ?? 0 );
            $row->cnt_manual   = (int) ( $c->manual   ?? 0 );
            $row->cnt_pending  = (int) ( $c->pending  ?? 0 );
            $row->cnt_ignored  = (int) ( $c->ignored  ?? 0 );
        }

        wp_send_json( [ 'Result' => 'OK', 'Records' => $rows, 'TotalRecordCount' => count( $rows ) ] );
    }

    /**
     * ftable listAction for the import-lines child table.
     * Expects POST param: import_log_id
     */
    public static function ajax_list_lines(): void {
        check_ajax_referer( 'eme_accounting_nonce', 'nonce' );
        Capabilities::require_manage_ajax( 'import' );
        $log_id = (int) ( $_POST['import_log_id'] ?? $_GET['import_log_id'] ?? 0 );
        if ( ! $log_id ) {
            wp_send_json( [ 'Result' => 'ERROR', 'Message' => 'Missing import_log_id' ] );
        }
        $lines = self::get_all_lines( $log_id );
        wp_send_json( [ 'Result' => 'OK', 'Records' => $lines, 'TotalRecordCount' => count( $lines ) ] );
    }

    /**
     * Delete import log and all related lines.
     */
    public static function delete_log( int $log_id ): void {
        global $wpdb;
        $wpdb->delete( $wpdb->prefix . 'emeacc_import_lines', [ 'import_log_id' => $log_id ], [ '%d' ] );
        $wpdb->delete( $wpdb->prefix . 'emeacc_import_log', [ 'id' => $log_id ], [ '%d' ] );
    }

    public static function ajax_delete_log(): void {
        check_ajax_referer( 'eme_accounting_nonce', 'nonce' );
        Capabilities::require_manage_ajax( 'import' );
        $id = (int) ( $_POST['id'] ?? 0 );
        if ( $id > 0 ) {
            self::delete_log( $id );
        }
        // Return ftable-compatible response (deleteAction expects Result: OK)
        wp_send_json( [ 'Result' => 'OK' ] );
    }

    /* ------------------------------------------------------------------
     * UPLOAD & PARSE
     * ------------------------------------------------------------------ */

    /**
     * Main entry point: receive uploaded file, detect format, parse, store.
     *
     * @param  int    $account_id
     * @param  array  $file       $_FILES element
     * @param  string $format     csv|coda|bnp|kbc|ing|belfius|auto
     * @return object      Import log row or error.
     */
    /**
     * Check for potential duplicate imports before inserting.
     *
     * Returns an array of warning strings (empty = no duplicates found).
     *  - Same filename + account → likely exact re-upload
     *  - Overlapping transaction date range + same account → possible overlap
     *
     * @param  int    $account_id
     * @param  string $filename    Sanitized filename
     * @param  array  $lines       Parsed lines (with 'date' key)
     * @return string[]
     */
    private static function check_duplicates( int $account_id, string $filename, array $lines ): array {
        global $wpdb;
        $warnings = [];

        // 1. Same filename + same account
        $existing = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM `{$wpdb->prefix}emeacc_import_log`
             WHERE account_id = %d AND filename = %s
             LIMIT 1",
            $account_id, $filename
        ) );
        if ( $existing ) {
            $warnings[] = sprintf(
                __( 'Bestand "%s" werd al eerder geïmporteerd voor deze rekening (import #%d).', 'eme-accounting' ),
                $filename, $existing
            );
        }

        // 2. Overlapping date range
        if ( ! empty( $lines ) ) {
            $dates    = array_filter( array_column( $lines, 'date' ) );
            $date_min = min( $dates );
            $date_max = max( $dates );

            $overlap = $wpdb->get_row( $wpdb->prepare(
                "SELECT il.id, il.filename, MIN(li.transaction_date) as d_min, MAX(li.transaction_date) as d_max
                 FROM `{$wpdb->prefix}emeacc_import_log` il
                 JOIN `{$wpdb->prefix}emeacc_import_lines` li ON li.import_log_id = il.id
                 WHERE il.account_id = %d
                 GROUP BY il.id
                 HAVING d_min <= %s AND d_max >= %s
                 LIMIT 1",
                $account_id, $date_max, $date_min
            ) );
            if ( $overlap ) {
                $warnings[] = sprintf(
                    __( 'Import #%d ("%s") bevat al transacties in de periode %s – %s.', 'eme-accounting' ),
                    $overlap->id, $overlap->filename,
                    $overlap->d_min, $overlap->d_max
                );
            }
        }

        return $warnings;
    }

    public static function process_upload( int $account_id, array $file, string $format = 'auto', bool $force = false ): object {
        global $wpdb;

        if ( $file['error'] !== UPLOAD_ERR_OK ) {
            return new \WP_Error( 'upload_error', __( 'Fout bij het uploaden van het bestand.', 'eme-accounting' ) );
        }

        $ext     = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );
        $content = file_get_contents( $file['tmp_name'] );

        if ( $content === false ) {
            return new \WP_Error( 'read_error', __( 'Bestand kon niet gelezen worden.', 'eme-accounting' ) );
        }

        // Detect format automatically if not specified
        if ( $format === 'auto' ) {
            $format = self::detect_format( $content, $ext );
        }

        // Parse into normalised line array
        $lines = match ( $format ) {
            'coda'    => self::parse_coda( $content ),
            'bnp'     => self::parse_bnp_csv( $content ),
            'kbc'     => self::parse_kbc_csv( $content ),
            'ing'     => self::parse_ing_csv( $content ),
            'belfius' => self::parse_belfius_csv( $content ),
            default   => self::parse_generic_csv( $content ),
        };

        if ( is_wp_error( $lines ) ) {
            return $lines;
        }

        // Duplicate check (skipped when $force = true)
        if ( ! $force ) {
            $filename = sanitize_file_name( $file['name'] );
            $warnings = self::check_duplicates( $account_id, $filename, $lines );
            if ( ! empty( $warnings ) ) {
                return (object) [
                    'duplicate_warnings' => $warnings,
                ];
            }
        }

        // Create import log entry
        $wpdb->insert( $wpdb->prefix . 'emeacc_import_log', [
            'account_id'  => $account_id,
            'filename'    => sanitize_file_name( $file['name'] ),
            'format'      => $format,
            'total_lines' => count( $lines ),
            'imported_by' => get_current_user_id(),
        ] );
        $log_id = (int) $wpdb->insert_id;

        // Insert lines
        $inserted = 0;
        foreach ( $lines as $line ) {
            $wpdb->insert( $wpdb->prefix . 'emeacc_import_lines', [
                'import_log_id'    => $log_id,
                'account_id'       => $account_id,
                'transaction_date' => $line['date'],
                'value_date'       => $line['value_date'] ?? null,
                'description'      => substr( $line['description'] ?? '', 0, 499 ),
                'counterparty_name'=> substr( $line['counterparty_name'] ?? '', 0, 149 ),
                'counterparty_iban'=> strtoupper( preg_replace( '/\s+/', '', $line['counterparty_iban'] ?? '' ) ),
                'amount'           => $line['amount'],
                'bank_ref'         => substr( $line['bank_ref'] ?? '', 0, 99 ),
                'structured_ref'   => substr( $line['structured_ref'] ?? '', 0, 21 ),
                'raw_data'         => json_encode( $line ),
                'status'           => 'pending',
            ] );
            $inserted++;
        }

        // Run automatic matching
        $matched = self::auto_match( $log_id, $account_id );

        return (object) [
            'log_id'    => $log_id,
            'total'     => $inserted,
            'matched'   => $matched,
            'unmatched' => $inserted-$matched
        ];
    }

    /* ------------------------------------------------------------------
     * AUTO-MATCHING
     * ------------------------------------------------------------------ */

    /**
     * Try to match import lines against existing journal entries.
     * Returns number of newly matched lines.
     */
    public static function auto_match( int $log_id, int $account_id ): int {
        global $wpdb;
        $lines   = self::get_pending_lines( $log_id );
        $matched = 0;

        foreach ( $lines as $line ) {
            $journal_id = self::find_match( $line, $account_id );
            if ( $journal_id ) {
                $wpdb->update(
                    $wpdb->prefix . 'emeacc_import_lines',
                    [ 'status' => 'matched', 'journal_id' => $journal_id ],
                    [ 'id' => $line->id ],
                    [ '%s', '%d' ],
                    [ '%d' ]
                );
                Journal::mark_reconciled( $journal_id );
                $matched++;
            }
        }
        return $matched;
    }

    /**
     * Attempt to find an existing journal entry matching the import line.
     * Scoring: date + amount required; structured ref or description boost.
     */
    private static function find_match( object $line, int $account_id ): int {
        global $wpdb;

        // Try structured reference first (most reliable)
        if ( ! empty( $line->structured_ref ) ) {
            $id = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT id FROM `{$wpdb->prefix}emeacc_journal`
                     WHERE account_id = %d
                       AND amount     = %f
                       AND is_reconciled = 0
                       AND (bank_statement_ref = %s OR bank_statement_seq = %s)
                     LIMIT 1",
                    $account_id,
                    abs( (float) $line->amount ),
                    $line->structured_ref,
                    $line->structured_ref
                )
            );
            if ( $id ) return $id;
        }

        // Try date + amount within ±3 days
        $id = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM `{$wpdb->prefix}emeacc_journal`
                 WHERE account_id    = %d
                   AND amount        = %f
                   AND direction     = %s
                   AND is_reconciled = 0
                   AND entry_date BETWEEN DATE_SUB(%s, INTERVAL 3 DAY) AND DATE_ADD(%s, INTERVAL 3 DAY)
                 ORDER BY ABS(DATEDIFF(entry_date, %s))
                 LIMIT 1",
                $account_id,
                abs( (float) $line->amount ),
                (float) $line->amount >= 0 ? 'in' : 'out',
                $line->transaction_date,
                $line->transaction_date,
                $line->transaction_date
            )
        );
        return $id;
    }

    /* ------------------------------------------------------------------
     * PARSERS
     * ------------------------------------------------------------------ */

    private static function detect_format( string $content, string $ext ): string {
        if ( $ext === 'cod' || $ext === 'coda' ) return 'coda';

        if ( str_contains( $content, 'Rekening;Boekingsdatum;Rekeninguittrekselnummer' ) && str_contains( $content, 'Transactie;Valutadatum;Bedrag') ) return 'belfius';
        if ( str_contains( $content, 'Rekeningnummer;Naam van de rekening' ) ) return 'bnp';
        if ( str_contains( $content, 'Rekeningnummer;Naam rekening' ) ) return 'kbc';
        if ( str_contains( $content, 'Rekening;Boekingsdatum;Valutadatum;Bedrag' ) ) return 'ing';
        return 'csv';
    }

    /**
     * BNP Paribas Fortis CSV.
     * Header: Rekeningnummer;Naam van de rekening;Referentie;Datum;…
     *
     * @return array<int,array>|\WP_Error
     */
    private static function parse_bnp_csv( string $content ): array|\WP_Error {
        return self::parse_csv_generic( $content, ';', [
            'date'             => 'Datum',
            'amount'           => 'Bedrag',
            'description'      => 'Naam van de rekening van de tegenpartij',
            'counterparty_iban'=> 'Rekening van de tegenpartij',
            'structured_ref'   => 'Gestructureerde mededeling',
            'bank_ref'         => 'Referentie',
        ] );
    }

    private static function parse_kbc_csv( string $content ): array|\WP_Error {
        return self::parse_csv_generic( $content, ';', [
            'date'             => 'Datum',
            'amount'           => 'Bedrag',
            'description'      => 'Omschrijving',
            'counterparty_iban'=> 'Tegenrekening',
            'counterparty_name'=> 'Naam tegenpartij',
            'bank_ref'         => 'Referentie',
        ] );
    }

    private static function parse_ing_csv( string $content ): array|\WP_Error {
        return self::parse_csv_generic( $content, ';', [
            'date'             => 'Boekingsdatum',
            'value_date'       => 'Valutadatum',
            'amount'           => 'Bedrag',
            'description'      => 'Omschrijving',
            'counterparty_iban'=> 'Tegenrekening',
            'counterparty_name'=> 'Naam tegenpartij',
        ] );
    }

    private static function parse_belfius_csv( string $content ): array|\WP_Error {
        // belfius has the reference in the Transaction column
        $rows = self::parse_csv_generic( $content, ';', [
            'date'             => 'Boekingsdatum',
            'value_date'       => 'Valutadatum',
            'amount'           => 'Bedrag',
            'description'      => 'Mededelingen',
            'counterparty_name'=> 'Naam tegenpartij bevat',
            'counterparty_iban'=> 'Rekening tegenpartij',
            'transaction'      => 'Transactie',
        ] );

        if ( is_wp_error( $rows ) ) {
            return $rows;
        }

        foreach ( $rows as &$row ) {
            $ref = '';
            if ( ! empty( $row['transaction'] ) ) {
                $pos = strrpos( $row['transaction'], 'REF. :' );
                if ( $pos !== false ) {
                    $ref = trim( substr( $row['transaction'], $pos + strlen( 'REF. :' ) ) );
                }
            }
            $row['bank_ref'] = $ref;
            unset( $row['transaction'] );
        }
        unset( $row );
        return $rows;
    }

    private static function parse_generic_csv( string $content ): array|\WP_Error {
        // Auto-detect delimiter by counting which character appears the most
        $delimiters = [ ';', ',', "\t", '|' ];
        $counts     = [];
        foreach ( $delimiters as $d ) {
            // we take the whole file, not just the first line
            //$counts[ $d ] = substr_count( strtok( $content, "\n" ), $d );
            $counts[ $d ] = substr_count( $content, $d );
        }
        arsort( $counts );
        $delimiter = array_key_first( $counts );

        return self::parse_csv_generic( $content, $delimiter, [
                'date'             => 'Datum',
                'amount'           => 'Bedrag',
                'counterparty_name'=> 'Naam',
                'description'      => 'Omschrijving'
        ] );
    }

    /**
     * Core CSV parser with column mapping.
     *
     * @param  array<string,string> $col_map  normalised_key => csv_header
     * @return array<int,array>|\WP_Error
     */
    private static function parse_csv_generic( string $content, string $delimiter, array $col_map ): array|\WP_Error {
        // Strip BOM
        $content = ltrim( $content, "\xEF\xBB\xBF" );
        $lines   = array_filter( array_map( 'trim', explode( "\n", $content ) ) );

        if ( count( $lines ) < 2 ) {
            return new \WP_Error( 'empty_file', __( 'Bestand bevat geen transacties.', 'eme-accounting' ) );
        }

        // Scan lines until we find one that contains all required col_map columns.
        $header  = null;
        $reverse = [];
        foreach ( $lines as $key => $line ) {
            $candidate = array_map( 'trim', str_getcsv( $line, $delimiter, '"', '\\' ) );
            $found = [];
            foreach ( $col_map as $norm => $csv ) {
                $idx = array_search( $csv, $candidate, true );
                if ( $idx !== false ) {
                    $found[ $idx ] = $norm;
                }
            }
            // Only accept this line as header if ALL col_map columns are present.
            if ( count( $found ) === count( $col_map ) ) {
                $header  = $candidate;
                $reverse = $found;
                // Remove all lines up to and including the header.
                $lines = array_slice( $lines, $key + 1 );
                break;
            }
        }

        if ( $header === null ) {
            return new \WP_Error(
                'no_header',
                sprintf(
                    __( 'Kolomhoofden niet gevonden in bestand. Verwachte kolommen: %s.', 'eme-accounting' ),
                    implode( ', ', array_values( $col_map ) )
                )
            );
        }

        $result = [];
        foreach ( $lines as $raw ) {
            $cells = str_getcsv( $raw, $delimiter, '"', '\\' );

            $row = [];
            foreach ( $reverse as $idx => $norm ) {
                $row[ $norm ] = trim( $cells[ $idx ] ?? '' );
                }

            // Normalise date (d/m/Y or d-m-Y → Y-m-d)
            $date = $row['date'] ?? '';
            if ( $date && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
                $ts   = strtotime( str_replace( '/', '-', $date ) );
                $date = $ts ? gmdate( 'Y-m-d', $ts ) : $date;
            }
            $row['date'] = $date;

            // Normalise amount (European format: 1.234,56 → 1234.56)
            $amount_str = preg_replace( '/[^\d,.-]/', '', $row['amount'] ?? '0' );
            if ( substr_count( $amount_str, ',' ) === 1 && substr_count( $amount_str, '.' ) <= 1 ) {
                // European: dots as thousands, comma as decimal
                $amount_str = str_replace( '.', '', $amount_str );
                $amount_str = str_replace( ',', '.', $amount_str );
            }
            $row['amount'] = (float) $amount_str;

            if ( ! empty( $row['date'] ) ) {
                $result[] = $row;
            }
        }

        return $result;
    }

    /**
     * CODA v2 parser (structured Belgian bank format).
     * Reference: NBB CODA specification.
     *
     * @return array<int,array>|\WP_Error
     */
    private static function parse_coda( string $content ): array|\WP_Error {
        $lines  = explode( "\n", $content );
        $result = [];
        $current_transaction = [];

        foreach ( $lines as $line ) {
            $line = rtrim( $line );
            if ( strlen( $line ) < 2 ) continue;
            $type = $line[0];
            $subtype = $line[1] ?? '';

            switch ( $type ) {
                case '2': // Movement record
                    if ( $subtype === '1' ) {
                        // New transaction
                        $seq  = substr( $line, 2, 4 );
                        $date = substr( $line, 14, 6 ); // DDMMYY
                        $sign = substr( $line, 31, 1 ); // 0=credit, 1=debit
                        $amt  = substr( $line, 32, 15 );
                        $dd   = substr( $date, 0, 2 );
                        $mm   = substr( $date, 2, 2 );
                        $yy   = substr( $date, 4, 2 );
                        $current_transaction = [
                            'date'    => "20{$yy}-{$mm}-{$dd}",
                            'amount'  => ( $sign === '1' ? -1 : 1 ) * ( (float) $amt / 1000 ),
                            'bank_ref'=> $seq,
                        ];
                    } elseif ( $subtype === '2' ) {
                        // Communication continuation
                        $comm = trim( substr( $line, 10, 53 ) );
                        $current_transaction['description'] = ( $current_transaction['description'] ?? '' ) . $comm;
                    }
                    break;
                case '3': // Counterparty info
                    if ( $subtype === '1' ) {
                        $iban = trim( substr( $line, 10, 37 ) );
                        $name = trim( substr( $line, 47, 35 ) );
                        $current_transaction['counterparty_iban'] = $iban;
                        $current_transaction['counterparty_name'] = $name;
                        $result[] = $current_transaction;
                        $current_transaction = [];
                    }
                    break;
            }
        }

        // Flush last if not yet added
        if ( ! empty( $current_transaction['date'] ) ) {
            $result[] = $current_transaction;
        }

        return $result ?: new \WP_Error( 'coda_parse', __( 'CODA-bestand kon niet geparsed worden.', 'eme-accounting' ) );
    }

    /* ------------------------------------------------------------------
     * SINGLE LINE FETCH (for ftable Record responses)
     * ------------------------------------------------------------------ */

    /**
     * Fetch a single import line enriched with its journal description,
     * formatted exactly as get_all_lines() returns rows.
     */
    public static function get_line( int $line_id ): ?object {
        global $wpdb;
        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT il.*, j.description as journal_description
                 FROM `{$wpdb->prefix}emeacc_import_lines` il
                 LEFT JOIN `{$wpdb->prefix}emeacc_journal` j ON j.id = il.journal_id
                 WHERE il.id = %d",
                $line_id
            )
        );
    }

    /* ------------------------------------------------------------------
     * QUERY HELPERS
     * ------------------------------------------------------------------ */

    /**
     * @return object
     */
    public static function get_status_counters( int $log_id ): object {
        global $wpdb;

        if ( ! $log_id ) {
            return (object) [];
        }

        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT status, COUNT(*) as count FROM `{$wpdb->prefix}emeacc_import_lines`
                WHERE import_log_id = %d GROUP BY status",
        $log_id
            )
        );

        $counters = [];
        foreach ( $results as $row ) {
            $counters[$row->status] = (int) $row->count;
        }

        return (object) $counters;
    }


    /**
     * @return object[]
     */
    public static function get_pending_lines( int $log_id ): array {
        global $wpdb;
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM `{$wpdb->prefix}emeacc_import_lines`
                 WHERE import_log_id = %d AND status = 'pending'",
                $log_id
            )
        );
    }

    /**
     * @return object[]
     */
    public static function get_all_lines( int $log_id ): array {
        global $wpdb;
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT il.*, j.description as journal_description
                 FROM `{$wpdb->prefix}emeacc_import_lines` il
                 LEFT JOIN `{$wpdb->prefix}emeacc_journal` j ON j.id = il.journal_id
                 WHERE il.import_log_id = %d
                 ORDER BY il.transaction_date",
                $log_id
            )
        );
    }

    /* ------------------------------------------------------------------
     * AJAX
     * ------------------------------------------------------------------ */

    public static function ajax_upload(): void {
        check_ajax_referer( 'eme_accounting_nonce', 'nonce' );
        Capabilities::require_manage_ajax( 'import' );
        $account_id = (int) ( $_POST['account_id'] ?? 0 );
        $format     = eme_sanitize_request( $_POST['format'] ?? 'auto' );
        $force      = ! empty( $_POST['force'] );
        $result = self::process_upload( $account_id, $_FILES['import_file'], $format, $force );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }
        // Return warnings without importing — JS will ask for confirmation
        if ( ! empty( $result->duplicate_warnings ) ) {
            wp_send_json_success( (array) $result );
            return;
        }
        wp_send_json_success( (array) $result );
    }

    public static function ajax_reconcile_line(): void {
        check_ajax_referer( 'eme_accounting_nonce', 'nonce' );
        Capabilities::require_manage_ajax( 'import' );
        global $wpdb;
        $line_id    = (int) ( $_POST['line_id'] ?? 0 );
        $journal_id = (int) ( $_POST['journal_id'] ?? 0 );
        $res = $wpdb->update(
            $wpdb->prefix . 'emeacc_import_lines',
            [ 'status' => 'matched', 'journal_id' => $journal_id ],
            [ 'id' => $line_id, 'is_reconciled' => 0 ],
            [ '%s', '%d' ],
            [ '%d' ]
        );
        if ($res) {
            Journal::mark_reconciled( $journal_id );
            wp_send_json_success( [ 'Record' => self::get_line( $line_id ) ] );
        } else {
            wp_send_json_error( 'Link niet kunnen leggen' );
        }
    }

    public static function ajax_ignore_line(): void {
        check_ajax_referer( 'eme_accounting_nonce', 'nonce' );
        Capabilities::require_manage_ajax( 'import' );
        global $wpdb;
        $line_id = (int) ( $_POST['line_id'] ?? 0 );
        $wpdb->update(
            $wpdb->prefix . 'emeacc_import_lines',
            [ 'status' => 'ignored' ],
            [ 'id' => $line_id ],
            [ '%s' ],
            [ '%d' ]
        );
        wp_send_json_success( [ 'Record' => self::get_line( $line_id ) ] );
    }

    public static function ajax_auto_reconcile(): void {
        check_ajax_referer( 'eme_accounting_nonce', 'nonce' );
        Capabilities::require_manage_ajax( 'import' );
        $log_id     = (int) ( $_POST['log_id'] ?? 0 );
        $account_id = (int) ( $_POST['account_id'] ?? 0 );
        $matched    = self::auto_match( $log_id, $account_id );
        wp_send_json_success( [ 'matched' => $matched ] );
    }

    public static function ajax_create_from_line(): void {
        check_ajax_referer( 'eme_accounting_nonce', 'nonce' );
        Capabilities::require_manage_ajax( 'import' );
        global $wpdb;

        $line_id = (int) ( $_POST['line_id'] ?? 0 );
        $line    = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM `{$wpdb->prefix}emeacc_import_lines` WHERE id = %d",
            $line_id
        ) );
        if ( ! $line ) {
            wp_send_json_error( __( 'Regel niet gevonden.', 'eme-accounting' ) );
        }

        $journal_id = Journal::save( [
            'entry_date'        => $line->transaction_date,
            'booking_date'      => $line->value_date,
            'description'       => $line->description,
            'amount'            => abs( (float) $line->amount ),
            'direction'         => (float) $line->amount >= 0 ? 'in' : 'out',
            'account_id'        => $line->account_id,
            'bank_statement_ref'=> $line->bank_ref ?: $line->structured_ref,
            'import_line_id'    => $line->id,
            'is_reconciled'     => 1,
            'coa_id'            => (int) ( $_POST['coa_id'] ?? 0 ),
            'eme_event_id'      => (int) ( $_POST['eme_event_id'] ?? 0 ),
        ] );

        if ( is_wp_error( $journal_id ) ) {
            wp_send_json_error( $journal_id->get_error_message() );
        }

        $wpdb->update(
            $wpdb->prefix . 'emeacc_import_lines',
            [ 'status' => 'manual', 'journal_id' => $journal_id ],
            [ 'id' => $line_id ],
            [ '%s', '%d' ],
            [ '%d' ]
        );

        wp_send_json_success( [ 'journal_id' => $journal_id, 'Record' => self::get_line( $line_id ) ] );
    }
}
