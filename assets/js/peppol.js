/* eme-accounting/assets/js/peppol.js */
/* global emeAccounting, emeAccountingPeppol, FTable, FDatepicker, esc, formatCurrency */
(function () {
    'use strict';

    const cfg     = window.emeAccountingPeppol || {};
    const ajaxUrl = cfg.ajaxUrl || emeAccounting.ajaxUrl;
    const nonce   = cfg.nonce || emeAccounting.nonce;

    // esc() expects a string; JSON values can be numbers, null or undefined.
    function E(value) { return esc(value === null || value === undefined ? '' : String(value)); }

    function post(action, data) {
        const fd = new FormData();
        fd.append('action', action);
        fd.append('nonce', nonce);
        Object.entries(data || {}).forEach(([k, v]) => fd.append(k, v));
        return fetch(ajaxUrl, { method: 'POST', body: fd })
            .then(r => r.json())
            .catch(function () { return { success: false, data: 'Netwerk- of serverfout' }; });
    }

    function isoRange(value) {
        if (!value) return '';
        return value.split(' -- ').map(function (part) {
            const m = part.trim().match(/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/);
            if (!m) return part.trim();
            return m[3] + '-' + m[2].padStart(2, '0') + '-' + m[1].padStart(2, '0');
        }).filter(Boolean).join(',');
    }

    const typeLabels = {
        INVOICE: 'Factuur',
        CREDIT_NOTE: 'Creditnota',
        SELFBILLING_INVOICE: 'Self-billing factuur',
        SELFBILLING_CREDIT_NOTE: 'Self-billing creditnota'
    };

    const statusBadge = function (status) {
        if (status === 'ignored') {
            return '<span class="eme-acc-tag eme-acc-ignored">Genegeerd</span>';
        }
        if (status === 'error') {
            return '<span class="eme-acc-tag" style="background:#fcf0f1;color:#d63638">Fout</span>';
        }
        return '<span class="eme-acc-tag eme-acc-pending">Nieuw</span>';
    };

    function bookingBadge(data) {
        return data.booking_status === 'booked'
            ? '<span class="eme-acc-tag eme-acc-reconciled">Geboekt</span>'
            : '<span class="eme-acc-tag eme-acc-pending">Niet geboekt</span>';
    }

    function fmtCur(value, currency) {
        const amount = Number(value) || 0;
        try {
            return new Intl.NumberFormat('nl-BE', {
                style: 'currency',
                currency: currency || emeAccounting.i18n.currency || 'EUR',
                minimumFractionDigits: 2
            }).format(amount);
        } catch (e) { // malformed currency code from external data
            return amount.toFixed(2) + ' ' + (currency || '');
        }
    }

    /* ── Inbox table ──────────────────────────────────────────────── */
    let table = null;
    const container = document.getElementById('PeppolTableContainer');

    if (container) {
        table = new FTable('#PeppolTableContainer', {
            forcePost: true,
            paging: true,
            sorting: true,
            sortingResetButton: true,
            multiSorting: true,
            defaultSorting: 'received_at DESC, id DESC',
            actions: {
                listAction: ajaxUrl + '?action=eme_accounting_peppol_list&nonce=' + nonce
            },
            listQueryParams: function () {
                return {
                    search: (document.getElementById('peppol-search-filter') || {}).value || '',
                    document_type: (document.getElementById('peppol-type-filter') || {}).value || '',
                    status: (document.getElementById('peppol-status-filter') || {}).value || '',
                    booking_status: (document.getElementById('peppol-booking-filter') || {}).value || '',
                    daterange: isoRange((document.getElementById('peppol-daterange-filter') || {}).value || '')
                };
            },
            fields: {
                id: {
                    title: '#', key: true, list: false, create: false, edit: false
                },
                invoice_date: {
                    title: 'Factuurdatum', sortable: true, create: false, edit: false,
                    display: function (data) {
                        return data.record.invoice_date_display
                            ? E(data.record.invoice_date_display)
                            : E(data.value || '');
                    }
                },
                invoice_id: {
                    title: 'Factuurnummer', sortable: true, create: false, edit: false,
                    display: function (data) {
                        return data.value ? '<strong>' + E(data.value) + '</strong>' : '<span style="color:#a7aaad">—</span>';
                    }
                },
                vendor_name: {
                    title: 'Leverancier', sortable: true, create: false, edit: false,
                    display: function (data) {
                        if (!data.value && !data.record.vendor_tax_id) return '';
                        return '<span style="font-size:12px">' + E(data.value || '') +
                            (data.record.vendor_tax_id
                                ? '<br><span style="color:#646970">' + E(data.record.vendor_tax_id) + '</span>'
                                : '') +
                            '</span>';
                    }
                },
                document_type: {
                    title: 'Type', sortable: true, create: false, edit: false,
                    display: function (data) {
                        if (!data.value) return '';
                        return '<span class="eme-acc-tag">' + E(typeLabels[data.value] || data.value) + '</span>';
                    }
                },
                invoice_total: {
                    title: 'Totaal', sortable: true, create: false, edit: false,
                    listClass: 'amount',
                    display: function (data) {
                        return '<span style="font-weight:bold">' + fmtCur(data.value, data.record.currency) + '</span>';
                    }
                },
                amount_due: {
                    title: 'Openstaand', sortable: false, create: false, edit: false,
                    listClass: 'amount',
                    display: function (data) {
                        return fmtCur(data.value, data.record.currency);
                    }
                },
                peppol_status: {
                    title: 'Toestand', sortable: true, create: false, edit: false,
                    display: function (data) {
                        if (!data.value) return '';
                        const cls = data.value === 'FAILED' ? 'eme-acc-pending' : 'eme-acc-reconciled';
                        return '<span class="eme-acc-tag ' + cls + '">' + E(data.value) + '</span>';
                    }
                },
                processing_status: {
                    title: 'Status', sortable: true, create: false, edit: false,
                    display: function (data) {
                        return statusBadge(data.value);
                    }
                },
                booking_status: {
                    title: 'Boeking', sortable: true, create: false, edit: false,
                    display: function (data) {
                        if (data.value === 'booked') {
                            const url = data.record.journal_edit_url;
                            return '<span class="eme-acc-tag eme-acc-reconciled">Geboekt</span>' +
                                (url ? ' <a href="' + E(url) + '">#' + E(data.record.journal_id) + '</a>' : '');
                        }
                        return '<span class="eme-acc-tag eme-acc-pending">Niet geboekt</span>';
                    }
                },
                received_at: {
                    title: 'Ontvangen', sortable: true, create: false, edit: false,
                    display: function (data) {
                        return '<span style="font-size:11px;color:#646970">' +
                            E(data.record.received_at_display || data.value || '') + '</span>';
                    }
                },
                files: {
                    title: 'Bestanden', sortable: false, create: false, edit: false,
                    display: function (data) {
                        return data.value || '';
                    }
                },
                _actions: {
                    title: 'Acties', sortable: false, create: false, edit: false,
                    display: function (data) {
                        let html = '<button type="button" class="button button-small peppol-view" data-id="' + E(data.record.id) + '">Details</button>';
                        if (data.record.processing_status === 'ignored') {
                            html += ' <button type="button" class="button button-small peppol-ignore" data-id="' +
                                E(data.record.id) + '" data-ignored="0">Terugzetten</button>';
                        } else {
                            html += ' <button type="button" class="button button-small peppol-ignore" data-id="' +
                                E(data.record.id) + '" data-ignored="1">Negeren</button>';
                        }
                        if (data.record.processing_status === 'error') {
                            html += ' <button type="button" class="button button-small peppol-retry" data-id="' +
                                E(data.record.id) + '">Opnieuw</button>';
                        }
                        return html;
                    }
                }
            }
        });

        table.load();

        const filterIds = ['peppol-type-filter', 'peppol-status-filter', 'peppol-booking-filter', 'peppol-daterange-filter'];
        filterIds.forEach(function (id) {
            const el = document.getElementById(id);
            if (el) el.addEventListener('change', function () { table.reload(); });
        });

        const search = document.getElementById('peppol-search-filter');
        if (search) {
            let timer = null;
            search.addEventListener('input', function () {
                clearTimeout(timer);
                timer = setTimeout(function () { table.reload(); }, 350);
            });
        }
        const apply = document.getElementById('peppol-filter-apply');
        if (apply) apply.addEventListener('click', function () { table.reload(); });

        if (typeof FDatepicker !== 'undefined') {
            const range = document.getElementById('peppol-daterange-filter');
            if (range) new FDatepicker('#peppol-daterange-filter', { fieldsizing: true, format: 'd/m/Y' });
        }
    }

    /* ── Detail panel ─────────────────────────────────────────────── */
    const detail       = document.getElementById('peppol-detail');
    const detailBody   = document.getElementById('peppol-detail-body');
    const detailTitle  = document.getElementById('peppol-detail-title');
    const detailIgnore = document.getElementById('peppol-detail-ignore');
    const detailRetry  = document.getElementById('peppol-detail-retry');
    let detailRow = null;

    function row(label, value) {
        if (value === null || value === undefined || value === '') return '';
        return '<tr><th style="width:220px">' + E(label) + '</th><td>' + value + '</td></tr>';
    }

    function renderDetail(data) {
        const items = Array.isArray(data.items) ? data.items : [];
        const taxes = Array.isArray(data.tax_details) ? data.tax_details : [];
        const atts  = Array.isArray(data.attachments) ? data.attachments : [];

        let html = '<table class="eme-acc-table"><tbody>';
        html += row('Factuurnummer', E(data.invoice_id || ''));
        html += row('Type', E(typeLabels[data.document_type] || data.document_type || ''));
        html += row('Factuurdatum', E(data.invoice_date_display || data.invoice_date || ''));
        html += row('Vervaldatum', E(data.due_date || ''));
        html += row('Leverancier', E(data.vendor_name || '') +
            (data.vendor_tax_id ? '<br><span style="color:#646970">' + E(data.vendor_tax_id) + '</span>' : '') +
            (data.vendor_email ? '<br><span style="color:#646970">' + E(data.vendor_email) + '</span>' : ''));
        html += row('Klant', E(data.customer_name || '') +
            (data.customer_tax_id ? '<br><span style="color:#646970">' + E(data.customer_tax_id) + '</span>' : ''));
        html += row('Subtotaal', fmtCur(data.subtotal, data.currency));
        html += row('BTW', fmtCur(data.total_tax, data.currency));
        html += row('Totaal', '<strong>' + fmtCur(data.invoice_total, data.currency) + '</strong>');
        html += row('Openstaand', fmtCur(data.amount_due, data.currency));
        html += row('IBAN', E(data.payment_iban || ''));
        html += row('Betalingsreferentie', E(data.payment_reference || ''));
        html += row('Aankooporder', E(data.purchase_order || ''));
        html += row('Nota', E(data.note || ''));
        html += row('Toestand', E(data.peppol_status || ''));
        html += row('Verwerking', statusBadge(data.processing_status));
        let bookingHtml = bookingBadge(data);
        if (data.booking_status === 'booked') {
            if (data.journal_edit_url) {
                bookingHtml += ' <a href="' + E(data.journal_edit_url) + '">Open boeking #' + E(data.journal_id) + '</a>';
            }
            if (cfg.canManage) {
                bookingHtml += ' <button type="button" class="button button-small peppol-unlink">Ontkoppel</button>';
            }
        } else if (cfg.canManage) {
            bookingHtml += ' <button type="button" class="button button-small peppol-book-open">Boek naar dagboek</button>';
        }
        html += row('Boeking', bookingHtml);
        html += row('Ontvangen', E(data.received_at || ''));
        html += row('Opgehaald', E(data.fetched_at || ''));
        if (data.last_error) {
            html += row('Laatste fout', '<span style="color:#d63638">' + E(data.last_error) + '</span>');
        }
        html += '</tbody></table>';

        if (items.length) {
            html += '<h3>Lijnen</h3><table class="eme-acc-table"><thead><tr>' +
                '<th>Omschrijving</th><th>Aantal</th><th>Eenheidsprijs</th><th>Totaal</th><th>BTW</th>' +
                '</tr></thead><tbody>';
            items.forEach(function (item) {
                const desc  = item.description || item.name || item.item_name || '';
                const qty   = item.quantity !== undefined ? item.quantity : '';
                const price = item.unit_price !== undefined ? item.unit_price : (item.price !== undefined ? item.price : '');
                const total = item.line_total !== undefined ? item.line_total
                    : (item.total_amount !== undefined ? item.total_amount
                    : (item.amount !== undefined ? item.amount : ''));
                const vat   = item.vat_rate !== undefined ? item.vat_rate
                    : (item.tax_rate !== undefined ? item.tax_rate : '');
                html += '<tr><td>' + E(desc) + '</td><td>' + E(qty) + '</td>' +
                    '<td class="amount">' + (price !== '' ? fmtCur(price, data.currency) : '') + '</td>' +
                    '<td class="amount">' + (total !== '' ? fmtCur(total, data.currency) : '') + '</td>' +
                    '<td>' + E(vat !== '' ? vat + '%' : '') + '</td></tr>';
            });
            html += '</tbody></table>';
        }

        if (taxes.length) {
            html += '<h3>BTW-details</h3><table class="eme-acc-table"><thead><tr>' +
                '<th>Categorie</th><th>Tarief</th><th>Basis</th><th>BTW</th></tr></thead><tbody>';
            taxes.forEach(function (tax) {
                const cat   = tax.category || tax.tax_category || tax.code || '';
                const rate  = tax.rate !== undefined ? tax.rate
                    : (tax.tax_rate !== undefined ? tax.tax_rate : '');
                const base  = tax.taxable_amount !== undefined ? tax.taxable_amount
                    : (tax.base !== undefined ? tax.base : (tax.amount_base !== undefined ? tax.amount_base : ''));
                const amt   = tax.tax_amount !== undefined ? tax.tax_amount
                    : (tax.amount !== undefined ? tax.amount : '');
                html += '<tr><td>' + E(cat) + '</td><td>' + E(rate !== '' ? rate + '%' : '') + '</td>' +
                    '<td class="amount">' + (base !== '' ? fmtCur(base, data.currency) : '') + '</td>' +
                    '<td class="amount">' + (amt !== '' ? fmtCur(amt, data.currency) : '') + '</td></tr>';
            });
            html += '</tbody></table>';
        }

        if (atts.length) {
            html += '<h3>Bijlagen (e-invoice.be)</h3><ul style="font-size:12px">';
            atts.forEach(function (att) {
                html += '<li>' + E(att.file_name || att.name || att.id || '') +
                    (att.file_type ? ' <span style="color:#646970">(' + E(att.file_type) + ')</span>' : '') + '</li>';
            });
            html += '</ul>';
        }

        if (data.files) {
            html += '<h3>Lokale bestanden</h3><div>' + data.files + '</div>';
        }

        detailBody.innerHTML = html;
        detailTitle.textContent = (data.invoice_id || data.document_id || 'Document') +
            (data.vendor_name ? ' – ' + data.vendor_name : '');

        detailRetry.style.display = data.processing_status === 'error' ? '' : 'none';
        detailIgnore.style.display = '';
        detailIgnore.textContent = data.processing_status === 'ignored' ? 'Terugzetten' : 'Negeren';

        detail.style.display = '';
        detail.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    function openDetail(id) {
        detailBody.innerHTML = '<p>Laden…</p>';
        detail.style.display = '';
        post('eme_accounting_peppol_get', { id: id }).then(function (r) {
            if (!r.success) {
                detailBody.innerHTML = '<p style="color:#d63638">' + E(r.data || 'Fout') + '</p>';
                return;
            }
            detailRow = r.data;
            renderDetail(r.data);
        });
    }

    /* ── Manual booking ───────────────────────────────────────────── */
    const bookingBox        = document.getElementById('peppol-booking');
    const bookingForm       = document.getElementById('peppol-booking-form');
    const bookingCandidates = document.getElementById('peppol-booking-candidates');
    const bookingResult     = document.getElementById('peppol-booking-result');

    function bookingDirection(data) {
        return String(data.document_type || '').indexOf('CREDIT_NOTE') !== -1 ? 'in' : 'out';
    }

    function bookingAmount(data) {
        const amount = Math.abs(Number(data.invoice_total) || 0) ||
            Math.abs(Number(data.amount_due) || 0);
        return amount;
    }

    function bookingDescription(data) {
        const label = bookingDirection(data) === 'in' ? 'Peppol creditnota' : 'Peppol factuur';
        let text = label;
        if (data.invoice_id) text += ' ' + data.invoice_id;
        if (data.vendor_name) text += ' – ' + data.vendor_name;
        return text;
    }

    function amountInput(value) {
        return (Number(value) || 0).toFixed(2).replace('.', ',');
    }

    function bookingValues() {
        const data = {};
        if (!bookingForm) return data;
        new FormData(bookingForm).forEach(function (value, key) { data[key] = value; });
        return data;
    }

    function openBookingForm(data) {
        if (!bookingBox || !bookingForm || !data) return;

        bookingForm.reset();
        bookingForm.querySelector('#peppol-booking-id').value = data.id || '';
        bookingForm.querySelector('#peppol-booking-date').value =
            data.invoice_date || new Date().toISOString().slice(0, 10);
        bookingForm.querySelector('#peppol-booking-amount').value = amountInput(bookingAmount(data));
        bookingForm.querySelector('#peppol-booking-description').value = bookingDescription(data);
        bookingForm.querySelector('#peppol-booking-reference').value =
            data.payment_reference || data.invoice_id || '';

        const direction = bookingDirection(data);
        const info = document.getElementById('peppol-booking-info');
        if (info) {
            info.textContent = (data.invoice_id || data.document_id || '') +
                (data.vendor_name ? ' – ' + data.vendor_name : '');
        }
        const dirInfo = document.getElementById('peppol-booking-direction');
        if (dirInfo) {
            dirInfo.textContent = direction === 'in'
                ? 'Richting: ontvangst (creditnota)'
                : 'Richting: uitgave (factuur)';
        }

        const coaSelect = bookingForm.querySelector('#peppol-booking-coa');
        if (coaSelect) {
            const placeholder = coaSelect.querySelector('option[value=""]');
            const opts = direction === 'in'
                ? (cfg.coaSelectIn || [])
                : (cfg.coaSelectOut || []);
            coaSelect.innerHTML = '';
            if (placeholder) coaSelect.appendChild(placeholder);
            coaSelect.insertAdjacentHTML('beforeend', opts.join(''));
            coaSelect.value = '';
        }

        if (!bookingForm.querySelector('#peppol-booking-account').value) {
            const accountSelect = bookingForm.querySelector('#peppol-booking-account');
            if (accountSelect.options.length === 2) {
                accountSelect.selectedIndex = 1;
            }
        }

        if (bookingCandidates) bookingCandidates.innerHTML = '';
        if (bookingResult) {
            bookingResult.textContent = '';
            bookingResult.style.color = '';
        }
        bookingBox.style.display = '';
        bookingBox.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    function renderBookingCandidates(candidates) {
        if (!bookingCandidates) return;

        let html = '<h3>Bestaande boekingen met hetzelfde bedrag</h3>';
        if (!candidates.length) {
            html += '<p>Geen bestaande boeking gevonden.</p>';
        } else {
            html += '<table class="eme-acc-table"><thead><tr>' +
                '<th>Datum</th><th>Omschrijving</th><th>Bedrag</th><th>Status</th><th></th>' +
                '</tr></thead><tbody>';
            candidates.forEach(function (candidate) {
                html += '<tr><td>' + E(candidate.entry_date_display || candidate.entry_date) + '</td>' +
                    '<td>' + E(candidate.description) + '</td>' +
                    '<td class="amount">' + fmtCur(candidate.amount) + '</td>' +
                    '<td>' + (Number(candidate.is_reconciled)
                        ? '<span class="eme-acc-tag eme-acc-reconciled">Afgestemd</span>'
                        : '<span class="eme-acc-tag eme-acc-pending">Open</span>') + '</td>' +
                    '<td><button type="button" class="button button-small peppol-book-link" data-journal-id="' +
                    E(candidate.id) + '">Koppel</button></td></tr>';
            });
            html += '</tbody></table>';
        }
        html += '<p><button type="button" id="peppol-book-force" class="button">' +
            'Toch een nieuwe boeking aanmaken</button></p>';
        bookingCandidates.innerHTML = html;
    }

    function handleBookingResponse(r) {
        if (!bookingResult) return;
        if (!r.success) {
            bookingResult.textContent = r.data || 'Fout';
            bookingResult.style.color = '#d63638';
            return;
        }
        if (r.data && r.data.needs_choice) {
            renderBookingCandidates(r.data.candidates || []);
            bookingResult.textContent = 'Er bestaat al een passende boeking. Kies een bestaande boeking of maak toch een nieuwe.';
            bookingResult.style.color = '#646970';
            return;
        }
        if (bookingBox) bookingBox.style.display = 'none';
        if (bookingCandidates) bookingCandidates.innerHTML = '';
        if (table) table.reload();
        if (detailRow) openDetail(detailRow.id);
    }

    if (bookingForm) {
        bookingForm.addEventListener('submit', function (e) {
            e.preventDefault();
            if (bookingResult) {
                bookingResult.textContent = 'Bezig…';
                bookingResult.style.color = '#646970';
            }
            post('eme_accounting_peppol_book', bookingValues()).then(handleBookingResponse);
        });
    }

    /* ── Delegated clicks ─────────────────────────────────────────── */
    document.addEventListener('click', function (e) {
        const bookOpen = e.target.closest('.peppol-book-open');
        if (bookOpen && detailRow) {
            openBookingForm(detailRow);
            return;
        }

        const unlink = e.target.closest('.peppol-unlink');
        if (unlink && detailRow) {
            if (!window.confirm('Boeking ontkoppelen? De journaalboeking blijft bestaan.')) return;
            unlink.disabled = true;
            post('eme_accounting_peppol_unlink', { id: detailRow.id }).then(function (r) {
                unlink.disabled = false;
                if (!r.success) { alert(r.data || 'Fout'); return; }
                if (table) table.reload();
                openDetail(detailRow.id);
            });
            return;
        }

        const link = e.target.closest('.peppol-book-link');
        if (link) {
            const data = bookingValues();
            data.journal_id = link.dataset.journalId;
            link.disabled = true;
            if (bookingResult) {
                bookingResult.textContent = 'Bezig…';
                bookingResult.style.color = '#646970';
            }
            post('eme_accounting_peppol_link', data).then(function (r) {
                link.disabled = false;
                handleBookingResponse(r);
            });
            return;
        }

        if (e.target.id === 'peppol-book-force') {
            const data = bookingValues();
            data.force_create = 1;
            e.target.disabled = true;
            if (bookingResult) {
                bookingResult.textContent = 'Bezig…';
                bookingResult.style.color = '#646970';
            }
            const forceButton = e.target;
            post('eme_accounting_peppol_book', data).then(function (r) {
                forceButton.disabled = false;
                handleBookingResponse(r);
            });
            return;
        }

        if (e.target.id === 'peppol-booking-cancel') {
            if (bookingBox) bookingBox.style.display = 'none';
            if (bookingCandidates) bookingCandidates.innerHTML = '';
            return;
        }

        const view = e.target.closest('.peppol-view');
        if (view) {
            openDetail(view.dataset.id);
            return;
        }

        const ignore = e.target.closest('.peppol-ignore');
        if (ignore) {
            const ignored = ignore.dataset.ignored === '1' ? 1 : 0;
            ignore.disabled = true;
            post('eme_accounting_peppol_ignore', { id: ignore.dataset.id, ignored: ignored }).then(function (r) {
                ignore.disabled = false;
                if (!r.success) { alert(r.data || 'Fout'); return; }
                if (table) table.reload();
                if (detailRow && String(detailRow.id) === String(ignore.dataset.id)) {
                    detailRow.processing_status = r.data.status;
                    renderDetail(detailRow);
                }
            });
            return;
        }

        const retry = e.target.closest('.peppol-retry');
        if (retry) {
            retry.disabled = true;
            retry.textContent = 'Bezig…';
            post('eme_accounting_peppol_retry', { id: retry.dataset.id }).then(function (r) {
                retry.disabled = false;
                retry.textContent = 'Opnieuw';
                if (!r.success) { alert(r.data || 'Fout'); return; }
                if (table) table.reload();
                openDetail(retry.dataset.id);
            });
            return;
        }

        if (e.target.id === 'peppol-detail-close') {
            detail.style.display = 'none';
            detailRow = null;
            return;
        }

        if (e.target.id === 'peppol-detail-ignore' && detailRow) {
            const ignored = detailRow.processing_status === 'ignored' ? 0 : 1;
            e.target.disabled = true;
            post('eme_accounting_peppol_ignore', { id: detailRow.id, ignored: ignored }).then(function (r) {
                e.target.disabled = false;
                if (!r.success) { alert(r.data || 'Fout'); return; }
                detailRow.processing_status = r.data.status;
                renderDetail(detailRow);
                if (table) table.reload();
            });
            return;
        }

        if (e.target.id === 'peppol-detail-retry' && detailRow) {
            e.preventDefault();
            e.target.textContent = 'Bezig…';
            post('eme_accounting_peppol_retry', { id: detailRow.id }).then(function (r) {
                e.target.textContent = 'Opnieuw ophalen';
                if (!r.success) { alert(r.data || 'Fout'); return; }
                openDetail(detailRow.id);
                if (table) table.reload();
            });
            return;
        }
    });

    /* ── Sync now (inbox + settings) ──────────────────────────────── */
    const syncBtn = document.getElementById('peppol-api-sync');
    if (syncBtn) {
        syncBtn.addEventListener('click', function () {
            const result = document.getElementById('peppol-api-sync-result');
            syncBtn.disabled = true;
            if (result) result.textContent = 'Bezig…';
            post('eme_accounting_peppol_api_sync', {}).then(function (r) {
                syncBtn.disabled = false;
                if (!r.success) {
                    if (result) result.textContent = r.data || 'Fout';
                    return;
                }
                const s = r.data.summary || {};
                if (result) {
                    result.textContent = 'Verwerkt: ' + (s.processed || 0) +
                        ', overgeslagen: ' + (s.skipped || 0) +
                        ', fouten: ' + (s.errors || 0);
                }
                if (table) table.reload();
            });
        });
    }

    /* ── XML upload ───────────────────────────────────────────────── */
    const xmlBtn = document.getElementById('peppol-xml-import');
    if (xmlBtn) {
        xmlBtn.addEventListener('click', function () {
            const input  = document.getElementById('peppol-xml-files');
            const result = document.getElementById('peppol-xml-result');
            const detailEl = document.getElementById('peppol-xml-detail');
            const files  = input && input.files ? Array.prototype.slice.call(input.files) : [];

            if (!files.length) {
                if (result) result.textContent = 'Kies eerst een of meer XML-bestanden.';
                return;
            }

            const fd = new FormData();
            fd.append('action', 'eme_accounting_peppol_import_xml');
            fd.append('nonce', nonce);
            files.forEach(function (file) { fd.append('xml_files[]', file); });

            xmlBtn.disabled = true;
            if (result) result.textContent = 'Bezig…';
            if (detailEl) detailEl.textContent = '';

            fetch(ajaxUrl, { method: 'POST', body: fd })
                .then(function (r) { return r.json(); })
                .catch(function () { return { success: false, data: 'Netwerk- of serverfout' }; })
                .then(function (r) {
                    xmlBtn.disabled = false;
                    if (!r.success) {
                        if (result) result.textContent = r.data || 'Fout';
                        return;
                    }
                    const s = r.data || {};
                    if (result) {
                        result.textContent = 'Verwerkt: ' + (s.processed || 0) +
                            ', overgeslagen: ' + (s.skipped || 0) +
                            ', fouten: ' + (s.errors || 0);
                    }
                    if (detailEl) {
                        detailEl.textContent = Array.isArray(s.detail) ? s.detail.join('\n') : '';
                    }
                    if (input) input.value = '';
                    if (table) table.reload();
                });
        });
    }

    /* ── Settings page actions ────────────────────────────────────── */
    function setText(id, text) {
        const el = document.getElementById(id);
        if (el) el.textContent = text;
    }

    const testBtn = document.getElementById('peppol-api-test');
    if (testBtn) {
        testBtn.addEventListener('click', function () {
            testBtn.disabled = true;
            setText('peppol-api-test-result', 'Bezig…');
            post('eme_accounting_peppol_api_test', {}).then(function (r) {
                testBtn.disabled = false;
                if (!r.success) {
                    setText('peppol-api-test-result', r.data || 'Fout');
                    return;
                }
                setText('peppol-api-test-result', 'Verbonden met ' +
                    (r.data.company_name || 'e-invoice.be') +
                    (r.data.company_email ? ' (' + r.data.company_email + ')' : ''));
            });
        });
    }

    const mailboxTest = document.getElementById('peppol-mailbox-test');
    if (mailboxTest) {
        mailboxTest.addEventListener('click', function () {
            mailboxTest.disabled = true;
            setText('peppol-mailbox-test-result', 'Bezig…');
            post('eme_accounting_peppol_mailbox_test', {}).then(function (r) {
                mailboxTest.disabled = false;
                if (!r.success) {
                    setText('peppol-mailbox-test-result', r.data || 'Fout');
                    return;
                }
                setText('peppol-mailbox-test-result', 'Verbonden met ' +
                    (r.data.mailbox || 'mailbox') + ': ' + (r.data.messages || 0) +
                    ' berichten in de map.');
            });
        });
    }

    const mailboxSync = document.getElementById('peppol-mailbox-sync');
    if (mailboxSync) {
        mailboxSync.addEventListener('click', function () {
            mailboxSync.disabled = true;
            setText('peppol-mailbox-sync-result', 'Bezig…');
            post('eme_accounting_peppol_mailbox_sync', {}).then(function (r) {
                mailboxSync.disabled = false;
                if (!r.success) {
                    setText('peppol-mailbox-sync-result', r.data || 'Fout');
                    return;
                }
                const s = r.data.summary || {};
                setText('peppol-mailbox-sync-result', 'Verwerkt: ' + (s.processed || 0) +
                    ', overgeslagen: ' + (s.skipped || 0) +
                    ', fouten: ' + (s.errors || 0));
                if (table) table.reload();
            });
        });
    }
})();
