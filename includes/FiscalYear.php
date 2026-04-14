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
     * This transfers all income and expense balances to a "resultaten" account.
     */
    public static function close_year( int $year ): bool|\WP_Error {
        if ( self::is_closed( $year ) ) {
            return new \WP_Error( 'already_closed', __( 'Boekjaar is al afgesloten.', 'eme-accounting' ) );
        }

        $totals = Journal::get_totals( [ 'fiscal_year' => $year ] );
        if ( $totals['income'] == 0 && $totals['expenses'] == 0 ) {
            return new \WP_Error( 'no_entries', __( 'Geen boekingen in dit boekjaar.', 'eme-accounting' ) );
        }

        global $wpdb;

        // Get or create "Resultaten" account (balans)
        $result_acc = self::get_or_create_result_account();

        // Close income: create expense entry to transfer income to result
        if ( $totals['income'] > 0 ) {
            $income_accounts = self::get_income_accounts_by_category( $year );
            foreach ( $income_accounts as $category => $total ) {
                if ( $total <= 0 ) continue;
                Journal::save( [
                    'entry_date'    => $year . '-12-31',
                    'description'   => sprintf( __( 'Afsluiten ontvangsten %d', 'eme-accounting' ), $year ),
                    'category'      => $category,
                    'amount'        => $total,
                    'direction'     => 'out',
                    'account_id'    => $result_acc->id,
                    'contra_account_id' => null,
                    'fiscal_year'   => $year,
                ] );
            }
        }

        // Close expenses: create income entry to transfer expenses to result
        if ( $totals['expenses'] > 0 ) {
            $expense_accounts = self::get_expense_accounts_by_category( $year );
            foreach ( $expense_accounts as $category => $total ) {
                if ( $total <= 0 ) continue;
                Journal::save( [
                    'entry_date'    => $year . '-12-31',
                    'description'   => sprintf( __( 'Afsluiten uitgaven %d', 'eme-accounting' ), $year ),
                    'category'      => $category,
                    'amount'        => $total,
                    'direction'     => 'in',
                    'account_id'    => $result_acc->id,
                    'contra_account_id' => null,
                    'fiscal_year'   => $year,
                ] );
            }
        }

        // Mark year as closed
        $closed = self::get_closed_years();
        $closed[] = $year;
        update_option( self::OPTION_KEY, array_unique( $closed ) );

        return true;
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

    private static function get_income_accounts_by_category( int $year ): array {
        global $wpdb;
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT category, SUM(amount) as total
                 FROM `{$wpdb->prefix}emeacc_journal`
                 WHERE fiscal_year = %d AND direction = 'in' AND category != ''
                 GROUP BY category",
                $year
            )
        );
        $result = [];
        foreach ( $rows as $row ) {
            $result[ $row->category ] = (float) $row->total;
        }
        return $result;
    }

    private static function get_expense_accounts_by_category( int $year ): array {
        global $wpdb;
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT category, SUM(amount) as total
                 FROM `{$wpdb->prefix}emeacc_journal`
                 WHERE fiscal_year = %d AND direction = 'out' AND category != ''
                 GROUP BY category",
                $year
            )
        );
        $result = [];
        foreach ( $rows as $row ) {
            $result[ $row->category ] = (float) $row->total;
        }
        return $result;
    }

    /**
     * Get the result for a closed year (surplus or deficit).
     */
    public static function get_year_result( int $year ): float {
        $totals = Journal::get_totals( [ 'fiscal_year' => $year ] );
        return $totals['income'] - $totals['expenses'];
    }

    public static function ajax_close(): void {
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
            ? sprintf( __( 'Overschot van %s', 'eme-accounting' ), number_format( $year_result, 2, ',', '.' ) . ' €' )
            : sprintf( __( 'Tekort van %s', 'eme-accounting' ), number_format( abs( $year_result ), 2, ',', '.' ) . ' €' );

        wp_send_json_success( [ 'message' => __( 'Boekjaar afgesloten.', 'eme-accounting' ), 'result' => $label ] );
    }
}