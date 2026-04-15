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
                "SELECT coa_id, direction, budgeted_amount
                 FROM `{$wpdb->prefix}emeacc_budget`
                 WHERE fiscal_year = %d",
                $year
            )
        );
        $map = [];
        foreach ( $rows as $r ) {
            $key = (int) $r->coa_id . '|' . $r->direction;
            $map[ $key ] = (float) $r->budgeted_amount;
        }
        return $map;
    }

    /**
     * Get totals from journal for each COA/category in a year.
     */
    public static function get_actual_totals( int $year ): array {
        global $wpdb;
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT coa_id, direction,
                       COALESCE(SUM(amount), 0) as total
                FROM `{$wpdb->prefix}emeacc_journal`
                WHERE fiscal_year = %d AND direction != 'transfer'
                GROUP BY coa_id, direction",
                $year
            )
        );
        $totals = [];
        foreach ( $rows as $r ) {
            $key = (int) $r->coa_id . '|' . $r->direction;
            $totals[ $key ] = (float) $r->total;
        }
        return $totals;
    }

    /**
     * Get actual total for a specific COA and direction.
     */
    public static function get_actual_for_coa( int $year, int $coa_id, string $direction ): float {
        global $wpdb;
        return (float) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COALESCE(SUM(amount), 0) as total
                 FROM `{$wpdb->prefix}emeacc_journal`
                 WHERE fiscal_year = %d AND coa_id = %d AND direction = %s AND direction != 'transfer'",
                $year,
                $coa_id,
                $direction
            )
        );
    }

    /**
     * Get comparison for all COA entries in a year.
     */
    public static function get_comparison( int $year ): array {
        $budget_map  = self::get_year_map( $year );
        $actual_map = self::get_actual_totals( $year );
        $coa       = ChartOfAccounts::get_all();
        $out       = [];

        foreach ( $coa as $account ) {
            $coa_id = (int) $account->id;
            $key_in  = $coa_id . '|in';
            $key_out = $coa_id . '|out';

            // IN
            $budget_in  = $budget_map[ $key_in ] ?? 0;
            $actual_in = $actual_map[ $key_in ] ?? 0;
            if ( $budget_in > 0 || $actual_in > 0 || $account->direction === 'in' ) {
                $out[] = [
                    'coa_id'      => $coa_id,
                    'account_name' => $account->code . ' - ' . $account->name,
                    'direction' => 'in',
                    'budget'    => $budget_in,
                    'actual'   => $actual_in,
                    'variance'=> $budget_in - $actual_in,
                ];
            }

            // OUT
            $budget_out  = $budget_map[ $key_out ] ?? 0;
            $actual_out = $actual_map[ $key_out ] ?? 0;
            if ( $budget_out > 0 || $actual_out > 0 || $account->direction === 'out' ) {
                $out[] = [
                    'coa_id'      => $coa_id,
                    'account_name' => $account->code . ' - ' . $account->name,
                    'direction' => 'out',
                    'budget'    => $budget_out,
                    'actual'   => $actual_out,
                    'variance'=> $budget_out - $actual_out,
                ];
            }
        }

        return $out;
    }

    public static function save( array $data ): int|\WP_Error {
        global $wpdb;
        $fiscal_year = (int) ( $data['fiscal_year'] ?? gmdate( 'Y' ) );
        $coa_id     = (int) ( $data['coa_id'] ?? 0 );
        $direction = in_array( $data['direction'] ?? 'out', [ 'in', 'out' ], true ) ? $data['direction'] : 'out';
        $amount    = (float) ( $data['budgeted_amount'] ?? 0 );

        if ( $coa_id <= 0 ) {
            return new \WP_Error( 'invalid_coa', __( 'Geen rekeningstelsel geselecteerd.', 'eme-accounting' ) );
        }

        // Check if exists
        $existing = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM `{$wpdb->prefix}emeacc_budget`
                 WHERE fiscal_year = %d AND coa_id = %d AND direction = %s",
                $fiscal_year,
                $coa_id,
                $direction
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
            'coa_id'        => $coa_id,
            'direction'     => $direction,
            'budgeted_amount' => $amount,
        ] );
        return (int) $wpdb->insert_id;
    }

    public static function ajax_save(): void {
        check_ajax_referer( 'eme_accounting_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error();

        $fiscal_year = (int) ( $_POST['fiscal_year'] ?? gmdate( 'Y' ) );
        $budget    = $_POST['budget'] ?? [];
        $saved    = 0;

        // Handle IN entries
        foreach ( $budget['in'] ?? [] as $coa_id => $amount ) {
            $coa_id = (int) $coa_id;
            $amount = (float) $amount;
            if ( $coa_id <= 0 ) continue;

            if ( $amount > 0 ) {
                $result = self::save( [
                    'fiscal_year'      => $fiscal_year,
                    'coa_id'        => $coa_id,
                    'direction'    => 'in',
                    'budgeted_amount' => $amount,
                ] );
                if ( $result > 0 ) $saved++;
            } else {
                global $wpdb;
                $wpdb->delete(
                    $wpdb->prefix . 'emeacc_budget',
                    [ 'fiscal_year' => $fiscal_year, 'coa_id' => $coa_id, 'direction' => 'in' ],
                    [ '%d', '%d', '%s' ]
                );
            }
        }

        // Handle OUT entries
        foreach ( $budget['out'] ?? [] as $coa_id => $amount ) {
            $coa_id = (int) $coa_id;
            $amount = (float) $amount;
            if ( $coa_id <= 0 ) continue;

            if ( $amount > 0 ) {
                $result = self::save( [
                    'fiscal_year'      => $fiscal_year,
                    'coa_id'        => $coa_id,
                    'direction'    => 'out',
                    'budgeted_amount' => $amount,
                ] );
                if ( $result > 0 ) $saved++;
            } else {
                global $wpdb;
                $wpdb->delete(
                    $wpdb->prefix . 'emeacc_budget',
                    [ 'fiscal_year' => $fiscal_year, 'coa_id' => $coa_id, 'direction' => 'out' ],
                    [ '%d', '%d', '%s' ]
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
        // Return actual totals (not budget) for copying
        $actuals = [];
        $coa = ChartOfAccounts::get_all();
        foreach ( $coa as $c ) {
            $actual = self::get_actual_for_coa( $year, $c->id, $c->direction );
            if ( $actual > 0 ) {
                $actuals[ $c->id . '|' . $c->direction ] = $actual;
            }
        }

        wp_send_json_success( $actuals );
    }
}