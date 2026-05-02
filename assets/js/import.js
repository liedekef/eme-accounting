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

    /* FtableModal-based confirm — returns a Promise that resolves true/false */
    function ftConfirm( title, message ) {
        return new Promise( ( resolve ) => {
            const modal = new FtableModal( {
                title,
                content : `<p>${message}</p>`,
                buttons : [
                    {
                        text      : i18n.cancel || 'Annuleren',
                        className : 'ftable-dialog-cancelbutton',
                        onClick   : () => { modal.close(); resolve( false ); },
                    },
                    {
                        text      : i18n.confirm || 'Bevestigen',
                        className : 'ftable-dialog-deletebutton',
                        onClick   : () => { modal.close(); resolve( true ); },
                    },
                ],
            } );
            modal.show();
        } );
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
        forcePost: true,
        paging   : false,
        sorting  : false,
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
                        html += `<br><span style="font-size:11px;color:#646970">#${record.journal_id}</span>`;
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
  <div style="display:flex;gap:4px;flex-wrap:wrap;margin-bottom:4px">
    <button class="button button-small btn-ignore-line">Negeer</button>
    <input type="number" class="journal-id-input" placeholder="Dagboek ID" style="width:80px;padding:2px 4px">
    <button class="button button-small btn-reconcile-manual">Link</button>
  </div>
  <div style="display:flex;gap:4px;flex-wrap:wrap">
    <select class="coa-select eme_snapselect" style="min-width:180px" data-live-search="true">
      <option value="">\u2014 Kies rekening \u2014</option>
      ${opts}
    </select>
    <select name="eme_event_id" class="eme_snapselect_events_class"></select>
    <button class="button button-small btn-create-from-line">Nieuwe boeking</button>
  </div>
</div>`;
                },
            },
        }, // end fields
        recordsLoaded: function () {
            // Re-init snapselects after every load (ftable rebuilds the tbody)
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
        post( 'eme_accounting_list_import_lines', { import_log_id: activeLogId } )
            .then( r => {
                if ( r.Result !== 'OK' ) return;
                const cnt = { matched: 0, manual: 0, pending: 0, ignored: 0 };
                ( r.Records || [] ).forEach( l => {
                    if ( Object.prototype.hasOwnProperty.call( cnt, l.status ) ) cnt[ l.status ]++;
                } );
                logsTable.updateRowData( activeLogRow, {
                    cnt_matched: cnt.matched,
                    cnt_manual : cnt.manual,
                    cnt_pending: cnt.pending,
                    cnt_ignored: cnt.ignored,
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
     * MAIN LOGS TABLE
     * ================================================================ */
    const logsTable = new FTable( '#eme-import-logs-table', {
        forcePost: true,
        paging   : false,
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
            },
            filename: {
                title: 'Bestand', list: true, create: false, edit: false,
                display: ( { record } ) =>
                    `${esc(record.filename)} <span class="eme-acc-tag">${esc(record.format)}</span>`,
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
            btn.textContent = 'Bezig\u2026';
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
            const ok = await ftConfirm(
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
            if ( ! coaId ) { linesTableInst.showInfo( 'Kies eerst een grootboekrekening.' ); return; }
            const description = wrap.closest( 'tr' )?.recordData?.description || '';
            if ( /transfer/i.test( description ) ) {
                const ok = await ftConfirm(
                    i18n.confirm_transfer_title || 'Transfer gedetecteerd',
                    i18n.confirm_transfer_msg   || 'De omschrijving bevat "transfer". Ben je zeker dat je een nieuwe boeking wil aanmaken?'
                );
                if ( ! ok ) return;
            }
            e.target.disabled = true;
            const r = await post( 'eme_accounting_create_entry_from_line',
                { line_id: lineId, coa_id: coaId } );
            if ( r.success ) {
                linesTableInst.updateRowData( linesTableInst.getRowByKey( lineId ), r.data.Record );
                refreshLogCounters();
            } else {
                linesTableInst.showError( r.data || 'Fout' );
                e.target.disabled = false;
            }
            return;
        }

    } );

    /* ================================================================
     * UPLOAD FORM – reload ftable after upload, no page reload
     * ================================================================ */
    document.getElementById( 'eme-acc-import-form' )?.addEventListener( 'submit', function ( e ) {
        e.preventDefault();
        const btn = this.querySelector( '.submit-btn' );
        const fd  = new FormData( this );
        fd.append( 'action', 'eme_accounting_import_upload' );
        fd.append( 'nonce',  nonce );
        btn.textContent = 'Importeren\u2026';
        btn.disabled    = true;
        fetch( ajaxUrl, { method: 'POST', body: fd } )
            .then( r => r.json() )
            .then( r => {
                btn.textContent = 'Importeren';
                btn.disabled    = false;
                if ( ! r.success ) { alert( r.data || 'Importfout' ); return; }
                const d = r.data;
                const resultDiv = document.querySelector('#import-result');
                if (resultDiv) {
                    resultDiv.innerHTML = `<div class="notice notice-success"><p>
                        Import klaar: <strong>${d.total}</strong> regels —
                        <strong>${d.matched}</strong> gematcht —
                        <strong>${d.unmatched}</strong> nog te verifiëren.
                    </p></div>`;
                    resultDiv.style.display = 'block';
                    document.getElementById( 'eme-acc-import-form' ).reset();
                    setTimeout(() => {
                        resultDiv.style.display = 'none';
                    }, 5000);
                }
                logsTable.reload();
            } )
            .catch( () => {
                btn.textContent = 'Importeren';
                btn.disabled    = false;
                alert( 'Verbindingsfout met server.' );
            } );
    } );

})();
