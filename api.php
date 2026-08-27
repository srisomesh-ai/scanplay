<?php
/**
 * ScanPlay backend — users, plans, accounts, items, payments, analytics
 * Storage: SQLite at data/scanplay.db + files at data/{code}/...
 */
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, X-Token, X-Admin-Pass');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit;

/* ---------------- CONFIG ----------------
   Secrets live in config.php (NOT in git). Copy config.sample.php to config.php on the server and fill it in. */
$cfg = file_exists(__DIR__.'/config.php') ? include __DIR__.'/config.php' : [];
$cfg += [ 'owner_pass'=>'arAdmin@2026', 'cron_key'=>'sp-cron-7c1e9a', 'rzp_key_id'=>'rzp_test_Svq7brYQvxA6kz', 'rzp_key_secret'=>'vHNYS7qh04Fyklra0YbzB6Iy',
          'smtp_host'=>'smtp.hostinger.com', 'smtp_port'=>465, 'smtp_user'=>'info@scanplay.in', 'smtp_pass'=>'CHANGE_ME', 'mail_from_name'=>'ScanPlay',
          'google_client_id'=>'CHANGE_ME.apps.googleusercontent.com', 'rzp_webhook_secret'=>'' ];
define('OWNER_PASS', $cfg['owner_pass']); define('CRON_KEY', $cfg['cron_key']);
define('RZP_KEY_ID', $cfg['rzp_key_id']); define('RZP_KEY_SECRET', $cfg['rzp_key_secret']); define('RZP_WEBHOOK_SECRET', $cfg['rzp_webhook_secret']);
define('SMTP_HOST', $cfg['smtp_host']); define('SMTP_PORT', (int)$cfg['smtp_port']); define('SMTP_USER', $cfg['smtp_user']); define('SMTP_PASS', $cfg['smtp_pass']); define('MAIL_FROM_NAME', $cfg['mail_from_name']);
define('GOOGLE_CLIENT_ID', $cfg['google_client_id']);
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
  'personal' => ['name'=>'Personal',  'photos'=>5, 'accounts'=>1, 'logo'=>false,'analytics'=>false,'sub'=>false,'domain'=>false,'month'=>499,  'year'=>4990,  'watermark'=>false],
  'business' => ['name'=>'Business',  'photos'=>15,'accounts'=>3, 'logo'=>true, 'analytics'=>true, 'sub'=>false,'domain'=>false,'month'=>1999, 'year'=>19990, 'watermark'=>false],
  'pro'      => ['name'=>'Pro',       'photos'=>30,'accounts'=>10,'logo'=>true, 'analytics'=>true, 'sub'=>true, 'domain'=>false,'month'=>2999, 'year'=>29990, 'watermark'=>false],
  'agency'   => ['name'=>'Agency',    'photos'=>50,'accounts'=>0, 'logo'=>true, 'analytics'=>true, 'sub'=>true, 'domain'=>true, 'month'=>5999, 'year'=>59990, 'watermark'=>false],
];

/* ---------------- DB ---------------- */
if (!is_dir(DATA_DIR)) mkdir(DATA_DIR, 0755, true);
try { $db = new PDO('sqlite:' . DATA_DIR . '/scanplay.db'); $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); }
catch (Exception $e) { echo json_encode(['ok'=>false,'error'=>'Database unavailable: '.$e->getMessage()]); exit; }
$db->exec("PRAGMA journal_mode=WAL");
$db->exec("CREATE TABLE IF NOT EXISTS users (id INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT UNIQUE, pass TEXT, name TEXT, phone TEXT,
  plan TEXT DEFAULT 'free', plan_until INTEGER, created INTEGER, token TEXT, logo INTEGER DEFAULT 0, deleted INTEGER DEFAULT 0)");
