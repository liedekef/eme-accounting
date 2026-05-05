<?php
defined( 'ABSPATH' ) || exit;

$formatter = new NumberFormatter( 'nl_BE', NumberFormatter::CURRENCY );
$config    = \EMEVzw\Config::get();
$currency  = $config->currency;

$year         = isset( $_GET['year'] ) ? (int) $_GET['year'] : (int) gmdate( 'Y' );
$budget_map   = EMEAccounting\Budget::get_year_map( $year );
$budget_data  = EMEAccounting\Budget::get_budget_data( $year );
$fy           = EMEAccounting\FiscalYear::get( $year );
$is_closed    = $fy && $fy->status === 'closed';
$fiscal_years = EMEAccounting\FiscalYear::get_all();

$income_rows  = $budget_data['income'];
$expense_rows = $budget_data['expenses'];
$totals       = $budget_data['totals'];

$prev_years = array_filter( $fiscal_years, fn( $f ) => $f->year < $year );

$fmt = fn( float $v ) => $formatter->formatCurrency( $v, $currency );
?>
<div class="wrap eme-acc-wrap">
<fieldset <?= $form_disabled ?>>
<h1><?php esc_html_e( 'Begroting', 'eme-accounting' ); ?> <?= $year ?></h1>

<?php if ( $is_closed ): ?>
<div class="notice notice-warning">
    <p><?php esc_html_e( 'Dit boekjaar is afgesloten. Begroting kan niet meer aangepast worden.', 'eme-accounting' ); ?></p>
</div>
<?php endif; ?>

<!-- Boekjaar selector en acties -->
<div style="display:flex;align-items:center;gap:12px;margin-bottom:20px;flex-wrap:wrap">
    <label><strong><?php esc_html_e( 'Boekjaar:', 'eme-accounting' ); ?></strong>
    <select id="eme-acc-year-select" onchange="window.location='?page=emeaccounting-budget&year='+this.value">
        <?php foreach ( $fiscal_years as $f ): ?>
        <option value="<?= (int) $f->year ?>" <?= selected( $f->year, $year, false ) ?>>
            <?= (int) $f->year ?>
            <?php if ( $f->status === 'closed' ): ?>(<?php esc_html_e( 'gesloten', 'eme-accounting' ); ?>)<?php endif; ?>
        </option>
        <?php endforeach; ?>
    </select>
    </label>

    <?php if ( $prev_years ): ?>
    <label style="margin-left:12px"><strong><?php esc_html_e( 'Kopieer resultaten van:', 'eme-accounting' ); ?></strong>
    <select id="eme-acc-copy-year">
        <option value="">— <?php esc_html_e( 'Kies jaar', 'eme-accounting' ); ?> —</option>
        <?php foreach ( $prev_years as $y ): ?>
        <option value="<?= $y->year ?>"><?= $y->year ?>
            <?php if ( $y->status === 'closed' ): ?>(<?php esc_html_e( 'gesloten', 'eme-accounting' ); ?>)<?php endif; ?>
        </option>
        <?php endforeach; ?>
    </select>
    <span id="copy-status" style="margin-left:8px;color:#646970"></span>
    </label>
    <?php endif; ?>

    <button type="button" class="button button-primary btn-budget-export-pdf" data-year="<?= $year ?>">
        <?php esc_html_e( 'Download PDF', 'eme-accounting' ); ?>
    </button>
</div>

<form id="eme-acc-budget-form">
<?php foreach ( [ 'in' => $income_rows, 'out' => $expense_rows ] as $dir => $rows ): ?>
<?php
$total_budget = $dir === 'in' ? $totals['budget_in'] : $totals['budget_out'];
$total_actual = $dir === 'in' ? $totals['actual_in'] : $totals['actual_out'];
$total_var    = $dir === 'in' ? $total_actual - $total_budget : $total_budget - $total_actual;
?>
<div class="eme-acc-form-box">
<h2><?= $dir === 'in' ? esc_html__( 'Ontvangsten', 'eme-accounting' ) : esc_html__( 'Uitgaven', 'eme-accounting' ) ?></h2>
<table class="eme-acc-table">
<thead><tr>
    <th><?php esc_html_e( 'Rekening', 'eme-accounting' ); ?></th>
    <th class="amount"><?php esc_html_e( 'Begroot', 'eme-accounting' ); ?></th>
    <th class="amount"><?php esc_html_e( 'Werkelijk', 'eme-accounting' ); ?></th>
    <th class="amount"><?php esc_html_e( 'Verschil', 'eme-accounting' ); ?></th>
    <th><?php esc_html_e( 'Voortgang', 'eme-accounting' ); ?></th>
</tr></thead>
<tbody>
<?php foreach ( $rows as $row ):
    $pct     = $row['budget'] > 0 ? min( 150, (int) ( $row['actual'] / $row['budget'] * 100 ) ) : 0;
    $var_cls = $row['variance'] >= 0 ? 'green' : 'red';
    $over    = $pct > 100;
?>
<tr data-coa-id="<?= $row['coa_id'] ?>">
    <td>
        <?= esc_html( $row['code'] . ' - ' . $row['name'] ) ?>
        <input type="hidden" name="lines[<?= $row['coa_id'] ?>][coa_id]" value="<?= $row['coa_id'] ?>">
    </td>
    <td>
        <input type="text" name="lines[<?= $row['coa_id'] ?>][budgeted_amount]"
               value="<?= $row['budget'] > 0 ? number_format( $row['budget'], 2, ',', '.' ) : '' ?>"
               inputmode="decimal"
               oninput="this.value=this.value.replace(/[^0-9,.]/g,'');"
               style="width:120px"
               data-orig="<?= $row['budget'] > 0 ? number_format( $row['budget'], 2, ',', '.' ) : '' ?>"
               <?= $is_closed ? 'disabled' : '' ?>>
    </td>
    <td class="amount"><?= $fmt( $row['actual'] ) ?></td>
    <td class="amount <?= $var_cls ?>"><?= $fmt( $row['variance'] ) ?></td>
    <td>
        <div class="budget-bar-wrap">
            <div class="budget-bar-fill <?= $over ? 'over' : '' ?>" style="width:<?= min( 100, $pct ) ?>%"></div>
        </div>
        <span style="font-size:11px;margin-left:4px"><?= $pct ?>%</span>
    </td>
