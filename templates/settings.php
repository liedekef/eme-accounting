<?php
defined( 'ABSPATH' ) || exit;

if ( isset( $_POST['eme_accounting_settings_nonce'] )
     && wp_verify_nonce( $_POST['eme_accounting_settings_nonce'], 'eme_accounting_settings' ) ) {
    $settings = [
        'default_income_account'  => (int)    ( $_POST['default_income_account']  ?? 0 ),
        'default_expense_account' => (int)    ( $_POST['default_expense_account'] ?? 0 ),
        'default_fiscal_year'     => (int)    ( $_POST['default_fiscal_year']     ?? (int) gmdate( 'Y' ) ),
        'currency'                => strtoupper( sanitize_text_field( $_POST['currency'] ?? 'EUR' ) ),
        'delete_on_uninstall'     => sanitize_key( $_POST['delete_on_uninstall'] ?? 'no' ),
        'eme_auto_booking'        => (int) (bool) ( $_POST['eme_auto_booking'] ?? 0 ),
        'default_coa'             => (int)    ( $_POST['default_coa'] ?? 0 ),
    ];
    if ($settings['eme_auto_booking'] && !$settings['default_coa']) {
        echo '<div class="notice notice-error"><p>' . esc_html__( 'Kies een resultatenrekening aub.', 'eme-accounting' ) . '</p></div>';
        return;
    }
    update_option( 'eme_accounting_settings', $settings );
    update_option( 'eme_accounting_delete_on_uninstall', $settings['delete_on_uninstall'] );
    echo '<div class="notice notice-success"><p>' . esc_html__( 'Instellingen opgeslagen.', 'eme-accounting' ) . '</p></div>';
}

$settings = wp_parse_args( get_option( 'eme_accounting_settings', [] ), [
    'default_income_account'  => 0,
    'default_expense_account' => 0,
    'default_fiscal_year'     => (int) gmdate( 'Y' ),
    'currency'                => 'EUR',
    'delete_on_uninstall'     => 'no',
    'default_coa'             => 0,
    'eme_auto_booking'        => 0,
] );
$accounts = EMEAccounting\Accounts::get_dropdown();
$coa_accounts = EMEAccounting\ChartOfAccounts::get_dropdown();
?>
<div class="wrap eme-acc-wrap">
<h1><?php esc_html_e( 'Instellingen – EME Boekhouding', 'eme-accounting' ); ?></h1>
<form method="post">
<?php wp_nonce_field( 'eme_accounting_settings', 'eme_accounting_settings_nonce' ); ?>

<div class="eme-acc-form-box">
<h2><?php esc_html_e( 'Standaardinstellingen', 'eme-accounting' ); ?></h2>
<table class="form-table">
<tr>
    <th><?php esc_html_e( 'Standaard ontvangstrekening', 'eme-accounting' ); ?></th>
    <td>
        <select name="default_income_account">
            <option value="0"><?php esc_html_e( '— geen —', 'eme-accounting' ); ?></option>
            <?php foreach ( $accounts as $id => $name ): ?>
            <option value="<?= $id ?>" <?= selected( $id, $settings['default_income_account'], false ) ?>><?= esc_html( $name ) ?></option>
            <?php endforeach; ?>
        </select>
        <p class="description"><?php esc_html_e( 'Wordt gebruikt voor automatische boekingen van EME-betalingen.', 'eme-accounting' ); ?></p>
    </td>
</tr>
<tr>
    <th><?php esc_html_e( 'Standaard uitgavenrekening', 'eme-accounting' ); ?></th>
    <td>
        <select name="default_expense_account">
            <option value="0"><?php esc_html_e( '— geen —', 'eme-accounting' ); ?></option>
            <?php foreach ( $accounts as $id => $name ): ?>
            <option value="<?= $id ?>" <?= selected( $id, $settings['default_expense_account'], false ) ?>><?= esc_html( $name ) ?></option>
            <?php endforeach; ?>
        </select>
    </td>
</tr>
<tr>
    <th><?php esc_html_e( 'Standaard boekjaar', 'eme-accounting' ); ?></th>
    <td><input type="number" name="default_fiscal_year" value="<?= (int) $settings['default_fiscal_year'] ?>" min="2000" max="2099"></td>
</tr>
<tr>
    <th><?php esc_html_e( 'Valuta', 'eme-accounting' ); ?></th>
    <td>
        <input type="text" name="currency" value="<?= esc_attr( $settings['currency'] ) ?>" maxlength="3" style="width:60px">
        <p class="description"><?php esc_html_e( 'ISO-valutacode, bv. EUR', 'eme-accounting' ); ?></p>
    </td>
</tr>
</table>
</div>

<div class="eme-acc-form-box">
<h2><?php esc_html_e( 'Integratie met Events Made Easy', 'eme-accounting' ); ?></h2>
<table class="form-table">
<tr>
    <th><?php esc_html_e( 'Automatisch boeken bij betaling', 'eme-accounting' ); ?></th>
    <td>
        <label>
            <input type="checkbox" name="eme_auto_booking" value="1" <?= checked( 1, $settings['eme_auto_booking'] ) ?>>
            <?php esc_html_e( 'Maak automatisch een journaalboeking aan wanneer een EME-betaling als betaald wordt gemarkeerd.', 'eme-accounting' ); ?>
        </label>
        <p class="description"><?php esc_html_e( 'Vereist een standaard ontvangstrekening (hierboven) en een standaard resultatenrekening (hieronder).', 'eme-accounting' ); ?></p>
    </td>
</tr>
<tr>
    <th><?php esc_html_e( 'Standaard resultatenrekening', 'eme-accounting' ); ?></th>
    <td>
        <select name="default_coa">
           <option value='0'><?php esc_html_e( '— Kies ontvangstenrekening —', 'eme-accounting' ); ?></option>
            <?php foreach ( $coa_accounts as $id => $acc ):
                     if ( $acc && $acc->direction === 'in' ):
            ?>
                         <option value="<?= (int) $id ?>" <?= selected( $edit_entry->coa_id ?? '', $id, false ) ?> <?php if (empty($acc->parent_id)) echo 'disabled'; ?> >
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
</div>
