<?php
namespace EMEAccounting;

defined( 'ABSPATH' ) || exit;

use EMEAccounting\Capabilities;

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
        add_action( 'wp_ajax_eme_accounting_events_list',  [ __CLASS__, 'ajax_events_snapselect' ] );
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
            'amount'         => 0,
            'eme_event_id'   => null,
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
            if ($args['direction'] == 'transfer') {
                $where[]   = 'transfer_id IS NOT NULL';
            } else {
                $where[]   = 'direction = %s AND transfer_id IS NULL';
                $prepare[] = $args['direction'];
            }
        }
        if ( $args['amount'] > 0 ) {
            $where[]   = 'amount = %d';
            $prepare[] = (int) $args['amount'];
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
        if ( ! empty( $args['eme_event_id'] ) ) {
            $where[]   = 'j.eme_event_id = %d';
            $prepare[] = (int) $args['eme_event_id'];
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

        $where_sql = $where ? 'WHERE ' . implode( ' AND ', $where ) . " AND transfer_id IS NULL" : "WHERE transfer_id IS NULL";

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

        $entry_date = eme_sanitize_request( $data['entry_date'] ?? current_time( 'Y-m-d' ) );

        $fy = FiscalYear::get_or_create_for_date( $entry_date );
        if ( ! $fy ) {
            return new \WP_Error( 'no_fiscal_year', __( 'Geen fiscaal jaar gevonden voor deze datum.', 'eme-accounting' ) );
        }

        $fiscal_year = $fy->year;

        if ( $fy->status === 'closed' ) {
            return new \WP_Error(
                'year_closed',
                sprintf(
                    __( 'Boekjaar %d is afgesloten op %s.', 'eme-accounting' ),
                    $fiscal_year,
                    date_i18n( 'd/m/Y', strtotime( $fy->closed_at ) )
                )
            );
        }

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
            'coa_id'             => ! empty( $data['coa_id'] ) ? (int) $data['coa_id'] : null,
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
            'is_closing_entry'   => (int) (bool) ( $data['is_closing_entry'] ?? 0 ),
            'notes'              => eme_sanitize_request( $data['notes'] ?? '' ),
            'created_by'         => get_current_user_id() ?: null,
        ];

        $id = isset( $data['id'] ) ? (int) $data['id'] : 0;

        if ( !empty( $id) ) {
            $orig_entry = self::get($id);
            if ($orig_entry->direction != $direction) {
                return new \WP_Error( 'no_direction_update', sprintf(__( 'Richting (%s =>%s) kan niet aangepast worden.', 'eme-accounting' ),$orig_entry->direction,$direction) );
            }
        }
        // Transfer requires both account_id and contra_account_id
        if ( $direction === 'transfer' ) {
            if ( !empty( $id) ) {
                return new \WP_Error( 'no_transfer_update', __( 'Transfer kan niet aangepast worden.', 'eme-accounting' ) );
            }
            if ( empty( $data['contra_account_id'] ) ) {
                return new \WP_Error( 'missing_contra_account', __( 'Transfer vereist tegenrekening.', 'eme-accounting' ) );
            }
            $account = Accounts::get( (int) $data['account_id'] );
            $contra_account = Accounts::get( (int) $data['contra_account_id'] );
            // Create dual booking: out from account_id, in to contra_account_id
            // Insert out-leg first (without transfer_id), then link both legs.
            $wpdb->query( 'START TRANSACTION' );
            $out_row = $row;
            $out_row['direction']         = 'out';
            $out_row['account_id']        = (int) $data['account_id'];
            $out_row['contra_account_id'] = (int) $data['contra_account_id'];
            $out_row['description']       = $row['description'] . " (transfer naar {$contra_account->name})";
            $wpdb->insert( $table, $out_row );
            $out_id = (int) $wpdb->insert_id;

            // now the in-leg
            $in_row = $row;
            $in_row['direction']          = 'in';
            $in_row['account_id']         = (int) $data['contra_account_id'];
            $in_row['contra_account_id']  = (int) $data['account_id'];
            $in_row['amount']             = $row['amount'];
            $in_row['transfer_id']        = $out_id; // link to out-leg
            $in_row['description']        = $row['description'] . " (transfer van {$account->name})";
            $wpdb->insert( $table, $in_row );
            $in_id = (int) $wpdb->insert_id;

            // Back-fill transfer_id on out-leg so both legs point to each other's pair.
            $wpdb->update( $table, [ 'transfer_id' => $in_id ], [ 'id' => $out_id ], [ '%d' ], [ '%d' ] );
            $out_row['transfer_id'] = $in_id;
            if ( $wpdb->last_error ) {
                $wpdb->query( 'ROLLBACK' );
                return new \WP_Error( 'db_problem_transfer', __( 'Transfer boeking niet gelukt.', 'eme-accounting' ) );
            }
            $wpdb->query( 'COMMIT' );

            do_action( 'eme_accounting_journal_created', $out_id, $out_row );
            do_action( 'eme_accounting_journal_created', $in_id, $in_row );
            return $out_id;
        }

        if ( $id > 0 ) {
            unset( $row['created_by'] );
            $row['updated_by'] = get_current_user_id() ?: null;
            $wpdb->update( $table, $row, [ 'id' => $id ], null, [ '%d' ] );
            do_action( 'eme_accounting_journal_updated', $id, $row );
            return $id;
        }

        $wpdb->insert( $table, $row );
        $new_id = (int) $wpdb->insert_id;
        do_action( 'eme_accounting_journal_created', $new_id, $row );
        return $new_id;
    }

    public static function delete( int $id ): bool|\WP_Error {
        global $wpdb;
        $table = $wpdb->prefix . 'emeacc_journal';

        $entry = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `$table` WHERE id = %d", $id ) );
        if ( ! $entry ) {
            return true;
        }

        if ( FiscalYear::is_closed( (int) $entry->fiscal_year ) ) {
            return new \WP_Error( 'year_closed', __( 'Kan boeking niet verwijderen: boekjaar is afgesloten.', 'eme-accounting' ) );
        }

        $transfer_id = $wpdb->get_var( $wpdb->prepare( "SELECT transfer_id FROM `$table` WHERE id = %d AND direction='transfer'", $id ) );
        if ( $transfer_id ) {
            $wpdb->delete( $table, [ 'id' => (int) $transfer_id ], [ '%d' ] );
            do_action( 'eme_accounting_journal_deleted', (int) $transfer_id );
        }

        $wpdb->delete( $table, [ 'id' => $id ], [ '%d' ] );
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
        Capabilities::require_manage_ajax( 'journal' );
        $result = self::save( $_POST );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }
        wp_send_json_success( [ 'id' => $result ] );
    }

    public static function ajax_delete(): void {
        check_ajax_referer( 'eme_accounting_nonce', 'nonce' );
        Capabilities::require_manage_ajax( 'journal' );
        $result = self::delete( (int) ( $_POST['id'] ?? 0 ) );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }
        wp_send_json( [ 'Result' => 'OK' ] );
    }

    public static function ajax_list(): void {
        check_ajax_referer( 'eme_accounting_nonce', 'nonce' );
        Capabilities::require_view_ajax( 'journal' );

        $formatter = new \NumberFormatter('nl_BE', \NumberFormatter::CURRENCY);

        header( 'Content-type: application/json; charset=utf-8' );

        $result = [
            'Result'           => 'OK',
            'Records'          => [],
            'TotalRecordCount' => 0,
        ];

        $args = [
            'fiscal_year'  => isset( $_POST['fiscal_year'] ) ? (int) $_POST['fiscal_year'] : null,
            'account_id'   => isset( $_POST['account_id'] ) && $_POST['account_id'] > 0 ? (int) $_POST['account_id'] : null,
            'direction'    => isset( $_POST['direction'] ) && $_POST['direction'] !== '' ? sanitize_key( $_POST['direction'] ) : null,
            'coa_id'       => isset( $_POST['coa_id'] ) && $_POST['coa_id'] > 0 ? (int) $_POST['coa_id'] : null,
            'eme_event_id' => isset( $_POST['eme_event_id'] ) && $_POST['eme_event_id'] > 0 ? (int) $_POST['eme_event_id'] : null,
            'amount'       => isset( $_POST['amount'] ) && $_POST['amount'] > 0 ? (int) $_POST['amount'] : null,
            'search'       => isset( $_POST['search'] ) && $_POST['search'] !== '' ? eme_sanitize_request( $_POST['search'] ) : null,
        ];

        $total = self::count_entries( $args );
        $result['TotalRecordCount'] = $total;

        $limit  = eme_get_datatables_limit();
        $orderby = eme_get_datatables_orderby();
        $entries = self::get_entries_for_ftable( $args, $orderby, $limit );

        foreach ( $entries as $entry ) {
            $signed_amount = $entry->direction === 'in'
                ? (float) $entry->amount
                : -1 * (float) $entry->amount;

            $event_name = '';
            if ($entry->eme_event_id) {
                $event = eme_get_event($entry->eme_event_id);
                if ($event)
                    $event_name = trim( eme_translate( $event['event_name'] ) . ' (' . eme_localized_datetime( $event['event_start'] ) . ')' );
            }
            $result['Records'][] = [
                'id'                 => (int) $entry->id,
                'entry_date'         => eme_localized_date( $entry->entry_date, EME_TIMEZONE ),
                'edit_url'           => esc_url( admin_url( 'admin.php?page=emeaccounting-journal&edit=' . $entry->id ) ),
                'description'        => $entry->description,
                'coa_name'           => $entry->coa_name ?? '',
                'eme_event'          => $event_name,
                'bank_statement_ref' => $entry->bank_statement_ref ?? '',
                'account_name'       => $entry->account_name ?? '',
                'amount'             => $signed_amount,
                'direction'          => $entry->direction,
                'is_reconciled'      => (bool) $entry->is_reconciled,
                'reconciled_at'      => $entry->is_reconciled && $entry->reconciled_at
                                            ? eme_localized_date( $entry->reconciled_at, EME_TIMEZONE )
                                            : '',
            ];
        }

        print wp_json_encode( $result );
        wp_die();
    }

    public static function ajax_events_snapselect() {
        check_ajax_referer( 'eme_accounting_nonce', 'nonce' );
        Capabilities::require_view_ajax( 'journal' );

        global $wpdb;
        header( 'Content-type: application/json; charset=utf-8' );
        // snapselect gives us paging, but currently we ignore it here and return everything we can
        $q         = isset( $_REQUEST['q'] ) ? strtolower( eme_sanitize_request( $_REQUEST['q'] ) ) : '';
        $year      = isset( $_REQUEST['entry_date'] ) ? intval(date( 'Y', strtotime( $_REQUEST['entry_date'] ) )) : (int) gmdate( 'Y' );
        $search_all= isset( $_REQUEST['search_all'] ) ? intval( $_REQUEST['search_all'] ) : 0;
        $scope="future";
        if ($search_all) {
            $scope="all";
        //} else {
        //    $dates = FiscalYear::calculate_dates( $year );
        //    $scope = $dates['start_date'].'--'.$dates['end_date'];
        }
        $pagesize  = isset( $_REQUEST['pagesize'] ) ? intval( $_REQUEST['pagesize'] ) : 20;
        $mysql_pagesize = $pagesize+1;
        $start     = ( isset( $_REQUEST['page'] ) && intval( $_REQUEST['page'] ) > 0 ) ? ( intval( $_REQUEST['page'] ) - 1 ) * $pagesize : 0;
        //$prepared_sql = $wpdb->prepare( "event_name LIKE %s", '%'.$wpdb->esc_like($q).'%' );
        //$events       = eme_get_events(limit: $mysql_pagesize, offset: $start, scope: $scope, extra_conditions: ['prepared'=>$prepared_sql]);
        // eme_search_events understands past/future/all scopes only
        $events     = eme_search_events( $q, $scope, 1, 0, 0, $start, $mysql_pagesize );
        $records     = [];
        foreach ( $events as $event ) {
            $records[] = [
                'id'   => $event['event_id'],
                'text' => trim( eme_translate( $event['event_name'] ) . ' (' . eme_localized_date( $event['event_start'], EME_TIMEZONE, 1 ) . ')' ),
            ];
        }
        $hasMore = count($records) > $pagesize;
        if ($hasMore)
            $records = array_slice($records, 0, $pagesize);
        print wp_json_encode( [ 'Records' => $records, 'hasMore' => $hasMore ] );
        wp_die();
    }

    /**
     * Build a WHERE clause and prepare-values array from the shared filter args
     * used by count_entries() and get_entries_for_ftable().
     *
     * Pass $alias = 'j' when the query uses a table alias (get_entries_for_ftable),
     * leave empty for unaliased queries (count_entries).
     *
     * @param  array<string,mixed> $args
     * @param  string              $alias  Optional table alias prefix (without dot).
     * @return array{ 0: string, 1: array<int,mixed> }  [ $where_sql, $prepare ]
     */
    private static function build_filter_where( array $args, string $alias = '' ): array {
        global $wpdb;
        $p       = $alias ? $alias . '.' : '';
        $where   = [];
        $prepare = [];

        if ( ! empty( $args['fiscal_year'] ) ) {
            $where[]   = "{$p}fiscal_year = %d";
            $prepare[] = (int) $args['fiscal_year'];
        }
        if ( ! empty( $args['account_id'] ) ) {
            $where[]   = "({$p}account_id = %d OR {$p}contra_account_id = %d)";
            $prepare[] = (int) $args['account_id'];
            $prepare[] = (int) $args['account_id'];
        }
        if ( ! empty( $args['direction'] ) ) {
            if ($args['direction'] == 'transfer') {
                $where[]   = "{$p}transfer_id IS NOT NULL";
            } else {
                $where[]   = "{$p}direction = %s AND transfer_id IS NULL";
                $prepare[] = $args['direction'];
            }
        }
        if ( ! empty( $args['coa_id'] ) ) {
            $where[]   = "{$p}coa_id = %d";
            $prepare[] = (int) $args['coa_id'];
        }
        if ( ! empty( $args['amount'] ) ) {
            $where[]   = "{$p}amount = %d";
            $prepare[] = (int) $args['amount'];
        }
        if ( ! empty( $args['eme_event_id'] ) ) {
            $where[]   = "{$p}eme_event_id = %d";
            $prepare[] = (int) $args['eme_event_id'];
        }
        if ( ! empty( $args['search'] ) ) {
            $like      = '%' . $wpdb->esc_like( $args['search'] ) . '%';
            $where[]   = "({$p}description LIKE %s OR {$p}bank_statement_ref LIKE %s)";
            $prepare[] = $like;
            $prepare[] = $like;
        }

        $where_sql = $where ? 'WHERE ' . implode( ' AND ', $where ) : '';
        return [ $where_sql, $prepare ];
    }

    public static function count_entries( array $args = [] ): int {
        global $wpdb;
        $table = $wpdb->prefix . 'emeacc_journal';

        [ $where_sql, $prepare ] = self::build_filter_where( $args );

        if ( empty( $prepare ) ) {
            return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
        }

        return (int) $wpdb->get_var(
            $wpdb->prepare( "SELECT COUNT(*) FROM {$table} {$where_sql}", ...$prepare )
        );
    }

    public static function get_entries_for_ftable( array $args = [], string $orderby = '', string $limit = '' ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'emeacc_journal';

        [ $where_sql, $prepare ] = self::build_filter_where( $args, 'j' );

        $order_clause = $orderby ?: 'j.entry_date DESC, j.id DESC';
        if ( stripos( $order_clause, 'ORDER BY' ) !== 0 ) {
            $order_clause = 'ORDER BY ' . $order_clause;
        }

        // $order_clause and $limit come from eme_get_datatables_orderby / eme_get_datatables_limit
        // and are guaranteed safe by the EME framework.
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
