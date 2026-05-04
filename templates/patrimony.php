<?php
defined( 'ABSPATH' ) || exit;

$year         = isset( $_GET['year'] ) ? (int) $_GET['year'] : (int) gmdate( 'Y' );
$p            = EMEAccounting\Patrimony::get( $year );
$all_records  = EMEAccounting\Patrimony::get_all();
$fiscal_years = EMEAccounting\FiscalYear::get_all();

// Helper: waarde tonen met decimalen
$val = fn( string $field ) => $p && property_exists($p, $field) ? number_format( (float) $p->$field, 2, ',', '.' ) : '0,00';
$txt = fn( string $field ) => $p && property_exists($p, $field) ? esc_attr( $p->$field ) : '';
$int = fn( string $field, int $default ) => $p && property_exists($p, $field)? (int) $p->$field : $default;
?>
<div class="wrap eme-acc-wrap">
<h1><?php esc_html_e( 'Staat van het Vermogen', 'eme-accounting' ); echo " Jaar ".esc_html($year);
if (!$p->id)
      echo '<span style="margin-left:8px;color:#d63638;border-color:#d63638"> NOG NIET BEWAARD </span>';
?>
</h1>
<p class="description">
    <?php esc_html_e( 'Bijlage C bij de enkelvoudige boekhouding – verplicht voor micro-VZW\'s (KB 29 april 2019, art. 6, 7 en 14).', 'eme-accounting' ); ?>
</p>

<!-- Boekjaar selector -->
<div style="display:flex;align-items:center;gap:12px;margin-bottom:20px;flex-wrap:wrap">
    <label><strong><?php esc_html_e( 'Boekjaar:', 'eme-accounting' ); ?></strong>
    <select id="eme-acc-patrimony-year">
        <?php foreach ( $fiscal_years as $f ): ?>
        <option value="<?= (int) $f->year ?>" <?= selected( $f->year, $year, false ) ?>>
            <?php echo (int) $f->year;
                  if ( $f->status === 'closed' ) esc_html_e( 'gesloten', 'eme-accounting' );
            ?>
        </option>
        <?php endforeach; ?>
    </select>
    </label>
    <?php if ( $p ): ?>
    <button type="button" class="button button-primary btn-patrimony-export-pdf" data-year="<?= $year ?>"><?php esc_html_e( 'Download PDF', 'eme-accounting' ); ?></button>
    <span class="status-badge status-<?= esc_attr( $p->status ?? 'draft' ) ?>">
        <?= $p->status === 'final' ? esc_html__( 'Definitief', 'eme-accounting' ) : esc_html__( 'Concept', 'eme-accounting' ) ?>
    </span>
    <?php endif; ?>
</div>

<form id="eme-acc-patrimony-form">
<input type="hidden" name="year" value="<?= $year ?>">

<!-- ══════════════════════════════════════════════════════════════════
     SECTIE 1 – WAARDERINGSREGELS (art. 6)
     ══════════════════════════════════════════════════════════════════ -->
<div class="acc-form-box">
<h2><?php esc_html_e( '1. Waarderingsregels (art. 6)', 'eme-accounting' ); ?></h2>
<p class="description"><?php esc_html_e( 'Afschrijvingsperiodes per categorie. Standaardwaarden zijn vooringevuld conform de gebruikelijke VZW-praktijk.', 'eme-accounting' ); ?></p>

