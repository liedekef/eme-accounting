<?php
namespace EMEAccounting;

defined( 'ABSPATH' ) || exit;

/**
 * Journal entries: create, read, update, delete + query helpers.
 *
 * Every financial transaction lives here.
 * The optional `bank_statement_ref` field holds a human-readable
 * bank-statement reference like "BE68 5390 0754 7034 / 2024-001"
 * or a structured communication ("+++123/4567/89012+++").
 */
class Journal {

    public static function init(): void {
        add_action( 'wp_ajax_eme_accounting_save_entry',   [ __CLASS__, 'ajax_save' ] );
        add_action( 'wp_ajax_eme_accounting_delete_entry', [ __CLASS__, 'ajax_delete' ] );
    }

    /* ------------------------------------------------------------------
     * READ
     * ------------------------------------------------------------------ */

    /**
     * Query journal entries with filters.
     *
     * @param  array<string,mixed> $args
     * @return array<int,object>
     */
    public static function get_entries( array $args = [] ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'emeacc_journal';

        $defaults = [
            'fiscal_year'    => null,
            'account_id'     => null,
            'direction'      => null,
            'category'       => null,
            'date_from'      => null,
            'date_to'        => null,
            'is_reconciled'  => null,
            'search'         => null,
            'orderby'        => 'entry_date',
            'order'          => 'DESC',
            'limit'          => 100,
            'offset'         => 0,
        ];
        $args = wp_parse_args( $args, $defaults );

        $where   = [];
        $prepare = [];

        if ( $args['fiscal_year'] !== null ) {
            $where[]   = 'fiscal_year = %d';
            $prepare[] = (int) $args['fiscal_year'];
        }
        if ( $args['account_id'] !== null ) {
            $where[]   = '(account_id = %d OR contra_account_id = %d)';
            $prepare[] = (int) $args['account_id'];
            $prepare[] = (int) $args['account_id'];
        }
        if ( $args['direction'] !== null ) {
            $where[]   = 'direction = %s';
            $prepare[] = $args['direction'];
        }
        if ( $args['category'] !== null ) {
            $where[]   = 'category = %s';
            $prepare[] = $args['category'];
        }
        if ( $args['date_from'] !== null ) {
            $where[]   = 'entry_date >= %s';
            $prepare[] = $args['date_from'];
        }
        if ( $args['date_to'] !== null ) {
            $where[]   = 'entry_date <= %s';
            $prepare[] = $args['date_to'];
        }
        if ( $args['is_reconciled'] !== null ) {
            $where[]   = 'is_reconciled = %d';
            $prepare[] = (int) $args['is_reconciled'];
        }
        if ( ! empty( $args['search'] ) ) {
            $like      = '%' . $wpdb->esc_like( $args['search'] ) . '%';
            $where[]   = '(description LIKE %s OR bank_statement_ref LIKE %s OR category LIKE %s)';
            $prepare[] = $like;
            $prepare[] = $like;
            $prepare[] = $like;
        }

        $where_sql = $where ? 'WHERE ' . implode( ' AND ', $where ) : '';

        $allowed_order = [ 'entry_date', 'amount', 'category', 'description', 'created_at' ];
        $orderby       = in_array( $args['orderby'], $allowed_order, true ) ? $args['orderby'] : 'entry_date';
        $order         = strtoupper( $args['order'] ) === 'ASC' ? 'ASC' : 'DESC';

        $prepare[] = (int) $args['limit'];
        $prepare[] = (int) $args['offset'];

        $sql = $wpdb->prepare(
            "SELECT j.*, a.name as account_name, ca.name as contra_account_name
             FROM `{$table}` j
             LEFT JOIN `{$wpdb->prefix}emeacc_accounts` a  ON a.id = j.account_id
             LEFT JOIN `{$wpdb->prefix}emeacc_accounts` ca ON ca.id = j.contra_account_id
             {$where_sql}
             ORDER BY {$orderby} {$order}
             LIMIT %d OFFSET %d",
            ...$prepare
        );

        return $wpdb->get_results( $sql );
    }

