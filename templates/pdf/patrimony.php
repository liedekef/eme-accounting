<?php
// phpcs:ignoreFile WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
/**
 * PDF template: Staat van het Vermogen (Bijlage C)
 * Variabelen: $p (patrimony record), $config (vzw config), $totals (array)
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
$year       = (int) $p->fiscal_year;
$court      = esc_html( $config->court ?? '' );

$formatter = new \NumberFormatter( 'nl_BE', \NumberFormatter::CURRENCY );

// Helper: bedrag formatteren
//$fmt = fn( float $v ) => number_format( $v, 2, ',', '.' ) . ' €';
$fmt = fn( float $v ) => $formatter->formatCurrency( $v,$config->currency );
$nihil = fn( string $s ) => trim( $s ) !== '' ? nl2br( esc_html( $s ) ) : '<em>Nihil</em>';

// Afschrijvingstabel regels
$depr_rows = [
    [ __( 'Machines en elektrische toestellen', 'eme-accounting' ), $p->depr_machines_new_years,  $p->depr_machines_used_years  ],
    [ __( 'Computers en informaticamateriaal',  'eme-accounting' ), $p->depr_computers_new_years, $p->depr_computers_used_years ],
    [ __( 'Meubilair',                          'eme-accounting' ), $p->depr_furniture_new_years, $p->depr_furniture_used_years ],
    [ __( 'Andere roerende goederen',           'eme-accounting' ), $p->depr_other_new_years,     $p->depr_other_used_years     ],
];
$depr_method_label = $p->depr_method === 'degressive'
    ? __( 'Degressief', 'eme-accounting' )
    : __( 'Lineair', 'eme-accounting' );
?>
<style>
  * { box-sizing: border-box; }
  body { font-family: Helvetica, Arial, sans-serif; font-size: 9.5pt; }

  h1.acc-title { font-size: 12pt; font-weight: bold; margin: 0 0 2pt; text-align: center; }
  .acc-subtitle { font-size: 9pt; color: #333; margin: 0 0 8pt; text-align: center; }

  .doc-title { font-size: 11pt; font-weight: bold; text-transform: uppercase;
               border-bottom: 2px solid #000; padding-bottom: 3pt; margin: 10pt 0 8pt; text-align: center; }

  .doc-section { page-break-inside: avoid; }

  h2.section-title { font-size: 10pt; font-weight: bold; padding-bottom: 2pt; margin: 12pt 0 6pt;
                     text-align: center; background: #1a1a2e; color: #fff; }

  p { margin: 0 0 5pt; line-height: 1.5; }

  /* Afschrijvingstabel */
  table.depr-table { width: 100%; border-collapse: collapse; margin-bottom: 6pt; font-size: 8.5pt; }
  table.depr-table th { background: #e8e8e8; padding: 3pt 5pt; text-align: left; border: 1px solid #aaa; }
  table.depr-table td { padding: 3pt 5pt; border: 1px solid #ccc; }
  table.depr-table td.num { text-align: center; }

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

  .footer { margin-top: 16pt; font-size: 8pt; color: #666;
            border-top: 1px solid #ccc; padding-top: 6pt; text-align: center; }
</style>

<div class="page">

    <!-- HOOFDING -->
    <h1 class="acc-title"><?php echo esc_html( $vzw_name ); ?> <?php echo esc_html( $vzw_form ); ?></h1>
    <p class="acc-subtitle">
        <?php echo esc_html( $street ); ?>, <?php echo esc_html( $postal ); ?><br>
        Ondernemingsnr. <?php echo esc_html( $vzw_number ); ?>
    </p>
    <p class="acc-subtitle">
        <?php echo esc_url( $website ); ?> | <?php echo esc_html( $email ); ?><br>
        <?php echo esc_html( $court ); ?>
    </p>

    <div class="doc-title">
        Boekjaar <?php echo esc_html( $year ); ?> Bijlage C
        <br>
        Inventaris van Activa, Rechten, Schulden en Verbintenissen
    </div>

    <!-- ── SECTIE 1: WAARDERINGSREGELS ── -->
    
    <div class="doc-section">
    <h2 class="section-title">1. Samenvatting van de waarderingsregels (art. 6)</h2>

    <table class="depr-table">
        <thead>
            <tr>
                <th>Categorie</th>
                <th style="text-align:center">Nieuw (jaren)</th>
                <th style="text-align:center">Tweedehands (jaren)</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ( $depr_rows as [ $cat, $new, $used ] ): ?>
            <tr>
                <td><?php echo esc_html( $cat ); ?></td>
                <td class="num"><?php echo (int) $new; ?></td>
                <td class="num"><?php echo (int) $used; ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <p>
        <strong><?php esc_html_e( 'Afschrijvingsmethode:', 'eme-accounting' ); ?></strong> <?php echo esc_html( $depr_method_label ); ?>.
    </p>
    <p>
        <?php echo $nihil( $p->cash_valuation_note ); //phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
    </p>
    <p>
        <?php esc_html_e( 'Het jaar van aankoop wordt de volledige aankoopwaarde genoteerd. Pas vanaf het jaar daarna worden de afschrijvingen toegepast volgens bovenstaande waardering.', 'eme-accounting' ); ?>
    </p>
    </div>

    <!-- ── SECTIE 2: AANPASSING WAARDERINGSREGELS ── -->
    <div class="doc-section">
    <h2 class="section-title">2. Aanpassing van de waarderingsregels (art. 7)</h2>
    <p><?php echo $nihil( $p->valuation_changes ); //phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></p>
    </div>

    <!-- ── SECTIE 3: BIJKOMENDE INLICHTINGEN ── -->
    <div class="doc-section">
    <h2 class="section-title">3. Bijkomende inlichtingen (art. 14)</h2>
    <p><?php echo $nihil( $p->additional_info ); //phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></p>
    </div>

    <!-- ── SECTIE 4: GENORMALISEERD SCHEMA ── -->
    <div class="doc-section">
    <h2 class="section-title">4. Genormaliseerd schema van de Staat van het Vermogen (art. 14)</h2>

    <table class="schema-table">
        <thead>
            <tr>
                <th class="header-cell" style="width:50%">BEZITTINGEN</th>
                <th class="header-cell" style="width:18%;text-align:right">BEDRAG</th>
                <th class="header-cell" style="width:50%">SCHULDEN</th>
                <th class="header-cell" style="width:18%;text-align:right">BEDRAG</th>
            </tr>
        </thead>
        <tbody>
            <?php
            $assets_rows = [
                [ __( 'Onroerende goederen',                   'eme-accounting' ), $p->asset_real_estate ],
                [ __( 'Machines',                              'eme-accounting' ), $p->asset_machines    ],
                [ __( 'Roerende goederen en rollend materiaal','eme-accounting' ), $p->asset_movables    ],
                [ __( 'Stocks en voorraden',                   'eme-accounting' ), $p->asset_stocks      ],
                [ __( 'Schuldvorderingen',                     'eme-accounting' ), $p->asset_receivables ],
                [ __( 'Geldbeleggingen',                       'eme-accounting' ), $p->asset_investments ],
                [ __( 'Liquiditeiten',                         'eme-accounting' ), $p->asset_cash        ],
                [ __( 'Andere activa',                         'eme-accounting' ), $p->asset_other       ],
            ];
            $liab_rows = [
                [ __( 'Financiële schulden',                   'eme-accounting' ), $p->liab_financial    ],
                [ __( 'Schulden ten aanzien van leveranciers', 'eme-accounting' ), $p->liab_suppliers    ],
                [ __( 'Schulden ten aanzien van leden',        'eme-accounting' ), $p->liab_members      ],
                [ __( 'Fiscale, salariële en sociale schulden','eme-accounting' ), $p->liab_fiscal_social],
                [ null, null ], // lege rij om kolommen gelijk te trekken
                [ null, null ],
                [ null, null ],
                [ __( 'Andere schulden',                       'eme-accounting' ), $p->liab_other        ],
            ];
            $max = max( count( $assets_rows ), count( $liab_rows ) );
            for ( $i = 0; $i < $max; $i++ ):
                $a = $assets_rows[ $i ] ?? [ null, null ];
                $l = $liab_rows[ $i ]   ?? [ null, null ];
            ?>
            <tr>
                <td><?php echo $a[0] !== null ? esc_html( $a[0] ) : ''; ?></td>
                <td class="amount"><?php echo $a[1] !== null ? esc_html( $fmt( (float) $a[1] ) ) : ''; ?></td>
                <td><?php echo $l[0] !== null ? esc_html( $l[0] ) : ''; ?></td>
                <td class="amount"><?php echo $l[1] !== null ? esc_html( $fmt( (float) $l[1] ) ) : ''; ?></td>
            </tr>
            <?php endfor; ?>

            <!-- Lege rij als scheiding -->
            <tr class="section-gap"><td colspan="4"></td></tr>

            <!-- Sub-header rechten / verplichtingen -->
            <tr>
                <th class="header-cell">RECHTEN</th>
                <th class="header-cell" style="text-align:right">BEDRAG</th>
                <th class="header-cell">VERPLICHTINGEN</th>
                <th class="header-cell" style="text-align:right">BEDRAG</th>
            </tr>

            <?php
            $rights_rows = [
                [ __( 'Beloofde subsidies',   'eme-accounting' ), $p->right_subsidies ],
                [ __( 'Beloofde schenkingen', 'eme-accounting' ), $p->right_donations ],
                [ __( 'Andere rechten',       'eme-accounting' ), $p->right_other     ],
            ];
            $oblig_rows = [
                [ __( 'Hypotheken en hypotheekbeloften', 'eme-accounting' ), $p->oblig_mortgages  ],
                [ __( 'Gegeven waarborgen',              'eme-accounting' ), $p->oblig_guarantees ],
                [ __( 'Andere verbintenissen',           'eme-accounting' ), $p->oblig_other      ],
            ];
            $max2 = max( count( $rights_rows ), count( $oblig_rows ) );
            for ( $i = 0; $i < $max2; $i++ ):
                $r = $rights_rows[ $i ] ?? [ null, null ];
                $o = $oblig_rows[ $i ]  ?? [ null, null ];
            ?>
            <tr>
                <td><?php echo $r[0] !== null ? esc_html( $r[0] ) : ''; ?></td>
                <td class="amount"><?php echo $r[1] !== null ? esc_html( $fmt( (float) $r[1] ) ) : ''; ?></td>
                <td><?php echo $o[0] !== null ? esc_html( $o[0] ) : ''; ?></td>
                <td class="amount"><?php echo $o[1] !== null ? esc_html( $fmt( (float) $o[1] ) ) : ''; ?></td>
            </tr>
            <?php endfor; ?>

        </tbody>
    </table>
    </div>

    <!-- ── SECTIE 5 ── -->
    <div class="doc-section">
    <h2 class="section-title" style="margin-top:12pt">
        5. Rechten en verplichtingen die niet in cijfers kunnen worden weergegeven (art. 14)
    </h2>
    <p><?php echo $nihil( $p->non_numeric_obligations ); //phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></p>
    </div>

</div>
