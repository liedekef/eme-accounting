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
        wp_enqueue_script( 'eme-accounting-sortable', EME_ACCOUNTING_URL . 'assets/js/sortable/sortable.min.js', [ 'jquery' ], EME_ACCOUNTING_VERSION, true );
        wp_enqueue_script( 'eme-accounting', EME_ACCOUNTING_URL . 'assets/js/admin.js', [ 'jquery', 'eme-accounting-sortable' ], EME_ACCOUNTING_VERSION, true );
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
