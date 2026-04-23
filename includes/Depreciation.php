<?php
namespace EMEAccounting;

defined( 'ABSPATH' ) || exit;

use EMEAccounting\Capabilities;

class Depreciation {
    public static function init(): void {
        add_action( 'wp_ajax_eme_accounting_save_asset',      [ __CLASS__, 'ajax_save' ] );
        add_action( 'wp_ajax_eme_accounting_delete_asset', [ __CLASS__, 'ajax_delete' ] );
        add_action( 'eme_accounting_daily_cron', [ __CLASS__, 'daily_check' ] );

        // Schedule daily cron if not exists
        if ( ! wp_next_scheduled( 'eme_accounting_daily_cron' ) ) {
            wp_schedule_event( time() + 3600, 'daily', 'eme_accounting_daily_cron' );
        }
    }

    /**
     * Daily check - runs automatic depreciation on January 2nd.
     */
    public static function daily_check(): void {
        // Only run on January 2nd
        if ( (int) gmdate( 'm' ) !== 1 || (int) gmdate( 'd' ) !== 2 ) {
            return;
        }

        $assets = self::get_all();
        foreach ( $assets as $asset ) {
            // Skip if no last_depreciation date or already processed this year
            if ( empty( $asset->last_depreciation ) ) {
                continue;
            }

            // Skip if already at residual value
            if ( (float) $asset->current_book_value <= (float) $asset->residual_value ) {
                continue;
            }

            $amount = self::calculate_annual_amount( $asset );
            if ( $amount <= 0 ) {
                continue;
            }

            // Calculate new book value
            $new_value = max( (float) $asset->residual_value, (float) $asset->current_book_value - $amount );

            // Set next automatic date: January 2nd of next year
            $next_year = (int) gmdate( 'Y' ) + 1;
            $next_date = sprintf( '%d-01-02', $next_year );

            // Optional: Create journal entries if both account_id and depreciation_account_id are set
            if ( ! empty( $asset->account_id ) && ! empty( $asset->depreciation_account_id ) ) {
                // Boeking voor afschrijvingsrekening (kosten)
                Journal::save( [
                    'entry_date'      => current_time( 'Y-m-d' ),
                    'description'   => sprintf(
                        __( 'Afschrijving %s – %s', 'eme-accounting' ),
                        $asset->name,
                        gmdate( 'Y' )
                    ),
                    'amount'      => $amount,
                    'direction'   => 'out',
                    'account_id'   => $asset->depreciation_account_id,
                ] );
                // Boeking voor activarekening (verminderen activa)
                Journal::save( [
                    'entry_date'      => current_time( 'Y-m-d' ),
                    'description'   => sprintf(
                        __( 'Afschrijving %s – %s', 'eme-accounting' ),
                        $asset->name,
                        gmdate( 'Y' )
                    ),
                    'amount'      => $amount,
                    'direction'   => 'in',
                    'account_id'   => $asset->account_id,
                ] );
            }

            // Update asset
            global $wpdb;
            $wpdb->update(
                $wpdb->prefix . 'emeacc_depreciation',
                [
                    'current_book_value' => $new_value,
                    'last_depreciation'  => $next_date,
                ],
                [ 'id' => $asset->id ],
                [ '%f', '%s' ],
                [ '%d' ]
            );
        }
    }

    public static function get_all(): array {
        global $wpdb;
        return $wpdb->get_results( "SELECT * FROM `{$wpdb->prefix}emeacc_depreciation` WHERE is_active = 1 ORDER BY name" );
    }

