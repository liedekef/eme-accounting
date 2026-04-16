<?php
namespace EMEAccounting;

defined( 'ABSPATH' ) || exit;

class Budget {
    public static function init(): void {
        add_action( 'wp_ajax_eme_accounting_save_budget_line', [ __CLASS__, 'ajax_save' ] );
        add_action( 'wp_ajax_eme_accounting_get_budget_year', [ __CLASS__, 'ajax_get_year' ] );
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
                 WHERE fiscal_year = %d AND coa_id = %d AND direction != 'transfer'",
                $year,
                $coa_id
            )
        );
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

    public static function ajax_save(): void {
        check_ajax_referer( 'eme_accounting_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error();

        $fiscal_year = (int) ( $_POST['fiscal_year'] ?? gmdate( 'Y' ) );
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
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error();

        $year = (int) ( $_GET['year'] ?? gmdate( 'Y' ) );
        $actuals = [];
        $coa = ChartOfAccounts::get_all();
        foreach ( $coa as $c ) {
            $actual = self::get_actual_for_coa( $year, $c->id );
            error_log("$year {$c->id} $actual");
            if ( $actual > 0 ) {
                $actuals[ $c->id ] = $actual;
            }
        }

        wp_send_json_success( $actuals );
    }
}
