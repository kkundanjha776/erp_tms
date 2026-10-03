<?php
require_once __DIR__ . '/../includes/db_connection.php'; require_once __DIR__ . '/../includes/functions.php'; require_once __DIR__ . '/../includes/master_data.php';
startSecureSession(); requireLoginAPI(); $conn=getDBConnection(); ensurePODSchema($conn); $id=(int)($_GET['id']??0);
$stmt=$conn->prepare('SELECT original_name, stored_name, mime_type FROM pod_files WHERE id=?'); $stmt->bind_param('i',$id); $stmt->execute(); $file=$stmt->get_result()->fetch_assoc(); $stmt->close(); $path=dirname(__DIR__).'/uploads/pod/'.($file['stored_name']??'');
if(!$file || !is_file($path)){http_response_code(404);exit('POD file not found');} header('Content-Type: '.$file['mime_type']); header('Content-Length: '.filesize($path)); $disposition=isset($_GET['download'])?'attachment':'inline'; header('Content-Disposition: '.$disposition.'; filename="'.str_replace('"','',basename($file['original_name'])).'"'); readfile($path);
