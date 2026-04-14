<?php
defined( 'ABSPATH' ) || exit;
$assets   = EMEAccounting\Depreciation::get_all();
$accounts = EMEAccounting\Accounts::get_dropdown();
?>
<div class="wrap eme-acc-wrap">
<h1><?php esc_html_e( 'Vaste Activa & Afschrijvingen', 'eme-accounting' ); ?></h1>

<div class="eme-acc-form-box">
<h2><?php esc_html_e( 'Activum toevoegen', 'eme-accounting' ); ?></h2>
<form id="eme-acc-asset-form">
<div class="eme-acc-row">
    <div class="eme-acc-field" style="flex:2">
        <label><?php esc_html_e( 'Naam', 'eme-accounting' ); ?> *</label>
        <input type="text" name="name" required maxlength="150">
    </div>
    <div class="eme-acc-field">
        <label><?php esc_html_e( 'Aankoopdatum', 'eme-accounting' ); ?> *</label>
        <input type="date" name="purchase_date" required value="<?= esc_attr( current_time( 'Y-m-d' ) ) ?>">
    </div>
    <div class="eme-acc-field">
        <label><?php esc_html_e( 'Aankoopprijs (€)', 'eme-accounting' ); ?> *</label>
        <input type="number" name="purchase_price" id="purchase_price" required step="0.01" min="0.01">
    </div>
</div>
<div class="eme-acc-row">
    <div class="eme-acc-field">
        <label><?php esc_html_e( 'Restwaarde (€)', 'eme-accounting' ); ?></label>
        <input type="number" name="residual_value" id="residual_value" step="0.01" min="0" value="0">
    </div>
    <div class="eme-acc-field">
        <label><?php esc_html_e( 'Gebruiksduur (jaar)', 'eme-accounting' ); ?></label>
        <input type="number" name="useful_life_years" id="useful_life_years" min="1" max="50" value="5">
    </div>
    <div class="eme-acc-field">
        <label><?php esc_html_e( 'Methode', 'eme-accounting' ); ?></label>
        <select name="method" id="method">
            <option value="linear"><?php esc_html_e( 'Lineair', 'eme-accounting' ); ?></option>
            <option value="declining"><?php esc_html_e( 'Degressief', 'eme-accounting' ); ?></option>
        </select>
    </div>
</div>
<div class="eme-acc-row">
    <div class="eme-acc-field">
        <label><?php esc_html_e( 'Activumrekening', 'eme-accounting' ); ?></label>
        <select name="account_id">
            <option value=""><?php esc_html_e( '—', 'eme-accounting' ); ?></option>
            <?php foreach ( $accounts as $id => $name ): ?>
            <option value="<?= $id ?>"><?= esc_html( $name ) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="eme-acc-field">
        <label><?php esc_html_e( 'Afschrijvingsrekening', 'eme-accounting' ); ?></label>
        <select name="depreciation_account_id">
            <option value=""><?php esc_html_e( '—', 'eme-accounting' ); ?></option>
            <?php foreach ( $accounts as $id => $name ): ?>
            <option value="<?= $id ?>"><?= esc_html( $name ) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="eme-acc-field">
        <label><?php esc_html_e( 'Omschrijving', 'eme-accounting' ); ?></label>
        <textarea name="description" style="min-height:38px"></textarea>
    </div>
</div>
<p id="depreciation-preview" style="font-weight:600;color:#0073aa"></p>
<p><button type="submit" class="button button-primary submit-btn"><?php esc_html_e( 'Activum opslaan', 'eme-accounting' ); ?></button></p>
</form>
</div>

<?php if ( $assets ): ?>
<table class="eme-acc-table">
<thead><tr>
    <th><?php esc_html_e( 'Naam', 'eme-accounting' ); ?></th>
    <th><?php esc_html_e( 'Aankoopdatum', 'eme-accounting' ); ?></th>
    <th class="amount"><?php esc_html_e( 'Aankoopprijs', 'eme-accounting' ); ?></th>
    <th class="amount"><?php esc_html_e( 'Boekwaarde', 'eme-accounting' ); ?></th>
    <th><?php esc_html_e( 'Methode', 'eme-accounting' ); ?></th>
    <th><?php esc_html_e( 'Duur', 'eme-accounting' ); ?></th>
    <th class="amount"><?php esc_html_e( 'Jaarl. afschr.', 'eme-accounting' ); ?></th>
    <th><?php esc_html_e( 'Laatste afschr.', 'eme-accounting' ); ?></th>
</tr></thead>
<tbody>
<?php foreach ( $assets as $asset ):
    $annual = EMEAccounting\Depreciation::calculate_annual_amount( $asset );
    $pct    = $asset->purchase_price > 0
        ? round( ( 1 - $asset->current_book_value / $asset->purchase_price ) * 100, 1 )
        : 0;
?>
<tr>
    <td>
        <strong><?= esc_html( $asset->name ) ?></strong>
        <?php if ( $asset->description ): ?>
        <br><span style="font-size:11px;color:#646970"><?= esc_html( mb_substr( $asset->description, 0, 60 ) ) ?></span>
        <?php endif; ?>
    </td>
    <td><?= esc_html( date_i18n( 'd/m/Y', strtotime( $asset->purchase_date ) ) ) ?></td>
    <td class="amount"><?= number_format( (float) $asset->purchase_price, 2, ',', '.' ) ?> €</td>
    <td class="amount">
        <?= number_format( (float) $asset->current_book_value, 2, ',', '.' ) ?> €
        <div class="budget-bar-wrap" title="<?= $pct ?>% afgeschreven">
            <div class="budget-bar-fill <?= $pct >= 100 ? 'over' : '' ?>" style="width:<?= min(100,$pct) ?>%"></div>
        </div>
    </td>
    <td><span class="tag"><?= $asset->method === 'linear' ? esc_html__( 'Lineair', 'eme-accounting' ) : esc_html__( 'Degressief', 'eme-accounting' ) ?></span></td>
    <td><?= (int) $asset->useful_life_years ?> <?php esc_html_e( 'jaar', 'eme-accounting' ); ?></td>
    <td class="amount out"><?= number_format( $annual, 2, ',', '.' ) ?> €</td>
    <td style="font-size:11px;color:#646970">
        <?= $asset->last_depreciation
            ? esc_html( date_i18n( 'd/m/Y', strtotime( $asset->last_depreciation ) ) )
            : '—' ?>
    </td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
<?php else: ?>
<p><?php esc_html_e( 'Nog geen vaste activa geregistreerd.', 'eme-accounting' ); ?></p>
<?php endif; ?>
</div>
