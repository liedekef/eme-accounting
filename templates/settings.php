<?php
// phpcs:ignoreFile WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
defined( 'ABSPATH' ) || exit;

use EMEAccounting\Capabilities;
use EMEAccounting\Vat;

$config = \EMEVzw\Config::get();
$eme_categories = eme_get_categories();
$vat_liable = (bool) ( $config->vat_liable ?? false );

// ── Handle: general settings ────────────────────────────────────────────────
if ( isset( $_POST['eme_accounting_settings_nonce'] )
    && Capabilities::can_manage( 'settings' )
    && check_admin_referer( 'eme_accounting_settings', 'eme_accounting_settings_nonce' ) ) {
    $settings = [
        'default_income_account' => (int) eme_sanitize_request( $_POST['default_income_account'] ?? 0 ), //phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        'delete_on_uninstall'    => sanitize_key( eme_sanitize_request( $_POST['delete_on_uninstall'] ?? 'no' ) ), //phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        'eme_scope'              => eme_sanitize_request( $_POST['eme_scope'] ?? '-3m--12m' ), //phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        'eme_cat_ids'            => eme_sanitize_request( $_POST['eme_cat_ids'] ?? [] ), //phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        'eme_exclude_cat_ids'    => eme_sanitize_request( $_POST['eme_exclude_cat_ids'] ?? [] ), //phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        'eme_auto_booking'       => (int) (bool) eme_sanitize_request( $_POST['eme_auto_booking'] ?? 0 ), //phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        'default_coa'            => (int) eme_sanitize_request( $_POST['default_coa'] ?? 0 ), //phpcs:ignore WordPress.Security.ValidatedSanitizedInput
    ];
    if ( $settings['eme_auto_booking'] && ! $settings['default_coa'] ) {
        echo '<div class="notice notice-error"><p>' . esc_html__( 'Kies een resultatenrekening aub.', 'eme-accounting' ) . '</p></div>';
    } else {
        // Preserve the weekly-rebuild setting, which is saved via its own form/handler below.
        $settings['weekly_rebuild_totals'] = (int) ( get_option( 'eme_accounting_settings', [] )['weekly_rebuild_totals'] ?? 0 );
        update_option( 'eme_accounting_settings', $settings );
        update_option( 'eme_accounting_delete_on_uninstall', $settings['delete_on_uninstall'] );
        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Instellingen opgeslagen.', 'eme-accounting' ) . '</p></div>';
    }
}

// ── Handle: access matrix ───────────────────────────────────────────────────
if ( isset( $_POST['emeacc_access_nonce'] )
    && Capabilities::can_manage( 'settings' )
    && check_admin_referer( 'emeacc_access_save', 'emeacc_access_nonce' ) ) {
    Capabilities::handle_save();
    echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Toegangsrechten opgeslagen.', 'eme-accounting' ) . '</p></div>';
}

// ── Handle: weekly rebuild cron setting ─────────────────────────────────────
if ( isset( $_POST['emeacc_weekly_rebuild_nonce'] )
    && Capabilities::can_manage( 'settings' )
    && check_admin_referer( 'emeacc_weekly_rebuild', 'emeacc_weekly_rebuild_nonce' ) ) {
    $settings                           = get_option( 'eme_accounting_settings', [] );
    $settings['weekly_rebuild_totals']  = (int) (bool) eme_sanitize_request( $_POST['weekly_rebuild_totals'] ?? 0 ); //phpcs:ignore WordPress.Security.ValidatedSanitizedInput
    update_option( 'eme_accounting_settings', $settings );
    EMEAccounting\Installer::sync_weekly_rebuild_cron();
    echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Instelling opgeslagen.', 'eme-accounting' ) . '</p></div>';
}

// ── Handle: rebuild totals ──────────────────────────────────────────────────
if ( isset( $_POST['emeacc_rebuild_totals_nonce'] )
    && Capabilities::can_manage( 'settings' )
    && check_admin_referer( 'emeacc_rebuild_totals', 'emeacc_rebuild_totals_nonce' ) ) {
    $r = EMEAccounting\Accounts::rebuild_totals();
    if ( is_wp_error( $r ) ) {
        echo '<div class="notice notice-error"><p>' . esc_html__( 'Fout bij het herberekenen van de totalen:', 'eme-accounting' ) . '<br>' ;
        echo esc_html($r->get_error_message());
        echo '</p></div>';
    } else {
        echo '<div class="notice notice-success"><p>' . esc_html__( 'Totals tabel succesvol herberekend.', 'eme-accounting' ) . '</p></div>';
    }
}

// ── Handle: BTW-tarieven ──
if ( isset( $_POST['emeacc_vat_nonce'] )
    && Capabilities::can_manage( 'settings' )
    && check_admin_referer( 'emeacc_vat_rates', 'emeacc_vat_nonce' ) ) {
    if ( ! $vat_liable ) {
        echo '<div class="notice notice-error"><p>' . esc_html__( 'Deze VZW is niet BTW-plichtig; tarieven kunnen niet aangepast worden.', 'eme-accounting' ) . '</p></div>';
    } else {
        Vat::save_custom( sanitize_text_field( wp_unslash( $_POST['vat_rates_custom'] ?? '' ) ) );
        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'BTW-tarieven opgeslagen.', 'eme-accounting' ) . '</p></div>';
    }
}

