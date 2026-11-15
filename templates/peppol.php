<?php
// phpcs:ignoreFile WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
defined( 'ABSPATH' ) || exit;

$peppol_status = EMEAccounting\Peppol::get_public_status();
$peppol_settings = EMEAccounting\Peppol::get_settings();
?>
<div class="wrap eme-acc-wrap">
<h1><?php esc_html_e( 'Peppol inbox', 'eme-accounting' ); ?></h1>

<?php if ( empty( $peppol_status['api_configured'] ) ): ?>
<div class="notice notice-warning">
    <p>
        <?php esc_html_e( 'Er is nog geen Peppol API-key ingesteld. Configureer de koppeling met e-invoice.be voordat je documenten kan ophalen.', 'eme-accounting' ); ?>
        <a href="<?php echo esc_url( admin_url( 'admin.php?page=emeaccounting-settings&tab=peppol' ) ); ?>"><?php esc_html_e( 'Naar de Peppol-instellingen', 'eme-accounting' ); ?></a>
    </p>
</div>
<?php endif; ?>

<div class="eme-acc-form-box">
<h2><?php esc_html_e( 'Status', 'eme-accounting' ); ?></h2>
<table class="eme-acc-table">
<tbody>
<tr>
    <th style="width:240px"><?php esc_html_e( 'Laatste synchronisatie', 'eme-accounting' ); ?></th>
    <td>
        <?php if ( ! empty( $peppol_status['last_poll_at'] ) ): ?>
            <?php echo esc_html( eme_localized_date( $peppol_status['last_poll_at'], EME_TIMEZONE ) ); ?>
            <?php if ( ! empty( $peppol_status['last_poll_status'] ) ): ?>
                <br><span style="font-size:12px;color:#646970"><?php echo esc_html( $peppol_status['last_poll_status'] ); ?></span>
            <?php endif; ?>
        <?php else: ?>
            <span style="color:#646970"><?php esc_html_e( 'Nog niet uitgevoerd', 'eme-accounting' ); ?></span>
        <?php endif; ?>
    </td>
</tr>
<tr>
    <th><?php esc_html_e( 'Automatische synchronisatie', 'eme-accounting' ); ?></th>
    <td>
        <?php if ( ! empty( $peppol_status['poll_enabled'] ) && ! empty( $peppol_status['api_configured'] ) ): ?>
            <span class="eme-acc-tag eme-acc-reconciled"><?php esc_html_e( 'Uurlijks actief', 'eme-accounting' ); ?></span>
        <?php else: ?>
            <span class="eme-acc-tag eme-acc-pending"><?php esc_html_e( 'Uit', 'eme-accounting' ); ?></span>
        <?php endif; ?>
        <?php if ( empty( $peppol_status['backfill_done'] ) && (int) $peppol_status['backfill_page'] > 0 ): ?>
            <br><span style="font-size:12px;color:#646970">
                <?php
                printf(
                    /* translators: %d: page number */
                    esc_html__( 'Geschiedenis wordt opgehaald (volgende pagina: %d).', 'eme-accounting' ),
                    (int) $peppol_status['backfill_page']
                );
                ?>
            </span>
        <?php endif; ?>
    </td>
</tr>
<tr>
    <th><?php esc_html_e( 'Webhook', 'eme-accounting' ); ?></th>
    <td>
        <?php if ( ! empty( $peppol_status['webhook_configured'] ) ): ?>
            <span class="eme-acc-tag eme-acc-reconciled"><?php esc_html_e( 'Actief', 'eme-accounting' ); ?></span>
        <?php else: ?>
            <span class="eme-acc-tag eme-acc-pending"><?php esc_html_e( 'Niet geconfigureerd', 'eme-accounting' ); ?></span>
        <?php endif; ?>
        <br><code style="font-size:11px;word-break:break-all"><?php echo esc_html( $peppol_status['webhook_url'] ); ?></code>
    </td>
