<?php
namespace EMEAccounting;

defined( 'ABSPATH' ) || exit;

use EMEAccounting\Capabilities;

class Budget {
    public static function init(): void {
        add_action( 'wp_ajax_eme_accounting_save_budget_line', [ __CLASS__, 'ajax_save' ] );
        add_action( 'wp_ajax_eme_accounting_get_budget_year', [ __CLASS__, 'ajax_get_year' ] );
        add_action( 'wp_ajax_eme_accounting_delete_budget',   [ __CLASS__, 'ajax_delete' ] );
        add_action( 'wp_ajax_eme_accounting_download_budget', [ __CLASS__, 'ajax_download_pdf' ] );
    }

    /**
     * Get all budget lines for a year as key-value by coa_id.
     */
    public static function get_year_map( int $year ): array {
        global $wpdb;
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT coa_id, budgeted_amount
                 FROM `{$wpdb->prefix}emeacc_budget`
                 WHERE fiscal_year = %d",
                $year
            )
        );
        $map = [];
        foreach ( $rows as $r ) {
            $map[ (int) $r->coa_id ] = (float) $r->budgeted_amount;
        }
        return $map;
    }

    /**
     * Get actual total for a specific COA.
     */
    public static function get_actual_for_coa( int $year, int $coa_id ): float {
        global $wpdb;
        return (float) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COALESCE(SUM(amount), 0) as total
                 FROM `{$wpdb->prefix}emeacc_journal`
                 WHERE fiscal_year = %d AND coa_id = %d AND transfer_id IS NULL",
                $year,
                $coa_id
            )
        );
    }

    /**
     * Get a summary of budgeted in/out/saldo for every fiscal year that has
     * at least one budget line.  Uses a JOIN with the COA table so that the
     * direction (in/out) is resolved in SQL rather than in PHP.
     *
     * Returns an array of objects ordered by fiscal_year ASC, each with:
     *   ->fiscal_year  int
     *   ->total_in     float   (sum of budgeted_amount where coa.direction = 'in')
     *   ->total_out    float   (sum of budgeted_amount where coa.direction = 'out')
     *   ->saldo        float   (total_in − total_out)
     */
    public static function get_all_years_summary(): array {
        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT
                b.fiscal_year,
                SUM(CASE WHEN c.direction = 'in'  THEN b.budgeted_amount ELSE 0 END) AS total_in,
                SUM(CASE WHEN c.direction = 'out' THEN b.budgeted_amount ELSE 0 END) AS total_out
             FROM `{$wpdb->prefix}emeacc_budget` b
             INNER JOIN `{$wpdb->prefix}emeacc_chart_of_accounts` c ON c.id = b.coa_id
             GROUP BY b.fiscal_year
             ORDER BY b.fiscal_year ASC"
        );

        foreach ( $rows as $r ) {
            $r->fiscal_year = (int)   $r->fiscal_year;
            $r->total_in    = (float) $r->total_in;
            $r->total_out   = (float) $r->total_out;
            $r->saldo       = $r->total_in - $r->total_out;
        }

        return $rows;
    }

    public static function save( array $data ): int|\WP_Error {
        global $wpdb;
        $fiscal_year = (int) ( $data['fiscal_year'] ?? gmdate( 'Y' ) );
        $coa_id     = (int) ( $data['coa_id'] ?? 0 );
        $amount    = isset( $data['budgeted_amount'] ) ? (float) $data['budgeted_amount'] : 0;
        $amount    = $amount > 0 ? round( $amount, 2 ) : 0;

        if ( $coa_id <= 0 ) {
            return new \WP_Error( 'invalid_coa', __( 'Geen rekeningstelsel geselecteerd.', 'eme-accounting' ) );
        }

        if ( FiscalYear::is_closed( $fiscal_year ) ) {
            return new \WP_Error( 'year_closed', __( 'Boekjaar is afgesloten. Begroting kan niet meer aangepast worden.', 'eme-accounting' ) );
        }

        $existing = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM `{$wpdb->prefix}emeacc_budget`
                 WHERE fiscal_year = %d AND coa_id = %d",
                $fiscal_year,
                $coa_id
            )
        );

        if ( $existing ) {
            if ( $amount > 0 ) {
                $wpdb->update(
                    $wpdb->prefix . 'emeacc_budget',
                    [ 'budgeted_amount' => $amount ],
                    [ 'id' => (int) $existing ],
                    [ '%f' ],
                    [ '%d' ]
                );
            } else {
                $wpdb->delete( $wpdb->prefix . 'emeacc_budget', [ 'id' => (int) $existing ], [ '%d' ] );
            }
            return (int) $existing;
        }

        if ( $amount <= 0 ) {
            return 0;
        }

        $wpdb->insert( $wpdb->prefix . 'emeacc_budget', [
            'fiscal_year'     => $fiscal_year,
            'coa_id'         => $coa_id,
            'budgeted_amount' => $amount,
        ] );
        return (int) $wpdb->insert_id;
    }

    /**
     * Delete all budget lines for a given fiscal year.
     */
    public static function ajax_delete(): void {
        check_ajax_referer( 'eme_accounting_nonce', 'nonce' );
        Capabilities::require_manage_ajax( 'budget' );
        global $wpdb;
        $year = (int) ( $_POST['year'] ?? 0 );
        if ( $year <= 0 ) {
            wp_send_json_error( __( 'Ongeldig boekjaar.', 'eme-accounting' ) );
        }
        $wpdb->delete(
            $wpdb->prefix . 'emeacc_budget',
            [ 'fiscal_year' => $year ],
            [ '%d' ]
        );
        wp_send_json_success();
    }

    public static function ajax_save(): void {
        check_ajax_referer( 'eme_accounting_nonce', 'nonce' );
        Capabilities::require_manage_ajax( 'budget' );

        $fiscal_year = (int) ( $_POST['fiscal_year'] ?? gmdate( 'Y' ) );

        if ( FiscalYear::is_closed( $fiscal_year ) ) {
            wp_send_json_error( __( 'Boekjaar is afgesloten. Begroting kan niet meer aangepast worden.', 'eme-accounting' ) );
        }

        $lines     = $_POST['lines'] ?? [];
        $saved    = 0;

        foreach ( $lines as $line ) {
            $coa_id = (int) ( $line['coa_id'] ?? 0 );
            if ( $coa_id <= 0 ) continue;

            $amount = (float) ( $line['budgeted_amount'] ?? 0 );
            if ( $amount > 0 ) {
                $result = self::save( [
                    'fiscal_year'    => $fiscal_year,
                    'coa_id'         => $coa_id,
                    'budgeted_amount' => $amount,
                ] );
                if ( $result > 0 ) $saved++;
            } else {
                global $wpdb;
                $wpdb->delete(
                    $wpdb->prefix . 'emeacc_budget',
                    [ 'fiscal_year' => $fiscal_year, 'coa_id' => $coa_id ],
                    [ '%d', '%d' ]
                );
            }
        }

        wp_send_json_success( [ 'saved' => $saved ] );
    }

    /**
     * Get actual totals for a specific year (for AJAX copy).
     */
    public static function ajax_get_year(): void {
        check_ajax_referer( 'eme_accounting_nonce', 'nonce' );
        Capabilities::require_view_ajax( 'budget' );

        $year = (int) ( $_POST['year'] ?? gmdate( 'Y' ) );
        $actuals = [];
        $coa = ChartOfAccounts::get_all();
        foreach ( $coa as $c ) {
            $actual = self::get_actual_for_coa( $year, $c->id );
            if ( $actual > 0 ) {
                $actuals[ $c->id ] = $actual;
            }
        }

        wp_send_json_success( $actuals );
    }

    /* ------------------------------------------------------------------
     * PDF GENERATIE
     * ------------------------------------------------------------------ */

    public static function generate_pdf_html( int $year ): string {
        $coa = ChartOfAccounts::get_all();
        $budget_map = self::get_year_map( $year );
        $config = \EMEVzw\Config::get();
        $currency = $config->currency;

        $income_rows = [];
        $expense_rows = [];

        foreach ( $coa as $c ) {
            if ( $c->direction !== 'in' && $c->direction !== 'out' ) continue;
            if ( empty( $c->is_active ) ) continue;
            if ( ! $c->parent_id ) continue;

            $budget = $budget_map[ $c->id ] ?? 0;
            $actual = self::get_actual_for_coa( $year, $c->id );
            $variance = $c->direction === 'out' ? $budget - $actual : $actual - $budget;

            $row = [
                'code'    => $c->code,
                'name'   => $c->name,
                'budget' => $budget,
                'actual' => $actual,
                'variance' => $variance,
            ];

            if ( $c->direction === 'in' ) {
                $income_rows[] = $row;
            } else {
                $expense_rows[] = $row;
            }
        }

        $data = [
            'income'   => $income_rows,
            'expenses' => $expense_rows,
        ];

        ob_start();
        include EME_ACCOUNTING_DIR . 'templates/pdf/budget.php';
        return ob_get_clean();
    }

    public static function ajax_download_pdf(): void {
        check_ajax_referer( 'eme_accounting_nonce', 'nonce' );
        Capabilities::require_view_ajax( 'budget' );

        $year = (int) ( $_GET['year'] ?? gmdate( 'Y' ) );
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
        $dompdf->load_html( $html );
        $dompdf->render();

        $dompdf->stream( 'Begroting_' . $year . '.pdf', [ 'Attachment' => true ] );
        exit;
    }
}