// ── Handle: Peppol inbox ────────────────────────────────────────────────────
if ( isset( $_POST['emeacc_peppol_nonce'] )
    && Capabilities::can_manage( 'settings' )
    && check_admin_referer( 'emeacc_peppol', 'emeacc_peppol_nonce' ) ) {
    $peppol     = EMEAccounting\Peppol::get_settings();
    $peppol_old = $peppol;
    // API keys are printable ASCII; drop anything else instead of sanitize_text_field() (which mangles %xx).
    $raw_key = $_POST['peppol_api_key'] ?? ''; //phpcs:ignore WordPress.Security.ValidatedSanitizedInput
    $new_key = is_string( $raw_key ) ? preg_replace( '/[^\x21-\x7E]/', '', wp_unslash( $raw_key ) ) : '';

    if ( ! empty( $_POST['peppol_clear_api_key'] ) ) {
        $peppol['api_key'] = '';
    } elseif ( $new_key !== '' ) {
        $peppol['api_key'] = $new_key;
    }

    $peppol['poll_enabled'] = (int) ! empty( $_POST['peppol_poll_enabled'] );
    $peppol['imap_enabled'] = (int) ! empty( $_POST['peppol_imap_enabled'] );

    $peppol['imap_host'] = sanitize_text_field( wp_unslash( $_POST['peppol_imap_host'] ?? '' ) ); //phpcs:ignore WordPress.Security.ValidatedSanitizedInput
    $imap_port           = (int) ( $_POST['peppol_imap_port'] ?? 993 ); //phpcs:ignore WordPress.Security.ValidatedSanitizedInput
    $peppol['imap_port'] = $imap_port > 0 && $imap_port <= 65535 ? $imap_port : 993;

    $imap_encryption          = sanitize_key( $_POST['peppol_imap_encryption'] ?? 'ssl' ); //phpcs:ignore WordPress.Security.ValidatedSanitizedInput
    $peppol['imap_encryption'] = in_array( $imap_encryption, [ 'ssl', 'tls', 'none' ], true ) ? $imap_encryption : 'ssl';
    $peppol['imap_username']   = sanitize_text_field( wp_unslash( $_POST['peppol_imap_username'] ?? '' ) ); //phpcs:ignore WordPress.Security.ValidatedSanitizedInput
    $peppol['imap_folder']     = sanitize_text_field( wp_unslash( $_POST['peppol_imap_folder'] ?? 'INBOX' ) ); //phpcs:ignore WordPress.Security.ValidatedSanitizedInput
    if ( $peppol['imap_folder'] === '' ) {
        $peppol['imap_folder'] = 'INBOX';
    }
    $imap_max                    = (int) ( $_POST['peppol_imap_max_messages'] ?? 25 ); //phpcs:ignore WordPress.Security.ValidatedSanitizedInput
    $peppol['imap_max_messages'] = max( 1, min( 100, $imap_max ) );
    $peppol['imap_delete_after_parse'] = (int) ! empty( $_POST['peppol_imap_delete_after_parse'] );

    $raw_password = $_POST['peppol_imap_password'] ?? ''; //phpcs:ignore WordPress.Security.ValidatedSanitizedInput
    $new_password = is_string( $raw_password ) ? preg_replace( '/[\x00-\x1F\x7F]/', '', wp_unslash( $raw_password ) ) : '';
    if ( ! empty( $_POST['peppol_imap_clear_password'] ) ) {
        $peppol['imap_password'] = '';
    } elseif ( $new_password !== '' ) {
        $peppol['imap_password'] = mb_substr( $new_password, 0, 255 );
    }

    $mode = sanitize_key( $_POST['peppol_initial_sync_mode'] ?? 'now' );
    $mode = in_array( $mode, [ 'now', 'full' ], true ) ? $mode : 'now';
    $peppol['initial_sync_mode'] = $mode;

    // https only: e-invoice.be delivers signed document events to this URL.
    $webhook_input = trim( (string) wp_unslash( $_POST['peppol_webhook_url_override'] ?? '' ) ); //phpcs:ignore WordPress.Security.ValidatedSanitizedInput
    $peppol['webhook_url_override'] = esc_url_raw( $webhook_input, [ 'https' ] );
    $webhook_rejected = $webhook_input !== '' && $peppol['webhook_url_override'] === '';

    // Apply the first-sync choice once a key is configured and no position exists
    // yet, or when the user explicitly asks to reset the sync position. Without
    // a key the reset would only store a cursor for a sync that cannot run.
    $reset_requested     = ! empty( $_POST['peppol_reset_sync'] );
    $can_reset           = $peppol['api_key'] !== '';
    $sync_reset_rejected = $reset_requested && ! $can_reset;
    if ( $can_reset && ( $reset_requested || empty( $peppol['cursor_created_at'] ) ) ) {
        $peppol = EMEAccounting\PeppolEInvoice::reset_sync_position( $peppol, $mode );
    }

    $peppol = EMEAccounting\PeppolEInvoice::prepare_settings_update( $peppol, $peppol_old );
    if ( is_wp_error( $peppol ) ) {
        echo '<div class="notice notice-error"><p>' . sprintf(
            /* translators: %s: API error message */
            esc_html__( 'Peppol-instellingen niet opgeslagen: %s', 'eme-accounting' ),
            esc_html( $peppol->get_error_message() )
        ) . '</p></div>';
    } else {
        EMEAccounting\Peppol::update_settings( $peppol );
        EMEAccounting\PeppolEInvoice::sync_poll_cron();
        EMEAccounting\PeppolImap::sync_cron();
        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Peppol-instellingen opgeslagen.', 'eme-accounting' ) . '</p></div>';

        if ( ! empty( $peppol['api_key'] ) ) {
            $webhook_sync = EMEAccounting\PeppolEInvoice::sync_webhook();
            if ( is_wp_error( $webhook_sync ) ) {
                echo '<div class="notice notice-warning is-dismissible"><p>' . sprintf(
                    /* translators: %s: API error message */
                    esc_html__( 'De webhook kon niet automatisch worden aangemaakt of bijgewerkt: %s', 'eme-accounting' ),
                    esc_html( $webhook_sync->get_error_message() )
                ) . '</p></div>';
            }
        }
    }
    if ( $webhook_rejected ) {
        echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html__( 'De webhook-URL moet met https:// beginnen en is niet opgeslagen.', 'eme-accounting' ) . '</p></div>';
    }
    if ( $sync_reset_rejected ) {
        echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html__( 'De synchronisatiepositie is niet gereset: stel eerst een API-key in.', 'eme-accounting' ) . '</p></div>';
    }
}

