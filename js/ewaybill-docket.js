/**
 * E-Way Bill Docket Entry — frontend logic
 */
(function () {
    'use strict';

    const csrf = window.EWB_CSRF || '';
    const demoMode = !!window.EWB_DEMO_MODE;
    const entries = new Map();

    const els = {
        input: document.getElementById('ewbNumbersInput'),
        fetchBtn: document.getElementById('fetchEwbBtn'),
        addBtn: document.getElementById('addEwbRowBtn'),
        rows: document.getElementById('ewbInputRows'),
        results: document.getElementById('ewbResults'),
        saveDraftBtn: document.getElementById('saveDraftBtn'),
        saveSubmitBtn: document.getElementById('saveSubmitBtn'),
        clearBtn: document.getElementById('clearAllBtn'),
        statusBar: document.getElementById('ewbStatusBar'),
        countBadge: document.getElementById('ewbCountBadge'),
    };

    function showStatus(msg, type) {
        if (!els.statusBar) return;
        els.statusBar.textContent = msg;
        els.statusBar.className = 'ewb-status-bar ' + (type || '');
        els.statusBar.style.display = msg ? 'block' : 'none';
    }

    function parseNumbers(text) {
        return [...new Set(
            text.split(/[\s,;]+/)
                .map(s => s.replace(/\D/g, ''))
                .filter(s => s.length > 0)
        )];
    }

    function collectInputNumbers() {
        const nums = [];
        els.rows.querySelectorAll('.ewb-no-input').forEach(inp => {
            const v = inp.value.replace(/\D/g, '');
            if (v) nums.push(v);
        });
        if (els.input && els.input.value.trim()) {
            parseNumbers(els.input.value).forEach(n => nums.push(n));
        }
        return [...new Set(nums)];
    }

    function validateEwb(no) {
        return /^\d{12}$/.test(no);
    }

    function renderResults() {
        if (entries.size === 0) {
            els.results.innerHTML = '<div class="ewb-empty">Enter E-Way Bill number(s) and click <strong>Fetch Details</strong>.</div>';
            els.countBadge.textContent = '0 loaded';
            els.saveDraftBtn.disabled = true;
            els.saveSubmitBtn.disabled = true;
            return;
        }

        els.countBadge.textContent = entries.size + ' loaded';
        els.saveDraftBtn.disabled = false;
        els.saveSubmitBtn.disabled = false;

        let html = '';
        entries.forEach((entry, ewbNo) => {
            const d = entry.data || {};
            const err = entry.error;
            const isDemo = entry.demo;
            html += `<div class="ewb-card ${err ? 'ewb-card-error' : ''}" data-ewb="${ewbNo}">
                <div class="ewb-card-head">
                    <div>
                        <span class="ewb-no-label">E-Way Bill</span>
                        <strong class="ewb-no-value">${ewbNo}</strong>
                        ${isDemo ? '<span class="ewb-badge demo">Demo</span>' : ''}
                        ${d.status ? `<span class="ewb-badge status">${esc(d.status)}</span>` : ''}
                    </div>
                    <button type="button" class="btn btn-outline-danger btn-sm remove-ewb" data-ewb="${ewbNo}">Remove</button>
                </div>`;

            if (err) {
                html += `<div class="ewb-error">${esc(err)}</div></div>`;
                return;
            }

            html += `<div class="ewb-grid">
                <div class="ewb-field"><label>Generation Date/Time</label><span>${esc(d.eway_bill_date)}</span></div>
                <div class="ewb-field"><label>Valid From</label><span>${esc(d.valid_from)}</span></div>
                <div class="ewb-field"><label>Valid Until</label><span>${esc(d.valid_upto)}</span></div>
                <div class="ewb-field"><label>Current Status</label><span>${esc(d.status)} (${esc(d.status_code || '')})</span></div>
                <div class="ewb-field"><label>Document No</label><span>${esc(d.doc_no)}</span></div>
                <div class="ewb-field"><label>Document Date</label><span>${esc(d.doc_date)}</span></div>
                <div class="ewb-field"><label>Document Type</label><span>${esc(d.doc_type)}</span></div>
                <div class="ewb-field"><label>Invoice Value</label><span>${esc(d.invoice_value)}</span></div>
                <div class="ewb-field span-2"><label>Consignor</label><span>${esc(d.consignor_name)} (${esc(d.consignor_gstin)})<br>${esc(d.consignor_address)}</span></div>
                <div class="ewb-field span-2"><label>Consignee</label><span>${esc(d.consignee_name)} (${esc(d.consignee_gstin)})<br>${esc(d.consignee_address)}</span></div>
                <div class="ewb-field span-2"><label>Goods / HSN</label><span>${esc(d.goods_details)}<br><small>HSN: ${esc(d.hsn_details)}</small></span></div>
                <div class="ewb-field"><label>Approx. Distance (km)</label><span>${esc(d.actual_distance)}</span></div>
                <div class="ewb-field"><label>Vehicle No</label><span>${esc(d.vehicle_no)}</span></div>
                <div class="ewb-field span-2"><label>Transporter</label><span>${esc(d.transporter_name)} (${esc(d.transporter_id)})</span></div>
            </div>`;

            if (d.part_a) {
                html += `<details class="ewb-details"><summary>Part-A Details</summary><pre>${esc(JSON.stringify(d.part_a, null, 2))}</pre></details>`;
            }
            if (d.part_b && d.part_b.length) {
                html += `<details class="ewb-details"><summary>Part-B / Vehicle Updates (${d.part_b.length})</summary>`;
                html += '<table class="ewb-table"><thead><tr><th>Vehicle</th><th>From</th><th>Mode</th><th>LR/Doc No</th><th>Doc Date</th></tr></thead><tbody>';
                d.part_b.forEach(v => {
                    html += `<tr><td>${esc(v.vehicle_no)}</td><td>${esc(v.from_place)}</td><td>${esc(v.trans_mode)}</td><td>${esc(v.trans_doc_no)}</td><td>${esc(v.trans_doc_date)}</td></tr>`;
                });
                html += '</tbody></table></details>';
            }

            if (d.items && d.items.length) {
                html += `<details class="ewb-details"><summary>Item List (${d.items.length})</summary>`;
                html += '<table class="ewb-table"><thead><tr><th>#</th><th>Product</th><th>HSN</th><th>Qty</th><th>Taxable</th></tr></thead><tbody>';
                d.items.forEach(it => {
                    html += `<tr><td>${esc(it.item_no)}</td><td>${esc(it.product_name)}</td><td>${esc(it.hsn_code)}</td><td>${esc(it.quantity)}</td><td>${esc(it.taxable_amount)}</td></tr>`;
                });
                html += '</tbody></table></details>';
            }

            html += `<div class="ewb-remarks"><label>Remarks</label><textarea class="form-control ewb-remarks-input" data-ewb="${ewbNo}" rows="2" placeholder="Optional remarks">${esc(entry.remarks || '')}</textarea></div>`;
            html += '</div>';
        });

        els.results.innerHTML = html;

        els.results.querySelectorAll('.remove-ewb').forEach(btn => {
            btn.addEventListener('click', () => {
                entries.delete(btn.dataset.ewb);
                renderResults();
            });
        });

        els.results.querySelectorAll('.ewb-remarks-input').forEach(ta => {
            ta.addEventListener('input', () => {
                const e = entries.get(ta.dataset.ewb);
                if (e) e.remarks = ta.value;
            });
        });
    }

    function esc(v) {
        if (v === null || v === undefined) return '';
        return String(v)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    async function fetchAll() {
        const numbers = collectInputNumbers();
        if (!numbers.length) {
            showStatus('Enter at least one E-Way Bill number.', 'error');
            return;
        }
        const invalid = numbers.filter(n => !validateEwb(n));
        if (invalid.length) {
            showStatus('Invalid E-Way Bill number(s): ' + invalid.join(', ') + ' (must be 12 digits).', 'error');
            return;
        }

        showStatus('Fetching ' + numbers.length + ' E-Way Bill(s)...', 'loading');
        els.fetchBtn.disabled = true;

        try {
            const fd = new FormData();
            fd.append('csrf_token', csrf);
            fd.append('ewb_numbers', numbers.join('\n'));
            const resp = await fetch('api/fetch_ewaybill.php', { method: 'POST', body: fd });
            const data = await resp.json();

            if (!data.success) {
                showStatus(data.message || 'Fetch failed.', 'error');
                return;
            }

            (data.results || []).forEach(r => {
                const no = r.ewb_no || (r.data && r.data.ewb_no) || '';
                if (!no) return;
                if (r.success && r.data) {
                    entries.set(no, { data: r.data, demo: !!r.demo, remarks: entries.get(no)?.remarks || '' });
                } else {
                    entries.set(no, { error: r.message || 'Fetch failed', data: null });
                }
            });

            const modeNote = data.demo_mode ? ' (Demo mode — configure API credentials for live data)' : '';
            showStatus((data.message || 'Done.') + modeNote, 'success');
            renderResults();
        } catch (e) {
            showStatus('Network error while fetching E-Way Bills.', 'error');
        } finally {
            els.fetchBtn.disabled = false;
        }
    }

    async function saveEntries(saveStatus) {
        const payload = [];
        entries.forEach((entry, ewbNo) => {
            if (!entry.data) return;
            payload.push(Object.assign({}, entry.data, {
                ewb_no: ewbNo,
                remarks: entry.remarks || '',
                demo: entry.demo ? 1 : 0,
            }));
        });

        if (!payload.length) {
            showStatus('No valid fetched entries to save.', 'error');
            return;
        }

        showStatus('Saving...', 'loading');
        try {
            const fd = new FormData();
            fd.append('csrf_token', csrf);
            fd.append('save_status', saveStatus);
            fd.append('entries', JSON.stringify(payload));
            const resp = await fetch('api/save_ewaybill_docket.php', { method: 'POST', body: fd });
            const data = await resp.json();
            showStatus(data.message || (data.success ? 'Saved.' : 'Save failed.'), data.success ? 'success' : 'error');
            if (data.success) {
                setTimeout(() => window.location.reload(), 1200);
            }
        } catch (e) {
            showStatus('Network error while saving.', 'error');
        }
    }

    function addInputRow(value) {
        const row = document.createElement('div');
        row.className = 'ewb-input-row';
        row.innerHTML = `<input type="text" class="form-control ewb-no-input" maxlength="12" placeholder="12-digit E-Way Bill No" value="${esc(value || '')}">
            <button type="button" class="btn btn-outline-secondary btn-sm remove-row">✕</button>`;
        row.querySelector('.remove-row').addEventListener('click', () => row.remove());
        els.rows.appendChild(row);
        const inp = row.querySelector('.ewb-no-input');
        inp.addEventListener('blur', () => {
            const v = inp.value.replace(/\D/g, '');
            if (v.length === 12) fetchAll();
        });
        return inp;
    }

    if (els.fetchBtn) els.fetchBtn.addEventListener('click', fetchAll);
    if (els.addBtn) els.addBtn.addEventListener('click', () => addInputRow(''));
    if (els.saveDraftBtn) els.saveDraftBtn.addEventListener('click', () => saveEntries('Draft'));
    if (els.saveSubmitBtn) els.saveSubmitBtn.addEventListener('click', () => saveEntries('Submitted'));
    if (els.clearBtn) els.clearBtn.addEventListener('click', () => {
        entries.clear();
        els.rows.innerHTML = '';
        if (els.input) els.input.value = '';
        addInputRow('');
        renderResults();
        showStatus('', '');
    });

    if (els.input) {
        els.input.addEventListener('keydown', e => {
            if (e.key === 'Enter') { e.preventDefault(); fetchAll(); }
        });
    }

    addInputRow('');
    renderResults();

    if (demoMode) {
        showStatus('Demo mode active — sample data will load until E-Way Bill API credentials are configured in includes/ewaybill_config.php', 'info');
    }
})();
