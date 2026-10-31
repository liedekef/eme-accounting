<?php
/**
 * Plugin Name:       EME Accounting
 * Plugin URI:        https://github.com/liedekef/eme-accounting
 * Update URI:        https://github.com/liedekef/eme-accounting
 * Description:       Enkelvoudige boekhouding als uitbreiding op Events Made Easy. Financiële rekeningen, dagboek, import/reconciliatie, stock, afschrijvingen, begroting en financiële rapporten.
 * Version:           1.0.9
 * Author:            Your Name
 * Author URI:        https://www.e-dynamics.be
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       eme-accounting
 * Requires at least: 6.0
 * Requires PHP:      8.2
 * Requires Plugins:  events-made-easy
 */

defined( 'ABSPATH' ) || exit;

define( 'EME_ACCOUNTING_VERSION', '1.0.9' );
define( 'EME_ACCOUNTING_FILE',    __FILE__ );
define( 'EME_ACCOUNTING_DIR',     plugin_dir_path( __FILE__ ) );
define( 'EME_ACCOUNTING_URL',     plugin_dir_url( __FILE__ ) );

require_once('includes/EMEFileAttachments.php');
/* ------------------------------------------------------------------
 * Autoloader
 * ------------------------------------------------------------------ */
spl_autoload_register( function ( string $class ): void {
    $prefix = 'EMEAccounting\\';
    if ( strncmp( $prefix, $class, strlen( $prefix ) ) !== 0 ) {
        return;
    }
    $relative = str_replace( '\\', DIRECTORY_SEPARATOR, substr( $class, strlen( $prefix ) ) );
    $file      = EME_ACCOUNTING_DIR . 'includes/' . $relative . '.php';
    if ( file_exists( $file ) ) {
        require $file;
    }
} );

/* ------------------------------------------------------------------
 * Boot
 * ------------------------------------------------------------------ */
function eme_accounting_init(): void {
    // Check dependency
    if ( ! function_exists( 'eme_get_event' ) ) {
        add_action( 'admin_notices', function () {
            echo '<div class="notice notice-error"><p>'
                . esc_html__( 'EME Accounting vereist de plugin Events Made Easy. Activeer die eerst.', 'eme-accounting' )
                . '</p></div>';
        } );
        return;
    }

    EMEAccounting\Installer::init();
    EMEAccounting\Admin\Menu::init();
    EMEAccounting\Accounts::init();
    EMEAccounting\ChartOfAccounts::init();
    EMEAccounting\FiscalYear::init();
    EMEAccounting\Journal::init();
    EMEAccounting\Import::init();
    EMEAccounting\Stock::init();
    EMEAccounting\Depreciation::init();
    EMEAccounting\Budget::init();
    EMEAccounting\Patrimony::init();
    EMEAccounting\Reports::init();
    EMEAccounting\Assets::init();
    EMEAccounting\EMEBridge::init();
}
add_action( 'plugins_loaded', 'eme_accounting_init' );

/* ------------------------------------------------------------------
 * DB schema upgrades.
 *
 * The activation hook does not fire on silent plugin updates, so the DB
 * version is checked on every request and the schema is upgraded when it
 * lags behind. Priority 5 => before the rest of the plugin is loaded.
 * ------------------------------------------------------------------ */
add_action( 'plugins_loaded', [ 'EMEAccounting\\Installer', 'maybe_upgrade' ], 5 );
add_action( 'wp_initialize_site', [ 'EMEAccounting\\Installer', 'new_site' ], 900, 2 );

/* ------------------------------------------------------------------
 * Activation / deactivation / uninstall
 * ------------------------------------------------------------------ */
register_activation_hook( __FILE__, [ 'EMEAccounting\\Installer', 'activate' ] );
register_deactivation_hook( __FILE__, [ 'EMEAccounting\\Installer', 'deactivate' ] );
register_uninstall_hook( __FILE__, [ 'EMEAccounting\\Installer', 'uninstall' ] );

/* ------------------------------------------------------------------
 * Extra links on the plugins overview page
 * ------------------------------------------------------------------ */
function eme_accounting_plugin_row_meta( $links, $file ) {
    if ( strpos( $file, 'eme-accounting.php' ) !== false ) {
        $new_links = [
            'donate Paypal'     => '<a href="https://www.paypal.com/donate/?business=SMGDS4GLCYWNG&no_recurring=0&currency_code=EUR">Donate (Paypal)</a>',
            'Github Sponsoring' => '<a href="https://github.com/sponsors/liedekef">Github sponsoring</a>',
            'Support'           => '<a href="https://github.com/liedekef/eme-accounting">Support</a>',
        ];
        $links = array_merge( $links, $new_links );
    }
    return $links;
}
add_filter( 'plugin_row_meta', 'eme_accounting_plugin_row_meta', 10, 2 );

// BEGIN NOT FOR WP
require_once __DIR__ . '/includes/class-eme-updater.php';
new \EmeAccounting\EME_GitHub_Updater( __FILE__, 'liedekef', 'eme-accounting' );
// END NOT FOR WP