$settings = wp_parse_args( get_option( 'eme_accounting_settings', [] ), [
    'default_income_account' => 0,
    'delete_on_uninstall'    => 'no',
    'default_coa'            => 0,
    'eme_auto_booking'       => 0,
    'weekly_rebuild_totals'  => 0,
    'eme_scope'              => '-3m--12m',
    'eme_cat_ids'            => [],
    'eme_exclude_cat_ids'    => [],
] );
$accounts     = EMEAccounting\Accounts::get_dropdown();
$coa_accounts = EMEAccounting\ChartOfAccounts::get_dropdown();
$active_tab   = sanitize_key( $_GET['tab'] ?? 'general' );
?>
<div class="wrap eme-acc-wrap">
<h1><?php esc_html_e( 'Instellingen – EME Boekhouding', 'eme-accounting' ); ?></h1>

<nav class="nav-tab-wrapper wp-clearfix" style="margin-bottom:1.5em">
    <a href="<?php echo esc_url( add_query_arg( 'tab', 'general' ) ); ?>"
       class="nav-tab <?php echo $active_tab === 'general' ? 'nav-tab-active' : ''; ?>">
        <?php esc_html_e( 'Algemeen', 'eme-accounting' ); ?>
    </a>
    <a href="<?php echo esc_url( add_query_arg( 'tab', 'access' ) ); ?>"
       class="nav-tab <?php echo $active_tab === 'access' ? 'nav-tab-active' : ''; ?>">
        <?php esc_html_e( 'Toegangsbeheer', 'eme-accounting' ); ?>
    </a>
    <a href="<?php echo esc_url( add_query_arg( 'tab', 'vat' ) ); ?>"
        class="nav-tab <?php echo $active_tab === 'vat' ? 'nav-tab-active' : ''; ?>">
        <?php esc_html_e( 'BTW', 'eme-accounting' ); ?>
    </a>
    <a href="<?php echo esc_url( add_query_arg( 'tab', 'peppol' ) ); ?>"
        class="nav-tab <?php echo $active_tab === 'peppol' ? 'nav-tab-active' : ''; ?>">
        <?php esc_html_e( 'Peppol', 'eme-accounting' ); ?>
    </a>
    <a href="<?php echo esc_url( add_query_arg( 'tab', 'advanced' ) ); ?>"
       class="nav-tab <?php echo $active_tab === 'advanced' ? 'nav-tab-active' : ''; ?>">
        <?php esc_html_e( 'Advanced', 'eme-accounting' ); ?>
    </a>
</nav>

<?php if ( $active_tab === 'general' ): ?>

<!-- ══════════════════════════════════════════════════════════════════════
     TAB: GENERAL SETTINGS
     ══════════════════════════════════════════════════════════════════════ -->
<form method="post">
<?php wp_nonce_field( 'eme_accounting_settings', 'eme_accounting_settings_nonce' ); ?>

<div class="eme-acc-form-box">
<h2><?php esc_html_e( 'Boekjaar info', 'eme-accounting' ); ?></h2>
<table class="form-table">
<tr>
    <th><?php esc_html_e( 'Boekjaar start', 'eme-accounting' ); ?></th>
    <td><b><?php echo esc_html( $config->fiscal_year_start_dd . ' ' . date_i18n( 'F', mktime( 0, 0, 0, $config->fiscal_year_start_mm, 1 ) ) ); ?></b>
        <br><?php esc_html_e( '(aan te passen in de VZW instellingen)', 'eme-accounting' ); ?>
    </td>
</tr>
<tr>
    <th><?php esc_html_e( 'Boekjaar einde', 'eme-accounting' ); ?></th>
    <td><b><?php echo esc_html( $config->fiscal_year_end_dd . ' ' . date_i18n( 'F', mktime( 0, 0, 0, $config->fiscal_year_end_mm, 1 ) ) ); ?></b>
        <br><?php esc_html_e( '(aan te passen in de VZW instellingen)', 'eme-accounting' ); ?>
    </td>
</tr>
</table>
</div>

<div class="eme-acc-form-box">
<h2><?php esc_html_e( 'Integratie met Events Made Easy', 'eme-accounting' ); ?></h2>
<table class="form-table">
<tr>
    <th><?php esc_html_e( 'Range bij doorzoeken van evenementen', 'eme-accounting' ); ?></th>
    <td>
        <label>
            <input type="text" name="eme_scope" value="<?php echo esc_html($settings['eme_scope']); ?>">
            <?php _e( "Definiëer de scope van evenementen die je standaard wil doorzoeken. Default is van 3 maand in het verleden tot 12 maand in de toekomst. Voor alle mogelijkheden, zie <a href='https://www.e-dynamics.be/wordpress/eme-docs/eme_events/' target='_blank'>de Events Made Easy documentatie</a>", 'eme-accounting'); //phpcs:ignore WordPress.Security.EscapeOutput.UnsafePrintingFunction -- intentional inline <a> markup ?>
        </label>
    </td>
</tr>
<?php if ( !empty( $eme_categories ) ): ?>
<tr>
    <th><?php esc_html_e( 'Event categorieën bij doorzoeken van evenementen', 'eme-accounting' ); ?></th>
    <td>
    <label>
            <?php
            echo eme_ui_multiselect_key_value( $settings['eme_cat_ids'], 'eme_cat_ids', $eme_categories, 'category_id', 'category_name', 3, '', 0, 'eme_snapselect' ); //phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- trusted HTML from eme_ui_multiselect()
            ?>
            <?php esc_html_e( "Beperk het doorzoeken van evenementen tot bepaalde categorieën", 'eme-accounting'); ?>
    </label>
    <br>
    <br>
    <label>
            <?php
            echo eme_ui_multiselect_key_value( $settings['eme_exclude_cat_ids'], 'eme_exclude_cat_ids', $eme_categories, 'category_id', 'category_name', 3, '', 0, 'eme_snapselect' ); //phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- trusted HTML from eme_ui_multiselect()
            ?>
            <?php esc_html_e( "En/of excludeer bepaalde categorieën bij het doorzoeken van evenementen", 'eme-accounting'); ?>
    </label>
    </td>
