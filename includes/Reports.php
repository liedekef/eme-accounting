<?php
// phpcs:ignoreFile WordPress.DB.DirectDatabaseQuery.DirectQuery WordPress.DB.DirectDatabaseQuery.NoCaching
namespace EMEAccounting;

defined( 'ABSPATH' ) || exit;

use EMEAccounting\Capabilities;

class Reports {
    public static function init(): void {
        add_action( 'wp_ajax_eme_accounting_export_report', [ __CLASS__, 'ajax_export' ] );
    }

    /**
     * Staat van Ontvangsten en Uitgaven (income & expenditure statement).
     *
     * Alle actieve hoofdrekeningen (parent_id IS NULL) worden getoond, ook
     * wanneer er voor het gevraagde jaar geen boekingen zijn (bedrag = 0).
     * Boekingen op kindrekeningen worden opgerold naar hun ouder;
     * boekingen rechtstreeks op een hoofdrekening tellen gewoon mee.
     * Boekingen zonder rekeningstelsel (coa_id IS NULL) worden apart getoond.
     *
     * @return array{fiscal_year: int, income: array, expenses: array, totals: array}
     */
    public static function income_expenditure_report( int $year ): array {
        global $wpdb;

        // ── Step 1: all active main accounts, pre-seeded at 0.00 ─────────
        $main_accounts = $wpdb->get_results( //phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter
            "SELECT id, code, name, direction
             FROM `{$wpdb->prefix}emeacc_chart_of_accounts`
             WHERE parent_id IS NULL AND is_active = 1
             ORDER BY direction DESC, code"
        );

        $income   = [];
        $expenses = [];
        foreach ( $main_accounts as $acc ) {
            $label = $acc->code . ' - ' . $acc->name;
            if ( $acc->direction === 'in' ) {
                $income[ $label ] = 0.0;
            } else {
                $expenses[ $label ] = 0.0;
            }
        }

        // ── Step 2: actual totals from the journal for this year ──────────
        // COALESCE(parent.id, coa.id) resolves the main account regardless of
        // whether the booking was made on a child or directly on the main account.
        $rows = $wpdb->get_results( //phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter
            $wpdb->prepare(
                "SELECT j.direction,
                        COALESCE(parent.id,   coa.id)   AS main_id,
                        COALESCE(parent.code, coa.code) AS main_code,
                        COALESCE(parent.name, coa.name) AS main_name,
                        COALESCE(SUM(j.amount), 0)      AS total
                 FROM `{$wpdb->prefix}emeacc_journal` j
                 LEFT JOIN `{$wpdb->prefix}emeacc_chart_of_accounts` coa    ON coa.id    = j.coa_id
                 LEFT JOIN `{$wpdb->prefix}emeacc_chart_of_accounts` parent ON parent.id = coa.parent_id
                 WHERE j.fiscal_year = %d
                   AND transfer_id IS NULL
                   AND j.is_closing_entry = 0
                 GROUP BY j.direction, main_id
                 ORDER BY j.direction DESC, main_code",
                $year
            )
        );

        // ── Step 3: overlay totals onto the pre-seeded arrays ────────────
        foreach ( $rows as $row ) {
            $label = $row->main_code && $row->main_name
                ? $row->main_code . ' - ' . $row->main_name
                : __( '(zonder rekeningstelsel)', 'eme-accounting' );
            if ( $row->direction === 'in' ) {
                $income[ $label ] = ( $income[ $label ] ?? 0.0 ) + (float) $row->total;
            } else {
                $expenses[ $label ] = ( $expenses[ $label ] ?? 0.0 ) + (float) $row->total;
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
    public static function patrimony_report( string $date = '' ): array {
        global $wpdb;
        if ( ! $date ) $date = current_time( 'Y-m-d' );

        // Financial accounts (all treated as assets for VZW)
        $accounts = Accounts::get_all();
        $assets   = [];
        $liabs    = [];

        // Use grouped SUM query for date-precise balances
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT j.account_id,
                        COALESCE(SUM(CASE WHEN j.direction = 'in' THEN j.amount WHEN j.direction = 'out' THEN -j.amount ELSE 0 END), 0) AS balance
                 FROM `{$wpdb->prefix}emeacc_journal` j
                 WHERE j.entry_date <= %s
                 GROUP BY j.account_id",
                $date
            )
        );
        $balance_map = [];
        foreach ( $rows as $r ) {
            $balance_map[ (int) $r->account_id ] = (float) $r->balance;
        }
        foreach ( $accounts as $acc ) {
            $balance = (float) $acc->opening_balance + ( $balance_map[ (int) $acc->id ] ?? 0 );
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
    public static function financial_diary_report( int $year ): array {
        $entries = Journal::get_entries( [ 'fiscal_year' => $year ], "entry_date, id");
        $grouped = [];
        foreach ( $entries as $entry ) {
            $entry->amount = $entry->direction === 'out' && $entry->amount > 0
                ? -1 * (float) $entry->amount
                : (float) $entry->amount;
            $grouped[ $entry->entry_date ][] = $entry;
        }
        return $grouped;
    }

    /**
     * Events report: all journal entries linked to EME events.
     *
     * @param  int      $year        Fiscal year
     * @param  int|null $event_id    Optional event ID for drilldown
     * @return array{summary: array<int,array{event_id:int,event_name:string,event_start:?string,event_end:?string,total_in:float,total_out:float}}, details: array<int,object>}
     */
    public static function events_report( int $year, ?int $event_id = null ): array {
        global $wpdb;

        // Drilldown: get all entries for a specific event
        if ( $event_id ) {
            $entries = Journal::get_entries( [ 'fiscal_year' => $year, 'eme_event_id' => $event_id ], "entry_date, id");
            foreach ( $entries as &$entry ) {
                $entry->amount = $entry->direction === 'out' && $entry->amount>0
                    ? -1 * (float) $entry->amount
                    : (float) $entry->amount;
            }
            unset($entry);
            $event = eme_get_event($event_id);
            $event['event_name'] = eme_translate( $event['event_name'] );
            $event['event_start'] = eme_localized_datetime($event['event_start']);
            $event['event_end'] = eme_localized_datetime($event['event_end']);
            return [ 'info' => $event, 'details' => $entries ];
        }

        // Overview: group by event_id
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT j.eme_event_id,
                        SUM(CASE WHEN j.direction = 'in'  THEN j.amount ELSE 0 END) AS total_in,
                        SUM(CASE WHEN j.direction = 'out' THEN j.amount ELSE 0 END) AS total_out
                 FROM `{$wpdb->prefix}emeacc_journal` j
                 WHERE j.fiscal_year = %d
                   AND j.eme_event_id IS NOT NULL
                   AND j.is_closing_entry = 0
                 GROUP BY j.eme_event_id",
                $year
            )
        );

        $summary = [];

        foreach ( $rows as $row ) {
            $event = eme_get_event( (int) $row->eme_event_id );
            $summary[ (int) $row->eme_event_id ] = [
                'event_id'    => (int) $row->eme_event_id,
                'event_name'  => $event ? eme_translate($event['event_name']) : __( '(onbekend event)', 'eme-accounting' ),
                'event_start' => $event ? eme_localized_datetime($event['event_start']) : null,
                'event_end'   => $event ? eme_localized_datetime($event['event_end']) : null,
                'total_in'    => (float) $row->total_in,
                'total_out'   => $row->total_out >0 ? -1 * (float) $row->total_out : (float) $row->total_out,
                'real_start'  => $event ? $event['event_start'] : null
            ];
        }

        // sort on original start date
        usort($summary, function($a, $b) {
            return strcmp($a['real_start'], $b['real_start']);
        });

        return [ 'summary' => $summary ];
    }

    /**
     * Financial year report: breakdown per COA account (including child accounts).
     *
     * @param int  $year        Fiscal year
     * @return array{fiscal_year: int, accounts: array}
     */
    public static function financial_year_report( int $year ): array {
        global $wpdb;

        // Get all active COA accounts (main + children)
        $accounts = $wpdb->get_results( //phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter
            "SELECT id, code, name, parent_id, direction
             FROM `{$wpdb->prefix}emeacc_chart_of_accounts`
             WHERE is_active = 1
             ORDER BY direction DESC, code"
        );

        $totals_by_coa = [];
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT j.coa_id, COALESCE(SUM(j.amount), 0) AS total
                 FROM `{$wpdb->prefix}emeacc_journal` j
                 WHERE j.fiscal_year = %d
                   AND j.is_closing_entry = 0
                 GROUP BY j.coa_id",
                $year
            )
        );
        foreach ( $rows as $r ) {
            $totals_by_coa[ (int) $r->coa_id ] = (float) $r->total;
        }

