<?php
namespace EMEAccounting;
defined( 'ABSPATH' ) || exit;

/* ===========================================================================
 * Stock management
 * =========================================================================== */
class Stock {
    public static function init(): void {
        add_action( 'wp_ajax_eme_accounting_save_stock',     [ __CLASS__, 'ajax_save' ] );
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
        $sign = in_array( $type, [ 'in', 'adjustment' ], true ) ? '+' : '-';
        $wpdb->query( $wpdb->prepare(
            "UPDATE `{$wpdb->prefix}emeacc_stock` SET current_qty = current_qty {$sign} %f WHERE id = %d",
            $qty, $stock_id
        ) );
        return (int) $wpdb->insert_id;
    }

    public static function ajax_save(): void {
        check_ajax_referer( 'eme_accounting_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error();
        $r = self::save( $_POST );
        is_wp_error( $r ) ? wp_send_json_error( $r->get_error_message() ) : wp_send_json_success( [ 'id' => $r ] );
    }

    public static function ajax_movement(): void {
        check_ajax_referer( 'eme_accounting_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error();
        $r = self::add_movement(
            (int) ( $_POST['stock_id'] ?? 0 ),
            eme_sanitize_request( $_POST['type'] ?? 'in' ),
            (float) ( $_POST['qty'] ?? 0 ),
            (float) ( $_POST['unit_price'] ?? 0 ),
            eme_sanitize_request( $_POST['notes'] ?? '' )
        );
        is_wp_error( $r ) ? wp_send_json_error( $r->get_error_message() ) : wp_send_json_success();
    }
}

/* ===========================================================================
 * Depreciation
 * =========================================================================== */
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

/* ===========================================================================
 * Budget
 * =========================================================================== */
class Budget {
    public static function init(): void {
        add_action( 'wp_ajax_eme_accounting_save_budget_line', [ __CLASS__, 'ajax_save' ] );
    }

    public static function get_year( int $year ): array {
        global $wpdb;
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT b.*, a.name as account_name
                 FROM `{$wpdb->prefix}emeacc_budget` b
                 LEFT JOIN `{$wpdb->prefix}emeacc_accounts` a ON a.id = b.account_id
                 WHERE b.fiscal_year = %d
                 ORDER BY b.direction DESC, a.name, b.category",
                $year
            )
        );
    }

    /**
     * Budget vs actual comparison for a given year.
     *
     * @return array{budget: float, actual: float, variance: float, variance_pct: float}[]
     */
    public static function get_comparison( int $year ): array {
        $budget_lines = self::get_year( $year );
        $out          = [];

        foreach ( $budget_lines as $b ) {
            $actual = (float) Journal::get_totals( [
                'fiscal_year' => $year,
                'account_id'  => (int) $b->account_id,
                'category'    => $b->category ?: null,
            ] )[ $b->direction === 'in' ? 'income' : 'expenses' ];

            $budget   = (float) $b->budgeted_amount;
            $variance = $actual - $budget;
            $out[]    = [
                'id'           => $b->id,
                'account_name' => $b->account_name,
                'category'     => $b->category,
                'direction'    => $b->direction,
                'budget'       => $budget,
                'actual'       => $actual,
                'variance'     => $variance,
                'variance_pct' => $budget != 0 ? round( $variance / $budget * 100, 1 ) : null,
            ];
        }

        return $out;
    }

    public static function save( array $data ): int|\WP_Error {
        global $wpdb;
        $row = [
            'fiscal_year'     => (int) ( $data['fiscal_year'] ?? gmdate( 'Y' ) ),
            'account_id'      => (int) ( $data['account_id'] ?? 0 ),
            'category'        => eme_sanitize_request( $data['category'] ?? '' ),
            'direction'       => in_array( $data['direction'] ?? 'out', [ 'in', 'out' ], true ) ? $data['direction'] : 'out',
            'budgeted_amount' => (float) ( $data['budgeted_amount'] ?? 0 ),
            'notes'           => eme_sanitize_request( $data['notes'] ?? '' ),
        ];
        $id = isset( $data['id'] ) ? (int) $data['id'] : 0;
        if ( $id > 0 ) {
            $wpdb->update( $wpdb->prefix . 'emeacc_budget', $row, [ 'id' => $id ], null, [ '%d' ] );
            return $id;
        }
        $wpdb->insert( $wpdb->prefix . 'emeacc_budget', $row );
        return (int) $wpdb->insert_id;
    }

