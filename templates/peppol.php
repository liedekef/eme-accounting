<?php
// phpcs:ignoreFile WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
defined( 'ABSPATH' ) || exit;

$peppol_status = EMEAccounting\PeppolEInvoice::get_public_status();
$imap_status   = EMEAccounting\PeppolImap::get_public_status();

$api_configured  = ! empty( $peppol_status['api_configured'] );
$imap_configured = ! empty( $imap_status['configured'] );
$imap_available  = ! empty( $imap_status['available'] );
$api_auto        = $api_configured && ! empty( $peppol_status['poll_enabled'] );
$imap_auto       = $imap_available && $imap_configured && ! empty( $imap_status['enabled'] );

$can_manage = EMEAccounting\Capabilities::can_manage( 'peppol' );
$booking_accounts = [];
foreach ( EMEAccounting\Accounts::get_all() as $acc ) {
    if ( empty( $acc->is_active ) ) {
        continue;
    }
    $label = $acc->name . ( $acc->iban ? ' (' . $acc->iban . ')' : '' );
    $booking_accounts[ (int) $acc->id ] = $label;
}
?>
<div class="wrap eme-acc-wrap">
<h1><?php esc_html_e( 'Peppol inbox', 'eme-accounting' ); ?></h1>

<?php if ( ! $api_configured && ! $imap_configured ): ?>
<div class="notice notice-warning">
    <p>
        <?php esc_html_e( 'Er is nog geen Peppol-bron ingesteld. Configureer een API-key voor e-invoice.be of een e-mailbox voordat je documenten kan ophalen.', 'eme-accounting' ); ?>
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
        <?php $has_last_sync = false; ?>
        <?php if ( $api_configured && ! empty( $peppol_status['last_poll_at'] ) ): ?>
            <?php $has_last_sync = true; ?>
            <strong><?php esc_html_e( 'e-invoice.be', 'eme-accounting' ); ?>:</strong>
            <?php echo esc_html( eme_localized_date( $peppol_status['last_poll_at'], EME_TIMEZONE ) ); ?>
            <?php if ( ! empty( $peppol_status['last_poll_status'] ) ): ?>
                <br><span style="font-size:12px;color:#646970"><?php echo esc_html( $peppol_status['last_poll_status'] ); ?></span>
            <?php endif; ?>
        <?php endif; ?>
        <?php if ( $imap_configured && ! empty( $imap_status['last_poll_at'] ) ): ?>
            <?php if ( $has_last_sync ): ?><br><?php endif; ?>
            <?php $has_last_sync = true; ?>
            <strong><?php esc_html_e( 'Mailbox', 'eme-accounting' ); ?>:</strong>
            <?php echo esc_html( eme_localized_date( $imap_status['last_poll_at'], EME_TIMEZONE ) ); ?>
            <?php if ( ! empty( $imap_status['last_poll_status'] ) ): ?>
                <br><span style="font-size:12px;color:#646970"><?php echo esc_html( $imap_status['last_poll_status'] ); ?></span>
            <?php endif; ?>
        <?php endif; ?>
        <?php if ( ! $has_last_sync ): ?>
            <span style="color:#646970"><?php esc_html_e( 'Nog niet uitgevoerd', 'eme-accounting' ); ?></span>
        <?php endif; ?>
    </td>
</tr>
<tr>
    <th><?php esc_html_e( 'Automatische synchronisatie', 'eme-accounting' ); ?></th>
    <td>
        <?php if ( $api_auto || $imap_auto ): ?>
            <span class="eme-acc-tag eme-acc-reconciled"><?php esc_html_e( 'Uurlijks actief', 'eme-accounting' ); ?></span>
        <?php else: ?>
            <span class="eme-acc-tag eme-acc-pending"><?php esc_html_e( 'Uit', 'eme-accounting' ); ?></span>
        <?php endif; ?>
        <?php if ( $api_configured ): ?>
            <br><span style="font-size:12px;color:#646970">
                <?php echo $api_auto ? esc_html__( 'API: uurlijks actief', 'eme-accounting' ) : esc_html__( 'API: uit', 'eme-accounting' ); ?>
            </span>
        <?php endif; ?>
        <?php if ( $imap_configured ): ?>
            <br><span style="font-size:12px;color:#646970">
                <?php if ( ! $imap_available ): ?>
                    <?php esc_html_e( 'Mailbox: IMAP-client niet beschikbaar', 'eme-accounting' ); ?>
                <?php elseif ( $imap_auto ): ?>
                    <?php esc_html_e( 'Mailbox: uurlijks actief', 'eme-accounting' ); ?>
                <?php else: ?>
                    <?php esc_html_e( 'Mailbox: uit', 'eme-accounting' ); ?>
                <?php endif; ?>
            </span>
        <?php endif; ?>
        <?php if ( $api_configured && empty( $peppol_status['backfill_done'] ) && (int) $peppol_status['backfill_page'] > 0 ): ?>
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
</tbody>
</table>
</div>

