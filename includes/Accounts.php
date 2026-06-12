<?php
namespace EMEAccounting;

defined( 'ABSPATH' ) || exit;

use EMEAccounting\Capabilities;

/**
 * Financial Accounts CRUD & helpers.
 */
class Accounts {

    public static function init(): void {
        add_action( 'wp_ajax_eme_accounting_save_account',   [ __CLASS__, 'ajax_save' ] );
        add_action( 'wp_ajax_eme_accounting_delete_account', [ __CLASS__, 'ajax_delete' ] );
    }

    /* ------------------------------------------------------------------
     * READ
     * ------------------------------------------------------------------ */

    /**
     * Get all active accounts.
     *
     * @return array<int, object>
     */
    public static function get_all(): array {
        global $wpdb;
        $table = $wpdb->prefix . 'emeacc_accounts';
        return $wpdb->get_results( "SELECT * FROM `$table` ORDER BY sort_order, name" );
    }

    /**
     * Get account by ID.
     */
    public static function get( int $id ): ?object {
        global $wpdb;
        return $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM `{$wpdb->prefix}emeacc_accounts` WHERE id = %d", $id )
        );
    }

    /**
     * Get accounts as id => name array (for dropdowns).
     */
    public static function get_dropdown(): array {
        $accounts = self::get_all();
        $out      = [];
        foreach ( $accounts as $a ) {
            $label = $a->name;
            if ( $a->iban ) {
                $label .= ' (' . $a->iban . ')';
            }
            $out[ (int) $a->id ] = $label;
        }
        return $out;
    }

    /**
     * Calculate balance on a specific end date for an account.
     * Balance = opening_balance + total_in - total_out (from aggregated totals table).
     */
    public static function get_balance( int $account_id, string $up_to_date ): float {
        global $wpdb;
        $account = self::get( $account_id );
        if ( ! $account ) {
            return 0.0;
        }

        $sum = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COALESCE(SUM(
                    CASE WHEN direction = 'in' THEN amount WHEN direction = 'out' THEN -amount ELSE 0 END
            ), 0)
            FROM `{$wpdb->prefix}emeacc_journal`
            WHERE account_id = %d AND entry_date <= %s",
            $account_id, $up_to_date
            )
        );
        return (float) $account->opening_balance + (float) $sum;
    }

    /**
     * Get balances for all accounts in a single query.
     * Returns array of [account_id => balance].
     *
     * @param  int|null $fiscal_year  When set, only totals for that year; when null, all years.
     * @return array<int,float>
     */
    public static function get_all_balances( ?int $fiscal_year = null ): array {
        global $wpdb;

        $year_clause = $fiscal_year ? $wpdb->prepare( 'AND t.fiscal_year = %d', $fiscal_year ) : '';
        $sql = "SELECT a.id,
            a.opening_balance,
            COALESCE(SUM(t.total_in),  0) AS total_in,
            COALESCE(SUM(t.total_out), 0) AS total_out
            FROM `{$wpdb->prefix}emeacc_accounts` a
            LEFT JOIN `{$wpdb->prefix}emeacc_account_totals` t ON t.account_id = a.id {$year_clause}
            GROUP BY a.id, a.opening_balance
            ORDER BY a.sort_order, a.name";

        $out = [];
        foreach ( $wpdb->get_results( $sql ) as $r ) {
            $out[ (int) $r->id ] = (float) $r->opening_balance + (float) $r->total_in - (float) $r->total_out;
        }

        return $out;
    }

    /**
     * Adjust aggregated totals by a delta for an account+fiscal_year.
     * O(1) per write — no full SUM scan.
     *
     * @param int    $account_id
     * @param int    $fiscal_year
     * @param string $direction  'in' or 'out'
     * @param float  $amount     Positive amount to add (or subtract when $op='subtract').
     * @param string $op         'add' or 'subtract'
     */
    public static function adjust_totals( int $account_id, int $fiscal_year, string $direction, float $amount, string $op = 'add' ): void {
        global $wpdb;
        if ( $amount <= 0 ) {
            return;
        }
        $col  = $direction === 'in' ? 'total_in' : 'total_out';
        $sign = $op === 'add' ? '+' : '-';
        $wpdb->query(
            $wpdb->prepare(
                "INSERT INTO `{$wpdb->prefix}emeacc_account_totals` (account_id, fiscal_year, total_in, total_out)
                 VALUES (%d, %d, 0, 0)
                 ON DUPLICATE KEY UPDATE {$col} = {$col} {$sign} %f",
                $account_id, $fiscal_year, $amount
            )
        );
    }

    public static function can_book_on_date( int $account_id, string $entry_date ): bool {
        $account = self::get( $account_id );
        if ( ! $account || empty( $account->opening_date ) ) {
            return true;
        }
        return $entry_date >= $account->opening_date;
    }

    /* ------------------------------------------------------------------
     * WRITE
     * ------------------------------------------------------------------ */

    /**
     * Insert or update an account.
     *
     * @param  array<string,mixed> $data
     * @return int|\WP_Error  New/updated ID or WP_Error on failure.
     */
    public static function save( array $data ): int|\WP_Error {
        global $wpdb;
        $table = $wpdb->prefix . 'emeacc_accounts';

        $row = [
            'name'            => eme_sanitize_request( $data['name'] ?? '' ),
            'iban'            => strtoupper( preg_replace( '/\s+/', '', $data['iban'] ?? '' ) ),
            'bic'             => strtoupper( eme_sanitize_request( $data['bic'] ?? '' ) ),
            'opening_balance' => (float) ( $data['opening_balance'] ?? 0 ),
            'opening_date'   => ! empty( $data['opening_date'] ) ? eme_sanitize_request( $data['opening_date'] ) : null,
            'is_active'       => (int) (bool) ( $data['is_active'] ?? 1 ),
            'sort_order'      => (int) ( $data['sort_order'] ?? 0 ),
            'description'     => eme_sanitize_request( $data['description'] ?? '' ),
        ];

        $id = isset( $data['id'] ) ? (int) $data['id'] : 0;

        if ( $id > 0 ) {
            $wpdb->update( $table, $row, [ 'id' => $id ], null, [ '%d' ] );
            return $id;
        }

        $wpdb->insert( $table, $row );
        return (int) $wpdb->insert_id;
    }

    public static function delete( int $id ): bool|\WP_Error {
        global $wpdb;
        // Prevent deletion if journal entries exist
        $count = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM `{$wpdb->prefix}emeacc_journal` WHERE account_id = %d OR contra_account_id = %d",
                $id, $id
            )
        );
        if ( $count > 0 ) {
            return new \WP_Error(
                'has_entries',
                sprintf(
                    __( 'Rekening kan niet verwijderd worden: er zijn %d boekingen gekoppeld. Deactiveer de rekening in de plaats.', 'eme-accounting' ),
                    $count
                )
            );
        }
        $wpdb->delete( $wpdb->prefix . 'emeacc_accounts', [ 'id' => $id ], [ '%d' ] );
        $wpdb->delete( $wpdb->prefix . 'emeacc_account_totals', [ 'account_id' => $id ], [ '%d' ] );
        return true;
    }

    /* ------------------------------------------------------------------
     * AJAX handlers
     * ------------------------------------------------------------------ */

    public static function ajax_save(): void {
        check_ajax_referer( 'eme_accounting_nonce', 'nonce' );
        Capabilities::require_manage_ajax( 'accounts' );
        $result = self::save( $_POST );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }
        wp_send_json_success( [ 'id' => $result ] );
    }

    public static function ajax_delete(): void {
        check_ajax_referer( 'eme_accounting_nonce', 'nonce' );
        Capabilities::require_manage_ajax( 'accounts' );
        $result = self::delete( (int) ( $_POST['id'] ?? 0 ) );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }
        wp_send_json_success();
    }
}