        $result = [];
        $parents = [];
        $children = [];

        foreach ( $accounts as $acc ) {
            $total = $totals_by_coa[ (int) $acc->id ] ?? 0.0;
            if ( $acc->parent_id ) {
                $children[] = [
                    'id'         => (int) $acc->id,
                    'code'       => $acc->code,
                    'name'       => $acc->name,
                    'parent_id' => (int) $acc->parent_id,
                    'direction' => $acc->direction,
                    'total'     => $total,
                ];
                if ( isset( $parents[ (int) $acc->parent_id ] ) ) {
                    $parents[ (int) $acc->parent_id ] += $total;
                } else {
                    $parents[ (int) $acc->parent_id ] = $total;
                }
            } else {
                $parents[ (int) $acc->id ] = 0.0;
                $result[] = [
                    'id'         => (int) $acc->id,
                    'code'       => $acc->code,
                    'name'       => $acc->name,
                    'parent_id' => null,
                    'direction' => $acc->direction,
                    'total'     => 0.0,
                    'children'  => [],
                ];
            }
        }

        foreach ( $children as $child ) {
            foreach ( $result as &$parent ) {
                if ( $parent['id'] === $child['parent_id'] ) {
                    $parent['children'][] = $child;
                    $parent['total'] += $child['total'];
                    break;
                }
            }
        }

