(function () {
    'use strict';
    const ajaxUrl = emeAccounting.ajaxUrl;
    const nonce   = emeAccounting.nonce;
    const i18n    = emeAccounting.i18n;

    /* Status badge HTML */
    const statusBadge = {
        pending : '<span class="eme-acc-tag eme-acc-pending">Te verifi\u00ebren</span>',
        matched : '<span class="eme-acc-tag eme-acc-reconciled">Gematcht</span>',
        ignored : '<span class="eme-acc-tag eme-acc-ignored">Genegeerd</span>',
        manual  : '<span class="eme-acc-tag">Manueel</span>',
    };

    /* Generic POST helper */
    function post( action, data ) {
        const fd = new FormData();
        fd.append( 'action', action );
        fd.append( 'nonce',  nonce );
        for ( const [ k, v ] of Object.entries( data ) ) fd.append( k, v );
        return fetch( ajaxUrl, { method: 'POST', body: fd } ).then( r => r.json() );
    }

    /* Currency formatter */
    function fmtCur( n ) {
        return new Intl.NumberFormat( 'nl-BE', {
            style: 'currency', currency: i18n.currency || 'EUR', minimumFractionDigits: 2,
        } ).format( n );
    }
    function esc( s ) {
        if ( s == null ) return '';
        return String( s ).replace( /&/g, '&amp;' ).replace( /</g, '&lt;' )
            .replace( />/g, '&gt;' ).replace( /"/g, '&quot;' );
    }

    /* ================================================================
     * STATE – which log is currently shown in the lines section
     * ================================================================ */
    let activeLogId     = null;   // currently loaded log id
    let activeLogRow    = null;   // the <tr> in the logs table for the active log

    const linesSection  = document.getElementById( 'eme-import-lines-section' );
    const linesTitleEl  = document.getElementById( 'eme-import-lines-title' );

    /* ================================================================
     * LINES TABLE (reconciliation) – created once, reused per log
     * ================================================================ */
    let linesTableInst = new FTable( '#eme-import-lines-table', {
        forcePost : true,
        paging    : true,
        pageSizes : [50, 100, 250, 500, 'all'],
        sorting   : false,
        selecting : true,
        multiselect: true,
        selectingCheckboxes: true,
        selectionChanged  : function () { updateBulkBar(); },
        actions: {
            listAction  : ajaxUrl + '?action=eme_accounting_list_import_lines&nonce='   + nonce,
        },
        listQueryParams: () => {
            return { import_log_id: activeLogId };
        },
        fields: {
            id: { key: true, list: false },
            transaction_date: {
                type: 'date',
                dateFormat: i18n.translate_fdateformat,
                title: 'Datum', list: true, create: false, edit: false,
            },
            description: {
                title: 'Omschrijving', list: true, create: false, edit: false,
                display: ( { value, record } ) =>
                `<span title="${esc(value)}">${esc( String( value || '' ).substring( 0, 60 ) )}</span>`,
            },
            counterparty_name: {
                title: 'Tegenpartij', list: true, create: false, edit: false,
                display: ( { value, record } ) =>
                `<span style="font-size:11px">${esc(record.counterparty_name)}<br>` +
                `<span style="color:#646970">${esc(record.counterparty_iban)}</span></span>`,
            },
            bank_ref: {
                title: 'Ref.', list: true, create: false, edit: false,
                width: '2%',
                display: ( { value, record } ) =>
                `<span style="font-size:11px">${esc(record.bank_ref || record.structured_ref)}</span>`,
            },
            amount: {
                title: 'Bedrag', list: true, create: false, edit: false,
                listClass: 'amount',
                width: '2%',
                display: ( { value, record } ) => {
                    const formatted_val = fmtCur(value);
                    if (value<0) {
                        return '<span style="color: red; font-weight: bold;">' + formatted_val + '</span>';
                    } else {
                        return '<span style="color: green; font-weight: bold;">' + formatted_val + '</span>';
                    }
                }
            },
            status: {
                title: 'Status', list: true, create: false, edit: false,
                width: '5%',
                display: ( { value, record } ) => {
                    let html = statusBadge[ value ] ||
                        `<span class="eme-acc-tag">${esc(value)}</span>`;
                    if ( record.journal_id ) {
                        html += `<br><span style="font-size:11px;color:#646970"><a target="_blank" href="admin.php?page=emeaccounting-journal&edit=${esc(record.journal_id)}">Wijzig boeking</a></span>`;
                    }
                    return html;
                },
            },

            /* Reconciliation action controls – only shown for pending lines */
            _actions: {
                title: 'Acties', list: true, create: false, edit: false, sorting: false,
                display: ( { value, record } ) => {
                    if ( record.status !== 'pending' ) return '';
                    const n    = parseFloat( record.amount );
                    const opts = ( n >= 0 ? emeAccountingImport.coaSelectIn : emeAccountingImport.coaSelectOut )
                    return `
<div class="eme-import-actions" data-line="${record.id}">
  <div style="display:flex;gap:4px;flex-wrap:wrap;margin-bottom:4px;align-items:center">
    <span style="font-size:12px;color:#646970">${esc('Kies hoe je dit wil afhandelen:')}</span>
    <select class="type-select">
      <option value="booking">Boeking</option>
      <option value="transfer">Transfer</option>
      <option value="link">Link naar bestaande boeking</option>
      <option value="ignore">Negeer</option>
    </select>
  </div>
  <div class="eme-import-booking" style="display:flex;gap:4px;flex-wrap:wrap">
    <select class="coa-select eme_snapselect" style="min-width:180px" data-live-search="true">
      <option value="">\u2014 Kies categorie \u2014</option>
      ${opts}
    </select>
    <select name="eme_event_id" class="eme_snapselect_events_class"></select>
    <button class="button button-small btn-create-from-line">Nieuwe boeking</button>
  </div>
  <div class="eme-import-transfer" style="display:none;gap:4px;flex-wrap:wrap">
    <select class="transfer-account-select eme_snapselect" style="min-width:180px" data-live-search="true" title="${esc('Kies de andere rekening')}">
      <option value="">\u2014 Kies tegenrekening \u2014</option>
      ${emeAccountingImport.accountOptions ? emeAccountingImport.accountOptions.join('') : ''}
    </select>
    <button class="button button-small btn-create-transfer" title="${esc('Maak een transfer tussen dit en de tegenrekening')}">Transfer</button>
  </div>
  <div class="eme-import-link" style="display:none;gap:4px;flex-wrap:wrap">
    <input type="number" class="journal-id-input" placeholder="Dagboek ID" style="width:80px;padding:2px 4px">
    <button class="button button-small btn-reconcile-manual">Link</button>
  </div>
  <div class="eme-import-ignore" style="display:none;gap:4px;flex-wrap:wrap">
    <button class="button button-small btn-ignore-line">Negeer</button>
  </div>
</div>`;
                },
            },
        }, // end fields
        recordsLoaded: function () {
            // Re-init snapselects after every load (ftable rebuilds the tbody)
            updateBulkBar();
            initSnapSelect('select.eme_snapselect');
            initSnapSelectRemote('select.eme_snapselect_events_class', {
                placeholder: 'Selecteer event',
                showClearButton: true,
                data: function(search, page) {
                    return {
                        action: 'eme_accounting_events_list',
                        entry_date: document.getElementById('entry_date')?.value || 0,
                        search_all: document.getElementById('eventsearch_all')?.checked ? 1 : 0,
                        nonce: nonce
                    };
                }
            });
        },
    } );

    /* Recalculate counter badges on the active log row in the logs table */
    function refreshLogCounters() {
        if ( ! activeLogId || ! activeLogRow ) return;
        post( 'eme_accounting_get_logline_stats', { import_log_id: activeLogId } )
            .then( r => {
                if ( ! r.success ) return;
                logsTable.updateRowData( activeLogRow, {
                    cnt_matched: r.data.matched  || 0,
                    cnt_manual : r.data.manual   || 0,
                    cnt_pending: r.data.pending  || 0,
                    cnt_ignored: r.data.ignored  || 0,
                } );
            } );
    }

    /* Show the lines section for a given log row */
    function openLinesSection( logId, logRow ) {
        activeLogId  = logId;
        activeLogRow = logRow;

        const rec = logRow.recordData;
        linesTitleEl.textContent =
            `Reconciliatie \u2013 import #${logId} \u2013 ${rec.filename || ''} (${rec.account_name || ''})`;

        linesSection.style.display = '';
        linesSection.scrollIntoView( { behavior: 'smooth', block: 'start' } );

        linesTableInst.load();
    }

    function closeLinesSection() {
        linesSection.style.display = 'none';
        /* Reset the "Bekijk" label on the previously active row */
        if ( activeLogRow ) {
            const btn = activeLogRow.querySelector( '.btn-view-log' );
            if ( btn ) btn.textContent = 'Bekijk';
        }
        activeLogId  = null;
        activeLogRow = null;
    }

    /* ================================================================
     * BULK ACTIONS
     * ================================================================ */

    const bulkBar       = document.getElementById( 'eme-import-bulk-actions' );
    const bulkSelect    = document.getElementById( 'eme-bulk-action-select' );
    const bulkCoaWrap   = document.getElementById( 'eme-bulk-coa-wrap' );
    const bulkCoaSel    = document.getElementById( 'eme-bulk-coa-select' );
    const bulkEventSel  = document.getElementById( 'eme-bulk-event-select' );
    const bulkApplyBtn  = document.getElementById( 'eme-bulk-apply' );
    const bulkCountSpan = document.getElementById( 'eme-bulk-selected-count' );

    /* Populate COA options from emeAccountingImport (same as per-line selects) */
    /*function populateBulkCoa() {
        if ( bulkCoaSel.options.length > 1 ) return; // already filled
        const allOpts = [ ...emeAccountingImport.coaOptionsIn, ...emeAccountingImport.coaOptionsOut ];
        allOpts.forEach( o => {
            const opt = document.createElement( 'option' );
            opt.value       = o.Value;
            opt.textContent = o.DisplayText;
            bulkCoaSel.appendChild( opt );
        } );
    }*/

    /* Show/hide bulk bar based on selection; update counter */
    function updateBulkBar() {
        if ( ! bulkCountSpan ) return;
        const selected = linesTableInst?.getSelectedRows?.() || [];
        const count    = selected.length;
        bulkCountSpan.style.display   = count > 0 ? '' : 'none';
        bulkCountSpan.textContent = count > 0
            ? `${count} rij${count === 1 ? '' : 'en'} geselecteerd`
            : '';
    }

    /* selectionChanged is wired as a FTable callback option (see linesTableInst definition) */

    /* Show/hide extra controls based on chosen action */
    bulkSelect?.addEventListener( 'change', function () {
        const action = this.value;
        if ( action === 'create' ) {
            //populateBulkCoa();
            bulkCoaWrap.style.display = 'flex';
        } else {
            bulkCoaWrap.style.display = 'none';
        }
    } );

    /* Apply bulk action */
    bulkApplyBtn?.addEventListener( 'click', async function () {
        const action = bulkSelect.value;
        if ( ! action ) { linesTableInst.showInfo( 'Kies eerst een bulk actie.' ); return; }

        const selectedRows = linesTableInst.getSelectedRows();
        if ( ! selectedRows.length ) { linesTableInst.showInfo( 'Selecteer eerst rijen.' ); return; }

        // Only process pending rows
        const pendingRows = selectedRows.filter( row => row.recordData?.status === 'pending' );
        if ( ! pendingRows.length ) {
            linesTableInst.showInfo( 'Geen openstaande rijen geselecteerd.' );
            return;
        }

        const ids = pendingRows.map( row => row.dataset.recordKey );

        if ( action === 'ignore' ) {
            const ok = await FTable.confirm(
                'Negeer geselecteerde rijen',
                `<p>Ben je zeker dat je deze <strong>${ids.length}</strong> geselecteerde rij${ids.length === 1 ? '' : 'en'} wil negeren?</p>`
            );
            if ( ! ok ) return;
        }

        if ( action === 'create' ) {
            if ( ! bulkCoaSel.value ) { linesTableInst.showInfo( 'Kies eerst een grootboekrekening.' ); return; }

            // Warn if any line contains "transfer"
            const hasTransfer = pendingRows.some( row =>
                /transfer/i.test( row.recordData?.description || '' )
            );
            if ( hasTransfer ) {
                const ok = await FTable.confirm(
                    'Transfer gedetecteerd',
                    '<p>Eén of meer geselecteerde regels bevatten "transfer" in de omschrijving. Toch nieuwe boekingen aanmaken?</p>'
                );
                if ( ! ok ) return;
            }
        }

        const origText = bulkApplyBtn.textContent;
        bulkApplyBtn.disabled    = true;
        bulkApplyBtn.textContent = i18n.processing;

        const r = await post( 'eme_accounting_bulk_action_lines', {
            action_type : action,
            line_ids    : ids.join( ',' ),
            coa_id      : action === 'create' ? bulkCoaSel.value : '',
            eme_event_id: action === 'create' ? bulkEventSel.value : '',
        } );

        bulkApplyBtn.disabled    = false;
        bulkApplyBtn.textContent = origText

        linesTableInst.reload();
        if ( r.success ) {
            refreshLogCounters();
            linesTableInst.showInfo( r.data.message || 'Klaar.' );
            updateBulkBar();
        } else {
            linesTableInst.showError( r.data || 'Fout bij bulk actie.' );
        }
    } );

    /* ================================================================
     * MAIN LOGS TABLE
     * ================================================================ */
    const logsTable = new FTable( '#eme-import-logs-table', {
        forcePost: true,
        paging   : true,
        sorting  : false,
        actions: {
            listAction  : ajaxUrl + '?action=eme_accounting_list_import_logs&nonce='   + nonce,
            deleteAction: ajaxUrl + '?action=eme_accounting_delete_import_log&nonce='  + nonce,
        },
        recordDeleted: function () {
            // close import lines table if open
            closeLinesSection();
        },
        fields: {
            id: {
                key: true, title: '#', list: true, create: false, edit: false,
            },
            import_date: {
                title: 'Datum', list: true, create: false, edit: false,
                type: 'datetime',
                dateFormat: i18n.translate_fdatetimeformat
            },
            account_name: {
                title: 'Rekening', list: true, create: false, edit: false,
                display: ( { value } ) => value ? esc( value ) : '',
            },
            filename: {
                title: 'Bestand', list: true, create: false, edit: false,
                display: ( { record } ) => {
                    const completeBadge = record.is_complete
                        ? ' <span class="eme-acc-tag eme-acc-reconciled" title="Alle regels verwerkt">✓ Afgerond</span>'
                        : ' <span class="eme-acc-tag eme-acc-pending" title="Nog openstaande regels">Open</span>';
                    return `${esc(record.filename)} <span class="eme-acc-tag">${esc(record.format)}</span>${completeBadge}`;
                },
            },
            total_lines: {
                title: 'Totaal', list: true, create: false, edit: false,
            },
            cnt_matched: {
                title: 'Gematcht', list: true, create: false, edit: false,
                display: ( { value } ) =>
                    `<span class="eme-acc-tag eme-acc-reconciled">${parseInt(value)||0}</span>`,
            },
            cnt_manual: {
                title: 'Manueel', list: true, create: false, edit: false,
                display: ( { value } ) =>
                    `<span class="eme-acc-tag eme-acc-manual">${parseInt(value)||0}</span>`,
            },
            cnt_pending: {
                title: 'Open', list: true, create: false, edit: false,
                display: ( { value } ) =>
                    `<span class="eme-acc-tag eme-acc-pending">${parseInt(value)||0}</span>`,
            },
            cnt_ignored: {
                title: 'Genegeerd', list: true, create: false, edit: false,
                display: ( { value } ) =>
                    `<span class="eme-acc-tag eme-acc-ignored">${parseInt(value)||0}</span>`,
            },

            /* Bekijk – opens the lines section below */
            _view: {
                title: ' ', list: true, create: false, edit: false, sorting: false,
                listClass: 'ftable-command-column',
                visibility: 'fixed',
                display: ( { record } ) =>
                    `<button class="button button-small btn-view-log" data-log="${record.id}">Bekijk</button>`,
            },
        },
    } );

    logsTable.load();

    /* ================================================================
     * All delegated click events in one place.
     * ================================================================ */
    document.addEventListener( 'click', async ( e ) => {

        if ( e.target.classList.contains( 'btn-view-log' ) ) {
            e.preventDefault();
            const logId  = e.target.dataset.log;
            const logRow = e.target.closest( 'tr.ftable-data-row' );
            if ( ! logRow ) return;
            if ( activeLogId === logId ) {
                closeLinesSection();
                return;
            }
            if ( activeLogRow ) {
                const prev = activeLogRow.querySelector( '.btn-view-log' );
                if ( prev ) prev.textContent = 'Bekijk';
            }
            e.target.textContent = '\u25b2 Sluit';
            openLinesSection( logId, logRow );
            return;
        }

        /* ── Auto-reconciliatie ──────────────────────────────────────── */
        if ( e.target.id === 'btn-auto-reconcile' ) {
            if ( ! activeLogId ) return;
            const btn      = e.target;
            const origText = btn.textContent;
            btn.disabled   = true;
            btn.textContent = i18n.processing;
            const r = await post( 'eme_accounting_auto_reconcile', {
                log_id    : activeLogId,
                account_id: activeLogRow.recordData.account_id,
            } );
            if ( r.success ) {
                btn.textContent = `Auto-reconciliatie (${r.data.matched} gematcht)`;
                linesTableInst?.load();
                refreshLogCounters();
            } else {
                linesTableInst.showError( r.data || 'Fout' );
                btn.textContent = origText;
            }
            btn.disabled = false;
            return;
        }

        /* ── Sluit reconciliatie ─────────────────────────────────────── */
        if ( e.target.id === 'btn-close-lines' ) {
            closeLinesSection();
            return;
        }

        /* ── Reconciliatie acties (lines table) ──────────────────────── */
        const wrap = e.target.closest( '.eme-import-actions' );
        const lineId = wrap?.dataset.line || null;
        if ( wrap && e.target.classList.contains( 'btn-ignore-line' ) ) {
            const ok = await FTable.confirm(
                i18n.confirm_ignore_title || 'Lijn negeren',
                i18n.confirm_ignore_msg   || 'Ben je zeker dat je deze lijn wil negeren?'
            );
            if ( ! ok ) return;
            e.target.disabled = true;
            const r = await post( 'eme_accounting_ignore_line', { line_id: lineId } );
            if ( r.success ) {
                linesTableInst.updateFullRowData( linesTableInst.getRowByKey( lineId ), r.data.Record );
                refreshLogCounters();
                wrap.innerHTML = '';
            } else {
                linesTableInst.showError( r.data || 'Fout' );
                e.target.disabled = false;
            }
            return;
        }
        if ( wrap && e.target.classList.contains( 'btn-reconcile-manual' ) ) {
            const jid = wrap.querySelector( '.journal-id-input' )?.value;
            if ( ! jid ) { linesTableInst.showInfo( 'Voer eerst een dagboek-ID in.' ); return; }
            e.target.disabled = true;
            const r = await post( 'eme_accounting_reconcile_line',
                { line_id: lineId, journal_id: jid } );
            if ( r.success ) {
                linesTableInst.updateFullRowData( linesTableInst.getRowByKey( lineId ), r.data.Record );
                refreshLogCounters();
            } else {
                linesTableInst.showError( r.data || 'Fout' );
                e.target.disabled = false;
            }
            return;
        }
        if ( wrap && e.target.classList.contains( 'btn-create-from-line' ) ) {
            const coaId = wrap.querySelector( '.coa-select' )?.value;
            const eventId = wrap.querySelector( '[name=eme_event_id]' )?.value;
            if ( ! coaId ) { linesTableInst.showInfo( 'Kies eerst een grootboekrekening.' ); return; }
            const description = wrap.closest( 'tr' )?.recordData?.description || '';
            if ( /transfer/i.test( description ) ) {
                const ok = await FTable.confirm(
                    i18n.confirm_transfer_title || 'Transfer gedetecteerd',
                    i18n.confirm_transfer_msg   || 'De omschrijving bevat "transfer". Ben je zeker dat je een nieuwe boeking wil aanmaken?'
                );
                if ( ! ok ) return;
            }
            e.target.disabled = true;
            const r = await post( 'eme_accounting_create_entry_from_line',
                { line_id: lineId, coa_id: coaId, eme_event_id: eventId } );
            if ( r.success ) {
                linesTableInst.updateFullRowData( linesTableInst.getRowByKey( lineId ), r.data.Record );
                refreshLogCounters();
            } else {
                linesTableInst.showError( r.data || 'Fout' );
                e.target.disabled = false;
            }
            return;
        }
        if ( wrap && e.target.classList.contains( 'btn-create-transfer' ) ) {
            const line = linesTableInst.getRowByKey( lineId )?.recordData;
            const otherAccountId = wrap.querySelector( '.transfer-account-select' )?.value;
            if ( ! otherAccountId ) { linesTableInst.showInfo( 'Kies eerst een tegenrekening.' ); return; }
            if ( line && otherAccountId === String( line.account_id ) ) {
                linesTableInst.showInfo( 'De tegenrekening mag niet dezelfde zijn als de importrekening.' );
                return;
            }
            const eventId = wrap.querySelector( '[name=eme_event_id]' )?.value;

            // Check whether this transfer may already exist before creating it.
            let proceed = true;
            const check = await post( 'eme_accounting_check_transfer_exists',
                { line_id: lineId, other_account_id: otherAccountId } );
            if ( check.success && check.data.existing?.length ) {
                const rows = check.data.existing.map( t =>
                    `<li>#${t.id} \u00b7 ${esc(t.entry_date)} \u00b7 ${esc(t.account_name)} \u2192 ${esc(t.contra_account_name)} \u00b7 ${fmtCur(t.amount)} \u00b7 ${esc(t.description)}</li>`
                ).join( '' );
                proceed = await FTable.confirm(
                    'Mogelijke bestaande transfer',
                    `<p>Er lijken al <strong>${check.data.existing.length}</strong> transfer(s) te bestaan tussen deze rekeningen met een vergelijkbaar bedrag/datum:</p><ul>${rows}</ul><p><strong>Toch aanmaken?</strong></p>`
                );
            }
            if ( ! proceed ) return;

            e.target.disabled = true;
            const r = await post( 'eme_accounting_create_transfer_from_line',
                { line_id: lineId, other_account_id: otherAccountId, eme_event_id: eventId || '' } );
            if ( r.success ) {
                linesTableInst.updateFullRowData( linesTableInst.getRowByKey( lineId ), r.data.Record );
                refreshLogCounters();
            } else {
                linesTableInst.showError( r.data || 'Fout' );
                e.target.disabled = false;
            }
            return;
        }

    } );

    /* Toggle between "Boeking" and "Transfer" mode per line (delegated change
       handler – ftable rebuilds the tbody, so we listen on the container). */
    document.addEventListener( 'change', ( e ) => {
        const sel = e.target.closest( '.type-select' );
        if ( ! sel ) return;
        const wrap     = sel.closest( '.eme-import-actions' );
        const booking  = wrap?.querySelector( '.eme-import-booking' );
        const transfer = wrap?.querySelector( '.eme-import-transfer' );
        const link     = wrap?.querySelector( '.eme-import-link' );
        const ignore   = wrap?.querySelector( '.eme-import-ignore' );
        const show = name => {
            if ( name === 'booking'  && booking )  booking.style.display  = 'flex';
            if ( name === 'transfer' && transfer ) transfer.style.display = 'flex';
            if ( name === 'link'     && link )     link.style.display     = 'flex';
            if ( name === 'ignore'   && ignore )   ignore.style.display   = 'flex';
        };
        const hide = name => {
            if ( name !== 'booking'  && booking )  booking.style.display  = 'none';
            if ( name !== 'transfer' && transfer ) transfer.style.display = 'none';
            if ( name !== 'link'     && link )     link.style.display     = 'none';
            if ( name !== 'ignore'   && ignore )   ignore.style.display   = 'none';
        };
        hide();
        show( sel.value );
    } );

    /* ================================================================
     * UPLOAD FORM – reload ftable after upload, no page reload
     * ================================================================ */
    /* ================================================================
     * FORMAT SELECTOR – show expected columns hint
     * ================================================================ */
    const formatSelect   = document.querySelector( '#eme-acc-import-form select[name="format"]' );
    const colHintEl      = document.getElementById( 'eme-acc-col-hint' );
    const schema         = emeAccountingImport.columnSchema || {};

    function renderColHint( format ) {
        if ( ! colHintEl ) return;
        const s = schema[ format ];
        if ( ! s ) { colHintEl.innerHTML = ''; return; }
        const req = s.required ? Object.values( s.required ) : [];
        const opt = s.optional ? Object.values( s.optional ) : [];
        if ( ! req.length && ! opt.length ) { colHintEl.innerHTML = ''; return; }
        let html = '';
        if ( req.length ) {
            html += `<span class="eme-col-required">${i18n.col_required || 'Vereist'}: <strong>${esc( req.join( ', ' ) )}</strong></span>`;
        }
        if ( opt.length ) {
            if ( html ) html += ' &nbsp;|&nbsp; ';
            html += `<span class="eme-col-optional">${i18n.col_optional || 'Optioneel'}: ${esc( opt.join( ', ' ) )}</span>`;
        }
        colHintEl.innerHTML = html;
    }

    formatSelect?.addEventListener( 'change', function () {
        renderColHint( this.value );
    } );
    // Render on page load for the default selected value
    if ( formatSelect ) renderColHint( formatSelect.value );

    document.getElementById( 'eme-acc-import-form' )?.addEventListener( 'submit', function ( e ) {
        e.preventDefault();
        doUpload( this, false );
    } );

    async function doUpload( form, force ) {
        const btn = form.querySelector( '.submit-btn' );
        const fd  = new FormData( form );
        fd.append( 'action', 'eme_accounting_import_upload' );
        fd.append( 'nonce',  nonce );
        if ( force ) fd.append( 'force', '1' );
        btn.textContent = 'Importeren\u2026';
        btn.disabled    = true;

        let r;
        try {
            r = await fetch( ajaxUrl, { method: 'POST', body: fd } ).then( res => res.json() );
        } catch {
            btn.textContent = 'Importeren';
            btn.disabled    = false;
            logsTable.showError( 'Verbindingsfout met server.' );
            return;
        }

        btn.textContent = 'Importeren';
        btn.disabled    = false;

        if ( ! r.success ) {
            logsTable.showError( r.data || 'Importfout' );
            return;
        }

        const d = r.data;

        // Duplicate warnings: ask for confirmation before forcing the import
        if ( d.duplicate_warnings?.length ) {
            const msg = d.duplicate_warnings.join( '\n' ) +
                '\n\nToch importeren?';
            const ok = await FTable.confirm(
                'Mogelijke duplicaten gedetecteerd',
                d.duplicate_warnings.map( w => `<p>⚠️ ${esc(w)}</p>` ).join( '' ) +
                '<p><strong>Toch importeren?</strong></p>'
            );
            if ( ! ok ) return;
            doUpload( form, true );
            return;
        }

        // Success
        const resultDiv = document.querySelector( '#import-result' );
        if ( resultDiv ) {
            resultDiv.innerHTML = `<div class="notice notice-success"><p>
                Import klaar: <strong>${d.total}</strong> regels —
                <strong>${d.matched}</strong> gematcht —
                <strong>${d.unmatched}</strong> nog te verifiëren.
            </p></div>`;
            resultDiv.style.display = 'block';
            form.reset();
            setTimeout( () => { resultDiv.style.display = 'none'; }, 5000 );
        }
        logsTable.reload();
    }

})();
