<?php
require_once __DIR__ . '/includes/db_connection.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/master_data.php';
startSecureSession(); requireLogin(); requireModule('billing');
$conn = getDBConnection(); ensureBillingSchema($conn);
$csrf = generateCSRFToken();
$from = trim($_GET['from'] ?? date('Y-m-01'));
$to = trim($_GET['to'] ?? date('Y-m-d'));
$party = trim($_GET['party'] ?? '');
$podOnly = ($_GET['pod_only'] ?? '') === '1';
$hasSearch = isset($_GET['search']);
$pastedDockets = [];
if (!empty($_GET['pasted_dockets'])) {
    $pastedDockets = array_map('trim', explode(',', $_GET['pasted_dockets']));
    $hasSearch = true;
}
$where = ["c.basic_freight > 0", "ii.id IS NULL"];
$types = ''; $params = [];
if ($from !== '') { $where[]='c.booking_date >= ?'; $types.='s'; $params[]=$from; }
if ($to !== '') { $where[]='c.booking_date <= ?'; $types.='s'; $params[]=$to; }
if ($party !== '') {
    // New dockets use client_master_id; older dockets may only have the saved party name.
    // Search both so Client Master filtering works across existing data too.
    // Also search by client code for better search experience
    $where[]='(c.billing_party_name = ? OR c.client_master_id IN (SELECT id FROM client_masters WHERE client_name = ?) OR c.client_master_id IN (SELECT id FROM client_masters WHERE client_code = ?))';
    $types.='sss'; $params[]=$party; $params[]=$party; $params[]=$party;
}
if ($podOnly) $where[]='EXISTS (SELECT 1 FROM pod_files pf WHERE pf.consignment_id=c.id)';

