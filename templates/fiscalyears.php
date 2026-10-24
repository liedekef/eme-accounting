<?php
// phpcs:ignoreFile WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
defined( 'ABSPATH' ) || exit;

$fiscal_years = EMEAccounting\FiscalYear::get_all();
$formatter = new NumberFormatter( 'nl_BE', NumberFormatter::CURRENCY );
$config =  \EMEVzw\Config::get();
$currency  = $config->currency;

$current_year = (int) gmdate( 'Y' );

$config_ready = ! empty( $config->fiscal_year_start_mm ) && ! empty( $config->fiscal_year_end_mm );
$today        = gmdate( 'Y-m-d' );

$current_fiscal_year = EMEAccounting\FiscalYear::get_for_date( $today );
$missing_current     = $config_ready && ! $current_fiscal_year;
$suggested_year      = $missing_current
    ? EMEAccounting\FiscalYear::get_year_for_date( $today )
    : $current_year;

?>
<div class="wrap eme-acc-wrap">
<fieldset <?php echo $form_disabled; //phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
    <h1><?php esc_html_e( 'Boekjaren', 'eme-accounting' ); ?></h1>

    <?php if ( $missing_current ): ?>
    <div class="notice notice-info">
        <p>
            <?php
            printf(
                /* translators: %d is the suggested fiscal year label. */
                esc_html__( 'Er bestaat nog geen boekjaar voor de huidige periode (boekjaar %d). Gebruik het formulier hieronder om het aan te maken.', 'eme-accounting' ),
                (int) $suggested_year
            );
            ?>
        </p>
    </div>
    <?php endif; ?>

    <?php if ( empty( $config->fiscal_year_start_mm) || empty( $config->fiscal_year_end_mm ) ): ?>
    <div class="notice notice-warning">
        <p>
            <?php esc_html_e( 'Stel de start- en einddatum van het boekjaar in via', 'eme-accounting' ); ?>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=emeaccounting-settings' ) ); ?>">
                <?php esc_html_e( 'de instellingen', 'eme-accounting' ); ?>
            </a>
            <?php esc_html_e( 'voordat u boekjaren toevoegt.', 'eme-accounting' ); ?>
        </p>
    </div>
    <?php endif; ?>

    <div class="eme-acc-form-box">
        <h2><?php esc_html_e( 'Boekjaar toevoegen', 'eme-accounting' ); ?></h2>
        <form id="eme-acc-fiscal-year-form">
            <input type="hidden" name="id" id="fy_id" value="">
            <label for="fy_year_input"><?php esc_html_e( 'Jaar', 'eme-accounting' ); ?></label>
            <input type="number" name="year" id="fy_year_input" min="2015" max="2100" placeholder="<?php echo esc_attr( $current_year ); ?>" value="<?php echo $missing_current ? esc_attr( $suggested_year ) : ''; ?>" required>
            <?php if ( ! empty( $config->fiscal_year_start_mm ) && ! empty( $config->fiscal_year_end_mm ) ): ?>
                  <button type="submit" class="button button-primary submit-btn" id="fy_submit">
                      <?php esc_html_e( 'Toevoegen', 'eme-accounting' ); ?>
                  </button>
            <?php endif; ?>
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
                <tr data-year="<?php echo (int) $fy->year; ?>">
                    <td><strong><?php echo (int) $fy->year; ?></strong></td>
                    <td>
                        <?php echo esc_html( date_i18n( 'd/m/Y', strtotime( $fy->start_date ) ) ); ?>
                        –
                        <?php echo esc_html( date_i18n( 'd/m/Y', strtotime( $fy->end_date ) ) ); ?>
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
                            <?php echo esc_html( date_i18n( 'd/m/Y H:i', strtotime( $fy->closed_at ) ) ); ?>
                        <?php else: ?>
                            —
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ( $closed_by_user ): ?>
                            <?php echo esc_html( $closed_by_user->display_name ); ?>
                        <?php else: ?>
                            —
                        <?php endif; ?>
                    </td>
                    <td class="amount <?php echo $result >= 0 ? 'in' : 'out'; ?>">
                        <?php echo $result >= 0 ? '+' : '–'; ?>
                        <?php echo esc_html( $formatter->formatCurrency( abs( $result ), $currency ) ); ?>
                    </td>
                    <td>
                        <?php if ( $fy->status === 'open' ): ?>
                        <button type="button" class="button btn-close" data-year="<?php echo (int) $fy->year; ?>">
                            <?php esc_html_e( 'Afsluiten', 'eme-accounting' ); ?>
                        </button>
                        <?php else: ?>
                        <button type="button" class="button btn-reopen" data-year="<?php echo (int) $fy->year; ?>">
                            <?php esc_html_e( 'Heropenen', 'eme-accounting' ); ?>
                        </button>
                        <?php endif; ?>
                        <?php if ( $fy->status === 'open' ): ?>
                        <button type="button" class="button btn-delete" data-year="<?php echo (int) $fy->year; ?>">
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
</fieldset>
</div>
