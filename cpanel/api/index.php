<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
$action=$_GET['action']??'health';
if($action==='health'&&($_SERVER['REQUEST_METHOD']??'GET')==='GET'){
  echo json_encode(['ok'=>true,'version'=>'github-build-foundation','database'=>'not-configured']);exit;
}
http_response_code(404);echo json_encode(['error'=>'Not implemented']);
