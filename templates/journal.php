<?php
defined( 'ABSPATH' ) || exit;

$year       = isset( $_GET['year'] )      ? (int) $_GET['year']                : (int) gmdate( 'Y' );
$account_id = isset( $_GET['account_id'] ) ? (int) $_GET['account_id']          : 0;
$direction  = isset( $_GET['direction'] )  ? sanitize_key( $_GET['direction'] ) : '';
$search     = isset( $_GET['s'] )          ? sanitize_text_field( $_GET['s'] )  : '';
$edit_id    = isset( $_GET['edit'] )       ? (int) $_GET['edit']                : 0;

$entries    = EMEAccounting\Journal::get_entries( [
    'fiscal_year' => $year,
    'account_id'  => $account_id ?: null,
    'direction'   => $direction ?: null,
    'search'      => $search ?: null,
    'limit'       => 200,
] );
$accounts   = EMEAccounting\Accounts::get_dropdown();
$coa_accounts = EMEAccounting\ChartOfAccounts::get_dropdown();
$categories = EMEAccounting\Journal::get_categories();
$edit_entry = $edit_id ? EMEAccounting\Journal::get( $edit_id ) : null;
$years      = range( (int) gmdate( 'Y' ), (int) gmdate( 'Y' ) - 4 );
$totals     = EMEAccounting\Journal::get_totals( [ 'fiscal_year' => $year ] );
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
                           value="<?= esc_attr( $edit_entry->entry_date ?? current_time( 'Y-m-d' ) ) ?>">
                </div>
                <div class="eme-acc-field">
                    <label><?php esc_html_e( 'Boekingsdatum bank', 'eme-accounting' ); ?></label>
                    <input type="date" name="booking_date"
                           value="<?= esc_attr( $edit_entry->booking_date ?? '' ) ?>">
                    <span class="description"><?php esc_html_e( 'Valutadatum (optioneel)', 'eme-accounting' ); ?></span>
                </div>
                <div class="eme-acc-field">
                    <label><?php esc_html_e( 'Boekjaar', 'eme-accounting' ); ?></label>
                    <input type="number" name="fiscal_year" id="fiscal_year" min="2000" max="2099"
                           value="<?= esc_attr( $edit_entry->fiscal_year ?? $year ) ?>">
                </div>
            </div>

            <div class="eme-acc-row">
                <div class="eme-acc-field" style="flex:2">
                    <label><?php esc_html_e( 'Omschrijving', 'eme-accounting' ); ?> *</label>
                    <input type="text" name="description" required maxlength="255"
                           value="<?= esc_attr( $edit_entry->description ?? '' ) ?>">
                </div>
                <div class="eme-acc-field">
                    <label><?php esc_html_e( 'Rekeningstelsel', 'eme-accounting' ); ?></label>
                    <select name="category" list="eme-categories">
                        <option value=""><?php esc_html_e( '— Kies rekening —', 'eme-accounting' ); ?></option>
                        <optgroup label="<?php esc_html_e( 'Ontvangsten', 'eme-accounting' ); ?>">
                        <?php foreach ( $coa_accounts as $id => $name ): 
                            $acc = EMEAccounting\ChartOfAccounts::get( (int) $id );
                            if ( $acc && $acc->direction === 'in' ):
                        ?>
                        <option value="<?= esc_attr( $name ) ?>" <?= selected( $edit_entry->category ?? '', $name, false ) ?>>
                            <?= esc_html( $name ) ?>
                        </option>
                        <?php endif; endforeach; ?>
                        </optgroup>
                        <optgroup label="<?php esc_html_e( 'Uitgaven', 'eme-accounting' ); ?>">
                        <?php foreach ( $coa_accounts as $id => $name ): 
                            $acc = EMEAccounting\ChartOfAccounts::get( (int) $id );
                            if ( $acc && $acc->direction === 'out' ):
                        ?>
                        <option value="<?= esc_attr( $name ) ?>" <?= selected( $edit_entry->category ?? '', $name, false ) ?>>
                            <?= esc_html( $name ) ?>
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
                    <label><?php esc_html_e( 'Richting', 'eme-accounting' ); ?></label>
                    <select name="direction">
                        <option value="in"  <?= selected( $edit_entry->direction ?? '', 'in',  false ) ?>><?php esc_html_e( 'Ontvangst (+)', 'eme-accounting' ); ?></option>
                        <option value="out" <?= selected( $edit_entry->direction ?? 'out', 'out', false ) ?>><?php esc_html_e( 'Uitgave (–)', 'eme-accounting' ); ?></option>
                        <option value="transfer" <?= selected( $edit_entry->direction ?? '', 'transfer', false ) ?>><?php esc_html_e( 'Overschrijving', 'eme-accounting' ); ?></option>
                    </select>
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
                    <select name="contra_account_id">
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
                <div class="eme-acc-field">
                    <label><?php esc_html_e( 'Vereffend?', 'eme-accounting' ); ?></label>
                    <select name="is_reconciled">
                        <option value="0" <?= selected( (int) ( $edit_entry->is_reconciled ?? 0 ), 0, false ) ?>><?php esc_html_e( 'Nee', 'eme-accounting' ); ?></option>
                        <option value="1" <?= selected( (int) ( $edit_entry->is_reconciled ?? 0 ), 1, false ) ?>><?php esc_html_e( 'Ja', 'eme-accounting' ); ?></option>
                    </select>
                </div>
            </div>

            <p>
                <button type="submit" class="button button-primary submit-btn">
                    <?= $edit_entry ? esc_html__( 'Opslaan', 'eme-accounting' ) : esc_html__( 'Boeking toevoegen', 'eme-accounting' ) ?>
                </button>
                <?php if ( $edit_entry ): ?>
                <a href="<?= esc_url( admin_url( 'admin.php?page=eme-accounting-journal' ) ) ?>" class="button">
                    <?php esc_html_e( 'Annuleren', 'eme-accounting' ); ?>
                </a>
                <?php endif; ?>
            </p>
        </form>
    </div>

    <!-- Filters -->
    <div class="eme-acc-toolbar">
        <form method="get">
            <input type="hidden" name="page" value="eme-accounting-journal">
            <select name="year" id="eme-acc-year-select" onchange="this.form.submit()">
                <?php foreach ( $years as $y ): ?>
                <option value="<?= $y ?>" <?= selected( $y, $year, false ) ?>><?= $y ?></option>
                <?php endforeach; ?>
            </select>
            <select name="account_id" onchange="this.form.submit()">
                <option value="0"><?php esc_html_e( 'Alle rekeningen', 'eme-accounting' ); ?></option>
                <?php foreach ( $accounts as $aid => $aname ): ?>
                <option value="<?= $aid ?>" <?= selected( $aid, $account_id, false ) ?>><?= esc_html( $aname ) ?></option>
                <?php endforeach; ?>
            </select>
            <select name="direction" onchange="this.form.submit()">
                <option value=""><?php esc_html_e( 'Alle richtingen', 'eme-accounting' ); ?></option>
                <option value="in"  <?= selected( 'in',  $direction, false ) ?>><?php esc_html_e( 'Ontvangsten', 'eme-accounting' ); ?></option>
                <option value="out" <?= selected( 'out', $direction, false ) ?>><?php esc_html_e( 'Uitgaven', 'eme-accounting' ); ?></option>
            </select>
            <input type="text" name="s" placeholder="<?php esc_attr_e( 'Zoeken…', 'eme-accounting' ); ?>" value="<?= esc_attr( $search ) ?>">
            <button type="submit" class="button"><?php esc_html_e( 'Filteren', 'eme-accounting' ); ?></button>
            <div class="spacer"></div>
            <strong>
                <?php printf(
                    esc_html__( 'O: %s  |  U: %s  |  Resultaat: %s', 'eme-accounting' ),
                    number_format( $totals['income'],   2, ',', '.' ) . ' €',
                    number_format( $totals['expenses'], 2, ',', '.' ) . ' €',
                    number_format( $totals['net'],      2, ',', '.' ) . ' €'
                ); ?>
            </strong>
        </form>
    </div>

    <!-- Entries table -->
    <table class="eme-acc-table">
        <thead>
            <tr>
                <th><?php esc_html_e( 'Datum', 'eme-accounting' ); ?></th>
                <th><?php esc_html_e( 'Omschrijving', 'eme-accounting' ); ?></th>
                <th><?php esc_html_e( 'Categorie', 'eme-accounting' ); ?></th>
                <th><?php esc_html_e( 'Bankref.', 'eme-accounting' ); ?></th>
                <th><?php esc_html_e( 'Rekening', 'eme-accounting' ); ?></th>
                <th class="amount"><?php esc_html_e( 'Bedrag', 'eme-accounting' ); ?></th>
                <th><?php esc_html_e( 'V', 'eme-accounting' ); ?></th>
                <th><?php esc_html_e( 'Acties', 'eme-accounting' ); ?></th>
            </tr>
        </thead>
        <tbody>
        <?php if ( empty( $entries ) ): ?>
            <tr><td colspan="8"><?php esc_html_e( 'Geen boekingen gevonden.', 'eme-accounting' ); ?></td></tr>
        <?php else: ?>
            <?php foreach ( $entries as $e ): ?>
            <tr data-journal-row="<?= (int) $e->id ?>">
                <td><?= esc_html( date_i18n( 'd/m/Y', strtotime( $e->entry_date ) ) ) ?></td>
                <td><?= esc_html( $e->description ) ?></td>
                <td><?= $e->category ? '<span class="tag">' . esc_html( $e->category ) . '</span>' : '' ?></td>
                <td style="font-size:11px;color:#646970;max-width:120px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?= esc_attr( $e->bank_statement_ref ) ?>">
                    <?= esc_html( $e->bank_statement_ref ) ?>
                </td>
                <td><?= esc_html( $e->account_name ?? '' ) ?></td>
                <td class="amount <?= esc_attr( $e->direction ) ?>">
                    <?= $e->direction === 'in' ? '+' : '–' ?>
                    <?= number_format( (float) $e->amount, 2, ',', '.' ) ?> €
                </td>
                <td>
                    <?php if ( $e->is_reconciled ): ?>
                    <span class="tag reconciled" title="<?= esc_attr( date_i18n( 'd/m/Y', strtotime( $e->reconciled_at ?? '' ) ) ) ?>">✓</span>
                    <?php endif; ?>
                </td>
                <td>
                    <a href="<?= esc_url( admin_url( 'admin.php?page=eme-accounting-journal&edit=' . $e->id ) ) ?>" class="button button-small">
                        <?php esc_html_e( 'Bewerk', 'eme-accounting' ); ?>
                    </a>
                    <button class="button button-small eme-acc-delete-entry" data-id="<?= (int) $e->id ?>">
                        <?php esc_html_e( 'Verwijder', 'eme-accounting' ); ?>
                    </button>
                </td>
            </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>
</div>
