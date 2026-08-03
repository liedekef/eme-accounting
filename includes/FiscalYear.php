<?php
// phpcs:ignoreFile WordPress.DB.DirectDatabaseQuery.DirectQuery WordPress.DB.DirectDatabaseQuery.NoCaching
namespace EMEAccounting;

defined( 'ABSPATH' ) || exit;

use EMEAccounting\Capabilities;

class FiscalYear {

    public static function init(): void {
        add_action( 'wp_ajax_eme_accounting_save_fiscal_year', [ __CLASS__, 'ajax_save' ] );
        add_action( 'wp_ajax_eme_accounting_close_fiscal_year', [ __CLASS__, 'ajax_close' ] );
        add_action( 'wp_ajax_eme_accounting_reopen_fiscal_year', [ __CLASS__, 'ajax_reopen' ] );
        add_action( 'wp_ajax_eme_accounting_delete_fiscal_year', [ __CLASS__, 'ajax_delete' ] );
        add_action( 'wp_ajax_eme_accounting_check_fiscal_year', [ __CLASS__, 'ajax_check' ] );
    }

    private static function days_in_month( int $month, int $year ): int {
        return cal_days_in_month( CAL_GREGORIAN, $month, $year );
    }

    public static function calculate_dates( int $year ): array {
        $config = \EMEVzw\Config::get();
        $start_month = (int) ( $config->fiscal_year_start_mm ?? 1 );
        $start_day   = (int) ( $config->fiscal_year_start_dd ?? 1 );
        $end_month   = (int) ( $config->fiscal_year_end_mm ?? 12 );
        $end_day     = (int) ( $config->fiscal_year_end_dd ?? 31 );

        $start_date = sprintf( '%04d-%02d-%02d', $year, $start_month, min( $start_day, self::days_in_month( $start_month, $year ) ) );
        $end_date   = sprintf( '%04d-%02d-%02d', $year, $end_month, min( $end_day, self::days_in_month( $end_month, $year ) ) );

        if ( $end_date < $start_date ) {
            $end_date = sprintf( '%04d-%02d-%02d', $year + 1, $end_month, min( $end_day, self::days_in_month( $end_month, $year + 1 ) ) );
        }

        return [
            'start_date' => $start_date,
            'end_date'   => $end_date,
        ];
    }

    public static function get_all(): array {
        global $wpdb;
        return $wpdb->get_results(
            "SELECT * FROM `{$wpdb->prefix}emeacc_fiscal_years` ORDER BY year DESC"
        );
    }

