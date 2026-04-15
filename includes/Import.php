<?php
namespace EMEAccounting;

defined( 'ABSPATH' ) || exit;

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
    }

    /**
     * Delete import log and all related lines.
     */
    public static function delete_log( int $log_id ): void {
        global $wpdb;
        $wpdb->delete( $wpdb->prefix . 'emeacc_import_lines', [ 'log_id' => $log_id ], [ '%d' ] );
        $wpdb->delete( $wpdb->prefix . 'emeacc_import_log', [ 'id' => $log_id ], [ '%d' ] );
    }

    public static function ajax_delete_log(): void {
        check_ajax_referer( 'eme_accounting_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error();
        $id = (int) ( $_POST['id'] ?? 0 );
        if ( $id > 0 ) {
            self::delete_log( $id );
        }
        wp_send_json_success();
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
     * @return object             Import log row or error.
     */
    public static function process_upload( int $account_id, array $file, string $format = 'auto' ): object {
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
                'currency'         => $line['currency'] ?? 'EUR',
                'bank_ref'         => substr( $line['bank_ref'] ?? '', 0, 99 ),
                'structured_ref'   => substr( $line['structured_ref'] ?? '', 0, 21 ),
                'raw_data'         => json_encode( $line ),
                'status'           => 'pending',
            ] );
            $inserted++;
        }

        // Run automatic matching
        $matched = self::auto_match( $log_id, $account_id );

        $wpdb->update(
            $wpdb->prefix . 'emeacc_import_log',
            [
                'total_lines'     => $inserted,
                'matched_lines'   => $matched,
                'unmatched_lines' => $inserted - $matched,
            ],
            [ 'id' => $log_id ],
            null,
            [ '%d' ]
        );

        return (object) [
            'log_id'   => $log_id,
            'total'    => $inserted,
            'matched'  => $matched,
            'pending'  => $inserted - $matched,
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
        if ( str_contains( $content, 'Datum verrichting;Beschrijving;Bedrag;Valutadatum' ) ) return 'belfius';
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
        return self::parse_csv_generic( $content, ';', [
            'date'             => 'Boekingsdatum',
            'value_date'       => 'Valutadatum',
            'amount'           => 'Bedrag',
            'description'      => 'Mededelingen',
            'counterparty_name'=> 'Naam tegenpartij bevat',
            'counterparty_iban'=> 'Rekening tegenpartij',
        ] );
    }

    private static function parse_generic_csv( string $content ): array|\WP_Error {
        // Auto-detect delimiter
        $delimiters = [ ';', ',', "\t", '|' ];
        $counts     = [];
        foreach ( $delimiters as $d ) {
            $counts[ $d ] = substr_count( strtok( $content, "\n" ), $d );
        }
        arsort( $counts );
        $delimiter = array_key_first( $counts );

        return self::parse_csv_generic( $content, $delimiter, [] );
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

        $header = str_getcsv( array_shift( $lines ), $delimiter );
        $header = array_map( 'trim', $header );

        // Build reverse map: csv_header => normalised_key
        $reverse = [];
        foreach ( $col_map as $norm => $csv ) {
            $idx = array_search( $csv, $header, true );
            if ( $idx !== false ) {
                $reverse[ $idx ] = $norm;
            }
        }

        $result = [];
        foreach ( $lines as $raw ) {
            $cells = str_getcsv( $raw, $delimiter );

            if ( $col_map ) {
                $row = [];
                foreach ( $reverse as $idx => $norm ) {
                    $row[ $norm ] = trim( $cells[ $idx ] ?? '' );
                }
            } else {
                // Generic: take first columns by position
                $row = [
                    'date'        => trim( $cells[0] ?? '' ),
                    'description' => trim( $cells[1] ?? '' ),
                    'amount'      => trim( $cells[2] ?? '' ),
                ];
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
     * QUERY HELPERS
     * ------------------------------------------------------------------ */

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
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( __( 'Geen rechten.', 'eme-accounting' ) );
        }
        $account_id = (int) ( $_POST['account_id'] ?? 0 );
        $format     = eme_sanitize_request( $_POST['format'] ?? 'auto' );
        $result     = self::process_upload( $account_id, $_FILES['import_file'], $format );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }
        wp_send_json_success( (array) $result );
    }

    public static function ajax_reconcile_line(): void {
        check_ajax_referer( 'eme_accounting_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( __( 'Geen rechten.', 'eme-accounting' ) );
        }
        global $wpdb;
        $line_id    = (int) ( $_POST['line_id'] ?? 0 );
        $journal_id = (int) ( $_POST['journal_id'] ?? 0 );
        $wpdb->update(
            $wpdb->prefix . 'emeacc_import_lines',
            [ 'status' => 'matched', 'journal_id' => $journal_id ],
            [ 'id' => $line_id ],
            [ '%s', '%d' ],
            [ '%d' ]
        );
        Journal::mark_reconciled( $journal_id );
        wp_send_json_success();
    }

    public static function ajax_ignore_line(): void {
        check_ajax_referer( 'eme_accounting_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( __( 'Geen rechten.', 'eme-accounting' ) );
        }
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'emeacc_import_lines',
            [ 'status' => 'ignored' ],
            [ 'id' => (int) ( $_POST['line_id'] ?? 0 ) ],
            [ '%s' ],
            [ '%d' ]
        );
        wp_send_json_success();
    }

    public static function ajax_auto_reconcile(): void {
        check_ajax_referer( 'eme_accounting_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( __( 'Geen rechten.', 'eme-accounting' ) );
        }
        $log_id     = (int) ( $_POST['log_id'] ?? 0 );
        $account_id = (int) ( $_POST['account_id'] ?? 0 );
        $matched    = self::auto_match( $log_id, $account_id );
        wp_send_json_success( [ 'matched' => $matched ] );
    }

    public static function ajax_create_from_line(): void {
        check_ajax_referer( 'eme_accounting_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( __( 'Geen rechten.', 'eme-accounting' ) );
        }
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
            'category'          => eme_sanitize_request( $_POST['category'] ?? '' ),
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

        wp_send_json_success( [ 'journal_id' => $journal_id ] );
    }
}
