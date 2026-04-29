<?php
defined( 'ABSPATH' ) || exit;

use EMEAccounting\Capabilities;

$config = \EMEVzw\Config::get();
$eme_categories = eme_get_categories();

// ── Handle: general settings ────────────────────────────────────────────────
if ( isset( $_POST['eme_accounting_settings_nonce'] )
     && wp_verify_nonce( $_POST['eme_accounting_settings_nonce'], 'eme_accounting_settings' ) ) {
    $settings = [
        'default_income_account' => (int)    ( $_POST['default_income_account']  ?? 0 ),
        'delete_on_uninstall'    => sanitize_key( $_POST['delete_on_uninstall'] ?? 'no' ),
        'eme_scope'              => eme_sanitize_request( $_POST['eme_scope'] ?? '-3m--12m' ),
        'eme_cat_ids'            => eme_sanitize_request( $_POST['eme_cat_ids'] ?? [] ),
        'eme_exclude_cat_ids'    => eme_sanitize_request( $_POST['eme_exclude_cat_ids'] ?? [] ),
        'eme_auto_booking'       => (int) (bool) ( $_POST['eme_auto_booking'] ?? 0 ),
        'default_coa'            => (int)    ( $_POST['default_coa'] ?? 0 ),
    ];
    if ( $settings['eme_auto_booking'] && ! $settings['default_coa'] ) {
        echo '<div class="notice notice-error"><p>' . esc_html__( 'Kies een resultatenrekening aub.', 'eme-accounting' ) . '</p></div>';
    } else {
        update_option( 'eme_accounting_settings', $settings );
        update_option( 'eme_accounting_delete_on_uninstall', $settings['delete_on_uninstall'] );
        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Instellingen opgeslagen.', 'eme-accounting' ) . '</p></div>';
    }
}

// ── Handle: access matrix ───────────────────────────────────────────────────
if ( isset( $_POST['emeacc_access_nonce'] ) ) {
    Capabilities::handle_save();
    echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Toegangsrechten opgeslagen.', 'eme-accounting' ) . '</p></div>';
}

