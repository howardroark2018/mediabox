<?php
declare(strict_types=1);
/**
 * Media Box first-host bootstrap.
 * CLI ONLY. Run from the cPanel Terminal/SSH, never through a browser.
 * Keep this script OUTSIDE public_html.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }
if (!extension_loaded('pdo_mysql')) { fwrite(STDERR,"PDO MySQL unavailable\n"); exit(1); }
$config = getenv('MEDIABOX_CONFIG') ?: '/home/ajhn9tohufdw/mediabox-private/config.php';
if (!is_file($config)) { fwrite(STDERR,"Private config missing\n"); exit(1); }
require $config;
function ask(string $label):string { fwrite(STDOUT,$label); return trim((string)fgets(STDIN)); }
$org=ask("Organisation name: ");
$email=strtolower(ask("Administrator email: "));
if(mb_strlen($org)<2||mb_strlen($org)>150||!filter_var($email,FILTER_VALIDATE_EMAIL)){fwrite(STDERR,"Invalid organisation or email\n");exit(1);}
fwrite(STDOUT,"Administrator password (input hidden when stty is available): ");
$hidden=false;
if (function_exists('shell_exec') && stripos(PHP_OS_FAMILY,'Windows')===false) {
 $state=trim((string)@shell_exec('stty -g 2>/dev/null'));
 if ($state!=='' && preg_match('/^[0-9a-f:]+$/i',$state)) {
   @shell_exec('stty -echo 2>/dev/null');$hidden=true;
 }
}
$password=rtrim((string)fgets(STDIN),"\r\n");
if($hidden){@shell_exec('stty echo 2>/dev/null');fwrite(STDOUT,"\n");}
if(!$hidden){fwrite(STDERR,"\nWarning: terminal echo could not be disabled; avoid shared terminals.\n");}
if(strlen($password)<14||strlen($password)>256){fwrite(STDERR,"Password must be 14–256 characters\n");exit(1);}
try{
 $db=new PDO('mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4',DB_USER,DB_PASS,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
 $count=(int)$db->query('SELECT COUNT(*) FROM users')->fetchColumn();
 if($count!==0){fwrite(STDERR,"Bootstrap refused: users already exist\n");exit(1);}
 $db->beginTransaction();
 $q=$db->prepare('INSERT INTO organizations(name) VALUES (?)');$q->execute([$org]);$orgId=(int)$db->lastInsertId();
 $q=$db->prepare('INSERT INTO users(organization_id,email,password_hash) VALUES (?,?,?)');
 $q->execute([$orgId,$email,password_hash($password,PASSWORD_DEFAULT)]);
 $db->commit();fwrite(STDOUT,"Administrator created successfully. Remove this CLI script from hosting after use.\n");
}catch(Throwable $e){
 if(isset($db)&&$db->inTransaction())$db->rollBack();
 fwrite(STDERR,"Bootstrap failed; inspect schema and database configuration.\n");exit(1);
}
