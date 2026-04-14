<?php
namespace EMEAccounting;

defined( 'ABSPATH' ) || exit;

class Reports {
    public static function init(): void {
        add_action( 'wp_ajax_eme_accounting_export_report', [ __CLASS__, 'ajax_export' ] );
    }

    /**
     * Staat van Ontvangsten en Uitgaven (income & expenditure statement).
     *
     * @return array{fiscal_year: int, income: array, expenses: array, totals: array}
     */
    public static function income_expenditure( int $year ): array {
        global $wpdb;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT direction, category,
                        COALESCE(SUM(amount), 0) as total
                 FROM `{$wpdb->prefix}emeacc_journal`
                 WHERE fiscal_year = %d AND direction != 'transfer'
                 GROUP BY direction, category
                 ORDER BY direction DESC, category",
                $year
            )
        );

        $income   = [];
        $expenses = [];
        foreach ( $rows as $row ) {
            if ( $row->direction === 'in' ) {
                $income[ $row->category ?: __( '(zonder categorie)', 'eme-accounting' ) ] = (float) $row->total;
            } else {
                $expenses[ $row->category ?: __( '(zonder categorie)', 'eme-accounting' ) ] = (float) $row->total;
            }
        }

        $total_income   = array_sum( $income );
        $total_expenses = array_sum( $expenses );

        return [
            'fiscal_year' => $year,
            'income'      => $income,
            'expenses'    => $expenses,
            'totals'      => [
                'income'   => $total_income,
                'expenses' => $total_expenses,
                'result'   => $total_income - $total_expenses,
            ],
        ];
    }

    /**
     * Staat van Vermogen (balance sheet / patrimony statement).
     * For a micro VZW using single-entry bookkeeping, this is a simplified
     * asset/liability overview based on account balances + stock + depreciation.
     *
     * @return array{date: string, assets: array, liabilities: array, equity: float}
     */
    public static function patrimony( string $date = '' ): array {
        global $wpdb;
        if ( ! $date ) $date = current_time( 'Y-m-d' );

        // Financial accounts (all treated as assets for VZW)
        $accounts = Accounts::get_all();
        $assets   = [];
        $liabs    = [];

        foreach ( $accounts as $acc ) {
            $balance = Accounts::get_balance( (int) $acc->id, $date );
            if ( $balance > 0 ) {
                $assets[ $acc->name ] = $balance;
            } elseif ( $balance < 0 ) {
                $liabs[ $acc->name ] = abs( $balance );
            }
        }

        // Stock inventory value
        $stock_value = (float) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COALESCE(SUM(current_qty * sale_price), 0) 
                 FROM `{$wpdb->prefix}emeacc_stock` 
                 WHERE is_active = 1 AND current_qty > 0"
            )
        );
        if ( $stock_value > 0 ) {
            $assets[ __( 'Voorraad', 'eme-accounting' ) ] = $stock_value;
        }

        // Depreciation: current book value of assets
        $dep_value = (float) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COALESCE(SUM(current_book_value), 0) 
                 FROM `{$wpdb->prefix}emeacc_depreciation` 
                 WHERE is_active = 1"
            )
        );
        if ( $dep_value > 0 ) {
            $assets[ __( 'Afschrijfbare activa', 'eme-accounting' ) ] = $dep_value;
        }

        $total_assets = array_sum( $assets );
        $total_liabs  = array_sum( $liabs );

        return [
            'date'        => $date,
            'assets'      => $assets,
            'liabilities' => $liabs,
            'totals'      => [
                'assets'      => $total_assets,
                'liabilities' => $total_liabs,
                'equity'      => $total_assets - $total_liabs,
            ],
        ];
    }

    /**
     * Financial diary report: all entries for a period, grouped by date.
     *
     * @return array<string, object[]>  date => entries
     */
    public static function diary( array $args = [] ): array {
        $entries = Journal::get_entries( array_merge( $args, [ 'limit' => 9999, 'orderby' => 'entry_date', 'order' => 'ASC' ] ) );
        $grouped = [];
        foreach ( $entries as $entry ) {
            $grouped[ $entry->entry_date ][] = $entry;
        }
        return $grouped;
    }

    public static function ajax_export(): void {
        check_ajax_referer( 'eme_accounting_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die();

        $type   = eme_sanitize_request( $_GET['type'] ?? 'diary' );
        $year   = (int) ( $_GET['year'] ?? gmdate( 'Y' ) );
        $format = eme_sanitize_request( $_GET['format'] ?? 'csv' );

        $data = match ( $type ) {
            'income_expenditure' => self::income_expenditure( $year ),
            'patrimony'          => self::patrimony( $_GET['date'] ?? '' ),
            default              => self::diary( [ 'fiscal_year' => $year ] ),
        };

        if ( $format === 'csv' ) {
            self::output_csv( $data, $type . '_' . $year );
        } else {
            wp_send_json_success( $data );
        }
    }

    private static function output_csv( array $data, string $filename ): void {
        header( 'Content-Type: text/csv; charset=UTF-8' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '.csv"' );
        echo "\xEF\xBB\xBF"; // BOM for Excel
        $out = fopen( 'php://output', 'w' );
        // Flatten nested data
        self::array_to_csv( $out, $data );
        fclose( $out );
        exit;
    }

    private static function array_to_csv( $handle, array $data, int $depth = 0 ): void {
        foreach ( $data as $key => $value ) {
            if ( is_array( $value ) ) {
                fputcsv( $handle, [ $key ], ';' );
                self::array_to_csv( $handle, $value, $depth + 1 );
            } elseif ( is_object( $value ) ) {
                fputcsv( $handle, (array) $value, ';' );
            } else {
                fputcsv( $handle, [ $key, $value ], ';' );
            }
        }
    }
}