$settings = wp_parse_args( get_option( 'eme_accounting_settings', [] ), [
    'default_income_account' => 0,
    'delete_on_uninstall'    => 'no',
    'default_coa'            => 0,
    'eme_auto_booking'       => 0,
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
    <a href="<?= esc_url( add_query_arg( 'tab', 'general' ) ) ?>"
       class="nav-tab <?= $active_tab === 'general' ? 'nav-tab-active' : '' ?>">
        <?php esc_html_e( 'Algemeen', 'eme-accounting' ); ?>
    </a>
    <a href="<?= esc_url( add_query_arg( 'tab', 'access' ) ) ?>"
       class="nav-tab <?= $active_tab === 'access' ? 'nav-tab-active' : '' ?>">
        <?php esc_html_e( 'Toegangsbeheer', 'eme-accounting' ); ?>
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
    <td><b><?= $config->fiscal_year_start_dd . ' ' . date_i18n( 'F', mktime( 0, 0, 0, $config->fiscal_year_start_mm, 1 ) ) ?></b>
        <br><?php esc_html_e( '(aan te passen in de VZW instellingen)', 'eme-accounting' ); ?>
    </td>
</tr>
<tr>
    <th><?php esc_html_e( 'Boekjaar einde', 'eme-accounting' ); ?></th>
    <td><b><?= $config->fiscal_year_end_dd . ' ' . date_i18n( 'F', mktime( 0, 0, 0, $config->fiscal_year_end_mm, 1 ) ) ?></b>
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
            <input type="text" name="eme_scope" value="<?= esc_html($settings['eme_scope']) ?>">
            <?php _e( "Definiëer de scope van evenementen die je standaard wil doorzoeken. Default is van 3 maand in het verleden tot 12 maand in de toekomst. Voor alle mogelijkheden, zie <a href='https://www.e-dynamics.be/wordpress/eme-docs/eme_events/' target='_blank'>de Events Made Easy documentatie</a>", 'eme-accounting'); ?>
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
            <?php _e( "Beperk het doorzoeken van evenementen tot bepaalde categorieën", 'eme-accounting'); ?>
    </label>
    <br>
    <br>
    <label>
            <?php
            echo eme_ui_multiselect_key_value( $settings['eme_exclude_cat_ids'], 'eme_exclude_cat_ids', $eme_categories, 'category_id', 'category_name', 3, '', 0, 'eme_snapselect' ); //phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- trusted HTML from eme_ui_multiselect()
            ?>
            <?php _e( "En/of excludeer bepaalde categorieën bij het doorzoeken van evenementen", 'eme-accounting'); ?>
    </label>
    </td>
</tr>
<?php endif; ?>

<tr>
    <th><?php esc_html_e( 'Automatisch boeken bij betaling', 'eme-accounting' ); ?></th>
    <td>
        <label>
            <input type="checkbox" name="eme_auto_booking" value="1" <?= checked( 1, $settings['eme_auto_booking'] ) ?>>
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
            <option value="<?= $id ?>" <?= selected( $id, $settings['default_income_account'], false ) ?>><?= esc_html( $name ) ?></option>
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
                <option value="<?= (int) $id ?>" <?= selected( $settings['default_coa'] ?? '', $id, false ) ?> <?php if ( empty( $acc->parent_id ) ) echo 'disabled'; ?>>
                    <?= esc_html( $acc->code . ' - ' . $acc->name ) ?>
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
    <th><?php esc_html_e( 'Data verwijderen bij deactivatie', 'eme-accounting' ); ?></th>
    <td>
        <select name="delete_on_uninstall">
            <option value="no"  <?= selected( 'no',  $settings['delete_on_uninstall'], false ) ?>><?php esc_html_e( 'Nee – data bewaren (aanbevolen)', 'eme-accounting' ); ?></option>
            <option value="yes" <?= selected( 'yes', $settings['delete_on_uninstall'], false ) ?>><?php esc_html_e( 'Ja – ALLE boekhouddata verwijderen bij uninstall', 'eme-accounting' ); ?></option>
        </select>
        <p class="description" style="color:#d63638"><?php esc_html_e( 'LET OP: "Ja" verwijdert permanent alle rekeningen, boekingen en importdata!', 'eme-accounting' ); ?></p>
    </td>
</tr>
</table>
</div>

<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Instellingen opslaan', 'eme-accounting' ); ?></button></p>
</form>

<?php else: ?>

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

<style>
/* ── Profile table ─────────────────────────────────────── */
.emeacc-role-table { border-collapse: collapse; width: 100%; margin-bottom: 2em; }
.emeacc-role-table th,
.emeacc-role-table td { padding: 10px 14px; border: 1px solid #c3c4c7; vertical-align: middle; }
.emeacc-role-table thead th { background: #f6f7f7; font-weight: 600; }
.emeacc-role-table tbody tr:nth-child(even) { background: #fafafa; }
.emeacc-role-table select { min-width: 190px; }
.emeacc-profile-desc { color: #787c82; font-size: 12px; margin-top: 4px; }
.emeacc-locked { color: #787c82; font-style: italic; }

/* ── Custom detail panels ──────────────────────────────── */
.emeacc-custom-panel { display: none; margin-top: 8px; }
.emeacc-custom-panel.is-open { display: block; }
.emeacc-custom-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
    gap: 6px 16px;
    background: #f6f7f7;
    border: 1px solid #c3c4c7;
    border-radius: 4px;
    padding: 12px 16px;
    margin-top: 6px;
}
.emeacc-custom-grid label {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 13px;
    white-space: nowrap;
}
.emeacc-custom-grid label span { flex: 1; }
.emeacc-custom-grid select { font-size: 12px; }
</style>

<p><?php esc_html_e(
    'Kies per WordPress-rol welk toegangsprofiel van toepassing is. '
    . 'De instellingenpagina is altijd toegankelijk voor beheerders (manage_options) en staat niet in deze lijst.',
    'eme-accounting'
); ?></p>

<form method="post" id="emeacc-access-form">
<?php wp_nonce_field( 'emeacc_access_save', 'emeacc_access_nonce' ); ?>

<table class="emeacc-role-table widefat">
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
    <td><strong><?= esc_html( $role_label ) ?></strong></td>
    <td>
        <?php /* Profile dropdown */ ?>
        <select name="emeacc_profile[<?= esc_attr( $role_slug ) ?>]"
                class="emeacc-profile-select"
                data-role="<?= esc_attr( $role_slug ) ?>"
                style="margin-bottom:4px">
            <?php foreach ( $profiles as $prof_slug => $prof ): ?>
            <option value="<?= esc_attr( $prof_slug ) ?>"
                    data-desc="<?= esc_attr( $prof['description'] ) ?>"
                    <?= selected( $current_prof, $prof_slug, false ) ?>>
                <?= esc_html( $prof['label'] ) ?>
            </option>
            <?php endforeach; ?>
        </select>

        <?php /* Live description line */ ?>
        <div class="emeacc-profile-desc" id="desc-<?= esc_attr( $role_slug ) ?>">
            <?= esc_html( $profiles[ $current_prof ]['description'] ) ?>
        </div>

        <?php /* Hint for roles with manage_options */ ?>
        <?php if ( $has_manage_options ): ?>
        <div class="emeacc-profile-desc" style="color:#b45309; margin-top:2px">
            ⚠️ <?php esc_html_e( 'Deze rol heeft manage_options: de instellingenpagina is altijd toegankelijk.', 'eme-accounting' ); ?>
        </div>
        <?php endif; ?>

        <?php /* Custom detail panel (hidden unless 'custom' selected) */ ?>
        <div class="emeacc-custom-panel <?= $current_prof === 'custom' ? 'is-open' : '' ?>"
             id="custom-<?= esc_attr( $role_slug ) ?>">
            <div class="emeacc-custom-grid">
                <?php foreach ( $pages as $page_slug => $page_label ):
                    $level = $matrix[ $role_slug ][ $page_slug ] ?? 'none';
                ?>
                <label>
                    <span><?= esc_html( $page_label ) ?></span>
                    <select name="emeacc_access[<?= esc_attr( $role_slug ) ?>][<?= esc_attr( $page_slug ) ?>]">
                        <option value="none"   <?= selected( $level, 'none',   false ) ?>><?php esc_html_e( 'Geen',   'eme-accounting' ); ?></option>
                        <option value="view"   <?= selected( $level, 'view',   false ) ?>><?php esc_html_e( 'Lezen',  'eme-accounting' ); ?></option>
                        <option value="manage" <?= selected( $level, 'manage', false ) ?>><?php esc_html_e( 'Beheer', 'eme-accounting' ); ?></option>
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
    <th style="width:180px"><?= esc_html( $prof['label'] ) ?></th>
    <td><?= esc_html( $prof['description'] ) ?>
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
    <th><?= esc_html( $profiles['custom']['label'] ) ?></th>
    <td><?= esc_html( $profiles['custom']['description'] ) ?></td>
</tr>
</table>
</div>

<script>
(function () {
    const descs   = <?= json_encode( array_map( fn($p) => $p['description'], Capabilities::PROFILES ) ) ?>;

    document.querySelectorAll('.emeacc-profile-select').forEach(function (sel) {
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

<?php endif; ?>
</div><!-- .wrap -->
