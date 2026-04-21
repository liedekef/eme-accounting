<?php // eme-accounting/templates/coa.php
defined( 'ABSPATH' ) || exit;

$accounts     = EMEAccounting\ChartOfAccounts::get_tree();
$all_accounts = EMEAccounting\ChartOfAccounts::get_all();
?>
<div class="wrap eme-acc-wrap">
<fieldset <?= $form_disabled ?>>
<h1><?php esc_html_e( 'Rekeningstelsel', 'eme-accounting' ); ?></h1>

<div class="eme-acc-form-box">
<h2 id="eme-acc-coa-form-title"><?php esc_html_e( 'Rekening toevoegen', 'eme-accounting' ); ?></h2>
<form id="eme-acc-coa-form">
<input type="hidden" name="id" value="0">
<div class="eme-acc-row">
    <div class="eme-acc-field"><label><?php esc_html_e( 'Code', 'eme-accounting' ); ?> *</label><input type="text" name="code" required maxlength="20" placeholder="01-000001" class="eme-acc-code-input"></div>
    <div class="eme-acc-field"><label><?php esc_html_e( 'Richting', 'eme-accounting' ); ?></label>
         <select name="direction">
             <option value="out"><?php esc_html_e( 'Uitgave', 'eme-accounting' ); ?></option>
             <option value="in"><?php esc_html_e( 'Ontvangst', 'eme-accounting' ); ?></option>
         </select>
    </div>
    <div class="eme-acc-field"><label><?php esc_html_e( 'Actief', 'eme-accounting' ); ?></label>
        <select name="is_active"><option value="1"><?php esc_html_e( 'Ja', 'eme-accounting' ); ?></option><option value="0"><?php esc_html_e( 'Nee', 'eme-accounting' ); ?></option></select>
    </div>
    <div class="eme-acc-field" style="flex:3"><label><?php esc_html_e( 'Naam', 'eme-accounting' ); ?> *</label><input type="text" name="name" required maxlength="200"></div>
</div>
<div class="eme-acc-row">
    <div class="eme-acc-field"><label><?php esc_html_e( 'Hoofdrekening', 'eme-accounting' ); ?></label>
        <select name="parent_id" class="eme-acc-parent-select">
            <option value="">— <?php esc_html_e( 'Geen (hoofdrekening)', 'eme-accounting' ); ?> —</option>
            <?php
            $main_in  = array_filter( $all_accounts, fn( $a ) => empty( $a->parent_id ) && $a->direction === 'in' && ! empty( $a->is_active ) );
            $main_out = array_filter( $all_accounts, fn( $a ) => empty( $a->parent_id ) && $a->direction === 'out' && ! empty( $a->is_active ) );
            ?>
            <?php if ( $main_in ): ?>
            <optgroup label="<?php esc_attr_e( 'Ontvangsten', 'eme-accounting' ); ?>">
                <?php foreach ( $main_in as $a ): ?>
                <option value="<?= (int) $a->id ?>" data-direction="in"><?= esc_html( $a->code . ' - ' . $a->name ) ?></option>
                <?php endforeach; ?>
            </optgroup>
            <?php endif; ?>
            <?php if ( $main_out ): ?>
            <optgroup label="<?php esc_attr_e( 'Uitgaven', 'eme-accounting' ); ?>">
                <?php foreach ( $main_out as $a ): ?>
                <option value="<?= (int) $a->id ?>" data-direction="out"><?= esc_html( $a->code . ' - ' . $a->name ) ?></option>
                <?php endforeach; ?>
            </optgroup>
            <?php endif; ?>
        </select>
    </div>
</div>
<div class="eme-acc-row">
    <div class="eme-acc-field"><label><?php esc_html_e( 'Omschrijving', 'eme-accounting' ); ?></label><textarea name="description"></textarea></div>
</div>
<p>
    <button type="submit" class="button button-primary submit-btn"><?php esc_html_e( 'Opslaan', 'eme-accounting' ); ?></button>
    <button type="button" id="eme-acc-coa-cancel" class="button" style="display:none"><?php esc_html_e( 'Annuleren', 'eme-accounting' ); ?></button>
