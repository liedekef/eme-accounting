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
        add_action( 'wp_ajax_eme_accounting_journal_list',  [ __CLASS__, 'ajax_list' ] );
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
            'date_from'      => null,
            'date_to'        => null,
            'is_reconciled'  => null,
            'search'         => null,
            'orderby'        => 'entry_date',
            'order'          => 'DESC',
            'limit'          => 100,
            'offset'         => 0,
            'coa_id'         => 0,
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
        if ( $args['coa_id'] > 0 ) {
            $where[]   = 'coa_id = %d';
            $prepare[] = $args['coa_id'];
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
            $where[]   = '(description LIKE %s OR bank_statement_ref LIKE %s)';
            $prepare[] = $like;
            $prepare[] = $like;
        }

        $where_sql = $where ? 'WHERE ' . implode( ' AND ', $where ) : '';

        $allowed_order = [ 'entry_date', 'id', 'amount', 'description', 'created_at' ];
        $orderby       = in_array( $args['orderby'], $allowed_order, true ) ? $args['orderby'] : 'entry_date';
        $order         = strtoupper( $args['order'] ) === 'ASC' ? 'ASC' : 'DESC';

        // Always order by date + id DESC for same-date consistency
        $order_clause = $orderby === 'id'
            ? "id {$order}"
            : "entry_date {$order}, id {$order}";

        $prepare[] = (int) $args['limit'];
        $prepare[] = (int) $args['offset'];

        $sql = $wpdb->prepare(
            "SELECT j.*, a.name as account_name, ca.name as contra_account_name, coa.name as coa_name
             FROM `{$table}` j
             LEFT JOIN `{$wpdb->prefix}emeacc_accounts` a  ON a.id = j.account_id
             LEFT JOIN `{$wpdb->prefix}emeacc_accounts` ca ON ca.id = j.contra_account_id
             LEFT JOIN `{$wpdb->prefix}emeacc_chart_of_accounts` coa ON coa.id = j.coa_id
             {$where_sql}
             ORDER BY {$order_clause}
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
            'coa_id'      => null,
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
        if ( $args['coa_id'] !== null ) {
            $where[]   = 'coa_id = %d';
            $prepare[] = $args['coa_id'];
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
            'amount'             => round( (float) $data['amount'], 2 ),
            'direction'          => $direction,
            'account_id'         => (int) $data['account_id'],
            'contra_account_id'  => ! empty( $data['contra_account_id'] ) ? (int) $data['contra_account_id'] : null,
            'coa_id'             => ! empty( $data['coa_id'] ) ? (int) $data['coa_id'] : null,
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

        // Transfer requires both account_id and contra_account_id
        if ( $direction === 'transfer' ) {
            if ( empty( $data['contra_account_id'] ) ) {
                return new \WP_Error( 'missing_contra_account', __( 'Transfer vereist tegenrekening.', 'eme-accounting' ) );
            }
            $account = Accounts::get( (int) $data['account_id'] );
            $contra_account = Accounts::get( (int) $data['contra_account_id'] );
            // Create dual booking: out from account_id, in to contra_account_id
            $out_row = $row;
            $out_row['direction']  = 'out';
            $out_row['account_id'] = (int) $data['account_id'];
            $out_row['contra_account_id'] = (int) $data['contra_account_id'];
            $out_row['description'] = $row['description'] . " (transfer naar {$contra_account->name})";
            $wpdb->insert( $table, $out_row );
            $out_id = (int) $wpdb->insert_id;

            $in_row = $row;
            $in_row['direction']  = 'in';
            $in_row['account_id'] = (int) $data['contra_account_id'];
            $in_row['contra_account_id'] = (int) $data['account_id'];
            $in_row['description'] = $row['description'] . " (transfer van {$account->name})";
            $wpdb->insert( $table, $in_row );
            $in_id = (int) $wpdb->insert_id;

            do_action( 'eme_accounting_journal_created', $out_id, $out_row );
            do_action( 'eme_accounting_journal_created', $in_id, $in_row );
            return $out_id;
        }

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
        wp_send_json(['Result' => 'OK']); //ftable expects Result
    }

    public static function ajax_list(): void {
        check_ajax_referer( 'eme_accounting_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( __( 'Geen rechten.', 'eme-accounting' ) );
        }

        header( 'Content-type: application/json; charset=utf-8' );

        $result = [
            'Result'           => 'OK',
            'Records'          => [],
            'TotalRecordCount' => 0,
        ];

        $args = [
            'fiscal_year'    => isset( $_POST['fiscal_year'] ) ? (int) $_POST['fiscal_year'] : null,
            'account_id'      => isset( $_POST['account_id'] ) && $_POST['account_id'] > 0 ? (int) $_POST['account_id'] : null,
            'direction'       => isset( $_POST['direction'] ) && $_POST['direction'] !== '' ? sanitize_key( $_POST['direction'] ) : null,
            'coa_id'          => isset( $_POST['coa_id'] ) && $_POST['coa_id'] > 0 ? (int) $_POST['coa_id'] : null,
            'search'          => isset( $_POST['search'] ) && $_POST['search'] !== '' ? eme_sanitize_request( $_POST['search'] ) : null,
        ];

        $total = self::count_entries( $args );
        $result['TotalRecordCount'] = $total;

        $limit  = eme_get_datatables_limit();
        $orderby = eme_get_datatables_orderby();

        $entries = self::get_entries_for_ftable( $args, $orderby, $limit );

        foreach ( $entries as $entry ) {
            $edit_url = esc_url( admin_url( 'admin.php?page=eme-accounting-journal&edit=' . $entry->id ) );
            $amount_class = $entry->direction === 'in' ? 'in' : 'out';
            $sign = $entry->direction === 'in' ? '+' : '–';

            $result['Records'][] = [
                'id'                 => $entry->id,
                'entry_date'         => eme_localized_date( $entry->entry_date, EME_TIMEZONE ),
                'description'        => "<a href='{$edit_url}'>" . esc_html( $entry->description ) . '</a>',
                'coa_name'           => $entry->coa_name ? '<span class="tag">' . esc_html( $entry->coa_name ) . '</span>' : '',
                'bank_statement_ref' => '<span style="font-size:11px;color:#646970;max-width:120px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="' . esc_attr( $entry->bank_statement_ref ) . '">' . esc_html( $entry->bank_statement_ref ) . '</span>',
                'account_name'       => esc_html( $entry->account_name ?? '' ),
                'amount_formatted'   => '<span class="amount ' . $amount_class . '">' . $sign . ' ' . number_format_i18n( (float) $entry->amount, 2 ) . ' €</span>',
                'is_reconciled'      => $entry->is_reconciled ? '<span class="tag reconciled" title="' . esc_attr( $entry->reconciled_at ? eme_localized_date( $entry->reconciled_at, EME_TIMEZONE ) : '' ) . '">✓</span>' : '',
            ];
        }

        print wp_json_encode( $result );
        wp_die();
    }

    public static function count_entries( array $args = [] ): int {
        global $wpdb;
        $table = $wpdb->prefix . 'emeacc_journal';

        $where   = [];
        $prepare = [];

        if ( ! empty( $args['fiscal_year'] ) ) {
            $where[]   = 'fiscal_year = %d';
            $prepare[] = (int) $args['fiscal_year'];
        }
        if ( ! empty( $args['account_id'] ) ) {
            $where[]   = '(account_id = %d OR contra_account_id = %d)';
            $prepare[] = (int) $args['account_id'];
            $prepare[] = (int) $args['account_id'];
        }
        if ( ! empty( $args['direction'] ) ) {
            $where[]   = 'direction = %s';
            $prepare[] = $args['direction'];
        }
        if ( ! empty( $args['coa_id'] ) ) {
            $where[]   = 'coa_id = %d';
            $prepare[] = (int) $args['coa_id'];
        }
        if ( ! empty( $args['search'] ) ) {
            $like      = '%' . $wpdb->esc_like( $args['search'] ) . '%';
            $where[]   = '(description LIKE %s OR bank_statement_ref LIKE %s)';
            $prepare[] = $like;
            $prepare[] = $like;
        }

        $where_sql = $where ? 'WHERE ' . implode( ' AND ', $where ) : '';

        if ( empty( $prepare ) ) {
            return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
        }

        $sql = $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} {$where_sql}",
            ...$prepare
        );

        return (int) $wpdb->get_var( $sql );
    }

    public static function get_entries_for_ftable( array $args = [], string $orderby = '', string $limit = '' ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'emeacc_journal';

        $where   = [];
        $prepare = [];

        if ( ! empty( $args['fiscal_year'] ) ) {
            $where[]   = 'j.fiscal_year = %d';
            $prepare[] = (int) $args['fiscal_year'];
        }
        if ( ! empty( $args['account_id'] ) ) {
            $where[]   = '(j.account_id = %d OR j.contra_account_id = %d)';
            $prepare[] = (int) $args['account_id'];
            $prepare[] = (int) $args['account_id'];
        }
        if ( ! empty( $args['direction'] ) ) {
            $where[]   = 'j.direction = %s';
            $prepare[] = $args['direction'];
        }
        if ( ! empty( $args['coa_id'] ) ) {
            $where[]   = 'j.coa_id = %d';
            $prepare[] = (int) $args['coa_id'];
        }
        if ( ! empty( $args['search'] ) ) {
            $like      = '%' . $wpdb->esc_like( $args['search'] ) . '%';
            $where[]   = '(j.description LIKE %s OR j.bank_statement_ref LIKE %s)';
            $prepare[] = $like;
            $prepare[] = $like;
        }
        $where_sql = $where ? 'WHERE ' . implode( ' AND ', $where ) : '';

        $order_clause = $orderby ?: 'j.entry_date DESC, j.id DESC';
        if ( stripos( $order_clause, 'ORDER BY' ) !== 0 ) {
            $order_clause = 'ORDER BY ' . $order_clause;
        }

        $sql = "SELECT j.*, a.name as account_name, ca.name as contra_account_name, coa.name as coa_name
                FROM `{$table}` j
                LEFT JOIN `{$wpdb->prefix}emeacc_accounts` a  ON a.id = j.account_id
                LEFT JOIN `{$wpdb->prefix}emeacc_accounts` ca ON ca.id = j.contra_account_id
                LEFT JOIN `{$wpdb->prefix}emeacc_chart_of_accounts` coa ON coa.id = j.coa_id
                {$where_sql}
                {$order_clause}
                {$limit}";

        if ( empty( $prepare ) ) {
            return $wpdb->get_results( $sql );
        }

        return $wpdb->get_results( $wpdb->prepare( $sql, ...$prepare ) );
    }
}
