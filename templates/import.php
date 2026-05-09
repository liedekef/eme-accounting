<?php
defined( 'ABSPATH' ) || exit;

$coa_accounts = EMEAccounting\ChartOfAccounts::get_dropdown();
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
        <div id="eme-acc-col-hint" style="margin-top:4px;font-size:12px;color:#646970"></div>
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
    
    <!-- Bulk actions bar (shown when rows are selected) -->
    <div id="eme-import-bulk-actions" style="margin-bottom:8px;gap:8px;align-items:center;flex-wrap:wrap">
        <select id="eme-bulk-action-select">
            <option value=""><?php esc_html_e( '&mdash; Bulk actie &mdash;', 'eme-accounting' ); ?></option>
            <option value="ignore"><?php esc_html_e( 'Negeer geselecteerde', 'eme-accounting' ); ?></option>
            <option value="create"><?php esc_html_e( 'Nieuwe boeking voor geselecteerde', 'eme-accounting' ); ?></option>
        </select>
        <span id="eme-bulk-coa-wrap" style="display:none;gap:4px;align-items:center">
            <select id="eme-bulk-coa-select" style="min-width:200px" class="eme_snapselect" data-live-search="true">
                <option value=""><?php esc_html_e( '— Kies categorie —', 'eme-accounting' ); ?></option>
                <optgroup label="<?php esc_html_e( 'Ontvangsten', 'eme-accounting' ); ?>">
                <?php foreach ( $coa_accounts as $id => $acc ):
                      if ( $acc && $acc->direction === 'in' ):
                ?>
                <option value="<?= (int) $id ?>" <?= selected( $edit_entry->coa_id ?? '', $id, false ) ?> <?php if (empty($acc->parent_id)) echo 'disabled'; ?> ><?= esc_html( $acc->code . ' - ' . $acc->name ) ?></option>
                <?php endif; endforeach; ?>
                </optgroup>
                <optgroup label="<?php esc_html_e( 'Uitgaven', 'eme-accounting' ); ?>">
                <?php foreach ( $coa_accounts as $id => $acc ):
                      if ( $acc && $acc->direction === 'out' ):
                ?>
                <option value="<?= (int) $id ?>" <?= selected( $edit_entry->coa_id ?? '', $id, false ) ?> <?php if (empty($acc->parent_id)) echo 'disabled'; ?> ><?= esc_html( $acc->code . ' - ' . $acc->name ) ?></option>
                <?php endif; endforeach; ?>
                </optgroup>
            </select>
            <select id="eme-bulk-event-select" class="eme_snapselect_events_class"></select>
        </span>
        <button id="eme-bulk-apply" class="button button-primary"><?php esc_html_e( 'Toepassen', 'eme-accounting' ); ?></button>
        <span id="eme-bulk-selected-count" style="color:#646970;font-size:13px"></span>
    </div>

    <div id="eme-import-lines-table"></div>
</div>
<h2><?php esc_html_e( 'Eerdere imports', 'eme-accounting' ); ?></h2>

<!-- ftable renders here -->
<div id="eme-import-logs-table"></div>

</div>
