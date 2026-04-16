<?php
defined( 'ABSPATH' ) || exit;

$formatter = new NumberFormatter('nl_BE',  NumberFormatter::CURRENCY);

$year       = isset( $_GET['year'] ) ? (int) $_GET['year'] : (int) gmdate( 'Y' );
$totals     = EMEAccounting\Journal::get_totals( [ 'fiscal_year' => $year ] );
$accounts   = EMEAccounting\Accounts::get_all();
$recent     = EMEAccounting\Journal::get_entries( [ 'fiscal_year' => $year, 'limit' => 10 ] );
$years      = range( (int) gmdate( 'Y' ), (int) gmdate( 'Y' ) - 4 );
?>
<div class="wrap eme-acc-wrap">
    <h1><?php esc_html_e( 'EME Boekhouding – Dashboard', 'eme-accounting' ); ?></h1>

    <div class="eme-acc-toolbar">
        <form method="get">
            <input type="hidden" name="page" value="eme-accounting">
            <label><?php esc_html_e( 'Boekjaar:', 'eme-accounting' ); ?>
                <select name="year" id="eme-acc-year-select" onchange="this.form.submit()">
                    <?php foreach ( $years as $y ): ?>
                    <option value="<?= $y ?>" <?= selected( $y, $year, false ); ?>><?= $y ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        </form>
        <div class="spacer"></div>
        <a href="<?= esc_url( admin_url( 'admin.php?page=eme-accounting-journal' ) ); ?>" class="button button-primary">
            + <?php esc_html_e( 'Nieuwe boeking', 'eme-accounting' ); ?>
        </a>
        <a href="<?= esc_url( admin_url( 'admin.php?page=eme-accounting-import' ) ); ?>" class="button">
            <?php esc_html_e( 'Bankimport', 'eme-accounting' ); ?>
        </a>
    </div>

    <!-- KPI Cards -->
    <div class="eme-acc-cards">
        <div class="eme-acc-card">
            <h3><?php esc_html_e( 'Ontvangsten', 'eme-accounting' ); ?> <?= $year ?></h3>
            <div class="card-value positive">
                <?= $formatter->formatCurrency( $totals['income'], 'EUR' ); ?>
            </div>
        </div>
        <div class="eme-acc-card">
            <h3><?php esc_html_e( 'Uitgaven', 'eme-accounting' ); ?> <?= $year ?></h3>
            <div class="card-value negative">
                <?= $formatter->formatCurrency( $totals['expenses'], 'EUR' ); ?>
            </div>
        </div>
        <div class="eme-acc-card">
            <h3><?php esc_html_e( 'Resultaat', 'eme-accounting' ); ?> <?= $year ?></h3>
            <div class="card-value <?= $totals['net'] >= 0 ? 'positive' : 'negative' ?>">
                <?= $formatter->formatCurrency( $totals['net'], 'EUR' ); ?>
            </div>
        </div>
        <?php foreach ( array_slice( $accounts, 0, 3 ) as $acc ): ?>
        <div class="eme-acc-card">
            <h3><?= esc_html( $acc->name ); ?></h3>
            <?php $bal = EMEAccounting\Accounts::get_balance( (int) $acc->id ); ?>
            <div class="card-value <?= $bal >= 0 ? '' : 'negative' ?>">
                <?= $formatter->formatCurrency( $bal, 'EUR' ); ?>
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
                <td><?= $entry->coa_name ? '<span class="tag">' . esc_html( $entry->coa_name ) . '</span>' : ''; ?></td>
                <td style="font-size:11px;color:#646970"><?= esc_html( $entry->bank_statement_ref ); ?></td>
                <td><?= esc_html( $entry->account_name ?? '' ); ?></td>
                <td class="amount <?= esc_attr( $entry->direction ) ?>">
                    <?php if ($entry->direction === 'out')
                            $real_amount = -1 * $entry->amount;
                          else
                            $real_amount = $entry->amount;
                          echo $formatter->formatCurrency($real_amount, 'EUR' );
                    ?>
                </td>
                <td>
                    <?php if ( $entry->is_reconciled ): ?>
                        <span class="tag reconciled">✓</span>
                    <?php else: ?>
                        <span class="tag pending"><?php esc_html_e( 'Openstaand', 'eme-accounting' ); ?></span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>

    <p style="margin-top:12px">
        <a href="<?= esc_url( admin_url( 'admin.php?page=eme-accounting-journal&year=' . $year ) ); ?>">
            <?php esc_html_e( '→ Alle boekingen bekijken', 'eme-accounting' ); ?>
        </a>
        &nbsp;|&nbsp;
        <a href="<?= esc_url( admin_url( 'admin.php?page=eme-accounting-reports' ) ); ?>">
            <?php esc_html_e( '→ Rapporten', 'eme-accounting' ); ?>
        </a>
    </p>
</div>
