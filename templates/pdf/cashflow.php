<?php
// phpcs:ignoreFile WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
/**
 * PDF template: Cashflow per maand
 * Variabelen: $data (cashflow array), $config (vzw config), $year (int)
 * Gerenderd via dompdf – liggend A4.
 */
defined( 'ABSPATH' ) || exit;

$formatter  = new NumberFormatter( 'nl_BE', NumberFormatter::CURRENCY );
$currency   = $config->currency;
$vzw_name   = esc_html( $config->vzw_name ?? '' );
$vzw_form   = esc_html( $config->vzw_legal_form ?? 'VZW' );

$month_names = [ '', 'Jan', 'Feb', 'Mrt', 'Apr', 'Mei', 'Jun',
                     'Jul', 'Aug', 'Sep', 'Okt', 'Nov', 'Dec' ];

$cf_fmt = fn( float $n ) => $formatter->formatCurrency( abs( $n ), $currency );
?>
<style>
  * { box-sizing: border-box; }
  body { font-family: Helvetica, Arial, sans-serif; font-size: 7.5pt; color: #000; margin: 0; padding: 0; }
  .page { width: 267mm; margin: 0 auto; padding: 8mm 0; }

  h1.vzw-title  { font-size: 11pt; font-weight: bold; margin: 0 0 2pt; text-align: center; }
  .vzw-subtitle { font-size: 8pt; color: #333; margin: 0 0 6pt; text-align: center; }
  .doc-title    { font-size: 10pt; font-weight: bold; text-transform: uppercase;
                  border-bottom: 2px solid #000; padding-bottom: 3pt; margin: 8pt 0 6pt; text-align: center; }

  table { width: 100%; border-collapse: collapse; }
  th, td { padding: 2pt 3pt; border: 0.5pt solid #ccc; }
  th { background: #1a1a2e; color: #fff; font-size: 7pt; text-align: right; }
  th.col-account { text-align: left; background: #1a1a2e; color: #fff; }
  th.col-type    { text-align: left; font-size: 6.5pt; }

  tr.row-in   td { color: #1a6b1a; }
  tr.row-out  td { color: #b00020; }
  tr.row-net  td { font-weight: bold; border-bottom: 1.5pt solid #999; }
  tr.row-net  td.negative { color: #b00020; }
  tr.row-net  td.positive { color: #1a6b1a; }

  tr.row-total td { background: #f0f0f1; font-weight: bold; border-top: 1.5pt solid #000; }
  tr.row-total td.negative { color: #b00020; }
  tr.row-total td.positive { color: #1a6b1a; }

  td.amount { text-align: right; }
  td.label  { text-align: left; font-size: 6.5pt; color: #555; padding-left: 4pt; }
  td.account-name { font-weight: bold; }

  .footer { margin-top: 8pt; font-size: 7pt; color: #666; text-align: center; }
</style>

<div class="page">
  <h1 class="vzw-title"><?php echo esc_html( $vzw_form ); ?> <?php echo esc_html( $vzw_name ); ?></h1>
  <p class="vzw-subtitle">Boekjaar <?php echo (int) $year; ?></p>
  <div class="doc-title">Cashflow per maand</div>

  <table>
    <thead>
      <tr>
        <th class="col-account" style="width:14%">Rekening</th>
        <th class="col-type" style="width:4%"></th>
        <?php for ( $m = 1; $m <= 12; $m++ ): ?>
        <th style="width:6%"><?php echo esc_html( $month_names[$m] ); ?></th>
        <?php endfor; ?>
        <th style="width:7%">Totaal</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ( $data['accounts'] as $aid => $account_name ):
        $months = $data['data'][ $aid ];
        $acc_in = $acc_out = 0.0;
        for ( $m = 1; $m <= 12; $m++ ) {
            $acc_in  += $months[$m]['in'];
            $acc_out += $months[$m]['out'];
        }
        $acc_net = $acc_in - $acc_out;
    ?>
      <!-- In row -->
      <tr class="row-in">
        <td class="account-name" rowspan="3"><?php echo esc_html( $account_name ); ?></td>
        <td class="label">Ontvangsten</td>
        <?php for ( $m = 1; $m <= 12; $m++ ): ?>
        <td class="amount"><?php echo esc_html( $months[$m]['in'] > 0 ? $cf_fmt( $months[$m]['in'] ) : '' ); ?></td>
        <?php endfor; ?>
        <td class="amount"><?php echo esc_html( $acc_in > 0 ? $cf_fmt( $acc_in ) : '' ); ?></td>
      </tr>
      <!-- Out row -->
      <tr class="row-out">
        <td class="label">Uitgaven</td>
        <?php for ( $m = 1; $m <= 12; $m++ ): ?>
        <td class="amount"><?php echo esc_html( $months[$m]['out'] > 0 ? $cf_fmt( $months[$m]['out'] ) : '' ); ?></td>
        <?php endfor; ?>
        <td class="amount"><?php echo esc_html( $acc_out > 0 ? $cf_fmt( $acc_out ) : '' ); ?></td>
      </tr>
      <!-- Net row -->
      <tr class="row-net">
        <td class="label">Netto</td>
        <?php for ( $m = 1; $m <= 12; $m++ ):
            $net = $months[$m]['net'];
            $cls = $net >= 0 ? 'positive' : 'negative';
        ?>
        <td class="amount <?php echo esc_attr( $cls ); ?>"><?php echo esc_html( $net != 0 ? $cf_fmt( $net ) : '' ); ?></td>
        <?php endfor; ?>
        <td class="amount <?php echo $acc_net >= 0 ? 'positive' : 'negative'; ?>"><?php echo esc_html( $cf_fmt( $acc_net ) ); ?></td>
      </tr>
    <?php endforeach; ?>

    <!-- Grand total row -->
    <?php
    $t = $data['month_totals'];
    $grand = $data['totals'];
    ?>
    <tr class="row-total">
      <td colspan="2">Totaal netto</td>
      <?php for ( $m = 1; $m <= 12; $m++ ):
          $net = $t[$m]['net'];
          $cls = $net >= 0 ? 'positive' : 'negative';
      ?>
      <td class="amount <?php echo esc_attr( $cls ); ?>"><?php echo esc_html( $net != 0 ? $cf_fmt( $net ) : '' ); ?></td>
      <?php endfor; ?>
      <td class="amount <?php echo $grand['net'] >= 0 ? 'positive' : 'negative'; ?>"><?php echo esc_html( $cf_fmt( $grand['net'] ) ); ?></td>
    </tr>
    </tbody>
  </table>

  <p class="footer">Gegenereerd op <?php echo esc_html( date_i18n( 'd/m/Y' ) ); ?></p>
</div>
