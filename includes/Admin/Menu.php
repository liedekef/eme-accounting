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
            'eme-accounting',
            [ __CLASS__, 'page_dashboard' ],
            'dashicons-money-alt',
            56
        );

        $subpages = [
            [ 'eme-accounting',             __( 'Dashboard', 'eme-accounting' ),              [ __CLASS__, 'page_dashboard' ] ],
            [ 'eme-accounting-journal',     __( 'Dagboek', 'eme-accounting' ),                [ __CLASS__, 'page_journal' ] ],
            [ 'eme-accounting-import',      __( 'Import / Reconciliatie', 'eme-accounting' ), [ __CLASS__, 'page_import' ] ],
            [ 'eme-accounting-stock',       __( 'Stock', 'eme-accounting' ),                  [ __CLASS__, 'page_stock' ] ],
            [ 'eme-accounting-depreciation',__( 'Afschrijvingen', 'eme-accounting' ),         [ __CLASS__, 'page_depreciation' ] ],
            [ 'eme-accounting-budget',      __( 'Begroting', 'eme-accounting' ),              [ __CLASS__, 'page_budget' ] ],
            [ 'eme-accounting-reports',     __( 'Rapporten', 'eme-accounting' ),              [ __CLASS__, 'page_reports' ] ],
            [ 'eme-accounting-close',       __( 'Boekjaar Afsluiten', 'eme-accounting' ),     [ __CLASS__, 'page_close' ] ],
            [ 'eme-accounting-coa',         __( 'Rekeningstelsel', 'eme-accounting' ),        [ __CLASS__, 'page_coa' ] ],
            [ 'eme-accounting-accounts',    __( 'Financiële Rekeningen', 'eme-accounting' ),  [ __CLASS__, 'page_accounts' ] ],
            [ 'eme-accounting-settings',    __( 'Instellingen', 'eme-accounting' ),           [ __CLASS__, 'page_settings' ] ],
        ];

        foreach ( $subpages as [ $slug, $title, $callback ] ) {
            add_submenu_page(
                'eme-accounting',
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
    public static function page_coa():         void { self::load_template( 'coa' ); }
    public static function page_accounts():     void { self::load_template( 'accounts' ); }
    public static function page_journal():      void { self::load_template( 'journal' ); }
    public static function page_import():       void { self::load_template( 'import' ); }
    public static function page_stock():        void { self::load_template( 'stock' ); }
    public static function page_depreciation(): void { self::load_template( 'depreciation' ); }
    public static function page_budget():       void { self::load_template( 'budget' ); }
    public static function page_reports():      void { self::load_template( 'reports' ); }
    public static function page_close():        void { self::load_template( 'close' ); }
    public static function page_settings():     void { self::load_template( 'settings' ); }
}