<table class="widefat striped" style="margin-bottom:12px">
    <thead><tr>
        <th><?php esc_html_e( 'Categorie', 'eme-accounting' ); ?></th>
        <th><?php esc_html_e( 'Nieuw (jaren)', 'eme-accounting' ); ?></th>
        <th><?php esc_html_e( 'Tweedehands (jaren)', 'eme-accounting' ); ?></th>
    </tr></thead>
    <tbody>
    <?php
    $categories = [
        [ 'machines',   __( 'Machines en elektrische toestellen', 'eme-accounting' ), 5, 2 ],
        [ 'computers',  __( 'Computers en informaticamateriaal',  'eme-accounting' ), 5, 2 ],
        [ 'furniture',  __( 'Meubilair',                          'eme-accounting' ), 5, 2 ],
        [ 'other',      __( 'Andere roerende goederen',           'eme-accounting' ), 5, 2 ],
    ];
    foreach ( $categories as [ $key, $label, $def_new, $def_used ] ): ?>
    <tr>
        <td><?= esc_html( $label ) ?></td>
        <td><input type="number" name="depr_<?= $key ?>_new_years" min="1" max="50"
                   value="<?= $int( 'depr_' . $key . '_new_years', $def_new ) ?>" style="width:60px"></td>
        <td><input type="number" name="depr_<?= $key ?>_used_years" min="1" max="50"
                   value="<?= $int( 'depr_' . $key . '_used_years', $def_used ) ?>" style="width:60px"></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>

<div class="eme-acc-row">
    <div class="eme-acc-field">
        <label><?php esc_html_e( 'Afschrijvingsmethode', 'eme-accounting' ); ?></label>
        <select name="depr_method">
            <option value="linear"     <?= selected( $p->depr_method ?? 'linear', 'linear', false ) ?>><?php esc_html_e( 'Lineair', 'eme-accounting' ); ?></option>
            <option value="degressive" <?= selected( $p->depr_method ?? 'linear', 'degressive', false ) ?>><?php esc_html_e( 'Degressief', 'eme-accounting' ); ?></option>
        </select>
    </div>
    <div class="eme-acc-field" style="flex:3">
        <label><?php esc_html_e( 'Toestand van liquiditeiten', 'eme-accounting' ); ?></label>
        <input type="text" name="cash_valuation_note"
               value="<?= $p && property_exists($p, 'cash_valuation_note') ? esc_attr( $p->cash_valuation_note ) : esc_attr__( 'Toestand van bankrekeningen en kas op 31 december van het betreffende jaar.', 'eme-accounting' ) ?>"
               style="width:100%">
        <span class="description"><?php esc_html_e( 'Standaard: eindejaarsstand bankrekeningen en kas.', 'eme-accounting' ); ?></span>
    </div>
</div>
<div class="eme-acc-row">
    <div class="eme-acc-field" style="flex:1">
        <label><?php esc_html_e( 'Moment van afschrijven', 'eme-accounting' ); ?></label>
        <p class="description" style="margin-top:4px">
            <?php esc_html_e( 'Het jaar van aankoop wordt de volledige aankoopwaarde genoteerd. Pas vanaf het jaar daarna worden de afschrijvingen toegepast.', 'eme-accounting' ); ?>
        </p>
    </div>
</div>
</div>

<!-- ══════════════════════════════════════════════════════════════════
     SECTIE 2 – AANPASSING WAARDERINGSREGELS (art. 7)
     ══════════════════════════════════════════════════════════════════ -->
<div class="eme-acc-form-box">
<h2><?php esc_html_e( '2. Aanpassing van de waarderingsregels (art. 7)', 'eme-accounting' ); ?></h2>
<p class="description"><?php esc_html_e( 'Enkel invullen indien de waarderingsregels t.o.v. vorig jaar gewijzigd werden. Laat leeg voor "Nihil".', 'eme-accounting' ); ?></p>
<textarea name="valuation_changes" rows="3" placeholder="<?php esc_attr_e( 'Nihil', 'eme-accounting' ); ?>"
          style="width:100%"><?= $p && property_exists($p, 'valuation_changes')? esc_textarea( $p->valuation_changes ) : '' ?></textarea>
</div>

<!-- ══════════════════════════════════════════════════════════════════
     SECTIE 3 – BIJKOMENDE INLICHTINGEN (art. 14)
     ══════════════════════════════════════════════════════════════════ -->
<div class="eme-acc-form-box">
<h2><?php esc_html_e( '3. Bijkomende inlichtingen (art. 14)', 'eme-accounting' ); ?></h2>
<p class="description"><?php esc_html_e( 'Bijkomende toelichting bij het vermogen. Laat leeg voor "Nihil".', 'eme-accounting' ); ?></p>
<textarea name="additional_info" rows="3" placeholder="<?php esc_attr_e( 'Nihil', 'eme-accounting' ); ?>"
          style="width:100%"><?= $p && property_exists($p, 'additional_info') ? esc_textarea( $p->additional_info ) : '' ?></textarea>
