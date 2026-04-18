<?php
namespace EMEAccounting\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * WordPress admin menu & submenu registration for EME Accounting.
 */
class Menu {

    public static function init(): void {
        add_action( 'admin_menu', [ __CLASS__, 'register' ] );
    }

    public static function register(): void {
        add_menu_page(
            __( 'EME Boekhouding', 'eme-accounting' ),
            __( 'EME Boekhouding', 'eme-accounting' ),
            'manage_options',
            'emeaccounting',
            [ __CLASS__, 'page_dashboard' ],
            'dashicons-money-alt',
            56
        );

        $subpages = [
            [ 'emeaccounting',             __( 'Dashboard', 'eme-accounting' ),              [ __CLASS__, 'page_dashboard' ] ],
            [ 'emeaccounting-accounts',    __( 'Rekeningen', 'eme-accounting' ),             [ __CLASS__, 'page_accounts' ] ],
            [ 'emeaccounting-journal',     __( 'Dagboek', 'eme-accounting' ),                [ __CLASS__, 'page_journal' ] ],
            [ 'emeaccounting-import',      __( 'Import / Reconciliatie', 'eme-accounting' ), [ __CLASS__, 'page_import' ] ],
            [ 'emeaccounting-stock',       __( 'Stock', 'eme-accounting' ),                  [ __CLASS__, 'page_stock' ] ],
            [ 'emeaccounting-depreciation',__( 'Afschrijvingen', 'eme-accounting' ),         [ __CLASS__, 'page_depreciation' ] ],
            [ 'emeaccounting-budget',      __( 'Begroting', 'eme-accounting' ),              [ __CLASS__, 'page_budget' ] ],
            [ 'emeaccounting-reports',     __( 'Rapporten', 'eme-accounting' ),              [ __CLASS__, 'page_reports' ] ],
            [ 'emeaccounting-settings',    __( 'Instellingen', 'eme-accounting' ),           [ __CLASS__, 'page_settings' ] ],
        ];

        foreach ( $subpages as [ $slug, $title, $callback ] ) {
            add_submenu_page(
                'emeaccounting',
                $title,
                $title,
                'manage_options',
                $slug,
                $callback
            );
        }
    }

    /* ------------------------------------------------------------------
     * Page callbacks – each loads a template file.
     * ------------------------------------------------------------------ */

    private static function load_template( string $name, array $vars = [] ): void {
        extract( $vars, EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract
        $file = EME_ACCOUNTING_DIR . 'templates/' . $name . '.php';
        if ( file_exists( $file ) ) {
            include $file;
        } else {
            echo '<div class="wrap"><p>Template ' . esc_html( $name ) . ' not found.</p></div>';
        }
    }

    public static function page_dashboard():    void { self::load_template( 'dashboard' ); }
    public static function page_accounts():     void { self::load_template( 'accounts' ); }
    public static function page_journal():      void { self::load_template( 'journal' ); }
    public static function page_import():       void { self::load_template( 'import' ); }
    public static function page_stock():        void { self::load_template( 'stock' ); }
    public static function page_depreciation(): void { self::load_template( 'depreciation' ); }
    public static function page_budget():       void { self::load_template( 'budget' ); }
    public static function page_reports():      void { self::load_template( 'reports' ); }
    public static function page_settings():     void { self::load_template( 'settings' ); }
}
