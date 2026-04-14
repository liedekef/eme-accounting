<?php
namespace EMEAccounting;

defined( 'ABSPATH' ) || exit;

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
