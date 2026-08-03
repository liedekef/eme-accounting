<?php
// phpcs:ignoreFile WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
/**
 * PDF template: Staat van Ontvangsten en Uitgaven
 * Variabelen: $data (income_expenditure record), $config (vzw config), $year (int)
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

  /* Genormaliseerd schema */
  table.schema-table { width: 100%; border-collapse: collapse; margin-bottom: 6pt; font-size: 8.5pt; }
  table.schema-table th.header-cell {
      font-weight: bold; padding: 4pt 5pt; text-align: left; border: 1px solid #aaa;
      font-size: 9pt; letter-spacing: 0.5pt;
  }
  table.schema-table td { padding: 3pt 8pt; border: 1px solid #ccc; vertical-align: top; }
  table.schema-table td.amount { text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }
  table.schema-table tr.section-gap td { height: 8pt; border: none; background: #fff; }
  table.schema-table tr.sub-header td {
      background: #f5f5f5; font-weight: bold; font-size: 8pt;
      text-transform: uppercase; letter-spacing: 0.3pt; padding: 3pt 6pt;
      border-bottom: 1px solid #ccc;
  }

  .net-box { background: #eef3ff; border: 1.5px solid #3a5fc8; padding: 5pt 8pt;
             display: flex; justify-content: space-between; align-items: center;
             margin-top: 8pt; font-size: 10pt; font-weight: bold; }
  .net-box.negative { background: #fff0f0; border-color: #d63638; color: #d63638; }

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

    <div class="doc-title">Boekjaar <?php echo esc_html( $year ); ?> Staat van Ontvangsten en Uitgaven
    </div>

    <?php
    // Normalize: both sides need the same number of rows for the two-column layout.
    $income_items   = $data['income']   ?? [];
    $expense_items  = $data['expenses'] ?? [];
    $totals         = $data['totals']   ?? [];
    $total_income   = (float) ( $totals['income']   ?? 0 );
    $total_expenses = (float) ( $totals['expenses'] ?? 0 );
    $result         = (float) ( $totals['result']   ?? 0 );

    $row_count = max( count( $income_items ), count( $expense_items ) );

    $income_keys   = array_keys( $income_items );
    $income_vals   = array_values( $income_items );
    $expense_keys  = array_keys( $expense_items );
    $expense_vals  = array_values( $expense_items );

    $formatter = new NumberFormatter('nl_BE',  NumberFormatter::CURRENCY);
    //$fmt = new NumberFormatter( 'nl_BE', NumberFormatter::DECIMAL );
    //$fmt->setAttribute( NumberFormatter::MIN_FRACTION_DIGITS, 2 );
    //$fmt->setAttribute( NumberFormatter::MAX_FRACTION_DIGITS, 2 );
    ?>

    <div class="doc-section">
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
        <?php for ( $i = 0; $i < $row_count; $i++ ): ?>
            <tr>
                <td><?php echo isset( $income_keys[ $i ] ) ? esc_html( $income_keys[ $i ] ) : ''; ?></td>
                <td class="amount"><?php echo esc_html( isset( $income_vals[ $i ] ) ? $formatter->formatCurrency( $income_vals[ $i ], $currency) : '' ); ?></td>
                <td><?php echo isset( $expense_keys[ $i ] ) ? esc_html( $expense_keys[ $i ] ) : ''; ?></td>
                <td class="amount"><?php echo esc_html( isset( $expense_vals[ $i ] ) ? $formatter->formatCurrency( $expense_vals[ $i ], $currency  ) : '' ); ?></td>
            </tr>
        <?php endfor; ?>
            <tr class="section-gap"><td colspan="4"></td></tr>
            <tr style="font-weight:bold; background:#f0f0f0;">
                <td>Totaal ontvangsten</td>
                <td class="amount"><?php echo esc_html( $formatter->formatCurrency( $total_income, $currency ) ); ?></td>
                <td>Totaal uitgaven</td>
                <td class="amount"><?php echo esc_html( $formatter->formatCurrency( $total_expenses, $currency ) ); ?></td>
            </tr>
        </tbody>
    </table>
    </div>

    <table class="schema-table" style="margin-top:4pt;">
        <tbody>
            <tr style="font-weight:bold; background:<?php echo $result >= 0 ? '#eef3ff' : '#fff0f0'; ?>;">
                <td style="width:50%; border:1.5px solid <?php echo $result >= 0 ? '#3a5fc8' : '#d63638'; ?>;">
                    <?php echo $result >= 0 ? 'Overschot van het boekjaar' : 'Tekort van het boekjaar'; ?>
                </td>
                <td class="amount" style="width:50%; border:1.5px solid <?php echo $result >= 0 ? '#3a5fc8' : '#d63638'; ?>; color:<?php echo $result >= 0 ? '#1a3a8f' : '#d63638'; ?>; font-size:10pt;">
                    <?php echo esc_html( $formatter->formatCurrency( abs( $result ), $currency ) ); ?>
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
