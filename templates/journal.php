<?php
defined( 'ABSPATH' ) || exit;
$formatter = new NumberFormatter('nl_BE',  NumberFormatter::CURRENCY);
$config =  \EMEVzw\Config::get();
$currency  = $config->currency;
$vat_liable = (bool) ( $config->vat_liable ?? false );

$year        = isset( $_GET['year'] )       ? (int) $_GET['year']                : (int) gmdate( 'Y' );
$account_id  = isset( $_GET['account_id'] ) ? (int) $_GET['account_id']          : 0;
$direction   = isset( $_GET['direction'] )  ? sanitize_key( $_GET['direction'] ) : '';
$edit_id     = isset( $_GET['edit'] )       ? (int) $_GET['edit']                : 0;

$accounts    = EMEAccounting\Accounts::get_dropdown();
$coa_accounts = EMEAccounting\ChartOfAccounts::get_dropdown();
$edit_entry  = $edit_id ? EMEAccounting\Journal::get( $edit_id ) : null;
$fiscal_years = EMEAccounting\FiscalYear::get_all();

// $form_disabled may already be set by Menu.php (read-only access profile).
// Journal can only further restrict it (e.g. closed fiscal year, transfer entry, inactive account).
$form_disabled           = $form_disabled ?? '';
$inactive_account        = null;
$inactive_contra_account = null;
if ( $edit_entry ) {
    if ( $edit_entry->transfer_id ) {
        $form_disabled = 'disabled="disabled"';
    }
    $account        = EMEAccounting\Accounts::get( $edit_entry->account_id );
    $contra_account = $edit_entry->contra_account_id ? EMEAccounting\Accounts::get( $edit_entry->contra_account_id ) : null;
    if ( $account && ! $account->is_active ) {
        $form_disabled        = 'disabled="disabled"';
        $inactive_account     = $account;
    }
    if ( $contra_account && ! $contra_account->is_active ) {
        $form_disabled           = 'disabled="disabled"';
        $inactive_contra_account = $contra_account;
    }
}

if (empty($accounts)) {
    echo '<div class="notice notice-error"><p>' . esc_html__( 'Maak eerst financiële rekeningen aan.', 'eme-accounting' ) . '</p></div>';
    return;
}

