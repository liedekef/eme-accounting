/**
 * Journal page: fiscal year auto-detection
 */
(function() {
    var entryDateInput = document.getElementById('entry_date');
    var fyInfoDiv = document.getElementById('fiscal-year-info');
    var fyDisplay = document.getElementById('fy-display');

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
                    fyDisplay.textContent = emeAccounting.i18n.noFyFound;
                    fyDisplay.style.color = '#d63638';
                }
            })
            .catch(function() {
                fyDisplay.textContent = '-';
            });
    }

    entryDateInput.addEventListener('change', checkFiscalYear);
    checkFiscalYear();
})();
