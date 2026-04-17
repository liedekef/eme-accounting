<?php
namespace EMEAccounting;

defined( 'ABSPATH' ) || exit;

class Assets {
    public static function init(): void {
        add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue' ] );
    }

    public static function enqueue( string $hook ): void {
        global $plugin_page;
        if ( strpos( $plugin_page, 'eme-accounting' ) === false && strpos( $plugin_page, 'eme_accounting' ) === false ) {
            return;
        }
        wp_enqueue_style( 'eme-accounting', EME_ACCOUNTING_URL . 'assets/css/admin.css', [], EME_ACCOUNTING_VERSION );
        wp_enqueue_script( 'eme-accounting-sortable', EME_PLUGIN_URL . 'js/sortable/sortable.min.js', [ ], EME_VERSION, true );
        wp_enqueue_script( 'eme-accounting', EME_ACCOUNTING_URL . 'assets/js/admin.js', [ 'eme-accounting-sortable' ], EME_ACCOUNTING_VERSION, true );
        wp_localize_script( 'eme-accounting', 'emeAccounting', [
            'nonce'   => wp_create_nonce( 'eme_accounting_nonce' ),
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'i18n'    => [
                'confirm_delete' => __( 'Zeker verwijderen?', 'eme-accounting' ),
                'saving'         => __( 'Opslaan…', 'eme-accounting' ),
                'loaded'         => __( 'Opgeladen', 'eme-accounting' ),
                'saved'          => __( 'Opgeslagen', 'eme-accounting' ),
                'date'           => __( 'Datum', 'eme-accounting' ),
                'description'    => __( 'Omschrijving', 'eme-accounting' ),
                'category'       => __( 'Categorie', 'eme-accounting' ),
                'bankref'        => __( 'Bankref.', 'eme-accounting' ),
                'account'         => __( 'Rekening', 'eme-accounting' ),
                'amount'         => __( 'Bedrag', 'eme-accounting' ),
                'reconciled'     => __( 'Vereffend', 'eme-accounting' ),
                'fyClosed'       => __( 'GESLOTEN', 'eme-accounting' ),
                'noFyFound'      => __( 'Geen boekjaar gevonden', 'eme-accounting' ),
                'save'           => __( 'Opslaan', 'eme-accounting' ),
                'update'         => __( 'Bijwerken', 'eme-accounting' ),
                'processing'     => __( 'Bezig…', 'eme-accounting' ),
                'error'          => __( 'Er is een fout opgetreden.', 'eme-accounting' ),
                'confirmClose'   => __( 'Boekjaar %s afsluiten? Dit kan niet ongedaan gemaakt worden.', 'eme-accounting' ),
                'confirmReopen'  => __( 'Boekjaar %s heropenen? De afsluitboekingen worden verwijderd.', 'eme-accounting' ),
                'confirmDelete'   => __( 'Boekjaar %s verwijderen?', 'eme-accounting' ),
            ],
        ] );
        wp_enqueue_script( 'eme-ftable', EME_PLUGIN_URL . 'js/ftable/ftable.min.js', [ ], EME_VERSION );
        $locale_code     = determine_locale();
        $locale_code     = str_replace( '_', '-', $locale_code );
        // for english, no translation code is needed)
        if ( $locale_code != 'en-US' ) {
            $locale_code     = substr( $locale_code, 0, 2 );
            $locale_file     = EME_PLUGIN_DIR . "js/ftable/localization/ftable.$locale_code.js";
            if ( file_exists( $locale_file ) ) {
                $locale_file_url = EME_PLUGIN_URL . "js/ftable/localization/ftable.$locale_code.js";
                wp_enqueue_script( 'eme-ftable-locale', $locale_file_url, '', EME_VERSION );
            }
        }
        wp_enqueue_style( 'eme-ftable-css', EME_PLUGIN_URL . 'js/ftable/themes/lightcolor/gray/ftable.min.css' );
        wp_enqueue_style( 'eme-ftables-css', EME_PLUGIN_URL . 'css/ftables.css', [], EME_VERSION );

        if ( $plugin_page === 'eme-accounting-journal' ) {
            wp_enqueue_script( 'eme-accounting-journal', EME_ACCOUNTING_URL . 'assets/js/journal.js', [ 'eme-accounting' ], EME_ACCOUNTING_VERSION, true );
        }

        if ( $plugin_page === 'eme-accounting-fiscal-years' ) {
            $fy_settings = get_option( 'eme_accounting_settings', [] );
            $current_year = (int) gmdate( 'Y' );
            $start_month = (int) ( $fy_settings['fiscal_year_start_month'] ?? 1 );
            $start_day   = (int) ( $fy_settings['fiscal_year_start_day'] ?? 1 );
            $end_month   = (int) ( $fy_settings['fiscal_year_end_month'] ?? 12 );
            $end_day     = (int) ( $fy_settings['fiscal_year_end_day'] ?? 31 );

            wp_enqueue_script( 'eme-accounting-fiscalyears', EME_ACCOUNTING_URL . 'assets/js/fiscalyears.js', [ 'eme-accounting' ], EME_ACCOUNTING_VERSION, true );
            wp_localize_script( 'eme-accounting-fiscalyears', 'emeAccountingFy', [
                'currentYear'       => $current_year,
                'defaultStartDate'  => sprintf( '%04d-%02d-%02d', $current_year, $start_month, min( $start_day, cal_days_in_month( CAL_GREGORIAN, $start_month, $current_year ) ) ),
                'defaultEndDate'    => sprintf( '%04d-%02d-%02d', $current_year, $end_month, min( $end_day, cal_days_in_month( CAL_GREGORIAN, $end_month, $current_year ) ) ),
            ] );
        }
    }
}