</div>

<!-- ══════════════════════════════════════════════════════════════════
     SECTIE 4 – GENORMALISEERD SCHEMA (art. 14)
     ══════════════════════════════════════════════════════════════════ -->
<div class="eme-acc-form-box">
<h2><?php esc_html_e( '4. Genormaliseerd schema van de Staat van het Vermogen (art. 14)', 'eme-accounting' ); ?></h2>
<p class="description"><?php esc_html_e( 'Bedragen in €. Gebruik een punt of komma als decimaalteken.', 'eme-accounting' ); ?></p>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:24px">

    <!-- BEZITTINGEN -->
    <div>
        <h3 style="border-bottom:2px solid #000;padding-bottom:4px"><?php esc_html_e( 'BEZITTINGEN', 'eme-accounting' ); ?></h3>
        <?php
        $assets = [
            [ 'asset_real_estate', __( 'Onroerende goederen',                  'eme-accounting' ) ],
            [ 'asset_machines',    __( 'Machines',                             'eme-accounting' ) ],
            [ 'asset_movables',    __( 'Roerende goederen en rollend materiaal','eme-accounting' ) ],
            [ 'asset_stocks',      __( 'Stocks en voorraden',                  'eme-accounting' ) ],
            [ 'asset_receivables', __( 'Schuldvorderingen',                    'eme-accounting' ) ],
            [ 'asset_investments', __( 'Geldbeleggingen',                      'eme-accounting' ) ],
            [ 'asset_cash',        __( 'Liquiditeiten',                        'eme-accounting' ) ],
            [ 'asset_other',       __( 'Andere activa',                        'eme-accounting' ) ],
        ];
        foreach ( $assets as [ $name, $label ] ): ?>
        <div class="eme-acc-patrimony-row">
            <label><?= esc_html( $label ) ?></label>
            <input type="text" name="<?= esc_attr( $name ) ?>" value="<?= $val( $name ) ?>"
                   oninput="this.value=this.value.replace(/[^0-9,.]/g,'');"
                   class="eme-acc-amount" data-section="assets" style="width:120px;text-align:right">
        </div>
        <?php endforeach; ?>
        <div class="eme-acc-patrimony-total">
            <strong><?php esc_html_e( 'Totaal bezittingen', 'eme-accounting' ); ?></strong>
            <strong id="total-assets">—</strong>
        </div>

        <h3 style="border-bottom:2px solid #000;padding-bottom:4px;margin-top:20px"><?php esc_html_e( 'RECHTEN', 'eme-accounting' ); ?></h3>
        <?php
        $rights = [
            [ 'right_subsidies', __( 'Beloofde subsidies',   'eme-accounting' ) ],
            [ 'right_donations', __( 'Beloofde schenkingen', 'eme-accounting' ) ],
            [ 'right_other',     __( 'Andere rechten',       'eme-accounting' ) ],
        ];
        foreach ( $rights as [ $name, $label ] ): ?>
        <div class="eme-acc-patrimony-row">
            <label><?= esc_html( $label ) ?></label>
            <input type="text" name="<?= esc_attr( $name ) ?>" value="<?= $val( $name ) ?>"
                   oninput="this.value=this.value.replace(/[^0-9,.]/g,'');"
                   class="eme-acc-amount" data-section="rights" style="width:120px;text-align:right">
        </div>
        <?php endforeach; ?>
        <div class="eme-acc-patrimony-total">
            <strong><?php esc_html_e( 'Totaal rechten', 'eme-accounting' ); ?></strong>
            <strong id="total-rights">—</strong>
        </div>
    </div>

    <!-- SCHULDEN / VERPLICHTINGEN -->
    <div>
        <h3 style="border-bottom:2px solid #000;padding-bottom:4px"><?php esc_html_e( 'SCHULDEN', 'eme-accounting' ); ?></h3>
        <?php
        $liabs = [
            [ 'liab_financial',    __( 'Financiële schulden',                         'eme-accounting' ) ],
            [ 'liab_suppliers',    __( 'Schulden ten aanzien van leveranciers',        'eme-accounting' ) ],
            [ 'liab_members',      __( 'Schulden ten aanzien van leden',              'eme-accounting' ) ],
            [ 'liab_fiscal_social',__( 'Fiscale, salariële en sociale schulden',      'eme-accounting' ) ],
            [ 'liab_other',        __( 'Andere schulden',                             'eme-accounting' ) ],
        ];
        foreach ( $liabs as [ $name, $label ] ): ?>
        <div class="eme-acc-patrimony-row">
            <label><?= esc_html( $label ) ?></label>
            <input type="text" name="<?= esc_attr( $name ) ?>" value="<?= $val( $name ) ?>"
                   oninput="this.value=this.value.replace(/[^0-9,.]/g,'');"
                   class="eme-acc-amount" data-section="liab" style="width:120px;text-align:right">
        </div>
        <?php endforeach; ?>
        <div class="eme-acc-patrimony-total">
            <strong><?php esc_html_e( 'Totaal schulden', 'eme-accounting' ); ?></strong>
            <strong id="total-liab">—</strong>
        </div>

        <h3 style="border-bottom:2px solid #000;padding-bottom:4px;margin-top:20px"><?php esc_html_e( 'VERPLICHTINGEN', 'eme-accounting' ); ?></h3>
        <?php
        $obligs = [
            [ 'oblig_mortgages',  __( 'Hypotheken en hypotheekbeloften', 'eme-accounting' ) ],
            [ 'oblig_guarantees', __( 'Gegeven waarborgen',              'eme-accounting' ) ],
            [ 'oblig_other',      __( 'Andere verbintenissen',           'eme-accounting' ) ],
        ];
        foreach ( $obligs as [ $name, $label ] ): ?>
        <div class="eme-acc-patrimony-row">
            <label><?= esc_html( $label ) ?></label>
            <input type="text" name="<?= esc_attr( $name ) ?>" value="<?= $val( $name ) ?>"
                   oninput="this.value=this.value.replace(/[^0-9,.]/g,'');"
                   class="eme-acc-amount" data-section="oblig" style="width:120px;text-align:right">
        </div>
        <?php endforeach; ?>
        <div class="eme-acc-patrimony-total">
            <strong><?php esc_html_e( 'Totaal verplichtingen', 'eme-accounting' ); ?></strong>
            <strong id="total-oblig">—</strong>
        </div>

        <div class="eme-acc-patrimony-total" style="margin-top:16px;border-top:2px solid #000;padding-top:8px;background:#f0f6ff">
            <strong><?php esc_html_e( 'Netto vermogen (bezittingen − schulden)', 'eme-accounting' ); ?></strong>
            <strong id="total-net">—</strong>
        </div>
    </div>

