<?php
namespace EMEAccounting;

defined( 'ABSPATH' ) || exit;

class Depreciation {
    public static function init(): void {
        add_action( 'wp_ajax_eme_accounting_save_asset',      [ __CLASS__, 'ajax_save' ] );
        add_action( 'eme_accounting_annual_depreciation_run', [ __CLASS__, 'run_annual' ] );
        // Schedule yearly cron if not yet scheduled
        if ( ! wp_next_scheduled( 'eme_accounting_annual_depreciation_run' ) ) {
            wp_schedule_event( mktime( 0, 0, 0, 1, 2, (int) gmdate( 'Y' ) + 1 ), 'yearly', 'eme_accounting_annual_depreciation_run' );
        }
    }

    public static function get_all(): array {
        global $wpdb;
        return $wpdb->get_results( "SELECT * FROM `{$wpdb->prefix}emeacc_depreciation` WHERE is_active = 1 ORDER BY name" );
    }

    public static function save( array $data ): int|\WP_Error {
        global $wpdb;
        $allowed_methods = [ 'linear', 'declining' ];
        if ( ! in_array( $data['method'] ?? 'linear', $allowed_methods, true ) ) {
            return new \WP_Error( 'invalid_method', __( 'Onbekende afschrijvingsmethode.', 'eme-accounting' ) );
        }
        $purchase_price = (float) ( $data['purchase_price'] ?? 0 );
        $row = [
            'name'                    => eme_sanitize_request( $data['name'] ?? '' ),
            'description'             => eme_sanitize_request( $data['description'] ?? '' ),
            'purchase_date'           => eme_sanitize_request( $data['purchase_date'] ?? '' ),
            'purchase_price'          => $purchase_price,
            'residual_value'          => (float) ( $data['residual_value'] ?? 0 ),
            'useful_life_years'       => max( 1, (int) ( $data['useful_life_years'] ?? 5 ) ),
            'method'                  => $data['method'] ?? 'linear',
            'account_id'              => ! empty( $data['account_id'] ) ? (int) $data['account_id'] : null,
            'depreciation_account_id' => ! empty( $data['depreciation_account_id'] ) ? (int) $data['depreciation_account_id'] : null,
            'current_book_value'      => $purchase_price, // Starts at purchase price
        ];
        $id = isset( $data['id'] ) ? (int) $data['id'] : 0;
        if ( $id > 0 ) {
            $wpdb->update( $wpdb->prefix . 'emeacc_depreciation', $row, [ 'id' => $id ], null, [ '%d' ] );
            return $id;
        }
        $wpdb->insert( $wpdb->prefix . 'emeacc_depreciation', $row );
        return (int) $wpdb->insert_id;
    }

    /**
     * Calculate annual depreciation amount for an asset.
     */
    public static function calculate_annual_amount( object $asset ): float {
        $depreciable = $asset->purchase_price - $asset->residual_value;
        if ( $asset->method === 'declining' ) {
            $rate = 2 / $asset->useful_life_years;
            return round( $asset->current_book_value * $rate, 2 );
        }
        // Linear
        return round( $depreciable / $asset->useful_life_years, 2 );
    }

    /**
     * Run annual depreciation for all active assets.
     * Creates journal entries automatically.
     */
    public static function run_annual(): void {
        $assets = self::get_all();
        foreach ( $assets as $asset ) {
            $amount = self::calculate_annual_amount( $asset );
            if ( $amount <= 0 || $asset->current_book_value <= $asset->residual_value ) {
                continue;
            }
            // Create journal entry
            Journal::save( [
                'entry_date'  => current_time( 'Y-m-d' ),
                'description' => sprintf(
                    __( 'Afschrijving %s – %s', 'eme-accounting' ),
                    $asset->name,
                    gmdate( 'Y' )
                ),
                'category'    => 'afschrijving',
                'amount'      => $amount,
                'direction'   => 'out',
                'account_id'  => $asset->depreciation_account_id ?: $asset->account_id,
                'fiscal_year' => (int) gmdate( 'Y' ),
            ] );
            // Update book value
            global $wpdb;
            $new_value = max( $asset->residual_value, $asset->current_book_value - $amount );
            $wpdb->update(
                $wpdb->prefix . 'emeacc_depreciation',
                [
                    'current_book_value' => $new_value,
                    'last_depreciation'  => current_time( 'Y-m-d' ),
                ],
                [ 'id' => $asset->id ],
                [ '%f', '%s' ],
                [ '%d' ]
            );
        }
    }

    public static function ajax_save(): void {
        check_ajax_referer( 'eme_accounting_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error();
        $r = self::save( $_POST );
        is_wp_error( $r ) ? wp_send_json_error( $r->get_error_message() ) : wp_send_json_success( [ 'id' => $r ] );
    }
}
