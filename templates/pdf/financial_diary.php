<?php
// phpcs:ignoreFile WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
/**
 * PDF template: Financieel Dagboek
 * Variabelen: $data (financial_diary_report record - date => entries), $config (vzw config), $year (int)
 * Gerenderd via dompdf.
 */
defined( 'ABSPATH' ) || exit;

$vzw_name   = $config->vzw_name ?? '';
$vzw_form   = $config->vzw_legal_short_form ?? 'VZW';
$vzw_number = $config->vzw_number ?? '';
$street     = trim( ( $config->street ?? '' ) . ' ' . ( $config->house_number ?? '' ) );
if (!empty($config->bus)) {
    $street .= ' bus ' . $config->house_bus;
}
$postal     = trim( ( $config->postal_code ?? '' ) . ' ' . ( $config->city ?? '' ) );
$website    = $config->website  ?? '';
$email      = $config->email ?? '';
$court      = $config->court ?? '';
$currency   = $config->currency;

$formatter = new NumberFormatter('nl_BE',  NumberFormatter::CURRENCY);

?>
<style>
  * { box-sizing: border-box; }
  body { font-family: Helvetica, Arial, sans-serif; font-size: 9pt; color: #000; margin: 0; padding: 0; }

  .page { width: 190mm; margin: 0 auto; padding: 10mm 0; }

  h1.vzw-title { font-size: 12pt; font-weight: bold; margin: 0 0 2pt; text-align: center; }
  .vzw-subtitle { font-size: 9pt; color: #333; margin: 0 0 8pt; text-align: center; }

  .doc-title { font-size: 11pt; font-weight: bold; text-transform: uppercase;
               border-bottom: 2px solid #000; padding-bottom: 3pt; margin: 10pt 0 8pt; text-align: center; }

  p { margin: 0 0 5pt; line-height: 1.5; }

  table.schema-table { width: 100%; border-collapse: collapse; margin-bottom: 6pt; font-size: 8.5pt; }
  table.schema-table th.header-cell {
      font-weight: bold; padding: 4pt 5pt; text-align: left; border: 1px solid #aaa;
      font-size: 9pt; letter-spacing: 0.5pt; background: #1a1a2e; color: #fff;
  }
  table.schema-table td { padding: 3pt 8pt; border: 1px solid #ccc; vertical-align: top; }
  table.schema-table td.amount { text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }
  table.schema-table td.date-cell { white-space: nowrap; }

  .day-header { font-weight: bold; background: #f5f5f5; padding: 4pt 8pt; margin-top: 8pt;
                border: 1px solid #ccc; border-bottom: none; font-size: 9pt; }
  .day-total { font-weight: bold; background: #eef3ff; padding: 3pt 8pt; border: 1px solid #ccc;
               border-top: none; margin-bottom: 6pt; }
  .day-total.negative { background: #fff0f0; }

  .direction-in { color: #1a3a8f; }
  .direction-out { color: #d63638; }
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

    <div class="doc-title">Boekjaar <?php echo esc_html( $year ); ?> Financieel Dagboek</div>
        <table class="schema-table">
            <thead>
                <tr>
                    <th class="header-cell">Tijd</th>
                    <th class="header-cell">Omschrijving</th>
                    <th class="header-cell">Rekening</th>
                    <th class="header-cell">Bank ref</th>
                    <th class="header-cell">Categorie</th>
                    <th class="header-cell" style="text-align:right;">Bedrag</th>
                </tr>
            </thead>
            <tbody>

    <?php
    foreach ( $data as $date => $entries ):
    ?>
            <?php foreach ( $entries as $i=>$entry ):
                $amount = (float) $entry->amount;
                $direction_class = $amount >= 0 ? 'direction-in' : 'direction-out';
            ?>
                <tr>
                    <td class="date-cell"><?php echo $i==0 ? esc_html( $date ) : ''; ?></td>
                    <td><?php echo esc_html( $entry->description ?? '' ); ?></td>
                    <td><?php echo esc_html( $entry->account_name ); ?></td>
                    <td><?php echo esc_html( $entry->bank_statement_ref ); ?></td>
                    <td><?php echo esc_html( $entry->coa_name ); ?></td>
                    <td class="amount <?php echo esc_attr( $direction_class ); ?>">
                        <?php echo esc_html( $formatter->formatCurrency( $amount, $currency ) ); ?>
                    </td>
                </tr>
            <?php endforeach; ?>
    <?php endforeach; ?>
            </tbody>
        </table>
</div>
