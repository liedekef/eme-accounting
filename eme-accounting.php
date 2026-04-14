<?php
/**
 * Plugin Name:       EME Accounting
 * Plugin URI:        https://github.com/your-repo/eme-accounting
 * Description:       Enkelvoudige boekhouding als uitbreiding op Events Made Easy. Financiële rekeningen, dagboek, import/reconciliatie, stock, afschrijvingen, begroting en financiële rapporten.
 * Version:           1.0.0
 * Author:            Your Name
 * Author URI:        https://yoursite.be
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       eme-accounting
 * Domain Path:       /languages
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Requires Plugins:  events-made-easy
 */

defined( 'ABSPATH' ) || exit;

define( 'EME_ACCOUNTING_VERSION', '1.0.0' );
define( 'EME_ACCOUNTING_FILE',    __FILE__ );
define( 'EME_ACCOUNTING_DIR',     plugin_dir_path( __FILE__ ) );
define( 'EME_ACCOUNTING_URL',     plugin_dir_url( __FILE__ ) );

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
    load_plugin_textdomain( 'eme-accounting', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

    // Check dependency
    if ( ! function_exists( 'eme_get_event' ) ) {
        add_action( 'admin_notices', function () {
            echo '<div class="notice notice-error"><p>'
                . esc_html__( 'EME Accounting vereist de plugin Events Made Easy. Activeer die eerst.', 'eme-accounting' )
                . '</p></div>';
        } );
        return;
    }

    EMEAccounting\Admin\Menu::init();
    EMEAccounting\Accounts::init();
    EMEAccounting\ChartOfAccounts::init();
    EMEAccounting\FiscalYear::init();
    EMEAccounting\Journal::init();
    EMEAccounting\Import::init();
    EMEAccounting\Stock::init();
    EMEAccounting\Depreciation::init();
    EMEAccounting\Budget::init();
    EMEAccounting\Reports::init();
    EMEAccounting\Assets::init();
    EMEAccounting\EMEBridge::init();
}
add_action( 'plugins_loaded', 'eme_accounting_init' );

/* ------------------------------------------------------------------
 * Activation / deactivation / uninstall
 * ------------------------------------------------------------------ */
register_activation_hook( __FILE__, [ 'EMEAccounting\\Installer', 'activate' ] );
register_deactivation_hook( __FILE__, [ 'EMEAccounting\\Installer', 'deactivate' ] );
register_uninstall_hook( __FILE__, [ 'EMEAccounting\\Installer', 'uninstall' ] );