</div><!-- /grid -->
</div>

<!-- ══════════════════════════════════════════════════════════════════
     SECTIE 5 – RECHTEN/VERPLICHTINGEN NIET IN CIJFERS
     ══════════════════════════════════════════════════════════════════ -->
<div class="eme-acc-form-box">
<h2><?php esc_html_e( '5. Rechten en verplichtingen die niet in cijfers kunnen worden weergegeven (art. 14)', 'eme-accounting' ); ?></h2>
<p class="description"><?php esc_html_e( 'Bv. borgtochten, lopende geschillen, erfpacht, enz. Laat leeg voor "Nihil".', 'eme-accounting' ); ?></p>
<textarea name="non_numeric_obligations" rows="3" placeholder="<?php esc_attr_e( 'Nihil', 'eme-accounting' ); ?>"
          style="width:100%"><?= $p && property_exists($p, 'non_numeric_obligations') ? esc_textarea( $p->non_numeric_obligations ) : '' ?></textarea>
</div>

<!-- Opties -->
<div class="eme-acc-form-box">
<div class="eme-acc-row">
    <div class="eme-acc-field">
        <label><?php esc_html_e( 'Status', 'eme-accounting' ); ?></label>
        <select name="status">
            <option value="draft" <?= selected( $p->status ?? 'draft', 'draft', false ) ?>><?php esc_html_e( 'Concept', 'eme-accounting' ); ?></option>
            <option value="final" <?= selected( $p->status ?? 'draft', 'final', false ) ?>><?php esc_html_e( 'Definitief', 'eme-accounting' ); ?></option>
        </select>
    </div>
    <div class="eme-acc-field" style="flex:3">
        <label><?php esc_html_e( 'Interne notities', 'eme-accounting' ); ?></label>
        <input type="text" name="notes" value="<?= $p && property_exists($p, 'notes')? esc_attr( $p->notes ) : '' ?>" style="width:100%">
    </div>
