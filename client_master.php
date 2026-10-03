<?php
require_once __DIR__ . '/includes/db_connection.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/master_data.php';
startSecureSession();
requireLogin();
$conn = getDBConnection();
$master = getAllMasterData($conn);
$clients = getClientMasters($conn, false);
$csrf = generateCSRFToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars(appBrandTitle('Client Master & Contracts')) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="css/style.css?v=opsmenu1">
<style>
.lane-grid{border:1px solid #d0d7de;border-radius:6px;overflow:hidden;background:#fff;margin-top:4px;}
.lane-grid-header{display:grid;grid-template-columns:42px 1.6fr 1.6fr 1fr 1fr 1fr 46px;background:var(--primary);color:#fff;font-size:0.62rem;font-weight:600;text-transform:uppercase;letter-spacing:0.3px;}
.lane-grid-header > div{padding:5px 8px;border-right:1px solid rgba(255,255,255,.15);display:flex;align-items:center;}
.lane-grid-header > div:last-child{border-right:0;}
.lane-grid-body{overflow-x:auto;}
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
<?php renderAppHeader('Client Master & Contracts', '👥'); ?>

<div class="app-body">
  <div id="message"></div>
  <div class="client-master-layout">

    <div class="card">
      <div class="card-header">
        <div class="d-flex justify-content-between align-items-center w-100" style="gap:8px;flex-wrap:wrap">
          <span>Saved Clients & Contracts</span>
          <div class="d-flex align-items-center" style="gap:8px">
            <a href="add_client.php" class="btn btn-primary btn-xs">+ Add New Client</a>
            <input type="text" id="clientSearchInput" class="form-control" placeholder="🔍 Search code / client / division / city..." style="height:24px;font-size:0.68rem;width:320px;max-width:50vw">
            <button type="button" class="btn btn-outline-secondary btn-xs" id="clearClientSearch">Clear</button>
            <span class="result-count-badge" style="font-size:0.6rem;padding:1px 8px;margin:0" id="clientCountBadge"><?= count($clients) ?> client<?= count($clients) === 1 ? '' : 's' ?></span>
          </div>
        </div>
      </div>
      <div class="card-body" style="padding:4px">
        <div class="results-table-wrap" style="max-height:250px;border:1px solid #e5e7eb;border-radius:4px;overflow-y:auto" id="clientsTableWrap">
          <table class="results-table">
            <thead>
              <tr>
                <th>Code</th><th>Client</th><th>Division</th><th>City</th>
                <th>Created</th><th>Expires</th>
                <th class="num">Docket</th><th class="num">ODA</th><th class="num">Fuel</th>
                <th class="actions">Lanes</th><th>Status</th><th class="actions">Actions</th>
              </tr>
            </thead>
            <tbody id="clientsTbody">
<?php if (!$clients): ?>
              <tr class="empty-state"><td colspan="12" class="text-muted text-center py-4" style="font-size:0.7rem;padding:24px">No clients defined yet. Click "+ Add New Client" above to create your first client 👆.</td></tr>
<?php else: ?>
<?php foreach ($clients as $client):
    $laneCount = 0;
    $cntStmt = $conn->prepare('SELECT COUNT(*) c FROM client_lane_rates WHERE client_id = ?');
    $cntStmt->bind_param('i', $client['id']);
    $cntStmt->execute();
    $cntRes = $cntStmt->get_result()->fetch_assoc();
    $cntStmt->close();
    if ($cntRes) $laneCount = (int)$cntRes['c'];
    $created = !empty($client['created_at']) ? date('d M Y', strtotime($client['created_at'])) : '--';
    $expiry = !empty($client['expiry_date']) ? date('d M Y', strtotime($client['expiry_date'])) : '—';
    $expired = false;
    if (!empty($client['expiry_date'])) {
        try {
            $ex = new DateTime($client['expiry_date']);
            $today = new DateTime();
            $expired = $ex < $today;
        } catch (\Exception $e) {}
    }
    $searchHaystack = $client['client_code'] . ' ' . $client['client_name'] . ' ' . ($client['division'] ?? '') . ' ' . ($client['city_name'] ?? '') . ' ' . $created . ' ' . $expiry;
?>
              <tr class="client-row" id="client-row-<?= $client['id'] ?>" data-client-id="<?= $client['id'] ?>" data-status="<?= htmlspecialchars($client['status']) ?>" data-search="<?= htmlspecialchars(strtolower($searchHaystack), ENT_QUOTES) ?>">
                <td class="mono strong"><?= htmlspecialchars($client['client_code']) ?></td>
                <td><?= htmlspecialchars($client['client_name']) ?></td>
                <td><?= htmlspecialchars($client['division'] ?? '') ?></td>
                <td><?= htmlspecialchars($client['city_name'] ?? '') ?></td>
                <td style="white-space:nowrap;font-size:0.62rem;color:#475569"><?= htmlspecialchars($created) ?></td>
                <td style="white-space:nowrap;font-size:0.62rem;color:<?= $expired ? '#dc2626;font-weight:700' : '#475569' ?>"><?= htmlspecialchars($expiry) ?><?= $expired ? ' ⚠' : '' ?></td>
                <td class="num">₹<?= number_format((float)$client['docket_charge'], 2) ?></td>
                <td class="num">₹<?= number_format((float)$client['oda_charge'], 2) ?></td>
                <td class="num">₹<?= number_format((float)$client['fuel_charge'], 2) ?></td>
                <td class="actions"><span class="badge bg-info-subtle text-info-emphasis rounded-pill" style="font-size:0.6rem"><?= $laneCount ?></span></td>
                <td><?php if ($client['status'] === 'Active'): ?><span class="badge rounded-pill text-success-emphasis bg-success-subtle" style="font-size:0.6rem">Active</span><?php else: ?><span class="badge rounded-pill text-secondary-emphasis bg-secondary-subtle" style="font-size:0.6rem">Inactive</span><?php endif; ?></td>
                <td class="actions">
                  <div class="d-flex gap-1 flex-wrap justify-content-end">
                    <button type="button" class="btn btn-xs btn-outline-primary edit-client" data-client-id="<?= $client['id'] ?>">Edit</button>
<?php if ($client['status'] === 'Active'): ?>
                    <button type="button" class="btn btn-xs btn-outline-warning toggle-client-status" data-client-id="<?= $client['id'] ?>" data-target="Inactive">Deactivate</button>
<?php else: ?>
                    <button type="button" class="btn btn-xs btn-outline-success toggle-client-status" data-client-id="<?= $client['id'] ?>" data-target="Active">Activate</button>
<?php endif; ?>
                  </div>
                </td>
              </tr>
<?php endforeach; ?>
<?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>



  </div>
</div>
</div>
</div>

<script>
const message = document.getElementById('message');
const clientsTbody = document.getElementById('clientsTbody');
const clientSearchInput = document.getElementById('clientSearchInput');
const clearClientSearchBtn = document.getElementById('clearClientSearch');
const clientCountBadge = document.getElementById('clientCountBadge');
const clientsTableWrap = document.getElementById('clientsTableWrap');

function escapeHtml(s){if(s==null)return '';return String(s).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));}

