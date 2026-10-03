<?php
declare(strict_types=1);
require_once __DIR__.'/../src/config.php';
require_once __DIR__.'/../src/auth.php';

function path_parts(): array { $p=parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH) ?: '/'; $p=trim($p,'/'); return $p===''?[]:explode('/',$p); }
function method(): string { return strtoupper($_SERVER['REQUEST_METHOD']??'GET'); }
function body(): array { return json_input(); }
function find_academy(int $id): array { $s=db()->prepare('SELECT * FROM academies WHERE id=? AND deleted_at IS NULL'); $s->execute([$id]); $r=$s->fetch(); if(!$r) fail('Academy not found',404); $r['theme']=$r['theme']?json_decode($r['theme'],true):null; return $r; }
function course(int $id): array { $s=db()->prepare('SELECT * FROM courses WHERE id=? AND deleted_at IS NULL'); $s->execute([$id]); $r=$s->fetch(); if(!$r) fail('Course not found',404); return $r; }
function api(): void {
  $p=path_parts(); if(($p[0]??'')!=='api') fail('Not found',404);
  if(($p[1]??'')!=='v1') fail('API version not found',404);
  $seg=array_slice($p,2); $m=method();
  if($m==='GET' && ($seg[0]??'')==='health') ok(['status'=>'ok','php'=>PHP_VERSION]);
  // auth
  if(($seg[0]??'')==='auth') {
    $action=$seg[1]??''; $d=body();
    if($action==='register' && $m==='POST'){
      require_fields($d,['name','email','password','academy_name']); if(!filter_var($d['email'],FILTER_VALIDATE_EMAIL)) fail('Invalid email',422);
      $pdo=db(); $pdo->beginTransaction(); try {
        $q=$pdo->prepare('SELECT id FROM users WHERE lower(email)=lower(?) AND deleted_at IS NULL'); $q->execute([$d['email']]); if($q->fetch()) fail('Email already registered',422);
        $hash=password_hash($d['password'],PASSWORD_DEFAULT); $q=$pdo->prepare('INSERT INTO users(name,email,password,is_super_admin,locale,created_at,updated_at) VALUES(?,?,?,?,?,?,?) RETURNING id'); $q->execute([$d['name'],$d['email'],$hash,false,$d['locale']??'ar',now(),now()]); $uid=(int)$q->fetchColumn();
        $base=slugify($d['academy_name']); $slug=$base; for($i=2;;$i++){ $q=$pdo->prepare('SELECT 1 FROM academies WHERE slug=?'); $q->execute([$slug]); if(!$q->fetch()) break; $slug=$base.'-'.$i; }
        $q=$pdo->prepare("SELECT id FROM plans WHERE slug='free' LIMIT 1"); $q->execute(); $plan=$q->fetchColumn();
        $q=$pdo->prepare('INSERT INTO academies(owner_id,plan_id,name,slug,description,template,language,currency,status,theme,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?) RETURNING id'); $q->execute([$uid,$plan,$d['academy_name'], $slug,$d['description']??null,$d['template']??'personal-instructor','ar','USD','draft',null,now(),now()]); $aid=(int)$q->fetchColumn();
        $pdo->prepare('INSERT INTO academy_members(academy_id,user_id,role,created_at,updated_at) VALUES(?,?,?,?,?)')->execute([$aid,$uid,'owner',now(),now()]); $pdo->commit();
        ok(['user'=>['id'=>$uid,'name'=>$d['name'],'email'=>$d['email']],'academy'=>find_academy($aid),'token'=>issue_token($uid)],201);
      } catch(Throwable $e){ if($pdo->inTransaction())$pdo->rollBack(); throw $e; }
    }
    if($action==='login' && $m==='POST'){ require_fields($d,['email','password']); $q=db()->prepare('SELECT * FROM users WHERE lower(email)=lower(?) AND deleted_at IS NULL'); $q->execute([$d['email']]); $u=$q->fetch(); if(!$u||!password_verify($d['password'],$u['password'])) fail('Invalid credentials',422); ok(['token'=>issue_token((int)$u['id'])]); }
    if($action==='logout' && $m==='POST'){ require_user(); $h=$_SERVER['HTTP_AUTHORIZATION']??''; preg_match('/Bearer\s+(.+)/i',$h,$mm); if(isset($mm[1])) db()->prepare('DELETE FROM auth_tokens WHERE token_hash=?')->execute([hash('sha256',trim($mm[1]))]); ok(null); }
    if($action==='me' && $m==='GET'){ $u=require_user(); unset($u['password']); $q=db()->prepare('SELECT academy_id,role FROM academy_members WHERE user_id=?'); $q->execute([$u['id']]); $u['memberships']=$q->fetchAll(); ok($u); }
  }
  // public academies
  if(($seg[0]??'')==='academies' && isset($seg[1])) {
    $aid=(int)$seg[1]; $academy=find_academy($aid); $action=$seg[2]??'';
    if($action==='courses' && $m==='GET' && !isset($seg[3])) { $q=db()->prepare("SELECT id,title,slug,description,price,status,created_at FROM courses WHERE academy_id=? AND deleted_at IS NULL AND status='published' ORDER BY id DESC"); $q->execute([$aid]); ok($q->fetchAll()); }
    if($action==='courses' && isset($seg[3])) { $cid=(int)$seg[3]; $c=course($cid); if((int)$c['academy_id']!==$aid) fail('Course not found',404);
      if($m==='GET' && !isset($seg[4])) { $s=db()->prepare('SELECT * FROM course_sections WHERE course_id=? ORDER BY sort_order,id'); $s->execute([$cid]); $sections=$s->fetchAll(); foreach($sections as &$sec){$l=db()->prepare('SELECT id,title,type,content,duration_seconds,sort_order,is_free_preview FROM lessons WHERE course_id=? AND section_id=? ORDER BY sort_order,id');$l->execute([$cid,$sec['id']]);$sec['lessons']=$l->fetchAll();} $c['sections']=$sections; ok($c); }
    }
    // authenticated academy actions
    $u=require_user(); require_academy_access((int)$u['id'],$aid,false);
    if($action==='courses' && $m==='POST' && !isset($seg[3])) { require_academy_access((int)$u['id'],$aid,true); require_fields(body(),['title']); $d=body(); $slug=slugify($d['title']).'-'.substr(bin2hex(random_bytes(3)),0,5); $q=db()->prepare('INSERT INTO courses(academy_id,instructor_id,title,slug,description,price,status,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?) RETURNING *'); $q->execute([$aid,$u['id'],$d['title'],$slug,$d['description']??null,(float)($d['price']??0),$d['status']??'draft',now(),now()]); ok($q->fetch(),201); }
    if($action==='courses' && isset($seg[3])) { $cid=(int)$seg[3];
      if($m==='PUT'){ require_academy_access((int)$u['id'],$aid,true); $d=body(); $sets=[];$vals=[]; foreach(['title','description','price','status'] as $f) if(array_key_exists($f,$d)){ $sets[]="$f=?";$vals[]=$d[$f]; } if(!$sets) ok(course($cid)); $vals[]=$cid;$vals[]=$aid; $q=db()->prepare('UPDATE courses SET '.implode(',',$sets).',updated_at=NOW() WHERE id=? AND academy_id=? RETURNING *');$q->execute($vals);ok($q->fetch());}
      if($m==='DELETE'){require_academy_access((int)$u['id'],$aid,true);db()->prepare('UPDATE courses SET deleted_at=NOW() WHERE id=? AND academy_id=?')->execute([$cid,$aid]);ok(null);}
      if($m==='POST' && ($seg[4]??'')==='enroll'){ $q=db()->prepare('SELECT 1 FROM enrollments WHERE course_id=? AND user_id=?');$q->execute([$cid,$u['id']]);if(!$q->fetch()) db()->prepare('INSERT INTO enrollments(academy_id,course_id,user_id,status,enrolled_at,created_at,updated_at) VALUES(?,?,?,?,?,?,?)')->execute([$aid,$cid,$u['id'],'active',now(),now(),now()]);ok(['enrolled'=>true]);}
      if($m==='GET' && ($seg[4]??'')==='learn'){ $q=db()->prepare("SELECT 1 FROM enrollments WHERE course_id=? AND user_id=? AND status='active'");$q->execute([$cid,$u['id']]);if(!$q->fetch() && !in_array(academy_role($u['id'],$aid),['owner','admin','instructor'],true)) fail('Enrollment required',403); $q=db()->prepare('SELECT * FROM lessons WHERE course_id=? ORDER BY section_id,sort_order,id');$q->execute([$cid]);ok(['course'=>course($cid),'lessons'=>$q->fetchAll()]);}
      if($m==='POST' && ($seg[4]??'')==='progress'){ $d=body();require_fields($d,['lesson_id']);$lid=(int)$d['lesson_id'];$q=db()->prepare('INSERT INTO lesson_progress(academy_id,user_id,course_id,lesson_id,completed,watched_seconds,last_position,completed_at,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?) ON CONFLICT(user_id,lesson_id) DO UPDATE SET completed=EXCLUDED.completed,watched_seconds=EXCLUDED.watched_seconds,last_position=EXCLUDED.last_position,completed_at=EXCLUDED.completed_at,updated_at=NOW()');$completed=!empty($d['completed']);$q->execute([$aid,$u['id'],$cid,$lid,$completed,(int)($d['watched_seconds']??0),(int)($d['last_position']??0),$completed?now():null,now(),now()]);ok(['saved'=>true]);}
    }
    if($action==='orders' && $m==='GET'){ $q=db()->prepare('SELECT * FROM orders WHERE academy_id=? AND user_id=? ORDER BY id DESC');$q->execute([$aid,$u['id']]);ok($q->fetchAll());}
    if($action==='certificates' && $m==='GET'){ $q=db()->prepare('SELECT c.*,co.title course_title FROM certificates c JOIN courses co ON co.id=c.course_id WHERE c.academy_id=? AND c.user_id=? ORDER BY c.id DESC');$q->execute([$aid,$u['id']]);ok($q->fetchAll());}
    if($action==='checkout' && $m==='POST'){ $d=body();require_fields($d,['course_id']);$cid=(int)$d['course_id'];$c=course($cid);if((int)$c['academy_id']!==$aid)fail('Course not found',404);$amount=(float)$c['price'];if($amount<=0){db()->prepare("INSERT INTO enrollments(academy_id,course_id,user_id,status,enrolled_at,created_at,updated_at) VALUES(?,?,?,?,?,?,?) ON CONFLICT(course_id,user_id) DO UPDATE SET status='active',updated_at=NOW()")->execute([$aid,$cid,$u['id'],'active',now(),now(),now()]);ok(['status'=>'paid','amount'=>0]);} $pdo=db();$pdo->beginTransaction();try{$q=$pdo->prepare('INSERT INTO orders(academy_id,user_id,subtotal,discount,amount,currency,status,payment_method,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?) RETURNING id');$q->execute([$aid,$u['id'],$amount,0,$amount,$academy['currency']??'USD','pending','fake',now(),now()]);$oid=(int)$q->fetchColumn();$pdo->prepare('INSERT INTO order_items(academy_id,order_id,course_id,title,price,created_at,updated_at) VALUES(?,?,?,?,?,?,?)')->execute([$aid,$oid,$cid,$c['title'],$amount,now(),now()]);$pdo->prepare("UPDATE orders SET status='paid',paid_at=NOW(),transaction_id=? WHERE id=?")->execute(['fake_'.bin2hex(random_bytes(6)),$oid]);$pdo->prepare('INSERT INTO payments(academy_id,order_id,provider,status,amount,currency,transaction_id,payload,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?)')->execute([$aid,$oid,'fake','succeeded',$amount,$academy['currency']??'USD','fake_'.bin2hex(random_bytes(6)),json_encode([]),now(),now()]);$pdo->prepare("INSERT INTO enrollments(academy_id,course_id,user_id,status,enrolled_at,created_at,updated_at) VALUES(?,?,?,?,?,?,?) ON CONFLICT(course_id,user_id) DO UPDATE SET status='active',updated_at=NOW()")->execute([$aid,$cid,$u['id'],'active',now(),now(),now()]);$pdo->commit();ok(['order_id'=>$oid,'status'=>'paid']);}catch(Throwable $e){$pdo->rollBack();throw $e;}}
  }
  fail('Route not found',404);
}

try {
  if(str_starts_with(parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH)??'/','/api/')) { api(); }
  $path=parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH)??'/';
  if($path==='/health') { header('Content-Type: application/json'); echo json_encode(['status'=>'ok','php'=>PHP_VERSION]); exit; }
  require __DIR__.'/../src/page.php';
} catch(Throwable $e) {
  if(str_starts_with(parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH)??'/','/api/')) fail(envv('APP_DEBUG','false')==='true'?$e->getMessage():'Server error',500);
  http_response_code(500); echo '<h1>Server error</h1><pre>'.htmlspecialchars($e->getMessage()).'</pre>';
}
