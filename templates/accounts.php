<?php // eme-accounting/templates/accounts.php
defined( 'ABSPATH' ) || exit;
$accounts = EMEAccounting\Accounts::get_all();
$types = [ 'asset' => __( 'Actief', 'eme-accounting' ), 'liability' => __( 'Passief', 'eme-accounting' ), 'equity' => __( 'Eigen vermogen', 'eme-accounting' ), 'income' => __( 'Ontvangst', 'eme-accounting' ), 'expense' => __( 'Uitgave', 'eme-accounting' ) ];
?>
<div class="wrap eme-acc-wrap">
<h1><?php esc_html_e( 'Financiële Rekeningen', 'eme-accounting' ); ?></h1>
<div class="eme-acc-form-box">
<h2><?php esc_html_e( 'Rekening toevoegen / bewerken', 'eme-accounting' ); ?></h2>
<form id="eme-acc-account-form">
<input type="hidden" name="id" value="0">
<div class="eme-acc-row">
    <div class="eme-acc-field"><label><?php esc_html_e( 'Naam', 'eme-accounting' ); ?> *</label><input type="text" name="name" required maxlength="150"></div>
    <div class="eme-acc-field"><label><?php esc_html_e( 'Type', 'eme-accounting' ); ?></label>
        <select name="type">
            <?php foreach ( $types as $v => $l ): ?>
            <option value="<?= esc_attr( $v ) ?>"><?= esc_html( $l ) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
</div>
<div class="eme-acc-row">
    <div class="eme-acc-field"><label><?php esc_html_e( 'IBAN', 'eme-accounting' ); ?></label><input type="text" name="iban" maxlength="34" placeholder="BE68 5390 0754 7034"></div>
    <div class="eme-acc-field"><label><?php esc_html_e( 'BIC', 'eme-accounting' ); ?></label><input type="text" name="bic" maxlength="11"></div>
    <div class="eme-acc-field"><label><?php esc_html_e( 'Beginsaldo (€)', 'eme-accounting' ); ?></label><input type="number" name="opening_balance" step="0.01" value="0.00"></div>
    <div class="eme-acc-field"><label><?php esc_html_e( 'Volgorde', 'eme-accounting' ); ?></label><input type="number" name="sort_order" value="0" min="0"></div>
</div>
<div class="eme-acc-row">
    <div class="eme-acc-field"><label><?php esc_html_e( 'Omschrijving', 'eme-accounting' ); ?></label><textarea name="description"></textarea></div>
</div>
<p><button type="submit" class="button button-primary submit-btn"><?php esc_html_e( 'Opslaan', 'eme-accounting' ); ?></button></p>
</form>
</div>

<table class="eme-acc-table">
<thead><tr>
    <th><?php esc_html_e( 'Naam', 'eme-accounting' ); ?></th>
    <th><?php esc_html_e( 'Type', 'eme-accounting' ); ?></th>
    <th><?php esc_html_e( 'IBAN', 'eme-accounting' ); ?></th>
    <th class="amount"><?php esc_html_e( 'Huidig saldo', 'eme-accounting' ); ?></th>
    <th><?php esc_html_e( 'Acties', 'eme-accounting' ); ?></th>
</tr></thead>
<tbody>
<?php foreach ( $accounts as $a ):
    $balance = EMEAccounting\Accounts::get_balance( (int) $a->id );
?>
<tr data-account-row="<?= (int) $a->id ?>">
    <td><strong><?= esc_html( $a->name ) ?></strong><?= $a->is_active ? '' : ' <span class="tag">' . esc_html__( 'inactief', 'eme-accounting' ) . '</span>' ?></td>
    <td><?= esc_html( $types[ $a->type ] ?? $a->type ) ?></td>
    <td><?= esc_html( $a->iban ) ?></td>
    <td class="amount <?= $balance >= 0 ? '' : 'negative' ?>"><?= number_format( $balance, 2, ',', '.' ) ?> €</td>
    <td>
        <a href="<?= esc_url( admin_url( 'admin.php?page=eme-accounting-journal&account_id=' . $a->id ) ) ?>" class="button button-small"><?php esc_html_e( 'Boekingen', 'eme-accounting' ); ?></a>
        <button class="button button-small eme-acc-delete-account" data-id="<?= (int) $a->id ?>"><?php esc_html_e( 'Verwijder', 'eme-accounting' ); ?></button>
    </td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
