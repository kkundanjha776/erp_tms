<?php
require_once __DIR__ . '/includes/db_connection.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/master_data.php';
require_once __DIR__ . '/includes/city_search_widget.php';
startSecureSession();
requireLogin();
$conn = getDBConnection();
$master = getAllMasterData($conn);
$csrf = generateCSRFToken();
$nextClientCode = getNextClientCode($conn);
$editClientId = (int) ($_GET['client_id'] ?? 0);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars(appBrandTitle('Add New Client Contract')) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="css/style.css?v=opsmenu1">
<style>
.lane-grid{border:1px solid #d0d7de;border-radius:6px;overflow:visible;background:#fff;margin-top:4px;}
.lane-grid-header{display:grid;grid-template-columns:42px 1.6fr 1.6fr 1fr 1fr 1fr 46px;background:var(--primary);color:#fff;font-size:0.62rem;font-weight:600;text-transform:uppercase;letter-spacing:0.3px;}
.lane-grid-header > div{padding:5px 8px;border-right:1px solid rgba(255,255,255,.15);display:flex;align-items:center;}
.lane-grid-header > div:last-child{border-right:0;}
.lane-grid-body{overflow:auto;max-height:520px;min-height:320px;padding-bottom:6px;}
.lane-row-grid{display:grid;grid-template-columns:42px 1.6fr 1.6fr 1fr 1fr 1fr 46px;align-items:start;border-bottom:1px solid #e5e7eb;}
.lane-row-grid:last-child{border-bottom:0;}
.lane-row-grid > div{padding:4px 6px;border-right:1px solid #e5e7eb;}
.lane-row-grid > div:last-child{border-right:0;}
.lane-row-grid:hover{background:#f8fbff;}
.lane-row-grid .form-label{display:none;}
.lane-sr{background:#f1f5f9;font-weight:700;color:#475569;display:flex;align-items:center;justify-content:center;height:22px;border-radius:3px;font-size:0.7rem;}
.lane-actions{display:flex;align-items:center;justify-content:center;height:100%;min-height:22px;}
.lane-remove{width:24px;height:22px;border-radius:3px;border:1px solid #fecaca;background:#fef2f2;color:#dc2626;font-weight:700;font-size:0.9rem;line-height:1;cursor:pointer;transition:all .15s;padding:0;}
.lane-remove:hover{background:#fee2e2;border-color:#fca5a5;}
.client-code-wrap{position:relative;}
.client-code-wrap input{background:#f1f5f9 !important;font-weight:700;color:#0f172a;}
.code-badge{position:absolute;right:6px;top:50%;transform:translateY(-50%);font-size:0.55rem;padding:1px 6px;background:#dbeafe;color:#1d4ed8;border-radius:10px;font-weight:700;letter-spacing:0.3px;}
.code-badge.locked{background:#fef3c7;color:#92400e;}
.saved-lanes-badge{display:inline-block;margin-left:6px;font-size:0.6rem;padding:1px 8px;background:#e0f2fe;color:#0369a1;border-radius:10px;font-weight:700;vertical-align:middle;}
.client-master-layout{display:flex;flex-direction:column;gap:4px;height:100%;overflow:hidden;}
.client-master-layout .card{border:1px solid var(--border);border-radius:6px;background:#fff;overflow:hidden;display:flex;flex-direction:column;}
.client-master-layout .card-header{background:#f8fafc;padding:4px 8px;border-bottom:1px solid #e5e7eb;font-size:0.72rem;font-weight:700;color:var(--primary);display:flex;align-items:center;justify-content:space-between;flex-shrink:0;}
.client-master-layout .card-body{padding:6px 8px;overflow-y:auto;flex:1;min-height:0;}
.action-bar{display:flex;align-items:center;gap:6px;flex-wrap:wrap;}
</style>
</head>
<body>
<div class="app-shell">
<aside class="app-sidebar">
    <?php renderAppBrand(); ?>
    <nav class="sidebar-nav">
        <?php renderSidebarNav(); ?>
    </nav>
    <?php renderSidebarFooter(); ?>
</aside>

<div class="app-main">
<?php renderAppHeader('Add New Client Contract', '👥'); ?>

<div class="app-body">
  <div id="message"></div>
  <div class="client-master-layout">

    <div class="card">
      <div class="card-header">
        <div class="d-flex justify-content-between align-items-center w-100" style="gap:8px;flex-wrap:wrap">
          <span>Add New Client Contract</span>
          <a href="client_master.php" class="btn btn-outline-secondary btn-xs">← Back to List</a>
        </div>
      </div>
      <div class="card-body">
    <form id="clientForm" novalidate>
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
      <input type="hidden" name="id" id="client_id">

      <div class="form-section">
        <div class="section-title">Client Details ▾</div>
        <div class="section-content">
          <div class="form-row">
            <div class="form-group">
              <label>Client Code <span class="req">*</span></label>
              <div class="client-code-wrap">
                <input required readonly name="client_code" id="client_code" class="form-control" value="<?= htmlspecialchars($nextClientCode) ?>">
                <span class="code-badge" id="codeModeBadge">AUTO</span>
              </div>
            </div>
            <div class="form-group">
              <label>Client Name <span class="req">*</span></label>
              <input required name="client_name" id="client_name" class="form-control">
            </div>
          </div>
          <div class="form-row">
            <div class="form-group"><label>Division</label><input name="division" id="division" class="form-control"></div>
            <div class="form-group"><label>Address</label><input name="address" id="address" class="form-control"></div>
          </div>
          <div class="form-row compact-inline">
            <div class="form-group">
              <label>State</label>
              <select name="state_id" id="state_id" class="form-select">
                <option value="">-- State --</option>
                <?php foreach ($master['states'] as $state): ?>
                <option value="<?= $state['id'] ?>"><?= htmlspecialchars($state['state_name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-group">
              <?php renderCitySearchField([
                  'field_id' => 'city_id',
                  'label' => 'City',
                  'state_field_id' => 'state_id',
                  'pin_field_id' => 'pin',
              ]); ?>
            </div>
            <div class="form-group"><label>PIN</label><input name="pin" id="pin" maxlength="6" class="form-control"></div>
          </div>
          <div class="form-row compact-inline">
            <div class="form-group"><label>GST</label><input name="gst_no" id="gst_no" maxlength="15" class="form-control"></div>
            <div class="form-group"><label>PAN</label><input name="pan_no" id="pan_no" maxlength="10" class="form-control"></div>
            <div class="form-group"><label>Phone</label><input name="phone" id="phone" class="form-control"></div>
          </div>
          <div class="form-row compact-inline">
            <div class="form-group"><label>Email</label><input type="email" name="email" id="email" class="form-control"></div>
            <div class="form-group">
              <label>Status</label>
              <select name="status" id="status" class="form-select">
                <option>Active</option><option>Inactive</option>
              </select>
            </div>
            <div class="form-group">
              <label>Contract Expiry Date</label>
              <input type="date" name="expiry_date" id="expiry_date" class="form-control">
            </div>
          </div>
          <div class="form-row compact-inline">
            <div class="form-group">
              <label>Created On</label>
              <input name="created_at" id="created_at" class="form-control" readonly style="background:#f1f5f9;color:#64748b;font-size:0.62rem">
            </div>
            <div class="form-group">
              <label>Last Updated</label>
              <input name="updated_at" id="updated_at" class="form-control" readonly style="background:#f1f5f9;color:#64748b;font-size:0.62rem">
            </div>
            <div class="form-group"><label>&nbsp;</label><span class="text-muted small">Codes auto-generate; edit is blocked after save.</span></div>
          </div>
        </div>
      </div>

      <div class="form-section">
        <div class="section-title d-flex justify-content-between align-items-center">
          <span>Contract Charges (Client Defaults) ▾</span>
        </div>
        <div class="section-content">
          <div class="form-row compact-inline">
            <div class="form-group"><label>Docket Charge (₹)</label><input type="number" name="docket_charge" id="docket_charge" value="0" step="0.01" min="0" class="form-control"></div>
            <div class="form-group"><label>ODA Charge (₹)</label><input type="number" name="oda_charge" id="oda_charge" value="0" step="0.01" min="0" class="form-control"></div>
            <div class="form-group"><label>Fuel Surcharge (₹)</label><input type="number" name="fuel_charge" id="fuel_charge" value="0" step="0.01" min="0" class="form-control"></div>
          </div>
          <div class="form-row compact-inline">
            <div class="form-group"><label>Risk Charge (%)</label><input type="number" name="risk_charge_percent" id="risk_charge_percent" value="0" step="0.01" min="0" class="form-control"></div>
            <div class="form-group"><label>Risk Min. Charge (₹)</label><input type="number" name="risk_minimum_charge" id="risk_minimum_charge" value="0" step="0.01" min="0" class="form-control"></div>
            <div class="form-group"><label>&nbsp;</label><span class="text-muted small">Applies to every lane below.</span></div>
          </div>
        </div>
      </div>

      <div class="form-section">
        <div class="section-title d-flex justify-content-between align-items-center">
          <span>Contracted Lane Rates <span class="saved-lanes-badge" id="laneCountBadge">1 Lane</span></span>
        </div>
        <div class="section-content">
          <p class="small text-muted mb-2" style="margin:0 0 4px;font-size:0.62rem">Add origin–destination lanes. Use Add Lane buttons below the grid or at the bottom with Save.</p>
          <div class="lane-grid">
            <div class="lane-grid-header">
              <div>#</div><div>Origin City</div><div>Destination City</div>
              <div>Rate / KG</div><div>Rate / Piece</div><div>Rate / KM</div><div></div>
            </div>
            <div class="lane-grid-body" id="laneRows">
              <div class="lane-row-grid" data-index="1">
                <div><div class="lane-sr">1</div></div>
                <div>
                  <label class="form-label">Origin</label>
                  <?php renderCitySearchField([
                      'field_id' => 'origin_city_id_1',
                      'name' => 'origin_city_id[]',
                      'label' => 'Origin City',
                      'placeholder' => 'Search origin city / PIN',
                      'hide_label' => true,
                  ]); ?>
                </div>
                <div>
                  <label class="form-label">Destination</label>
                  <?php renderCitySearchField([
                      'field_id' => 'destination_city_id_1',
                      'name' => 'destination_city_id[]',
                      'label' => 'Destination City',
                      'placeholder' => 'Search destination city / PIN',
                      'hide_label' => true,
                  ]); ?>
                </div>
                <div><label class="form-label">Rate / KG</label><input type="number" name="rate_per_kg[]" value="0" step="0.01" min="0" class="form-control"></div>
                <div><label class="form-label">Rate / Piece</label><input type="number" name="rate_per_piece[]" value="0" step="0.01" min="0" class="form-control"></div>
                <div><label class="form-label">Rate / KM</label><input type="number" name="rate_per_km[]" value="0" step="0.01" min="0" class="form-control"></div>
                <div class="lane-actions"><button type="button" class="lane-remove remove-lane" title="Remove lane">×</button></div>
              </div>
            </div>
          </div>
          <div class="mt-2 d-flex justify-content-between align-items-center">
            <div class="text-muted" style="font-size:0.62rem">Scroll the page to see more rows. Grid expands naturally.</div>
            <button class="btn btn-outline-primary btn-sm" type="button" id="addLaneInline"><span style="font-weight:700">+</span> Add Lane</button>
          </div>
        </div>
      </div>

      <div class="action-bar" style="margin-top:4px;padding:6px 8px;border:1px solid #e5e7eb;border-radius:6px;background:#f8fafc">
        <button class="btn btn-primary btn-sm" type="submit">💾 Save Client Contract</button>
        <button class="btn btn-outline-primary btn-sm" type="button" id="addLane"><span style="font-weight:700">+</span> Add Lane</button>
        <a href="client_master.php" class="btn btn-outline-secondary btn-sm">Cancel</a>
        <div class="ms-auto text-muted" style="font-size:0.62rem" id="formStatus">Ready. New contract code: <b id="codeDisplay"><?= htmlspecialchars($nextClientCode) ?></b></div>
      </div>
    </form>
      </div>
    </div>

  </div>
</div>
</div>
</div>

<script type="application/json" id="citiesData"><?= json_encode($master['cities']) ?></script>
<script type="application/json" id="statesData"><?= json_encode($master['states']) ?></script>
<script src="js/city-search.js?v=opsmenu1"></script>
<script>
const defaultClientCode = <?= json_encode($nextClientCode) ?>;
const editClientId = <?= $editClientId ?>;
const form = document.getElementById('clientForm');
const message = document.getElementById('message');
const laneRows = document.getElementById('laneRows');
const laneCountBadge = document.getElementById('laneCountBadge');
const codeBadge = document.getElementById('codeModeBadge');
const codeDisplay = document.getElementById('codeDisplay');
const formStatus = document.getElementById('formStatus');
let laneRowSeq = laneRows.querySelectorAll('.lane-row-grid').length;

function buildCitySearchWrap(fieldId, fieldName, selectedId, placeholder) {
    const selected = selectedId ? String(selectedId) : '';
    const ph = placeholder ? String(placeholder) : 'Search PIN, area, or district';
    return (
        '<div class="form-group city-search-wrap" data-city-field="' + escapeHtml(fieldId) + '">' +
        '<input type="text" class="form-control city-search-input" value="" placeholder="' + escapeHtml(ph) + '" autocomplete="off">' +
        '<input type="hidden" id="' + escapeHtml(fieldId) + '" name="' + escapeHtml(fieldName) + '" value="' + escapeHtml(selected) + '">' +
        '<div class="city-search-dropdown"></div>' +
        '<div class="invalid-feedback"></div>' +
        '</div>'
    );
}
function escapeHtml(s){if(s==null)return '';return String(s).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));}

function updateLaneCount() {
    const count = laneRows.querySelectorAll('.lane-row-grid').length;
    laneCountBadge.textContent = count + ' Lane' + (count === 1 ? '' : 's');
    let i = 1;
    laneRows.querySelectorAll('.lane-sr').forEach(el => { el.textContent = i++; });
}

function addLaneRow(prefill, scroll) {
    const count = laneRows.querySelectorAll('.lane-row-grid').length + 1;
    const seq = ++laneRowSeq;
    const origin = prefill && prefill.origin_city_id ? prefill.origin_city_id : '';
    const dest = prefill && prefill.destination_city_id ? prefill.destination_city_id : '';
    const kg = prefill && prefill.rate_per_kg != null ? Number(prefill.rate_per_kg).toFixed(2) : '0.00';
    const pc = prefill && prefill.rate_per_piece != null ? Number(prefill.rate_per_piece).toFixed(2) : '0.00';
    const km = prefill && prefill.rate_per_km != null ? Number(prefill.rate_per_km).toFixed(2) : '0.00';
    const originId = 'origin_city_id_' + seq;
    const destId = 'destination_city_id_' + seq;
    const wrap = document.createElement('div');
    wrap.className = 'lane-row-grid';
    wrap.dataset.index = count;
    wrap.innerHTML =
        '<div><div class="lane-sr">' + count + '</div></div>' +
        '<div><label class="form-label">Origin</label>' + buildCitySearchWrap(originId, 'origin_city_id[]', origin, 'Search origin city / PIN') + '</div>' +
        '<div><label class="form-label">Destination</label>' + buildCitySearchWrap(destId, 'destination_city_id[]', dest, 'Search destination city / PIN') + '</div>' +
        '<div><label class="form-label">Rate / KG</label><input type="number" name="rate_per_kg[]" value="' + kg + '" step="0.01" min="0" class="form-control"></div>' +
        '<div><label class="form-label">Rate / Piece</label><input type="number" name="rate_per_piece[]" value="' + pc + '" step="0.01" min="0" class="form-control"></div>' +
        '<div><label class="form-label">Rate / KM</label><input type="number" name="rate_per_km[]" value="' + km + '" step="0.01" min="0" class="form-control"></div>' +
        '<div class="lane-actions"><button type="button" class="lane-remove remove-lane" title="Remove lane">×</button></div>';
    laneRows.appendChild(wrap);
    if (window.CitySearch && typeof window.CitySearch.initAll === 'function') {
        window.CitySearch.initAll(wrap);
    }
    updateLaneCount();
    if (scroll) {
        setTimeout(() => wrap.scrollIntoView({ behavior: 'smooth', block: 'center' }), 20);
        const firstInput = wrap.querySelector('.city-search-input');
        if (firstInput) setTimeout(() => firstInput.focus(), 100);
    }
}

function clearAllLanes(){ laneRows.innerHTML = ''; laneRowSeq = 0; }

function setCodeMode(isEdit, code) {
    const codeInput = document.getElementById('client_code');
    const val = code || defaultClientCode;
    codeInput.value = val;
    codeInput.readOnly = true;
    codeBadge.textContent = isEdit ? 'LOCKED' : 'AUTO';
    codeBadge.classList.toggle('locked', !!isEdit);
    codeDisplay.textContent = val;
    formStatus.textContent = isEdit ? ('Editing existing client: ' + val) : ('Ready. New contract code: ' + val);
}

function clearForm() {
    form.reset();
    document.getElementById('client_id').value = '';
    ['created_at', 'updated_at', 'expiry_date'].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.value = '';
    });
    clearAllLanes();
    addLaneRow();
    setCodeMode(false);
    message.innerHTML = '';
    const fn = document.getElementById('client_name');
    if (fn) fn.focus();
}

document.getElementById('addLane').onclick = () => addLaneRow(null, true);
if (document.getElementById('addLaneInline')) document.getElementById('addLaneInline').onclick = () => addLaneRow(null, true);

laneRows.addEventListener('click', e => {
    if (e.target.classList.contains('remove-lane')) {
        const total = laneRows.querySelectorAll('.lane-row-grid').length;
        if (total > 1) {
            e.target.closest('.lane-row-grid').remove();
            updateLaneCount();
        }
    }
});

form.onsubmit = async e => {
    e.preventDefault();
    message.innerHTML = '<div class="alert alert-info py-2 px-3" style="font-size:0.72rem;margin:4px 0">Saving...</div>';
    try {
        const fd = new FormData(form);
        const resp = await fetch('api/save_client_contract.php', { method: 'POST', body: fd });
        const d = await resp.json();
        message.innerHTML = '<div class="alert alert-' + (d.success ? 'success' : 'danger') + ' py-2 px-3" style="font-size:0.72rem;margin:4px 0">' + escapeHtml(d.message || 'Unable to save client contract') + '</div>';
        if (d.success) setTimeout(() => { window.location.href = 'client_master.php'; }, 900);
    } catch (error) {
        message.innerHTML = '<div class="alert alert-danger py-2 px-3" style="font-size:0.72rem;margin:4px 0">Unable to save client contract. Please try again.</div>';
    }
};

async function loadClientForEdit(clientId) {
    message.innerHTML = '<div class="alert alert-info py-2 px-3" style="font-size:0.72rem;margin:4px 0">Loading client contract...</div>';
    try {
        const response = await fetch('api/get_client_for_edit.php?client_id=' + encodeURIComponent(clientId));
        const data = await response.json();
        if (!data.success || !data.client) throw new Error(data.message || 'Client not found');

        const client = data.client;
        Object.keys(client).forEach(key => {
            const field = document.getElementById(key);
            if (field) field.value = client[key] ?? '';
        });
        document.getElementById('client_id').value = client.id;
        clearAllLanes();
        (data.lanes || []).forEach(lane => addLaneRow(lane));
        if (!data.lanes || !data.lanes.length) addLaneRow();
        updateLaneCount();
        setCodeMode(true, client.client_code);
        message.innerHTML = '';
    } catch (error) {
        clearForm();
        message.innerHTML = '<div class="alert alert-danger py-2 px-3" style="font-size:0.72rem;margin:4px 0">' + escapeHtml(error.message || 'Unable to load client contract') + '</div>';
    }
}

if (editClientId > 0) loadClientForEdit(editClientId);
else { updateLaneCount(); setCodeMode(false); }
</script>
</body>
</html>
