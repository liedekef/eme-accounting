<?php
namespace EMEAccounting\Admin;

defined( 'ABSPATH' ) || exit;

use EMEAccounting\Capabilities;

/**
 * WordPress admin menu & submenu registration for EME Accounting.
 *
 * Each page is gated on its view-capability.
 * Templates receive $form_disabled (string: '' or 'disabled="disabled"')
 * so they can wrap their forms in <fieldset <?php echo $form_disabled; ?>>.
 */
class Menu {

    public static function init(): void {
        add_action( 'admin_menu', [ __CLASS__, 'register' ] );
    }

    public static function register(): void {
        add_menu_page(
            __( 'EME Boekhouding', 'eme-accounting' ),
            __( 'EME Boekhouding', 'eme-accounting' ),
            Capabilities::menu_cap( 'dashboard' ),
            'emeaccounting',
            [ __CLASS__, 'page_dashboard' ],
            'dashicons-money-alt',
            56
        );

        // [ menu_slug, title, page_key, callback ]
        $subpages = [
            [ 'emeaccounting',              __( 'Dashboard', 'eme-accounting' ),              'dashboard',    [ __CLASS__, 'page_dashboard' ] ],
            [ 'emeaccounting-journal',      __( 'Dagboek', 'eme-accounting' ),                'journal',      [ __CLASS__, 'page_journal' ] ],
            [ 'emeaccounting-import',       __( 'Import / Reconciliatie', 'eme-accounting' ), 'import',       [ __CLASS__, 'page_import' ] ],
            [ 'emeaccounting-stock',        __( 'Stock', 'eme-accounting' ),                  'stock',        [ __CLASS__, 'page_stock' ] ],
            [ 'emeaccounting-depreciation', __( 'Afschrijvingen', 'eme-accounting' ),         'depreciation', [ __CLASS__, 'page_depreciation' ] ],
            [ 'emeaccounting-budget',       __( 'Begroting', 'eme-accounting' ),              'budget',       [ __CLASS__, 'page_budget' ] ],
            [ 'emeaccounting-patrimony',    __( 'Staat van het Vermogen', 'eme-accounting' ), 'patrimony',    [ __CLASS__, 'page_patrimony' ] ],
            [ 'emeaccounting-reports',      __( 'Rapporten', 'eme-accounting' ),              'reports',      [ __CLASS__, 'page_reports' ] ],
            [ 'emeaccounting-fiscal-years', __( 'Boekjaren', 'eme-accounting' ),              'fiscalyears',  [ __CLASS__, 'page_fiscal_years' ] ],
            [ 'emeaccounting-coa',          __( 'Rekeningstelsel', 'eme-accounting' ),        'coa',          [ __CLASS__, 'page_coa' ] ],
            [ 'emeaccounting-accounts',     __( 'Financiële Rekeningen', 'eme-accounting' ),  'accounts',     [ __CLASS__, 'page_accounts' ] ],
            [ 'emeaccounting-settings',     __( 'Instellingen', 'eme-accounting' ),           'settings',     [ __CLASS__, 'page_settings' ] ],
        ];

        foreach ( $subpages as [ $slug, $title, $page_key, $callback ] ) {
            add_submenu_page(
                'emeaccounting',
                $title,
                $title,
                Capabilities::menu_cap( $page_key ),
                $slug,
                $callback
            );
        }
    }

    // ------------------------------------------------------------------ //
    //  Page callbacks                                                      //
    // ------------------------------------------------------------------ //

    private static function load_template( string $name, string $page_key ): void {
        Capabilities::require_view( $page_key );

        // $form_disabled is the HTML attribute string used by templates:
        $form_disabled = Capabilities::can_manage( $page_key ) ? '' : 'disabled="disabled"';

        $file = EME_ACCOUNTING_DIR . 'templates/' . $name . '.php';
        if ( file_exists( $file ) ) {
            include $file;
        } else {
            echo '<div class="wrap"><p>Template ' . esc_html( $name ) . ' not found.</p></div>';
        }
    }

    public static function page_dashboard():    void { self::load_template( 'dashboard',    'dashboard' ); }
    public static function page_journal():      void { self::load_template( 'journal',      'journal' ); }
    public static function page_import():       void { self::load_template( 'import',       'import' ); }
    public static function page_stock():        void { self::load_template( 'stock',        'stock' ); }
    public static function page_depreciation(): void { self::load_template( 'depreciation', 'depreciation' ); }
    public static function page_patrimony():    void { self::load_template( 'patrimony',    'patrimony' ); }
    public static function page_budget():       void { self::load_template( 'budget',       'budget' ); }
    public static function page_reports():      void { self::load_template( 'reports',      'reports' ); }
    public static function page_fiscal_years(): void { self::load_template( 'fiscalyears',  'fiscalyears' ); }
    public static function page_coa():          void { self::load_template( 'coa',          'coa' ); }
    public static function page_accounts():     void { self::load_template( 'accounts',     'accounts' ); }
    public static function page_settings():     void { self::load_template( 'settings',     'settings' ); }
}