</tr>
<?php endif; ?>

<tr>
    <th><?php esc_html_e( 'Automatisch boeken bij betaling', 'eme-accounting' ); ?></th>
    <td>
        <label>
            <input type="checkbox" name="eme_auto_booking" value="1" <?php echo checked( 1, $settings['eme_auto_booking'] ); ?>>
            <?php esc_html_e( 'Maak automatisch een journaalboeking aan wanneer een EME-betaling als betaald wordt gemarkeerd.', 'eme-accounting' ); ?>
        </label>
        <p class="description"><?php esc_html_e( 'Vereist een standaard ontvangstrekening en een standaard resultatenrekening (hieronder).', 'eme-accounting' ); ?></p>
    </td>
</tr>
<tr>
    <th><?php esc_html_e( 'Standaard ontvangstrekening', 'eme-accounting' ); ?></th>
    <td>
        <select name="default_income_account">
            <option value="0"><?php esc_html_e( '— geen —', 'eme-accounting' ); ?></option>
            <?php foreach ( $accounts as $id => $name ): ?>
            <option value="<?php echo esc_attr( $id ); ?>" <?php echo selected( $id, $settings['default_income_account'], false ); ?>><?php echo esc_html( $name ); ?></option>
            <?php endforeach; ?>
        </select>
    </td>
</tr>
<tr>
    <th><?php esc_html_e( 'Standaard resultatenrekening', 'eme-accounting' ); ?></th>
    <td>
        <select name="default_coa" class="eme_snapselect" data-live-search="true">
            <option value="0"><?php esc_html_e( '— Kies ontvangstenrekening —', 'eme-accounting' ); ?></option>
            <?php foreach ( $coa_accounts as $id => $acc ):
                if ( $acc && $acc->direction === 'in' ): ?>
                <option value="<?php echo (int) $id; ?>" <?php echo selected( $settings['default_coa'] ?? '', $id, false ); ?> <?php if ( empty( $acc->parent_id ) ) echo 'disabled'; ?>>
                    <?php echo esc_html( $acc->code . ' - ' . $acc->name ); ?>
                </option>
            <?php endif; endforeach; ?>
        </select>
    </td>
</tr>
</table>
</div>

<div class="eme-acc-form-box" style="border-color:#d63638">
<h2 style="color:#d63638"><?php esc_html_e( 'Gevaarzone', 'eme-accounting' ); ?></h2>
<table class="form-table">
<tr>
    <th><?php esc_html_e( 'Data verwijderen bij uninstall plugin?', 'eme-accounting' ); ?></th>
    <td>
        <select name="delete_on_uninstall">
            <option value="no"  <?php echo selected( 'no',  $settings['delete_on_uninstall'], false ); ?>><?php esc_html_e( 'Nee – data bewaren (aanbevolen)', 'eme-accounting' ); ?></option>
            <option value="yes" <?php echo selected( 'yes', $settings['delete_on_uninstall'], false ); ?>><?php esc_html_e( 'Ja – ALLE boekhouddata verwijderen bij uninstall', 'eme-accounting' ); ?></option>
        </select>
        <p class="description" style="color:#d63638"><?php esc_html_e( 'LET OP: "Ja" verwijdert permanent alle rekeningen, boekingen en importdata!', 'eme-accounting' ); ?></p>
    </td>
</tr>
</table>
</div>

<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Instellingen opslaan', 'eme-accounting' ); ?></button></p>
</form>

<?php elseif ( $active_tab === 'access' ): ?>

<!-- ══════════════════════════════════════════════════════════════════════
     TAB: ACCESS MANAGEMENT
     ══════════════════════════════════════════════════════════════════════ -->
<?php
$all_roles  = wp_roles()->roles;
$matrix     = Capabilities::get_current_matrix();
$profiles   = Capabilities::PROFILES;
$pages      = Capabilities::PAGES;

// Detect current profile per role.
$role_profiles = [];
foreach ( array_keys( $all_roles ) as $slug ) {
    $role_profiles[ $slug ] = Capabilities::detect_profile( $slug );
}
?>

<p><?php esc_html_e(
    'Kies per WordPress-rol welk toegangsprofiel van toepassing is. '
    . 'De instellingenpagina is altijd toegankelijk voor beheerders (manage_options) en staat niet in deze lijst.',
    'eme-accounting'
); ?></p>

<form method="post" id="emeacc-access-form">
<?php wp_nonce_field( 'emeacc_access_save', 'emeacc_access_nonce' ); ?>

<table class="eme-acc-role-table widefat">
<thead>
<tr>
    <th style="width:220px"><?php esc_html_e( 'WordPress-rol', 'eme-accounting' ); ?></th>
    <th><?php esc_html_e( 'Toegangsprofiel', 'eme-accounting' ); ?></th>
</tr>
</thead>
<tbody>
<?php foreach ( $all_roles as $role_slug => $role_data ):
    $role_label   = translate_user_role( $role_data['name'] );
    $current_prof = $role_profiles[ $role_slug ];
    $has_manage_options = ! empty( $role_data['capabilities']['manage_options'] );