    public static function get( int $year ): ?object {
        global $wpdb;
        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM `{$wpdb->prefix}emeacc_fiscal_years` WHERE year = %d",
                $year
            )
        );
    }

    public static function get_by_id( int $id ): ?object {
        global $wpdb;
        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM `{$wpdb->prefix}emeacc_fiscal_years` WHERE id = %d",
                $id
            )
        );
    }

    public static function create( array $data ): int|\WP_Error {
        global $wpdb;

        $year       = (int) ( $data['year'] ?? 0 );
        $start_date = sanitize_text_field( $data['start_date'] ?? '' );
        $end_date   = sanitize_text_field( $data['end_date'] ?? '' );

        if ( $year < 1900 || $year > 2100 ) {
            return new \WP_Error( 'invalid_year', __( 'Ongeldig boekjaar.', 'eme-accounting' ) );
        }

        if ( empty( $start_date ) || empty( $end_date ) ) {
            return new \WP_Error( 'missing_dates', __( 'Start- en einddatum zijn verplicht.', 'eme-accounting' ) );
        }

        $existing = self::get( $year );
        if ( $existing ) {
            return new \WP_Error( 'year_exists', __( 'Boekjaar bestaat al.', 'eme-accounting' ) );
        }

        $result = $wpdb->insert(
            $wpdb->prefix . 'emeacc_fiscal_years',
            [
                'year'       => $year,
                'start_date' => $start_date,
                'end_date'   => $end_date,
                'status'     => 'open',
            ]
        );

        if ( $result === false ) {
            return new \WP_Error( 'db_error', __( 'Kon boekjaar niet aanmaken.', 'eme-accounting' ) );
        }

        return (int) $wpdb->insert_id;
    }

    public static function update( int $year, array $data ): bool|\WP_Error {
        global $wpdb;

        $existing = self::get( $year );
        if ( ! $existing ) {
            return new \WP_Error( 'not_found', __( 'Boekjaar niet gevonden.', 'eme-accounting' ) );
        }

        if ( $existing->status === 'closed' ) {
            return new \WP_Error( 'year_closed', __( 'Kan geen gesloten boekjaar bewerken.', 'eme-accounting' ) );
        }

        $update = [];
        if ( ! empty( $data['start_date'] ) ) {
            $update['start_date'] = sanitize_text_field( $data['start_date'] );
        }
        if ( ! empty( $data['end_date'] ) ) {
            $update['end_date'] = sanitize_text_field( $data['end_date'] );
        }

        if ( empty( $update ) ) {
            return true;
        }

        $result = $wpdb->update(
            $wpdb->prefix . 'emeacc_fiscal_years',
            $update,
            [ 'year' => $year ]
        );

        return $result !== false;
    }

    public static function update_by_id( int $id, array $data ): bool|\WP_Error {
        global $wpdb;

        $existing = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM `{$wpdb->prefix}emeacc_fiscal_years` WHERE id = %d",
                $id
            )
        );
        if ( ! $existing ) {
            return new \WP_Error( 'not_found', __( 'Boekjaar niet gevonden.', 'eme-accounting' ) );
        }

        if ( $existing->status === 'closed' ) {
            return new \WP_Error( 'year_closed', __( 'Kan geen gesloten boekjaar bewerken.', 'eme-accounting' ) );
        }

        $update = [];
        if ( ! empty( $data['start_date'] ) ) {
            $update['start_date'] = sanitize_text_field( $data['start_date'] );
        }
        if ( ! empty( $data['end_date'] ) ) {
            $update['end_date'] = sanitize_text_field( $data['end_date'] );
        }

        if ( empty( $update ) ) {
            return true;
        }

        $result = $wpdb->update(
            $wpdb->prefix . 'emeacc_fiscal_years',
            $update,
            [ 'id' => $id ]
        );

        return $result !== false;
    }

    public static function delete( int $year ): bool|\WP_Error {
        global $wpdb;

        $existing = self::get( $year );
        if ( ! $existing ) {
            return new \WP_Error( 'not_found', __( 'Boekjaar niet gevonden.', 'eme-accounting' ) );
        }

        if ( $existing->status === 'closed' ) {
            return new \WP_Error( 'year_closed', __( 'Kan geen gesloten boekjaar verwijderen.', 'eme-accounting' ) );
        }

        $has_entries = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM `{$wpdb->prefix}emeacc_journal` WHERE fiscal_year = %d",
                $year
            )
        );

        if ( $has_entries > 0 ) {
            return new \WP_Error( 'has_entries', __( 'Boekjaar heeft nog boekingen en kan niet verwijderd worden.', 'eme-accounting' ) );
        }

        $result = $wpdb->delete(
            $wpdb->prefix . 'emeacc_fiscal_years',
            [ 'year' => $year ]
        );

        return $result !== false;
    }

    public static function is_closed( int $year ): bool {
        $fy = self::get( $year );
        return $fy && $fy->status === 'closed';
    }

    public static function get_for_date( string $date ): ?object {
        global $wpdb;
        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM `{$wpdb->prefix}emeacc_fiscal_years`
                 WHERE %s BETWEEN start_date AND end_date",
                $date
            )
        );
    }

    public static function get_or_create_for_date( string $date ): ?object {
        $fy = self::get_for_date( $date );
        if ( $fy ) {
            return $fy;
        }

        $year  = (int) date( 'Y', strtotime( $date ) ); //phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
        $dates = self::calculate_dates( $year );

        $fy = self::get( $year );
        if ( ! $fy ) {
            $result = $wpdb->insert(
                $wpdb->prefix . 'emeacc_fiscal_years',
                [
                    'year'       => $year,
                    'start_date' => $dates['start_date'],
                    'end_date'   => $dates['end_date'],
                    'status'     => 'open',
                ]
            );
            if ( $result ) {
                $fy = self::get( $year );
            }
        }

        return $fy;
    }

    public static function close( int $year ): bool|\WP_Error {
        global $wpdb;

        $fy = self::get( $year );
        if ( ! $fy ) {
            return new \WP_Error( 'not_found', __( 'Boekjaar niet gevonden.', 'eme-accounting' ) );
        }

        if ( $fy->status === 'closed' ) {
            return new \WP_Error( 'already_closed', __( 'Boekjaar is al afgesloten.', 'eme-accounting' ) );
        }

        $totals = Journal::get_totals( [ 'fiscal_year' => $year ] );
        if ( $totals['income'] == 0 && $totals['expenses'] == 0 ) {
            return new \WP_Error( 'no_entries', __( 'Geen boekingen in dit boekjaar.', 'eme-accounting' ) );
        }

        /*
        $result_acc = self::get_or_create_result_account();

        foreach ( self::get_totals_by_coa( $year, 'in' ) as $group ) {
            if ( $group['total'] <= 0 ) continue;
            Journal::save( [
                'entry_date'        => $year . '-12-31',
                'description'       => sprintf(
                    __( 'Afsluiten ontvangsten %d%s', 'eme-accounting' ),
                    $year,
                    $group['coa_name'] !== '' ? ' – ' . $group['coa_name'] : ''
                ),
                'amount'            => $group['total'],
                'direction'         => 'out',
                'account_id'        => $result_acc->id,
                'coa_id'            => $group['coa_id'],
                'contra_account_id' => null,
                'fiscal_year'       => $year,
                'is_closing_entry'  => 1,
            ] );
        }

        foreach ( self::get_totals_by_coa( $year, 'out' ) as $group ) {
            if ( $group['total'] <= 0 ) continue;
            Journal::save( [
                'entry_date'        => $year . '-12-31',
                'description'       => sprintf(
                    __( 'Afsluiten uitgaven %d%s', 'eme-accounting' ),
                    $year,
                    $group['coa_name'] !== '' ? ' – ' . $group['coa_name'] : ''
                ),
                'amount'            => $group['total'],
                'direction'         => 'in',
                'account_id'        => $result_acc->id,
                'coa_id'            => $group['coa_id'],
                'contra_account_id' => null,
                'fiscal_year'       => $year,
                'is_closing_entry'  => 1,
            ] );
        }
         */

        $wpdb->update(
            $wpdb->prefix . 'emeacc_fiscal_years',
            [
                'status'     => 'closed',
                'closed_at'  => current_time( 'mysql' ),
                'closed_by'  => get_current_user_id(),
            ],
            [ 'year' => $year ]
        );

        return true;
    }

    public static function reopen( int $year ): bool|\WP_Error {
        global $wpdb;

        $fy = self::get( $year );
        if ( ! $fy ) {
            return new \WP_Error( 'not_found', __( 'Boekjaar niet gevonden.', 'eme-accounting' ) );
        }

        if ( $fy->status !== 'closed' ) {
            return new \WP_Error( 'not_closed', __( 'Boekjaar is niet afgesloten.', 'eme-accounting' ) );
        }

        self::delete_closing_entries( $year );

        $wpdb->update(
            $wpdb->prefix . 'emeacc_fiscal_years',
            [
                'status'      => 'open',
                'reopened_at' => current_time( 'mysql' ),
                'reopened_by' => get_current_user_id(),
                'closed_at'   => null,
                'closed_by'   => null,
            ],
            [ 'year' => $year ]
        );

        return true;
    }

    private static function delete_closing_entries( int $year ): void {
        global $wpdb;
        $wpdb->delete(
            $wpdb->prefix . 'emeacc_journal',
            [
                'fiscal_year'       => $year,
                'is_closing_entry'  => 1,
            ],
            [ '%d', '%d' ]
        );
    }

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
                   AND j.is_closing_entry = 0
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

    /*
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
    }*/

    public static function get_year_result( int $year ): float {
        $totals = Journal::get_totals( [ 'fiscal_year' => $year ] );
        return $totals['income'] - $totals['expenses'];
    }

    public static function ajax_save(): void {
        check_ajax_referer( 'eme_accounting_nonce', 'nonce' );
        Capabilities::require_manage_ajax( 'fiscalyears' );

        $id         = (int) eme_sanitize_request( $_POST['id'] ?? 0 ); //phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        $year       = (int) eme_sanitize_request( $_POST['year'] ?? 0 ); //phpcs:ignore WordPress.Security.ValidatedSanitizedInput

        if ( $year < 1900 || $year > 2100 ) {
            wp_send_json_error( __( 'Ongeldig boekjaar.', 'eme-accounting' ) );
        }

        $dates = self::calculate_dates( $year );

        if ( $id > 0 ) {
            $result = self::update_by_id( $id, $dates );
        } else {
            $result = self::create( [
                'year'       => $year,
                'start_date' => $dates['start_date'],
                'end_date'   => $dates['end_date'],
            ] );
        }

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }

        wp_send_json_success( [ 'message' => __( 'Boekjaar opgeslagen.', 'eme-accounting' ) ] );
    }

    public static function ajax_close(): void {
        check_ajax_referer( 'eme_accounting_nonce', 'nonce' );
        Capabilities::require_manage_ajax( 'fiscalyears' );

        $config = \EMEVzw\Config::get();
        $year = (int) eme_sanitize_request( $_POST['year'] ?? 0 ); //phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        if ( ! $year ) {
            wp_send_json_error( __( 'Geen boekjaar geselecteerd.', 'eme-accounting' ) );
        }

        $result = self::close( $year );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }

        $formatter = new \NumberFormatter( 'nl_BE', \NumberFormatter::CURRENCY );
        $year_result = self::get_year_result( $year );
        $label = $year_result >= 0
            ? sprintf(
                // translators: %s is the formatted amount of the surplus.
                __( 'Overschot van %s', 'eme-accounting' ),
                $formatter->formatCurrency( $year_result, $config->currency )
            )
            : sprintf(
                // translators: %s is the formatted amount of the deficit.
                __( 'Tekort van %s', 'eme-accounting' ),
                $formatter->formatCurrency( abs( $year_result ), $config->currency )
            );

        wp_send_json_success( [ 'message' => __( 'Boekjaar afgesloten.', 'eme-accounting' ), 'result' => $label ] );
    }

    public static function ajax_reopen(): void {
        check_ajax_referer( 'eme_accounting_nonce', 'nonce' );
        Capabilities::require_manage_ajax( 'fiscalyears' );

        $year = (int) eme_sanitize_request( $_POST['year'] ?? 0 ); //phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        if ( ! $year ) {
            wp_send_json_error( __( 'Geen boekjaar geselecteerd.', 'eme-accounting' ) );
        }

        $result = self::reopen( $year );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }

        wp_send_json_success( [ 'message' => __( 'Boekjaar heropend.', 'eme-accounting' ) ] );
    }

    public static function ajax_delete(): void {
        check_ajax_referer( 'eme_accounting_nonce', 'nonce' );
        Capabilities::require_manage_ajax( 'fiscalyears' );

        $year = (int) eme_sanitize_request( $_POST['year'] ?? 0 ); //phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        if ( ! $year ) {
            wp_send_json_error( __( 'Geen boekjaar geselecteerd.', 'eme-accounting' ) );
        }

        $result = self::delete( $year );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }

        wp_send_json_success( [ 'message' => __( 'Boekjaar verwijderd.', 'eme-accounting' ) ] );
    }

    public static function ajax_check(): void {
        check_ajax_referer( 'eme_accounting_nonce', 'nonce' );
        Capabilities::require_view_ajax( 'fiscalyears' );
        $date = eme_sanitize_request( $_POST['date'] ?? '' ); //phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        if ( empty( $date ) ) {
            wp_send_json_error( __( 'Geen datum opgegeven.', 'eme-accounting' ) );
        }

        //$fy = self::get_or_create_for_date( $date );
        $fy = self::get_for_date( $date );
        if ( ! $fy ) {
            wp_send_json_error( __( 'Geen boekjaar gevonden.', 'eme-accounting' ) );
        }

        wp_send_json_success( [
            'year'       => (int) $fy->year,
            'start_date' => $fy->start_date,
            'end_date'   => $fy->end_date,
            'status'     => $fy->status,
        ] );
    }

    public static function get_closed_years(): array {
        global $wpdb;
        $rows = $wpdb->get_col(
            "SELECT year FROM `{$wpdb->prefix}emeacc_fiscal_years` WHERE status = 'closed' ORDER BY year DESC"
        );
        return array_map( 'intval', $rows );
    }

    public static function get_available_years(): array {
        global $wpdb;
        $years = $wpdb->get_col(
            "SELECT DISTINCT fiscal_year FROM `{$wpdb->prefix}emeacc_journal` ORDER BY fiscal_year DESC"
        );
        if ( empty( $years ) ) {
            $years = [ (int) gmdate( 'Y' ) ];
        }
        return $years;
    }

    public static function get_current_year(): int {
        return (int) gmdate( 'Y' );
    }

    public static function ensure_current_year_exists(): void {
        global $wpdb;
        $current = (int) gmdate( 'Y' );
        $exists = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM `{$wpdb->prefix}emeacc_fiscal_years` WHERE year = %d",
                $current
            )
        );

        if ( $exists == 0 ) {
            $dates = self::calculate_dates( $current );
            $wpdb->insert(
                $wpdb->prefix . 'emeacc_fiscal_years',
                [
                    'year'       => $current,
                    'start_date' => $dates['start_date'],
                    'end_date'   => $dates['end_date'],
                    'status'     => 'open',
                ]
            );
        }
    }
}