    public static function save( array $data ): int|\WP_Error {
        global $wpdb;

        // Check: both account_id and depreciation_account_id must be filled together
        $has_account_id              = ! empty( $data['account_id'] );
        $has_depreciation_account_id = ! empty( $data['depreciation_account_id'] );
        if ( $has_account_id !== $has_depreciation_account_id ) {
            return new \WP_Error( 'invalid_accounts', __( '.beide rekeningen invoegen of geen van beide', 'eme-accounting' ) );
        }

        $purchase_price = (float) ( $data['purchase_price'] ?? 0 );
        $useful_life   = max( 1, (int) ( $data['useful_life_years'] ?? 5 ) );
        $method       = $data['method'] ?? 'linear';
        $residual     = (float) ( $data['residual_value'] ?? 0 );
        $purchase_date = eme_sanitize_request( $data['purchase_date'] ?? '' );

        // Calculate years since purchase
        $years_elapsed = 0;
        if ( $purchase_date ) {
            $purchase_ts = strtotime( $purchase_date );
            $now_ts     = current_time( 'timestamp' );
            $years_elapsed = floor( ( $now_ts - $purchase_ts ) / ( 365.25 * 24 * 3600 ) );
        }

        // Calculate current book value with historical depreciation
        $current_book_value = $purchase_price;
        if ( $years_elapsed > 0 ) {
            // Temporary: set to purchase price for calculation
            $temp_asset = (object) [
                'purchase_price'    => $purchase_price,
                'residual_value'  => $residual,
                'useful_life_years' => $useful_life,
                'method'          => $method,
                'current_book_value' => $purchase_price,
            ];

            $total_depreciation = 0;
            for ( $i = 0; $i < $years_elapsed; $i++ ) {
                $annual = self::calculate_annual_amount( $temp_asset );
                $total_depreciation += $annual;
                $temp_asset->current_book_value = max( $residual, $temp_asset->current_book_value - $annual );
            }
            $current_book_value = max( $residual, $purchase_price - $total_depreciation );
        }

        // Set last_depreciation date
        // If purchased more than 1 year ago: set to January 2nd of next year
        // Otherwise: NULL (will be handled by first daily cron on Jan 2)
        $last_depreciation = null;
        if ( $years_elapsed >= 1 ) {
            $purchase_year = $purchase_date ? (int) date( 'Y', strtotime( $purchase_date ) ) : (int) gmdate( 'Y' );
            $last_depreciation = sprintf( '%d-01-02', $purchase_year + $years_elapsed );
        }

        $row = [
            'name'                    => eme_sanitize_request( $data['name'] ?? '' ),
            'description'             => eme_sanitize_request( $data['description'] ?? '' ),
            'purchase_date'           => $purchase_date,
            'purchase_price'          => $purchase_price,
            'residual_value'          => $residual,
            'useful_life_years'       => $useful_life,
            'method'                  => $method,
            'asset_type'              => $data['asset_type'] ?? 'machines',
            'account_id'              => ! empty( $data['account_id'] ) ? (int) $data['account_id'] : null,
            'depreciation_account_id' => ! empty( $data['depreciation_account_id'] ) ? (int) $data['depreciation_account_id'] : null,
            'current_book_value'      => $current_book_value,
            'last_depreciation'    => $last_depreciation,
        ];

        $id = isset( $data['id'] ) ? (int) $data['id'] : 0;
        if ( $id > 0 ) {
            // When editing: recalculate only if purchase_date changed
            $existing = $wpdb->get_row( $wpdb->prepare( "SELECT purchase_date FROM `{$wpdb->prefix}emeacc_depreciation` WHERE id = %d", $id ) );
            if ( $existing && $existing->purchase_date !== $purchase_date ) {
                // Recalculate historical depreciation
                $years_elapsed = 0;
                if ( $purchase_date ) {
                    $purchase_ts = strtotime( $purchase_date );
                    $now_ts     = current_time( 'timestamp' );
                    $years_elapsed = floor( ( $now_ts - $purchase_ts ) / ( 365.25 * 24 * 3600 ) );
                }

                if ( $years_elapsed > 0 ) {
                    $temp_asset = (object) [
                        'purchase_price'    => $purchase_price,
                        'residual_value'  => $residual,
                        'useful_life_years' => $useful_life,
                        'method'          => $method,
                        'current_book_value' => $purchase_price,
                    ];

                    $total_depreciation = 0;
                    for ( $i = 0; $i < $years_elapsed; $i++ ) {
                        $annual = self::calculate_annual_amount( $temp_asset );
                        $total_depreciation += $annual;
                        $temp_asset->current_book_value = max( $residual, $temp_asset->current_book_value - $annual );
                    }
                    $row['current_book_value'] = max( $residual, $purchase_price - $total_depreciation );
                } else {
                    $row['current_book_value'] = $purchase_price;
                }

                if ( $years_elapsed >= 1 ) {
                    $purchase_year = $purchase_date ? (int) date( 'Y', strtotime( $purchase_date ) ) : (int) gmdate( 'Y' );
                    $row['last_depreciation'] = sprintf( '%d-01-02', $purchase_year + 1 );
                } else {
                    $row['last_depreciation'] = null;
                }
            }
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
     * Get total current book value by asset type as of a specific date.
     *
     * @param string $asset_type Asset type (onroerende_goederen, machines, roerende_goederen)
     * @param string|null $up_to_date Date string (Y-m-d), defaults to current date
     * @return float
     */
    public static function get_total_by_type( string $asset_type, ?string $up_to_date = null ): float {
        global $wpdb;

        if ( ! $up_to_date ) {
            $up_to_date = current_time( 'Y-m-d' );
        }

        $assets = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM `{$wpdb->prefix}emeacc_depreciation`
                 WHERE is_active = 1 AND asset_type = %s",
                $asset_type
            )
        );

        $total = 0;
        foreach ( $assets as $asset ) {
            $total += self::get_book_value_at_date( $asset, $up_to_date );
        }

        return round( $total, 2 );
    }