?>
<tr>
    <td><strong><?php echo esc_html( $role_label ); ?></strong></td>
    <td>
        <?php /* Profile dropdown */ ?>
        <select name="emeacc_profile[<?php echo esc_attr( $role_slug ); ?>]"
                class="eme-acc-profile-select"
                data-role="<?php echo esc_attr( $role_slug ); ?>"
                style="margin-bottom:4px">
            <?php foreach ( $profiles as $prof_slug => $prof ): ?>
            <option value="<?php echo esc_attr( $prof_slug ); ?>"
                    data-desc="<?php echo esc_attr( $prof['description'] ); ?>"
                    <?php echo selected( $current_prof, $prof_slug, false ); ?>>
                <?php echo esc_html( $prof['label'] ); ?>
            </option>
            <?php endforeach; ?>
        </select>

        <?php /* Live description line */ ?>
        <div class="eme-acc-role-profile-desc" id="desc-<?php echo esc_attr( $role_slug ); ?>">
            <?php echo esc_html( $profiles[ $current_prof ]['description'] ); ?>
        </div>

        <?php /* Hint for roles with manage_options */ ?>
        <?php if ( $has_manage_options ): ?>
        <div class="eme-acc-role-profile-desc" style="color:#b45309; margin-top:2px">
            ⚠️ <?php esc_html_e( 'Deze rol heeft manage_options: de instellingenpagina is altijd toegankelijk.', 'eme-accounting' ); ?>
        </div>
        <?php endif; ?>

        <?php /* Custom detail panel (hidden unless 'custom' selected) */ ?>
        <div class="eme-acc-role-custom-panel <?php echo $current_prof === 'custom' ? 'is-open' : ''; ?>"
             id="custom-<?php echo esc_attr( $role_slug ); ?>">
            <div class="eme-acc-role-custom-grid">
                <?php foreach ( $pages as $page_slug => $page_label ):
                    $level = $matrix[ $role_slug ][ $page_slug ] ?? 'none';
                ?>
                <label>
                    <span><?php echo esc_html( $page_label ); ?></span>
                    <select name="emeacc_access[<?php echo esc_attr( $role_slug ); ?>][<?php echo esc_attr( $page_slug ); ?>]">
                        <option value="none"   <?php echo selected( $level, 'none',   false ); ?>><?php esc_html_e( 'Geen',   'eme-accounting' ); ?></option>
                        <option value="view"   <?php echo selected( $level, 'view',   false ); ?>><?php esc_html_e( 'Lezen',  'eme-accounting' ); ?></option>
                        <option value="manage" <?php echo selected( $level, 'manage', false ); ?>><?php esc_html_e( 'Beheer', 'eme-accounting' ); ?></option>
                    </select>
                </label>
                <?php endforeach; ?>
            </div>
        </div>
    </td>
</tr>
<?php endforeach; ?>
</tbody>
</table>

<p>
    <button type="submit" class="button button-primary">
        <?php esc_html_e( 'Toegangsrechten opslaan', 'eme-accounting' ); ?>
    </button>
    <span class="description" style="margin-left:1em">
        <?php esc_html_e( 'Wijzigingen zijn direct van kracht.', 'eme-accounting' ); ?>
    </span>
</p>
</form>

<?php /* Profile legend */ ?>
<div class="eme-acc-form-box" style="margin-top:1.5em; border-color:#0073aa">
<h3 style="color:#0073aa; margin-top:.5em"><?php esc_html_e( 'Profielen', 'eme-accounting' ); ?></h3>
<table class="form-table" style="margin-top:0">
<?php foreach ( $profiles as $prof_slug => $prof ): if ( $prof_slug === 'custom' ) continue; ?>
<tr>
    <th style="width:180px"><?php echo esc_html( $prof['label'] ); ?></th>
    <td><?php echo esc_html( $prof['description'] ); ?>
        <?php if ( $prof_slug !== 'none' ): ?>
        <br><small style="color:#787c82">
            <?php
            $detail = [];
            foreach ( $prof['caps'] as $pg => $lv ) {
                if ( $lv !== 'none' ) {
                    $icon = $lv === 'manage' ? '✏️' : '👁';
                    $detail[] = $icon . ' ' . ( Capabilities::PAGES[ $pg ] ?? $pg );
                }
            }
            echo esc_html( implode( ', ', $detail ) );
            ?>
        </small>
        <?php endif; ?>
    </td>
</tr>
<?php endforeach; ?>
<tr>
    <th><?php echo esc_html( $profiles['custom']['label'] ); ?></th>
    <td><?php echo esc_html( $profiles['custom']['description'] ); ?></td>
</tr>
</table>
</div>

<script>
(function () {
    const descs   = <?php echo wp_json_encode( array_map( fn($p) => $p['description'], Capabilities::PROFILES ), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ); ?>;

    document.querySelectorAll('.eme-acc-profile-select').forEach(function (sel) {
        sel.addEventListener('change', function () {
            const role   = this.dataset.role;
            const prof   = this.value;
            // Update description.
            const descEl = document.getElementById('desc-' + role);
            if (descEl) descEl.textContent = descs[prof] || '';
            // Show/hide custom panel.
            const panel = document.getElementById('custom-' + role);
            if (panel) panel.classList.toggle('is-open', prof === 'custom');
        });
    });
}());
</script>

<?php elseif ( $active_tab === 'vat' ): ?>
   <?php if ( ! $vat_liable ): ?>
   <div class="notice notice-info inline">
       <p><?php esc_html_e( 'Deze VZW is niet BTW-plichtig (aan te passen in de VZW instellingen). De tarieven hieronder zijn enkel ter info.', 'eme-accounting' ); ?></p>
   </div>
   <?php endif; ?>
   <form method="post">
   <?php wp_nonce_field( 'emeacc_vat_rates', 'emeacc_vat_nonce' ); ?>
   <fieldset <?php disabled( ! $vat_liable ); ?> <?php echo $vat_liable ? '' : 'inert'; ?>>
       <div class="eme-acc-form-box">
       <h2><?php esc_html_e( 'BTW-tarieven', 'eme-accounting' ); ?></h2>
       <table class="form-table">
       <tr>
           <th><?php esc_html_e( 'Officiële tarieven', 'eme-accounting' ); ?></th>
           <td><b><?php echo esc_html( implode( '%, ', Vat::OFFICIAL_RATES ) . '%' ); ?></b>
               <br><span class="description"><?php esc_html_e( 'Deel van de plugin; wordt bij updates bijgewerkt.', 'eme-accounting' ); ?></span></td>
       </tr>
       <tr>
           <th><?php esc_html_e( 'Eigen tarieven', 'eme-accounting' ); ?></th>
           <td><input type="text" name="vat_rates_custom" value="<?php echo esc_attr( implode( ', ', Vat::custom() ) ); ?>">
               <br><span class="description"><?php esc_html_e( 'Kommagescheiden, decimalen met punt (bv. 5.5, 25). Tarieven die al officieel zijn worden genegeerd.', 'eme-accounting' ); ?></span></td>
       </tr>
       <tr>
           <th><?php esc_html_e( 'Beschikbaar bij nieuwe boekingen', 'eme-accounting' ); ?></th>
           <td><b><?php echo esc_html( implode( '%, ', Vat::rates() ) . '%' ); ?></b>
               + <?php esc_html_e( 'Vrijgesteld', 'eme-accounting' ); ?></td>
       </tr>
       </table>
       <?php submit_button(); ?>
       </div>
   </fieldset>
   </form>

