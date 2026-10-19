<?php
declare(strict_types=1);
ini_set('display_errors','0');
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
function answer(int $code,array $data):never {http_response_code($code);echo json_encode($data,JSON_UNESCAPED_SLASHES);exit;}
function payload():array {$x=json_decode(file_get_contents('php://input'),true);if(!is_array($x))answer(400,['error'=>'Invalid JSON']);return $x;}
function csrf():void {if(!hash_equals($_SESSION['csrf']??'',$_SERVER['HTTP_X_CSRF_TOKEN']??'')||empty($_SESSION['csrf']))answer(419,['error'=>'CSRF validation failed']);}
function host():array {if(empty($_SESSION['uid'])||empty($_SESSION['org']))answer(401,['error'=>'Sign in required']);return [(int)$_SESSION['uid'],(int)$_SESSION['org']];}
function query(PDO $db,string $sql,array $params=[]):PDOStatement {$q=$db->prepare($sql);$q->execute($params);return $q;}
$base=rtrim(dirname(dirname($_SERVER['SCRIPT_NAME']??'/mediabox-staging/api/index.php')),'/').'/';
session_name('mediabox_host');
session_set_cookie_params(['path'=>$base,'httponly'=>true,'secure'=>!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off','samesite'=>'Lax']);
ini_set('session.use_strict_mode','1');session_start();
$action=(string)($_GET['action']??'health');$method=$_SERVER['REQUEST_METHOD']??'GET';
if($action==='health')answer(200,['ok'=>true,'version'=>'0.2-integrated']);
if($action==='csrf'&&$method==='GET'){$_SESSION['csrf']??=bin2hex(random_bytes(24));answer(200,['csrf'=>$_SESSION['csrf']]);}
$config=getenv('MEDIABOX_CONFIG')?:dirname(__DIR__,4).'/mediabox-private/config.php';
if(!is_file($config))answer(503,['error'=>'Private database configuration missing']);
require $config;
try{$db=new PDO('mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4',DB_USER,DB_PASS,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);}
catch(Throwable $e){answer(503,['error'=>'Database unavailable']);}
if($method!=='GET')csrf();
if($action==='login'&&$method==='POST'){
 $p=payload();$email=strtolower(trim((string)($p['email']??'')));
 $u=query($db,'SELECT id,organization_id,password_hash FROM users WHERE email=? LIMIT 1',[$email])->fetch(PDO::FETCH_ASSOC);
 if(!$u||!password_verify((string)($p['password']??''),$u['password_hash']))answer(401,['error'=>'Invalid credentials']);
 session_regenerate_id(true);$_SESSION['uid']=(int)$u['id'];$_SESSION['org']=(int)$u['organization_id'];
 $_SESSION['csrf']=bin2hex(random_bytes(24));answer(200,['ok'=>true,'csrf'=>$_SESSION['csrf']]);
}
if($action==='me')answer(200,['authenticated'=>!empty($_SESSION['uid'])]);
if($action==='logout'&&$method==='POST'){$_SESSION=[];session_destroy();answer(200,['ok'=>true]);}
if($action==='public_event'&&$method==='GET'){
 $slug=substr((string)($_GET['slug']??''),0,180);
 $event=query($db,"SELECT id,name,slug FROM events WHERE slug=? AND status='live'",[$slug])->fetch(PDO::FETCH_ASSOC);
 if(!$event)answer(404,['error'=>'Event unavailable']);
 $media=query($db,"SELECT id,guest_name,mime_type FROM media WHERE event_id=? AND status='approved' ORDER BY id DESC LIMIT 250",[$event['id']])->fetchAll(PDO::FETCH_ASSOC);
 $notes=query($db,"SELECT guest_name,message FROM guestbook WHERE event_id=? AND status='approved' ORDER BY id DESC LIMIT 100",[$event['id']])->fetchAll(PDO::FETCH_ASSOC);
 answer(200,['event'=>$event,'media'=>$media,'guestbook'=>$notes]);
}
if($action==='media_file'&&$method==='GET'){
 $id=(int)($_GET['id']??0);
 $m=query($db,"SELECT m.storage_key,m.mime_type,m.status,e.status event_status,e.organization_id FROM media m JOIN events e ON e.id=m.event_id WHERE m.id=?",[$id])->fetch(PDO::FETCH_ASSOC);
 $authorized=$m&&(($m['status']==='approved'&&$m['event_status']==='live')||(!empty($_SESSION['uid'])&&(int)($_SESSION['org']??0)===(int)$m['organization_id']));
 if(!$authorized||!preg_match('/^[a-f0-9]{40}\\.(jpg|png|webp)$/',$m['storage_key']))answer(404,['error'=>'Not found']);
 $dir=getenv('MEDIABOX_UPLOAD_DIR')?:dirname(__DIR__,4).'/mediabox-private/uploads';
 $file=$dir.'/'.$m['storage_key'];if(!is_file($file))answer(404,['error'=>'Missing file']);
 header_remove('Content-Type');header('Content-Type: '.$m['mime_type']);header('Content-Length: '.filesize($file));readfile($file);exit;
}
if($action==='guest_upload'&&$method==='POST'){
 $slug=substr((string)($_POST['slug']??''),0,180);
 $e=query($db,"SELECT id FROM events WHERE slug=? AND status='live'",[$slug])->fetch(PDO::FETCH_ASSOC);
 if(!$e)answer(404,['error'=>'Event unavailable']);
 $f=$_FILES['file']??null;if(!$f||is_array($f['name'])||$f['error']!==UPLOAD_ERR_OK||$f['size']<=0||$f['size']>15*1024*1024)answer(422,['error'=>'Invalid upload (15 MB max)']);
 $mime=(new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
 $types=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
 $dim=@getimagesize($f['tmp_name']);
 if(!isset($types[$mime])||!$dim||$dim[0]*$dim[1]>40000000)answer(415,['error'=>'Unsupported or oversized image']);
 $dir=getenv('MEDIABOX_UPLOAD_DIR')?:dirname(__DIR__,4).'/mediabox-private/uploads';
 if(!is_dir($dir)&&!mkdir($dir,0700,true))answer(500,['error'=>'Storage unavailable']);
 $key=bin2hex(random_bytes(20)).'.'.$types[$mime];
 if(!move_uploaded_file($f['tmp_name'],$dir.'/'.$key))answer(500,['error'=>'Upload failed']);
 try{query($db,"INSERT INTO media(event_id,guest_name,storage_key,mime_type,byte_size,status) VALUES(?,?,?,?,?,'pending')",[$e['id'],mb_substr(trim((string)($_POST['guest_name']??'')),0,120),$key,$mime,$f['size']]);}
 catch(Throwable $ex){@unlink($dir.'/'.$key);answer(500,['error'=>'Could not save upload']);}
 answer(201,['ok'=>true,'status'=>'pending']);
}
if($action==='guestbook_submit'&&$method==='POST'){
 $p=payload();$slug=substr((string)($p['slug']??''),0,180);
 $e=query($db,"SELECT id FROM events WHERE slug=? AND status='live'",[$slug])->fetch(PDO::FETCH_ASSOC);
 if(!$e)answer(404,['error'=>'Event unavailable']);
 $name=trim((string)($p['name']??''));$message=trim((string)($p['message']??''));
 if(mb_strlen($name)<1||mb_strlen($name)>120||mb_strlen($message)<2||mb_strlen($message)>1500)answer(422,['error'=>'Invalid message']);
 query($db,"INSERT INTO guestbook(event_id,guest_name,message,status) VALUES(?,?,?,'pending')",[$e['id'],$name,$message]);
 answer(201,['ok'=>true,'status'=>'pending']);
}
[$uid,$org]=host();
if($action==='events'&&$method==='GET'){
 answer(200,['events'=>query($db,'SELECT id,name,slug,status FROM events WHERE organization_id=? ORDER BY id DESC',[$org])->fetchAll(PDO::FETCH_ASSOC)]);
}
if($action==='create_event'&&$method==='POST'){
 $p=payload();$name=trim((string)($p['name']??''));
 if(mb_strlen($name)<3||mb_strlen($name)>160)answer(422,['error'=>'Event name must be 3-160 characters']);
 $slug=trim(strtolower(preg_replace('/[^a-z0-9]+/i','-',$name)),'-').'-'.bin2hex(random_bytes(4));
 query($db,"INSERT INTO events(organization_id,name,slug,status) VALUES(?,?,?,'draft')",[$org,$name,$slug]);
 answer(201,['id'=>(int)$db->lastInsertId(),'slug'=>$slug]);
}
if($action==='event_status'&&$method==='POST'){
 $p=payload();$status=(string)($p['status']??'');
 if(!in_array($status,['draft','live','closed'],true))answer(422,['error'=>'Invalid status']);
 query($db,'UPDATE events SET status=? WHERE id=? AND organization_id=?',[$status,(int)($p['id']??0),$org]);answer(200,['ok'=>true]);
}
if($action==='moderation'&&$method==='GET'){
 $id=(int)($_GET['event_id']??0);
 $e=query($db,'SELECT id FROM events WHERE id=? AND organization_id=?',[$id,$org])->fetch();
 if(!$e)answer(404,['error'=>'Event not found']);
 $media=query($db,'SELECT id,guest_name,status,mime_type FROM media WHERE event_id=? ORDER BY id DESC LIMIT 200',[$id])->fetchAll(PDO::FETCH_ASSOC);
 $notes=query($db,'SELECT id,guest_name,message,status FROM guestbook WHERE event_id=? ORDER BY id DESC LIMIT 200',[$id])->fetchAll(PDO::FETCH_ASSOC);
 answer(200,['media'=>$media,'guestbook'=>$notes]);
}
if($action==='moderate'&&$method==='POST'){
 $p=payload();$type=$p['type']??'';$status=$p['status']??'';
 if(!in_array($type,['media','guestbook'],true)||!in_array($status,['approved','rejected'],true))answer(422,['error'=>'Invalid decision']);
 query($db,"UPDATE $type m JOIN events e ON e.id=m.event_id SET m.status=? WHERE m.id=? AND e.organization_id=?",[$status,(int)($p['id']??0),$org]);
 answer(200,['ok'=>true]);
}

if($action==='dashboard'&&$method==='GET'){
 [$uid,$org]=host();
 $counts=query($db,"SELECT COUNT(*) total,SUM(status='live') live,SUM(status='closed') closed FROM events WHERE organization_id=?",[$org])->fetch(PDO::FETCH_ASSOC);
 $media=query($db,"SELECT COUNT(*) total,SUM(m.status='pending') pending FROM media m JOIN events e ON e.id=m.event_id WHERE e.organization_id=?",[$org])->fetch(PDO::FETCH_ASSOC);
 $notes=query($db,"SELECT COUNT(*) total,SUM(g.status='pending') pending FROM guestbook g JOIN events e ON e.id=g.event_id WHERE e.organization_id=?",[$org])->fetch(PDO::FETCH_ASSOC);
 $recent=query($db,"SELECT m.id,m.guest_name,m.status,m.created_at,e.name event_name,e.id event_id,'media' kind FROM media m JOIN events e ON e.id=m.event_id WHERE e.organization_id=? UNION ALL SELECT g.id,g.guest_name,g.status,g.created_at,e.name,e.id,'guestbook' FROM guestbook g JOIN events e ON e.id=g.event_id WHERE e.organization_id=? ORDER BY created_at DESC LIMIT 12",[$org,$org])->fetchAll(PDO::FETCH_ASSOC);
 answer(200,['events'=>['total'=>(int)$counts['total'],'live'=>(int)$counts['live'],'closed'=>(int)$counts['closed']],'media'=>['total'=>(int)$media['total'],'pending'=>(int)$media['pending']],'guestbook'=>['total'=>(int)$notes['total'],'pending'=>(int)$notes['pending']],'recent'=>$recent]);
}
if($action==='account'&&$method==='GET'){
 [$uid,$org]=host();
 $row=query($db,'SELECT u.email,o.name organization FROM users u JOIN organizations o ON o.id=u.organization_id WHERE u.id=? AND u.organization_id=?',[$uid,$org])->fetch(PDO::FETCH_ASSOC);
 if(!$row)answer(404,['error'=>'Account not found']);
 answer(200,['account'=>$row]);
}
if($action==='change_password'&&$method==='POST'){
 [$uid,$org]=host();$p=payload();
 $current=(string)($p['current']??'');$next=(string)($p['next']??'');
 if(strlen($next)<14||strlen($next)>256)answer(422,['error'=>'New password must be at least 14 characters']);
 $row=query($db,'SELECT password_hash FROM users WHERE id=? AND organization_id=?',[$uid,$org])->fetch(PDO::FETCH_ASSOC);
 if(!$row||!password_verify($current,$row['password_hash']))answer(403,['error'=>'Current password incorrect']);
 query($db,'UPDATE users SET password_hash=? WHERE id=? AND organization_id=?',[password_hash($next,PASSWORD_DEFAULT),$uid,$org]);
 session_regenerate_id(true);answer(200,['ok'=>true]);
}
if($action==='rename_event'&&$method==='POST'){
 [$uid,$org]=host();$p=payload();$name=trim((string)($p['name']??''));
 if(mb_strlen($name)<3||mb_strlen($name)>160)answer(422,['error'=>'Event name must be 3–160 characters']);
 $q=query($db,'UPDATE events SET name=? WHERE id=? AND organization_id=?',[$name,(int)($p['id']??0),$org]);
 if($q->rowCount()===0)answer(404,['error'=>'Event not found or unchanged']);
 answer(200,['ok'=>true]);
}
if($action==='bulk_moderate'&&$method==='POST'){
 [$uid,$org]=host();$p=payload();$type=(string)($p['type']??'');$status=(string)($p['status']??'');$ids=$p['ids']??[];
 if(!in_array($type,['media','guestbook'],true)||!in_array($status,['approved','rejected'],true)||!is_array($ids)||count($ids)<1||count($ids)>100)answer(422,['error'=>'Invalid bulk request']);
 $ids=array_values(array_unique(array_map('intval',$ids)));
 if(min($ids)<1)answer(422,['error'=>'Invalid identifiers']);
 $marks=implode(',',array_fill(0,count($ids),'?'));
 $sql="UPDATE $type m JOIN events e ON e.id=m.event_id SET m.status=? WHERE e.organization_id=? AND m.id IN ($marks)";
 $q=query($db,$sql,array_merge([$status,$org],$ids));
 answer(200,['ok'=>true,'updated'=>$q->rowCount()]);
}

answer(404,['error'=>'Unknown endpoint']);
