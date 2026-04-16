<?php
namespace EMEAccounting;

defined( 'ABSPATH' ) || exit;

class FiscalYear {

    const OPTION_KEY = 'emeacc_fiscal_years';

    public static function init(): void {
        add_action( 'wp_ajax_eme_accounting_close_year', [ __CLASS__, 'ajax_close' ] );
    }

    public static function get_closed_years(): array {
        return get_option( self::OPTION_KEY, [] );
    }

    public static function is_closed( int $year ): bool {
        $closed = self::get_closed_years();
        return in_array( $year, $closed, true );
    }

    public static function get_current_year(): int {
        return (int) gmdate( 'Y' );
    }

    public static function get_available_years(): array {
        global $wpdb;
        $years = $wpdb->get_col(
            "SELECT DISTINCT fiscal_year FROM `{$wpdb->prefix}emeacc_journal` ORDER BY fiscal_year DESC"
        );
        if ( empty( $years ) ) {
            $years = [ self::get_current_year() ];
        }
        return $years;
    }

    /**
     * Close a fiscal year by creating closing entries.
     *
     * Groups all income/expense entries by coa_id and creates one closing
     * entry per coa_id on the "Resultaten" account (plus one catch-all entry
     * for any bookings that have no coa_id assigned).
     *
     * Income  → 'out' entry on Resultaten (income is absorbed into equity).
     * Expense → 'in'  entry on Resultaten (expense is absorbed into equity).
     *
     * Transfers are never included in closing entries.
     */
    public static function close_year( int $year ): bool|\WP_Error {
        if ( self::is_closed( $year ) ) {
            return new \WP_Error( 'already_closed', __( 'Boekjaar is al afgesloten.', 'eme-accounting' ) );
        }

        $totals = Journal::get_totals( [ 'fiscal_year' => $year ] );
        if ( $totals['income'] == 0 && $totals['expenses'] == 0 ) {
            return new \WP_Error( 'no_entries', __( 'Geen boekingen in dit boekjaar.', 'eme-accounting' ) );
        }

        $result_acc = self::get_or_create_result_account();

        // Close income: one 'out' entry per coa_id on the Resultaten account.
        foreach ( self::get_totals_by_coa( $year, 'in' ) as $group ) {
            if ( $group['total'] <= 0 ) continue;
            Journal::save( [
                'entry_date'        => $year . '-12-31',
                'description'       => sprintf(
                    __( 'Afsluiten ontvangsten %d%s', 'eme-accounting' ),
                    $year,
                    $group['coa_name'] !== '' ? ' \u2013 ' . $group['coa_name'] : ''
                ),
                'amount'            => $group['total'],
                'direction'         => 'out',
                'account_id'        => $result_acc->id,
                'coa_id'            => $group['coa_id'],
                'contra_account_id' => null,
                'fiscal_year'       => $year,
            ] );
        }

        // Close expenses: one 'in' entry per coa_id on the Resultaten account.
        foreach ( self::get_totals_by_coa( $year, 'out' ) as $group ) {
            if ( $group['total'] <= 0 ) continue;
            Journal::save( [
                'entry_date'        => $year . '-12-31',
                'description'       => sprintf(
                    __( 'Afsluiten uitgaven %d%s', 'eme-accounting' ),
                    $year,
                    $group['coa_name'] !== '' ? ' \u2013 ' . $group['coa_name'] : ''
                ),
                'amount'            => $group['total'],
                'direction'         => 'in',
                'account_id'        => $result_acc->id,
                'coa_id'            => $group['coa_id'],
                'contra_account_id' => null,
                'fiscal_year'       => $year,
            ] );
        }

        // Mark year as closed.
        $closed   = self::get_closed_years();
        $closed[] = $year;
        update_option( self::OPTION_KEY, array_unique( $closed ) );

        return true;
    }

    /**
     * Return per-coa_id totals for a given direction and fiscal year.
     *
     * Entries without a coa_id are grouped as a single catch-all row
     * with coa_id = null and coa_name = ''.
     *
     * @return array<int, array{coa_id: int|null, coa_name: string, total: float}>
     */
    private static function get_totals_by_coa( int $year, string $direction ): array {
        global $wpdb;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT j.coa_id,
                        coa.name  AS coa_name,
                        SUM(j.amount) AS total
                 FROM `{$wpdb->prefix}emeacc_journal` j
                 LEFT JOIN `{$wpdb->prefix}emeacc_chart_of_accounts` coa ON coa.id = j.coa_id
                 WHERE j.fiscal_year = %d
                   AND j.direction   = %s
                 GROUP BY j.coa_id
                 ORDER BY coa.code",
                $year,
                $direction
            )
        );

        $result = [];
        foreach ( $rows as $row ) {
            $result[] = [
                'coa_id'   => $row->coa_id !== null ? (int) $row->coa_id : null,
                'coa_name' => (string) ( $row->coa_name ?? '' ),
                'total'    => (float) $row->total,
            ];
        }
        return $result;
    }

    private static function get_or_create_result_account(): object {
        global $wpdb;
        $table = $wpdb->prefix . 'emeacc_accounts';

        $existing = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM `$table` WHERE name = %s", __( 'Resultaten', 'eme-accounting' ) )
        );

        if ( $existing ) {
            return $existing;
        }

        $wpdb->insert( $table, [
            'name'            => __( 'Resultaten', 'eme-accounting' ),
            'opening_balance' => 0,
            'is_active'       => 1,
            'sort_order'      => 9999,
        ] );

        return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `$table` WHERE id = %d", $wpdb->insert_id ) );
    }

    /**
     * Get the result for a fiscal year (positive = surplus, negative = deficit).
     */
    public static function get_year_result( int $year ): float {
        $totals = Journal::get_totals( [ 'fiscal_year' => $year ] );
        return $totals['income'] - $totals['expenses'];
    }

    public static function ajax_close(): void {
        $formatter = new \NumberFormatter( 'nl_BE', \NumberFormatter::CURRENCY );

        check_ajax_referer( 'eme_accounting_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( __( 'Geen rechten.', 'eme-accounting' ) );
        }

        $year = (int) ( $_POST['year'] ?? 0 );
        if ( ! $year ) {
            wp_send_json_error( __( 'Geen boekjaar geselecteerd.', 'eme-accounting' ) );
        }

        $result = self::close_year( $year );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }

        $year_result = self::get_year_result( $year );
        $label = $year_result >= 0
            ? sprintf( __( 'Overschot van %s', 'eme-accounting' ), $formatter->formatCurrency( $year_result, 'EUR' ) )
            : sprintf( __( 'Tekort van %s', 'eme-accounting' ), $formatter->formatCurrency( abs( $year_result ), 'EUR' ) );

        wp_send_json_success( [ 'message' => __( 'Boekjaar afgesloten.', 'eme-accounting' ), 'result' => $label ] );
    }
}
