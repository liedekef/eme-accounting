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
     * @return array<int,float>
     */
    public static function get_all_balances(): array {
        global $wpdb;

        $sql = "SELECT a.id,
            a.opening_balance,
            COALESCE(t.delta, 0) AS delta
            FROM `{$wpdb->prefix}emeacc_accounts` a
            LEFT JOIN `{$wpdb->prefix}emeacc_account_totals` t ON t.account_id = a.id
            ORDER BY a.sort_order, a.name";

        $out = [];
        foreach ( $wpdb->get_results( $sql ) as $r ) {
            $out[ (int) $r->id ] = (float) $r->opening_balance + (float) $r->delta;
        }

        return $out;
    }

    /**
     * Adjust aggregated totals by a delta for an account.
     * O(1) per write — no full SUM scan.
     *
     * @param int    $account_id
     * @param string $direction  'in' or 'out'
     * @param float  $amount     Positive amount.
     * @param string $op         'add' or 'subtract'
     * @param int    $journal_id Optional, for logging context on failure.
     */
    public static function adjust_totals( int $account_id, string $direction, float $amount, string $op = 'add', int $journal_id = 0 ): bool|\WP_Error {
        global $wpdb;
        if ( $amount <= 0 ) {
            return true;
        }

        $delta = $direction === 'in' ? $amount : -$amount;
        if ( $op === 'subtract' ) $delta = -$delta;
        $result = $wpdb->query(
            $wpdb->prepare(
                "INSERT INTO `{$wpdb->prefix}emeacc_account_totals` (account_id, delta)
                VALUES (%d, 0)
                ON DUPLICATE KEY UPDATE delta = delta + %f",
            $account_id, $delta
            )
        );

        if ( false === $result ) {
            error_log( sprintf(
                '[EME Accounting] adjust_totals failed (journal_id=%d, account_id=%d, delta=%F, op=%s): %s — rebuild_totals required.',
                $journal_id, $account_id, $delta, $op, $wpdb->last_error
            ) );
            return new \WP_Error( 'db_problem_adjust_totals', __( 'Totalen aanpassen mislukt.', 'eme-accounting' ) );
        }
        return true;
    }

    /**
     * Rebuild the account_totals table from scratch.
     * Should only be called manually by an admin (e.g. after direct DB edits or data migration).
     */
    public static function rebuild_totals(): bool|\WP_Error {
        global $wpdb;
        $totals_table  = "{$wpdb->prefix}emeacc_account_totals";
        $journal_table = "{$wpdb->prefix}emeacc_journal";

        $wpdb->query( 'START TRANSACTION' );

        $r1 = $wpdb->query( "DELETE FROM `{$totals_table}`" );
        $r2 = ( false === $r1 ) ? false : $wpdb->query(
            "INSERT INTO `{$totals_table}` (account_id, delta)
            SELECT account_id,
            SUM( CASE WHEN direction = 'in' THEN amount ELSE -amount END )
            FROM `{$journal_table}`
            GROUP BY account_id"
        );

        if ( false === $r1 || false === $r2 ) {
            $wpdb->query( 'ROLLBACK' );
            error_log( sprintf( '[EME Accounting] rebuild_totals FAILED: %s — totals table may now be inconsistent!', $wpdb->last_error ) );
            return new \WP_Error( 'db_rebuild_failed', $wpdb->last_error );
        }

        $wpdb->query( 'COMMIT' );
        error_log( '[EME Accounting] rebuild_totals succeeded.' );
        return true;
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
