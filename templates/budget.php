<?php
defined( 'ABSPATH' ) || exit;

$formatter = new NumberFormatter('nl_BE',  NumberFormatter::CURRENCY);
$config =  \EMEVzw\Config::get();
$currency  = $config->currency;

$year        = isset( $_GET['year'] ) ? (int) $_GET['year'] : (int) gmdate( 'Y' );
$coa        = EMEAccounting\ChartOfAccounts::get_all();
$budget_map = EMEAccounting\Budget::get_year_map( $year );
$fy         = EMEAccounting\FiscalYear::get( $year );
$is_closed  = $fy && $fy->status === 'closed';

$fiscal_years = EMEAccounting\FiscalYear::get_all();
$prev_years = [];
foreach ( $fiscal_years as $f ) {
    if ( $f->year < $year ) {
        $prev_years[] = $f;
    }
}
?>
<div class="wrap eme-acc-wrap">
<fieldset <?= $form_disabled ?>>
<h1><?php esc_html_e( 'Begroting', 'eme-accounting' ); ?> <?= $year ?></h1>

<?php if ( $is_closed ): ?>
<div class="notice notice-warning">
    <p><?php esc_html_e( 'Dit boekjaar is afgesloten. Begroting kan niet meer aangepast worden.', 'eme-accounting' ); ?></p>
</div>
<?php endif; ?>

<p>
    <label><?php esc_html_e( 'Boekjaar:', 'eme-accounting' ); ?></label>
    <select id="eme-acc-year-select" onchange="window.location='?page=emeaccounting-budget&year='+this.value">
        <?php foreach ( $fiscal_years as $f ): ?>
        <option value="<?= (int) $f->year ?>" <?= selected( $f->year, $year, false ) ?>>
            <?= (int) $f->year ?>
            <?php if ( $f->status === 'closed' ): ?>
                (<?php esc_html_e( 'gesloten', 'eme-accounting' ); ?>)
            <?php endif; ?>
        </option>
        <?php endforeach; ?>
    </select>

    <label style="margin-left:20px"><?php esc_html_e( 'Kopieer resultaten van:', 'eme-accounting' ); ?></label>
    <select id="eme-acc-copy-year">
        <option value="">— <?php esc_html_e( 'Kies jaar', 'eme-accounting' ); ?> —</option>
        <?php foreach ( $prev_years as $y ): ?>
        <option value="<?= $y->year ?>"><?= $y->year ?>
            <?php if ( $y->status === 'closed' ): ?>
                (<?php esc_html_e( 'gesloten', 'eme-accounting' ); ?>)
            <?php endif; ?>
        </option>
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
    if (empty($c->is_active)) continue;

    $key = $c->id;
    $budget = $budget_map[ $key ] ?? 0;
    $actual  = EMEAccounting\Budget::get_actual_for_coa( $year, $c->id );
    if ($dir === 'out') {
        $variance = $budget - $actual;
    } else {
        $variance = $actual - $budget;
    }
    $pct  = $budget > 0 ? min( 150, (int) ( $actual / $budget * 100 ) ) : 0;
    if ($variance<0) {
        $var_class="red";
        $over = $pct > 100;
    } else {
        $var_class="green";
        $over = false;
    }
?>
<tr data-coa-id="<?= (int) $c->id ?>">
    <td>
        <?php if ($c->parent_id)
                echo esc_html( '&nbsp;&nbsp;&nbsp;'.$c->code . ' - ' . $c->name );
              else
                echo esc_html( $c->code . ' - ' . $c->name );
        ?>
        <input type="hidden" name="lines[<?= $c->id ?>][coa_id]" value="<?= (int) $c->id ?>">
    </td>
    <td>
        <?php if ($c->parent_id): ?>
        <input type="text" name="lines[<?= $c->id ?>][budgeted_amount]" 
               value="<?= $budget > 0 ? number_format( (float) $budget, 2, ',', '.' ) : '' ?>" 
               inputmode="decimal"
               oninput="this.value=this.value.replace(/[^0-9,.]/g,'');"
               style="width:120px"
               data-orig="<?= $budget > 0 ? number_format( (float) $budget, 2, ',', '.' ) : '' ?>"
               <?= $is_closed ? 'disabled' : '' ?>>
        <?php endif; ?>
    </td>
    <td><?php if ($c->parent_id) echo $formatter->formatCurrency($actual, $currency); ?> </td>
    <td class="amount <?= $var_class ?>"><?php if ($c->parent_id) echo $formatter->formatCurrency($variance, $currency); ?></td>
    <td><?php if ($c->parent_id): ?>
        <div class="budget-bar-wrap">
            <div class="budget-bar-fill <?= $over ? 'over' : '' ?>" style="width:<?= min( 100, $pct ) ?>%"></div>
        </div>
        <span style="font-size:11px;margin-left:4px"><?= $pct ?>%</span>
        <?php endif; ?>
    </td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<?php endforeach; ?>
<input type="hidden" name="fiscal_year" value="<?= $year ?>">
<p><button type="submit" class="button button-primary submit-btn" <?= $is_closed ? 'disabled' : '' ?>><?php esc_html_e( 'Opslaan', 'eme-accounting' ); ?></button></p>
</form>
</fieldset>
</div>
