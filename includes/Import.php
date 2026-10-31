<?php
// phpcs:ignoreFile WordPress.DB.DirectDatabaseQuery.DirectQuery WordPress.DB.DirectDatabaseQuery.NoCaching
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

    /** Maximum accepted upload size in bytes (10 MB). */
    const MAX_UPLOAD_SIZE = 10485760;

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
        add_action( 'wp_ajax_eme_accounting_check_transfer_exists', [ __CLASS__, 'ajax_check_transfer_exists' ] );
        add_action( 'wp_ajax_eme_accounting_create_transfer_from_line', [ __CLASS__, 'ajax_create_transfer_from_line' ] );
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

        $action_type = eme_sanitize_request( $_POST['action_type'] ?? '' ); //phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        $coa_id      = (int) eme_sanitize_request( $_POST['coa_id'] ?? 0 ); //phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        $eme_event_id= (int) eme_sanitize_request( $_POST['eme_event_id'] ?? 0 ); //phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        $raw_ids     = eme_sanitize_request( $_POST['line_ids'] ?? '' ); //phpcs:ignore WordPress.Security.ValidatedSanitizedInput

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
                    'import_line_id'    => $line->id,
                    'is_reconciled'     => 1,
                    'coa_id'            => $coa_id,
                    'eme_event_id'      => $eme_event_id,
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
            // translators: %d is the number of processed import lines.
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
        $log_id = (int) eme_sanitize_request( $_POST['import_log_id'] ?? 0 ); //phpcs:ignore WordPress.Security.ValidatedSanitizedInput
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

        $limit  = eme_get_ftable_limit() ?: '';
        $rows = $wpdb->get_results( //phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter
            "SELECT il.*, a.name as account_name FROM `{$wpdb->prefix}emeacc_import_log` il
             LEFT JOIN `{$wpdb->prefix}emeacc_accounts` a ON a.id = il.account_id
             ORDER BY il.import_date DESC, il.id DESC $limit" //phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        );

        foreach ( $rows as $row ) {
            $c = self::get_status_counters( (int) $row->id );
            $row->cnt_matched  = (int) ( $c->matched  ?? 0 );
            $row->cnt_manual   = (int) ( $c->manual   ?? 0 );
            $row->cnt_pending  = (int) ( $c->pending  ?? 0 );
            $row->cnt_ignored  = (int) ( $c->ignored  ?? 0 );
            // Convenience flag: no pending lines left → fully processed.
            $row->is_complete  = ( $row->cnt_pending === 0 ) ? 1 : 0;
        }

        $total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$wpdb->prefix}emeacc_import_log`" ); //phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        wp_send_json( [ 'Result' => 'OK', 'Records' => $rows, 'TotalRecordCount' => $total ] );
    }

    /**
     * ftable listAction for the import-lines child table.
     * Expects POST param: import_log_id
     */
    public static function ajax_list_lines(): void {
        check_ajax_referer( 'eme_accounting_nonce', 'nonce' );
        Capabilities::require_manage_ajax( 'import' );
        $log_id = (int) eme_sanitize_request( $_POST['import_log_id'] ?? $_GET['import_log_id'] ?? 0 ); //phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        if ( ! $log_id ) {
            wp_send_json( [ 'Result' => 'ERROR', 'Message' => 'Missing import_log_id' ] );
        }
        $limit  = eme_get_ftable_limit() ?: '';
        $lines = self::get_all_lines( $log_id, $limit );
        $total = self::count_lines( $log_id );
        wp_send_json( [ 'Result' => 'OK', 'Records' => $lines, 'TotalRecordCount' => $total ] );
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
        $id = (int) eme_sanitize_request( $_POST['id'] ?? 0 ); //phpcs:ignore WordPress.Security.ValidatedSanitizedInput
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
                // translators: %1$s is the filename, %2$d is the existing import ID.
                __( 'Bestand "%1$s" werd al eerder geïmporteerd voor deze rekening (import #%2$d).', 'eme-accounting' ),
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
                    // translators: %1$d is the import ID, %2$s the filename, %3$s and %4$s the start and end date of the overlap.
                    __( 'Import #%1$d ("%2$s") bevat al transacties in de periode %3$s – %4$s.', 'eme-accounting' ),
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

        if ( ! is_uploaded_file( $file['tmp_name'] ) ) {
            return new \WP_Error( 'invalid_upload', __( 'Ongeldig uploadbestand.', 'eme-accounting' ) );
        }

        // Guard against oversized files: parsing loads the whole file into memory.
        if ( ! empty( $file['size'] ) && (int) $file['size'] > self::MAX_UPLOAD_SIZE ) {
            return new \WP_Error( 'file_too_large', __( 'Bestand is te groot om te importeren.', 'eme-accounting' ) );
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

        // Sanitize filename once, reused for duplicate check and DB insert.
        $filename = sanitize_file_name( $file['name'] );

        // Duplicate check (skipped when $force = true)
        if ( ! $force ) {
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
            'filename'    => $filename,
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
                'description'      => substr( trim ( $line['description'] ?? '' ), 0, 499 ),
                'counterparty_name'=> substr( trim ( $line['counterparty_name'] ?? '' ), 0, 149 ),
                'counterparty_iban'=> strtoupper( trim( $line['counterparty_iban'] ?? '' ) ),
                'amount'           => $line['amount'],
                'bank_ref'         => substr( trim ( $line['bank_ref'] ?? '' ), 0, 99 ),
                'structured_ref'   => substr( trim ( $line['structured_ref'] ?? '' ), 0, 21 ),
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
                $res = $wpdb->update(
                    $wpdb->prefix . 'emeacc_import_lines',
                    [ 'status' => 'matched', 'journal_id' => $journal_id ],
                    [ 'id' => $line->id, 'status' => 'pending' ],
                    [ '%s', '%d' ],
                    [ '%d', '%s' ]
                );
                if ($res) {
                    Journal::mark_reconciled( $journal_id );
                    $matched++;
                }
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
                    "SELECT j.id FROM `{$wpdb->prefix}emeacc_journal` j
                     WHERE j.account_id = %d
                       AND j.amount     = %f
                       AND NOT EXISTS ( SELECT 1 FROM `{$wpdb->prefix}emeacc_import_lines` il WHERE il.journal_id = j.id )
                       AND (j.bank_statement_ref = %s OR j.bank_statement_seq = %s)
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
                "SELECT j.id FROM `{$wpdb->prefix}emeacc_journal` j
                 WHERE j.account_id = %d
                   AND j.amount     = %f
                   AND j.direction  = %s
                   AND NOT EXISTS ( SELECT 1 FROM `{$wpdb->prefix}emeacc_import_lines` il WHERE il.journal_id = j.id )
                   AND j.entry_date BETWEEN DATE_SUB(%s, INTERVAL 3 DAY) AND DATE_ADD(%s, INTERVAL 3 DAY)
                 ORDER BY ABS(DATEDIFF(j.entry_date, %s))
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
        $s = self::get_column_schema()['bnp'];
        return self::parse_csv_generic( $content, ';', $s['required'], $s['optional'] );
    }

    private static function parse_kbc_csv( string $content ): array|\WP_Error {
        $s = self::get_column_schema()['kbc'];
        return self::parse_csv_generic( $content, ';', $s['required'], $s['optional'] );
    }

    private static function parse_ing_csv( string $content ): array|\WP_Error {
        $s = self::get_column_schema()['ing'];
        return self::parse_csv_generic( $content, ';', $s['required'], $s['optional'] );
    }

    private static function parse_belfius_csv( string $content ): array|\WP_Error {
        // belfius has the reference in the Transaction column
        $s    = self::get_column_schema()['belfius'];
        $rows = self::parse_csv_generic( $content, ';', $s['required'], $s['optional'] );

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

    /**
     * Column schema per format.
     *
     * 'required' keys MUST be present in the CSV header for parsing to succeed.
     * 'optional' keys are mapped when found but silently skipped when absent.
     *
     * Used by:
     *  - parse_generic_csv()  — to build the col_map passed to parse_csv_generic()
     *  - ajax_upload (JS)     — to show the user which columns are expected
     *
     * @return array<string, array{required: array<string,string>, optional: array<string,string>}>
     */
    public static function get_column_schema(): array {
        return [
            'csv' => [
                'required' => [
                    'date'   => 'Datum',
                    'amount' => 'Bedrag',
                ],
                'optional' => [
                    'description'       => 'Omschrijving',
                    'counterparty_name' => 'Naam',
                    'counterparty_iban' => 'Rekening',
                    'value_date'        => 'Valutadatum',
                    'structured_ref'    => 'Gestructureerde mededeling',
                    'bank_ref'          => 'Referentie',
                ],
            ],
            'bnp' => [
                'required' => [
                    'date'             => 'Datum',
                    'amount'           => 'Bedrag',
                    'description'      => 'Naam van de rekening van de tegenpartij',
                    'counterparty_iban'=> 'Rekening van de tegenpartij',
                ],
                'optional' => [
                    'structured_ref'   => 'Gestructureerde mededeling',
                    'bank_ref'         => 'Referentie',
                ],
            ],
            'kbc' => [
                'required' => [
                    'date'             => 'Datum',
                    'amount'           => 'Bedrag',
                    'description'      => 'Omschrijving',
                ],
                'optional' => [
                    'counterparty_iban'=> 'Tegenrekening',
                    'counterparty_name'=> 'Naam tegenpartij',
                    'bank_ref'         => 'Referentie',
                ],
            ],
            'ing' => [
                'required' => [
                    'date'             => 'Boekingsdatum',
                    'amount'           => 'Bedrag',
                    'description'      => 'Omschrijving',
                ],
                'optional' => [
                    'value_date'       => 'Valutadatum',
                    'counterparty_iban'=> 'Tegenrekening',
                    'counterparty_name'=> 'Naam tegenpartij',
                ],
            ],
            'belfius' => [
                'required' => [
                    'date'             => 'Boekingsdatum',
                    'amount'           => 'Bedrag',
                    'description'      => 'Mededelingen',
                ],
                'optional' => [
                    'value_date'       => 'Valutadatum',
                    'counterparty_name'=> 'Naam tegenpartij bevat',
                    'counterparty_iban'=> 'Rekening tegenpartij',
                    'transaction'      => 'Transactie',
                ],
            ],
            'coda' => [
                'required' => [],  // binary format, no CSV columns
                'optional' => [],
            ],
        ];
    }

    private static function parse_generic_csv( string $content ): array|\WP_Error {
        // Auto-detect delimiter by counting occurrences in the full file.
        $delimiters = [ ';', ',', "\t", '|' ];
        $counts     = [];
        foreach ( $delimiters as $d ) {
            $counts[ $d ] = substr_count( $content, $d );
        }
        arsort( $counts );
        $delimiter = array_key_first( $counts );

        $schema = self::get_column_schema()['csv'];
        // Required + optional merged: parse_csv_generic will only error on missing required ones.
        return self::parse_csv_generic(
            $content,
            $delimiter,
            $schema['required'],
            $schema['optional']
        );
    }

    /**
     * Core CSV parser with column mapping.
     *
     * @param  array<string,string> $required_map  normalised_key => csv_header (ALL must be present)
     * @param  array<string,string> $optional_map  normalised_key => csv_header (mapped when found)
     * @return array<int,array>|\WP_Error
     */
    private static function parse_csv_generic( string $content, string $delimiter, array $required_map, array $optional_map = [] ): array|\WP_Error {
        // Strip BOM
        $content = ltrim( $content, "\xEF\xBB\xBF" );
        $lines   = array_filter( array_map( 'trim', explode( "\n", $content ) ) );

        if ( count( $lines ) < 2 ) {
            return new \WP_Error( 'empty_file', __( 'Bestand bevat geen transacties.', 'eme-accounting' ) );
        }

        // Scan lines until we find one that contains all required columns.
        $header  = null;
        $reverse = [];
        foreach ( $lines as $key => $line ) {
            $candidate = array_map( 'trim', str_getcsv( $line, $delimiter, '"', '\\' ) );
            $found = [];
            // Required columns: all must be present.
            foreach ( $required_map as $norm => $csv ) {
                $idx = array_search( $csv, $candidate, true );
                if ( $idx !== false ) {
                    $found[ $idx ] = $norm;
                }
            }
            if ( count( $found ) !== count( $required_map ) ) {
                continue;
            }
            // Optional columns: add when present.
            foreach ( $optional_map as $norm => $csv ) {
                $idx = array_search( $csv, $candidate, true );
                if ( $idx !== false ) {
                    $found[ $idx ] = $norm;
                }
            }
            $header  = $candidate;
            $reverse = $found;
            // Remove all lines up to and including the header.
            $lines = array_slice( $lines, $key + 1 );
            break;
        }

        if ( $header === null ) {
            return new \WP_Error(
                'no_header',
                sprintf(
                    // translators: %s is the comma-separated list of required column names.
                    __( 'Kolomhoofden niet gevonden in bestand. Vereiste kolommen: %s.', 'eme-accounting' ),
                    implode( ', ', array_values( $required_map ) )
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

    public static function count_lines( int $log_id ): int {
        global $wpdb;
        return (int) $wpdb->get_var( //phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter
            $wpdb->prepare(
                "SELECT COUNT(*) FROM `{$wpdb->prefix}emeacc_import_lines` WHERE import_log_id = %d", //phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $log_id
            )
        );
    }

    /**
     * @return object[]
     */
    public static function get_all_lines( int $log_id, string $limit = '' ): array {
        global $wpdb;
        return $wpdb->get_results( //phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter
            $wpdb->prepare(
                "SELECT il.*, j.description as journal_description
                 FROM `{$wpdb->prefix}emeacc_import_lines` il
                 LEFT JOIN `{$wpdb->prefix}emeacc_journal` j ON j.id = il.journal_id
                 WHERE il.import_log_id = %d
                 ORDER BY il.transaction_date, il.id $limit", //phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $log_id
            )
        );
    }

    /**
     * Resolve the two legs of a transfer for an import line.
     *
     * The import line belongs to a fixed account ($line->account_id). Depending
     * on the sign of the amount, that account is either the target (money in,
     * "+") or the source (money out, "–") of the transfer. The user supplies
     * the "other" account; this method returns [ source_account_id, target_account_id ].
     *
     * @param  object $line
     * @param  int    $other_account_id  The account chosen to be the other leg.
     * @return array{0:int,1:int}
     */
    private static function transfer_legs( object $line, int $other_account_id ): array {
        $other = (int) $other_account_id;
        if ( (float) $line->amount >= 0 ) {
            // Money in → import account is the target, other account is the source.
            return [ $other, (int) $line->account_id ];
        }
        // Money out → import account is the source, other account is the target.
        return [ (int) $line->account_id, $other ];
    }

    /**
     * Find existing transfers between two accounts for a given amount/date.
     *
     * Used to warn the user before creating a transfer from an import line in
     * case that transfer has already been booked (e.g. from the counterparty
     * account's own import).
     *
     * @param  int    $source_account_id
     * @param  int    $target_account_id
     * @param  float  $amount
     * @param  string $entry_date
     * @return object[]
     */
    public static function find_existing_transfers( int $source_account_id, int $target_account_id, float $amount, string $entry_date ): array {
        global $wpdb;
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT j.*, a.name AS account_name, ca.name AS contra_account_name
                 FROM `{$wpdb->prefix}emeacc_journal` j
                 LEFT JOIN `{$wpdb->prefix}emeacc_accounts` a  ON a.id = j.account_id
                 LEFT JOIN `{$wpdb->prefix}emeacc_accounts` ca ON ca.id = j.contra_account_id
                 WHERE j.direction = 'out' AND j.transfer_id IS NOT NULL
                   AND j.account_id = %d AND j.contra_account_id = %d
                   AND j.amount = %f
                   AND j.entry_date BETWEEN DATE_SUB(%s, INTERVAL 7 DAY) AND DATE_ADD(%s, INTERVAL 7 DAY)
                 ORDER BY ABS(DATEDIFF(j.entry_date, %s))
                 LIMIT 5",
                $source_account_id,
                $target_account_id,
                abs( $amount ),
                $entry_date,
                $entry_date,
                $entry_date
            )
        );
    }

    /* ------------------------------------------------------------------
     * AJAX
     * ------------------------------------------------------------------ */

    public static function ajax_upload(): void {
        check_ajax_referer( 'eme_accounting_nonce', 'nonce' );
        Capabilities::require_manage_ajax( 'import' );
        $account_id = (int) eme_sanitize_request( $_POST['account_id'] ?? 0 ); //phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        $format     = eme_sanitize_request( $_POST['format'] ?? 'auto' ); //phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        $force      = (bool) eme_sanitize_request( $_POST['force'] ?? '' ); //phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        $result = self::process_upload( $account_id, $_FILES['import_file'], $format, $force ); //phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
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
        $line_id    = (int) eme_sanitize_request( $_POST['line_id'] ?? 0 ); //phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        $journal_id = (int) eme_sanitize_request( $_POST['journal_id'] ?? 0 ); //phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        $res = $wpdb->update(
            $wpdb->prefix . 'emeacc_import_lines',
            [ 'status' => 'matched', 'journal_id' => $journal_id ],
            [ 'id' => $line_id, 'status' => 'pending' ],
            [ '%s', '%d' ],
            [ '%d', '%s' ]
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
        $line_id = (int) eme_sanitize_request( $_POST['line_id'] ?? 0 ); //phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        $res = $wpdb->update(
            $wpdb->prefix . 'emeacc_import_lines',
            [ 'status' => 'ignored' ],
            [ 'id' => $line_id, 'status' => 'pending' ],
            [ '%s' ],
            [ '%d', '%s' ]
        );
        if ($res) {
            wp_send_json_success( [ 'Record' => self::get_line( $line_id ) ] );
        } else {
            wp_send_json_error( 'Lijn niet kunnen negeren' );
        }
    }

    public static function ajax_auto_reconcile(): void {
        check_ajax_referer( 'eme_accounting_nonce', 'nonce' );
        Capabilities::require_manage_ajax( 'import' );
        $log_id     = (int) eme_sanitize_request( $_POST['log_id'] ?? 0 ); //phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        $account_id = (int) eme_sanitize_request( $_POST['account_id'] ?? 0 ); //phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        $matched    = self::auto_match( $log_id, $account_id );
        wp_send_json_success( [ 'matched' => $matched ] );
    }

    public static function ajax_create_from_line(): void {
        check_ajax_referer( 'eme_accounting_nonce', 'nonce' );
        Capabilities::require_manage_ajax( 'import' );
        global $wpdb;

        $line_id = (int) eme_sanitize_request( $_POST['line_id'] ?? 0 ); //phpcs:ignore WordPress.Security.ValidatedSanitizedInput
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
	    'counterparty_name' => $line->counterparty_name ?? '',
            'import_line_id'    => $line->id,
            'is_reconciled'     => 1,
            'coa_id'            => (int) eme_sanitize_request( $_POST['coa_id'] ?? 0 ), //phpcs:ignore WordPress.Security.ValidatedSanitizedInput
            'eme_event_id'      => (int) eme_sanitize_request( $_POST['eme_event_id'] ?? 0 ), //phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        ] );

        if ( is_wp_error( $journal_id ) ) {
            wp_send_json_error( $journal_id->get_error_message() );
        }

        $wpdb->update(
            $wpdb->prefix . 'emeacc_import_lines',
            [ 'status' => 'manual', 'journal_id' => $journal_id ],
            [ 'id' => $line_id, 'status' => 'pending' ],
            [ '%s', '%d' ],
            [ '%d', '%s' ]
        );

        wp_send_json_success( [ 'journal_id' => $journal_id, 'Record' => self::get_line( $line_id ) ] );
    }

    /**
     * Check whether a transfer between the import account and the chosen
     * "other" account already exists for this line's amount/date.
     *
     * POST params: line_id, other_account_id
     */
    public static function ajax_check_transfer_exists(): void {
        check_ajax_referer( 'eme_accounting_nonce', 'nonce' );
        Capabilities::require_manage_ajax( 'import' );
        global $wpdb;

        $line_id          = (int) eme_sanitize_request( $_POST['line_id'] ?? 0 ); //phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        $other_account_id = (int) eme_sanitize_request( $_POST['other_account_id'] ?? 0 ); //phpcs:ignore WordPress.Security.ValidatedSanitizedInput

        $line = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM `{$wpdb->prefix}emeacc_import_lines` WHERE id = %d",
            $line_id
        ) );
        if ( ! $line || ! $other_account_id ) {
            wp_send_json_error( __( 'Regel niet gevonden.', 'eme-accounting' ) );
        }

        [ $source, $target ] = self::transfer_legs( $line, $other_account_id );
        $existing = self::find_existing_transfers( $source, $target, (float) $line->amount, $line->transaction_date );

        $records = [];
        foreach ( $existing as $e ) {
            $records[] = [
                'id'          => (int) $e->id,
                'entry_date'  => eme_localized_date( $e->entry_date, EME_TIMEZONE ),
                'amount'      => (float) $e->amount,
                'account_name'=> $e->account_name ?? '',
                'contra_account_name' => $e->contra_account_name ?? '',
                'description' => $e->description,
            ];
        }

        wp_send_json_success( [ 'existing' => $records ] );
    }

    /**
     * Create a transfer from an import line.
     *
     * The import account is fixed; the other leg is chosen by the user. The
     * source/target is derived from the sign of the amount ("+" → money in to
     * the import account, "–" → money out of it).
     *
     * POST params: line_id, other_account_id, eme_event_id
     */
    public static function ajax_create_transfer_from_line(): void {
        check_ajax_referer( 'eme_accounting_nonce', 'nonce' );
        Capabilities::require_manage_ajax( 'import' );
        global $wpdb;

        $line_id          = (int) eme_sanitize_request( $_POST['line_id'] ?? 0 ); //phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        $other_account_id = (int) eme_sanitize_request( $_POST['other_account_id'] ?? 0 ); //phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        $eme_event_id     = (int) eme_sanitize_request( $_POST['eme_event_id'] ?? 0 ); //phpcs:ignore WordPress.Security.ValidatedSanitizedInput

        $line = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM `{$wpdb->prefix}emeacc_import_lines` WHERE id = %d AND status = 'pending'",
            $line_id
        ) );
        if ( ! $line ) {
            wp_send_json_error( __( 'Regel niet gevonden.', 'eme-accounting' ) );
        }
        if ( ! $other_account_id || (int) $other_account_id === (int) $line->account_id ) {
            wp_send_json_error( __( 'Kies een geldige tegenrekening voor de transfer.', 'eme-accounting' ) );
        }

        [ $source, $target ] = self::transfer_legs( $line, $other_account_id );

        $journal_id = Journal::save( [
            'entry_date'        => $line->transaction_date,
            'booking_date'      => $line->value_date,
            'description'       => $line->description,
            'amount'            => abs( (float) $line->amount ),
            'direction'         => 'transfer',
            'account_id'        => $source,
            'contra_account_id' => $target,
            'bank_statement_ref'=> $line->bank_ref ?: $line->structured_ref,
            'counterparty_name' => $line->counterparty_name ?? '',
            'import_line_id'    => $line->id,
            'is_reconciled'     => 1,
            'eme_event_id'      => $eme_event_id,
        ] );

        if ( is_wp_error( $journal_id ) ) {
            wp_send_json_error( $journal_id->get_error_message() );
        }

        $wpdb->update(
            $wpdb->prefix . 'emeacc_import_lines',
            [ 'status' => 'manual', 'journal_id' => $journal_id ],
            [ 'id' => $line_id, 'status' => 'pending' ],
            [ '%s', '%d' ],
            [ '%d', '%s' ]
        );

        wp_send_json_success( [ 'journal_id' => $journal_id, 'Record' => self::get_line( $line_id ) ] );
    }
}
