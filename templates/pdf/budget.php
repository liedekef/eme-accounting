<?php
// phpcs:ignoreFile WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
defined( 'ABSPATH' ) || exit;

$vzw_name   = $config->vzw_name ?? '';
$vzw_form   = $config->vzw_legal_short_form ?? 'VZW';
$vzw_number = $config->vzw_number ?? '';
$street     = trim( ( $config->street ?? '' ) . ' ' . ( $config->house_number ?? '' ) );
if (!empty($config->bus)) {
    $street .= ' bus ' . $config->house_bus;
}
$postal     = trim( ( $config->postal_code ?? '' ) . ' ' . ( $config->city ?? '' ) );
$website    = $config->website ?? '';
$email      = $config->email ?? '';
$court      = $config->court ?? '';
$currency   = $config->currency;

$year = $year ?? date( 'Y' );
$fmt = new NumberFormatter( 'nl_BE', NumberFormatter::CURRENCY );

$vertegenwoordigers = EMEVzw\Config::get_legal_representatives();

?>
<style>
  * { box-sizing: border-box; }
  body { font-family: Helvetica, Arial, sans-serif; font-size: 9pt; color: #000; margin: 0; padding: 0; }

  .page { width: 190mm; margin: 0 auto; padding: 10mm 0; }

  h1.vzw-title { font-size: 12pt; font-weight: bold; margin: 0 0 2pt; text-align: center; }
  .vzw-subtitle { font-size: 9pt; color: #333; margin: 0 0 8pt; text-align: center; }

  .doc-title { font-size: 11pt; font-weight: bold; text-transform: uppercase;
               border-bottom: 2px solid #000; padding-bottom: 3pt; margin: 10pt 0 8pt; text-align: center; }

  .doc-section { page-break-inside: avoid; }

  h2.section-title { font-size: 10pt; font-weight: bold; padding-bottom: 2pt; margin: 12pt 0 6pt;
                   text-align: center; background: #1a1a2e; color: #fff; }

  p { margin: 0 0 5pt; line-height: 1.5; }

  table.schema-table { width: 100%; border-collapse: collapse; margin-bottom: 6pt; font-size: 8.5pt; }
  table.schema-table th.header-cell {
      font-weight: bold; padding: 4pt 5pt; text-align: left; border: 1px solid #aaa;
      font-size: 9pt; letter-spacing: 0.5pt;
  }
  table.schema-table td { padding: 3pt 8pt; border: 1px solid #ccc; vertical-align: top; }
  table.schema-table td.amount { text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }
  table.schema-table tr.sub-header td {
      background: #f5f5f5; font-weight: bold; font-size: 8pt;
      text-transform: uppercase; letter-spacing: 0.3pt; padding: 3pt 6pt;
      border-bottom: 1px solid #ccc;
  }
  table.schema-table tr.total-row td {
      font-weight: bold; background: #f0f0f0; border-top: 1px solid #000;
  }

  .balance-positive { color: #3a5fc8; }
  .balance-negative { color: #d63638; }

  .signature-block { margin-top: 20pt; }
  .signature-table { width: 100%; border-collapse: collapse; }
  .signature-table td { vertical-align: bottom; padding: 0 12pt 0 0; width: 50%; }
  .signature-table td:last-child { padding-right: 0; }
  .sig-label { font-size: 8.5pt; color: #555; margin-bottom: 3pt; }
  .sig-line { border-bottom: 1px solid #000; height: 30pt; margin-bottom: 3pt; }
  .sig-identity { font-size: 8pt; color: #666; font-style: italic; }

</style>

<div class="page">

    <!-- HOOFDING -->
    <h1 class="vzw-title"><?php echo esc_html( $vzw_name ); ?> <?php echo esc_html( $vzw_form ); ?></h1>
    <p class="vzw-subtitle">
        <?php echo esc_html( $street ); ?>, <?php echo esc_html( $postal ); ?><br>
        Ondernemingsnr. <?php echo esc_html( $vzw_number ); ?>
    </p>
    <p class="vzw-subtitle">
        <?php echo esc_url( $website ); ?> | <?php echo esc_html( $email ); ?><br>
        <?php echo esc_html( $court ); ?>
    </p>

    <div class="doc-title">Boekjaar <?php echo esc_html( $year ); ?> Begroting
    </div>

    <?php
    $income_rows  = $data['income']    ?? [];
    $expense_rows = $data['expenses']  ?? [];
    $totals       = $data['totals']    ?? [];
    $total_budget_in  = $totals['budget_in']  ?? 0;
    $total_actual_in  = $totals['actual_in']  ?? 0;
    $total_budget_out = $totals['budget_out'] ?? 0;
    $total_actual_out = $totals['actual_out'] ?? 0;
    $net_budget = $totals['net_budget'] ?? 0;
    $net_actual = $totals['net_actual'] ?? 0;
    ?>

    <div class="doc-section">
    <h2 class="section-title">Ontvangsten</h2>
    <table class="schema-table">
        <thead>
            <tr>
                <th style="width:50%">Rekening</th>
                <th style="width:15%;text-align:right">Begroot</th>
                <th style="width:15%;text-align:right">Werkelijk</th>
                <th style="width:20%;text-align:right">Verschil</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ( $income_rows as $row ): ?>
            <tr>
                <td><?php echo esc_html( $row['code'] . ' - ' . $row['name'] ); ?></td>
                <td class="amount"><?php echo esc_html( $row['budget'] > 0 ? $fmt->formatCurrency( $row['budget'], $currency ) : '' ); ?></td>
                <td class="amount"><?php echo esc_html( $fmt->formatCurrency( $row['actual'], $currency ) ); ?></td>
                <td class="amount <?php echo $row['variance'] >= 0 ? 'balance-positive' : 'balance-negative'; ?>">
                    <?php echo esc_html( $fmt->formatCurrency( $row['variance'], $currency ) ); ?>
                </td>
            </tr>
        <?php endforeach; ?>
            <tr class="total-row">
                <td>Totaal Ontvangsten</td>
                <td class="amount"><?php echo esc_html( $fmt->formatCurrency( $total_budget_in, $currency ) ); ?></td>
                <td class="amount"><?php echo esc_html( $fmt->formatCurrency( $total_actual_in, $currency ) ); ?></td>
                <td class="amount <?php echo ( $total_actual_in - $total_budget_in ) >= 0 ? 'balance-positive' : 'balance-negative'; ?>">
                    <?php echo esc_html( $fmt->formatCurrency( $total_actual_in - $total_budget_in, $currency ) ); ?>
                </td>
            </tr>
        </tbody>
    </table>
    </div>

    <div class="doc-section">
    <h2 class="section-title">Uitgaven</h2>
    <table class="schema-table">
        <thead>
            <tr>
                <th style="width:50%">Rekening</th>
                <th style="width:15%;text-align:right">Begroot</th>
                <th style="width:15%;text-align:right">Werkelijk</th>
                <th style="width:20%;text-align:right">Verschil</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ( $expense_rows as $row ): ?>
            <tr>
                <td><?php echo esc_html( $row['code'] . ' - ' . $row['name'] ); ?></td>
                <td class="amount"><?php echo esc_html( $row['budget'] > 0 ? $fmt->formatCurrency( $row['budget'], $currency ) : '' ); ?></td>
                <td class="amount"><?php echo esc_html( $fmt->formatCurrency( $row['actual'], $currency ) ); ?></td>
                <td class="amount <?php echo $row['variance'] >= 0 ? 'balance-positive' : 'balance-negative'; ?>">
                    <?php echo esc_html( $fmt->formatCurrency( $row['variance'], $currency ) ); ?>
                </td>
            </tr>
        <?php endforeach; ?>
            <tr class="total-row">
                <td>Totaal Uitgaven</td>
                <td class="amount"><?php echo esc_html( $fmt->formatCurrency( $total_budget_out, $currency ) ); ?></td>
                <td class="amount"><?php echo esc_html( $fmt->formatCurrency( $total_actual_out, $currency ) ); ?></td>
                <td class="amount <?php echo ( $total_budget_out - $total_actual_out ) >= 0 ? 'balance-positive' : 'balance-negative'; ?>">
                    <?php echo esc_html( $fmt->formatCurrency( $total_budget_out - $total_actual_out, $currency ) ); ?>
                </td>
            </tr>
        </tbody>
    </table>
    </div>

<table class="schema-table" style="margin-top:8pt;">
<thead><tr>
    <th style="width:50%;text-align:right;"></th>
    <th style="width:15%;text-align:right;"><?php esc_html_e( 'Begroot saldo', 'eme-accounting' ); ?></th>
    <th style="width:15%;text-align:right;"><?php esc_html_e( 'Werkelijk saldo', 'eme-accounting' ); ?></th>
    <th style="width:20%;text-align:right;"><?php esc_html_e( 'Verschil', 'eme-accounting' ); ?></th>
</tr></thead>
<tbody>
<tr class="total-row">
    <td><?php esc_html_e( 'Totalen', 'eme-accounting' ); ?></td>
    <td class="amount <?php echo $net_budget >= 0 ? 'balance-positive' : 'balance-negative'; ?>">
          <?php echo esc_html( $fmt->formatCurrency( $net_budget, $currency ) ); ?>
    </td>
    <td class="amount <?php echo $net_actual >= 0 ? 'balance-positive' : 'balance-negative'; ?>">
          <?php echo esc_html( $fmt->formatCurrency( $net_actual, $currency ) ); ?>
    </td>
    <td class="amount <?php echo $net_actual-$net_budget >= 0 ? 'balance-positive' : 'balance-negative'; ?>">
          <?php echo esc_html( $fmt->formatCurrency( $net_actual-$net_budget, $currency ) ); ?>
    </td>
</tr>
</tbody>
</table>

    <!-- HANDTEKENINGEN -->
    <div class="signature-block">
        <table class="signature-table">
            <?php if ( ! empty( $vertegenwoordigers ) ): ?>
                <?php foreach ( array_chunk( $vertegenwoordigers, 2 ) as $vertegenwoordigers_per2 ): ?>
                <tr>
                    <?php foreach ( $vertegenwoordigers_per2 as $v ): ?>
                    <td>
                        <div class="sig-identity">
                            <?php echo esc_html( $v->first_name . ' ' . $v->last_name ); ?><br>
                            <em><?php echo esc_html( EMEVzw\Config::legal_capacity_label( $v->legal_capacity ?? 'bestuurder' ) ); ?><?php echo ! empty( $v->function_title ) ? ' · ' . esc_html( EMEVzw\Config::function_title_label( $v->function_title ) ) : ''; ?></em>
                        </div>
                        <div class="sig-label">Handtekening</div>
                        <div class="sig-line"></div>
                    </td>
                    <?php endforeach; ?>
                    <?php if ( count( $vertegenwoordigers_per2 ) === 1 ): ?>
                    <td></td>
                    <?php endif; ?>
                </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td>
                        <div class="sig-label">Handtekening (identiteit/hoedanigheid vertegenwoordiger)</div>
                        <div class="sig-line"></div>
                    </td>
                    <td></td>
                </tr>
            <?php endif; ?>
        </table>
    </div>

</div>