function filterClients() {
    const q = (clientSearchInput.value || '').trim().toLowerCase();
    let visible = 0;
    clientsTbody.querySelectorAll('tr.client-row').forEach(tr => {
        const hay = tr.getAttribute('data-search') || '';
        const match = !q || hay.indexOf(q) !== -1;
        tr.style.display = match ? '' : 'none';
        if (match) visible++;
    });
    clientCountBadge.textContent = visible + ' client' + (visible === 1 ? '' : 's');
    const empty = clientsTbody.querySelector('tr.empty-state');
    if (empty) empty.remove();
    if (visible === 0) {
        const tr = document.createElement('tr');
        tr.className = 'empty-state';
        tr.innerHTML = '<td colspan="10" class="text-center py-3" style="font-size:0.7rem;color:#64748b;padding:20px">No contracts match your search. Try another keyword or click Clear.</td>';
        clientsTbody.appendChild(tr);
    }
}

clientSearchInput && clientSearchInput.addEventListener('input', filterClients);
clearClientSearchBtn && clearClientSearchBtn.addEventListener('click', () => {
    clientSearchInput.value = '';
    filterClients();
    clientSearchInput.focus();
});

function attachEditHandlers() {
    document.querySelectorAll('.edit-client').forEach(button => {
        if (button.dataset.editBound) return;
        button.dataset.editBound = '1';
        button.onclick = async () => {
            const cid = button.getAttribute('data-client-id');
            // Redirect to add_client.php with client_id for editing
            window.location.href = 'add_client.php?client_id=' + encodeURIComponent(cid);
        };
    });
}

function attachToggleHandlers() {
    document.querySelectorAll('.toggle-client-status').forEach(btn => {
        if (btn.dataset.toggleBound) return;
        btn.dataset.toggleBound = '1';
        btn.onclick = async () => {
            const cid = btn.getAttribute('data-client-id');
            const target = btn.getAttribute('data-target');
            if (!cid || !target) return;
            if (!confirm('Set this contract status to ' + target + '?')) return;
            const fd = new FormData();
            fd.append('csrf_token', document.querySelector('input[name="csrf_token"]').value);
            fd.append('client_id', cid);
            fd.append('status', target);
            const resp = await fetch('api/toggle_client_status.php', { method: 'POST', body: fd });
            const d = await resp.json();
            message.innerHTML = '<div class="alert alert-' + (d.success ? 'success' : 'danger') + ' py-2 px-3" style="font-size:0.72rem;margin:4px 0">' + d.message + '</div>';
            if (d.success) {
                const currentRow = document.getElementById('client-row-' + cid);
                if (currentRow) {
                    currentRow.setAttribute('data-status', d.status);
                    const statusCol = currentRow.querySelector('td:nth-child(11)');
                    const actionsCol = currentRow.querySelector('td:nth-child(12) .toggle-client-status');
                    if (statusCol) {
                        statusCol.innerHTML = d.status === 'Inactive'
                            ? '<span class="badge rounded-pill text-secondary-emphasis bg-secondary-subtle" style="font-size:0.6rem">Inactive</span>'
                            : '<span class="badge rounded-pill text-success-emphasis bg-success-subtle" style="font-size:0.6rem">Active</span>';
                    }
                    if (actionsCol) {
                        const next = d.status === 'Inactive' ? 'Active' : 'Inactive';
                        actionsCol.setAttribute('data-target', next);
                        actionsCol.textContent = d.status === 'Inactive' ? 'Activate' : 'Deactivate';
                        actionsCol.classList.remove('btn-outline-success', 'btn-outline-warning');
                        actionsCol.classList.add(d.status === 'Inactive' ? 'btn-outline-success' : 'btn-outline-warning');
                    }
                    currentRow.style.background = '#fff7d6';
                    clientsTableWrap.scrollTo({ top: Math.max(0, currentRow.offsetTop - clientsTableWrap.clientHeight/2), behavior: 'smooth' });
                    setTimeout(() => currentRow.style.removeProperty('background'), 2500);
                }
            }
        };
    });
}

attachEditHandlers();
attachToggleHandlers();
</script>
</body>
</html>