    public static function get( int $id ): ?object {
        global $wpdb;
        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT j.*, a.name as account_name
                 FROM `{$wpdb->prefix}emeacc_journal` j
                 LEFT JOIN `{$wpdb->prefix}emeacc_accounts` a ON a.id = j.account_id
                 WHERE j.id = %d",
                $id
            )
        );
    }

    /**
     * Sum of income and expenses for a given period / account / year.
     *
     * @return array{income: float, expenses: float, net: float}
     */
    public static function get_totals( array $args = [] ): array {
        global $wpdb;
        $defaults = [
            'fiscal_year' => null,
            'account_id'  => null,
            'date_from'   => null,
            'date_to'     => null,
            'category'    => null,
        ];
        $args    = wp_parse_args( $args, $defaults );
        $where   = [];
        $prepare = [];

        if ( $args['fiscal_year'] !== null ) {
            $where[]   = 'fiscal_year = %d';
            $prepare[] = (int) $args['fiscal_year'];
        }
        if ( $args['account_id'] !== null ) {
            $where[]   = 'account_id = %d';
            $prepare[] = (int) $args['account_id'];
        }
        if ( $args['date_from'] !== null ) {
            $where[]   = 'entry_date >= %s';
            $prepare[] = $args['date_from'];
        }
        if ( $args['date_to'] !== null ) {
            $where[]   = 'entry_date <= %s';
            $prepare[] = $args['date_to'];
        }
        if ( $args['category'] !== null ) {
            $where[]   = 'category = %s';
            $prepare[] = $args['category'];
        }

        $where_sql = $where ? 'WHERE ' . implode( ' AND ', $where ) . " AND direction != 'transfer'" : "WHERE direction != 'transfer'";

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT
                    COALESCE(SUM(CASE WHEN direction = 'in'  THEN amount ELSE 0 END), 0) AS income,
                    COALESCE(SUM(CASE WHEN direction = 'out' THEN amount ELSE 0 END), 0) AS expenses
                 FROM `{$wpdb->prefix}emeacc_journal`
                 {$where_sql}",
                ...$prepare
            )
        );

        $income   = (float) ( $row->income ?? 0 );
        $expenses = (float) ( $row->expenses ?? 0 );

        return [
            'income'   => $income,
            'expenses' => $expenses,
            'net'      => $income - $expenses,
        ];
    }

    /**
     * Get distinct categories from journal.
     *
     * @return string[]
     */
    public static function get_categories(): array {
        global $wpdb;
        return $wpdb->get_col(
            "SELECT DISTINCT category FROM `{$wpdb->prefix}emeacc_journal` WHERE category != '' ORDER BY category"
        );
    }

    /* ------------------------------------------------------------------
     * WRITE
     * ------------------------------------------------------------------ */

    /**
     * Insert or update a journal entry.
     *
     * @param  array<string,mixed> $data
     * @return int|\WP_Error
     */
    public static function save( array $data ): int|\WP_Error {
        global $wpdb;
        $table = $wpdb->prefix . 'emeacc_journal';

        if ( empty( $data['account_id'] ) ) {
            return new \WP_Error( 'missing_account', __( 'Geen rekening geselecteerd.', 'eme-accounting' ) );
        }
        if ( empty( $data['amount'] ) || (float) $data['amount'] <= 0 ) {
            return new \WP_Error( 'invalid_amount', __( 'Bedrag moet groter zijn dan 0.', 'eme-accounting' ) );
        }

        $entry_date  = eme_sanitize_request( $data['entry_date'] ?? current_time( 'Y-m-d' ) );
        $fiscal_year = isset( $data['fiscal_year'] )
            ? (int) $data['fiscal_year']
            : (int) gmdate( 'Y', strtotime( $entry_date ) );

        $allowed_directions = [ 'in', 'out', 'transfer' ];
        $direction          = in_array( $data['direction'] ?? 'out', $allowed_directions, true )
            ? $data['direction']
            : 'out';

        $row = [
            'entry_date'         => $entry_date,
            'booking_date'       => ! empty( $data['booking_date'] ) ? eme_sanitize_request( $data['booking_date'] ) : null,
            'description'        => eme_sanitize_request( $data['description'] ?? '' ),
            'category'           => eme_sanitize_request( $data['category'] ?? '' ),
            'amount'             => round( (float) $data['amount'], 2 ),
            'direction'          => $direction,
            'account_id'         => (int) $data['account_id'],
            'contra_account_id'  => ! empty( $data['contra_account_id'] ) ? (int) $data['contra_account_id'] : null,
            // bank_statement_ref: optioneel rekeningnummer / ref van bankafschrift
            'bank_statement_ref' => eme_sanitize_request( $data['bank_statement_ref'] ?? '' ),
            'bank_statement_seq' => eme_sanitize_request( $data['bank_statement_seq'] ?? '' ),
            'payment_method'     => eme_sanitize_request( $data['payment_method'] ?? '' ),
            'vat_code'           => eme_sanitize_request( $data['vat_code'] ?? '' ),
            'vat_amount'         => round( (float) ( $data['vat_amount'] ?? 0 ), 2 ),
            'eme_payment_id'     => ! empty( $data['eme_payment_id'] ) ? (int) $data['eme_payment_id'] : null,
            'eme_event_id'       => ! empty( $data['eme_event_id'] ) ? (int) $data['eme_event_id'] : null,
            'attachment_url'     => esc_url_raw( $data['attachment_url'] ?? '' ),
            'is_reconciled'      => (int) (bool) ( $data['is_reconciled'] ?? 0 ),
            'import_line_id'     => ! empty( $data['import_line_id'] ) ? (int) $data['import_line_id'] : null,
            'fiscal_year'        => $fiscal_year,
            'notes'              => eme_sanitize_request( $data['notes'] ?? '' ),
            'created_by'         => get_current_user_id() ?: null,
        ];

        $id = isset( $data['id'] ) ? (int) $data['id'] : 0;

        if ( $id > 0 ) {
            unset( $row['created_by'] );
            $wpdb->update( $table, $row, [ 'id' => $id ], null, [ '%d' ] );
            do_action( 'eme_accounting_journal_updated', $id, $row );
            return $id;
        }

        $wpdb->insert( $table, $row );
        $new_id = (int) $wpdb->insert_id;
        do_action( 'eme_accounting_journal_created', $new_id, $row );
        return $new_id;
    }

    public static function delete( int $id ): bool {
        global $wpdb;
        $wpdb->delete( $wpdb->prefix . 'emeacc_journal', [ 'id' => $id ], [ '%d' ] );
        do_action( 'eme_accounting_journal_deleted', $id );
        return true;
    }

    public static function mark_reconciled( int $id ): void {
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'emeacc_journal',
            [
                'is_reconciled' => 1,
                'reconciled_at' => current_time( 'mysql' ),
            ],
            [ 'id' => $id ],
            [ '%d', '%s' ],
            [ '%d' ]
        );
    }

    /* ------------------------------------------------------------------
     * AJAX
     * ------------------------------------------------------------------ */

    public static function ajax_save(): void {
        check_ajax_referer( 'eme_accounting_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( __( 'Geen rechten.', 'eme-accounting' ) );
        }
        $result = self::save( $_POST );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }
        wp_send_json_success( [ 'id' => $result ] );
    }

    public static function ajax_delete(): void {
        check_ajax_referer( 'eme_accounting_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( __( 'Geen rechten.', 'eme-accounting' ) );
        }
        self::delete( (int) ( $_POST['id'] ?? 0 ) );
        wp_send_json_success();
    }
}