    /**
     * Calculate book value of a single asset at a specific date.
     *
     * @param object $asset Asset row from emeacc_depreciation
     * @param string $at_date Date string (Y-m-d)
     * @return float
     */
    public static function get_book_value_at_date( object $asset, string $at_date ): float {
        if ( empty( $asset->purchase_date ) ) {
            return (float) $asset->current_book_value;
        }

        $purchase_ts = strtotime( $asset->purchase_date );
        $at_ts     = strtotime( $at_date );

        if ( $at_ts <= $purchase_ts ) {
            return (float) $asset->purchase_price;
        }

        $years_elapsed = floor( ( $at_ts - $purchase_ts ) / ( 365.25 * 24 * 3600 ) );

        if ( $years_elapsed <= 0 ) {
            return (float) $asset->purchase_price;
        }

        $temp_asset = (object) [
            'purchase_price'    => $asset->purchase_price,
            'residual_value'  => $asset->residual_value,
            'useful_life_years' => $asset->useful_life_years,
            'method'            => $asset->method,
            'current_book_value' => $asset->purchase_price,
        ];

        $total_depreciation = 0;
        for ( $i = 0; $i < $years_elapsed; $i++ ) {
            $annual = self::calculate_annual_amount( $temp_asset );
            $total_depreciation += $annual;
            $temp_asset->current_book_value = max(
                $asset->residual_value,
                $temp_asset->current_book_value - $annual
            );
        }

        return max( $asset->residual_value, round( $asset->purchase_price - $total_depreciation, 2 ) );
    }

    public static function ajax_save(): void {
        check_ajax_referer( 'eme_accounting_nonce', 'nonce' );
        Capabilities::require_manage_ajax( 'depreciation' );
        $r = self::save( $_POST );
        is_wp_error( $r ) ? wp_send_json_error( $r->get_error_message() ) : wp_send_json_success( [ 'id' => $r ] );
    }

    public static function ajax_delete(): void {
        check_ajax_referer( 'eme_accounting_nonce', 'nonce' );
        Capabilities::require_manage_ajax( 'depreciation' );
        global $wpdb;
        $id = (int) ( $_POST['id'] ?? 0 );
        if ( $id > 0 ) {
            $wpdb->delete( $wpdb->prefix . 'emeacc_depreciation', [ 'id' => $id ], [ '%d' ] );
        }
        wp_send_json_success();
    }
}
