<?php
require_once __DIR__ . '/includes/db_connection.php';
require_once __DIR__ . '/includes/functions.php';

startSecureSession();
requireLogin();
if (!isAdminUser()) { header('Location: dashboard.php'); exit; }

$conn = getDBConnection();
ensureCompanySchema($conn);
$csrf = generateCSRFToken();
$message = '';
$messageType = 'success';
$locked = isApplicationLocked($conn);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $message = 'Invalid security token. Please refresh and try again.'; $messageType = 'danger';
    } elseif (($_POST['action'] ?? '') === 'save') {
        if ($locked && !isSystemOwner()) {
            $message = 'The system is locked. Only the System Owner may change company details.'; $messageType = 'danger';
        } else {
            $id = (int)($_POST['id'] ?? 0);
            $code = strtoupper(trim($_POST['company_code'] ?? ''));
            $legalName = trim($_POST['legal_name'] ?? '');
            $tradeName = trim($_POST['trade_name'] ?? '');
            $address = trim($_POST['address'] ?? '');
            $cityId = (int)($_POST['city_id'] ?? 0);
            $stateCode = trim($_POST['state_code'] ?? '');
            $pin = trim($_POST['pincode'] ?? '');
            $pan = strtoupper(trim($_POST['pan_no'] ?? ''));
            $phone = trim($_POST['phone'] ?? ''); $email = trim($_POST['email'] ?? ''); $website = trim($_POST['website'] ?? '');
            $status = in_array($_POST['status'] ?? '', ['Active','Inactive'], true) ? $_POST['status'] : 'Active';
            $logoPath = trim($_POST['existing_logo'] ?? '');
            
            // Handle logo upload
            if (isset($_FILES['company_logo']) && $_FILES['company_logo']['error'] === UPLOAD_ERR_OK) {
                $uploadDir = __DIR__ . '/uploads/logos/';
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0755, true);
                }
                $fileInfo = pathinfo($_FILES['company_logo']['name']);
                $allowedTypes = ['jpg', 'jpeg', 'png', 'gif'];
                if (in_array(strtolower($fileInfo['extension']), $allowedTypes)) {
                    $newFileName = 'company_' . $id . '_' . time() . '.' . $fileInfo['extension'];
                    $uploadPath = $uploadDir . $newFileName;
                    if (move_uploaded_file($_FILES['company_logo']['tmp_name'], $uploadPath)) {
                        // Delete old logo if exists
                        if ($logoPath && file_exists(__DIR__ . '/' . $logoPath)) {
                            unlink(__DIR__ . '/' . $logoPath);
                        }
                        $logoPath = 'uploads/logos/' . $newFileName;
                    }
                }
            }
            
            $gstStates = $_POST['gst_state_code'] ?? []; $gstins = $_POST['gstin'] ?? []; $gstAddresses = $_POST['gst_address'] ?? [];
            $defaultIndex = (int)($_POST['default_gst'] ?? -1);
            $registrations = [];
            foreach ($gstStates as $i => $gstState) {
                $gstState = trim((string)$gstState); $gstin = strtoupper(trim((string)($gstins[$i] ?? '')));
                if ($gstState === '' && $gstin === '') continue;
                if ($gstState === '' || !preg_match('/^[0-9A-Z]{15}$/', $gstin)) { $message = 'Each GST registration needs a state and a valid 15-character GSTIN.'; $messageType = 'danger'; break; }
                if (isset($registrations[$gstState])) { $message = 'Only one GST registration is allowed for each state.'; $messageType = 'danger'; break; }
                $registrations[$gstState] = ['gstin' => $gstin, 'address' => trim((string)($gstAddresses[$i] ?? '')), 'default' => $i === $defaultIndex];
            }
            if ($message === '' && ($code === '' || $legalName === '')) { $message = 'Company Code and Legal Company Name are required.'; $messageType = 'danger'; }
            if ($message === '' && !empty($registrations) && !array_filter($registrations, fn($r) => $r['default'])) {
                $firstState = array_key_first($registrations); $registrations[$firstState]['default'] = true;
            }
            if ($message === '') {
                $conn->begin_transaction();
                try {
                    $city = $cityId > 0 ? $cityId : null;
                    if ($id > 0) {
                        $stmt = $conn->prepare('UPDATE companies SET company_code=?, legal_name=?, trade_name=?, address=?, city_id=?, state_code=?, pincode=?, pan_no=?, phone=?, email=?, website=?, logo_path=?, status=? WHERE id=?');
                        $stmt->bind_param('ssssisssssssi', $code,$legalName,$tradeName,$address,$city,$stateCode,$pin,$pan,$phone,$email,$website,$logoPath,$status,$id);
                    } else {
                        $stmt = $conn->prepare('INSERT INTO companies (company_code,legal_name,trade_name,address,city_id,state_code,pincode,pan_no,phone,email,website,logo_path,status) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)');
                        $stmt->bind_param('ssssissssssss', $code,$legalName,$tradeName,$address,$city,$stateCode,$pin,$pan,$phone,$email,$website,$logoPath,$status);
                    }
                    if (!$stmt->execute()) throw new RuntimeException($stmt->error); $stmt->close();
                    if ($id <= 0) $id = $conn->insert_id;
                    $stmt = $conn->prepare('DELETE FROM company_gst_registrations WHERE company_id=?'); $stmt->bind_param('i',$id); $stmt->execute(); $stmt->close();
                    $stmt = $conn->prepare('INSERT INTO company_gst_registrations (company_id,state_code,gstin,registration_address,is_default,status) VALUES (?,?,?,?,?,"Active")');
                    foreach ($registrations as $gstState => $reg) { $isDefault = $reg['default'] ? 1 : 0; $stmt->bind_param('isssi',$id,$gstState,$reg['gstin'],$reg['address'],$isDefault); if (!$stmt->execute()) throw new RuntimeException($stmt->error); }
                    $stmt->close(); $conn->commit(); $message = 'Company details and GST registrations saved.';
                } catch (Throwable $e) { $conn->rollback(); $message = 'Could not save company: ' . $e->getMessage(); $messageType = 'danger'; }
            }
        }
    }
}

