<?php // eme-accounting/templates/budget.php
defined( 'ABSPATH' ) || exit;
$year       = isset( $_GET['year'] ) ? (int) $_GET['year'] : (int) gmdate( 'Y' );
$accounts   = EMEAccounting\Accounts::get_dropdown();
$comparison = EMEAccounting\Budget::get_comparison( $year );
?>
<div class="wrap eme-acc-wrap">
<h1><?php esc_html_e( 'Begroting', 'eme-accounting' ); ?> <?= $year ?></h1>
<div class="eme-acc-form-box">
<h2><?php esc_html_e( 'Begrotingslijn toevoegen', 'eme-accounting' ); ?></h2>
<form id="eme-acc-budget-form">
<div class="eme-acc-row">
    <div class="eme-acc-field">
        <label><?php esc_html_e( 'Boekjaar', 'eme-accounting' ); ?></label>
        <input type="number" name="fiscal_year" value="<?= $year ?>" min="2000" max="2099">
    </div>
    <div class="eme-acc-field">
        <label><?php esc_html_e( 'Rekening', 'eme-accounting' ); ?> *</label>
        <select name="account_id" required>
            <option value=""><?php esc_html_e( '—', 'eme-accounting' ); ?></option>
            <?php foreach ( $accounts as $id => $name ): ?>
            <option value="<?= $id ?>"><?= esc_html( $name ) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="eme-acc-field">
        <label><?php esc_html_e( 'Categorie', 'eme-accounting' ); ?></label>
        <input type="text" name="category">
    </div>
    <div class="eme-acc-field">
        <label><?php esc_html_e( 'Richting', 'eme-accounting' ); ?></label>
        <select name="direction">
            <option value="in"><?php esc_html_e( 'Ontvangst', 'eme-accounting' ); ?></option>
            <option value="out" selected><?php esc_html_e( 'Uitgave', 'eme-accounting' ); ?></option>
        </select>
    </div>
    <div class="eme-acc-field">
        <label><?php esc_html_e( 'Begroot bedrag (€)', 'eme-accounting' ); ?> *</label>
        <input type="number" name="budgeted_amount" required step="0.01" min="0">
    </div>
</div>
<p><button type="submit" class="button button-primary submit-btn"><?php esc_html_e( 'Opslaan', 'eme-accounting' ); ?></button></p>
</form>
</div>
<?php if ( $comparison ): ?>
<table class="eme-acc-table">
<thead><tr>
    <th><?php esc_html_e( 'Rekening', 'eme-accounting' ); ?></th>
    <th><?php esc_html_e( 'Categorie', 'eme-accounting' ); ?></th>
    <th><?php esc_html_e( 'Dir.', 'eme-accounting' ); ?></th>
    <th class="amount"><?php esc_html_e( 'Begroot', 'eme-accounting' ); ?></th>
    <th class="amount"><?php esc_html_e( 'Werkelijk', 'eme-accounting' ); ?></th>
    <th class="amount"><?php esc_html_e( 'Verschil', 'eme-accounting' ); ?></th>
    <th><?php esc_html_e( 'Voortgang', 'eme-accounting' ); ?></th>
</tr></thead>
<tbody>
<?php foreach ( $comparison as $row ):
    $pct  = $row['budget'] > 0 ? min( 150, (int) ( $row['actual'] / $row['budget'] * 100 ) ) : 0;
    $over = $pct > 100;
?>
<tr>
    <td><?= esc_html( $row['account_name'] ) ?></td>
    <td><?= esc_html( $row['category'] ) ?></td>
    <td><span class="tag"><?= $row['direction'] === 'in' ? 'O' : 'U' ?></span></td>
    <td class="amount"><?= number_format( $row['budget'], 2, ',', '.' ) ?> €</td>
    <td class="amount <?= $row['direction'] === 'in' ? 'in' : 'out' ?>"><?= number_format( $row['actual'], 2, ',', '.' ) ?> €</td>
    <td class="amount <?= $row['variance'] >= 0 ? 'in' : 'out' ?>"><?= number_format( $row['variance'], 2, ',', '.' ) ?> €<?= $row['variance_pct'] !== null ? ' (' . $row['variance_pct'] . '%)' : '' ?></td>
    <td>
        <div class="budget-bar-wrap">
            <div class="budget-bar-fill <?= $over ? 'over' : '' ?>" style="width:<?= min( 100, $pct ) ?>%"></div>
        </div>
        <span style="font-size:11px;margin-left:4px"><?= $pct ?>%</span>
    </td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
<?php else: ?>
<p><?php esc_html_e( 'Geen begrotingslijnen gevonden voor dit boekjaar.', 'eme-accounting' ); ?></p>
<?php endif; ?>
</div>
