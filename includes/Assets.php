<?php
namespace EMEAccounting;

defined( 'ABSPATH' ) || exit;

class Assets {
    public static function init(): void {
        add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue' ] );
    }

    public static function enqueue( string $hook ): void {
        if ( strpos( $hook, 'eme-accounting' ) === false && strpos( $hook, 'eme_accounting' ) === false ) {
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
    }
}
