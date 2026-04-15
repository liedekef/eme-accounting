<?php
defined( 'ABSPATH' ) || exit;
$accounts  = EMEAccounting\Accounts::get_dropdown( 'asset' );
$log_id    = isset( $_GET['log_id'] ) ? (int) $_GET['log_id'] : 0;
$lines     = $log_id ? EMEAccounting\Import::get_all_lines( $log_id ) : [];

global $wpdb;
$import_logs = $wpdb->get_results(
    "SELECT il.*, a.name as account_name FROM `{$wpdb->prefix}emeacc_import_log` il
     LEFT JOIN `{$wpdb->prefix}emeacc_accounts` a ON a.id = il.account_id
     ORDER BY il.import_date DESC LIMIT 20"
);
?>
<div class="wrap eme-acc-wrap">
<h1><?php esc_html_e( 'Import & Reconciliatie', 'eme-accounting' ); ?></h1>

<div class="eme-acc-form-box">
<h2><?php esc_html_e( 'Bankafschrift importeren', 'eme-accounting' ); ?></h2>
<p class="description"><?php esc_html_e( 'Ondersteunde formaten: CSV (BNP, KBC, ING, Belfius), CODA v2, generiek CSV.', 'eme-accounting' ); ?></p>
<form id="eme-acc-import-form" enctype="multipart/form-data">
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
</form>
<div id="import-result" style="display:none"></div>
</div>

<?php if ( $log_id && $lines ): ?>
<div class="eme-acc-form-box">
<h2><?php printf( esc_html__( 'Reconciliatie – import #%d', 'eme-accounting' ), $log_id ); ?></h2>
<p>
    <button id="btn-auto-reconcile" class="button" data-log-id="<?= $log_id ?>" data-account-id="<?= (int) ( $lines[0]->account_id ?? 0 ) ?>">
        <?php esc_html_e( 'Auto-reconciliatie uitvoeren', 'eme-accounting' ); ?>
    </button>
</p>
<table class="eme-acc-table">
<thead><tr>
    <th><?php esc_html_e( 'Datum', 'eme-accounting' ); ?></th>
    <th><?php esc_html_e( 'Omschrijving', 'eme-accounting' ); ?></th>
    <th><?php esc_html_e( 'Tegenpartij', 'eme-accounting' ); ?></th>
    <th><?php esc_html_e( 'Ref.', 'eme-accounting' ); ?></th>
    <th class="amount"><?php esc_html_e( 'Bedrag', 'eme-accounting' ); ?></th>
    <th><?php esc_html_e( 'Status', 'eme-accounting' ); ?></th>
    <th><?php esc_html_e( 'Acties', 'eme-accounting' ); ?></th>
</tr></thead>
<tbody>
<?php foreach ( $lines as $line ):
    $status_label = [ 'pending' => __( 'Te verifiëren', 'eme-accounting' ), 'matched' => __( 'Gematcht', 'eme-accounting' ), 'ignored' => __( 'Genegeerd', 'eme-accounting' ), 'manual' => __( 'Manueel', 'eme-accounting' ) ];
    $status_class = [ 'pending' => 'pending', 'matched' => 'reconciled', 'ignored' => 'ignored', 'manual' => '' ];
?>
<tr>
    <td><?= esc_html( date_i18n( 'd/m/Y', strtotime( $line->transaction_date ) ) ) ?></td>
    <td><?= esc_html( mb_substr( $line->description, 0, 60 ) ) ?></td>
    <td style="font-size:11px"><?= esc_html( $line->counterparty_name ) ?><br><span style="color:#646970"><?= esc_html( $line->counterparty_iban ) ?></span></td>
    <td style="font-size:11px"><?= esc_html( $line->bank_ref ?: $line->structured_ref ) ?></td>
    <td class="amount <?= (float) $line->amount >= 0 ? 'in' : 'out' ?>"><?= number_format( (float) $line->amount, 2, ',', '.' ) ?> €</td>
    <td><span class="tag <?= esc_attr( $status_class[ $line->status ] ?? '' ) ?>"><?= esc_html( $status_label[ $line->status ] ?? $line->status ) ?></span>
        <?php if ( $line->journal_id ): ?><br><span style="font-size:11px;color:#646970">#<?= (int) $line->journal_id ?></span><?php endif; ?></td>
    <td>
        <?php if ( $line->status === 'pending' ): ?>
        <div style="display:flex;gap:4px;flex-wrap:wrap">
            <input type="number" class="journal-id-input" placeholder="Dagboek ID" style="width:80px;padding:2px 4px">
            <button class="button button-small btn-reconcile-manual" data-line-id="<?= (int) $line->id ?>"><?php esc_html_e( 'Link', 'eme-accounting' ); ?></button>
            <input type="text" class="category-input" placeholder="<?php esc_attr_e( 'Categorie', 'eme-accounting' ); ?>" style="width:100px;padding:2px 4px">
            <button class="button button-small btn-create-from-line" data-line-id="<?= (int) $line->id ?>"><?php esc_html_e( 'Nieuwe boeking', 'eme-accounting' ); ?></button>
            <button class="button button-small btn-ignore-line" data-line-id="<?= (int) $line->id ?>"><?php esc_html_e( 'Negeer', 'eme-accounting' ); ?></button>
        </div>
        <?php endif; ?>
    </td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<?php endif; ?>

<?php if ( $import_logs ): ?>
<h2><?php esc_html_e( 'Eerdere imports', 'eme-accounting' ); ?></h2>
<table class="eme-acc-table">
<thead><tr>
    <th>#</th><th><?php esc_html_e( 'Datum', 'eme-accounting' ); ?></th>
    <th><?php esc_html_e( 'Rekening', 'eme-accounting' ); ?></th>
    <th><?php esc_html_e( 'Bestand', 'eme-accounting' ); ?></th>
    <th><?php esc_html_e( 'Totaal', 'eme-accounting' ); ?></th>
    <th><?php esc_html_e( 'Gematcht', 'eme-accounting' ); ?></th>
    <th><?php esc_html_e( 'Open', 'eme-accounting' ); ?></th>
    <th></th>
</tr></thead>
<tbody>
<?php foreach ( $import_logs as $log ): ?>
<tr data-import-log="<?= (int) $log->id ?>">
    <td><?= (int) $log->id ?></td>
    <td><?= esc_html( date_i18n( 'd/m/Y H:i', strtotime( $log->import_date ) ) ) ?></td>
    <td><?= esc_html( $log->account_name ) ?></td>
    <td style="font-size:11px"><?= esc_html( $log->filename ) ?> <span class="tag"><?= esc_html( $log->format ) ?></span></td>
    <td><?= (int) $log->total_lines ?></td>
    <td><span class="tag reconciled"><?= (int) $log->matched_lines ?></span></td>
    <td><?= $log->unmatched_lines > 0 ? '<span class="tag pending">' . (int) $log->unmatched_lines . '</span>' : '–' ?></td>
    <td>
        <a href="<?= esc_url( admin_url( 'admin.php?page=eme-accounting-import&log_id=' . $log->id ) ) ?>" class="button button-small"><?php esc_html_e( 'Bekijk', 'eme-accounting' ); ?></a>
        <a href="#" class="button button-small btn-delete-import-log" data-id="<?= (int) $log->id ?>"><?php esc_html_e( 'Verwijder', 'eme-accounting' ); ?></a>
    </td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
<?php endif; ?>
</div>
