<?php // eme-accounting/templates/accounts.php
defined( 'ABSPATH' ) || exit;
$accounts = EMEAccounting\Accounts::get_all();
$formatter = new NumberFormatter('nl_BE',  NumberFormatter::CURRENCY);

?>
<div class="wrap eme-acc-wrap">
<h1><?php esc_html_e( 'Financiële Rekeningen', 'eme-accounting' ); ?></h1>
<div class="eme-acc-form-box">
<h2 id="eme-acc-account-form-title"><?php esc_html_e( 'Rekening toevoegen', 'eme-accounting' ); ?></h2>
<form id="eme-acc-account-form">
<input type="hidden" name="id" value="0">
<input type="hidden" name="type" value="asset">
<div class="eme-acc-row">
    <div class="eme-acc-field" style="flex:3"><label><?php esc_html_e( 'Naam', 'eme-accounting' ); ?> *</label><input type="text" name="name" required maxlength="150"></div>
    <div class="eme-acc-field"><label><?php esc_html_e( 'Volgorde', 'eme-accounting' ); ?></label><input type="number" name="sort_order" value="0" min="0"></div>
    <div class="eme-acc-field"><label><?php esc_html_e( 'Actief', 'eme-accounting' ); ?></label>
        <select name="is_active"><option value="1"><?php esc_html_e( 'Ja', 'eme-accounting' ); ?></option><option value="0"><?php esc_html_e( 'Nee', 'eme-accounting' ); ?></option></select>
    </div>
</div>
<div class="eme-acc-row">
    <div class="eme-acc-field"><label><?php esc_html_e( 'IBAN', 'eme-accounting' ); ?></label><input type="text" name="iban" maxlength="34" placeholder="BE68 5390 0754 7034"></div>
    <div class="eme-acc-field"><label><?php esc_html_e( 'BIC', 'eme-accounting' ); ?></label><input type="text" name="bic" maxlength="11"></div>
    <div class="eme-acc-field"><label><?php esc_html_e( 'Beginsaldo (€)', 'eme-accounting' ); ?></label><input type="number" name="opening_balance" step="0.01" value="0.00"></div>
</div>
<div class="eme-acc-row">
    <div class="eme-acc-field"><label><?php esc_html_e( 'Omschrijving', 'eme-accounting' ); ?></label><textarea name="description"></textarea></div>
</div>
<p>
    <button type="submit" class="button button-primary submit-btn"><?php esc_html_e( 'Opslaan', 'eme-accounting' ); ?></button>
    <button type="button" id="eme-acc-account-cancel" class="button" style="display:none"><?php esc_html_e( 'Annuleren', 'eme-accounting' ); ?></button>
</p>
</form>
</div>

<table class="eme-acc-table">
<thead><tr>
    <th><?php esc_html_e( 'Naam', 'eme-accounting' ); ?></th>
    <th><?php esc_html_e( 'IBAN', 'eme-accounting' ); ?></th>
    <th class="amount"><?php esc_html_e( 'Huidig saldo', 'eme-accounting' ); ?></th>
    <th><?php esc_html_e( 'Acties', 'eme-accounting' ); ?></th>
</tr></thead>
<tbody>
<?php foreach ( $accounts as $a ):
    $balance = EMEAccounting\Accounts::get_balance( (int) $a->id );
?>
<tr data-account-row="<?= (int) $a->id ?>">
    <td><strong><?= esc_html( $a->name ) ?></strong><?= $a->is_active ? '' : ' <span class="eme-acc-tag">' . esc_html__( 'inactief', 'eme-accounting' ) . '</span>' ?></td>
    <td><?= esc_html( $a->iban ) ?></td>
    <td class="amount <?= $balance >= 0 ? '' : 'negative' ?>"><?= $formatter->formatCurrency( $balance, 'EUR') ?></td>
    <td>
        <button class="button button-small eme-acc-edit-account"
                data-id="<?= (int) $a->id ?>"
                data-name="<?= esc_attr( $a->name ) ?>"
                data-iban="<?= esc_attr( $a->iban ) ?>"
                data-bic="<?= esc_attr( $a->bic ) ?>"
                data-opening-balance="<?= esc_attr( $a->opening_balance ) ?>"
                data-sort-order="<?= (int) $a->sort_order ?>"
                data-is-active="<?= (int) $a->is_active ?>"
                data-description="<?= esc_attr( $a->description ?? '' ) ?>"><?php esc_html_e( 'Bewerk', 'eme-accounting' ); ?></button>
        <a href="<?= esc_url( admin_url( 'admin.php?page=eme-accounting-journal&account_id=' . $a->id ) ) ?>" class="button button-small"><?php esc_html_e( 'Boekingen', 'eme-accounting' ); ?></a>
        <button class="button button-small eme-acc-delete-account" data-id="<?= (int) $a->id ?>"><?php esc_html_e( 'Verwijder', 'eme-accounting' ); ?></button>
    </td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
