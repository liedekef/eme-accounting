<?php
defined( 'ABSPATH' ) || exit;
$year        = isset( $_GET['year'] ) ? (int) $_GET['year'] : (int) gmdate( 'Y' );
$coa        = EMEAccounting\ChartOfAccounts::get_all();
$budget_map = EMEAccounting\Budget::get_year_map( $year );
$prev_years = range( $year - 5, $year - 1 );
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

    <label style="margin-left:20px"><?php esc_html_e( 'Kopieer resultaten van:', 'eme-accounting' ); ?></label>
    <select id="eme-acc-copy-year">
        <option value="">— <?php esc_html_e( 'Kies jaar', 'eme-accounting' ); ?> —</option>
        <?php foreach ( $prev_years as $y ): ?>
        <option value="<?= $y ?>"><?= $y ?></option>
        <?php endforeach; ?>
    </select>
    <span id="copy-status" style="margin-left:8px;color:#646970"></span>
</p>

<?php // Group by direction: IN first, then OUT ?>
<form id="eme-acc-budget-form">
<?php foreach ( [ 'in', 'out' ] as $dir ): ?>
<div class="eme-acc-form-box">
<h2><?php esc_html_e( $dir === 'in' ? 'Ontvangsten' : 'Uitgaven', 'eme-accounting' ); ?></h2>
<table class="eme-acc-table">
<thead><tr>
    <th><?php esc_html_e( 'Rekening', 'eme-accounting' ); ?></th>
    <th class="amount"><?php esc_html_e( 'Begroot', 'eme-accounting' ); ?></th>
    <th class="amount"><?php esc_html_e( 'Werkelijk', 'eme-accounting' ); ?></th>
    <th class="amount"><?php esc_html_e( 'Verschil', 'eme-accounting' ); ?></th>
    <th><?php esc_html_e( 'Voortgang', 'eme-accounting' ); ?></th>
</tr></thead>
<tbody>
<?php foreach ( $coa as $c ):
    if ( $c->direction !== $dir ) continue;

    $key = $c->id;
    $budget = $budget_map[ $key ] ?? 0;
    $actual  = EMEAccounting\Budget::get_actual_for_coa( $year, $c->id );
    $variance = $budget - $actual;
    $pct     = $budget > 0 ? min( 150, (int) ( $actual / $budget * 100 ) ) : 0;
    $over    = $pct > 100;
?>
<tr data-coa-id="<?= (int) $c->id ?>">
    <td>
        <?= esc_html( $c->code . ' - ' . $c->name ) ?>
        <input type="hidden" name="lines[<?= $c->id ?>][coa_id]" value="<?= (int) $c->id ?>">
    </td>
    <td>
        <input type="number" name="lines[<?= $c->id ?>][budgeted_amount]" 
               value="<?= $budget > 0 ? $budget : '' ?>" 
               step="0.01" min="0" style="width:120px"
               data-orig="<?= $budget > 0 ? $budget : '' ?>">
    </td>
    <td class="amount <?= $dir ?>"><?= number_format( $actual, 2, ',', '.' ) ?> EUR</td>
    <td class="amount <?= $dir ?>"><?= number_format( $variance, 2, ',', '.' ) ?> EUR</td>
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
</div>
<?php endforeach; ?>
<input type="hidden" name="fiscal_year" value="<?= $year ?>">
<p><button type="submit" class="button button-primary submit-btn"><?php esc_html_e( 'Opslaan', 'eme-accounting' ); ?></button></p>
</form>
</div>
