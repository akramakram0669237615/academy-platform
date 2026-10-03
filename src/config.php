<?php
declare(strict_types=1);

function envv(string $key, ?string $default=null): ?string {
    $v=getenv($key); return ($v===false || $v==='') ? $default : $v;
}
function app_key(): string { return (string)envv('APP_KEY','change-this-secret'); }
function db(): PDO {
    static $pdo=null; if ($pdo instanceof PDO) return $pdo;
    $host=envv('DB_HOST','127.0.0.1'); $port=envv('DB_PORT','5432');
    $name=envv('DB_DATABASE','academybuilder'); $user=envv('DB_USERNAME','app'); $pass=envv('DB_PASSWORD','secret');
    $pdo=new PDO("pgsql:host=$host;port=$port;dbname=$name",$user,$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    return $pdo;
}
function json_input(): array {
    $raw=file_get_contents('php://input'); $d=json_decode($raw,true);
    if (is_array($d)) return $d;
    return $_POST ?: [];
}
function json_out(mixed $data,int $status=200): never {
    http_response_code($status); header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); exit;
}
function ok(mixed $data=null,int $status=200,array $meta=[]): never { json_out(['success'=>true,'data'=>$data,'meta'=>(object)$meta,'message'=>null],$status); }
function fail(string $message,int $status=400,array $errors=[]): never { json_out(['success'=>false,'data'=>null,'meta'=>(object)[],'message'=>$message,'errors'=>(object)$errors],$status); }
function require_fields(array $d,array $fields): void { foreach($fields as $f) if (!isset($d[$f]) || trim((string)$d[$f])==='') fail("Missing field: $f",422); }
function slugify(string $s): string { $s=trim(mb_strtolower($s)); $s=preg_replace('/[^\pL\pN]+/u','-',$s); return trim($s,'-') ?: 'academy'; }
function uuid_token(): string { return bin2hex(random_bytes(32)); }
function now(): string { return date('Y-m-d H:i:s'); }