</div>
<p>
    <button type="submit" class="button button-primary submit-btn"><?php esc_html_e( 'Opslaan', 'eme-accounting' ); ?></button>
    <?php if ( $p && $p->id): ?>
    <button type="button" class="button eme-acc-delete-patrimony" data-id="<?= (int) $p->id ?>"
            style="margin-left:8px;color:#d63638;border-color:#d63638">
        <?php esc_html_e( 'Verwijder deze Staat van Vermogen', 'eme-accounting' ); ?>
    </button>
    <?php endif; ?>
</p>
</div>

</form>

<!-- ══════════════════════════════════════════════════════════════════
     OVERZICHT ALLE BOEKJAREN
     ══════════════════════════════════════════════════════════════════ -->
<?php if ( $all_records ): ?>
<h2 style="margin-top:28px"><?php esc_html_e( 'Overzicht per boekjaar', 'eme-accounting' ); ?></h2>
<table class="eme-acc-pub-table widefat">
    <thead><tr>
        <th><?php esc_html_e( 'Boekjaar', 'eme-accounting' ); ?></th>
        <th><?php esc_html_e( 'Totaal bezittingen', 'eme-accounting' ); ?></th>
        <th><?php esc_html_e( 'Totaal schulden', 'eme-accounting' ); ?></th>
        <th><?php esc_html_e( 'Netto vermogen', 'eme-accounting' ); ?></th>
        <th><?php esc_html_e( 'Status', 'eme-accounting' ); ?></th>
        <th><?php esc_html_e( 'Acties', 'eme-accounting' ); ?></th>
    </tr></thead>
    <tbody>
    <?php foreach ( $all_records as $rec ):
        $t = EMEAccounting\Patrimony::totals( $rec );
        $fmt = fn( float $v ) => number_format( $v, 2, ',', '.' ) . ' €';
    ?>
    <tr>
        <td><strong><?= (int) $rec->fiscal_year ?></strong></td>
        <td><?= esc_html( $fmt( $t['total_assets'] ) ) ?></td>
        <td><?= esc_html( $fmt( $t['total_liab'] ) ) ?></td>
        <td style="<?= $t['net_assets'] < 0 ? 'color:#d63638;font-weight:600' : '' ?>">
            <?= esc_html( $fmt( $t['net_assets'] ) ) ?>
        </td>
        <td><span class="status-badge status-<?= esc_attr( $rec->status ) ?>">
            <?= $rec->status === 'final' ? esc_html__( 'Definitief', 'eme-accounting' ) : esc_html__( 'Concept', 'eme-accounting' ) ?>
        </span></td>
        <td>
            <a href="<?= esc_url( admin_url( 'admin.php?page=emeaccounting-patrimony&year=' . (int) $rec->fiscal_year ) ) ?>"
               class="button button-small"><?php esc_html_e( 'Bewerk', 'eme-accounting' ); ?></a>
            <button type="button" class="button button-small btn-patrimony-export-pdf" data-year="<?= (int) $rec->fiscal_year ?>"><?php esc_html_e( 'PDF', 'eme-accounting' ); ?></button>
            <button type="button" class="button button-small eme-acc-delete-patrimony" data-id="<?= (int) $rec->id ?>">✕</button>
        </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>

</div><!-- /wrap -->
