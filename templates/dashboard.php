<?php
defined( 'ABSPATH' ) || exit;

$formatter = new NumberFormatter('nl_BE',  NumberFormatter::CURRENCY);
$config =  \EMEVzw\Config::get();
$currency  = $config->currency;

$year       = isset( $_GET['year'] ) ? (int) $_GET['year'] : (int) gmdate( 'Y' );
$totals     = EMEAccounting\Journal::get_totals( [ 'fiscal_year' => $year ] );
$accounts   = EMEAccounting\Accounts::get_all();
$recent     = EMEAccounting\Journal::get_entries( [ 'fiscal_year' => $year ], '', 'LIMIT 10' );
$fiscal_years = EMEAccounting\FiscalYear::get_all();
?>
<div class="wrap eme-acc-wrap">
<fieldset <?= $form_disabled ?>>
    <h1><?php esc_html_e( 'EME Boekhouding – Dashboard', 'eme-accounting' ); ?></h1>

    <div class="eme-acc-toolbar">
        <label><?php esc_html_e( 'Boekjaar:', 'eme-accounting' ); ?>
        <select id="eme-acc-year-select" onchange="window.location='?page=emeaccounting&year='+this.value">
            <?php foreach ( $fiscal_years as $f ): ?>
            <option value="<?= (int) $f->year ?>" <?= selected( $f->year, $year, false ) ?>>
            <?php echo (int) $f->year;
                  if ( $f->status === 'closed' ) esc_html_e( ' (gesloten)', 'eme-accounting' );
            ?>
            </option>
            <?php endforeach; ?>
        </select>
        </label>
        <div class="spacer"></div>
        <a href="<?= esc_url( admin_url( 'admin.php?page=emeaccounting-journal' ) ); ?>" class="button button-primary">
            + <?php esc_html_e( 'Nieuwe boeking', 'eme-accounting' ); ?>
        </a>
        <a href="<?= esc_url( admin_url( 'admin.php?page=emeaccounting-import' ) ); ?>" class="button">
            <?php esc_html_e( 'Bankimport', 'eme-accounting' ); ?>
        </a>
    </div>

    <!-- KPI Cards -->
    <div class="eme-acc-cards">
        <div class="eme-acc-card">
            <h3><?php esc_html_e( 'Ontvangsten', 'eme-accounting' ); ?> <?= $year ?></h3>
            <div class="card-value positive">
                <?= $formatter->formatCurrency( $totals['income'], $currency ); ?>
            </div>
        </div>
        <div class="eme-acc-card">
            <h3><?php esc_html_e( 'Uitgaven', 'eme-accounting' ); ?> <?= $year ?></h3>
            <div class="card-value negative">
                <?= $formatter->formatCurrency( $totals['expenses'], $currency ); ?>
            </div>
        </div>
        <div class="eme-acc-card">
            <h3><?php esc_html_e( 'Resultaat', 'eme-accounting' ); ?> <?= $year ?></h3>
            <div class="card-value <?= $totals['net'] >= 0 ? 'positive' : 'negative' ?>">
                <?= $formatter->formatCurrency( $totals['net'], $currency ); ?>
            </div>
        </div>
        <?php $balances = EMEAccounting\Accounts::get_all_balances(); ?>
        <?php foreach ( $accounts as $acc ): ?>
        <div class="eme-acc-card">
            <h3><?= esc_html( $acc->name ); ?></h3>
            <div class="card-value <?= ( $balances[ (int) $acc->id ] ?? 0 ) >= 0 ? '' : 'negative' ?>">
                <?= $formatter->formatCurrency( $balances[ (int) $acc->id ] ?? 0, $currency ); ?>
            </div>
            <div class="card-sub"><?= esc_html( $acc->iban ); ?></div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Recent entries -->
    <h2><?php esc_html_e( 'Recente boekingen', 'eme-accounting' ); ?></h2>
    <table class="eme-acc-table">
        <thead>
            <tr>
                <th><?php esc_html_e( 'Datum', 'eme-accounting' ); ?></th>
                <th><?php esc_html_e( 'Omschrijving', 'eme-accounting' ); ?></th>
                <th><?php esc_html_e( 'Categorie', 'eme-accounting' ); ?></th>
                <th><?php esc_html_e( 'Bankref.', 'eme-accounting' ); ?></th>
                <th><?php esc_html_e( 'Rekening', 'eme-accounting' ); ?></th>
                <th class="amount"><?php esc_html_e( 'Bedrag', 'eme-accounting' ); ?></th>
                <th><?php esc_html_e( 'Vereff.', 'eme-accounting' ); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php if ( empty( $recent ) ): ?>
            <tr><td colspan="7"><?php esc_html_e( 'Geen boekingen gevonden.', 'eme-accounting' ); ?></td></tr>
            <?php else: ?>
            <?php foreach ( $recent as $entry ): ?>
            <tr data-journal-row="<?= (int) $entry->id ?>">
                <td><?= esc_html( date_i18n( 'd/m/Y', strtotime( $entry->entry_date ) ) ); ?></td>
                <td><?= esc_html( $entry->description ); ?></td>
                <td><?= $entry->coa_name ? '<span class="eme-acc-tag">' . esc_html( $entry->coa_name ) . '</span>' : ''; ?></td>
                <td style="font-size:11px;color:#646970"><?= esc_html( $entry->bank_statement_ref ); ?></td>
                <td><?= esc_html( $entry->account_name ?? '' ); ?></td>
                <td class="amount <?= esc_attr( $entry->direction ) ?>">
                    <?php if ($entry->direction === 'out')
                            $real_amount = -1 * $entry->amount;
                          else
                            $real_amount = $entry->amount;
                          echo $formatter->formatCurrency($real_amount, $currency );
                    ?>
                </td>
                <td>
                    <?php if ( $entry->is_reconciled ): ?>
                        <span class="eme-acc-tag eme-acc-reconciled">✓</span>
                    <?php else: ?>
                        <span class="eme-acc-tag eme-acc-pending"><?php esc_html_e( 'Openstaand', 'eme-accounting' ); ?></span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>

    <p style="margin-top:12px">
        <a href="<?= esc_url( admin_url( 'admin.php?page=emeaccounting-journal' ) ); ?>">
            <?php esc_html_e( '→ Alle boekingen bekijken', 'eme-accounting' ); ?>
        </a>
        &nbsp;|&nbsp;
        <a href="<?= esc_url( admin_url( 'admin.php?page=emeaccounting-reports' ) ); ?>">
            <?php esc_html_e( '→ Rapporten', 'eme-accounting' ); ?>
        </a>
    </p>
</fieldset>
</div>
