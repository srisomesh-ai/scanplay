<?php
/**
 * ScanPlay backend — users, plans, accounts, items, payments, analytics
 * Storage: SQLite at data/scanplay.db + files at data/{code}/...
 */
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, X-Token, X-Admin-Pass');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit;

/* ---------------- CONFIG ---------------- */
define('OWNER_PASS',   'arAdmin@2026');                 // owner/admin panel password — change
define('CRON_KEY',     'sp-cron-7c1e9a');               // for ?action=cron&key=
define('RZP_KEY_ID',   'rzp_test_XXXXXXXXXXXX');        // Razorpay key id
define('RZP_KEY_SECRET','XXXXXXXXXXXXXXXXXXXXXXXX');    // Razorpay key secret
define('DATA_DIR', __DIR__ . '/data');
define('MAX_VIDEO_MB', 200);
define('GRACE_DAYS', 30);
/* Email (Hostinger mailbox) — fill in once noreply@scanplay.in exists */
define('SMTP_HOST', 'smtp.hostinger.com'); define('SMTP_PORT', 465);
define('SMTP_USER', 'info@scanplay.in'); define('SMTP_PASS', 'Simhadriappanna@143');
define('MAIL_FROM_NAME', 'ScanPlay');
/* Google sign-in — OAuth client ID from console.cloud.google.com (authorised origin: https://scanplay.in) */
define('GOOGLE_CLIENT_ID', 'CHANGE_ME.apps.googleusercontent.com');

/* plan => [photos, accounts (0 = unlimited), logo, analytics, sublogins, domain, price_month, price_year, watermark] */
const PLANS = [
  'free'     => ['name'=>'Free trial','photos'=>3, 'accounts'=>1, 'logo'=>false,'analytics'=>false,'sub'=>false,'domain'=>false,'month'=>0,    'year'=>0,     'watermark'=>true],
  'personal' => ['name'=>'Personal',  'photos'=>5, 'accounts'=>1, 'logo'=>false,'analytics'=>false,'sub'=>false,'domain'=>false,'month'=>999,  'year'=>4999,  'watermark'=>false],
  'business' => ['name'=>'Business',  'photos'=>15,'accounts'=>3, 'logo'=>true, 'analytics'=>true, 'sub'=>false,'domain'=>false,'month'=>2999, 'year'=>24999, 'watermark'=>false],
  'pro'      => ['name'=>'Pro',       'photos'=>30,'accounts'=>10,'logo'=>true, 'analytics'=>true, 'sub'=>true, 'domain'=>false,'month'=>5999, 'year'=>49999, 'watermark'=>false],
  'agency'   => ['name'=>'Agency',    'photos'=>50,'accounts'=>0, 'logo'=>true, 'analytics'=>true, 'sub'=>true, 'domain'=>true, 'month'=>9999, 'year'=>84999, 'watermark'=>false],
];

/* ---------------- DB ---------------- */
if (!is_dir(DATA_DIR)) mkdir(DATA_DIR, 0755, true);
try { $db = new PDO('sqlite:' . DATA_DIR . '/scanplay.db'); $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); }
catch (Exception $e) { echo json_encode(['ok'=>false,'error'=>'Database unavailable: '.$e->getMessage()]); exit; }
$db->exec("PRAGMA journal_mode=WAL");
$db->exec("CREATE TABLE IF NOT EXISTS users (id INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT UNIQUE, pass TEXT, name TEXT, phone TEXT,
  plan TEXT DEFAULT 'free', plan_until INTEGER, created INTEGER, token TEXT, logo INTEGER DEFAULT 0, deleted INTEGER DEFAULT 0)");
foreach (['verified INTEGER DEFAULT 0','code TEXT','code_exp INTEGER','google_id TEXT'] as $col) { try { $db->exec("ALTER TABLE users ADD COLUMN $col"); } catch (Exception $e) {} }
$db->exec("CREATE TABLE IF NOT EXISTS accounts (code TEXT PRIMARY KEY, user_id INTEGER, name TEXT, created INTEGER)");
$db->exec("CREATE TABLE IF NOT EXISTS items (id TEXT PRIMARY KEY, code TEXT, title TEXT, ratio REAL, vratio REAL, fit TEXT, created INTEGER)");
$db->exec("CREATE TABLE IF NOT EXISTS scans (code TEXT, day TEXT, n INTEGER, PRIMARY KEY(code,day))");
$db->exec("CREATE TABLE IF NOT EXISTS payments (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, order_id TEXT, payment_id TEXT, plan TEXT, period TEXT, amount INTEGER, status TEXT, created INTEGER)");

