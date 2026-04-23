<?php
namespace EMEAccounting;

defined( 'ABSPATH' ) || exit;

use EMEAccounting\Capabilities;

/**
 * Staat van het Vermogen (Bijlage C – enkelvoudige boekhouding)
 *
 * Wettelijke basis: KB 29 april 2019 (art. 6, 7 en 14)
 * Verplicht voor micro-VZW's die enkelvoudige boekhouding voeren.
 *
 * Het genormaliseerde schema (art. 14) bestaat uit:
 *   BEZITTINGEN  ↔  SCHULDEN
 *   RECHTEN      ↔  VERPLICHTINGEN
 */
class Patrimony {

    public static function init(): void {
        add_action( 'wp_ajax_eme_accounting_save_patrimony',     [ __CLASS__, 'ajax_save' ] );
        add_action( 'wp_ajax_eme_accounting_get_patrimony',      [ __CLASS__, 'ajax_get' ] );
        add_action( 'wp_ajax_eme_accounting_delete_patrimony',   [ __CLASS__, 'ajax_delete' ] );
        add_action( 'wp_ajax_eme_accounting_download_patrimony', [ __CLASS__, 'ajax_download_pdf' ] );
    }

    /* ------------------------------------------------------------------
     * DATA CRUD
     * ------------------------------------------------------------------ */

