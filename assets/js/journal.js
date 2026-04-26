/**
 * Journal page: fiscal year auto-detection
 */
(function() {
    var entryDateInput = document.getElementById('entry_date');
    var fyInfoDiv = document.getElementById('fiscal-year-info');
    var fyDisplay = document.getElementById('fy-display');
    const directionSelect = document.getElementById('direction');

    let JournalTable;

    function handleJournalSave(form) {
        const btn = form.querySelector('.submit-btn');
        const originalText = btn.textContent;
        const isNewEntry = !form.querySelector('input[name="id"]') ||
            !form.querySelector('input[name="id"]').value;
        btn.disabled = true;
        const journalEntryToSave = formToObj(form);
        journalEntryToSave.amount = parseBE(String(journalEntryToSave.amount));  // Belgische notatie normaliseren
        ajax('eme_accounting_save_entry', journalEntryToSave,
            (data) => {
                if (isNewEntry) {
                    flashButton(btn, emeAccounting.i18n.saving);
                    JournalTable.reload();
                    form.reset();
                    directionSelect.dispatchEvent(new Event('change'));
                } else {
                    location.reload();
                }
            },
            (errorMsg) => {  // Error callback met message
                alert(errorMsg);
                btn.disabled = false;
                btn.textContent = originalText;
            }
        );
    }

    function handleJournalSearch(form) {
        JournalTable.reload();
    }

    function handleJournalDelete(btn) {
        if (!confirm(emeAccounting.i18n.confirm_delete)) return;
        ajax('eme_accounting_delete_entry', { id: btn.dataset.id }, () =>
            fadeRemove(document.querySelector(`[data-journal-row="${btn.dataset.id}"]`))
        );
    }

    function handleDeleteUpload(btn) {
        if (!confirm(emeAccounting.i18n.confirm_delete)) return;
        const parentlink = btn.closest('.del_upload-button');
        let id = parentlink.dataset.id;
        let name = parentlink.dataset.name;
        let type = parentlink.dataset.type;
        let random_id = parentlink.dataset.random_id;
        let key = parentlink.dataset.key;

        const formData = {
            id: id,
            name: name,
            type: type,
            key: key,
            random_id: random_id,
        };

        ajax('eme_accounting_del_journal_attachment', formData, 
            () => {
                const spanEl = document.getElementById('span_' + random_id);
                if (spanEl) spanEl.remove();
            });
    }

    /* ═══════════════════════════════════════════════════════════════════
     * SINGLE SUBMIT DISPATCHER
     * ═══════════════════════════════════════════════════════════════════ */
    const submitHandlers = {
        'eme-acc-journal-form':        handleJournalSave,
        'eme-acc-filter-form':         handleJournalSearch
    };

    document.addEventListener('submit', function (e) {
        // If e.target is an input, e.target.form gives the parent form
        const form = e.target.form || e.target.closest('form');
        if (!form) return;
        const formId = form.getAttribute('id');
        const handler = submitHandlers[formId];
        if (!handler) return;
        e.preventDefault();
        handler(e.target);
    });

    /* ═══════════════════════════════════════════════════════════════════
     * SINGLE CLICK DISPATCHER
     * ═══════════════════════════════════════════════════════════════════ */
    const clickHandlers = {
        'eme-acc-delete-entry': handleJournalDelete,
        'del_upload-button':    handleDeleteUpload

    };
    document.addEventListener('click', function (e) {
        for (const [cls, handler] of Object.entries(clickHandlers)) {
            const btn = e.target.closest(`#${cls}, .${cls}`);
            if (btn) { handler(btn, e); return; }
        }
    });

    /* ═══════════════════════════════════════════════════════════════════
     * JOURNAL FTABLE
     * ═══════════════════════════════════════════════════════════════════ */
    const JournalTableContainer = document.querySelector('#JournalTableContainer');
    if (JournalTableContainer) {
        JournalTable = new FTable('#JournalTableContainer', {
            title: '',
            paging: true,
            sorting: true,
            sortingResetButton: true,
            multiSorting: true,
            defaultSorting: 'entry_date DESC, id DESC',
            selecting: false,
            multiselect: false,
            actions: {
                listAction: emeAccounting.ajaxUrl + '?action=eme_accounting_journal_list&nonce=' + emeAccounting.nonce,
                deleteAction: emeAccounting.ajaxUrl + '?action=eme_accounting_delete_entry&nonce=' + emeAccounting.nonce
            },
            deleteConfirmation: function(data) {
                if (data.record.direction=='transfer') {
                    data.deleteConfirmMessage = "Weet u zeker dat u deze transfer van " + formatCurrency(data.record.amount) + " wil verwijderen? Dit zal beide entries van de transfer verwijderen."; 
                } else {
                    data.deleteConfirmMessage = "Weet u zeker dat u deze boeking van " + formatCurrency(data.record.amount) + " wil verwijderen?"; 
                }
            },
            recordDeleted: function(eventArgs) {
                // since sometimes 2 lines are deleted in one go (linked transfers), we do a reload
                JournalTable.reload();
            },
            fields: {
                id: {
                    key: true,
                    width: '1%',
                    columnResizable: false,
                    list: false
                },
                entry_date: {
                    title: emeAccounting.i18n.date || 'Datum',
                    sortable: true
                },
                description: {
                    title: emeAccounting.i18n.description || 'Omschrijving',
                    sortable: true,
                    display: function(data) {
                        return '<a href="' + data.record.edit_url + '">' + esc(data.value) + '</a>';
                    }
                },
                coa_name: {
                    title: emeAccounting.i18n.category || 'Categorie',
                    sortable: false,
                    searchable: false,
                    display: function(data) {
                        return data.value ? '<span class="eme-acc-tag">' + esc(data.value) + '</span>' : '';
                    }
                },
                bank_statement_ref: {
                    title: emeAccounting.i18n.bankref || 'Bankref.',
                    sortable: false,
                    searchable: false,
                    display: function(data) {
                        if (!data.value) return '';
                        return '<span style="font-size:11px;color:#646970;max-width:120px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="' + esc(data.value) + '">' + esc(data.value) + '</span>';
                    }
                },
                files : {
                    title: emeAccounting.i18n.files || 'Categorie',
                    sortable: false,
                    searchable: false
                },
                eme_event: {
                    title: emeAccounting.i18n.event || 'Event',
                    sortable: false,
                    visibility: 'hidden'
                },
                account_name: {
                    title: emeAccounting.i18n.account || 'Rekening',
                    sortable: false,
                    searchable: false
                },
                amount: {
                    title: emeAccounting.i18n.amount || 'Bedrag',
                    sortable: true,
                    searchable: false,
                    display: function(data) {
                        const formatted_val = formatCurrency(data.value);
                        if (data.value<0) {
                            return '<span style="color: red; font-weight: bold;">' + formatted_val + '</span>';
                        } else {
                            return '<span style="color: green; font-weight: bold;">' + formatted_val + '</span>';
                        }
                    }
                },
                is_reconciled: {
                    title: emeAccounting.i18n.reconciled || 'Vereffend',
                    width: '1%',
                    sortable: false,
                    searchable: false,
                    display: function(data) {
                        if (data.value) {
                            const title = data.record.reconciled_at ? esc(data.record.reconciled_at) : '';
                            return '<span class="eme-acc-tag eme-acc-reconciled" title="' + title + '">✓</span>';
                        }
                        return '<span class="eme-acc-tag eme-acc-pending">' + esc(emeAccounting.i18n.pending || 'Openstaand') + '</span>';
                    }
                }
            },
            listQueryParams: () => ({
                fiscal_year: document.querySelector('#eme-acc-year-filter')?.value || '',
                account_id: document.querySelector('#eme-acc-account-filter')?.value || '',
                direction: document.querySelector('#eme-acc-direction-filter')?.value || '',
                coa_id: document.querySelector('#eme-acc-coa-filter')?.value || '',
                search: document.querySelector('#eme-acc-search-filter')?.value || '',
                daterange: document.querySelector('#eme-daterange-filter')?.value || '',
                amount: document.querySelector('#eme-acc-amount-filter')?.value || '',
                eme_event_id: document.querySelector('#eme-acc-event-filter')?.value || ''
            })
        });

        JournalTable.load();

        document.querySelectorAll('#eme-acc-filter-form select').forEach(function(sel) {
            sel.addEventListener('change', function() {
                JournalTable.reload();
            });
        });
    }

    function initJournalLinking() {
        const coaSelect       = document.getElementById('category_coa_id');
        const contraAccount   = document.getElementById('contra_account_id');
        const originalValue   = coaSelect.value;

        if (!directionSelect || !coaSelect) return;

        // Store original options for filtering
        const originalOptions = Array.from(coaSelect.options);

        // Get direction from optgroup label
        function getDirectionFromOptgroup(option) {
            const parentOptgroup = option.closest('optgroup');
            if (!parentOptgroup) return null;
            const label = parentOptgroup.getAttribute('label');
            if (label === 'Ontvangsten') return 'in';
            if (label === 'Uitgaven') return 'out';
            return null;
        }

        // Filter COA options based on direction
        function filterCoaByDirection(direction) {
            // Clear current options
            coaSelect.innerHTML = '';

            if (direction === 'transfer') {
                // Add empty option for transfer
                const emptyOption = document.createElement('option');
                emptyOption.value = '';
                emptyOption.textContent = '— Geen rekening voor transfers —';
                coaSelect.appendChild(emptyOption);
                coaSelect.disabled = true;
                return;
            }

            coaSelect.disabled = false;

            // Loop through original options and rebuild with optgroups
            let currentOptgroup = null;

            // Filter and add matching options
            originalOptions.forEach(option => {
                const parentOptgroup = option.parentElement;
                const optionDirection = getDirectionFromOptgroup(option);
                if (optionDirection === direction || (direction === null && !optionDirection)) {
                    // Check if we need to create a new optgroup
                    if (parentOptgroup && parentOptgroup.tagName === 'OPTGROUP') {
                        const groupLabel = parentOptgroup.getAttribute('label');
                        if (!currentOptgroup || currentOptgroup.label !== groupLabel) {
                            currentOptgroup = document.createElement('optgroup');
                            currentOptgroup.label = groupLabel;
                            coaSelect.appendChild(currentOptgroup);
                        }
                        currentOptgroup.appendChild(option.cloneNode(true));
                    } else {
                        coaSelect.appendChild(option.cloneNode(true));
                    }
                }
            });

            // Add empty option at the beginning
            const emptyOption = document.createElement('option');
            emptyOption.value = '';
            emptyOption.textContent = '— Kies rekening —';
            coaSelect.insertBefore(emptyOption, coaSelect.firstChild);

            // Reset selected value
            coaSelect.value = '';
        }

        // Set direction based on selected COA
        function setDirectionFromCoa() {
            const selectedOption = coaSelect.options[coaSelect.selectedIndex];
            if (!selectedOption || !selectedOption.value) return;

            const direction = getDirectionFromOptgroup(selectedOption);
            if (direction) {
                directionSelect.value = direction;
                directionSelect.disabled = true;
            }
        }

        // Handle manual direction change
        function handleDirectionChange() {
            const direction = directionSelect.value;

            contraAccount.disabled = true;
            contraAccount.required = false;
            if (direction === 'in') {
                filterCoaByDirection('in');
            } else if (direction === 'out') {
                filterCoaByDirection('out');
            } else if (direction === 'transfer') {
                filterCoaByDirection('transfer');
                contraAccount.disabled = false;
                contraAccount.required = true;
            } else {
                // Show all options if no valid direction
                filterCoaByDirection(null);
            }

            // Clear COA value when direction changes
            coaSelect.value = '';
        }

        // Event listeners
        directionSelect.addEventListener('change', handleDirectionChange);
        handleDirectionChange();

        // Initial setup based on current values
        const currentDirection = directionSelect.value;
        if (currentDirection === 'in' || currentDirection === 'out') {
            filterCoaByDirection(currentDirection);
        } else if (currentDirection === 'transfer') {
            filterCoaByDirection('transfer');
        } else {
            // not possible currently (currentDirection is in/out/transfer only
            coaSelect.addEventListener('change', setDirectionFromCoa);
            filterCoaByDirection(null);
        }

        // If there's a preselected COA (when editing), set direction accordingly
        if (originalValue) {
            setDirectionFromCoa();
            // since we would lose the selected value, set it again
            coaSelect.value = originalValue;
        }
    }
    initJournalLinking();

    function checkFiscalYear() {
        var date = entryDateInput.value;
        if (!date) {
            fyInfoDiv.style.display = 'none';
            return;
        }
        fyInfoDiv.style.display = 'block';

        var data = new FormData();
        data.append('action', 'eme_accounting_check_fiscal_year');
        data.append('nonce', emeAccounting.nonce);
        data.append('date', date);

        fetch(emeAccounting.ajaxUrl, { method: 'POST', body: data })
            .then(function(r) { return r.json(); })
            .then(function(r) {
                if (r.success && r.data) {
                    var fy = r.data;
                    fyDisplay.textContent = fy.year + ' (' + fy.start_date + ' - ' + fy.end_date + ')';
                    if (fy.status === 'closed') {
                        fyDisplay.style.color = '#d63638';
                        fyDisplay.textContent += ' [' + emeAccounting.i18n.fyClosed + ']';
                    } else {
                        fyDisplay.style.color = '#2271b1';
                    }
                } else {
                    fyDisplay.textContent = r.data || emeAccounting.i18n.noFyFound;
                    fyDisplay.style.color = '#d63638';
                }
            })
            .catch(function() {
                fyDisplay.textContent = '-';
            });
    }

    entryDateInput.addEventListener('change', checkFiscalYear);
    checkFiscalYear();

    // when editing, we will clear the edit param from the url
    const form = document.getElementById('eme-acc-journal-form');
    if (form) {
        // Remove only the 'edit' parameter from the URL
        const url = new URL(window.location.href);
        url.searchParams.delete('edit');
        window.history.replaceState({}, document.title, url.toString());
    }

    if (typeof FDatepicker !== 'undefined') {
        new FDatepicker('#localized_daterange-filter', {
            fieldsizing: true,
            format: 'd/m/Y'
        });
    }

})();