</p>
</form>
</div>

<div class="eme-acc-columns">
<div class="eme-acc-col">
<h3><?php esc_html_e( 'Ontvangsten', 'eme-accounting' ); ?></h3>
<ul class="eme-acc-coa-tree" data-direction="in">
<?php foreach ( $accounts as $a ): if ( $a->direction === 'in' ): ?>
<li class="<?= $a->parent_id ? 'sub' : 'main' ?>"
    data-id="<?= (int) $a->id ?>"
    data-direction="<?= esc_attr( $a->direction ) ?>"
    data-parent-id="<?= (int) ( $a->parent_id ?? 0 ) ?>"
    data-code="<?= esc_attr( $a->code ) ?>"
    data-name="<?= esc_attr( $a->name ) ?>"
    data-description="<?= esc_attr( $a->description ?? '' ) ?>"
    data-is-active="<?= (int) ( $a->is_active ?? 1 ) ?>"
    style="margin-left: <?= (int) ( $a->depth * 20 ) ?>px">
    <?php if ($a->parent_id) : ?>
        <span class="handle">☰</span>
    <?php endif; ?>
    <span class="code"><?= esc_html( $a->code ) ?></span>
    <span class="name"><?= esc_html( $a->name ) ?><?= empty( $a->is_active ) ? ' <span class="eme-acc-tag">' . esc_html__( 'inactief', 'eme-accounting' ) . '</span>' : '' ?></span>
    <span class="actions">
        <button class="button button-small eme-acc-edit-coa" data-id="<?= (int) $a->id ?>"><?php esc_html_e( 'Bewerk', 'eme-accounting' ); ?></button>
        <?php if ( ! $a->has_children ) : ?>
        <button class="button button-small eme-acc-delete-coa" data-id="<?= (int) $a->id ?>"><?php esc_html_e( 'Verwijder', 'eme-accounting' ); ?></button>
        <?php endif; ?>
    </span>
</li>
<?php endif; endforeach; ?>
</ul>
</div>
<div class="eme-acc-col">
<h3><?php esc_html_e( 'Uitgaven', 'eme-accounting' ); ?></h3>
<ul class="eme-acc-coa-tree" data-direction="out">
<?php foreach ( $accounts as $a ): if ( $a->direction === 'out' ): ?>
<li class="<?= $a->parent_id ? 'sub' : 'main' ?>"
    data-id="<?= (int) $a->id ?>"
    data-direction="<?= esc_attr( $a->direction ) ?>"
    data-parent-id="<?= (int) ( $a->parent_id ?? 0 ) ?>"
    data-code="<?= esc_attr( $a->code ) ?>"
    data-name="<?= esc_attr( $a->name ) ?>"
    data-description="<?= esc_attr( $a->description ?? '' ) ?>"
    data-is-active="<?= (int) ( $a->is_active ?? 1 ) ?>"
    style="margin-left: <?= (int) ( $a->depth * 20 ) ?>px">
    <?php if ($a->parent_id) : ?>
        <span class="handle">☰</span>
    <?php endif; ?>
    <span class="code"><?= esc_html( $a->code ) ?></span>
    <span class="name"><?= esc_html( $a->name ) ?><?= empty( $a->is_active ) ? ' <span class="eme-acc-tag">' . esc_html__( 'inactief', 'eme-accounting' ) . '</span>' : '' ?></span>
    <span class="actions">
        <button class="button button-small eme-acc-edit-coa" data-id="<?= (int) $a->id ?>"><?php esc_html_e( 'Bewerk', 'eme-accounting' ); ?></button>
        <?php if ( ! $a->has_children ) : ?>
        <button class="button button-small eme-acc-delete-coa" data-id="<?= (int) $a->id ?>"><?php esc_html_e( 'Verwijder', 'eme-accounting' ); ?></button>
        <?php endif; ?>
    </span>
</li>
<?php endif; endforeach; ?>
</ul>
</div>
</div>
</fieldset>
</div>
