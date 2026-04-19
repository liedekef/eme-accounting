<?php
defined( 'ABSPATH' ) || exit;
$formatter = new NumberFormatter('nl_BE',  NumberFormatter::CURRENCY);
$config =  \EMEVzw\Config::get();
$currency  = $config->currency;

$year        = isset( $_GET['year'] )       ? (int) $_GET['year']                : (int) gmdate( 'Y' );
$account_id  = isset( $_GET['account_id'] ) ? (int) $_GET['account_id']          : 0;
$direction   = isset( $_GET['direction'] )  ? sanitize_key( $_GET['direction'] ) : '';
$edit_id     = isset( $_GET['edit'] )       ? (int) $_GET['edit']                : 0;

$accounts    = EMEAccounting\Accounts::get_dropdown();
$coa_accounts = EMEAccounting\ChartOfAccounts::get_dropdown();
$edit_entry  = $edit_id ? EMEAccounting\Journal::get( $edit_id ) : null;
$years       = range( (int) gmdate( 'Y' ), (int) gmdate( 'Y' ) - 4 );
$totals      = EMEAccounting\Journal::get_totals( [ 'fiscal_year' => $year ] );
?>
<div class="wrap eme-acc-wrap">
    <h1><?php esc_html_e( 'Financieel Dagboek', 'eme-accounting' ); ?></h1>

    <!-- Add / Edit form -->
    <div class="eme-acc-form-box">
        <h2><?= $edit_entry ? esc_html__( 'Boeking bewerken', 'eme-accounting' ) : esc_html__( 'Nieuwe boeking', 'eme-accounting' ) ?></h2>
        <form id="eme-acc-journal-form">
            <?php if ( $edit_entry ): ?>
            <input type="hidden" name="id" value="<?= (int) $edit_entry->id ?>">
            <?php endif; ?>
            <div class="eme-acc-row">
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

            <div class="eme-acc-row">
                <div class="eme-acc-field" style="flex:2">
                    <label><?php esc_html_e( 'Omschrijving', 'eme-accounting' ); ?> *</label>
                    <input type="text" name="description" required maxlength="255"
                           value="<?= esc_attr( $edit_entry->description ?? '' ) ?>">
                </div>
                <div class="eme-acc-field">
                    <label><?php esc_html_e( 'Richting', 'eme-accounting' ); ?></label>
                    <?php if ($edit_entry) : ?>
                    <input type="hidden" name="direction" id="direction" value="<?=$edit_entry->direction ?? '' ?>">
                    <?= $edit_entry->direction == "in" ? esc_html_e( 'Ontvangst (+)', 'eme-accounting' ) : '' ?>
                    <?= $edit_entry->direction == "out" ? esc_html_e( 'Uitgave (-)', 'eme-accounting' ) : '' ?>
                    <?= $edit_entry->direction == "transfer" ? esc_html_e( 'Transfer', 'eme-accounting' ) : '' ?>
                    <?php else: ?>
                    <select name="direction" id="direction">
                        <option value="in"  <?= selected( $edit_entry->direction ?? '', 'in',  false ) ?>><?php esc_html_e( 'Ontvangst (+)', 'eme-accounting' ); ?></option>
                        <option value="out" <?= selected( $edit_entry->direction ?? '', 'out', false ) ?>><?php esc_html_e( 'Uitgave (–)', 'eme-accounting' ); ?></option>
                        <option value="transfer" <?= selected( $edit_entry->direction ?? '', 'transfer', false ) ?>><?php esc_html_e( 'Transfer', 'eme-accounting' ); ?></option>
                    </select>
                    <?php endif; ?>
                </div>
                <div class="eme-acc-field">
                    <label><?php esc_html_e( 'Rekeningstelsel', 'eme-accounting' ); ?></label>
                    <select name="coa_id" id="category_coa_id" required class="eme_snapselect" data-live-search="true">
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

            <div class="eme-acc-row">
                <div class="eme-acc-field">
                    <label><?php esc_html_e( 'Bedrag (€)', 'eme-accounting' ); ?> *</label>
                    <input type="number" name="amount" required step="0.01" min="0.01"
                           value="<?= esc_attr( $edit_entry->amount ?? '' ) ?>">
                </div>
                <div class="eme-acc-field">
                    <label><?php esc_html_e( 'Event', 'eme-accounting' ); ?> *</label>
                    <?php
                    $event_id  = 0;
                    $myevents  = [];
                    if ( ! empty( $conditions['event_id'] ) ) {
                        $event_id = $conditions['event_id'];
                        $events    = eme_get_events( extra_conditions: [ 'event_id' => array_map( 'intval', $event_ids ) ] );
                        foreach ( $events as $event ) {
                            $myevents[ $event['event_id'] ] = $event['event_name']. ' (' . eme_localized_date( $event['event_start'], EME_TIMEZONE, 1 ) . ')';
                        }
                    }
                    echo eme_ui_select( $event_id, 'eme_event_id', $myevents, '', 0, 'eme_snapselect_events_class' ); //phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- trusted HTML from eme_ui_select()
                    ?>
                    <input id="eventsearch_all" name='eventsearch_all' value='1' type='checkbox'> <?php esc_html_e( 'Check this box to search through all events and not just future ones.', 'events-made-easy' ); ?>
                </div>
                <div class="eme-acc-field">
                    <label><?php esc_html_e( 'Rekening', 'eme-accounting' ); ?> *</label>
                    <select name="account_id" required>
                        <option value=""><?php esc_html_e( '— Kies rekening —', 'eme-accounting' ); ?></option>
                        <?php foreach ( $accounts as $id => $name ): ?>
                        <option value="<?= (int) $id ?>" <?= selected( $edit_entry->account_id ?? 0, $id, false ) ?>>
                            <?= esc_html( $name ) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="eme-acc-field">
                    <label><?php esc_html_e( 'Tegenrekening', 'eme-accounting' ); ?></label>
                    <select name="contra_account_id" id="contra_account_id">
                        <option value=""><?php esc_html_e( '— (geen) —', 'eme-accounting' ); ?></option>
                        <?php foreach ( $accounts as $id => $name ): ?>
                        <option value="<?= (int) $id ?>" <?= selected( $edit_entry->contra_account_id ?? 0, $id, false ) ?>>
                            <?= esc_html( $name ) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <!-- Bank statement info -->
            <div class="eme-acc-row">
                <div class="eme-acc-field" style="flex:2">
                    <label><?php esc_html_e( 'Bankafschrift referentie', 'eme-accounting' ); ?></label>
                    <input type="text" name="bank_statement_ref" maxlength="100"
                           placeholder="bv. BE68 5390 0754 7034 / 2024-001 of +++123/4567/89012+++"
                           value="<?= esc_attr( $edit_entry->bank_statement_ref ?? '' ) ?>">
                    <span class="description">
                        <?php esc_html_e( 'Optioneel: rekeningnummer tegenpartij, gestructureerde mededeling of volgnummer bankafschrift.', 'eme-accounting' ); ?>
                    </span>
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
                <div class="eme-acc-field">
                    <label><?php esc_html_e( 'Bijlage URL', 'eme-accounting' ); ?></label>
                    <input type="url" name="attachment_url"
                           value="<?= esc_attr( $edit_entry->attachment_url ?? '' ) ?>">
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
        </form>
    </div>

    <!-- Filters -->
    <div class="eme-acc-toolbar">
        <form method="get" id="eme-acc-filter-form">
            <select name="year" id="eme-acc-year-filter">
                <option value=""></option>
                <?php foreach ( $years as $y ): ?>
                <option value="<?= $y ?>" ><?= $y ?></option>
                <?php endforeach; ?>
            </select>
            <input type="search" placeholder="Filter op omschrijving" name="eme-acc-search-filter" id="eme-acc-search-filter">
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
            <div class="spacer"></div>
            <strong id="eme-acc-totals">
                <?php printf(
                    esc_html__( 'Ontvangen: %s  |  Uitgaven: %s  |  Resultaat: %s', 'eme-accounting' ),
                    $formatter->formatCurrency( $totals['income'], $currency),
                    $formatter->formatCurrency( $totals['expenses'], $currency ),
                    $formatter->formatCurrency( $totals['net'], $currency )
                ); ?>
            </strong>
        </form>
    </div>

    <!-- Journal entries table -->
    <div id="JournalTableContainer"></div>
</div>