$active_accounts = array_filter( $accounts, function( $id ) use ( $accounts ) {
    $account = EMEAccounting\Accounts::get( $id );
    return $account && $account->is_active;
}, ARRAY_FILTER_USE_KEY );
?>
<div class="wrap eme-acc-wrap">
    <h1><?php esc_html_e( 'Financieel Dagboek', 'eme-accounting' ); ?></h1>

    <!-- Add / Edit form -->
    <div class="eme-acc-form-box">
        <h2><?= $edit_entry ? esc_html__( 'Boeking bewerken', 'eme-accounting' ) : esc_html__( 'Nieuwe boeking', 'eme-accounting' ) ?></h2>
        <form id="eme-acc-journal-form" enctype="multipart/form-data">
        <fieldset <?= $form_disabled ?>>
            <?php if ( $edit_entry ): ?>
            <input type="hidden" name="id" value="<?= (int) $edit_entry->id ?>">
            <?php endif; ?>
            <div class="eme-acc-row-3">
                <div class="eme-acc-field">
                    <label><?php esc_html_e( 'Datum', 'eme-accounting' ); ?> *</label>
                    <input type="date" name="entry_date" id="entry_date" required
                           value="<?= esc_attr( $edit_entry->entry_date ?? current_time( 'Y-m-d' )) ?>">
                </div>
                <div class="eme-acc-field">
                    <label><?php esc_html_e( 'Boekingsdatum bank', 'eme-accounting' ); ?></label>
                    <input type="date" name="booking_date"
                           value="<?= esc_attr( $edit_entry->booking_date ?? '' ) ?>">
                    <span class="description"><?php esc_html_e( 'Valutadatum (optioneel)', 'eme-accounting' ); ?></span>
                </div>
                <div class="eme-acc-field" id="fiscal-year-info" style="display:none">
                    <label><?php esc_html_e( 'Boekjaar', 'eme-accounting' ); ?></label>
                    <br><br><span id="fy-display" style="font-weight:bold;color:#2271b1">—</span>
                </div>
            </div>

            <div class="eme-acc-row-3">
                <div class="eme-acc-field">
                    <label><?php esc_html_e( 'Omschrijving', 'eme-accounting' ); ?> *</label>
                    <input type="text" name="description" required maxlength="255"
                           value="<?= esc_attr( $edit_entry->description ?? '' ) ?>">
                </div>
                <div class="eme-acc-field">
                    <label><?php esc_html_e( 'Richting', 'eme-accounting' ); ?></label>
                    <select name="direction" id="direction" <?= ($edit_entry) ? 'disabled' : '' ?>>
                        <option value="in"  <?= selected( $edit_entry->direction ?? '', 'in',  false ) ?>><?php esc_html_e( 'Ontvangst (+)', 'eme-accounting' ); ?></option>
                        <option value="out" <?= selected( $edit_entry->direction ?? '', 'out', false ) ?>><?php esc_html_e( 'Uitgave (–)', 'eme-accounting' ); ?></option>
                        <option value="transfer" <?= selected( $edit_entry->direction ?? '', 'transfer', false ) ?>><?php esc_html_e( 'Transfer', 'eme-accounting' ); ?></option>
                    </select>
                </div>
                <div class="eme-acc-field">
                    <label><?php esc_html_e( 'Rekeningstelsel', 'eme-accounting' ); ?></label>
                    <select name="coa_id" id="category_coa_id" required class="eme_snapselect" data-live-search="true" data-open-on-focus-if-empty="true">
                        <option value=""><?php esc_html_e( '— Kies rekening —', 'eme-accounting' ); ?></option>
                        <optgroup label="<?php esc_html_e( 'Ontvangsten', 'eme-accounting' ); ?>">
                        <?php foreach ( $coa_accounts as $id => $acc ): 
                            if ( $acc && $acc->direction === 'in' ):
                        ?>
                        <option value="<?= (int) $id ?>" <?= selected( $edit_entry->coa_id ?? '', $id, false ) ?> <?php if (empty($acc->parent_id)) echo 'disabled'; ?> >
                            <?= esc_html( $acc->code . ' - ' . $acc->name ) ?>
                        </option>
                        <?php endif; endforeach; ?>
                        </optgroup>
                        <optgroup label="<?php esc_html_e( 'Uitgaven', 'eme-accounting' ); ?>">
                        <?php foreach ( $coa_accounts as $id => $acc ): 
                            if ( $acc && $acc->direction === 'out' ):
                        ?>
                        <option value="<?= (int) $id ?>" <?= selected( $edit_entry->coa_id ?? '', $id, false ) ?> <?php if (empty($acc->parent_id)) echo 'disabled'; ?> >
                            <?= esc_html( $acc->code . ' - ' . $acc->name ) ?>
                        </option>
                        <?php endif; endforeach; ?>
                        </optgroup>
                    </select>
                </div>
            </div>

            <div class="eme-acc-row-3">
                <div class="eme-acc-field">
                    <label><?php esc_html_e( 'Bedrag (€)', 'eme-accounting' ); ?> *</label>
                    <input type="text" name="amount" required
                           inputmode="decimal" size="5"
                           oninput="this.value=this.value.replace(/[^0-9,.]/g,'');"
                           value="<?= esc_attr( $edit_entry && $edit_entry->amount > 0 ? number_format( (float) $edit_entry->amount, 2, ',', '.' ) : '' ) ?>"
                           placeholder="0,00">
                </div>
                <div class="eme-acc-field">
                    <label><?php esc_html_e( 'Rekening', 'eme-accounting' ); ?> *</label>
                    <?php if ( $form_disabled && $inactive_account ): ?>
                    <input type="hidden" name="account_id" value="<?= $edit_entry ? (int) $edit_entry->account_id : 0 ?>">
                    <span style="color:#d63638"><?= __( 'Inactief: ', 'eme-accounting' ) . esc_html( $inactive_account->name ) . ( $inactive_account->iban ? ' (' . $inactive_account->iban . ')' : '' ) ?></span>
                    <?php else: ?>
                    <select name="account_id" required>
                        <option value=""><?php esc_html_e( '— Kies rekening —', 'eme-accounting' ); ?></option>
                        <?php foreach ( $active_accounts as $id => $name ): ?>
                        <option value="<?= (int) $id ?>" <?= selected( $edit_entry->account_id ?? 0, $id, false ) ?>>
                            <?= esc_html( $name ) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <?php endif; ?>
                </div>
                <div class="eme-acc-field">
                    <label><?php esc_html_e( 'Tegenrekening', 'eme-accounting' ); ?></label>
                    <?php if ( $form_disabled && $inactive_contra_account ): ?>
                    <input type="hidden" name="contra_account_id" value="<?= (int) $edit_entry->contra_account_id ?>">
                    <span style="color:#d63638"><?= __( 'Inactief: ', 'eme-accounting' ) . esc_html( $inactive_contra_account->name ) . ( $inactive_contra_account->iban ? ' (' . $inactive_contra_account->iban . ')' : '' ) ?></span>
                    <?php else: ?>
                    <select name="contra_account_id" id="contra_account_id">
                        <option value=""><?php esc_html_e( '— (geen) —', 'eme-accounting' ); ?></option>
                        <?php foreach ( $active_accounts as $id => $name ): ?>
                        <option value="<?= (int) $id ?>" <?= selected( $edit_entry->contra_account_id ?? 0, $id, false ) ?>>
                            <?= esc_html( $name ) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <?php endif; ?>
                    <span class="description">
                        <?php esc_html_e( 'Enkel geldig voor transfers tussen rekeningen.', 'eme-accounting' ); ?>
                    </span>
                </div>
            </div>

            <?php if ( $vat_liable ): ?>
            <div class="eme-acc-row-3" id="vat-row">
                <div class="eme-acc-field">
                    <label><?php esc_html_e( 'BTW-tarief', 'eme-accounting' ); ?></label>
                    <select name="vat_code" id="vat_code">
                        <option value=""><?php esc_html_e( '— geen BTW —', 'eme-accounting' ); ?></option>
                        <option value="6"      <?= selected( $edit_entry->vat_code  ?? '', '6',      false ) ?>>6%</option>
                        <option value="12"     <?= selected( $edit_entry->vat_code  ?? '', '12',     false ) ?>>12%</option>
                        <option value="21"     <?= selected( $edit_entry->vat_code  ?? '', '21',     false ) ?>>21%</option>
                        <option value="exempt" <?= selected( $edit_entry->vat_code  ?? '', 'exempt', false ) ?>><?php esc_html_e( 'Vrijgesteld', 'eme-accounting' ); ?></option>
                    </select>
                </div>
                <div class="eme-acc-field">
                    <label><?php esc_html_e( 'BTW-bedrag (€)', 'eme-accounting' ); ?></label>
                    <input type="text" name="vat_amount" id="vat_amount" size="5"
                           inputmode="decimal"
                           oninput="this.value=this.value.replace(/[^0-9,.]/g,'');"
                           value="<?= esc_attr( $edit_entry && $edit_entry->vat_amount > 0 ? number_format( (float) $edit_entry->vat_amount, 2, ',', '.' ) : '' ) ?>"
                           placeholder="0,00">
                    <span class="description"><?php esc_html_e( 'Auto-berekend op basis van bedrag incl. BTW, maar overschrijfbaar.', 'eme-accounting' ); ?></span>
                </div>
                <div class="eme-acc-field">
                    <label><?php esc_html_e( 'BTW-nummer tegenpartij', 'eme-accounting' ); ?></label>
                    <input type="text" name="counterparty_vat_number" id="counterparty_vat_number" size="40"
                           value="<?= esc_attr( $edit_entry->counterparty_vat_number ?? '' ) ?>">
                    <span class="description"><?php esc_html_e( 'BTW nummer tegenpartij indien BTW toepasbaar is.', 'eme-accounting' ); ?></span>
                </div>
            </div>
            <?php endif; ?>

            <!-- Bank statement info -->
            <div class="eme-acc-row-4">
                <div class="eme-acc-field">
                    <label><?php esc_html_e( 'Event', 'eme-accounting' ); ?></label>
                    <?php
                    $event_id  = 0;
                    $myevents  = [];
                    if ( ! empty( $edit_entry->eme_event_id ) ) {
                        $event_id = $edit_entry->eme_event_id;
                        $event = eme_get_event(  $event_id);
                        if ( $event ) {
                            $myevents[ $event_id ] = eme_translate($event['event_name']) . ' (' . eme_localized_date( $event['event_start'], EME_TIMEZONE, 1 ) . ')';
                        } else {
                            $myevents[ $event_id ] = __('onbekend event','eme-accounting');
                        }
                    }
                    echo eme_ui_select( $event_id, 'eme_event_id', $myevents, '', 0, 'eme_snapselect_events_class' ); //phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- trusted HTML from eme_ui_select()
                    ?>
                    <span><input id="eventsearch_all" name='eventsearch_all' value='1' type='checkbox'> <?php esc_html_e( 'Vink dit aan om alle evenementen te doorzoeken en niet enkel de scope ingesteld in de settings.', 'events-made-easy' ); ?></span>
                </div>
                <div class="eme-acc-field">
                    <label><?php esc_html_e( 'Bankafschrift referentie', 'eme-accounting' ); ?></label>
                    <input type="text" name="bank_statement_ref" maxlength="100"
                           placeholder="bv. BE68 5390 0754 7034 / 2024-001 of +++123/4567/89012+++"
                           value="<?= esc_attr( $edit_entry->bank_statement_ref ?? '' ) ?>">
                    <span class="description">
                        <?php esc_html_e( 'Optioneel: rekeningnummer tegenpartij, gestructureerde mededeling of volgnummer bankafschrift.', 'eme-accounting' ); ?>
                    </span>
                </div>
                <div class="eme-acc-field">
                    <label><?php esc_html_e( 'Naam tegenpartij', 'eme-accounting' ); ?></label>
                    <input type="text" name="counterparty_name" maxlength="150"
                           placeholder="<?php esc_attr_e( 'Naam van de tegenpartij', 'eme-accounting' ); ?>"
                           value="<?= esc_attr( $edit_entry->counterparty_name ?? '' ) ?>">
                </div>
                <div class="eme-acc-field">
                    <label><?php esc_html_e( 'Betaalmethode', 'eme-accounting' ); ?></label>
                    <select name="payment_method">
                        <option value=""><?php esc_html_e( '—', 'eme-accounting' ); ?></option>
                        <option value="bank"      <?= selected( $edit_entry->payment_method ?? '', 'bank',      false ) ?>><?php esc_html_e( 'Bankoverschrijving', 'eme-accounting' ); ?></option>
                        <option value="cash"      <?= selected( $edit_entry->payment_method ?? '', 'cash',      false ) ?>><?php esc_html_e( 'Cash', 'eme-accounting' ); ?></option>
                        <option value="card"      <?= selected( $edit_entry->payment_method ?? '', 'card',      false ) ?>><?php esc_html_e( 'Bankkaart', 'eme-accounting' ); ?></option>
                        <option value="direct_debit" <?= selected( $edit_entry->payment_method ?? '', 'direct_debit', false ) ?>><?php esc_html_e( 'Domiciliëring', 'eme-accounting' ); ?></option>
                        <option value="mollie"    <?= selected( $edit_entry->payment_method ?? '', 'mollie',    false ) ?>><?php esc_html_e( 'Mollie / Online', 'eme-accounting' ); ?></option>
                    </select>
                </div>
            </div>

            <div class="eme-acc-row">
                <div class="eme-acc-field">
                    <label><?php esc_html_e( 'Bijlage URL', 'eme-accounting' ); ?></label>
                    <input type="url" name="attachment_url"
                           value="<?= esc_attr( $edit_entry->attachment_url ?? '' ) ?>">
                </div>
                <div class="eme-acc-field">
                    <label><?php esc_html_e( 'Bijlagen', 'eme-accounting' ); ?></label>
                    <?php if ( $edit_entry ):
                        $existing_files = EMEAccounting\Journal::get_attachments()->get_delete_links_html( (int) $edit_entry->id, 'journal' );
                        if ( $existing_files ):
                    ?>
                    <div id="eme-acc-journal-attachments" class="eme-acc-attachments">
                        <?= $existing_files // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- trusted HTML from EMEFileAttachments ?>
                    </div>
                    <?php endif; endif; ?>
                    <?php if ( ! $form_disabled ): ?>
                    <input type="file" name="files[]" id="journal-files" multiple
                           accept=".pdf,.jpg,.jpeg,.png,.gif,.webp,.doc,.docx,.xls,.xlsx,.csv,.txt">
                    <span class="description"><?php esc_html_e( 'Optioneel: één of meerdere bestanden (PDF, afbeelding, …)', 'eme-accounting' ); ?></span>
                    <?php endif; ?>
                </div>
            </div>

            <div class="eme-acc-row">
                <div class="eme-acc-field" style="flex:2">
                    <label><?php esc_html_e( 'Notities', 'eme-accounting' ); ?></label>
                    <textarea name="notes"><?= esc_textarea( $edit_entry->notes ?? '' ) ?></textarea>
                </div>
                <?php if ($edit_entry) : ?>
                <div class="eme-acc-field">
                    <label><?php esc_html_e( 'Vereffend?', 'eme-accounting' ); ?></label>
                    <select name="is_reconciled">
                        <option value="0" <?= selected( (int) ( $edit_entry->is_reconciled ?? 1 ), 0, false ) ?>><?php esc_html_e( 'Nee', 'eme-accounting' ); ?></option>
                        <option value="1" <?= selected( (int) ( $edit_entry->is_reconciled ?? 1 ), 1, false ) ?>><?php esc_html_e( 'Ja', 'eme-accounting' ); ?></option>
                    </select>
                </div>
                <?php else: ?>
                    <input type="hidden" name="is_reconciled" value="1">
                <?php endif; ?>
            </div>

            <p>
                <button type="submit" class="button button-primary submit-btn">
                    <?= $edit_entry ? esc_html__( 'Opslaan', 'eme-accounting' ) : esc_html__( 'Boeking toevoegen', 'eme-accounting' ) ?>
                </button>
                <?php if ( $edit_entry ): ?>
                <a href="<?= esc_url( admin_url( 'admin.php?page=emeaccounting-journal' ) ) ?>" class="button">
                    <?php esc_html_e( 'Annuleren', 'eme-accounting' ); ?>
                </a>
                <?php endif; ?>
            </p>
        </fieldset>
        </form>
    </div>

    <!-- Filters -->
    <div class="eme-acc-toolbar">
        <form method="get" id="eme-acc-filter-form">
            <select name="year" id="eme-acc-year-filter">
                <option value=""><?php esc_html_e( 'Filter op boekjaar', 'eme-accounting' ); ?></option>
                <?php foreach ( $fiscal_years as $f ): ?>
                    <option value="<?= (int) $f->year ?>">
                            <?php echo (int) $f->year;
                                  if ( $f->status === 'closed' ) esc_html_e( ' (gesloten)', 'eme-accounting' );
                            ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <input type='text' id='eme-daterange-filter' name='eme-daterange-filter' placeholder='<?php esc_html_e( 'Filter op daterange', 'eme-accounting' ); ?>' data-autoclose='false' data-range='true' data-multiple-separator=' -- ' data-date='' style='width: 22ch;' class='eme_formfield_fdate'>
            <input type="search" placeholder="Filter op omschrijving / bankref" size="22" name="eme-acc-search-filter" id="eme-acc-search-filter">
            <input type="search" placeholder="Filter op tegenpartij" size="18" name="eme-acc-counterparty-filter" id="eme-acc-counterparty-filter">
            <input type="search" placeholder="Bedrag" name="eme-acc-amount-filter" id="eme-acc-amount-filter" size="8">
            <select name="account_id" id="eme-acc-account-filter">
                <option value="0"><?php esc_html_e( 'Alle rekeningen', 'eme-accounting' ); ?></option>
                <?php foreach ( $accounts as $aid => $aname ): ?>
                <option value="<?= $aid ?>" <?= selected( $aid, $account_id, false ) ?>><?= esc_html( $aname ) ?></option>
                <?php endforeach; ?>
            </select>
            <select name="direction" id="eme-acc-direction-filter" >
                <option value=""><?php esc_html_e( 'Alle richtingen', 'eme-accounting' ); ?></option>
                <option value="in"  <?= selected( 'in',  $direction, false ) ?>><?php esc_html_e( 'Ontvangsten', 'eme-accounting' ); ?></option>
                <option value="out" <?= selected( 'out', $direction, false ) ?>><?php esc_html_e( 'Uitgaven', 'eme-accounting' ); ?></option>
                <option value="transfer" <?= selected( 'transfer', $direction, false ) ?>><?php esc_html_e( 'Transfers', 'eme-accounting' ); ?></option>
            </select>
            <select name="coa_id" id="eme-acc-coa-filter" class="eme_snapselect" data-live-search="true">
                <option value=""><?php esc_html_e( '— Kies rekening —', 'eme-accounting' ); ?></option>
                <optgroup label="<?php esc_html_e( 'Ontvangsten', 'eme-accounting' ); ?>">
                <?php foreach ( $coa_accounts as $id => $acc ): 
                    if ( $acc && $acc->direction === 'in' ):
                ?>
                <option value="<?= (int) $id ?>" <?php if (empty($acc->parent_id)) echo 'disabled'; ?> >
                    <?= esc_html( $acc->code . ' - ' . $acc->name ) ?>
                </option>
                <?php endif; endforeach; ?>
                </optgroup>
                <optgroup label="<?php esc_html_e( 'Uitgaven', 'eme-accounting' ); ?>">
                <?php foreach ( $coa_accounts as $id => $acc ): 
                    if ( $acc && $acc->direction === 'out' ):
                ?>
                <option value="<?= (int) $id ?>" <?php if (empty($acc->parent_id)) echo 'disabled'; ?> >
                    <?= esc_html( $acc->code . ' - ' . $acc->name ) ?>
                </option>
                <?php endif; endforeach; ?>
                </optgroup>
            </select>
            <br>
            <?php
                 echo eme_ui_select( 0, 'eme-acc-event-filter', [], '', 0, 'eme_snapselect_filterevents_class' ); //phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- trusted HTML from eme_ui_select()
            ?>
            <span><input id="filter_eventsearch_all" value='1' type='checkbox'> <?php esc_html_e( 'Vink dit aan om alle evenementen te doorzoeken en niet enkel de scope ingesteld in de settings.', 'events-made-easy' ); ?></span>
            <button type="submit" class="button button-primary submit-btn"><?php esc_html_e( 'Filteren', 'eme-accounting' ); ?></button>

        </form>
    </div>

    <!-- Journal entries table -->
    <div id="JournalTableContainer"></div>
</div>