/* ---------------- helpers ---------------- */
function out($ok, $data = []) { echo json_encode(['ok' => $ok] + $data); exit; }
function clean($s) { return preg_replace('/[^a-z0-9]/', '', strtolower($s)); }
function rrmdir($d) { if (!is_dir($d)) return; foreach (scandir($d) as $f) { if ($f==='.'||$f==='..') continue; $p="$d/$f"; is_dir($p)?rrmdir($p):unlink($p); } rmdir($d); }
function baseUrl() { $s=(!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off')?'https':'http'; return $s.'://'.$_SERVER['HTTP_HOST'].rtrim(dirname($_SERVER['SCRIPT_NAME']),'/\\'); }
function q($sql, $p = []) { global $db; $st = $db->prepare($sql); $st->execute($p); return $st; }
function row($sql, $p = []) { return q($sql,$p)->fetch(PDO::FETCH_ASSOC); }
function rows($sql, $p = []) { return q($sql,$p)->fetchAll(PDO::FETCH_ASSOC); }
function now() { return time(); }
function mailConfigured() { return SMTP_PASS !== 'CHANGE_ME'; }
/* Minimal SMTP client (SSL) — no dependencies */
function sendMail($to, $subject, $html) {
  if (!mailConfigured()) return false;
  $fp = @stream_socket_client('ssl://'.SMTP_HOST.':'.SMTP_PORT, $errno, $errstr, 15); if (!$fp) return false;
  $read = function() use ($fp) { $r=''; while ($l=fgets($fp,515)) { $r.=$l; if (substr($l,3,1)===' ') break; } return $r; };
  $cmd  = function($c) use ($fp,$read) { fwrite($fp, $c."\r\n"); return $read(); };
  $read(); $cmd('EHLO scanplay.in'); $cmd('AUTH LOGIN'); $cmd(base64_encode(SMTP_USER)); $r=$cmd(base64_encode(SMTP_PASS));
  if (strpos($r,'235')!==0) { fclose($fp); return false; }
  $cmd('MAIL FROM:<'.SMTP_USER.'>'); $cmd('RCPT TO:<'.$to.'>'); $cmd('DATA');
  $msg = "From: ".MAIL_FROM_NAME." <".SMTP_USER.">\r\nTo: <$to>\r\nSubject: =?UTF-8?B?".base64_encode($subject)."?=\r\nMIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n".chunk_split(base64_encode($html));
  $r=$cmd($msg."\r\n."); $cmd('QUIT'); fclose($fp); return strpos($r,'250')===0;
}
function codeMail($name, $code, $what) {
  return "<div style='font-family:Arial,sans-serif;max-width:480px;margin:auto;padding:28px;border:1px solid #eee;border-radius:14px'>
  <div style='font-size:20px;font-weight:700;color:#7C3AED'>ScanPlay</div><p>Hi ".htmlspecialchars($name).",</p><p>Your $what code is:</p>
  <div style='font-size:34px;font-weight:800;letter-spacing:8px;background:#F6F3FF;padding:16px;text-align:center;border-radius:10px'>$code</div>
  <p style='color:#666;font-size:13px'>It expires in 15 minutes. If you didn't request this, ignore this email.</p></div>";
}
function issueCode($u, $what) {
  $code = str_pad((string)random_int(0,999999), 6, '0', STR_PAD_LEFT);
  q("UPDATE users SET code=?, code_exp=? WHERE id=?", [password_hash($code, PASSWORD_DEFAULT), now()+900, $u['id']]);
  return sendMail($u['email'], "ScanPlay $what code: $code", codeMail($u['name'], $code, $what));
}
function checkCode($u, $code) { return $u['code'] && (int)$u['code_exp'] > now() && password_verify(trim($code), $u['code']); }
function issueToken($id) { $t = bin2hex(random_bytes(24)); q("UPDATE users SET token=?, code=NULL WHERE id=?", [$t, $id]); return $t; }

/* plan state for a user: active | grace | expired */
function planState($u) {
  $until = (int)$u['plan_until'];
  if (now() <= $until) return 'active';
  if (now() <= $until + GRACE_DAYS*86400) return 'grace';
  return 'expired';
}
function userInfo($u) {
  $p = PLANS[$u['plan']] ?? PLANS['free'];
  $photos = (int)row("SELECT COUNT(*) c FROM items i JOIN accounts a ON a.code=i.code WHERE a.user_id=?", [$u['id']])['c'];
  $accs   = (int)row("SELECT COUNT(*) c FROM accounts WHERE user_id=?", [$u['id']])['c'];
  return ['id'=>(int)$u['id'],'email'=>$u['email'],'name'=>$u['name'],'phone'=>$u['phone'],'plan'=>$u['plan'],'planName'=>$p['name'],
    'planUntil'=>(int)$u['plan_until'],'state'=>planState($u),'limits'=>$p,'used'=>['photos'=>$photos,'accounts'=>$accs],
    'logo'=>$u['logo'] ? "data/users/{$u['id']}/logo.png?v={$u['logo']}" : null, 'graceDays'=>GRACE_DAYS];
}
function auth() {
  $t = $_SERVER['HTTP_X_TOKEN'] ?? ($_POST['token'] ?? '');
  if (!$t) out(false, ['error'=>'Please sign in','auth'=>true]);
  $u = row("SELECT * FROM users WHERE token=? AND deleted=0", [$t]);
  if (!$u) out(false, ['error'=>'Session expired, please sign in again','auth'=>true]);
  return $u;
}
function requireWritable($u) {
  $s = planState($u);
  if ($s !== 'active') out(false, ['error'=> $s==='grace' ? 'Your plan has ended. Renew to add or change pictures.' : 'Your plan expired and data was removed. Choose a plan to start again.', 'upgrade'=>true]);
}
function ownerAuth() { if (($_SERVER['HTTP_X_ADMIN_PASS'] ?? '') !== OWNER_PASS) out(false, ['error'=>'Not allowed']); }
function pubAccount($a) {
  $items = rows("SELECT * FROM items WHERE code=? ORDER BY created", [$a['code']]);
  foreach ($items as &$it) $it['thumb'] = "data/{$a['code']}/{$it['id']}/target.jpg";
  $scans = (int)(row("SELECT SUM(n) s FROM scans WHERE code=?", [$a['code']])['s'] ?? 0);
  $scans30 = (int)(row("SELECT SUM(n) s FROM scans WHERE code=? AND day>=?", [$a['code'], date('Y-m-d', now()-30*86400)])['s'] ?? 0);
  return ['code'=>$a['code'],'name'=>$a['name'],'created'=>(int)$a['created'],'items'=>$items,'qrUrl'=>baseUrl().'/view.html?c='.$a['code'],'scans'=>$scans,'scans30'=>$scans30];
}

$action = $_REQUEST['action'] ?? '';
if ($_SERVER['REQUEST_METHOD']==='POST' && !$_POST && !$_FILES && ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0)
  out(false, ['error'=>'Upload too large for server ('.round($_SERVER['CONTENT_LENGTH']/1048576).' MB). Use a smaller video.']);

switch ($action) {

  /* ---------- auth ---------- */
  case 'config': out(true, ['google'=> GOOGLE_CLIENT_ID !== 'CHANGE_ME.apps.googleusercontent.com' ? GOOGLE_CLIENT_ID : null, 'mail'=>mailConfigured()]);

  case 'signup': {
    $email = strtolower(trim($_POST['email'] ?? '')); $pass = $_POST['pass'] ?? ''; $name = trim(strip_tags($_POST['name'] ?? '')); $phone = preg_replace('/\D/','',$_POST['phone'] ?? '');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) out(false, ['error'=>'Enter a valid email']);
    if (strlen($pass) < 6) out(false, ['error'=>'Password must be at least 6 characters']);
    if ($name === '') out(false, ['error'=>'Enter your name']);
    $ex = row("SELECT * FROM users WHERE email=?", [$email]);
    if ($ex && $ex['verified']) out(false, ['error'=>'An account with this email already exists. Sign in instead.']);
    if ($ex) q("UPDATE users SET pass=?, name=?, phone=? WHERE id=?", [password_hash($pass, PASSWORD_DEFAULT), $name, $phone, $ex['id']]);
    else q("INSERT INTO users (email,pass,name,phone,plan,plan_until,created,verified) VALUES (?,?,?,?,'free',?,?,0)", [$email, password_hash($pass, PASSWORD_DEFAULT), $name, $phone, now()+7*86400, now()]);
    $u = row("SELECT * FROM users WHERE email=?", [$email]);
    if (!mailConfigured()) { q("UPDATE users SET verified=1 WHERE id=?", [$u['id']]); out(true, ['token'=>issueToken($u['id']), 'user'=>userInfo(row("SELECT * FROM users WHERE id=?", [$u['id']]))]); }
    issueCode($u, 'verification'); out(true, ['needVerify'=>true, 'email'=>$email]);
  }
  case 'verify': {
    $email = strtolower(trim($_POST['email'] ?? '')); $u = row("SELECT * FROM users WHERE email=? AND deleted=0", [$email]);
    if (!$u || !checkCode($u, $_POST['code'] ?? '')) out(false, ['error'=>'Wrong or expired code']);
    q("UPDATE users SET verified=1, plan_until=MAX(plan_until, ?) WHERE id=?", [now()+7*86400, $u['id']]);
    out(true, ['token'=>issueToken($u['id']), 'user'=>userInfo(row("SELECT * FROM users WHERE id=?", [$u['id']]))]);
  }
  case 'resend': {
    $email = strtolower(trim($_POST['email'] ?? '')); $u = row("SELECT * FROM users WHERE email=? AND deleted=0", [$email]);
    if ($u) issueCode($u, isset($_POST['reset']) ? 'password reset' : 'verification'); out(true);
  }
  case 'login': {
    $email = strtolower(trim($_POST['email'] ?? '')); $pass = $_POST['pass'] ?? '';
    $u = row("SELECT * FROM users WHERE email=? AND deleted=0", [$email]);
    if (!$u || !$u['pass'] || !password_verify($pass, $u['pass'])) out(false, ['error'=>'Wrong email or password']);
    if (!$u['verified'] && mailConfigured()) { issueCode($u, 'verification'); out(true, ['needVerify'=>true, 'email'=>$email]); }
    out(true, ['token'=>issueToken($u['id']), 'user'=>userInfo($u)]);
  }
  case 'forgot': {
    $email = strtolower(trim($_POST['email'] ?? '')); $u = row("SELECT * FROM users WHERE email=? AND deleted=0", [$email]);
    if (!mailConfigured()) out(false, ['error'=>'Password reset by email is not set up yet. Message us on WhatsApp.']);
    if ($u) issueCode($u, 'password reset'); out(true);   // same answer whether or not the email exists
  }
  case 'reset': {
    $email = strtolower(trim($_POST['email'] ?? '')); $u = row("SELECT * FROM users WHERE email=? AND deleted=0", [$email]);
    if (!$u || !checkCode($u, $_POST['code'] ?? '')) out(false, ['error'=>'Wrong or expired code']);
    if (strlen($_POST['newpass'] ?? '') < 6) out(false, ['error'=>'Password must be at least 6 characters']);
    q("UPDATE users SET pass=?, verified=1 WHERE id=?", [password_hash($_POST['newpass'], PASSWORD_DEFAULT), $u['id']]);
    out(true, ['token'=>issueToken($u['id']), 'user'=>userInfo(row("SELECT * FROM users WHERE id=?", [$u['id']]))]);
  }
  case 'google': {
    $cred = $_POST['credential'] ?? ''; if (!$cred) out(false, ['error'=>'No Google credential']);
    $info = json_decode(@file_get_contents('https://oauth2.googleapis.com/tokeninfo?id_token='.urlencode($cred)), true);
    if (!$info || ($info['aud'] ?? '') !== GOOGLE_CLIENT_ID || empty($info['email']) || ($info['email_verified'] ?? 'false') !== 'true') out(false, ['error'=>'Google sign-in could not be verified']);
    $email = strtolower($info['email']); $u = row("SELECT * FROM users WHERE email=?", [$email]);
    if (!$u) { q("INSERT INTO users (email,pass,name,phone,plan,plan_until,created,verified,google_id) VALUES (?,NULL,?,'','free',?,?,1,?)", [$email, $info['name'] ?? $email, now()+7*86400, now(), $info['sub']]); $u = row("SELECT * FROM users WHERE email=?", [$email]); }
    else q("UPDATE users SET verified=1, google_id=?, deleted=0 WHERE id=?", [$info['sub'], $u['id']]);
    out(true, ['token'=>issueToken($u['id']), 'user'=>userInfo(row("SELECT * FROM users WHERE id=?", [$u['id']]))]);
  }
  case 'logout': { $u = auth(); q("UPDATE users SET token=NULL WHERE id=?", [$u['id']]); out(true); }
  case 'me': { $u = auth(); out(true, ['user'=>userInfo($u)]); }
  case 'profile': {
    $u = auth(); $name = trim(strip_tags($_POST['name'] ?? $u['name'])); $phone = preg_replace('/\D/','',$_POST['phone'] ?? $u['phone']);
    q("UPDATE users SET name=?, phone=? WHERE id=?", [$name, $phone, $u['id']]);
    if (!empty($_POST['newpass'])) { if (strlen($_POST['newpass'])<6) out(false,['error'=>'Password too short']); q("UPDATE users SET pass=? WHERE id=?", [password_hash($_POST['newpass'], PASSWORD_DEFAULT), $u['id']]); }
    out(true, ['user'=>userInfo(row("SELECT * FROM users WHERE id=?", [$u['id']]))]);
  }
  case 'logo': {
    $u = auth(); requireWritable($u);
    if (!(PLANS[$u['plan']]['logo'] ?? false)) out(false, ['error'=>'Your plan does not include a custom logo. Upgrade to Business or above.','upgrade'=>true]);
    if (empty($_FILES['logo'])) { q("UPDATE users SET logo=0 WHERE id=?", [$u['id']]); @unlink(DATA_DIR."/users/{$u['id']}/logo.png"); out(true, ['user'=>userInfo(row("SELECT * FROM users WHERE id=?", [$u['id']]))]); }
    $dir = DATA_DIR."/users/{$u['id']}"; if (!is_dir($dir)) mkdir($dir, 0755, true);
    $img = @imagecreatefromstring(file_get_contents($_FILES['logo']['tmp_name'])); if (!$img) out(false, ['error'=>'Logo must be a PNG or JPG']);
    $w=imagesx($img); $h=imagesy($img); $s=min(1, 400/max($w,$h)); $nw=(int)($w*$s); $nh=(int)($h*$s);
    $dst=imagecreatetruecolor($nw,$nh); imagealphablending($dst,false); imagesavealpha($dst,true); imagecopyresampled($dst,$img,0,0,0,0,$nw,$nh,$w,$h);
    imagepng($dst, "$dir/logo.png"); q("UPDATE users SET logo=? WHERE id=?", [now(), $u['id']]);
    out(true, ['user'=>userInfo(row("SELECT * FROM users WHERE id=?", [$u['id']]))]);
  }

  /* ---------- accounts ---------- */
  case 'accounts': {
    $u = auth();
    $list = array_map('pubAccount', rows("SELECT * FROM accounts WHERE user_id=? ORDER BY created", [$u['id']]));
    out(true, ['accounts'=>$list, 'user'=>userInfo($u)]);
  }
  case 'account_create': {
    $u = auth(); requireWritable($u);
    $name = trim(strip_tags($_POST['name'] ?? '')); if ($name==='') out(false, ['error'=>'Account name is required']);
    $lim = PLANS[$u['plan']]['accounts']; $have = (int)row("SELECT COUNT(*) c FROM accounts WHERE user_id=?", [$u['id']])['c'];
    if ($lim && $have >= $lim) out(false, ['error'=>"Your plan allows $lim account".($lim>1?'s':'').". Upgrade for more.", 'upgrade'=>true]);
    $code = substr(bin2hex(random_bytes(4)),0,8);
    q("INSERT INTO accounts (code,user_id,name,created) VALUES (?,?,?,?)", [$code, $u['id'], $name, now()]);
    mkdir(DATA_DIR."/$code", 0755, true);
    out(true, ['code'=>$code]);
  }
  case 'account_delete': {
    $u = auth(); $code = clean($_POST['code'] ?? '');
    if (!row("SELECT code FROM accounts WHERE code=? AND user_id=?", [$code, $u['id']])) out(false, ['error'=>'Account not found']);
    q("DELETE FROM items WHERE code=?", [$code]); q("DELETE FROM scans WHERE code=?", [$code]); q("DELETE FROM accounts WHERE code=?", [$code]);
    rrmdir(DATA_DIR."/$code"); out(true);
  }

  /* ---------- chunked video upload (background, resumable) ---------- */
  case 'up_start': {
    $u = auth(); requireWritable($u);
    $size = (int)($_POST['size'] ?? 0); if ($size <= 0 || $size > MAX_VIDEO_MB*1048576) out(false, ['error'=>'Video must be under '.MAX_VIDEO_MB.' MB']);
    $id = 'u'.$u['id'].'_'.bin2hex(random_bytes(6)); $dir = DATA_DIR.'/tmp'; if (!is_dir($dir)) mkdir($dir, 0755, true);
    file_put_contents("$dir/$id.part", ''); file_put_contents("$dir/$id.json", json_encode(['user'=>$u['id'],'size'=>$size,'t'=>now()]));
    // sweep temp files older than 1 day
    foreach (glob("$dir/*.part") as $f) if (filemtime($f) < now()-86400) { @unlink($f); @unlink(substr($f,0,-5).'.json'); }
    out(true, ['id'=>$id]);
  }
  case 'up_chunk': {
    $u = auth(); $id = preg_replace('/[^a-z0-9_]/','',$_POST['id'] ?? ''); $off = (int)($_POST['offset'] ?? -1);
    $meta = @json_decode(file_get_contents(DATA_DIR."/tmp/$id.json"), true);
    if (!$meta || $meta['user'] != $u['id'] || empty($_FILES['chunk'])) out(false, ['error'=>'Bad upload chunk']);
    $f = DATA_DIR."/tmp/$id.part"; $have = filesize($f);
    if ($off !== $have) out(true, ['received'=>$have]);              // client resyncs to what we have
    file_put_contents($f, file_get_contents($_FILES['chunk']['tmp_name']), FILE_APPEND);
    out(true, ['received'=>filesize($f)]);
  }
  case 'up_status': {
    $u = auth(); $id = preg_replace('/[^a-z0-9_]/','',$_POST['id'] ?? ''); $f = DATA_DIR."/tmp/$id.part";
    out(true, ['received'=>file_exists($f)?filesize($f):0]);
  }

  /* ---------- items ---------- */
  case 'item_add': {
    $u = auth(); requireWritable($u); $code = clean($_POST['code'] ?? '');
    if (!row("SELECT code FROM accounts WHERE code=? AND user_id=?", [$code, $u['id']])) out(false, ['error'=>'Account not found']);
    $lim = PLANS[$u['plan']]['photos']; $have = (int)row("SELECT COUNT(*) c FROM items i JOIN accounts a ON a.code=i.code WHERE a.user_id=?", [$u['id']])['c'];
    if ($have >= $lim) out(false, ['error'=>"Your plan allows $lim photos. Upgrade to add more.", 'upgrade'=>true]);
    $videoUrl = trim($_POST['video_url'] ?? ''); $upId = preg_replace('/[^a-z0-9_]/','',$_POST['video_id'] ?? '');
    $upFile = $upId ? DATA_DIR."/tmp/$upId.part" : '';
    if ($upId) { $meta=@json_decode(file_get_contents(DATA_DIR."/tmp/$upId.json"),true); if(!$meta||$meta['user']!=$u['id']||!file_exists($upFile)||filesize($upFile)!=$meta['size']) out(false,['error'=>'Video upload incomplete — please try again']); }
    if (empty($_FILES['mind']) || empty($_FILES['target']) || (empty($_FILES['video']) && $videoUrl==='' && !$upId))
      out(false, ['error'=>'Photo, a video (file or URL) and compiled file are all required']);
    if (!empty($_FILES['video']) && $_FILES['video']['size'] > MAX_VIDEO_MB*1048576) out(false, ['error'=>"Video must be under ".MAX_VIDEO_MB." MB"]);
    if ($videoUrl!=='' && !preg_match('#^https?://#i', $videoUrl)) out(false, ['error'=>'Video URL must start with http:// or https://']);

    $id = substr(bin2hex(random_bytes(5)),0,8); $dir = DATA_DIR."/$code/$id"; mkdir($dir, 0755, true);
    move_uploaded_file($_FILES['target']['tmp_name'], "$dir/target.jpg");
    if ($upId) { rename($upFile, "$dir/video.mp4"); @unlink(DATA_DIR."/tmp/$upId.json"); }
    elseif (!empty($_FILES['video'])) move_uploaded_file($_FILES['video']['tmp_name'], "$dir/video.mp4");
    else {
      set_time_limit(600); $fp=fopen("$dir/video.mp4",'w'); $ch=curl_init($videoUrl);
      curl_setopt_array($ch,[CURLOPT_FILE=>$fp,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_MAXREDIRS=>5,CURLOPT_TIMEOUT=>560,CURLOPT_USERAGENT=>'Mozilla/5.0 ScanPlay/1.0',CURLOPT_MAXFILESIZE=>MAX_VIDEO_MB*1048576]);
      $okDl=curl_exec($ch); $http=curl_getinfo($ch,CURLINFO_HTTP_CODE); $ctype=curl_getinfo($ch,CURLINFO_CONTENT_TYPE)?:''; $err=curl_error($ch); curl_close($ch); fclose($fp);
      if (!$okDl || $http>=400 || filesize("$dir/video.mp4")<10000 || stripos($ctype,'text/html')!==false) { rrmdir($dir); out(false, ['error'=>'Could not download video from that URL'.($err?" ($err)":'').'. It must be a direct link to an MP4 file.']); }
    }
    move_uploaded_file($_FILES['mind']['tmp_name'], DATA_DIR."/$code/targets.mind");
    q("INSERT INTO items (id,code,title,ratio,vratio,fit,created) VALUES (?,?,?,?,?,?,?)",
      [$id, $code, trim(strip_tags($_POST['title'] ?? 'Untitled')), (float)($_POST['ratio']??1), (float)($_POST['vratio']??1), in_array($_POST['fit']??'',['fill','fit','stretch'])?$_POST['fit']:'fill', now()]);
    out(true, ['id'=>$id]);
  }
  case 'item_delete': {
    $u = auth(); $code = clean($_POST['code'] ?? ''); $id = clean($_POST['id'] ?? '');
    if (!row("SELECT code FROM accounts WHERE code=? AND user_id=?", [$code, $u['id']])) out(false, ['error'=>'Account not found']);
    q("DELETE FROM items WHERE id=? AND code=?", [$id, $code]); rrmdir(DATA_DIR."/$code/$id");
    $mind = DATA_DIR."/$code/targets.mind";
    if (!empty($_FILES['mind'])) move_uploaded_file($_FILES['mind']['tmp_name'], $mind);
    elseif (!row("SELECT id FROM items WHERE code=?", [$code]) && file_exists($mind)) unlink($mind);
    out(true);
  }

  /* ---------- public player ---------- */
  case 'get': {
    $code = clean($_GET['c'] ?? '');
    $a = row("SELECT a.*, u.plan, u.plan_until, u.logo, u.id uid FROM accounts a JOIN users u ON u.id=a.user_id WHERE a.code=? AND u.deleted=0", [$code]);
    if (!$a) out(false, ['error'=>'This QR is not linked to any account']);
    $state = planState($a);
    if ($state === 'expired') out(false, ['error'=>'This experience has expired']);
    $items = rows("SELECT id,title,ratio,vratio,fit FROM items WHERE code=? ORDER BY created", [$code]);
    foreach ($items as &$it) $it['video'] = "data/$code/{$it['id']}/video.mp4";
    if (!$items) out(false, ['error'=>'This account has no pictures yet']);
    $p = PLANS[$a['plan']] ?? PLANS['free'];
    q("INSERT INTO scans (code,day,n) VALUES (?,?,1) ON CONFLICT(code,day) DO UPDATE SET n=n+1", [$code, date('Y-m-d')]);
    out(true, ['name'=>$a['name'], 'mind'=>"data/$code/targets.mind", 'items'=>$items,
      'watermark'=>$p['watermark'], 'logo'=>($p['logo'] && $a['logo']) ? "data/users/{$a['uid']}/logo.png?v={$a['logo']}" : null]);
  }

  /* ---------- payments (Razorpay Orders) ---------- */
  case 'pay_create': {
    $u = auth(); $plan = $_POST['plan'] ?? ''; $period = ($_POST['period'] ?? 'month')==='year' ? 'year' : 'month';
    if (!isset(PLANS[$plan]) || $plan==='free') out(false, ['error'=>'Choose a paid plan']);
    $amount = PLANS[$plan][$period] * 100;
    $ch = curl_init('https://api.razorpay.com/v1/orders');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_USERPWD=>RZP_KEY_ID.':'.RZP_KEY_SECRET, CURLOPT_POST=>true,
      CURLOPT_HTTPHEADER=>['Content-Type: application/json'],
      CURLOPT_POSTFIELDS=>json_encode(['amount'=>$amount,'currency'=>'INR','receipt'=>"u{$u['id']}-".now(),'notes'=>['user'=>$u['id'],'plan'=>$plan,'period'=>$period]])]);
    $res = json_decode(curl_exec($ch), true); curl_close($ch);
    if (empty($res['id'])) out(false, ['error'=>'Could not start payment: '.($res['error']['description'] ?? 'Razorpay not configured')]);
    q("INSERT INTO payments (user_id,order_id,plan,period,amount,status,created) VALUES (?,?,?,?,?,'created',?)", [$u['id'], $res['id'], $plan, $period, $amount, now()]);
    out(true, ['order_id'=>$res['id'], 'amount'=>$amount, 'key'=>RZP_KEY_ID, 'name'=>$u['name'], 'email'=>$u['email'], 'phone'=>$u['phone'], 'label'=>PLANS[$plan]['name'].' · '.($period==='year'?'1 year':'1 month')]);
  }
  case 'pay_verify': {
    $u = auth(); $oid=$_POST['order_id']??''; $pid=$_POST['payment_id']??''; $sig=$_POST['signature']??'';
    $pay = row("SELECT * FROM payments WHERE order_id=? AND user_id=?", [$oid, $u['id']]); if (!$pay) out(false, ['error'=>'Unknown order']);
    if (!hash_equals(hash_hmac('sha256', "$oid|$pid", RZP_KEY_SECRET), $sig)) out(false, ['error'=>'Payment verification failed']);
    if ($pay['status'] !== 'paid') {
      q("UPDATE payments SET payment_id=?, status='paid' WHERE id=?", [$pid, $pay['id']]);
      $add = $pay['period']==='year' ? 365*86400 : 30*86400;
      $base = ($u['plan']===$pay['plan'] && (int)$u['plan_until'] > now()) ? (int)$u['plan_until'] : now();   // extend if same plan still active
      q("UPDATE users SET plan=?, plan_until=? WHERE id=?", [$pay['plan'], $base+$add, $u['id']]);
    }
    out(true, ['user'=>userInfo(row("SELECT * FROM users WHERE id=?", [$u['id']]))]);
  }

  /* ---------- analytics ---------- */
  case 'stats': {
    $u = auth(); $code = clean($_GET['code'] ?? $_POST['code'] ?? '');
    if (!(PLANS[$u['plan']]['analytics'] ?? false)) out(false, ['error'=>'Analytics is included from the Business plan.','upgrade'=>true]);
    if (!row("SELECT code FROM accounts WHERE code=? AND user_id=?", [$code, $u['id']])) out(false, ['error'=>'Account not found']);
    out(true, ['days'=>rows("SELECT day, n FROM scans WHERE code=? AND day>=? ORDER BY day", [$code, date('Y-m-d', now()-30*86400)])]);
  }

  /* ---------- owner admin ---------- */
  case 'admin_users': {
    ownerAuth();
    $list = rows("SELECT u.*, (SELECT COUNT(*) FROM accounts a WHERE a.user_id=u.id) accs, (SELECT COUNT(*) FROM items i JOIN accounts a ON a.code=i.code WHERE a.user_id=u.id) photos FROM users u WHERE deleted=0 ORDER BY created DESC");
    foreach ($list as &$x) { unset($x['pass'],$x['token']); $x['state']=planState($x); }
    out(true, ['users'=>$list, 'plans'=>PLANS]);
  }
  case 'admin_setplan': {
    ownerAuth(); $id=(int)($_POST['id']??0); $plan=$_POST['plan']??''; $days=(int)($_POST['days']??30);
    if (!isset(PLANS[$plan])) out(false, ['error'=>'Bad plan']);
    q("UPDATE users SET plan=?, plan_until=? WHERE id=?", [$plan, now()+$days*86400, $id]); out(true);
  }

  /* ---------- cron: delete data after grace ---------- */
  case 'cron': {
    if (($_GET['key'] ?? '') !== CRON_KEY) out(false, ['error'=>'Bad key']);
    $cut = now() - GRACE_DAYS*86400; $n=0;
    foreach (rows("SELECT id FROM users WHERE plan_until < ? AND deleted=0", [$cut]) as $u) {
      foreach (rows("SELECT code FROM accounts WHERE user_id=?", [$u['id']]) as $a) { rrmdir(DATA_DIR."/{$a['code']}"); q("DELETE FROM items WHERE code=?", [$a['code']]); q("DELETE FROM scans WHERE code=?", [$a['code']]); $n++; }
      q("DELETE FROM accounts WHERE user_id=?", [$u['id']]);
    }
    out(true, ['accounts_removed'=>$n]);
  }

  default: out(false, ['error'=>'Unknown action']);
}