</tr>
<?php endforeach; ?>
</tbody>
<tfoot>
<tr style="font-weight:bold;border-top:2px solid #ccc">
    <td><?= $dir === 'in' ? esc_html__( 'Totaal ontvangsten', 'eme-accounting' ) : esc_html__( 'Totaal uitgaven', 'eme-accounting' ) ?></td>
    <td class="amount"><?= $fmt( $total_budget ) ?></td>
    <td class="amount"><?= $fmt( $total_actual ) ?></td>
    <td class="amount <?= $total_var >= 0 ? 'green' : 'red' ?>"><?= $fmt( $total_var ) ?></td>
    <td></td>
</tr>
</tfoot>
</table>
</div>
<?php endforeach; ?>

<!-- Netto saldo -->
<?php
$saldo_diff = $totals['net_actual'] - $totals['net_budget'];
?>
<div class="eme-acc-form-box" style="padding:12px 16px">
<table class="eme-acc-table" style="margin:0">
<thead><tr>
    <th></th>
    <th class="amount"><?php esc_html_e( 'Begroot saldo', 'eme-accounting' ); ?></th>
    <th class="amount"><?php esc_html_e( 'Werkelijk saldo', 'eme-accounting' ); ?></th>
    <th class="amount"><?php esc_html_e( 'Verschil', 'eme-accounting' ); ?></th>
</tr></thead>
<tbody>
<tr style="font-weight:bold">
    <td><?php esc_html_e( 'Totalen', 'eme-accounting' ); ?></td>
    <td class="amount <?= $totals['net_budget'] >= 0 ? 'green' : 'red' ?>"><?= $fmt( $totals['net_budget'] ) ?></td>
    <td class="amount <?= $totals['net_actual'] >= 0 ? 'green' : 'red' ?>"><?= $fmt( $totals['net_actual'] ) ?></td>
    <td class="amount <?= $saldo_diff >= 0 ? 'green' : 'red' ?>"><?= $fmt( $saldo_diff ) ?></td>
</tr>
</tbody>
</table>
</div>

<input type="hidden" name="fiscal_year" value="<?= $year ?>">
<p>
    <button type="submit" class="button button-primary submit-btn" <?= $is_closed ? 'disabled' : '' ?>>
        <?php esc_html_e( 'Opslaan', 'eme-accounting' ); ?>
    </button>
    <button type="button" class="button eme-acc-delete-budget" data-year="<?= $year ?>"
            style="margin-left:8px;color:#d63638;border-color:#d63638">
        <?php esc_html_e( 'Verwijder deze begroting', 'eme-accounting' ); ?>
    </button>
</p>
</form>

<!-- ══════════════════════════════════════════════════════════════════
     OVERZICHT ALLE BOEKJAREN
     ══════════════════════════════════════════════════════════════════ -->
<?php
$all_budget_years = EMEAccounting\Budget::get_all_years_summary();
if ( $all_budget_years ):
    $fmtSimple = fn( float $v ) => number_format( $v, 2, ',', '.' ) . ' €';
?>
<h2 style="margin-top:28px"><?php esc_html_e( 'Overzicht per boekjaar', 'eme-accounting' ); ?></h2>
<table class="eme-acc-pub-table widefat">
    <thead><tr>
        <th><?php esc_html_e( 'Boekjaar', 'eme-accounting' ); ?></th>
        <th><?php esc_html_e( 'Begroot ontvangsten', 'eme-accounting' ); ?></th>
        <th><?php esc_html_e( 'Begroot uitgaven', 'eme-accounting' ); ?></th>
        <th><?php esc_html_e( 'Begroot saldo', 'eme-accounting' ); ?></th>
        <th><?php esc_html_e( 'Acties', 'eme-accounting' ); ?></th>
    </tr></thead>
    <tbody>
    <?php foreach ( $all_budget_years as $row ): ?>
    <tr>
        <td><strong><?= (int) $row->fiscal_year ?></strong></td>
        <td><?= esc_html( $fmtSimple( $row->total_in ) ) ?></td>
        <td><?= esc_html( $fmtSimple( $row->total_out ) ) ?></td>
        <td style="<?= $row->saldo < 0 ? 'color:#d63638;font-weight:600' : '' ?>">
            <?= esc_html( $fmtSimple( $row->saldo ) ) ?>
        </td>
        <td>
            <a href="<?= esc_url( admin_url( 'admin.php?page=emeaccounting-budget&year=' . (int) $row->fiscal_year ) ) ?>"
               class="button button-small"><?php esc_html_e( 'Bewerk', 'eme-accounting' ); ?></a>
            <button type="button" class="button button-small btn-budget-export-pdf"
                    data-year="<?= (int) $row->fiscal_year ?>"><?php esc_html_e( 'PDF', 'eme-accounting' ); ?></button>
            <button type="button" class="button button-small eme-acc-delete-budget"
                    data-year="<?= (int) $row->fiscal_year ?>">✕</button>
        </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>

</fieldset>
</div>
