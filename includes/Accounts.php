<?php
namespace EMEAccounting;

defined( 'ABSPATH' ) || exit;

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
     * Calculate running balance for an account.
     * Balance = opening_balance + sum(in) - sum(out)
     */
    public static function get_balance( int $account_id, ?string $up_to_date = null ): float {
        global $wpdb;
        $account = self::get( $account_id );
        if ( ! $account ) {
            return 0.0;
        }

        $date_clause = $up_to_date
            ? $wpdb->prepare( 'AND entry_date <= %s', $up_to_date )
            : '';

        $sum = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT
                    COALESCE(SUM(
                        CASE
                        WHEN direction = 'in' THEN amount
                        WHEN direction = 'out' THEN -amount
                        ELSE 0
                        END
                    ), 0)
                 FROM `{$wpdb->prefix}emeacc_journal`
                 WHERE account_id = %d
                   {$date_clause}",
                $account_id,
                $account_id,
                $account_id
            )
        );

        return (float) $account->opening_balance + (float) $sum;
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
        return true;
    }

    /* ------------------------------------------------------------------
     * AJAX handlers
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
        $result = self::delete( (int) ( $_POST['id'] ?? 0 ) );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }
        wp_send_json_success();
    }
}
