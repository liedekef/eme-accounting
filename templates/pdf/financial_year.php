<?php
// phpcs:ignoreFile WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
/**
 * PDF template: Financieel Jaarverslag per rekening
 * Variabelen: $data (financial_year_report record), $config (vzw config), $year (int)
 * Gerenderd via dompdf.
 */
defined( 'ABSPATH' ) || exit;

$vzw_name   = esc_html( $config->vzw_name ?? '' );
$vzw_form   = esc_html( $config->vzw_legal_form ?? 'VZW' );
$vzw_number = esc_html( $config->vzw_number ?? '' );
$street     = esc_html( trim( ( $config->street ?? '' ) . ' ' . ( $config->house_number ?? '' ) ) );
if (!empty($config->bus)) {
    $street .= esc_html('bus '.$config->bus);
}
$postal     = esc_html( trim( ( $config->postal_code ?? '' ) . ' ' . ( $config->city ?? '' ) ) );
$website    = esc_html( $config->website  ?? '' );
$email      = esc_html( $config->email ?? '' );
$court      = esc_html( $config->court ?? '' );
$currency   = $config->currency;

$formatter = new NumberFormatter('nl_BE',  NumberFormatter::CURRENCY);

$accounts = $data['accounts'] ?? [];

$inAccounts = array_filter( $accounts, fn( $a ) => $a['direction'] === 'in' );
$outAccounts = array_filter( $accounts, fn( $a ) => $a['direction'] === 'out' );
?>
<style>
  * { box-sizing: border-box; }
  body { font-family: Helvetica, Arial, sans-serif; font-size: 9pt; color: #000; margin: 0; padding: 0; }

  .page { width: 190mm; margin: 0 auto; padding: 10mm 0; }

  h1.vzw-title { font-size: 12pt; font-weight: bold; margin: 0 0 2pt; text-align: center; }
  .vzw-subtitle { font-size: 9pt; color: #333; margin: 0 0 8pt; text-align: center; }

  .doc-title { font-size: 11pt; font-weight: bold; text-transform: uppercase;
               border-bottom: 2px solid #000; padding-bottom: 3pt; margin: 10pt 0 8pt; text-align: center; }

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

  .net-box { background: #eef3ff; border: 1.5px solid #3a5fc8; padding: 5pt 8pt;
             display: flex; justify-content: space-between; align-items: center;
             margin-top: 8pt; font-size: 10pt; font-weight: bold; }
  .net-box.negative { background: #fff0f0; border-color: #d63638; color: #d63638; }

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

    <div class="doc-title">Boekjaar <?php echo esc_html( $year ); ?> Financieel Jaarverslag per rekening</div>

    <table class="schema-table">
        <thead>
            <tr>
                <th class="header-cell" style="width:44%">Ontvangsten</th>
                <th class="header-cell" style="width:6%; text-align:right"></th>
                <th class="header-cell" style="width:44%">Uitgaven</th>
                <th class="header-cell" style="width:6%; text-align:right"></th>
            </tr>
        </thead>
        <tbody>
        <?php
        $inRows = [];
        $outRows = [];
        $totalIn = 0;
        $totalOut = 0;

        foreach ( $inAccounts as $acc ) {
            $inRows[] = [
                'name'  => $acc['code'] . ' - ' . $acc['name'],
                'total' => $acc['total'],
            ];
            $totalIn += $acc['total'];
            foreach ( $acc['children'] ?? [] as $child ) {
                if ( $child['total'] > 0 ) {
                    $inRows[] = [
                        'name'  => $child['code'] . ' - ' . $child['name'],
                        'total' => $child['total'],
                        'indent' => true,
                    ];
                }
            }
        }

        foreach ( $outAccounts as $acc ) {
            $outRows[] = [
                'name'  => $acc['code'] . ' - ' . $acc['name'],
                'total' => $acc['total'],
            ];
            $totalOut += $acc['total'];
            foreach ( $acc['children'] ?? [] as $child ) {
                if ( $child['total'] > 0 ) {
                    $outRows[] = [
                        'name'  => $child['code'] . ' - ' . $child['name'],
                        'total' => $child['total'],
                        'indent' => true,
                    ];
                }
            }
        }

        $row_count = max( count( $inRows ), count( $outRows ) );
        ?>
        <?php for ( $i = 0; $i < $row_count; $i++ ): ?>
            <tr>
                <td <?php echo isset( $inRows[ $i ] ) && ! empty( $inRows[ $i ]['indent'] ) ? 'style="padding-left:20px"' : ''; ?>>
                    <?php echo isset( $inRows[ $i ] ) ? esc_html( $inRows[ $i ]['name'] ) : ''; ?>
                </td>
                <td class="amount">
                    <?php echo esc_html( isset( $inRows[ $i ] ) ? $formatter->formatCurrency( $inRows[ $i ]['total'], $currency ) : '' ); ?>
                </td>
                <td <?php echo isset( $outRows[ $i ] ) && ! empty( $outRows[ $i ]['indent'] ) ? 'style="padding-left:20px"' : ''; ?>>
                    <?php echo isset( $outRows[ $i ] ) ? esc_html( $outRows[ $i ]['name'] ) : ''; ?>
                </td>
                <td class="amount">
                    <?php echo esc_html( isset( $outRows[ $i ] ) ? $formatter->formatCurrency( $outRows[ $i ]['total'], $currency ) : '' ); ?>
                </td>
            </tr>
        <?php endfor; ?>
            <tr style="font-weight:bold; background:#f0f0f0;">
                <td>Totaal ontvangsten</td>
                <td class="amount"><?php echo esc_html( $formatter->formatCurrency( $totalIn, $currency ) ); ?></td>
                <td>Totaal uitgaven</td>
                <td class="amount"><?php echo esc_html( $formatter->formatCurrency( $totalOut, $currency ) ); ?></td>
            </tr>
        </tbody>
    </table>

    <?php $saldo = $totalIn - $totalOut; ?>
    <table class="schema-table" style="margin-top:4pt;">
        <tbody>
            <tr style="font-weight:bold; background:<?php echo $saldo >= 0 ? '#eef3ff' : '#fff0f0'; ?>;">
                <td style="width:50%; border:1.5px solid <?php echo $saldo >= 0 ? '#3a5fc8' : '#d63638'; ?>;">
                    Saldo
                </td>
                <td class="amount" style="width:50%; border:1.5px solid <?php echo $saldo >= 0 ? '#3a5fc8' : '#d63638'; ?>; color:<?php echo $saldo >= 0 ? '#1a3a8f' : '#d63638'; ?>; font-size:10pt;">
                    <?php echo esc_html( $formatter->formatCurrency( $saldo, $currency ) ); ?>
                </td>
            </tr>
        </tbody>
    </table>

</div>