<?php elseif ( $active_tab === 'peppol' ): ?>
<?php
$pp              = EMEAccounting\Peppol::get_settings();
$einvoice_status = EMEAccounting\PeppolEInvoice::get_public_status();
$imap_status     = EMEAccounting\PeppolImap::get_public_status();
$api_configured  = ! empty( $einvoice_status['api_configured'] );
$imap_configured = ! empty( $imap_status['configured'] );

$masked_key  = $pp['api_key'] !== '' ? str_repeat( '•', 8 ) . substr( $pp['api_key'], -4 ) : '';
$masked_imap = $pp['imap_password'] !== '' ? str_repeat( '•', 8 ) . substr( $pp['imap_password'], -4 ) : '';
?>

<!-- ══════════════════════════════════════════════════════════════════════
     TAB: PEPPOL INBOX
     ══════════════════════════════════════════════════════════════════════ -->
<form method="post">
<?php wp_nonce_field( 'emeacc_peppol', 'emeacc_peppol_nonce' ); ?>

<details class="eme-acc-form-box">
    <summary><b><?php esc_html_e( 'e-invoice.be koppeling', 'eme-accounting' ); ?></b>
        <span class="eme-acc-tag <?php echo $api_configured ? 'eme-acc-reconciled' : 'eme-acc-pending'; ?>">
            <?php echo $api_configured ? esc_html__( 'Geconfigureerd', 'eme-accounting' ) : esc_html__( 'Niet geconfigureerd', 'eme-accounting' ); ?>
        </span>
    </summary>
<table class="form-table">
<tr>
    <th><?php esc_html_e( 'API-key', 'eme-accounting' ); ?></th>
    <td>
        <?php if ( $pp['api_key'] !== '' ): ?>
            <p><code><?php echo esc_html( $masked_key ); ?></code></p>
            <label>
                <input type="checkbox" name="peppol_clear_api_key" value="1">
                <?php esc_html_e( 'API-key verwijderen', 'eme-accounting' ); ?>
            </label>
            <p class="description"><?php esc_html_e( 'Laat het veld hieronder leeg om de bestaande key te bewaren.', 'eme-accounting' ); ?></p>
        <?php endif; ?>
        <input type="password" name="peppol_api_key" value="" autocomplete="new-password" style="min-width:340px"
               placeholder="<?php echo $pp['api_key'] !== '' ? esc_attr__( 'Nieuwe key (optioneel)', 'eme-accounting' ) : esc_attr__( 'Plak hier je e-invoice.be API-key', 'eme-accounting' ); ?>">
        <p class="description"><?php esc_html_e( 'De key wordt niet in de pagina getoond en nooit gelogd.', 'eme-accounting' ); ?></p>
    </td>
</tr>
<tr>
    <th><?php esc_html_e( 'Automatisch synchroniseren', 'eme-accounting' ); ?></th>
    <td>
        <label>
            <input type="checkbox" name="peppol_poll_enabled" value="1" <?php echo checked( 1, (int) $pp['poll_enabled'], false ); ?>>
            <?php esc_html_e( 'Elk uur de inbox van e-invoice.be ophalen', 'eme-accounting' ); ?>
        </label>
        <p class="description"><?php esc_html_e( 'De webhook levert documenten bijna onmiddellijk; de uurlijkse poll is een vangnet.', 'eme-accounting' ); ?></p>
    </td>
</tr>
<tr>
    <th><?php esc_html_e( 'Eerste synchronisatie', 'eme-accounting' ); ?></th>
    <td>
        <select name="peppol_initial_sync_mode">
            <option value="now"  <?php echo selected( $pp['initial_sync_mode'], 'now', false ); ?>><?php esc_html_e( 'Vanaf nu (aanbevolen)', 'eme-accounting' ); ?></option>
            <option value="full" <?php echo selected( $pp['initial_sync_mode'], 'full', false ); ?>><?php esc_html_e( 'Volledige geschiedenis', 'eme-accounting' ); ?></option>
        </select>
        <p class="description"><?php esc_html_e( 'De keuze geldt bij de eerste synchronisatie of wanneer je hieronder de positie reset. De geschiedenis wordt in pagina\'s van 100 opgehaald.', 'eme-accounting' ); ?></p>
        <p>
            <label>
                <input type="checkbox" name="peppol_reset_sync" value="1">
                <?php esc_html_e( 'Synchronisatiepositie resetten en bovenstaande keuze opnieuw toepassen', 'eme-accounting' ); ?>
            </label>
        </p>
        <p class="description">
            <?php if ( ! empty( $pp['cursor_created_at'] ) ): ?>
                <?php
                printf(
                    /* translators: %s: cursor date */
                    esc_html__( 'Huidige positie: documenten vanaf %s.', 'eme-accounting' ),
                    esc_html( eme_localized_datetime( $pp['cursor_created_at'] . 'UTC', EME_TIMEZONE ) )
                );
                ?>
            <?php else: ?>
                <?php esc_html_e( 'Nog geen synchronisatiepositie ingesteld.', 'eme-accounting' ); ?>
            <?php endif; ?>
            <?php if ( empty( $pp['backfill_done'] ) && (int) $pp['backfill_page'] > 0 ): ?>
                <br>
                <?php
                printf(
                    /* translators: %d: page number */
                    esc_html__( 'Geschiedenis wordt opgehaald (volgende pagina: %d).', 'eme-accounting' ),
                    (int) $pp['backfill_page']
                );
                ?>
            <?php endif; ?>
        </p>
    </td>
