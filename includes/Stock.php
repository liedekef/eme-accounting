<?php
// phpcs:ignoreFile WordPress.DB.DirectDatabaseQuery.DirectQuery WordPress.DB.DirectDatabaseQuery.NoCaching
namespace EMEAccounting;

defined( 'ABSPATH' ) || exit;

use EMEAccounting\Capabilities;

class Stock {
    public static function init(): void {
        add_action( 'wp_ajax_eme_accounting_save_stock',     [ __CLASS__, 'ajax_save' ] );
        add_action( 'wp_ajax_eme_accounting_delete_stock', [ __CLASS__, 'ajax_delete' ] );
        add_action( 'wp_ajax_eme_accounting_stock_movement', [ __CLASS__, 'ajax_movement' ] );
    }

    public static function get_all(): array {
        global $wpdb;
        return $wpdb->get_results( "SELECT * FROM `{$wpdb->prefix}emeacc_stock` ORDER BY name" );
    }

    public static function get( int $id ): ?object {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$wpdb->prefix}emeacc_stock` WHERE id = %d", $id ) );
    }

    public static function save( array $data ): int|\WP_Error {
        global $wpdb;
        $row = [
            'name'           => eme_sanitize_request( $data['name'] ?? '' ),
            'sku'            => eme_sanitize_request( $data['sku'] ?? '' ),
            'description'    => eme_sanitize_request( $data['description'] ?? '' ),
            'unit'           => eme_sanitize_request( $data['unit'] ?? 'stuk' ),
            'purchase_price' => (float) ( $data['purchase_price'] ?? 0 ),
            'sale_price'     => (float) ( $data['sale_price'] ?? 0 ),
            'min_qty'        => (float) ( $data['min_qty'] ?? 0 ),
            'account_id'     => ! empty( $data['account_id'] ) ? (int) $data['account_id'] : null,
            'is_active'      => (int) (bool) ( $data['is_active'] ?? 1 ),
        ];
        $id = isset( $data['id'] ) ? (int) $data['id'] : 0;
        if ( $id > 0 ) {
            $wpdb->update( $wpdb->prefix . 'emeacc_stock', $row, [ 'id' => $id ], null, [ '%d' ] );
            return $id;
        }
        $wpdb->insert( $wpdb->prefix . 'emeacc_stock', $row );
        return (int) $wpdb->insert_id;
    }

    public static function add_movement( int $stock_id, string $type, float $qty, float $unit_price, string $notes = '', ?int $journal_id = null ): int|\WP_Error {
        global $wpdb;
        $allowed = [ 'in', 'out', 'adjustment', 'write_off' ];
        if ( ! in_array( $type, $allowed, true ) ) {
            return new \WP_Error( 'invalid_type', __( 'Ongeldig bewegingstype.', 'eme-accounting' ) );
        }
        $wpdb->insert( $wpdb->prefix . 'emeacc_stock_movements', [
            'stock_id'      => $stock_id,
            'movement_date' => current_time( 'Y-m-d' ),
            'type'          => $type,
            'qty'           => $qty,
            'unit_price'    => $unit_price,
            'total_value'   => round( $qty * $unit_price, 2 ),
            'journal_id'    => $journal_id,
            'notes'         => eme_sanitize_request( $notes ),
            'created_by'    => get_current_user_id(),
        ] );
        // Update current_qty
        $sign = in_array( $type, [ 'in', 'adjustment' ], true ) ? 1 : -1;
        $wpdb->query( $wpdb->prepare(
            "UPDATE `{$wpdb->prefix}emeacc_stock` SET current_qty = current_qty + ( %f * %d ) WHERE id = %d",
            $qty, $sign, $stock_id
        ) );
        return (int) $wpdb->insert_id;
    }

    public static function ajax_save(): void {
        check_ajax_referer( 'eme_accounting_nonce', 'nonce' );
        Capabilities::require_manage_ajax( 'stock' );
        $r = self::save( $_POST );
        is_wp_error( $r ) ? wp_send_json_error( $r->get_error_message() ) : wp_send_json_success( [ 'id' => $r ] );
    }

    public static function ajax_movement(): void {
        check_ajax_referer( 'eme_accounting_nonce', 'nonce' );
        Capabilities::require_manage_ajax( 'stock' );
        $r = self::add_movement(
            (int) eme_sanitize_request( $_POST['stock_id'] ?? 0 ), //phpcs:ignore WordPress.Security.ValidatedSanitizedInput
            eme_sanitize_request( $_POST['type'] ?? 'in' ), //phpcs:ignore WordPress.Security.ValidatedSanitizedInput
            (float) eme_sanitize_request( $_POST['qty'] ?? 0 ), //phpcs:ignore WordPress.Security.ValidatedSanitizedInput
            (float) eme_sanitize_request( $_POST['unit_price'] ?? 0 ), //phpcs:ignore WordPress.Security.ValidatedSanitizedInput
            eme_sanitize_request( $_POST['notes'] ?? '' ) //phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        );
        is_wp_error( $r ) ? wp_send_json_error( $r->get_error_message() ) : wp_send_json_success();
    }

    public static function ajax_delete(): void {
        check_ajax_referer( 'eme_accounting_nonce', 'nonce' );
        Capabilities::require_manage_ajax( 'stock' );
        global $wpdb;
        $id = (int) eme_sanitize_request( $_POST['id'] ?? 0 ); //phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        if ( $id > 0 ) {
            $wpdb->delete( $wpdb->prefix . 'emeacc_stock', [ 'id' => $id ], [ '%d' ] );
        }
        wp_send_json_success();
    }
}