// Filter by pasted docket numbers if provided
if (!empty($pastedDockets)) {
    $docketConditions = [];
    foreach($pastedDockets as $pd) {
        $docketConditions[] = 'UPPER(c.consignment_note) LIKE ?';
        $types .= 's';
        $params[] = '%' . strtoupper($pd) . '%';
    }
    if (!empty($docketConditions)) {
        $where[] = '(' . implode(' OR ', $docketConditions) . ')';
    }
}
$dockets=[];
if ($hasSearch) { 
    $sql = "SELECT c.id,c.consignment_note,c.booking_date,c.billing_party_name,c.billing_gst_no,c.consignee_name,c.basic_freight,c.grand_total, 
            EXISTS(SELECT 1 FROM pod_files p WHERE p.consignment_id=c.id) pod_ready,
            cm.client_code, cm.client_name
            FROM consignments c 
            LEFT JOIN invoice_items ii ON ii.consignment_id=c.id
            LEFT JOIN client_masters cm ON c.client_master_id = cm.id
            WHERE ".implode(' AND ',$where)." 
            ORDER BY c.booking_date DESC,c.id DESC LIMIT 500"; 
    $stmt=$conn->prepare($sql); 
    if($params) $stmt->bind_param($types,...$params); 
    $stmt->execute(); 
    $dockets=$stmt->get_result()->fetch_all(MYSQLI_ASSOC); 
    $stmt->close(); 
}
$activeCompany=getActiveCompanyProfile($conn); $activeCompanyId=(int)($activeCompany['id']??0);
$companies=[]; $companyWhere="c.status='Active' AND g.status='Active'" . ($activeCompanyId ? " AND c.id=".$activeCompanyId : ''); $q=$conn->query("SELECT g.id,g.company_id,g.gstin,g.state_code,g.registration_address,g.is_default,COALESCE(NULLIF(c.trade_name,''),c.legal_name) company_name,c.address,c.phone,c.email FROM company_gst_registrations g JOIN companies c ON c.id=g.company_id WHERE $companyWhere ORDER BY g.is_default DESC,g.state_code"); if($q)$companies=$q->fetch_all(MYSQLI_ASSOC);
$parties=getClientMasters($conn);
// Billing selection intentionally uses Client Master only; legacy party records are not used here.
foreach ($parties as &$client) { $client['party_name'] = $client['client_name']; }
unset($client);
$invoiceTerms = getInvoiceTerms($conn);
$defaultTerms = getDefaultInvoiceTerms($conn);
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?= htmlspecialchars(appBrandTitle('Billing')) ?></title><link rel="stylesheet" href="css/style.css"><style>
.bill-wrap{max-width:1500px;margin:18px auto;padding:0 18px}.bill-hero{background:linear-gradient(120deg,#083b5c,#0f766e);border-radius:18px;padding:22px 26px;color:#fff;display:flex;justify-content:space-between;align-items:center;box-shadow:0 14px 30px #0f4c5c2e}.bill-hero h2{margin:0;font-size:1.45rem}.bill-hero p{margin:6px 0 0;color:#c9f5ee}.bill-kpi{display:flex;gap:11px}.bill-kpi div{background:#ffffff19;border:1px solid #ffffff35;padding:8px 13px;border-radius:10px;text-align:center}.bill-kpi b{font-size:1.1rem;display:block}.bill-card{margin-top:16px;background:#fff;border:1px solid #dbe5ea;border-radius:15px;box-shadow:0 4px 16px #12334a0a;padding:18px}.bill-top{display:grid;grid-template-columns:1.2fr 1.2fr 1fr 1fr;gap:14px}.bill-top label,.filter-grid label{font-size:.71rem;font-weight:800;color:#4b6475;text-transform:uppercase;letter-spacing:.04em;margin-bottom:5px;display:block}.gst-choice{display:flex;gap:7px}.gst-choice button{border:1px solid #cbdbe2;background:#f8fbfc;border-radius:8px;padding:7px 10px;font-weight:700;font-size:.78rem}.gst-choice button.active{background:#0f766e;color:#fff;border-color:#0f766e}.filter-grid{display:grid;grid-template-columns:1fr 1fr 1.25fr auto;gap:10px;align-items:end}.paste-box{background:#f0faf8;border:1px dashed #52a797;border-radius:11px;padding:11px;margin:15px 0;display:flex;gap:10px;align-items:center}.paste-box textarea{height:44px;resize:vertical;flex:1;font-size:.78rem}.billing-table{width:100%;border-collapse:separate;border-spacing:0 7px}.billing-table th{color:#64748b;font-size:.7rem;text-transform:uppercase;padding:0 9px 5px}.billing-table td{background:#fff;border-top:1px solid #e3eaee;border-bottom:1px solid #e3eaee;padding:10px 9px;font-size:.82rem}.billing-table td:first-child{border-left:1px solid #e3eaee;border-radius:9px 0 0 9px}.billing-table td:last-child{border-right:1px solid #e3eaee;border-radius:0 9px 9px 0}.freight{font-weight:800;color:#0f766e}.pod-pill{font-size:.68rem;border-radius:50px;padding:3px 7px;background:#e9f9ee;color:#16713b}.summary{position:sticky;bottom:10px;background:#073b5c;color:#fff;border-radius:14px;padding:13px 17px;display:flex;align-items:center;justify-content:space-between;box-shadow:0 8px 24px #062b4499}.sum-items{display:flex;gap:24px}.sum-items small{display:block;color:#b6d6e5}.sum-items b{font-size:1.08rem}.row-edit{font-size:.73rem}.notice{display:none;margin:10px 0;padding:10px;border-radius:8px}.notice.ok{background:#e9f8ee;color:#166534}.notice.err{background:#fff0f0;color:#b42318}.docket-modal{display:none;position:fixed;z-index:1000;inset:0;background:#062b44b8;padding:3vh 3vw}.docket-modal.open{display:block}.modal-box{height:94vh;background:#fff;border-radius:15px;overflow:hidden;box-shadow:0 18px 55px #00111d}.modal-top{height:48px;padding:9px 13px;background:#073b5c;color:#fff;display:flex;justify-content:space-between;align-items:center}.modal-box iframe{width:100%;height:calc(100% - 48px);border:0}@media(max-width:900px){.bill-top,.filter-grid{grid-template-columns:1fr 1fr}.bill-hero{display:block}.bill-kpi{margin-top:15px}.billing-table{display:block;overflow:auto}.summary{position:static;gap:12px}.sum-items{gap:10px;flex-wrap:wrap}}
</style></head><body><div class="app-shell"><aside class="app-sidebar"><?php renderAppBrand(); ?><nav class="sidebar-nav"><?php renderSidebarNav(); ?></nav><?php renderSidebarFooter(); ?></aside><div class="app-main"><?php renderAppHeader('Smart Billing', '🧾'); ?><main class="app-body"><div class="bill-wrap">
<section class="bill-hero"><div><h2>Invoice desk, made for fast dispatch billing</h2><p>Select company GST, choose dockets, review totals, then create one clean invoice.</p></div><div class="bill-kpi"><div><b id="heroCount">0</b><small>Dockets selected</small></div><div><b id="heroAmount">₹0.00</b><small>Freight</small></div></div></section>
<form class="bill-card filter-grid" method="get"><div><label>Booking from</label><input class="form-control" type="date" name="from" value="<?=htmlspecialchars($from)?>"></div><div><label>Booking to</label><input class="form-control" type="date" name="to" value="<?=htmlspecialchars($to)?>"></div><div><label>Client / billing party</label><select class="form-select" id="searchParty" name="party"><option value="">All Clients</option><?php foreach($parties as $p):?><option value="<?=htmlspecialchars($p['client_name'])?>" data-gst="<?=htmlspecialchars($p['gst_no']??'')?>" data-code="<?=htmlspecialchars($p['client_code'])?>" <?=($party === $p['client_name'] || $party === $p['client_code'])?'selected':''?>><?=htmlspecialchars($p['client_name'].' · '.$p['client_code'])?></option><?php endforeach;?></select></div><div><label>&nbsp;</label><label style="text-transform:none"><input type="checkbox" name="pod_only" value="1" <?=$podOnly?'checked':''?>> POD uploaded only</label><button class="btn btn-primary btn-sm" name="search" value="1" style="margin-left:8px">Search dockets</button></div></form>
<section class="bill-card"><div class="bill-top"><div><label>Owner company GST <span style="color:#e11d48">*</span></label><select class="form-select" id="companyGst"><option value="">Select GST registration</option><?php foreach($companies as $c):?><option value="<?=htmlspecialchars($c['gstin'])?>" data-company="<?=$c['company_id']?>" data-reg="<?=$c['id']?>" data-state="<?=htmlspecialchars($c['state_code'])?>"><?=htmlspecialchars($c['company_name'].' · '.$c['gstin'].' ('.$c['state_code'].')')?></option><?php endforeach;?></select></div><div><label>Client / billing party <span style="color:#e11d48">*</span></label><select class="form-select" id="invoiceParty"><option value="">Select billing party</option><?php foreach($parties as $p):?><option value="<?=htmlspecialchars($p['party_name'])?>" data-gst="<?=htmlspecialchars($p['gst_no']??'')?>" data-state="<?=$p['state_id']?>" <?=$party===$p['party_name']?'selected':''?>><?=htmlspecialchars($p['party_name'])?></option><?php endforeach;?></select></div><div><label>Billing party GST</label><input id="partyGst" class="form-control" maxlength="15" placeholder="GSTIN (editable)"></div><div><label>Invoice date</label><input id="invoiceDate" type="date" class="form-control" value="<?=date('Y-m-d')?>"></div><div><label>GST treatment</label><div class="gst-choice"><button type="button" class="active" data-tax="CGST_SGST">CGST + SGST</button><button type="button" data-tax="IGST">IGST</button></div></div><div><label>GST rate %</label><input id="gstRate" type="number" min="0" max="100" step="0.01" class="form-control" value="18"></div><div><label>Invoice number</label><input id="invoiceNo" class="form-control" placeholder="Auto-generated"></div><div><label>Remarks</label><input id="remarks" class="form-control" placeholder="Optional note"></div></div>
<div class="mt-3" style="border-top:1px solid #e5e7eb;padding-top:15px">
<div class="d-flex justify-content-between align-items-center mb-2"><b style="font-size:.8rem">Print Format & Export Options</b></div>
<div class="row">
<div class="col-md-4"><label>Invoice Format</label><select class="form-select" id="printFormat"><option value="standard">Standard A4 Invoice</option><option value="detailed">Detailed with All Charges</option><option value="simple">Simple Commercial</option></select></div>
<div class="col-md-4"><label>Terms & Conditions</label><select class="form-select" id="termsSelect"><option value="">-- Select Terms --</option><?php foreach($invoiceTerms as $t):?><option value="<?=$t['id']?>" <?=($defaultTerms && $defaultTerms['id']==$t['id'])?'selected':''?>><?=htmlspecialchars($t['term_name'])?></option><?php endforeach;?></select></div>
<div class="col-md-4"><label>Export Options</label><div class="d-flex gap-2"><button type="button" class="btn btn-outline-success btn-sm" id="exportExcel">📊 Excel Export</button><button type="button" class="btn btn-outline-primary btn-sm" id="printPreview">🖨️ Print Preview</button></div></div>
</div>
<div class="mt-2" style="background:#f8fafc;border:1px solid #e5e7eb;border-radius:8px;padding:12px">
<label style="font-size:.75rem;font-weight:700;color:#475569;display:block;margin-bottom:8px">Include in Invoice (Charge Selection)</label>
<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:8px">
<label style="font-size:.75rem"><input type="checkbox" class="charge-select" data-charge="basic_freight" checked> Basic Freight</label>
<label style="font-size:.75rem"><input type="checkbox" class="charge-select" data-charge="fuel_charge" checked> Fuel Charge</label>
<label style="font-size:.75rem"><input type="checkbox" class="charge-select" data-charge="dkt_charge" checked> Docket Charge</label>
<label style="font-size:.75rem"><input type="checkbox" class="charge-select" data-charge="handling_charge" checked> Handling Charge</label>
<label style="font-size:.75rem"><input type="checkbox" class="charge-select" data-charge="oda_charge" checked> ODA Charge</label>
<label style="font-size:.75rem"><input type="checkbox" class="charge-select" data-charge="detention" checked> Detention</label>
<label style="font-size:.75rem"><input type="checkbox" class="charge-select" data-charge="misc_charge" checked> Misc Charge</label>
<label style="font-size:.75rem"><input type="checkbox" class="charge-select" data-charge="other_charge" checked> Other Charge</label>
<label style="font-size:.75rem"><input type="checkbox" class="charge-select" data-charge="risk_charge" checked> Risk Charge</label>
<label style="font-size:.75rem"><input type="checkbox" class="charge-select" data-charge="cgst" checked> CGST</label>
<label style="font-size:.75rem"><input type="checkbox" class="charge-select" data-charge="sgst" checked> SGST</label>
<label style="font-size:.75rem"><input type="checkbox" class="charge-select" data-charge="igst" checked> IGST</label>
</div>
</div>
</div>
<div class="paste-box"><span style="font-size:1.3rem">📋</span><div><b style="font-size:.82rem">Paste docket numbers from Excel</b><br><small>Paste comma, space, or new-line separated docket numbers — only matching dockets will be selected.</small></div><textarea id="pasteDockets" class="form-control" placeholder="DKT-1001&#10;DKT-1002"></textarea><button class="btn btn-outline-primary btn-sm" type="button" id="applyPaste">Select pasted</button></div><div id="notice" class="notice"></div>
<div style="display:flex;justify-content:space-between;align-items:center"><h3 style="font-size:1rem;margin:0">Eligible dockets <small style="font-weight:400;color:#64748b">(zero freight and already billed dockets are excluded)</small></h3><label><input type="checkbox" id="selectAll"> Select all shown</label></div>
<table class="billing-table"><thead><tr><th></th><th>Docket / Booking</th><th>Client</th><th>Billing party</th><th>Consignee</th><th>POD</th><th>Freight</th><th>Action</th></tr></thead><tbody><?php foreach($dockets as $d):?><tr data-note="<?=htmlspecialchars(strtoupper($d['consignment_note']))?>" data-amount="<?=$d['basic_freight']?>"><td><input class="docket-check" type="checkbox" value="<?=$d['id']?>" data-note="<?=htmlspecialchars($d['consignment_note'])?>"></td><td><b><?=htmlspecialchars($d['consignment_note'])?></b><br><small><?=htmlspecialchars($d['booking_date'])?></small></td><td><?=htmlspecialchars($d['client_code']??'N/A')?><br><small><?=htmlspecialchars($d['client_name']??'')?></small></td><td><?=htmlspecialchars($d['billing_party_name'])?><br><small><?=htmlspecialchars($d['billing_gst_no'])?></small></td><td><?=htmlspecialchars($d['consignee_name'])?></td><td><?=$d['pod_ready']?'<span class="pod-pill">POD ready</span>':'<small>Not uploaded</small>'?></td><td class="freight">₹<?=number_format($d['basic_freight'],2)?></td><td><a class="btn btn-outline-primary btn-sm row-edit" href="index.php?id=<?=$d['id']?>" target="_blank">Edit docket</a></td></tr><?php endforeach;?><?php if(!$dockets):?><tr><td colspan="8" style="text-align:center;padding:26px">No eligible dockets found for these filters. Try adjusting your search criteria or date range.</td></tr><?php endif;?></tbody></table>
<div class="summary"><div class="sum-items"><div><small>Selected</small><b id="selectedCount">0 dockets</b></div><div><small>Taxable freight</small><b id="taxable">₹0.00</b></div><div><small id="taxLabel">CGST + SGST</small><b id="taxTotal">₹0.00</b></div><div><small>Invoice total</small><b id="grandTotal">₹0.00</b></div></div><button id="createInvoice" class="btn btn-light">Create invoice →</button></div></section>
</div></main></div></div><div class="docket-modal" id="docketModal" aria-hidden="true"><div class="modal-box"><div class="modal-top"><b id="modalDocketTitle">Edit docket</b><button type="button" id="closeDocketModal" class="btn btn-light btn-sm">Close</button></div><iframe id="docketFrame" title="Edit docket"></iframe></div></div><script>
const csrf=<?=json_encode($csrf)?>, money=n=>'₹'+Number(n).toLocaleString('en-IN',{minimumFractionDigits:2,maximumFractionDigits:2}); let taxType='CGST_SGST';
const checks=[...document.querySelectorAll('.docket-check')], party=document.querySelector('#invoiceParty'), partyGst=document.querySelector('#partyGst'), searchParty=document.querySelector('#searchParty');
// Sync search party with invoice party for better UX
if(searchParty && party) {
    searchParty.addEventListener('change', function() {
        party.value = this.value;
        let o = party.options[party.selectedIndex];
        partyGst.value = o.dataset.gst || '';
        renderPartyDetails();
    });
    
    // Also sync when invoice party changes
    party.addEventListener('change', function() {
        searchParty.value = this.value;
        let o = party.options[party.selectedIndex];
        partyGst.value = o.dataset.gst || '';
        renderPartyDetails();
    });
}
const companyDetails=<?=json_encode($companies, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?>, clientDetails=<?=json_encode($parties, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?>;
document.querySelector('.bill-top').insertAdjacentHTML('beforebegin','<div class="invoice-parties" style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin:16px 0"><div id="fromCard" style="border:1px solid #cce3de;border-top:4px solid #0f766e;border-radius:12px;padding:14px;background:#f8fffd"></div><div id="toCard" style="border:1px solid #d4e1f8;border-top:4px solid #2563eb;border-radius:12px;padding:14px;background:#fbfdff"></div></div>');
function cardHtml(title, data, gst){if(!data)return '<small>'+title+': Select details</small>';return '<small style="letter-spacing:.07em;color:#64748b;font-weight:800">'+title+'</small><strong style="display:block;color:#073b5c;margin:6px 0">'+(data.company_name||data.client_name||'—')+'</strong><div style="font-size:.8rem;color:#526777">'+(data.registration_address||data.address||'Address not available')+'</div><div style="font-size:.8rem;color:#526777;margin-top:4px">'+[data.phone,data.email].filter(Boolean).join(' · ')+'</div><b style="display:block;color:#0f766e;margin-top:7px;font-size:.82rem">GSTIN: '+(gst||data.gstin||data.gst_no||'—')+'</b>'}
function renderPartyDetails(){let co=companyGst.options[companyGst.selectedIndex], cp=companyDetails.find(x=>String(x.id)===String(co.dataset.reg));let cl=clientDetails.find(x=>x.client_name===party.value);fromCard.innerHTML=cardHtml('BILL FROM · OWNER COMPANY',cp,co.value);toCard.innerHTML=cardHtml('BILL TO · CLIENT',cl,partyGst.value||cl?.gst_no)}
party.addEventListener('change',renderPartyDetails);companyGst.addEventListener('change',renderPartyDetails);
function calc(){let a=checks.filter(x=>x.checked).reduce((s,x)=>s+Number(x.closest('tr').dataset.amount),0), r=Number(gstRate.value||0), t=a*r/100; selectedCount.textContent=checks.filter(x=>x.checked).length+' dockets';heroCount.textContent=checks.filter(x=>x.checked).length; taxable.textContent=heroAmount.textContent=money(a); taxTotal.textContent=money(t); grandTotal.textContent=money(a+t);taxLabel.textContent=taxType==='IGST'?'IGST @ '+r+'%':'CGST + SGST @ '+r+'%';}
checks.forEach(x=>x.onchange=calc); selectAll.onchange=e=>{checks.forEach(x=>x.checked=e.target.checked);calc()}; gstRate.oninput=calc;

// Auto-select dockets that were found from paste search
const urlParams = new URLSearchParams(window.location.search);
const pastedDocketsParam = urlParams.get('pasted_dockets');
if(pastedDocketsParam && pastedDocketsParam.trim() !== '') {
    const pastedDockets = pastedDocketsParam.split(',').map(d => d.trim().toUpperCase());
    let selectedCount = 0;
    
    checks.forEach(x => {
        let docketNote = x.dataset.note ? x.dataset.note.toUpperCase().trim() : '';
        pastedDockets.forEach(pd => {
            if(docketNote === pd || docketNote.includes(pd) || pd.includes(docketNote)) {
                x.checked = true;
                selectedCount++;
            }
        });
    });
    
    if(selectedCount > 0) {
        show('ok', selectedCount + ' docket(s) found and selected from your paste.');
        calc();
    }
    
    // Clear the parameter from URL
    const url = new URL(window.location);
    url.searchParams.delete('pasted_dockets');
    window.history.replaceState({}, '', url.toString());
}
// Initialize party selection from search
if(searchParty && searchParty.value) {
    party.value = searchParty.value;
    let o = party.options[party.selectedIndex];
    if(o) partyGst.value = o.dataset.gst || '';
}
// Trigger initial render
renderPartyDetails();
if (!companyGst.value && companyGst.options.length > 1) companyGst.selectedIndex = 1; renderPartyDetails();
document.querySelectorAll('.row-edit').forEach(link=>link.onclick=e=>{e.preventDefault(); docketFrame.src=link.href; modalDocketTitle.textContent='Edit docket · '+(link.closest('tr')?.dataset.note||''); docketModal.classList.add('open'); docketModal.setAttribute('aria-hidden','false')});
closeDocketModal.onclick=()=>{docketModal.classList.remove('open'); docketModal.setAttribute('aria-hidden','true'); docketFrame.src=''};
docketModal.onclick=e=>{if(e.target===docketModal) closeDocketModal.click()};
document.querySelectorAll('.gst-choice button').forEach(b=>b.onclick=()=>{document.querySelectorAll('.gst-choice button').forEach(x=>x.classList.remove('active'));b.classList.add('active');taxType=b.dataset.tax;calc()});
applyPaste.onclick=()=>{
    let wanted = pasteDockets.value.trim();
    if(!wanted) {
        show('err','Please paste docket numbers first.');
        return;
    }
    
    // Split and clean the pasted docket numbers
    let docketNumbers = wanted.split(/[\s,;]+/).filter(Boolean).join(',');
    
    // Get current URL and add pasted_dockets parameter
    const url = new URL(window.location);
    url.searchParams.set('pasted_dockets', docketNumbers);
    url.searchParams.set('search', '1');
    
    // Preserve current filters
    const party = document.getElementById('searchParty')?.value;
    const from = document.querySelector('input[name="from"]')?.value;
    const to = document.querySelector('input[name="to"]')?.value;
    const podOnly = document.querySelector('input[name="pod_only"]')?.checked;
    
    if(party) url.searchParams.set('party', party);
    if(from) url.searchParams.set('from', from);
    if(to) url.searchParams.set('to', to);
    if(podOnly) url.searchParams.set('pod_only', '1');
    
    // Redirect to trigger search
    window.location.href = url.toString();
};
function show(type,msg){notice.className='notice '+type;notice.textContent=msg;notice.style.display='block'}

function showInvoiceConfirmation(docketCount,freightAmount,totalAmount){
    if(docketCount===0){
        show('err','Kam se kam ek docket select karein invoice create karne ke liye.');
        return;
    }
    let confirmModal=document.getElementById('invoiceConfirmModal');
    if(!confirmModal){
        let modalHTML='<div class="modal fade" id="invoiceConfirmModal" tabindex="-1" style="display:block;background:rgba(0,0,0,0.5);position:fixed;top:0;left:0;right:0;bottom:0;z-index:2000"><div class="modal-dialog" style="margin-top:100px"><div class="modal-content"><div class="modal-header"><h5 class="modal-title">Confirm Invoice Creation</h5><button type="button" class="btn-close" onclick="closeInvoiceConfirm()"></button></div><div class="modal-body"><div class="alert alert-info"><strong>Invoice Summary:</strong><br>Dockets: '+docketCount+'<br>Taxable Amount: '+freightAmount+'<br>Total Amount: '+totalAmount+'</div><p>Are you sure you want to create this invoice? This action cannot be undone.</p></div><div class="modal-footer"><button type="button" class="btn btn-secondary" onclick="closeInvoiceConfirm()">Cancel</button><button type="button" class="btn btn-primary" id="confirmInvoiceBtn">Create Invoice</button></div></div></div></div>';
        document.body.insertAdjacentHTML('beforeend',modalHTML);
        confirmModal=document.getElementById('invoiceConfirmModal');
    }else{
        confirmModal.style.display='block';
    }
    document.getElementById('confirmInvoiceBtn').onclick=async()=>{await proceedWithInvoiceCreation()};
    closeInvoiceConfirm=function(){document.getElementById('invoiceConfirmModal').style.display='none'};
}

async function proceedWithInvoiceCreation(){
    let selectedDockets=checks.filter(x=>x.checked);
    if(selectedDockets.length===0){
        show('err','Koi docket selected nahi hai. Please select dockets first.');
        closeInvoiceConfirm();
        return;
    }
    let ids=selectedDockets.map(x=>x.value);
    let co=companyGst.options[companyGst.selectedIndex];
    if(!co.value){
        show('err','Owner company GST select karein.');
        closeInvoiceConfirm();
        return;
    }
    if(!party.value){
        show('err','Billing party select karein.');
        closeInvoiceConfirm();
        return;
    }
    let f=new FormData();
    f.append('csrf_token',csrf);
    f.append('company_gstin',co.value);
    f.append('company_id',co.dataset.company);
    f.append('company_gst_registration_id',co.dataset.reg);
    f.append('billing_party_name',party.value);
    f.append('billing_party_gstin',partyGst.value);
    f.append('invoice_date',invoiceDate.value);
    f.append('invoice_no',invoiceNo.value);
    f.append('gst_type',taxType);
    f.append('gst_rate',gstRate.value);
    f.append('remarks',remarks.value);
    ids.forEach(id=>f.append('consignment_ids[]',id));
    let r=await fetch('api/save_invoice.php',{method:'POST',body:f});
    let d=await r.json();
    if(d.success){
        show('ok',d.message+' Invoice: '+d.invoice_no);
        closeInvoiceConfirm();
        setTimeout(()=>location.reload(),2000);
    }else{
        show('err',d.message);
        closeInvoiceConfirm();
    }
}
createInvoice.onclick=async()=>{let selectedDockets=checks.filter(x=>x.checked);if(selectedDockets.length===0)return show('err','Kam se kam ek docket select karein invoice create karne ke liye.');let co=companyGst.options[companyGst.selectedIndex];if(!co.value)return show('err','Owner company GST select karein.');if(!party.value)return show('err','Billing party select karein.');showInvoiceConfirmation(selectedDockets.length,heroAmount.textContent,grandTotal.textContent)};

// Print preview functionality
printPreview.onclick=async()=>{let ids=checks.filter(x=>x.checked).map(x=>x.value);if(!ids.length)return show('err','Kam se kam ek docket select karein pehle.');let co=companyGst.options[companyGst.selectedIndex];if(!co.value)return show('err','Owner company GST select karein.');if(!party.value)return show('err','Billing party select karein.');let f=new FormData();[['csrf_token',csrf],['company_gstin',co.value],['company_id',co.dataset.company],['company_gst_registration_id',co.dataset.reg],['billing_party_name',party.value],['billing_party_gstin',partyGst.value],['invoice_date',invoiceDate.value],['gst_type',taxType],['gst_rate',gstRate.value]].forEach(x=>f.append(x[0],x[1]));ids.forEach(id=>f.append('consignment_ids[]',id));let r=await fetch('api/save_invoice.php',{method:'POST',body:f}),d=await r.json();if(d.success){let selectedCharges=[...document.querySelectorAll('.charge-select:checked')].map(x=>x.dataset.charge);let termsId=termsSelect.value;let printUrl='invoice_print.php?invoice_id='+d.invoice_id+'&format='+printFormat.value+'&terms_id='+termsId+'&charges='+selectedCharges.join(',');window.open(printUrl,'_blank')}else show('err',d.message)};

// Excel export functionality
exportExcel.onclick=async()=>{let ids=checks.filter(x=>x.checked).map(x=>x.value);if(!ids.length)return show('err','Kam se kam ek docket select karein pehle.');let co=companyGst.options[companyGst.selectedIndex];if(!co.value)return show('err','Owner company GST select karein.');if(!party.value)return show('err','Billing party select karein.');let f=new FormData();[['csrf_token',csrf],['company_gstin',co.value],['company_id',co.dataset.company],['company_gst_registration_id',co.dataset.reg],['billing_party_name',party.value],['billing_party_gstin',partyGst.value],['invoice_date',invoiceDate.value],['gst_type',taxType],['gst_rate',gstRate.value]].forEach(x=>f.append(x[0],x[1]));ids.forEach(id=>f.append('consignment_ids[]',id));let r=await fetch('api/save_invoice.php',{method:'POST',body:f}),d=await r.json();if(d.success){let selectedCharges=[...document.querySelectorAll('.charge-select:checked')].map(x=>x.dataset.charge);let exportForm=new FormData();exportForm.append('invoice_id',d.invoice_id);selectedCharges.forEach(c=>exportForm.append('charges[]',c));let response=await fetch('api/export_invoice_excel.php',{method:'POST',body:exportForm});if(response.ok){let blob=await response.blob();let url=window.URL.createObjectURL(blob);let a=document.createElement('a');a.href=url;a.download='Invoice_'+d.invoice_no+'.csv';document.body.appendChild(a);a.click();document.body.removeChild(a);window.URL.revokeObjectURL(url);show('ok','Excel export successful')}else show('err','Excel export failed')}else show('err',d.message)};

calc();
</script></body></html>