$companyId = isset($_GET['new']) ? 0 : (int)($_GET['edit'] ?? 0);
if ($companyId <= 0 && !isset($_GET['new'])) { $r = $conn->query('SELECT id FROM companies ORDER BY legal_name LIMIT 1'); $companyId = $r && ($row=$r->fetch_assoc()) ? (int)$row['id'] : 0; if ($r) $r->free(); }
$company = null; $registrations = [];
if ($companyId) { $stmt=$conn->prepare('SELECT * FROM companies WHERE id=?'); $stmt->bind_param('i',$companyId); $stmt->execute(); $company=$stmt->get_result()->fetch_assoc(); $stmt->close(); $stmt=$conn->prepare('SELECT * FROM company_gst_registrations WHERE company_id=? ORDER BY is_default DESC,state_code'); $stmt->bind_param('i',$companyId); $stmt->execute(); $registrations=$stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close(); }
if (!$registrations) $registrations=[['state_code'=>'','gstin'=>'','registration_address'=>'','is_default'=>1]];
$states = $conn->query('SELECT state_name,state_code FROM states ORDER BY state_name')->fetch_all(MYSQLI_ASSOC);
$cities = $conn->query('SELECT id,city_name,state_code FROM cities ORDER BY city_name LIMIT 5000')->fetch_all(MYSQLI_ASSOC);
$companies = $conn->query('SELECT id,company_code,legal_name,status FROM companies ORDER BY legal_name')->fetch_all(MYSQLI_ASSOC);
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?=htmlspecialchars(appBrandTitle('Company Master'))?></title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet"><link rel="stylesheet" href="css/style.css"><style>.cm-wrap{padding:12px;max-width:1250px}.gst-row{display:grid;grid-template-columns:1fr 1fr 2fr auto auto;gap:8px;margin-bottom:7px;align-items:end}.gst-row .form-group{margin:0}.owner-box{border:1px solid #fdba74;background:#fff7ed;border-radius:6px;padding:10px;font-size:.76rem}@media(max-width:800px){.gst-row{grid-template-columns:1fr}.cm-wrap{padding:7px}}</style></head><body><div class="app-shell"><aside class="app-sidebar"><?php renderAppBrand(); ?><nav class="sidebar-nav"><?php renderSidebarNav(); ?></nav><?php renderSidebarFooter(); ?></aside><div class="app-main"><?php renderAppHeader('Company Master', '🏢'); ?><main class="app-body"><div class="cm-wrap">
<?php if($message):?><div class="alert alert-<?=htmlspecialchars($messageType)?> py-2" style="font-size:.78rem"><?=htmlspecialchars($message)?></div><?php endif;?>
<div class="d-flex justify-content-between align-items-center mb-2"><div><b>Company Profile & State GST Registrations</b><div class="text-muted small">Add one GSTIN per state. Invoice modules can use this master to select the issuing registration.</div></div><a class="btn btn-outline-primary btn-sm" href="company_master.php?new=1">+ New Company</a></div>
<form method="post" class="card" enctype="multipart/form-data"><div class="card-body"><input type="hidden" name="csrf_token" value="<?=htmlspecialchars($csrf)?>"><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= (int)($company['id']??0) ?>"><input type="hidden" name="existing_logo" value="<?=htmlspecialchars($company['logo_path']??'')?>">
<div class="form-row"><div class="form-group"><label>Company Code *</label><input class="form-control" required name="company_code" maxlength="32" value="<?=htmlspecialchars($company['company_code']??'')?>"></div><div class="form-group"><label>Legal Company Name *</label><input class="form-control" required name="legal_name" maxlength="200" value="<?=htmlspecialchars($company['legal_name']??'')?>"></div><div class="form-group"><label>Trade Name</label><input class="form-control" name="trade_name" maxlength="200" value="<?=htmlspecialchars($company['trade_name']??'')?>"></div></div>
<div class="form-row"><div class="form-group"><label>Company Logo</label><input type="file" name="company_logo" accept="image/*" class="form-control"><?php if(!empty($company['logo_path'])):?><div class="mt-1"><img src="<?=htmlspecialchars($company['logo_path'])?>" alt="Company Logo" style="max-height:60px;max-width:200px;border:1px solid #ddd;"></div><?php endif;?></div></div>
<div class="form-row full"><div class="form-group"><label>Registered Address</label><textarea class="form-control" name="address" rows="2"><?=htmlspecialchars($company['address']??'')?></textarea></div></div><div class="form-row"><div class="form-group"><label>State</label><select class="form-select" name="state_code"><option value="">-- Select --</option><?php foreach($states as $s):?><option value="<?=htmlspecialchars($s['state_code'])?>" <?=($company['state_code']??'')===$s['state_code']?'selected':''?>><?=htmlspecialchars($s['state_name'].' ('.$s['state_code'].')')?></option><?php endforeach;?></select></div><div class="form-group"><label>PIN</label><input class="form-control" name="pincode" maxlength="10" value="<?=htmlspecialchars($company['pincode']??'')?>"></div><div class="form-group"><label>PAN</label><input class="form-control" name="pan_no" maxlength="10" value="<?=htmlspecialchars($company['pan_no']??'')?>"></div><div class="form-group"><label>Status</label><select class="form-select" name="status"><option <?=($company['status']??'Active')==='Active'?'selected':''?>>Active</option><option <?=($company['status']??'')==='Inactive'?'selected':''?>>Inactive</option></select></div></div><div class="form-row"><div class="form-group"><label>Phone</label><input class="form-control" name="phone" maxlength="30" value="<?=htmlspecialchars($company['phone']??'')?>"></div><div class="form-group"><label>Email</label><input class="form-control" type="email" name="email" maxlength="150" value="<?=htmlspecialchars($company['email']??'')?>"></div><div class="form-group"><label>Website</label><input class="form-control" name="website" maxlength="150" value="<?=htmlspecialchars($company['website']??'')?>"></div></div>
<hr><div class="d-flex justify-content-between"><b style="font-size:.8rem">GST Registrations by State</b><button type="button" id="addGst" class="btn btn-outline-primary btn-sm">+ Add State GST</button></div><div id="gstRows" class="mt-2"><?php foreach($registrations as $i=>$g):?><div class="gst-row"><div class="form-group"><label>State *</label><select class="form-select" name="gst_state_code[]"><option value="">-- State --</option><?php foreach($states as $s):?><option value="<?=htmlspecialchars($s['state_code'])?>" <?=($g['state_code']??'')===$s['state_code']?'selected':''?>><?=htmlspecialchars($s['state_name'].' ('.$s['state_code'].')')?></option><?php endforeach;?></select></div><div class="form-group"><label>GSTIN *</label><input class="form-control" name="gstin[]" maxlength="15" value="<?=htmlspecialchars($g['gstin']??'')?>"></div><div class="form-group"><label>Registration Address</label><input class="form-control" name="gst_address[]" value="<?=htmlspecialchars($g['registration_address']??'')?>"></div><label class="small mb-2"><input type="radio" name="default_gst" value="<?=$i?>" <?=!empty($g['is_default'])?'checked':''?>> Default</label><button type="button" class="btn btn-outline-danger btn-sm remove-gst">Remove</button></div><?php endforeach;?></div><button class="btn btn-primary btn-sm mt-2">Save Company</button></div></form>
<div class="owner-box mt-3"><b>System lock: <?= $locked ? 'LOCKED' : 'UNLOCKED' ?></b><br>Locking and unlocking require a one-time OTP sent only to <b>kkundan.jha@gmail.com</b>. No Admin or System Owner password can bypass this.<br><a class="btn btn-warning btn-sm mt-2" href="owner_control.php">Open Owner Lock Control</a></div>
<?php if($companies):?><div class="card mt-3"><div class="card-body py-2" style="font-size:.78rem"><b>Companies:</b><?php foreach($companies as $c):?><a class="btn btn-sm <?=($company['id']??0)==$c['id']?'btn-primary':'btn-outline-secondary'?> ms-1" href="company_master.php?edit=<?=$c['id']?>"><?=htmlspecialchars($c['company_code'].' — '.$c['legal_name'])?></a><?php endforeach;?></div></div><?php endif;?></div></main></div></div>
<template id="gstTemplate"><div class="gst-row"><div class="form-group"><label>State *</label><select class="form-select" name="gst_state_code[]"><option value="">-- State --</option><?php foreach($states as $s):?><option value="<?=htmlspecialchars($s['state_code'])?>"><?=htmlspecialchars($s['state_name'].' ('.$s['state_code'].')')?></option><?php endforeach;?></select></div><div class="form-group"><label>GSTIN *</label><input class="form-control" name="gstin[]" maxlength="15"></div><div class="form-group"><label>Registration Address</label><input class="form-control" name="gst_address[]"></div><label class="small mb-2"><input type="radio" name="default_gst" value=""> Default</label><button type="button" class="btn btn-outline-danger btn-sm remove-gst">Remove</button></div></template><script>const rows=document.getElementById('gstRows');function sync(){rows.querySelectorAll('.gst-row').forEach((r,i)=>r.querySelector('input[type=radio]').value=i)}document.getElementById('addGst').onclick=()=>{rows.insertAdjacentHTML('beforeend',document.getElementById('gstTemplate').innerHTML);sync()};rows.onclick=e=>{if(e.target.classList.contains('remove-gst')&&rows.children.length>1){e.target.closest('.gst-row').remove();sync()}};sync();</script></body></html>
