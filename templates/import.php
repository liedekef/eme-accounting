<?php
defined( 'ABSPATH' ) || exit;

$accounts = EMEAccounting\Accounts::get_dropdown( 'asset' );
?>
<div class="wrap eme-acc-wrap">
<h1><?php esc_html_e( 'Import & Reconciliatie', 'eme-accounting' ); ?></h1>

<div class="eme-acc-form-box">
<h2><?php esc_html_e( 'Bankafschrift importeren', 'eme-accounting' ); ?></h2>
<p class="description"><?php esc_html_e( 'Ondersteunde formaten: CSV (BNP, KBC, ING, Belfius), CODA v2, generiek CSV.', 'eme-accounting' ); ?></p>
<form id="eme-acc-import-form" enctype="multipart/form-data">
<fieldset <?= $form_disabled ?>>
<div class="eme-acc-row">
    <div class="eme-acc-field">
        <label><?php esc_html_e( 'Rekening', 'eme-accounting' ); ?> *</label>
        <select name="account_id" required>
            <option value=""><?php esc_html_e( '— Kies rekening —', 'eme-accounting' ); ?></option>
            <?php foreach ( $accounts as $id => $name ): ?>
            <option value="<?= (int) $id ?>"><?= esc_html( $name ) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="eme-acc-field">
        <label><?php esc_html_e( 'Bestandsformaat', 'eme-accounting' ); ?></label>
        <select name="format">
            <option value="auto"><?php esc_html_e( 'Automatisch detecteren', 'eme-accounting' ); ?></option>
            <option value="bnp">BNP Paribas Fortis</option>
            <option value="kbc">KBC</option>
            <option value="ing">ING</option>
            <option value="belfius">Belfius</option>
            <option value="coda">CODA v2</option>
            <option value="csv"><?php esc_html_e( 'Generiek CSV', 'eme-accounting' ); ?></option>
        </select>
    </div>
    <div class="eme-acc-field">
        <label><?php esc_html_e( 'Bestand', 'eme-accounting' ); ?> *</label>
        <input type="file" name="import_file" accept=".csv,.txt,.cod,.coda" required>
    </div>
</div>
<p><button type="submit" class="button button-primary submit-btn"><?php esc_html_e( 'Importeren', 'eme-accounting' ); ?></button></p>
</fieldset>
</form>
<div id="import-result" style="display:none"></div>
</div>
<!-- Reconciliation section: shown/hidden and loaded via JS when a log row is clicked -->
<div id="eme-import-lines-section" style="display:none;">
    <h2 id="eme-import-lines-title"></h2>
    <p>
        <button id="btn-auto-reconcile" class="button"><?php esc_html_e( 'Auto-reconciliatie uitvoeren', 'eme-accounting' ); ?></button>
        <button id="btn-close-lines" class="button"><?php esc_html_e( '↑ Sluit', 'eme-accounting' ); ?></button>
    </p>
    <div id="eme-import-lines-table"></div>
</div>
<h2><?php esc_html_e( 'Eerdere imports', 'eme-accounting' ); ?></h2>

<!-- ftable renders here -->
<div id="eme-import-logs-table"></div>

</div>
