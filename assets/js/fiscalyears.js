/**
 * Fiscal Years page: CRUD operations
 */
function emeAccEscapeHtml(str) {
    var div = document.createElement('div');
    div.appendChild(document.createTextNode(str));
    return div.innerHTML;
}

(function() {
    var form = document.getElementById('eme-acc-fiscal-year-form');
    var idHidden = document.getElementById('fy_id');
    var yearInput = document.getElementById('fy_year_input');
    var submitBtn = document.getElementById('fy_submit');

    // Pin the page URL at load time, so an async continuation always reloads
    // the current admin page instead of whatever URL the last fetch used.
    var pageUrl = window.location.href;

    function reloadPage() {
        window.location.href = pageUrl;
    }

    function clearForm() {
        idHidden.value = '';
        yearInput.value = '';
        yearInput.readOnly = false;
    }

    form.addEventListener('submit', function(e) {
        e.preventDefault();
        if (!submitBtn) return;
        submitBtn.disabled = true;
        const originalText = submitBtn.textContent;
        submitBtn.textContent = emeAccounting.i18n.saving;

        var data = new FormData();
        data.append('action', 'eme_accounting_save_fiscal_year');
        data.append('nonce', emeAccounting.nonce);
        data.append('id', idHidden.value);
        data.append('year', yearInput.value);

        fetch(emeAccounting.ajaxUrl, { method: 'POST', body: data })
            .then(function(r) { return r.json(); })
            .then(async function(r) {
                if (r.success) {
                    await FTable.alert('Opslaan boekjaar gelukt', emeAccEscapeHtml(r.data.message + ' ' + (r.data.result || '')));
                } else {
                    await FTable.alert('Fout', emeAccEscapeHtml(r.data));
                }
                reloadPage();
            })
            .catch(async function() {
                await FTable.alert('Fout', emeAccounting.i18n.error);
                reloadPage();
            });
    });

    document.querySelectorAll('.btn-close').forEach(function(btn) {
        btn.addEventListener('click', async function() {
            var year = this.dataset.year;
            const ok = await FTable.confirm( 'Afsluiten', emeAccounting.i18n.confirmClose.replace('%s', year) );
            if (!ok) return;
            this.disabled = true;
            this.textContent = emeAccounting.i18n.processing;

            var data = new FormData();
            data.append('action', 'eme_accounting_close_fiscal_year');
            data.append('nonce', emeAccounting.nonce);
            data.append('year', year);

            fetch(emeAccounting.ajaxUrl, { method: 'POST', body: data })
                .then(function(r) { return r.json(); })
                .then(async function(r) {
                    if (r.success) {
                        await FTable.alert('Afsluiten', emeAccEscapeHtml(r.data.message + ' ' + (r.data.result || '')));
                    } else {
                        await FTable.alert('Fout', emeAccEscapeHtml(r.data));
                    }
                    reloadPage();
                })
                .catch(async function() {
                    await FTable.alert('Fout', emeAccounting.i18n.error);
                    reloadPage();
                });
        });
    });

    document.querySelectorAll('.btn-reopen').forEach(function(btn) {
        btn.addEventListener('click', async function() {
            var year = this.dataset.year;
            const ok = await FTable.confirm( 'Heropenen', emeAccounting.i18n.confirmReopen.replace('%s', year) );
            if (!ok) return;
            this.disabled = true;
            this.textContent = emeAccounting.i18n.processing;

            var data = new FormData();
            data.append('action', 'eme_accounting_reopen_fiscal_year');
            data.append('nonce', emeAccounting.nonce);
            data.append('year', year);

            fetch(emeAccounting.ajaxUrl, { method: 'POST', body: data })
                .then(function(r) { return r.json(); })
                .then(async function(r) {
                    if (r.success) {
                        await FTable.alert('Heropenen', emeAccEscapeHtml(r.data.message));
                    } else {
                        await FTable.alert('Fout', emeAccEscapeHtml(r.data));
                    }
                    reloadPage();
                })
                .catch(async function() {
                    await FTable.alert('Fout', emeAccounting.i18n.error);
                    reloadPage();
                });
        });
    });

    document.querySelectorAll('.btn-delete').forEach(function(btn) {
        btn.addEventListener('click', async function() {
            var year = this.dataset.year;
            const ok = await FTable.confirm( 'Verwijderen', emeAccounting.i18n.confirmDelete.replace('%s', year) );
            if (!ok) return;
            this.disabled = true;
            this.textContent = emeAccounting.i18n.processing;

            var data = new FormData();
            data.append('action', 'eme_accounting_delete_fiscal_year');
            data.append('nonce', emeAccounting.nonce);
            data.append('year', year);

            fetch(emeAccounting.ajaxUrl, { method: 'POST', body: data })
                .then(function(r) { return r.json(); })
                .then(async function(r) {
                    if (r.success) {
                        await FTable.alert('Verwijderen', emeAccEscapeHtml(r.data.message));
                    } else {
                        await FTable.alert('Fout', emeAccEscapeHtml(r.data));
                    }
                    reloadPage();
                })
                .catch(async function() {
                    await FTable.alert('Fout', emeAccounting.i18n.error);
                    reloadPage();
                });
        });
    });
})();