    public static function ajax_save(): void {
        check_ajax_referer( 'eme_accounting_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error();
        $r = self::save( $_POST );
        is_wp_error( $r ) ? wp_send_json_error( $r->get_error_message() ) : wp_send_json_success( [ 'id' => $r ] );
    }
}

/* ===========================================================================
 * Financial Reports
 * =========================================================================== */
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
     * asset/liability overview based on account balances.
     *
     * @return array{date: string, assets: array, liabilities: array, equity: float}
     */
    public static function patrimony( string $date = '' ): array {
        if ( ! $date ) $date = current_time( 'Y-m-d' );

        $accounts = Accounts::get_all();
        $assets   = [];
        $liabs    = [];

        foreach ( $accounts as $acc ) {
            $balance = Accounts::get_balance( (int) $acc->id, $date );
            if ( in_array( $acc->type, [ 'asset' ], true ) ) {
                $assets[ $acc->name ] = $balance;
            } elseif ( in_array( $acc->type, [ 'liability' ], true ) ) {
                $liabs[ $acc->name ] = $balance;
            }
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

/* ===========================================================================
 * Admin assets enqueue
 * =========================================================================== */
class Assets {
    public static function init(): void {
        add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue' ] );
    }

    public static function enqueue( string $hook ): void {
        if ( strpos( $hook, 'eme-accounting' ) === false && strpos( $hook, 'eme_accounting' ) === false ) {
            return;
        }
        wp_enqueue_style( 'eme-accounting', EME_ACCOUNTING_URL . 'assets/css/admin.css', [], EME_ACCOUNTING_VERSION );
        wp_enqueue_script( 'eme-accounting', EME_ACCOUNTING_URL . 'assets/js/admin.js', [ 'jquery' ], EME_ACCOUNTING_VERSION, true );
        wp_localize_script( 'eme-accounting', 'emeAccounting', [
            'nonce'   => wp_create_nonce( 'eme_accounting_nonce' ),
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'i18n'    => [
                'confirm_delete' => __( 'Zeker verwijderen?', 'eme-accounting' ),
                'saving'         => __( 'Opslaan…', 'eme-accounting' ),
                'saved'          => __( 'Opgeslagen', 'eme-accounting' ),
            ],
        ] );
    }
}

/* ===========================================================================
 * Bridge to Events Made Easy
 * =========================================================================== */
class EMEBridge {
    public static function init(): void {
        // Auto-create journal entry when EME registers a payment
        add_action( 'eme_payment_paid', [ __CLASS__, 'on_payment' ], 10, 2 );
    }

    /**
     * When EME marks a payment as paid, create a corresponding journal entry.
     *
     * @param  int   $payment_id  EME payment ID
     * @param  array $payment     EME payment data
     */
    public static function on_payment( int $payment_id, array $payment ): void {
        // Only auto-book if a default income account is configured
        $settings         = get_option( 'eme_accounting_settings', [] );
        $default_account  = (int) ( $settings['default_income_account'] ?? 0 );
        if ( ! $default_account ) {
            return;
        }

        // Prevent duplicate bookings
        global $wpdb;
        $exists = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM `{$wpdb->prefix}emeacc_journal` WHERE eme_payment_id = %d",
            $payment_id
        ) );
        if ( $exists ) return;

        Journal::save( [
            'entry_date'     => $payment['payment_date'] ?? current_time( 'Y-m-d' ),
            'description'    => sprintf(
                __( 'EME betaling #%d – %s', 'eme-accounting' ),
                $payment_id,
                $payment['event_name'] ?? ''
            ),
            'category'       => 'eme-inschrijving',
            'amount'         => (float) ( $payment['amount'] ?? 0 ),
            'direction'      => 'in',
            'account_id'     => $default_account,
            'eme_payment_id' => $payment_id,
            'eme_event_id'   => (int) ( $payment['event_id'] ?? 0 ),
            'fiscal_year'    => (int) gmdate( 'Y', strtotime( $payment['payment_date'] ?? 'now' ) ),
        ] );
    }
}
