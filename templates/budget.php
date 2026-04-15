<?php
defined( 'ABSPATH' ) || exit;
$year         = isset( $_GET['year'] ) ? (int) $_GET['year'] : (int) gmdate( 'Y' );
$coa         = EMEAccounting\ChartOfAccounts::get_all();
$comparison  = EMEAccounting\Budget::get_comparison( $year );
$budget_map  = EMEAccounting\Budget::get_year_map( $year );
?>
<div class="wrap eme-acc-wrap">
<h1><?php esc_html_e( 'Begroting', 'eme-accounting' ); ?> <?= $year ?></h1>
<p>
    <label><?php esc_html_e( 'Boekjaar:', 'eme-accounting' ); ?></label>
    <select id="eme-acc-year-select" onchange="window.location='?page=eme-accounting-budget&year='+this.value">
        <?php for ( $y = (int) gmdate( 'Y' ) - 2; $y <= (int) gmdate( 'Y' ) + 1; $y++ ): ?>
        <option value="<?= $y ?>" <?= $y === $year ? 'selected' : '' ?>><?= $y ?></option>
        <?php endfor; ?>
    </select>
</p>
<div class="eme-acc-form-box">
<h2><?php esc_html_e( 'Begrotingslijn toevoegen', 'eme-accounting' ); ?></h2>
<form id="eme-acc-budget-form">
    <div class="eme-acc-row">
        <div class="eme-acc-field">
            <label><?php esc_html_e( 'Rekeningstelsel', 'eme-accounting' ); ?> *</label>
            <select name="coa_id" required>
                <option value=""><?php esc_html_e( '— Kies —', 'eme-accounting' ); ?></option>
                <?php foreach ( $coa as $c ): ?>
                <option value="<?= (int) $c->id ?>"><?= esc_html( $c->code . ' - ' . $c->name ) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="eme-acc-field">
            <label><?php esc_html_e( 'Richting', 'eme-accounting' ); ?></label>
            <select name="direction">
                <option value="in"><?php esc_html_e( 'Ontvangst', 'eme-accounting' ); ?></option>
                <option value="out"><?php esc_html_e( 'Uitgave', 'eme-accounting' ); ?></option>
            </select>
        </div>
        <div class="eme-acc-field">
            <label><?php esc_html_e( 'Begroot bedrag (€)', 'eme-accounting' ); ?></label>
            <input type="number" name="budgeted_amount" step="0.01" min="0">
        </div>
    </div>
    <input type="hidden" name="fiscal_year" value="<?= $year ?>">
    <p><button type="submit" class="button button-primary submit-btn"><?php esc_html_e( 'Opslaan', 'eme-accounting' ); ?></button></p>
</form>
</div>
<?php if ( $comparison ): ?>
<table class="eme-acc-table">
<thead><tr>
    <th><?php esc_html_e( 'Rekeningstelsel', 'eme-accounting' ); ?></th>
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
    $dir_class = $row['direction'] === 'in' ? 'in' : 'out';
?>
<tr>
    <td><?= esc_html( $row['account_name'] ) ?></td>
    <td><span class="tag"><?= $row['direction'] === 'in' ? 'O' : 'U' ?></span></td>
    <td class="amount"><?= number_format( $row['budget'], 2, ',', '.' ) ?> €</td>
    <td class="amount <?= $dir_class ?>"><?= number_format( $row['actual'], 2, ',', '.' ) ?> €</td>
    <td class="amount <?= $dir_class ?>"><?= number_format( $row['variance'], 2, ',', '.' ) ?> €<?php if ( $row['budget'] > 0 ): ?> (<?= $row['variance'] >= 0 ? '+' : '' ?><?= round( $row['variance'] / $row['budget'] * 100, 1 ) ?>%)<?php endif; ?></td>
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
<p><?php esc_html_e( 'Geen begrotingslijnen of boekingen gevonden voor dit boekjaar.', 'eme-accounting' ); ?></p>
<?php endif; ?>
</div>