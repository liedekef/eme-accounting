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

<?php else: ?> <!-- tab advanced -->
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