    public static function get( int $fiscal_year ): ?object {
        global $wpdb;

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM `{$wpdb->prefix}emeacc_patrimony`
             WHERE fiscal_year = %d",
            $fiscal_year
        ) );

        if ( $row ) {
            return $row;
        }

        $dates = FiscalYear::calculate_dates( $fiscal_year );
        $end_date = $dates['end_date'];

        $asset_real_estate = Depreciation::get_total_by_type( 'onroerende_goederen', $end_date );
        $asset_machines = Depreciation::get_total_by_type( 'machines', $end_date );
        $asset_movables = Depreciation::get_total_by_type( 'roerende_goederen', $end_date );

        $asset_cash = 0;
        $accounts = Accounts::get_all();
        foreach ( $accounts as $acc ) {
            $balance = Accounts::get_balance( (int) $acc->id, $end_date );
            $asset_cash += $balance;
        }

        return (object) [
            'fiscal_year    '   => $fiscal_year,
            'asset_real_estate' => $asset_real_estate,
            'asset_machines'    => $asset_machines,
            'asset_movables'    => $asset_movables,
            'asset_cash'        => $asset_cash,
            'status'            => 'draft',
            'id'                => 0,
        ];
    }

    public static function get_all(): array {
        global $wpdb;
        return $wpdb->get_results(
            "SELECT * FROM `{$wpdb->prefix}emeacc_patrimony`
             ORDER BY fiscal_year ASC"
        );
    }

    public static function save( array $data ): int|\WP_Error {
        global $wpdb;

        $fiscal_year = (int) ( $data['year'] ?? 0 );
        if ( $fiscal_year < 2000 || $fiscal_year > 2100 ) {
            return new \WP_Error( 'invalid_year', 'Ongeldig boekjaar.' );
        }

        $dec = fn( $k ) => round( (float) str_replace( ',', '.', str_replace('.', '', $data[ $k ] ?? '0' )), 2 );

        $row = [
            'fiscal_year'                 => $fiscal_year,

            // ── Sectie 1: Waarderingsregels ──────────────────────────
            'depr_machines_new_years'     => (int) ( $data['depr_machines_new_years']     ?? 5 ),
            'depr_machines_used_years'    => (int) ( $data['depr_machines_used_years']    ?? 2 ),
            'depr_computers_new_years'    => (int) ( $data['depr_computers_new_years']    ?? 5 ),
            'depr_computers_used_years'   => (int) ( $data['depr_computers_used_years']   ?? 2 ),
            'depr_furniture_new_years'    => (int) ( $data['depr_furniture_new_years']    ?? 5 ),
            'depr_furniture_used_years'   => (int) ( $data['depr_furniture_used_years']   ?? 2 ),
            'depr_other_new_years'        => (int) ( $data['depr_other_new_years']        ?? 5 ),
            'depr_other_used_years'       => (int) ( $data['depr_other_used_years']       ?? 2 ),
            'depr_method'                 => in_array( $data['depr_method'] ?? 'linear', [ 'linear', 'degressive' ], true )
                                             ? $data['depr_method'] : 'linear',
            'cash_valuation_note'         => eme_sanitize_request( $data['cash_valuation_note'] ?? '' ),

            // ── Sectie 2: Aanpassing waarderingsregels (art. 7) ─────
            'valuation_changes'           => eme_sanitize_request( $data['valuation_changes'] ?? '' ),

            // ── Sectie 3: Bijkomende inlichtingen (art. 14) ─────────
            'additional_info'             => eme_sanitize_request( $data['additional_info'] ?? '' ),

            // ── Sectie 4: Genormaliseerd schema – BEZITTINGEN ────────
            'asset_real_estate'           => $dec( 'asset_real_estate' ),
            'asset_machines'              => $dec( 'asset_machines' ),
            'asset_movables'              => $dec( 'asset_movables' ),
            'asset_stocks'                => $dec( 'asset_stocks' ),
            'asset_receivables'           => $dec( 'asset_receivables' ),
            'asset_investments'           => $dec( 'asset_investments' ),
            'asset_cash'                  => $dec( 'asset_cash' ),
            'asset_other'                 => $dec( 'asset_other' ),

            // ── Sectie 4: Genormaliseerd schema – SCHULDEN ───────────
            'liab_financial'              => $dec( 'liab_financial' ),
            'liab_suppliers'              => $dec( 'liab_suppliers' ),
            'liab_members'                => $dec( 'liab_members' ),
            'liab_fiscal_social'          => $dec( 'liab_fiscal_social' ),
            'liab_other'                  => $dec( 'liab_other' ),

            // ── Sectie 4: Genormaliseerd schema – RECHTEN ────────────
            'right_subsidies'             => $dec( 'right_subsidies' ),
            'right_donations'             => $dec( 'right_donations' ),
            'right_other'                 => $dec( 'right_other' ),

            // ── Sectie 4: Genormaliseerd schema – VERPLICHTINGEN ─────
            'oblig_mortgages'             => $dec( 'oblig_mortgages' ),
            'oblig_guarantees'            => $dec( 'oblig_guarantees' ),
            'oblig_other'                 => $dec( 'oblig_other' ),

            // ── Sectie 5: Rechten/verplichtingen niet in cijfers ─────
            'non_numeric_obligations'     => eme_sanitize_request( $data['non_numeric_obligations'] ?? '' ),

            // ── Status ───────────────────────────────────────────────
            'status'                      => in_array( $data['status'] ?? 'draft', [ 'draft', 'final' ], true )
                                             ? $data['status'] : 'draft',
            'notes'                       => eme_sanitize_request( $data['notes'] ?? '' ),
        ];

        $exists = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM `{$wpdb->prefix}emeacc_patrimony`
             WHERE fiscal_year = %d",
            $fiscal_year
        ) );

        if ( $exists ) {
            $wpdb->update( $wpdb->prefix . 'emeacc_patrimony', $row,
                [ 'id' => (int) $exists ], null, [ '%d' ] );
            return (int) $exists;
        }

        $wpdb->insert( $wpdb->prefix . 'emeacc_patrimony', $row );
        return (int) $wpdb->insert_id;
    }

    /* ------------------------------------------------------------------
     * BEREKENINGEN
     * ------------------------------------------------------------------ */

    public static function totals( object $p ): array {
        $total_assets  = $p->asset_real_estate + $p->asset_machines + $p->asset_movables
                       + $p->asset_stocks + $p->asset_receivables + $p->asset_investments
                       + $p->asset_cash + $p->asset_other;

        $total_liab    = $p->liab_financial + $p->liab_suppliers + $p->liab_members
                       + $p->liab_fiscal_social + $p->liab_other;

        $total_rights  = $p->right_subsidies + $p->right_donations + $p->right_other;

        $total_oblig   = $p->oblig_mortgages + $p->oblig_guarantees + $p->oblig_other;

        $net_assets    = $total_assets - $total_liab;

        return compact( 'total_assets', 'total_liab', 'total_rights', 'total_oblig', 'net_assets' );
    }

    /* ------------------------------------------------------------------
     * PDF GENERATIE (via dompdf – zit al in Events Made Easy)
     * ------------------------------------------------------------------ */

    public static function generate_pdf_html( int $fiscal_year ): string {
        $p      = self::get( $fiscal_year );
        $config = \EMEVzw\Config::get();
        if ( ! $p || ! $config ) return '';

        $totals = self::totals( $p );

        ob_start();
        include EME_ACCOUNTING_DIR . 'templates/pdf/patrimony.php';
        return ob_get_clean();
    }

    /* ------------------------------------------------------------------
     * AJAX
     * ------------------------------------------------------------------ */

    public static function ajax_save(): void {
        check_ajax_referer( 'eme_accounting_nonce', 'nonce' );
        Capabilities::require_manage_ajax( 'patrimony' );
        $r    = self::save( $_POST );
        is_wp_error( $r )
            ? wp_send_json_error( $r->get_error_message() )
            : wp_send_json_success( [ 'id' => $r ] );
    }

    public static function ajax_get(): void {
        check_ajax_referer( 'eme_accounting_nonce', 'nonce' );
        Capabilities::require_view_ajax( 'patrimony' );
        $year = (int) ( $_GET['year'] ?? 0 );
        $p    = self::get( $year );
        $p ? wp_send_json_success( $p ) : wp_send_json_error( 'Niet gevonden.' );
    }

    public static function ajax_delete(): void {
        check_ajax_referer( 'eme_accounting_nonce', 'nonce' );
        Capabilities::require_manage_ajax( 'patrimony' );
        global $wpdb;
        $wpdb->delete( $wpdb->prefix . 'emeacc_patrimony',
            [ 'id' => (int) ( $_POST['id'] ?? 0 ) ], [ '%d' ] );
        wp_send_json_success();
    }

    public static function ajax_download_pdf(): void {
        check_ajax_referer( 'eme_accounting_nonce', 'nonce' );
        Capabilities::require_view_ajax( 'patrimony' );

        $year = (int) ( $_GET['year'] ?? 0 );
        $html = self::generate_pdf_html( $year );

        if ( empty( $html ) ) {
            wp_send_json_error();
        }

        $dompdf_autoload = WP_PLUGIN_DIR . '/events-made-easy/includes/dompdf/vendor/autoload.php';
        if ( ! file_exists( $dompdf_autoload ) ) {
            wp_send_json_error();
        }
        require_once $dompdf_autoload;

        $options = new \Dompdf\Options();
        $options->set( 'isHtml5ParserEnabled', true );
        $options->set( 'isRemoteEnabled', false );
        $options->set( 'defaultFont', 'helvetica' );

        $dompdf = new \Dompdf\Dompdf( $options );
        $dompdf->loadHtml( '<!DOCTYPE html><html lang="nl"><head><meta charset="UTF-8"></head><body>'
            . $html . '</body></html>' );
        $dompdf->setPaper( 'A4', 'landscape' );
        $dompdf->render();
        $dompdf->stream( 'StaatVanHetVermogen_' . $year . '.pdf', [ 'Attachment' => false ] );
        exit;
    }
}