</tr>
</tbody>
</table>
<p>
    <button type="button" id="peppol-sync-now" class="button button-primary" <?php echo empty( $peppol_status['api_configured'] ) ? 'disabled' : ''; ?>>
        <?php esc_html_e( 'Nu synchroniseren', 'eme-accounting' ); ?>
    </button>
    <a href="<?php echo esc_url( admin_url( 'admin.php?page=emeaccounting-settings&tab=peppol' ) ); ?>" class="button">
        <?php esc_html_e( 'Peppol-instellingen', 'eme-accounting' ); ?>
    </a>
    <span id="peppol-sync-result" style="margin-left:1em;font-size:13px;color:#646970"></span>
</p>
</div>

<div class="eme-acc-form-box">
<h2><?php esc_html_e( 'Documenten', 'eme-accounting' ); ?></h2>

<div class="eme-acc-toolbar">
    <input type="search" id="peppol-search-filter" placeholder="<?php esc_attr_e( 'Zoek op factuurnummer, leverancier, klant, referentie…', 'eme-accounting' ); ?>" size="34">
    <select id="peppol-type-filter">
        <option value=""><?php esc_html_e( 'Alle types', 'eme-accounting' ); ?></option>
        <option value="INVOICE"><?php esc_html_e( 'Factuur', 'eme-accounting' ); ?></option>
        <option value="CREDIT_NOTE"><?php esc_html_e( 'Creditnota', 'eme-accounting' ); ?></option>
        <option value="SELFBILLING_INVOICE"><?php esc_html_e( 'Self-billing factuur', 'eme-accounting' ); ?></option>
        <option value="SELFBILLING_CREDIT_NOTE"><?php esc_html_e( 'Self-billing creditnota', 'eme-accounting' ); ?></option>
    </select>
    <select id="peppol-status-filter">
        <option value=""><?php esc_html_e( 'Alle statussen', 'eme-accounting' ); ?></option>
        <option value="new"><?php esc_html_e( 'Nieuw', 'eme-accounting' ); ?></option>
        <option value="ignored"><?php esc_html_e( 'Genegeerd', 'eme-accounting' ); ?></option>
        <option value="error"><?php esc_html_e( 'Fout', 'eme-accounting' ); ?></option>
    </select>
    <input type='text' id='peppol-daterange-filter' name='peppol-daterange-filter' placeholder='<?php esc_attr_e( 'Filter op factuurdatum', 'eme-accounting' ); ?>' data-autoclose='false' data-range='true' data-multiple-separator=' -- ' data-date='' style='width: 22ch;' class='eme_formfield_fdate'>
    <button type="button" id="peppol-filter-apply" class="button"><?php esc_html_e( 'Filteren', 'eme-accounting' ); ?></button>
</div>

<div id="PeppolTableContainer"></div>
</div>

<div id="peppol-detail" class="eme-acc-form-box" style="display:none">
    <h2 id="peppol-detail-title"><?php esc_html_e( 'Documentdetails', 'eme-accounting' ); ?></h2>
    <p>
        <button type="button" id="peppol-detail-close" class="button"><?php esc_html_e( '↑ Sluit', 'eme-accounting' ); ?></button>
        <a href="#" id="peppol-detail-retry" class="button" style="display:none"><?php esc_html_e( 'Opnieuw ophalen', 'eme-accounting' ); ?></a>
        <button type="button" id="peppol-detail-ignore" class="button" style="display:none"><?php esc_html_e( 'Negeren', 'eme-accounting' ); ?></button>
    </p>
    <div id="peppol-detail-body"></div>
</div>

<?php if ( ! empty( $peppol_settings['company_name'] ) ): ?>
<p class="description">
    <?php
    printf(
        /* translators: %s: company name */
        esc_html__( 'Gekoppeld e-invoice.be bedrijf: %s', 'eme-accounting' ),
        esc_html( $peppol_settings['company_name'] )
    );
    ?>
</p>
<?php endif; ?>
</div>