foreach (['verified INTEGER DEFAULT 0','code TEXT','code_exp INTEGER','google_id TEXT','extra_photos INTEGER DEFAULT 0','extra_accounts INTEGER DEFAULT 0','note TEXT'] as $col) { try { $db->exec("ALTER TABLE users ADD COLUMN $col"); } catch (Exception $e) {} }
$db->exec("CREATE TABLE IF NOT EXISTS accounts (code TEXT PRIMARY KEY, user_id INTEGER, name TEXT, created INTEGER)");
try { $db->exec("ALTER TABLE accounts ADD COLUMN blocked INTEGER DEFAULT 0"); } catch (Exception $e) {}
$db->exec("CREATE TABLE IF NOT EXISTS items (id TEXT PRIMARY KEY, code TEXT, title TEXT, ratio REAL, vratio REAL, fit TEXT, created INTEGER)");
$db->exec("CREATE TABLE IF NOT EXISTS scans (code TEXT, day TEXT, n INTEGER, PRIMARY KEY(code,day))");
$db->exec("CREATE TABLE IF NOT EXISTS activity (id INTEGER PRIMARY KEY AUTOINCREMENT, ts INTEGER, user_id INTEGER, who TEXT, action TEXT, detail TEXT, ip TEXT)");
$db->exec("CREATE TABLE IF NOT EXISTS promos (code TEXT PRIMARY KEY, percent INTEGER DEFAULT 0, flat INTEGER DEFAULT 0, max_uses INTEGER DEFAULT 0, uses INTEGER DEFAULT 0, expires INTEGER DEFAULT 0, active INTEGER DEFAULT 1, note TEXT, created INTEGER)");
try { $db->exec("ALTER TABLE payments ADD COLUMN promo TEXT"); $db->exec("ALTER TABLE payments ADD COLUMN discount INTEGER DEFAULT 0"); } catch (Exception $e) {}
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
/* Turn share links into direct-download links; reject streaming sites */
function resolveVideoUrl($url) {
  if (preg_match('#(youtube\.com|youtu\.be|instagram\.com|facebook\.com|fb\.watch|tiktok\.com|vimeo\.com/\d)#i', $url))
    return [null, 'YouTube, Instagram, Facebook, TikTok and Vimeo links cannot be used — those sites do not provide video files. Download the clip and upload it, or put it on Google Drive and paste that link.'];
  if (preg_match('#drive\.google\.com/(?:file/d/|open\?id=|uc\?.*id=)([\w-]+)#', $url, $m)) return ["https://drive.google.com/uc?export=download&id={$m[1]}&confirm=t", null];
  if (preg_match('#drive\.google\.com/.*[?&]id=([\w-]+)#', $url, $m)) return ["https://drive.google.com/uc?export=download&id={$m[1]}&confirm=t", null];
  if (preg_match('#dropbox\.com/#', $url)) return [preg_replace('/[?&]dl=0/', '', $url).(strpos($url,'?')!==false?'&':'?').'dl=1', null];
  return [$url, null];
}
/* Promo: returns [promoRow, discountRupees, error] for a plan/period price */
function applyPromo($codeIn, $priceRupees) {
  $code = strtoupper(preg_replace('/[^A-Z0-9]/','', strtoupper($codeIn))); if ($code==='') return [null,0,null];
  $p = row("SELECT * FROM promos WHERE code=?", [$code]);
  if (!$p || !$p['active']) return [null,0,'That promo code is not valid'];
  if ($p['expires'] && now() > (int)$p['expires']) return [null,0,'That promo code has expired'];
  if ($p['max_uses'] && (int)$p['uses'] >= (int)$p['max_uses']) return [null,0,'That promo code has been fully used'];
  $d = (int)round($priceRupees * (int)$p['percent'] / 100) + (int)$p['flat'];
  $d = max(0, min($d, $priceRupees - 1));
  return [$p, $d, null];
}
function logAct($userId, $action, $detail='', $who='user') {
  q("INSERT INTO activity (ts,user_id,who,action,detail,ip) VALUES (?,?,?,?,?,?)", [now(), (int)$userId, $who, $action, mb_substr((string)$detail,0,200), $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '']);
}
function issueToken($id) { $t = bin2hex(random_bytes(24)); q("UPDATE users SET token=?, code=NULL WHERE id=?", [$t, $id]); return $t; }

/* plan state for a user: active | grace | expired */
function planState($u) {
  $until = (int)$u['plan_until'];
  if (now() <= $until) return 'active';
  if (now() <= $until + GRACE_DAYS*86400) return 'grace';
  return 'expired';
}
function userInfo($u) {
  $p = PLANS[$u['plan']] ?? PLANS['free']; $p['photos'] += (int)($u['extra_photos'] ?? 0); if ($p['accounts']) $p['accounts'] += (int)($u['extra_accounts'] ?? 0);
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
  return ['code'=>$a['code'],'name'=>$a['name'],'blocked'=>(int)($a['blocked']??0),'created'=>(int)$a['created'],'items'=>$items,'qrUrl'=>baseUrl().'/view.html?c='.$a['code'],'scans'=>$scans,'scans30'=>$scans30];
}

