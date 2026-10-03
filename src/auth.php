<?php
require_once __DIR__.'/config.php';
function user_from_token(): ?array {
    $h=$_SERVER['HTTP_AUTHORIZATION']??'';
    if (!preg_match('/Bearer\s+(.+)/i',$h,$m)) return null;
    $st=db()->prepare('SELECT u.* FROM auth_tokens t JOIN users u ON u.id=t.user_id WHERE t.token_hash=:h AND t.expires_at>NOW() AND u.deleted_at IS NULL');
    $st->execute([':h'=>hash('sha256',trim($m[1]))]); $u=$st->fetch(); return $u?:null;
}
function require_user(): array { $u=user_from_token(); if(!$u) fail('Unauthenticated',401); return $u; }
function issue_token(int $uid): string {
    $token=uuid_token(); $st=db()->prepare('INSERT INTO auth_tokens(user_id,token_hash,name,expires_at,created_at) VALUES(?,?,?,?,?)');
    $st->execute([$uid,hash('sha256',$token),'spa',date('Y-m-d H:i:s',time()+60*60*24*30),now()]); return $token;
}
function academy_role(int $uid,int $aid): ?string {
    $s=db()->prepare('SELECT role FROM academy_members WHERE user_id=? AND academy_id=? LIMIT 1'); $s->execute([$uid,$aid]); $r=$s->fetchColumn(); return $r?:null;
}
function require_academy_access(int $uid,int $aid,bool $manage=false): string {
    $role=academy_role($uid,$aid);
    if (!$role) { $u=user_from_token(); if(!$u || !$u['is_super_admin']) fail('Forbidden',403); $role='super_admin'; }
    if($manage && !in_array($role,['owner','admin','instructor','super_admin'],true)) fail('Manager permission required',403);
    return $role;
}