</tr>
<tr>
    <th><?php esc_html_e( 'Webhook-URL overschrijven', 'eme-accounting' ); ?></th>
    <td>
        <input type="url" name="peppol_webhook_url_override" value="<?php echo esc_attr( $pp['webhook_url_override'] ); ?>" style="min-width:420px"
               placeholder="<?php echo esc_attr( EMEAccounting\PeppolEInvoice::webhook_url() ); ?>">
        <p class="description"><?php esc_html_e( 'Laat leeg om de standaard REST-URL van deze site te gebruiken. Enkel nodig wanneer de site achter een proxy of alternatief domein draait.', 'eme-accounting' ); ?></p>
        <p><code><?php echo esc_html( EMEAccounting\PeppolEInvoice::webhook_url() ); ?></code></p>
    </td>
</tr>
<tr>
    <th><?php esc_html_e( 'Webhook', 'eme-accounting' ); ?></th>
    <td>
        <span class="eme-acc-tag <?php echo ! empty( $einvoice_status['webhook_configured'] ) ? 'eme-acc-reconciled' : 'eme-acc-pending'; ?>">
            <?php echo ! empty( $einvoice_status['webhook_configured'] ) ? esc_html__( 'Actief', 'eme-accounting' ) : esc_html__( 'Niet geconfigureerd', 'eme-accounting' ); ?>
        </span>
    </td>
</tr>
</table>

<p>
    <button type="submit" class="button button-primary"><?php esc_html_e( 'Peppol-instellingen opslaan', 'eme-accounting' ); ?></button>
    <button type="button" id="peppol-api-test" class="button"><?php esc_html_e( 'Verbinding testen', 'eme-accounting' ); ?></button>
    <button type="button" id="peppol-api-sync" class="button" <?php echo empty( $einvoice_status['api_configured'] ) ? 'disabled' : ''; ?>>
        <?php esc_html_e( 'Nu synchroniseren', 'eme-accounting' ); ?>
    </button>
    <span id="peppol-api-test-result" style="margin-left:1em;font-size:13px;color:#646970"></span>
    <span id="peppol-api-sync-result" style="margin-left:1em;font-size:13px;color:#646970">
        <?php if ( ! empty( $einvoice_status['last_poll_at'] ) ): ?>
            <?php
            printf(
                /* translators: %s: date */
                esc_html__( 'Laatste synchronisatie: %s', 'eme-accounting' ),
                esc_html( eme_localized_datetime( $einvoice_status['last_poll_at'] . 'UTC', EME_TIMEZONE ) )
            );
            ?>
        <?php endif; ?>
    </span>
</p>
</details>

<details class="eme-acc-form-box">
<summary><b><?php esc_html_e( 'E-mailbox koppeling', 'eme-accounting' ); ?></b>
    <span class="eme-acc-tag <?php echo $imap_configured ? 'eme-acc-reconciled' : 'eme-acc-pending'; ?>">
        <?php echo $imap_configured ? esc_html__( 'Geconfigureerd', 'eme-accounting' ) : esc_html__( 'Niet geconfigureerd', 'eme-accounting' ); ?>
    </span>
</summary>
<?php if ( empty( $imap_status['available'] ) ): ?>
    <div class="notice notice-warning inline"><p><?php esc_html_e( 'De interne IMAP-client is niet beschikbaar op deze server.', 'eme-accounting' ); ?></p></div>
<?php endif; ?>
<table class="form-table">
<tr>
    <th><?php esc_html_e( 'Mailbox inschakelen', 'eme-accounting' ); ?></th>
    <td>
        <label>
            <input type="checkbox" name="peppol_imap_enabled" value="1" <?php echo checked( 1, (int) $pp['imap_enabled'], false ); ?>>
            <?php esc_html_e( 'Elk uur XML-bijlagen uit de mailbox ophalen', 'eme-accounting' ); ?>
        </label>
        <p class="description"><?php esc_html_e( 'Gebruik een aparte mailbox waarin de Peppol-facturen toekomen (UBL-XML inhoud is verplicht, optioneel met PDF).', 'eme-accounting' ); ?></p>
    </td>
</tr>
<tr>
    <th><?php esc_html_e( 'IMAP-server', 'eme-accounting' ); ?></th>
    <td>
        <input type="text" name="peppol_imap_host" value="<?php echo esc_attr( $pp['imap_host'] ); ?>" style="min-width:260px" placeholder="imap.example.be">
        <input type="number" name="peppol_imap_port" value="<?php echo esc_attr( (string) $pp['imap_port'] ); ?>" min="1" max="65535" style="width:90px">
        <select name="peppol_imap_encryption">
            <option value="ssl"  <?php echo selected( $pp['imap_encryption'], 'ssl', false ); ?>>SSL</option>
            <option value="tls"  <?php echo selected( $pp['imap_encryption'], 'tls', false ); ?>>STARTTLS</option>
            <option value="none" <?php echo selected( $pp['imap_encryption'], 'none', false ); ?>><?php esc_html_e( 'Geen', 'eme-accounting' ); ?></option>
        </select>
    </td>
</tr>
<tr>
    <th><?php esc_html_e( 'Gebruiker', 'eme-accounting' ); ?></th>
    <td>
        <input type="text" name="peppol_imap_username" value="<?php echo esc_attr( $pp['imap_username'] ); ?>" style="min-width:320px" autocomplete="username">
    </td>