        usort( $result, function( $a, $b ) {
            return strcmp( $a['direction'], $b['direction'] );
        } );

        return [
            'fiscal_year'        => $year,
            'accounts'         => $result,
        ];
    }


    /**
     * BTW-aangifte rapport: per kwartaal de BTW-bedragen uit de journal entries.
     *
     * @param  int $year  Fiscal year
     * @return array{fiscal_year: int, quarters: array}
     */
    public static function vat_report( int $year ): array {
        global $wpdb;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT direction,
                        QUARTER(entry_date)      AS quarter,
                        COALESCE(SUM(vat_amount), 0) AS total_vat
                 FROM `{$wpdb->prefix}emeacc_journal`
                 WHERE fiscal_year = %d
                   AND vat_amount > 0
                   AND vat_code != ''
                   AND is_closing_entry = 0
                 GROUP BY direction, QUARTER(entry_date)
                 ORDER BY quarter",
                $year
            )
        );

        // Pre-seed all 4 quarters with 0
        $quarters = [];
        for ( $q = 1; $q <= 4; $q++ ) {
            $quarters[ $q ] = [
                'quarter'     => $q,
                'vat_in'      => 0.0,  // BTW op ontvangsten (te betalen)
                'vat_out'     => 0.0,  // BTW op uitgaven (aftrekbaar)
                'balance'     => 0.0,  // te betalen - aftrekbaar
            ];
        }

        foreach ( $rows as $row ) {
            $q = (int) $row->quarter;
            if ( $row->direction === 'in' ) {
                $quarters[ $q ]['vat_in'] += (float) $row->total_vat;
            } else {
                $quarters[ $q ]['vat_out'] += (float) $row->total_vat;
            }
        }

        // Calculate balance per quarter
        foreach ( $quarters as $q => $data ) {
            $quarters[ $q ]['balance'] = $data['vat_in'] - $data['vat_out'];
        }

        return [
            'fiscal_year' => $year,
            'quarters'    => $quarters,
            'totals'      => [
                'vat_in'  => array_sum( array_column( $quarters, 'vat_in' ) ),
                'vat_out' => array_sum( array_column( $quarters, 'vat_out' ) ),
                'balance' => array_sum( array_column( $quarters, 'balance' ) ),
            ],
        ];
    }

    /**
     * Cashflow rapport: maandelijkse in/out/saldo per rekening voor een boekjaar.
     *
     * @return array{fiscal_year: int, accounts: array, months: array, totals: array}
     */
    public static function cashflow_report( int $year, bool $exclude_transfers = false ): array {
        global $wpdb;

        $extra = $exclude_transfers ? 'AND j.transfer_id IS NULL' : '';

        $rows = $wpdb->get_results( //phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter
            $wpdb->prepare(
                "SELECT a.id        AS account_id,
                        a.name      AS account_name,
                        MONTH(j.entry_date)                 AS month,
                        SUM(CASE WHEN j.direction = 'in'  THEN j.amount ELSE 0 END) AS total_in,
                        SUM(CASE WHEN j.direction = 'out' THEN j.amount ELSE 0 END) AS total_out
                 FROM `{$wpdb->prefix}emeacc_accounts` a
                 LEFT JOIN `{$wpdb->prefix}emeacc_journal` j
                        ON j.account_id = a.id
                       AND j.fiscal_year = %d
                       AND j.is_closing_entry = 0
                       {$extra} //phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                 WHERE a.is_active = 1
                 GROUP BY a.id, MONTH(j.entry_date)
                 ORDER BY a.id, month",
                $year
            )
        );

        // Pre-seed structure: all 12 months × all accounts
        $accounts = [];
        $data     = [];
        foreach ( $rows as $row ) {
            $aid = (int) $row->account_id;
            if ( ! isset( $accounts[ $aid ] ) ) {
                $accounts[ $aid ] = $row->account_name;
                for ( $m = 1; $m <= 12; $m++ ) {
                    $data[ $aid ][ $m ] = [ 'in' => 0.0, 'out' => 0.0, 'net' => 0.0 ];
                }
            }
            $m   = (int) $row->month;
            $in  = (float) $row->total_in;
            $out = (float) $row->total_out;
            $data[ $aid ][ $m ] = [ 'in' => $in, 'out' => $out, 'net' => $in - $out ];
        }

        // Grand totals per month (across all accounts)
        $month_totals = [];
        for ( $m = 1; $m <= 12; $m++ ) {
            $t_in = $t_out = 0.0;
            foreach ( $data as $aid => $months ) {
                $t_in  += $months[ $m ]['in'];
                $t_out += $months[ $m ]['out'];
            }
            $month_totals[ $m ] = [ 'in' => $t_in, 'out' => $t_out, 'net' => $t_in - $t_out ];
        }

        return [
            'fiscal_year'  => $year,
            'accounts'     => $accounts,
            'data'         => $data,
            'month_totals' => $month_totals,
            'totals'       => [
                'in'  => array_sum( array_column( $month_totals, 'in' ) ),
                'out' => array_sum( array_column( $month_totals, 'out' ) ),
                'net' => array_sum( array_column( $month_totals, 'net' ) ),
            ],
        ];
    }

    public static function ajax_export(): void {
        check_ajax_referer( 'eme_accounting_nonce', 'nonce' );
        Capabilities::require_view_ajax( 'reports' );

        $type              = eme_sanitize_request( $_GET['type'] ?? 'financial_diary' ); //phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        $year              = (int) eme_sanitize_request( $_GET['year'] ?? gmdate( 'Y' ) ); //phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        $event_id          = (int) eme_sanitize_request( $_GET['event_id'] ?? 0 ) ?: null; //phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        $format            = eme_sanitize_request( $_GET['format'] ?? 'csv' ); //phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        $exclude_transfers = eme_sanitize_request( $_GET['exclude_transfers'] ?? '' ) === '1'; //phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        $date              = eme_sanitize_request( $_GET['date'] ?? '' ); //phpcs:ignore WordPress.Security.ValidatedSanitizedInput

        $data = match ( $type ) {
            'income_expenditure' => self::income_expenditure_report( $year ),
            'patrimony'          => self::patrimony_report( $date ),
            'events'             => self::events_report( $year, $event_id ),
            'financial_year'     => self::financial_year_report( $year ),
            'vat_report'         => self::vat_report( $year ),
            'cashflow'           => self::cashflow_report( $year, $exclude_transfers ),
            default              => self::financial_diary_report( $year ),
        };

        if ( $format === 'csv' ) {
            self::output_csv( $type, $data, $year );
        } elseif ( $format === 'pdf') {
            self::ajax_download_pdf( $type, $data, $year );
        } else {
            wp_send_json_success( $data );
        }
    }

    private static function output_csv( string $type, array $data, int $year ): void {
        $name = match ( $type ) {
            'income_expenditure' => 'StaatVanOntvangstenUitgaven',
            'financial_diary'    => 'FinancieelDagboek',
            'financial_year'     => 'FinancieelJaarverslag',
            'cashflow'           => 'Cashflow',
            default              => ucfirst( $type ),
        };
        header( 'Content-Type: text/csv; charset=UTF-8' );
        header( 'Content-Disposition: attachment; filename="' . $name . '-' . $year . '.csv"' );
        echo "\xEF\xBB\xBF"; // BOM for Excel
        $out = fopen( 'php://output', 'w' );
        if ( $type === 'financial_diary' ) {
            self::array_to_csv_diary( $out, $data );
        } else {
            self::array_to_csv( $out, $data );
        }
        fclose( $out );
        exit;
    }

    /**
     * Neutralize spreadsheet formula injection for text cells.
     * Cells starting with = + - @ are interpreted as formulas by
     * Excel/LibreOffice; prefixing them with a single quote prevents that.
     */
    private static function csv_safe( $value ) {
        if ( ! is_string( $value ) || $value === '' ) {
            return $value;
        }
        $chars = [ '=', '+', '-', '@' ];
        if ( ! in_array( $value[0], $chars, true ) ) {
            return $value;
        }
        // Never break legitimate negative numbers ("-1.234,56").
        if ( is_numeric( $value ) ) {
            return $value;
        }
        return "'" . $value;
    }

    /**
     * Dedicated CSV export for the financial diary:
     * one row per entry with explicit column headers, no _id fields.
     */
    private static function array_to_csv_diary( $handle, array $data ): void {
        fputcsv( $handle, [ 'datum', 'omschrijving', 'rekening', 'bankref', 'tegenpartij', 'categorie', 'richting', 'bedrag' ], ';', escape: "" );
        foreach ( $data as $date => $entries ) {
            foreach ( $entries as $entry ) {
                fputcsv( $handle, [
                    $date,
                    self::csv_safe( $entry->description       ?? '' ),
                    self::csv_safe( $entry->account_name      ?? '' ),
                    self::csv_safe( $entry->bank_statement_ref ?? '' ),
                    self::csv_safe( $entry->counterparty_name  ?? '' ),
                    self::csv_safe( $entry->coa_name           ?? '' ),
                    self::csv_safe( $entry->direction          ?? '' ),
                    number_format( (float) $entry->amount, 2, ',', '' ),
                ], ';', escape: "" );
            }
        }
    }

    private static function array_to_csv( $handle, array $data, int $depth = 0 ): void {
        foreach ( $data as $key => $value ) {
            if ( is_array( $value ) ) {
                fputcsv( $handle, [ $key ], ';', escape: "" );
                self::array_to_csv( $handle, $value, $depth + 1 );
            } elseif ( is_object( $value ) ) {
                fputcsv( $handle, array_map( [ __CLASS__, 'csv_safe' ], (array) $value ), ';', escape: "" );
            } else {
                fputcsv( $handle, [ $key, self::csv_safe( $value ) ], ';', escape: "" );
            }
        }
    }

    private static function generate_pdf_html( string $template, array $data, int $year ): string {
        $settings = get_option( 'eme_accounting_settings', [] );
        $config = \EMEVzw\Config::get();
        if ( ! $config ) return '';
        ob_start();
        include EME_ACCOUNTING_DIR . 'templates/pdf/' . $template .'.php';
        return ob_get_clean();
    }

    private static function ajax_download_pdf( string $type, array $data, int $year ): void {
        $html = self::generate_pdf_html( $type, $data, $year );
        if ( empty( $html ) ) {
            wp_send_json_error();
        }
        $name        = "report";
        $orientation = "portrait";
        switch ( $type ) {
            case 'income_expenditure':
                $name = 'StaatVanOntvangstenUitgaven';
                break;
            case 'financial_diary':
                $name = 'FinancieelDagboek';
                break;
            case 'financial_year':
                $name = 'FinancieelJaarverslag';
                break;
            case 'cashflow':
                $name = 'Cashflow';
                $orientation = 'landscape';
                break;
            default:
                $name = ucfirst($type);
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
        $dompdf->setPaper( 'A4', $orientation );
        $dompdf->render();
        $dompdf->stream( $name . '-' . $year . '.pdf', [ 'Attachment' => true ] );
        exit;
    }
}
