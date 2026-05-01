<?php
namespace EMEAccounting;

defined( 'ABSPATH' ) || exit;

class Assets {
    public static function init(): void {
        add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue' ] );
    }

    public static function enqueue( string $hook ): void {
        global $plugin_page;
        if (empty($plugin_page)) {
            return;
        }
        if ( strpos( $plugin_page, 'emeaccounting' ) === false && strpos( $plugin_page, 'eme_accounting' ) === false ) {
            return;
        }

        $config =  \EMEVzw\Config::get();

        wp_enqueue_script( 'emeaccounting-select', EME_PLUGIN_URL . 'js/snapselect/snapselect.min.js', [], EME_VERSION );
        wp_enqueue_style('emeaccounting-select-css', EME_PLUGIN_URL . 'js/snapselect/snapselect.min.css' );
        wp_enqueue_script( 'emeaccounting-sortable', EME_PLUGIN_URL . 'js/sortable/sortable.min.js', [ ], EME_VERSION, true );
        wp_enqueue_style( 'emeaccounting-fdatepicker', EME_PLUGIN_URL . 'js/fdatepicker/css/fdatepicker.min.css', [], EME_VERSION );
        wp_enqueue_script( 'emeaccounting-fdatepicker', EME_PLUGIN_URL . 'js/fdatepicker/js/fdatepicker.min.js', [ ], EME_VERSION );

        wp_enqueue_style( 'emeaccounting-ftable-css', EME_PLUGIN_URL . 'js/ftable/themes/lightcolor/gray/ftable.min.css' );
        wp_enqueue_style( 'emeaccounting-ftables-css', EME_PLUGIN_URL . 'css/ftables.css', [], EME_VERSION );
        wp_enqueue_script( 'emeaccounting-ftable', EME_PLUGIN_URL . 'js/ftable/ftable.min.js', [ ], EME_VERSION );
        $locale_code     = determine_locale();
        $locale_code     = str_replace( '_', '-', $locale_code );
        // for english, no translation code is needed)
        if ( $locale_code != 'en-US' ) {
            $locale_code     = substr( $locale_code, 0, 2 );
            $locale_file     = EME_PLUGIN_DIR . "js/ftable/localization/ftable.$locale_code.js";
            if ( file_exists( $locale_file ) ) {
                $locale_file_url = EME_PLUGIN_URL . "js/ftable/localization/ftable.$locale_code.js";
                wp_enqueue_script( 'emeaccounting-ftable-locale', $locale_file_url, '', EME_VERSION );
            }
            $locale_file     = EME_PLUGIN_DIR . "js/fdatepicker/js/i18n/fdatepicker.$locale_code.js";
            if ( file_exists( $locale_file ) ) {
                $locale_file_url    = EME_PLUGIN_URL . "js/fdatepicker/js/i18n/fdatepicker.$locale_code.js";
                wp_enqueue_script( 'emeaccounting-fdatepick-locale', $locale_file_url, '', EME_VERSION );
            }
        }

        wp_enqueue_style( 'emeaccounting', EME_ACCOUNTING_URL . 'assets/css/admin.css', [], EME_ACCOUNTING_VERSION );
        wp_enqueue_script( 'emeaccounting', EME_ACCOUNTING_URL . 'assets/js/admin.js', [ 'emeaccounting-sortable' ], EME_ACCOUNTING_VERSION, true );
        wp_localize_script( 'emeaccounting', 'emeAccounting', [
            'nonce'   => wp_create_nonce( 'eme_accounting_nonce' ),
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'i18n'    => [
                'confirm_delete' => __( 'Zeker verwijderen?', 'eme-accounting' ),
                'confirm_delete_patrimony' => __( 'Staat van Vermogen verwijderen?', 'eme-vzw-helper' ),
                'confirm_delete_budget'    => __( 'Begroting van dit boekjaar verwijderen?', 'eme-accounting' ),
                'save'           => __( 'Opslaan', 'eme-accounting' ),
                'update'         => __( 'Bijwerken', 'eme-accounting' ),
                'saving'         => __( 'Opslaan…', 'eme-accounting' ),
                'loaded'         => __( 'Opgeladen', 'eme-accounting' ),
                'saved'          => __( 'Opgeslagen', 'eme-accounting' ),
                'date'           => __( 'Datum', 'eme-accounting' ),
                'description'    => __( 'Omschrijving', 'eme-accounting' ),
                'category'       => __( 'Categorie', 'eme-accounting' ),
                'files'          => __( 'Bijlagen', 'eme-accounting' ),
                'bankref'        => __( 'Bankref.', 'eme-accounting' ),
                'account'        => __( 'Rekening', 'eme-accounting' ),
                'amount'         => __( 'Bedrag', 'eme-accounting' ),
                'currency'       => $config->currency,
                'reconciled'     => __( 'Vereffend', 'eme-accounting' ),
                'fyClosed'       => __( 'GESLOTEN', 'eme-accounting' ),
                'noFyFound'      => __( 'Geen boekjaar gevonden', 'eme-accounting' ),
                'processing'     => __( 'Bezig…', 'eme-accounting' ),
                'error'          => __( 'Er is een fout opgetreden.', 'eme-accounting' ),
                'confirmClose'   => __( 'Boekjaar %s afsluiten? Dit kan niet ongedaan gemaakt worden.', 'eme-accounting' ),
                'confirmReopen'  => __( 'Boekjaar %s heropenen? De afsluitboekingen worden verwijderd.', 'eme-accounting' ),
                'confirmDelete'   => __( 'Boekjaar %s verwijderen?', 'eme-accounting' ),
                'translate_fdateformat'        => get_option( 'date_format' ),
                'translate_fdatetimeformat'    => get_option( 'date_format' ) . ' ' . get_option( 'time_format' ),
            ],
        ] );
        if ( $plugin_page === 'emeaccounting-journal' ) {
            wp_enqueue_script( 'emeaccounting-journal', EME_ACCOUNTING_URL . 'assets/js/journal.js', [ 'emeaccounting' ], EME_ACCOUNTING_VERSION, true );
        }
        if ( $plugin_page === 'emeaccounting-import' ) {
            $coa_accounts = ChartOfAccounts::get_dropdown();
            // Build COA option arrays for ftable (direction-filtered, category headers excluded)
            $coa_in_select = [];
            $coa_out_select = [];
            $coa_in_select[] = "<optgroup label='".esc_html__( 'Ontvangsten', 'eme-accounting' )."'>";
            $coa_out_select[] = "<optgroup label='".esc_html__( 'Uitgaven', 'eme-accounting' )."'>";
            foreach ( $coa_accounts as $id => $acc ) {
                //if ( ! $acc || empty( $acc->parent_id ) ) continue;
                $disabled = '';
                if (empty( $acc->parent_id )) $disabled='disabled';
                if ( $acc->direction === 'in' )  {
                    $coa_in_select[] = "<option value='$id' $disabled>". esc_html__( $acc->code . ' - ' . $acc->name )."</option>";
                }
                if ( $acc->direction === 'out' )  {
                    $coa_out_select[] = "<option value='$id' $disabled>". esc_html__( $acc->code . ' - ' . $acc->name )."</option>";
                }
            }
            $coa_in_select[] = "</optgroup>";
            $coa_out_select[] = "</optgroup>";
            wp_enqueue_script( 'emeaccounting-import', EME_ACCOUNTING_URL . 'assets/js/import.js', [ 'emeaccounting' ], EME_ACCOUNTING_VERSION, true );
            wp_localize_script( 'emeaccounting-import', 'emeAccountingImport', [
                'coaSelectIn'  => $coa_in_select,
                'coaSelectOut' => $coa_out_select
            ] );
        }

        if ( $plugin_page === 'emeaccounting-fiscal-years' ) {
            $current_year = (int) gmdate( 'Y' );
            $dates = FiscalYear::calculate_dates($current_year);
            wp_enqueue_script( 'emeaccounting-fiscalyears', EME_ACCOUNTING_URL . 'assets/js/fiscalyears.js', [ 'emeaccounting' ], EME_ACCOUNTING_VERSION, true );
            /*wp_localize_script( 'emeaccounting-fiscalyears', 'emeAccountingFy', [
                'currentYear'       => $current_year,
                'defaultStartDate'  => $dates['start_date'],
                'defaultEndDate'    => $dates['end_date']
            ] );*/
        }
    }
}