<?php if ( $can_manage ): ?>
<div class="eme-acc-form-box">
<h2><?php esc_html_e( 'XML importeren', 'eme-accounting' ); ?></h2>
<p class="description"><?php esc_html_e( 'Voeg UBL-facturen of -creditnota\'s (.xml) rechtstreeks toe, bv. een factuur die buiten Peppol of de mailbox om binnenkwam. Bestaande documenten worden herkend en niet dubbel opgeslagen.', 'eme-accounting' ); ?></p>
<p>
    <input type="file" id="peppol-xml-files" accept=".xml,application/xml,text/xml" multiple>
    <button type="button" id="peppol-xml-import" class="button"><?php esc_html_e( 'Importeren', 'eme-accounting' ); ?></button>
    <span id="peppol-xml-result" style="margin-left:1em;font-size:13px;color:#646970"></span>
</p>
<div id="peppol-xml-detail" style="white-space:pre-line;font-size:12px;color:#b32d2e"></div>
</div>
<?php endif; ?>

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
    <select id="peppol-booking-filter">
        <option value=""><?php esc_html_e( 'Alle boekingen', 'eme-accounting' ); ?></option>
        <option value="unbooked"><?php esc_html_e( 'Niet geboekt', 'eme-accounting' ); ?></option>
        <option value="booked"><?php esc_html_e( 'Geboekt', 'eme-accounting' ); ?></option>
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

<?php if ( $can_manage ): ?>
<div id="peppol-booking" class="eme-acc-form-box" style="display:none">
    <h2><?php esc_html_e( 'Boek naar dagboek', 'eme-accounting' ); ?></h2>
    <p id="peppol-booking-info" class="description"></p>
    <form id="peppol-booking-form">
        <input type="hidden" name="id" id="peppol-booking-id" value="">

        <div class="eme-acc-row-3">
            <div class="eme-acc-field">
                <label><?php esc_html_e( 'Datum', 'eme-accounting' ); ?> *</label>
                <input type="date" name="entry_date" id="peppol-booking-date" required>
            </div>
            <div class="eme-acc-field">
                <label><?php esc_html_e( 'Bedrag (€)', 'eme-accounting' ); ?> *</label>
                <input type="text" name="amount" id="peppol-booking-amount" inputmode="decimal"
                       oninput="this.value=this.value.replace(/[^0-9,.]/g,'');" required>
            </div>
            <div class="eme-acc-field">
                <label><?php esc_html_e( 'Rekening', 'eme-accounting' ); ?> *</label>
                <select name="account_id" id="peppol-booking-account" required>
                    <option value=""><?php esc_html_e( '— Kies rekening —', 'eme-accounting' ); ?></option>
                    <?php foreach ( $booking_accounts as $acc_id => $acc_label ): ?>
                        <option value="<?php echo (int) $acc_id; ?>"><?php echo esc_html( $acc_label ); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="eme-acc-row">
            <div class="eme-acc-field">
                <label><?php esc_html_e( 'Rekeningstelsel', 'eme-accounting' ); ?> *</label>
                <select name="coa_id" id="peppol-booking-coa" required class="eme_snapselect" data-live-search="true">
                    <option value=""><?php esc_html_e( '— Kies rubriek —', 'eme-accounting' ); ?></option>
                </select>
            </div>
        </div>

        <div class="eme-acc-row">
            <div class="eme-acc-field">
                <label><?php esc_html_e( 'Omschrijving', 'eme-accounting' ); ?> *</label>
                <input type="text" name="description" id="peppol-booking-description" maxlength="255" required>
            </div>
            <div class="eme-acc-field">
                <label><?php esc_html_e( 'Betalingsreferentie', 'eme-accounting' ); ?></label>
                <input type="text" name="bank_statement_ref" id="peppol-booking-reference" maxlength="100">
            </div>
        </div>

        <p class="description" id="peppol-booking-direction"></p>
        <p>
            <button type="submit" class="button button-primary"><?php esc_html_e( 'Boeken', 'eme-accounting' ); ?></button>
            <button type="button" id="peppol-booking-cancel" class="button"><?php esc_html_e( 'Annuleren', 'eme-accounting' ); ?></button>
            <span id="peppol-booking-result" style="margin-left:1em"></span>
        </p>
    </form>
    <div id="peppol-booking-candidates"></div>
</div>
<?php endif; ?>

<?php if ( ! empty( $peppol_status['company_name'] ) ): ?>
<p class="description">
    <?php
    printf(
        /* translators: %s: company name */
        esc_html__( 'Gekoppeld e-invoice.be bedrijf: %s', 'eme-accounting' ),
        esc_html( $peppol_status['company_name'] )
    );
    ?>
</p>
<?php endif; ?>
</div>