</tr>
<tr>
    <th><?php esc_html_e( 'Wachtwoord', 'eme-accounting' ); ?></th>
    <td>
        <?php if ( $pp['imap_password'] !== '' ): ?>
            <p><code><?php echo esc_html( $masked_imap ); ?></code></p>
            <label>
                <input type="checkbox" name="peppol_imap_clear_password" value="1">
                <?php esc_html_e( 'Wachtwoord verwijderen', 'eme-accounting' ); ?>
            </label>
            <p class="description"><?php esc_html_e( 'Laat het veld hieronder leeg om het bestaande wachtwoord te bewaren.', 'eme-accounting' ); ?></p>
        <?php endif; ?>
        <input type="password" name="peppol_imap_password" value="" autocomplete="new-password" style="min-width:320px"
               placeholder="<?php echo $pp['imap_password'] !== '' ? esc_attr__( 'Nieuw wachtwoord (optioneel)', 'eme-accounting' ) : esc_attr__( 'IMAP-wachtwoord', 'eme-accounting' ); ?>">
        <p class="description"><?php esc_html_e( 'Het wachtwoord wordt niet in de pagina getoond en nooit gelogd.', 'eme-accounting' ); ?></p>
    </td>
</tr>
<tr>
    <th><?php esc_html_e( 'Map', 'eme-accounting' ); ?></th>
    <td>
        <input type="text" name="peppol_imap_folder" value="<?php echo esc_attr( $pp['imap_folder'] ); ?>" style="min-width:220px" placeholder="INBOX">
    </td>
</tr>
<tr>
    <th><?php esc_html_e( 'Verwerking', 'eme-accounting' ); ?></th>
    <td>
        <p>
            <label>
                <?php esc_html_e( 'Maximaal aantal berichten per synchronisatie:', 'eme-accounting' ); ?>
                <input type="number" name="peppol_imap_max_messages" value="<?php echo esc_attr( (string) $pp['imap_max_messages'] ); ?>" min="1" max="100" style="width:80px">
            </label>
        </p>
        <p class="description"><?php esc_html_e( 'Enkel berichten met een UBL-XML-bijlage worden geïmporteerd. Dubbels worden herkend aan de UBL-UUID.', 'eme-accounting' ); ?></p>
        <p>
            <label>
                <input type="checkbox" name="peppol_imap_delete_after_parse" value="1" <?php echo checked( 1, (int) $pp['imap_delete_after_parse'], false ); ?>>
                <?php esc_html_e( 'Berichten verwijderen uit de mailbox na succesvolle verwerking', 'eme-accounting' ); ?>
            </label>
        </p>
        <p class="description"><?php esc_html_e( 'Zonder deze optie wordt de mailbox enkel gelezen en worden berichten van de laatste periode (dubbel zo lang als sinds de vorige controle) opnieuw bekeken, zodat een eenmalige fout vanzelf wordt hersteld. Met deze optie worden enkel berichten verwijderd waarvan alle UBL-XML-bijlagen geïmporteerd zijn; bij fouten of zonder XML blijven ze staan. Verwijderde berichten zijn niet terug te halen.', 'eme-accounting' ); ?></p>
    </td>
</tr>
</table>

<p>
    <button type="submit" class="button button-primary"><?php esc_html_e( 'Peppol-instellingen opslaan', 'eme-accounting' ); ?></button>
    <button type="button" id="peppol-mailbox-test" class="button" <?php echo empty( $imap_status['available'] ) ? 'disabled' : ''; ?>><?php esc_html_e( 'Mailbox testen', 'eme-accounting' ); ?></button>
    <button type="button" id="peppol-mailbox-sync" class="button" <?php echo empty( $imap_status['available'] ) || empty( $imap_status['configured'] ) ? 'disabled' : ''; ?>><?php esc_html_e( 'Mailbox nu synchroniseren', 'eme-accounting' ); ?></button>
    <span id="peppol-mailbox-test-result" style="margin-left:1em;font-size:13px;color:#646970"></span>
    <span id="peppol-mailbox-sync-result" style="margin-left:1em;font-size:13px;color:#646970"></span>
</p>
<?php if ( $imap_status['last_poll_at'] !== '' ): ?>
    <p class="description">
        <?php
        printf(
            /* translators: 1: date, 2: status */
            esc_html__( 'Laatste mailbox-synchronisatie: %1$s — %2$s', 'eme-accounting' ),
            esc_html( eme_localized_datetime( $imap_status['last_poll_at'] . 'UTC', EME_TIMEZONE ) ),
            esc_html( $imap_status['last_poll_status'] )
        );
        ?>
    </p>
<?php endif; ?>
</details>
</form>

<?php elseif ( $active_tab === 'advanced' ): ?> <!-- tab advanced -->
<div class="eme-acc-form-box">
<h2><?php esc_html_e( 'Herbereken account totals', 'eme-accounting' ); ?></h2>
<form method="post">
    <?php wp_nonce_field( 'emeacc_rebuild_totals', 'emeacc_rebuild_totals_nonce' ); ?>
    <p><?php esc_html_e( 'Herbereken de totals tabel vanuit het journaal. Gebruik dit enkel na directe DB-wijzigingen of bij vermoeden van inconsistentie.', 'eme-accounting' ); ?></p>
    <input type="submit" name="emeacc_rebuild_totals" class="button button-secondary"
           value="<?php esc_attr_e( 'Herbereken totals', 'eme-accounting' ); ?>">
</form>
<hr>
<form method="post">
    <?php wp_nonce_field( 'emeacc_weekly_rebuild', 'emeacc_weekly_rebuild_nonce' ); ?>
    <p>
        <label>
            <input type="checkbox" name="weekly_rebuild_totals" value="1" <?php echo checked( 1, $settings['weekly_rebuild_totals'] ); ?>>
            <?php esc_html_e( 'Automatisch wekelijks herberekenen (zondagnacht 03:00)', 'eme-accounting' ); ?>
        </label>
    </p>
    <p class="description"><?php esc_html_e( 'Vangt drift op die niet via een gefaalde query loopt (bv. rechtstreekse DB-wijzigingen). Standaard uit.', 'eme-accounting' ); ?></p>
    <input type="submit" name="submit" class="button button-secondary"
           value="<?php esc_attr_e( 'Instelling opslaan', 'eme-accounting' ); ?>">
</form>
</div>

<?php endif; ?>
</div><!-- .wrap -->