$action = $_REQUEST['action'] ?? '';
if ($_SERVER['REQUEST_METHOD']==='POST' && !$_POST && !$_FILES && ($_SERVER['CONTENT_LENGTH'] ?? 0) > 1048576)
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
    logAct($u['id'],'signup',$email);
    if (!mailConfigured()) { q("UPDATE users SET verified=1 WHERE id=?", [$u['id']]); out(true, ['token'=>issueToken($u['id']), 'user'=>userInfo(row("SELECT * FROM users WHERE id=?", [$u['id']]))]); }
    issueCode($u, 'verification'); out(true, ['needVerify'=>true, 'email'=>$email]);
  }
  case 'verify': {
    $email = strtolower(trim($_POST['email'] ?? '')); $u = row("SELECT * FROM users WHERE email=? AND deleted=0", [$email]);
    if (!$u || !checkCode($u, $_POST['code'] ?? '')) out(false, ['error'=>'Wrong or expired code']);
    q("UPDATE users SET verified=1, plan_until=MAX(plan_until, ?) WHERE id=?", [now()+7*86400, $u['id']]); logAct($u['id'],'verified');
    out(true, ['token'=>issueToken($u['id']), 'user'=>userInfo(row("SELECT * FROM users WHERE id=?", [$u['id']]))]);
  }
  case 'resend': {
    $email = strtolower(trim($_POST['email'] ?? '')); $u = row("SELECT * FROM users WHERE email=? AND deleted=0", [$email]);
    if ($u) issueCode($u, isset($_POST['reset']) ? 'password reset' : 'verification'); out(true);
  }
  case 'login': {
    $email = strtolower(trim($_POST['email'] ?? '')); $pass = $_POST['pass'] ?? '';
    $u = row("SELECT * FROM users WHERE email=? AND deleted=0", [$email]);
    if (!$u || !$u['pass'] || !password_verify($pass, $u['pass'])) { if ($u) logAct($u['id'],'login_failed'); out(false, ['error'=>'Wrong email or password']); }
    if (!$u['verified'] && mailConfigured()) { issueCode($u, 'verification'); out(true, ['needVerify'=>true, 'email'=>$email]); }
    logAct($u['id'],'login'); out(true, ['token'=>issueToken($u['id']), 'user'=>userInfo($u)]);
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
    q("UPDATE users SET pass=?, verified=1 WHERE id=?", [password_hash($_POST['newpass'], PASSWORD_DEFAULT), $u['id']]); logAct($u['id'],'password_reset');
    out(true, ['token'=>issueToken($u['id']), 'user'=>userInfo(row("SELECT * FROM users WHERE id=?", [$u['id']]))]);
  }
  case 'google': {
    $cred = $_POST['credential'] ?? ''; if (!$cred) out(false, ['error'=>'No Google credential']);
    $info = json_decode(@file_get_contents('https://oauth2.googleapis.com/tokeninfo?id_token='.urlencode($cred)), true);
    if (!$info || ($info['aud'] ?? '') !== GOOGLE_CLIENT_ID || empty($info['email']) || ($info['email_verified'] ?? 'false') !== 'true') out(false, ['error'=>'Google sign-in could not be verified']);
    $email = strtolower($info['email']); $u = row("SELECT * FROM users WHERE email=?", [$email]);
    if (!$u) { q("INSERT INTO users (email,pass,name,phone,plan,plan_until,created,verified,google_id) VALUES (?,NULL,?,'','free',?,?,1,?)", [$email, $info['name'] ?? $email, now()+7*86400, now(), $info['sub']]); $u = row("SELECT * FROM users WHERE email=?", [$email]); }
    else q("UPDATE users SET verified=1, google_id=?, deleted=0 WHERE id=?", [$info['sub'], $u['id']]);
    logAct($u['id'],'login_google',$email);
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
    $lim = PLANS[$u['plan']]['accounts'] ? PLANS[$u['plan']]['accounts'] + (int)$u['extra_accounts'] : 0; $have = (int)row("SELECT COUNT(*) c FROM accounts WHERE user_id=?", [$u['id']])['c'];
    if ($lim && $have >= $lim) out(false, ['error'=>"Your plan allows $lim account".($lim>1?'s':'').". Upgrade for more.", 'upgrade'=>true]);
    $code = substr(bin2hex(random_bytes(4)),0,8);
    q("INSERT INTO accounts (code,user_id,name,created) VALUES (?,?,?,?)", [$code, $u['id'], $name, now()]);
    mkdir(DATA_DIR."/$code", 0755, true); logAct($u['id'],'project_create',"$code $name");
    out(true, ['code'=>$code]);
  }
  case 'account_delete': {
    $u = auth(); $code = clean($_POST['code'] ?? '');
    if (!row("SELECT code FROM accounts WHERE code=? AND user_id=?", [$code, $u['id']])) out(false, ['error'=>'Account not found']);
    q("DELETE FROM items WHERE code=?", [$code]); q("DELETE FROM scans WHERE code=?", [$code]); q("DELETE FROM accounts WHERE code=?", [$code]);
    rrmdir(DATA_DIR."/$code"); logAct($u['id'],'project_delete',$code); out(true);
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
    $lim = PLANS[$u['plan']]['photos'] + (int)$u['extra_photos']; $have = (int)row("SELECT COUNT(*) c FROM items i JOIN accounts a ON a.code=i.code WHERE a.user_id=?", [$u['id']])['c'];
    if ($have >= $lim) out(false, ['error'=>"Your plan allows $lim photos. Upgrade to add more.", 'upgrade'=>true]);
    $videoUrl = trim($_POST['video_url'] ?? ''); $upId = preg_replace('/[^a-z0-9_]/','',$_POST['video_id'] ?? '');
    $upFile = $upId ? DATA_DIR."/tmp/$upId.part" : '';
    if ($upId) { $meta=@json_decode(file_get_contents(DATA_DIR."/tmp/$upId.json"),true); if(!$meta||$meta['user']!=$u['id']||!file_exists($upFile)||filesize($upFile)!=$meta['size']) out(false,['error'=>'Video upload incomplete — please try again']); }
    if (empty($_FILES['mind']) || empty($_FILES['target']) || (empty($_FILES['video']) && $videoUrl==='' && !$upId))
      out(false, ['error'=>'Photo, a video (file or URL) and compiled file are all required']);
    if (!empty($_FILES['video']) && $_FILES['video']['size'] > MAX_VIDEO_MB*1048576) out(false, ['error'=>"Video must be under ".MAX_VIDEO_MB." MB"]);
    if ($videoUrl!=='' && !preg_match('#^https?://#i', $videoUrl)) out(false, ['error'=>'Video URL must start with http:// or https://']);
    if ($videoUrl!=='') { [$videoUrl, $vErr] = resolveVideoUrl($videoUrl); if ($vErr) out(false, ['error'=>$vErr]); }

    $id = substr(bin2hex(random_bytes(5)),0,8); $dir = DATA_DIR."/$code/$id"; mkdir($dir, 0755, true);
    move_uploaded_file($_FILES['target']['tmp_name'], "$dir/target.jpg");
    if ($upId) { rename($upFile, "$dir/video.mp4"); @unlink(DATA_DIR."/tmp/$upId.json"); }
    elseif (!empty($_FILES['video'])) move_uploaded_file($_FILES['video']['tmp_name'], "$dir/video.mp4");
    else {
      set_time_limit(600); $fp=fopen("$dir/video.mp4",'w'); $ch=curl_init($videoUrl);
      curl_setopt_array($ch,[CURLOPT_FILE=>$fp,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_MAXREDIRS=>5,CURLOPT_TIMEOUT=>560,CURLOPT_USERAGENT=>'Mozilla/5.0 ScanPlay/1.0',CURLOPT_MAXFILESIZE=>MAX_VIDEO_MB*1048576]);
      $okDl=curl_exec($ch); $http=curl_getinfo($ch,CURLINFO_HTTP_CODE); $ctype=curl_getinfo($ch,CURLINFO_CONTENT_TYPE)?:''; $err=curl_error($ch); curl_close($ch); fclose($fp);
      if (stripos($ctype,'text/html')!==false && strpos($videoUrl,'drive.google.com')!==false) {   // large Drive files: virus-scan interstitial
        $html=file_get_contents("$dir/video.mp4");
        if (preg_match('/action="([^"]+)"/',$html,$fm) && preg_match('/name="uuid" value="([^"]+)"/',$html,$um) && preg_match('/name="id" value="([^"]+)"/',$html,$im)) {
          $u2=html_entity_decode($fm[1])."?id={$im[1]}&export=download&confirm=t&uuid={$um[1]}";
          $fp=fopen("$dir/video.mp4",'w'); $ch=curl_init($u2); curl_setopt_array($ch,[CURLOPT_FILE=>$fp,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_TIMEOUT=>560,CURLOPT_USERAGENT=>'Mozilla/5.0 ScanPlay/1.0']);
          $okDl=curl_exec($ch); $http=curl_getinfo($ch,CURLINFO_HTTP_CODE); $ctype=curl_getinfo($ch,CURLINFO_CONTENT_TYPE)?:''; curl_close($ch); fclose($fp);
        }
      }
      if (!$okDl || $http>=400 || filesize("$dir/video.mp4")<10000 || stripos($ctype,'text/html')!==false) { rrmdir($dir); out(false, ['error'=>'Could not download video from that link'.($err?" ($err)":'').'. Use a Google Drive / Dropbox share link (set to "Anyone with the link") or a direct .mp4 link.']); }
    }
    move_uploaded_file($_FILES['mind']['tmp_name'], DATA_DIR."/$code/targets.mind");
    q("INSERT INTO items (id,code,title,ratio,vratio,fit,created) VALUES (?,?,?,?,?,?,?)",
      [$id, $code, trim(strip_tags($_POST['title'] ?? 'Untitled')), (float)($_POST['ratio']??1), (float)($_POST['vratio']??1), in_array($_POST['fit']??'',['fill','fit','stretch'])?$_POST['fit']:'fit', now()]);
    logAct($u['id'],'photo_add',"$code/$id");
    $acc = row("SELECT name FROM accounts WHERE code=?", [$code]); $qr = baseUrl()."/view.html?c=$code";
    @sendMail($u['email'], "Your AR photo is ready — ".$acc['name'], "<div style='font-family:Arial,sans-serif;max-width:480px;margin:auto;padding:28px;border:1px solid #eee;border-radius:14px'>
      <div style='font-size:20px;font-weight:700;color:#7C3AED'>ScanPlay</div><p>Hi ".htmlspecialchars($u['name']).",</p><p>Your photo <b>".htmlspecialchars(trim(strip_tags($_POST['title'] ?? 'Untitled')))."</b> in project <b>".htmlspecialchars($acc['name'])."</b> is linked and ready.</p>
      <p><a href='$qr' style='display:inline-block;background:#7C3AED;color:#fff;padding:12px 18px;border-radius:10px;text-decoration:none;font-weight:700'>Open the player</a></p>
      <p>Get your QR in the studio (Show my QR) and print, stick or share it. Scan it, point at the photo, and it plays.</p>
      <p style='color:#666;font-size:13px'>Tip: matte paper, good light, at least 4×6 inches.</p></div>");
    out(true, ['id'=>$id]);
  }
  case 'item_update': {
    $u = auth(); $code = clean($_POST['code'] ?? ''); $id = clean($_POST['id'] ?? '');
    if (!row("SELECT code FROM accounts WHERE code=? AND user_id=?", [$code, $u['id']])) out(false, ['error'=>'Project not found']);
    $fit = in_array($_POST['fit']??'',['fill','fit','stretch']) ? $_POST['fit'] : null; $title = isset($_POST['title']) ? trim(strip_tags($_POST['title'])) : null;
    if ($fit) q("UPDATE items SET fit=? WHERE id=? AND code=?", [$fit, $id, $code]);
    if ($title !== null && $title !== '') q("UPDATE items SET title=? WHERE id=? AND code=?", [$title, $id, $code]);
    out(true);
  }
  case 'item_delete': {
    $u = auth(); $code = clean($_POST['code'] ?? ''); $id = clean($_POST['id'] ?? '');
    if (!row("SELECT code FROM accounts WHERE code=? AND user_id=?", [$code, $u['id']])) out(false, ['error'=>'Account not found']);
    q("DELETE FROM items WHERE id=? AND code=?", [$id, $code]); rrmdir(DATA_DIR."/$code/$id");
    $mind = DATA_DIR."/$code/targets.mind";
    if (!empty($_FILES['mind'])) move_uploaded_file($_FILES['mind']['tmp_name'], $mind);
    elseif (!row("SELECT id FROM items WHERE code=?", [$code]) && file_exists($mind)) unlink($mind);
    logAct($u['id'],'photo_delete',"$code/$id"); out(true);
  }

  /* ---------- public player ---------- */
  case 'get': {
    $code = clean($_GET['c'] ?? '');
    $a = row("SELECT a.*, u.plan, u.plan_until, u.logo, u.id uid FROM accounts a JOIN users u ON u.id=a.user_id WHERE a.code=? AND u.deleted=0", [$code]);
    if (!$a) out(false, ['error'=>'This QR is not linked to any account']);
    if (!empty($a['blocked'])) out(false, ['error'=>'This content is unavailable']);
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
  case 'promo_check': {
    $u = auth(); $plan = $_POST['plan'] ?? ''; $period = ($_POST['period'] ?? 'month')==='year' ? 'year' : 'month';
    if (!isset(PLANS[$plan]) || $plan==='free') out(false, ['error'=>'Choose a paid plan']);
    $price = PLANS[$plan][$period]; [$p,$d,$err] = applyPromo($_POST['code'] ?? '', $price);
    if ($err) out(false, ['error'=>$err]);
    out(true, ['code'=>$p['code'], 'discount'=>$d, 'total'=>$price-$d, 'label'=>$p['percent'] ? $p['percent'].'% off' : '₹'.$p['flat'].' off']);
  }
  case 'pay_create': {
    $u = auth(); $plan = $_POST['plan'] ?? ''; $period = ($_POST['period'] ?? 'month')==='year' ? 'year' : 'month';
    if (!isset(PLANS[$plan]) || $plan==='free') out(false, ['error'=>'Choose a paid plan']);
    $price = PLANS[$plan][$period]; [$promo,$disc,$perr] = applyPromo($_POST['promo'] ?? '', $price); if ($perr) out(false, ['error'=>$perr]);
    $amount = ($price - $disc) * 100;
    $ch = curl_init('https://api.razorpay.com/v1/orders');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_USERPWD=>RZP_KEY_ID.':'.RZP_KEY_SECRET, CURLOPT_POST=>true,
      CURLOPT_HTTPHEADER=>['Content-Type: application/json'],
      CURLOPT_POSTFIELDS=>json_encode(['amount'=>$amount,'currency'=>'INR','receipt'=>"u{$u['id']}-".now(),'notes'=>['user'=>$u['id'],'plan'=>$plan,'period'=>$period]])]);
    $res = json_decode(curl_exec($ch), true); curl_close($ch);
    if (empty($res['id'])) out(false, ['error'=>'Could not start payment: '.($res['error']['description'] ?? 'Razorpay not configured')]);
    q("INSERT INTO payments (user_id,order_id,plan,period,amount,status,created,promo,discount) VALUES (?,?,?,?,?,'created',?,?,?)", [$u['id'], $res['id'], $plan, $period, $amount, now(), $promo ? $promo['code'] : null, $disc*100]);
    out(true, ['order_id'=>$res['id'], 'amount'=>$amount, 'key'=>RZP_KEY_ID, 'name'=>$u['name'], 'email'=>$u['email'], 'phone'=>$u['phone'], 'label'=>PLANS[$plan]['name'].' · '.($period==='year'?'1 year':'1 month')]);
  }
  case 'pay_verify': {
    $u = auth(); $oid=$_POST['order_id']??''; $pid=$_POST['payment_id']??''; $sig=$_POST['signature']??'';
    $pay = row("SELECT * FROM payments WHERE order_id=? AND user_id=?", [$oid, $u['id']]); if (!$pay) out(false, ['error'=>'Unknown order']);
    if (!hash_equals(hash_hmac('sha256', "$oid|$pid", RZP_KEY_SECRET), $sig)) out(false, ['error'=>'Payment verification failed']);
    if ($pay['status'] !== 'paid') {
      q("UPDATE payments SET payment_id=?, status='paid' WHERE id=?", [$pid, $pay['id']]);
      if (!empty($pay['promo'])) q("UPDATE promos SET uses=uses+1 WHERE code=?", [$pay['promo']]);
      logAct($u['id'],'payment',"{$pay['plan']} {$pay['period']} ₹".($pay['amount']/100)." $pid");
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
    $list = rows("SELECT u.*, (SELECT COUNT(*) FROM accounts a WHERE a.user_id=u.id) accs, (SELECT COUNT(*) FROM items i JOIN accounts a ON a.code=i.code WHERE a.user_id=u.id) photos,
      (SELECT SUM(s.n) FROM scans s JOIN accounts a ON a.code=s.code WHERE a.user_id=u.id) scans, (SELECT SUM(amount) FROM payments p WHERE p.user_id=u.id AND p.status='paid') paid FROM users u WHERE deleted=0 ORDER BY created DESC");
    foreach ($list as &$x) { unset($x['pass'],$x['token'],$x['code']); $x['state']=planState($x); }
    $tot = ['users'=>count($list), 'active'=>count(array_filter($list, fn($x)=>$x['state']==='active')), 'paid'=>count(array_filter($list, fn($x)=>$x['plan']!=='free')),
      'revenue'=>(int)(row("SELECT SUM(amount) s FROM payments WHERE status='paid'")['s'] ?? 0)/100,
      'integrity'=>row("PRAGMA integrity_check")['integrity_check'] ?? '?', 'photos'=>(int)row("SELECT COUNT(*) c FROM items")['c'],
      'backups'=>count(glob(DATA_DIR.'/backups/db-*.sqlite')), 'lastBackup'=>($b=glob(DATA_DIR.'/backups/db-*.sqlite')) ? basename(end($b)) : 'none yet — cron not run', 'scans30'=>(int)(row("SELECT SUM(n) s FROM scans WHERE day>=?", [date('Y-m-d', now()-30*86400)])['s'] ?? 0)];
    out(true, ['users'=>$list, 'plans'=>PLANS, 'totals'=>$tot]);
  }
  case 'admin_user': {
    ownerAuth(); $id=(int)($_POST['id']??0);
    $u = row("SELECT * FROM users WHERE id=?", [$id]); if (!$u) out(false, ['error'=>'No such user']); unset($u['pass'],$u['token'],$u['code']);
    $accs = array_map('pubAccount', rows("SELECT * FROM accounts WHERE user_id=? ORDER BY created", [$id]));
    $pays = rows("SELECT order_id,payment_id,plan,period,amount,status,created FROM payments WHERE user_id=? ORDER BY created DESC", [$id]);
    $act = rows("SELECT ts,who,action,detail,ip FROM activity WHERE user_id=? ORDER BY id DESC LIMIT 100", [$id]);
    out(true, ['user'=>$u, 'accounts'=>$accs, 'payments'=>$pays, 'activity'=>$act, 'info'=>userInfo(row("SELECT * FROM users WHERE id=?", [$id]))]);
  }
  case 'admin_setplan': {
    ownerAuth(); logAct((int)($_POST['id']??0),'admin_setplan',json_encode(array_diff_key($_POST,['pass'=>1])),'admin'); $id=(int)($_POST['id']??0); $plan=$_POST['plan']??''; $days=(int)($_POST['days']??30);
    if (!isset(PLANS[$plan])) out(false, ['error'=>'Bad plan']);
    q("UPDATE users SET plan=?, plan_until=? WHERE id=?", [$plan, now()+$days*86400, $id]); out(true);
  }
  case 'admin_extend': { ownerAuth(); logAct((int)($_POST['id']??0),'admin_extend',json_encode(array_diff_key($_POST,['pass'=>1])),'admin'); $id=(int)($_POST['id']??0); $days=(int)($_POST['days']??30); $u=row("SELECT plan_until FROM users WHERE id=?",[$id]); $base=max((int)$u['plan_until'], now()); q("UPDATE users SET plan_until=? WHERE id=?", [$base+$days*86400, $id]); out(true); }
  case 'admin_credit': {
    ownerAuth(); logAct((int)($_POST['id']??0),'admin_credit',json_encode(array_diff_key($_POST,['pass'=>1])),'admin'); $id=(int)($_POST['id']??0);
    if (isset($_POST['photos'])) q("UPDATE users SET extra_photos=? WHERE id=?", [max(0,(int)$_POST['photos']), $id]);
    if (isset($_POST['accounts'])) q("UPDATE users SET extra_accounts=? WHERE id=?", [max(0,(int)$_POST['accounts']), $id]);
    if (isset($_POST['note'])) q("UPDATE users SET note=? WHERE id=?", [trim(strip_tags($_POST['note'])), $id]);
    out(true);
  }
  case 'admin_verify': { ownerAuth(); logAct((int)($_POST['id']??0),'admin_verify',json_encode(array_diff_key($_POST,['pass'=>1])),'admin'); q("UPDATE users SET verified=1 WHERE id=?", [(int)($_POST['id']??0)]); out(true); }
  case 'admin_setpass': { ownerAuth(); logAct((int)($_POST['id']??0),'admin_setpass',json_encode(array_diff_key($_POST,['pass'=>1])),'admin'); $p=$_POST['pass']??''; if (strlen($p)<6) out(false,['error'=>'Min 6 chars']); q("UPDATE users SET pass=?, token=NULL WHERE id=?", [password_hash($p,PASSWORD_DEFAULT), (int)($_POST['id']??0)]); out(true); }
  case 'admin_item_delete': {
    ownerAuth(); logAct(0,'admin_item_delete',json_encode($_POST),'admin'); $code=clean($_POST['code']??''); $id=clean($_POST['id']??'');
    q("DELETE FROM items WHERE id=? AND code=?", [$id,$code]); rrmdir(DATA_DIR."/$code/$id");
    if (!row("SELECT id FROM items WHERE code=?", [$code])) @unlink(DATA_DIR."/$code/targets.mind");
    out(true, ['note'=>'Removed. The owner must open the studio once so remaining photos are recompiled.']);
  }
  case 'admin_block': { ownerAuth(); logAct(0,'admin_block',json_encode($_POST),'admin'); $code=clean($_POST['code']??''); q("UPDATE accounts SET blocked=? WHERE code=?", [(int)!!($_POST['blocked']??0), $code]); out(true); }
  case 'admin_account_delete': {
    ownerAuth(); logAct(0,'admin_account_delete',json_encode($_POST),'admin'); $code=clean($_POST['code']??'');
    q("DELETE FROM items WHERE code=?", [$code]); q("DELETE FROM scans WHERE code=?", [$code]); q("DELETE FROM accounts WHERE code=?", [$code]); rrmdir(DATA_DIR."/$code"); out(true);
  }
  case 'admin_user_delete': {
    ownerAuth(); logAct((int)($_POST['id']??0),'admin_user_delete',json_encode(array_diff_key($_POST,['pass'=>1])),'admin'); $id=(int)($_POST['id']??0);
    foreach (rows("SELECT code FROM accounts WHERE user_id=?", [$id]) as $acc) { q("DELETE FROM items WHERE code=?", [$acc['code']]); q("DELETE FROM scans WHERE code=?", [$acc['code']]); rrmdir(DATA_DIR."/{$acc['code']}"); }
    q("DELETE FROM accounts WHERE user_id=?", [$id]); rrmdir(DATA_DIR."/users/$id");
    q("UPDATE users SET deleted=1, token=NULL, email=email||'.deleted.'||id WHERE id=?", [$id]); out(true);
  }

  case 'admin_activity': {
    ownerAuth(); $q = trim($_POST['q'] ?? '');
    $rows = $q ? rows("SELECT a.*, u.email FROM activity a LEFT JOIN users u ON u.id=a.user_id WHERE a.action LIKE ? OR a.detail LIKE ? OR u.email LIKE ? OR a.ip LIKE ? ORDER BY a.id DESC LIMIT 300", ["%$q%","%$q%","%$q%","%$q%"])
               : rows("SELECT a.*, u.email FROM activity a LEFT JOIN users u ON u.id=a.user_id ORDER BY a.id DESC LIMIT 200");
    $sus = rows("SELECT ip, COUNT(*) n FROM activity WHERE action='login_failed' AND ts>? GROUP BY ip HAVING n>=5 ORDER BY n DESC", [now()-86400]);
    out(true, ['activity'=>$rows, 'suspicious'=>$sus]);
  }

  /* ---------- announcements (admin) ---------- */
  case 'admin_broadcast': {
    ownerAuth(); $subj = trim($_POST['subject'] ?? ''); $body = trim($_POST['body'] ?? ''); $seg = $_POST['segment'] ?? 'all';
    if ($subj==='' || $body==='') out(false, ['error'=>'Subject and message are required']);
    if (!mailConfigured()) out(false, ['error'=>'Email is not configured']);
    $where = ['all'=>"deleted=0 AND verified=1", 'free'=>"deleted=0 AND verified=1 AND plan='free'", 'paid'=>"deleted=0 AND verified=1 AND plan!='free'", 'expired'=>"deleted=0 AND verified=1 AND plan_until < ".now()][$seg] ?? "deleted=0 AND verified=1";
    $users = rows("SELECT id,email,name FROM users WHERE $where"); $sent=0; set_time_limit(600);
    $html = "<div style='font-family:Arial,sans-serif;max-width:520px;margin:auto;padding:28px;border:1px solid #eee;border-radius:14px'><div style='font-size:20px;font-weight:700;color:#7C3AED'>ScanPlay</div>".nl2br(htmlspecialchars($body))."<p style='margin-top:20px'><a href='".baseUrl()."/studio.html' style='display:inline-block;background:#7C3AED;color:#fff;padding:12px 18px;border-radius:10px;text-decoration:none;font-weight:700'>Open ScanPlay Studio</a></p><p style='color:#999;font-size:12px'>You receive this because you have a ScanPlay account. Reply to this email to contact us.</p></div>";
    foreach ($users as $x) { if (@sendMail($x['email'], $subj, str_replace('{name}', htmlspecialchars($x['name']), $html))) $sent++; }
    logAct(0,'admin_broadcast',"$seg: $subj ($sent/".count($users).")",'admin');
    out(true, ['sent'=>$sent, 'total'=>count($users)]);
  }

  /* ---------- promo codes (admin) ---------- */
  case 'admin_promos': {
    ownerAuth();
    $list = rows("SELECT p.*, (SELECT COUNT(*) FROM payments x WHERE x.promo=p.code AND x.status='paid') paid_count, (SELECT SUM(amount) FROM payments x WHERE x.promo=p.code AND x.status='paid') revenue, (SELECT SUM(discount) FROM payments x WHERE x.promo=p.code AND x.status='paid') given FROM promos p ORDER BY created DESC");
    $sales = rows("SELECT pm.created, pm.plan, pm.period, pm.amount, pm.discount, pm.promo, u.name, u.email FROM payments pm JOIN users u ON u.id=pm.user_id WHERE pm.status='paid' AND pm.promo IS NOT NULL ORDER BY pm.created DESC LIMIT 200");
    out(true, ['promos'=>$list, 'sales'=>$sales]);
  }
  case 'admin_promo_save': {
    ownerAuth(); $code = strtoupper(preg_replace('/[^A-Za-z0-9]/','', $_POST['code'] ?? '')); if (strlen($code)<3) out(false, ['error'=>'Code must be 3+ letters/numbers']);
    $pct=max(0,min(90,(int)($_POST['percent']??0))); $flat=max(0,(int)($_POST['flat']??0)); if(!$pct && !$flat) out(false, ['error'=>'Give a percent or a flat amount']);
    $exp = !empty($_POST['expires']) ? strtotime($_POST['expires'].' 23:59:59') : 0;
    q("INSERT INTO promos (code,percent,flat,max_uses,expires,active,note,created) VALUES (?,?,?,?,?,1,?,?) ON CONFLICT(code) DO UPDATE SET percent=excluded.percent, flat=excluded.flat, max_uses=excluded.max_uses, expires=excluded.expires, note=excluded.note",
      [$code,$pct,$flat,max(0,(int)($_POST['max_uses']??0)),$exp,trim(strip_tags($_POST['note']??'')),now()]);
    out(true, ['code'=>$code]);
  }
  case 'admin_promo_toggle': { ownerAuth(); q("UPDATE promos SET active=? WHERE code=?", [(int)!!($_POST['active']??0), strtoupper($_POST['code']??'')]); out(true); }
  case 'admin_promo_delete': { ownerAuth(); q("DELETE FROM promos WHERE code=?", [strtoupper($_POST['code']??'')]); out(true); }

  /* ---------- backups ---------- */
  case 'admin_backup': {
    ownerAuth(); header_remove('Content-Type');
    $tmp = tempnam(sys_get_temp_dir(), 'spb'); $z = new ZipArchive(); $z->open($tmp, ZipArchive::OVERWRITE);
    $db->exec("VACUUM INTO '".DATA_DIR."/backup-live.db'"); $z->addFile(DATA_DIR.'/backup-live.db', 'scanplay.db');
    $export = ['exported'=>date('c'), 'users'=>rows("SELECT id,email,name,phone,plan,plan_until,created,verified,extra_photos,extra_accounts,note FROM users WHERE deleted=0"),
               'accounts'=>rows("SELECT * FROM accounts"), 'items'=>rows("SELECT * FROM items"), 'payments'=>rows("SELECT * FROM payments"), 'scans'=>rows("SELECT * FROM scans")];
    $z->addFromString('export.json', json_encode($export, JSON_PRETTY_PRINT)); $z->close(); @unlink(DATA_DIR.'/backup-live.db');
    header('Content-Type: application/zip'); header('Content-Disposition: attachment; filename="scanplay-backup-'.date('Ymd-Hi').'.zip"'); header('Content-Length: '.filesize($tmp));
    readfile($tmp); unlink($tmp); exit;
  }

  /* ---------- cron: daily backup + delete data after grace ---------- */
  case 'cron': {
    if (($_GET['key'] ?? '') !== CRON_KEY) out(false, ['error'=>'Bad key']);
    // 1. daily DB backup (keeps 30)
    $bdir = DATA_DIR.'/backups'; if (!is_dir($bdir)) mkdir($bdir, 0755, true);
    $bfile = "$bdir/db-".date('Ymd').".sqlite"; if (!file_exists($bfile)) $db->exec("VACUUM INTO '$bfile'");
    $old = glob("$bdir/db-*.sqlite"); sort($old); foreach (array_slice($old, 0, max(0, count($old)-30)) as $f) @unlink($f);
    // 2. delete data after grace
    $cut = now() - GRACE_DAYS*86400; $n=0;
    foreach (rows("SELECT id FROM users WHERE plan_until < ? AND deleted=0", [$cut]) as $u) {
      foreach (rows("SELECT code FROM accounts WHERE user_id=?", [$u['id']]) as $a) { rrmdir(DATA_DIR."/{$a['code']}"); q("DELETE FROM items WHERE code=?", [$a['code']]); q("DELETE FROM scans WHERE code=?", [$a['code']]); $n++; }
      q("DELETE FROM accounts WHERE user_id=?", [$u['id']]);
    }
    out(true, ['accounts_removed'=>$n, 'backup'=>basename($bfile), 'backups_kept'=>count(glob("$bdir/db-*.sqlite"))]);
  }

  default: out(false, ['error'=>'Unknown action']);
}
