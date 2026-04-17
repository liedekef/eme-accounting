<?php
defined( 'ABSPATH' ) || exit;

$fiscal_years = EMEAccounting\FiscalYear::get_all();
$formatter = new NumberFormatter( 'nl_BE', NumberFormatter::CURRENCY );
$current_year = (int) gmdate( 'Y' );

if ( empty( $fiscal_years ) ) {
    EMEAccounting\FiscalYear::ensure_current_year_exists();
    $fiscal_years = EMEAccounting\FiscalYear::get_all();
}

$settings = get_option( 'eme_accounting_settings', [] );
$default_start_month = (int) ( $settings['fiscal_year_start_month'] ?? 1 );
$default_start_day   = (int) ( $settings['fiscal_year_start_day'] ?? 1 );
$default_end_month   = (int) ( $settings['fiscal_year_end_month'] ?? 12 );
$default_end_day     = (int) ( $settings['fiscal_year_end_day'] ?? 31 );
?>
<div class="wrap eme-acc-wrap">
    <h1><?php esc_html_e( 'Boekjaren', 'eme-accounting' ); ?></h1>

    <?php if ( empty( $settings['fiscal_year_start_month'] ) || empty( $settings['fiscal_year_end_month'] ) ): ?>
    <div class="notice notice-warning">
        <p>
            <?php esc_html_e( 'Stel de start- en einddatum van het boekjaar in via', 'eme-accounting' ); ?>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=eme-accounting-settings' ) ); ?>">
                <?php esc_html_e( 'de instellingen', 'eme-accounting' ); ?>
            </a>
            <?php esc_html_e( 'voordat u boekjaren toevoegt.', 'eme-accounting' ); ?>
        </p>
    </div>
    <?php endif; ?>

    <div class="eme-acc-form-box">
        <h2><?php esc_html_e( 'Boekjaar toevoegen of bewerken', 'eme-accounting' ); ?></h2>
        <form id="eme-acc-fiscal-year-form">
            <input type="hidden" name="id" id="fy_id" value="">
            <div class="eme-acc-row">
                <div class="eme-acc-field">
                    <label><?php esc_html_e( 'Jaar', 'eme-accounting' ); ?></label>
                    <input type="number" name="year" id="fy_year_input" min="1900" max="2100" value="<?= $current_year ?>" required>
                </div>
                <div class="eme-acc-field">
                    <label><?php esc_html_e( 'Startdatum', 'eme-accounting' ); ?></label>
                    <input type="date" id="fy_start_date" required>
                </div>
                <div class="eme-acc-field">
                    <label><?php esc_html_e( 'Einddatum', 'eme-accounting' ); ?></label>
                    <input type="date" id="fy_end_date" required>
                </div>
                <div class="eme-acc-field" style="align-self:flex-end">
                    <button type="submit" class="button button-primary" id="fy_submit">
                        <?php esc_html_e( 'Opslaan', 'eme-accounting' ); ?>
                    </button>
                    <button type="button" class="button" id="fy_cancel" style="display:none">
                        <?php esc_html_e( 'Annuleren', 'eme-accounting' ); ?>
                    </button>
                </div>
            </div>
            <div id="fy-message" style="margin-top:10px"></div>
        </form>
    </div>

    <div class="eme-acc-form-box">
        <h2><?php esc_html_e( 'Overzicht Boekjaren', 'eme-accounting' ); ?></h2>
        <?php if ( empty( $fiscal_years ) ): ?>
        <p><?php esc_html_e( 'Nog geen boekjaren gedefinieerd.', 'eme-accounting' ); ?></p>
        <?php else: ?>
        <table class="eme-acc-table">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Boekjaar', 'eme-accounting' ); ?></th>
                    <th><?php esc_html_e( 'Periode', 'eme-accounting' ); ?></th>
                    <th><?php esc_html_e( 'Status', 'eme-accounting' ); ?></th>
                    <th><?php esc_html_e( 'Afgesloten op', 'eme-accounting' ); ?></th>
                    <th><?php esc_html_e( 'Door', 'eme-accounting' ); ?></th>
                    <th><?php esc_html_e( 'Resultaat', 'eme-accounting' ); ?></th>
                    <th><?php esc_html_e( 'Acties', 'eme-accounting' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $fiscal_years as $fy ): 
                    $result = EMEAccounting\FiscalYear::get_year_result( (int) $fy->year );
                    $closed_by_user = $fy->closed_by ? get_userdata( (int) $fy->closed_by ) : null;
                ?>
                <tr data-year="<?= (int) $fy->year ?>">
                    <td><strong><?= (int) $fy->year ?></strong></td>
                    <td>
                        <?= esc_html( date_i18n( 'd/m/Y', strtotime( $fy->start_date ) ) ) ?>
                        –
                        <?= esc_html( date_i18n( 'd/m/Y', strtotime( $fy->end_date ) ) ) ?>
                    </td>
                    <td>
                        <?php if ( $fy->status === 'closed' ): ?>
                        <span class="eme-acc-tag" style="background:#d63638;color:#fff">
                            <?php esc_html_e( 'Gesloten', 'eme-accounting' ); ?>
                        </span>
                        <?php else: ?>
                        <span class="eme-acc-tag eme-acc-reconciled">
                            <?php esc_html_e( 'Open', 'eme-accounting' ); ?>
                        </span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ( $fy->closed_at ): ?>
                            <?= esc_html( date_i18n( 'd/m/Y H:i', strtotime( $fy->closed_at ) ) ) ?>
                        <?php else: ?>
                            —
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ( $closed_by_user ): ?>
                            <?= esc_html( $closed_by_user->display_name ) ?>
                        <?php else: ?>
                            —
                        <?php endif; ?>
                    </td>
                    <td class="amount <?= $result >= 0 ? 'in' : 'out' ?>">
                        <?= $result >= 0 ? '+' : '–' ?>
                        <?= $formatter->formatCurrency( abs( $result ), 'EUR' ) ?>
                    </td>
                    <td>
                        <?php if ( $fy->status === 'open' ): ?>
                        <button type="button" class="button btn-close" data-year="<?= (int) $fy->year ?>">
                            <?php esc_html_e( 'Afsluiten', 'eme-accounting' ); ?>
                        </button>
                        <?php else: ?>
                        <button type="button" class="button btn-reopen" data-year="<?= (int) $fy->year ?>">
                            <?php esc_html_e( 'Heropenen', 'eme-accounting' ); ?>
                        </button>
                        <?php endif; ?>
                        <?php if ( $fy->status === 'open' ): ?>
                        <button type="button" class="button btn-edit" data-id="<?= (int) $fy->id ?>"
                                data-year="<?= (int) $fy->year ?>"
                                data-start="<?= esc_attr( $fy->start_date ) ?>"
                                data-end="<?= esc_attr( $fy->end_date ) ?>">
                            <?php esc_html_e( 'Bewerken', 'eme-accounting' ); ?>
                        </button>
                        <button type="button" class="button btn-delete" data-year="<?= (int) $fy->year ?>">
                            <?php esc_html_e( 'Verwijderen', 'eme-accounting' ); ?>
                        </button>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>
