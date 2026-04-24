<?php
defined( 'ABSPATH' ) || exit;

$vzw_name   = esc_html( $config->vzw_name ?? '' );
$vzw_form   = esc_html( $config->vzw_legal_form ?? 'VZW' );
$vzw_number = esc_html( $config->vzw_number ?? '' );
$street     = esc_html( trim( ( $config->street ?? '' ) . ' ' . ( $config->house_number ?? '' ) ) );
if ( ! empty( $config->bus ) ) {
    $street .= esc_html( 'bus ' . $config->bus );
}
$postal     = esc_html( trim( ( $config->postal_code ?? '' ) . ' ' . ( $config->city ?? '' ) ) );
$website    = esc_html( $config->website ?? '' );
$email      = esc_html( $config->email ?? '' );
$court      = esc_html( $config->court ?? '' );
$currency   = $config->currency;

$year = $year ?? date( 'Y' );
$fmt = new NumberFormatter( 'nl_BE', NumberFormatter::CURRENCY );
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
    <h1 class="vzw-title"><?= $vzw_name ?> <?= $vzw_form ?></h1>
    <p class="vzw-subtitle">
        <?= $street ?>, <?= $postal ?><br>
        Ondernemingsnr. <?= $vzw_number ?>
    </p>
    <p class="vzw-subtitle">
        <?= $website ?> | <?= $email ?><br>
        <?= $court ?>
    </p>

    <div class="doc-title">Boekjaar <?= $year ?> Begroting
    </div>

    <?php
    $total_budget_in = 0;
    $total_actual_in = 0;
    $total_budget_out = 0;
    $total_actual_out = 0;

    $income_rows = $data['income'] ?? [];
    $expense_rows = $data['expenses'] ?? [];
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
        <?php foreach ( $income_rows as $row ):
            $total_budget_in += $row['budget'];
            $total_actual_in += $row['actual'];
        ?>
            <tr>
                <td><?= esc_html( $row['code'] . ' - ' . $row['name'] ) ?></td>
                <td class="amount"><?= $row['budget'] > 0 ? $fmt->formatCurrency( $row['budget'], $currency ) : '' ?></td>
                <td class="amount"><?= $fmt->formatCurrency( $row['actual'], $currency ) ?></td>
                <td class="amount <?= $row['variance'] >= 0 ? 'balance-positive' : 'balance-negative' ?>">
                    <?= $fmt->formatCurrency( $row['variance'], $currency ) ?>
                </td>
            </tr>
        <?php endforeach; ?>
            <tr class="total-row">
                <td>Totaal Ontvangsten</td>
                <td class="amount"><?= $fmt->formatCurrency( $total_budget_in, $currency ) ?></td>
                <td class="amount"><?= $fmt->formatCurrency( $total_actual_in, $currency ) ?></td>
                <td class="amount <?= ( $total_actual_in - $total_budget_in ) >= 0 ? 'balance-positive' : 'balance-negative' ?>">
                    <?= $fmt->formatCurrency( $total_actual_in - $total_budget_in, $currency ) ?>
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
        <?php foreach ( $expense_rows as $row ):
            $total_budget_out += $row['budget'];
            $total_actual_out += $row['actual'];
        ?>
            <tr>
                <td><?= esc_html( $row['code'] . ' - ' . $row['name'] ) ?></td>
                <td class="amount"><?= $row['budget'] > 0 ? $fmt->formatCurrency( $row['budget'], $currency ) : '' ?></td>
                <td class="amount"><?= $fmt->formatCurrency( $row['actual'], $currency ) ?></td>
                <td class="amount <?= $row['variance'] >= 0 ? 'balance-positive' : 'balance-negative' ?>">
                    <?= $fmt->formatCurrency( $row['variance'], $currency ) ?>
                </td>
            </tr>
        <?php endforeach; ?>
            <tr class="total-row">
                <td>Totaal Uitgaven</td>
                <td class="amount"><?= $fmt->formatCurrency( $total_budget_out, $currency ) ?></td>
                <td class="amount"><?= $fmt->formatCurrency( $total_actual_out, $currency ) ?></td>
                <td class="amount <?= ( $total_budget_out - $total_actual_out ) >= 0 ? 'balance-positive' : 'balance-negative' ?>">
                    <?= $fmt->formatCurrency( $total_budget_out - $total_actual_out, $currency ) ?>
                </td>
            </tr>
        </tbody>
    </table>
    </div>

    <table class="schema-table" style="margin-top:8pt;">
        <tbody>
            <?php
            $net_budget = $total_budget_in - $total_budget_out;
            $net_actual = $total_actual_in - $total_actual_out;
            ?>
            <tr style="font-weight:bold; background:<?= $net_actual >= 0 ? '#eef3ff' : '#fff0f0' ?>;">
                <td style="width:50%; border:1.5px solid <?= $net_actual >= 0 ? '#3a5fc8' : '#d63638' ?>;">
                    <?= $net_actual >= 0 ? 'Saldo (positief)' : 'Saldo (negatief)' ?>
                </td>
                <td style="width:25%; text-align:right; border:1.5px solid <?= $net_actual >= 0 ? '#3a5fc8' : '#d63638' ?>; border-left:none;">
                    <?= $fmt->formatCurrency( $net_actual, $currency ) ?>
                </td>
                <td style="width:25%; border:none;"></td>
            </tr>
        </tbody>
    </table>

    <div class="signature-block">
    <table class="signature-table">
        <tr>
            <td>
                <p class="sig-label">Handtekening penningmeester</p>
                <div class="sig-line"></div>
                <p class="sig-identity">Datum: _______________</p>
            </td>
            <td>
                <p class="sig-label">Handtekening voorzitter</p>
                <div class="sig-line"></div>
                <p class="sig-identity">Datum: _______________</p>
            </td>
        </tr>
    </table>
    </div>

</div>