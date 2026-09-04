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
$cfg += [ 'owner_pass'=>'', 'cron_key'=>'', 'rzp_key_id'=>'', 'rzp_key_secret'=>'',
          'smtp_host'=>'smtp.hostinger.com', 'smtp_port'=>465, 'smtp_user'=>'info@scanplay.in', 'smtp_pass'=>'CHANGE_ME', 'mail_from_name'=>'ScanPlay',
          'google_client_id'=>'CHANGE_ME.apps.googleusercontent.com', 'rzp_webhook_secret'=>'' ];
define('OWNER_PASS', $cfg['owner_pass']); define('CRON_KEY', $cfg['cron_key']);
define('RZP_KEY_ID', $cfg['rzp_key_id']); define('RZP_KEY_SECRET', $cfg['rzp_key_secret']); define('RZP_WEBHOOK_SECRET', $cfg['rzp_webhook_secret']);
define('SMTP_HOST', $cfg['smtp_host']); define('SMTP_PORT', (int)$cfg['smtp_port']); define('SMTP_USER', $cfg['smtp_user']); define('SMTP_PASS', $cfg['smtp_pass']); define('MAIL_FROM_NAME', $cfg['mail_from_name']);
define('GOOGLE_CLIENT_ID', $cfg['google_client_id']);
define('DATA_DIR', __DIR__ . '/data');
define('MAX_VIDEO_MB', 200);
define('GRACE_DAYS', 30);
/* Email + Google settings come from config.php (see config.sample.php) */

/* plan => [photos, accounts (0 = unlimited), logo, analytics, sublogins, domain, price_month, price_year, watermark] */
const PLANS = [
  'free' => ['name'=>'Free','photos'=>1,'accounts'=>1,'ppp'=>1,'logo'=>false,'analytics'=>false,'sub'=>false,'domain'=>false,'month'=>0,'year'=>0,'watermark'=>true],
  'personal' => ['name'=>'Personal','photos'=>6,'accounts'=>2,'ppp'=>3,'logo'=>false,'analytics'=>false,'sub'=>false,'domain'=>false,'month'=>499,'year'=>4990,'watermark'=>false],
  'business' => ['name'=>'Business','photos'=>30,'accounts'=>6,'ppp'=>5,'logo'=>true,'analytics'=>true,'sub'=>false,'domain'=>false,'month'=>1999,'year'=>19990,'watermark'=>false],
  'pro' => ['name'=>'Pro','photos'=>100,'accounts'=>10,'ppp'=>10,'logo'=>true,'analytics'=>true,'sub'=>true,'domain'=>false,'month'=>2999,'year'=>29990,'watermark'=>false],
  'agency' => ['name'=>'Gold','photos'=>300,'accounts'=>15,'ppp'=>20,'logo'=>true,'analytics'=>true,'sub'=>true,'domain'=>true,'month'=>5999,'year'=>59990,'watermark'=>false],
];

/* ---------------- DB ---------------- */
if (!is_dir(DATA_DIR)) mkdir(DATA_DIR, 0755, true);
try { $db = new PDO('sqlite:' . DATA_DIR . '/scanplay.db'); $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); }
catch (Exception $e) { echo json_encode(['ok'=>false,'error'=>'Database unavailable: '.$e->getMessage()]); exit; }
$db->exec("PRAGMA journal_mode=WAL");
$db->exec("CREATE TABLE IF NOT EXISTS users (id INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT UNIQUE, pass TEXT, name TEXT, phone TEXT,
  plan TEXT DEFAULT 'free', plan_until INTEGER, created INTEGER, token TEXT, logo INTEGER DEFAULT 0, deleted INTEGER DEFAULT 0)");
foreach (['verified INTEGER DEFAULT 0','code TEXT','code_exp INTEGER','google_id TEXT','extra_photos INTEGER DEFAULT 0','extra_accounts INTEGER DEFAULT 0','note TEXT','referral_code TEXT','referrer_id INTEGER','ref_awarded INTEGER DEFAULT 0',
          'role TEXT DEFAULT \'user\'','parent_id INTEGER','tokens INTEGER DEFAULT 0','tokens_used INTEGER DEFAULT 0','partner_code TEXT','business TEXT','whatsapp TEXT','pay_details TEXT','area TEXT','listed INTEGER DEFAULT 0','lat REAL','lng REAL','address TEXT'] as $col) { try { $db->exec("ALTER TABLE users ADD COLUMN $col"); } catch (Exception $e) {} }
$db->exec("CREATE TABLE IF NOT EXISTS accounts (code TEXT PRIMARY KEY, user_id INTEGER, name TEXT, created INTEGER)");
try { $db->exec("ALTER TABLE accounts ADD COLUMN blocked INTEGER DEFAULT 0"); } catch (Exception $e) {}
try { $db->exec("ALTER TABLE accounts ADD COLUMN showcase INTEGER DEFAULT 0"); } catch (Exception $e) {}
$db->exec("CREATE TABLE IF NOT EXISTS ledger (id INTEGER PRIMARY KEY AUTOINCREMENT, ts INTEGER, from_id INTEGER, to_id INTEGER, qty INTEGER, kind TEXT, note TEXT)");
$db->exec("CREATE TABLE IF NOT EXISTS settings (k TEXT PRIMARY KEY, v TEXT)");
$db->exec("CREATE TABLE IF NOT EXISTS outreach (id INTEGER PRIMARY KEY AUTOINCREMENT, ts INTEGER, email TEXT, name TEXT, company TEXT, code TEXT, item TEXT, subject TEXT, sent INTEGER DEFAULT 0, error TEXT)");
$db->exec("CREATE TABLE IF NOT EXISTS slides (id INTEGER PRIMARY KEY AUTOINCREMENT, sort INTEGER DEFAULT 0, city TEXT, status TEXT, title TEXT, text TEXT, whatsapp TEXT, photo INTEGER DEFAULT 0, created INTEGER)");
try { $db->exec("ALTER TABLE slides ADD COLUMN img_url TEXT"); } catch (Exception $e) {}
if (!(int)$db->query("SELECT COUNT(*) FROM slides WHERE city='Visakhapatnam'")->fetchColumn())
  $db->prepare("INSERT INTO slides (sort,city,status,title,text,whatsapp,img_url,created) VALUES (1,'Visakhapatnam','partner',?,?,?,?,?)")->execute(["Veerumama's Brand","Authorised distributor for Visakhapatnam district. Supplies tokens to promoters — photographers, event managers and print shops across the city.","919912999949","assets/partners/visakhapatnam.jpg",time()]);
if (!(int)$db->query("SELECT COUNT(*) FROM slides")->fetchColumn()) foreach ([
  ['Hyderabad','open','Be the first in Hyderabad','No distributor yet. Buy tokens at partner price, appoint promoters, sell at your own price across the city.'],
  ['Vijayawada','open','Be the first in Vijayawada','Wedding capital of Andhra — every invitation card is a ScanPlay card waiting to happen.'],
  ['Rajahmundry','open','Be the first in the Godavari districts','Rajahmundry, Kakinada, Tanuku, Eluru — one distributor, four busy print markets.'],
  ['Tirupati','open','Be the first in Tirupati','Temple tourism, hotels, and a steady stream of family functions.']] as $i=>$s)
  $db->prepare("INSERT INTO slides (sort,city,status,title,text,created) VALUES (?,?,?,?,?,?)")->execute([$i+10,$s[0],$s[1],$s[2],$s[3],time()]);
$db->exec("CREATE TABLE IF NOT EXISTS agreements (id TEXT PRIMARY KEY, user_id INTEGER, created INTEGER, status TEXT, terms TEXT, sign_name TEXT, signed_at INTEGER, sign_ip TEXT, sign_kind TEXT, admin_signed_at INTEGER)");
try { $db->exec("ALTER TABLE agreements ADD COLUMN mail_sent INTEGER DEFAULT 0"); } catch (Exception $e) {}
try { $db->exec("ALTER TABLE agreements ADD COLUMN mail_err TEXT"); } catch (Exception $e) {}
try { $db->exec("ALTER TABLE agreements ADD COLUMN mail_at INTEGER"); } catch (Exception $e) {}
/* one-time migration to the token model: nobody loses paid capacity */
if (!$db->query("SELECT v FROM settings WHERE k='token_migrated'")->fetch()) {
  $db->exec("INSERT INTO settings (k,v) VALUES ('token_migrated','1')");
  foreach ($db->query("SELECT * FROM users WHERE deleted=0")->fetchAll(PDO::FETCH_ASSOC) as $mu) {
    $grant = 2;   // welcome tokens for everyone
    $plan = PLANS[$mu['plan']] ?? null;
    if ($plan && $mu['plan'] !== 'free' && (int)$mu['plan_until'] > time()) {
      $cap = ((int)$plan['accounts'] + (int)($mu['extra_accounts']??0)) * (int)($plan['ppp']??1) + (int)($mu['extra_photos']??0);
      $used = (int)$db->query("SELECT COUNT(*) FROM items i JOIN accounts a ON a.code=i.code WHERE a.user_id=".(int)$mu['id'])->fetchColumn();
      $grant += max(0, $cap - $used);
    }
    $db->prepare("UPDATE users SET tokens=COALESCE(tokens,0)+? WHERE id=?")->execute([$grant, $mu['id']]);
    $db->prepare("INSERT INTO ledger (ts,from_id,to_id,qty,kind,note) VALUES (?,?,?,?,?,?)")->execute([time(), 0, $mu['id'], $grant, 'grant', 'migration to tokens'.($grant>2?' (paid plan converted)':'')]);
  }
}
/* ---------- TOKEN MODEL (beta) ----------
   1 token = 1 photo + 1 video. Spent when a photo is added; never refunded, never expires.
   Roles form a tree: admin -> distributor -> retailer -> user. Tokens only move DOWN the tree; only admin can remove them. */
const ROLES = ['distributor','promoter','user'];
q("UPDATE users SET role='promoter' WHERE role='retailer'");   // rename migration
function setting($k, $d='') { $r = row("SELECT v FROM settings WHERE k=?", [$k]); return $r ? $r['v'] : $d; }
function adminContact() { return ['name'=>setting('admin_name','ScanPlay'), 'business'=>setting('admin_business','ScanPlay LLP'), 'phone'=>setting('admin_phone',''), 'whatsapp'=>setting('admin_whatsapp',''), 'email'=>setting('admin_email','info@scanplay.in'), 'pay_details'=>setting('admin_pay','')]; }
function contactOf($u) { if (!$u) return null; return ['id'=>(int)$u['id'], 'name'=>$u['name'], 'business'=>$u['business'] ?: $u['name'], 'phone'=>$u['phone'], 'whatsapp'=>$u['whatsapp'] ?: $u['phone'], 'email'=>$u['email'], 'pay_details'=>$u['pay_details'], 'role'=>$u['role'] ?: 'user']; }
function childRole($parentRole) { return $parentRole==='distributor' ? 'promoter' : 'user'; }
function partnerCode($u) { if (empty($u['partner_code'])) { $c = strtoupper(substr(bin2hex(random_bytes(4)),0,6)); q("UPDATE users SET partner_code=? WHERE id=?", [$c, $u['id']]); $u['partner_code']=$c; } return $u['partner_code']; }
function linkParent($childId, $parentCode) {
  $p = row("SELECT * FROM users WHERE upper(partner_code)=? AND deleted=0", [strtoupper($parentCode)]); if (!$p || (int)$p['id']===(int)$childId) return;
  $cr = childRole($p['role']); q("UPDATE users SET parent_id=?, role=?, listed=? WHERE id=? AND parent_id IS NULL", [$p['id'], $cr, $cr==='user'?0:1, $childId]);
}
function saveLocation($id) {
  $lat=(float)($_POST['lat']??0); $lng=(float)($_POST['lng']??0); if (!$lat || !$lng || abs($lat)>90 || abs($lng)>180) return;
  q("UPDATE users SET lat=?, lng=?, address=?".(!empty($_POST['business'])?", business=?":"")." WHERE id=?", array_merge([$lat,$lng,trim(strip_tags($_POST['address']??''))], !empty($_POST['business'])?[trim(strip_tags($_POST['business']))]:[], [$id]));
}
function kmBetween($a,$b,$c,$d){ $r=6371; $x=deg2rad($c-$a); $y=deg2rad($d-$b); $h=sin($x/2)**2+cos(deg2rad($a))*cos(deg2rad($c))*sin($y/2)**2; return 2*$r*asin(sqrt($h)); }
/* ---------- video compression (background ffmpeg, static binary in data/bin) ----------
   Rewrites video.mp4 as 720p H.264 ~2.5 Mbps: same sharpness on a phone, 5-8x less bandwidth, starts faster.
   Runs after the API has replied; the original stays in place until the compressed file is complete, then replaces it atomically. */
function compressVideo($dir) {
  $ff = DATA_DIR.'/bin/ffmpeg'; $src = "$dir/video.mp4";
  if (!is_executable($ff) || !file_exists($src) || filesize($src) < 6*1048576) return;   // small files: not worth it
  $tmp = "$dir/video_c.mp4"; $log = "$dir/compress.log"; @unlink($tmp);
  $vf = "scale='if(gt(iw,ih),min(1280,iw),-2)':'if(gt(iw,ih),-2,min(1280,ih))'";
  $cmd = escapeshellarg($ff)." -y -nostdin -threads 2 -i ".escapeshellarg($src)." -vf ".escapeshellarg($vf)." -r 30 -c:v libx264 -preset veryfast -crf 26 -pix_fmt yuv420p -c:a aac -b:a 96k -movflags +faststart ".escapeshellarg($tmp)
       ." > ".escapeshellarg($log)." 2>&1 && [ -s ".escapeshellarg($tmp)." ] && [ $(stat -c%s ".escapeshellarg($tmp).") -lt $(stat -c%s ".escapeshellarg($src).") ] && mv -f ".escapeshellarg($tmp)." ".escapeshellarg($src)." ; rm -f ".escapeshellarg($tmp);
  shell_exec("nohup sh -c ".escapeshellarg($cmd)." > /dev/null 2>&1 &");
}
function agreementNotify($g) {   // emails the partner, copies admin, records delivery on the agreement
  $t = json_decode($g['terms'], true) ?: []; $num = $t['number'] ?? $g['id']; $roleWord = ($g['role']==='distributor')?'Distributor':'Promoter';
  $ok = sendMail($g['email'], "Your ScanPlay $roleWord Agreement is ready to sign", mailTpl("Agreement ready &#128203;", "Hi ".htmlspecialchars($g['name']).", your $roleWord Agreement <b>$num</b> is ready. Open the Studio &rarr; Partner panel to review it and sign digitally, or download the PDF, sign and upload it.", "Territory: ".htmlspecialchars($t['territory'] ?? ''), "Review and sign", baseUrl()."/studio.html"));
  $err = $ok ? '' : $GLOBALS['MAIL_ERR'];
  q("UPDATE agreements SET mail_sent=?, mail_err=?, mail_at=? WHERE id=?", [$ok?1:0, $err, now(), $g['id']]);
  $admin = setting('admin_email','info@scanplay.in');
  if ($admin && strtolower($admin) !== strtolower($g['email']))
    @sendMail($admin, "Copy: agreement $num sent to ".($g['business'] ?: $g['name']), mailTpl("Agreement sent", "Agreement <b>$num</b> (".$roleWord.", ".htmlspecialchars($t['territory'] ?? '').") was sent to <b>".htmlspecialchars($g['business'] ?: $g['name'])."</b> &lt;".htmlspecialchars($g['email'])."&gt;.", $ok ? "Partner email: delivered to the mail server." : "<b>Partner email FAILED:</b> ".htmlspecialchars($err), "Open agreement", baseUrl()."/agreement.html?id=".$g['id']));
  return $ok;
}
function agrId($s) { return preg_replace('/[^A-Za-z0-9\-]/', '', (string)$s); }
function ledger($from, $to, $qty, $kind, $note='') { q("INSERT INTO ledger (ts,from_id,to_id,qty,kind,note) VALUES (?,?,?,?,?,?)", [now(), $from, $to, $qty, $kind, mb_substr((string)$note,0,120)]); }
try { $db->exec("ALTER TABLE accounts ADD COLUMN public INTEGER DEFAULT 1"); } catch (Exception $e) {}
try { $db->exec("UPDATE accounts SET public=1 WHERE public=0 OR public IS NULL"); } catch (Exception $e) {}

/* self-running daily backup: first request of each day snapshots the DB (keeps 30) — no cron needed */
$_bdir = DATA_DIR.'/backups'; $_bfile = "$_bdir/db-".date('Ymd').".sqlite";
if (!file_exists($_bfile)) {
  if (!is_dir($_bdir)) @mkdir($_bdir, 0755, true);
  try { $db->exec("VACUUM INTO '$_bfile'");
    $_old = glob("$_bdir/db-*.sqlite"); sort($_old);
    foreach (array_slice($_old, 0, max(0, count($_old)-30)) as $_f) @unlink($_f);
  } catch (Exception $e) { @unlink($_bfile); }
}
try { $db->exec("ALTER TABLE items ADD COLUMN yt TEXT"); } catch (Exception $e) {}
$db->exec("CREATE TABLE IF NOT EXISTS items (id TEXT PRIMARY KEY, code TEXT, title TEXT, ratio REAL, vratio REAL, fit TEXT, created INTEGER)");
$db->exec("CREATE TABLE IF NOT EXISTS scans (code TEXT, day TEXT, n INTEGER, PRIMARY KEY(code,day))");
$db->exec("CREATE TABLE IF NOT EXISTS item_hits (item_id TEXT, day TEXT, n INTEGER, PRIMARY KEY(item_id,day))");
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
$MAIL_ERR = '';
function sendMail($to, $subject, $html, $attachments = []) {
  global $MAIL_ERR; $MAIL_ERR = '';
  if (!mailConfigured()) { $MAIL_ERR = 'Email is not configured (smtp_pass in config.php)'; return false; }
  $fp = @stream_socket_client('ssl://'.SMTP_HOST.':'.SMTP_PORT, $errno, $errstr, 15); if (!$fp) { $MAIL_ERR = "Cannot reach ".SMTP_HOST.": $errstr"; return false; }
  $read = function() use ($fp) { $r=''; while ($l=fgets($fp,515)) { $r.=$l; if (substr($l,3,1)===' ') break; } return $r; };
  $cmd  = function($c) use ($fp,$read) { fwrite($fp, $c."\r\n"); return $read(); };
  $read(); $cmd('EHLO scanplay.in'); $cmd('AUTH LOGIN'); $cmd(base64_encode(SMTP_USER)); $r=$cmd(base64_encode(SMTP_PASS));
  if (strpos($r,'235')!==0) { fclose($fp); $MAIL_ERR = 'Mailbox login failed for '.SMTP_USER.' — '.trim($r).' (check smtp_pass in config.php)'; return false; }
  $r=$cmd('MAIL FROM:<'.SMTP_USER.'>'); if (strpos($r,'250')!==0) { fclose($fp); $MAIL_ERR='MAIL FROM rejected: '.trim($r); return false; }
  $r=$cmd('RCPT TO:<'.$to.'>'); if (strpos($r,'250')!==0) { fclose($fp); $MAIL_ERR='Recipient rejected: '.trim($r); return false; }
  $r=$cmd('DATA'); if (strpos($r,'354')!==0) { fclose($fp); $MAIL_ERR='DATA rejected: '.trim($r); return false; }
  $logoPath = __DIR__.'/assets/brand/icon-192.png'; $bnd = 'sp'.bin2hex(random_bytes(8)); $mix = 'mx'.bin2hex(random_bytes(8));
  $related  = "--$bnd\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n".chunk_split(base64_encode($html))."\r\n";
  if (file_exists($logoPath)) $related .= "--$bnd\r\nContent-Type: image/png; name=\"logo.png\"\r\nContent-Transfer-Encoding: base64\r\nContent-ID: <logo>\r\nContent-Disposition: inline; filename=\"logo.png\"\r\n\r\n".chunk_split(base64_encode(file_get_contents($logoPath)))."\r\n";
  $related .= "--$bnd--\r\n";
  $head = "From: ".MAIL_FROM_NAME." <".SMTP_USER.">\r\nTo: <$to>\r\nSubject: =?UTF-8?B?".base64_encode($subject)."?=\r\nMIME-Version: 1.0\r\n";
  if ($attachments) {
    $msg = $head."Content-Type: multipart/mixed; boundary=\"$mix\"\r\n\r\n--$mix\r\nContent-Type: multipart/related; boundary=\"$bnd\"; type=\"text/html\"\r\n\r\n".$related;
    foreach ($attachments as $at) $msg .= "--$mix\r\nContent-Type: ".$at['mime']."; name=\"".$at['name']."\"\r\nContent-Transfer-Encoding: base64\r\nContent-Disposition: attachment; filename=\"".$at['name']."\"\r\n\r\n".chunk_split(base64_encode($at['data']))."\r\n";
    $msg .= "--$mix--";
  } else $msg = $head."Content-Type: multipart/related; boundary=\"$bnd\"; type=\"text/html\"\r\n\r\n".$related;
  $r=$cmd($msg."\r\n."); $cmd('QUIT'); fclose($fp);
  if (strpos($r,'250')!==0) { $MAIL_ERR = 'Message rejected: '.trim($r); return false; }
  return true;
}
/* Branded email shell (style A): logo top, centred content, footer with support details */
function mailTpl($title, $lead, $body='', $ctaText=null, $ctaUrl=null, $code=null) {
  $logo = 'cid:logo';
  $codeBlock = $code ? "<div style='margin:22px auto;background:#F6F3FF;border-radius:14px;padding:18px;font:800 38px Courier New,monospace;letter-spacing:10px;color:#0F0A1F;max-width:320px'>$code</div><div style='font:13px Arial;color:#6B6480'>This code expires in 15 minutes.</div>" : '';
  $cta = $ctaText ? "<a href='$ctaUrl' style='display:inline-block;margin:22px 0 6px;background:#7C3AED;color:#fff;text-decoration:none;font:700 15px Arial;padding:14px 26px;border-radius:12px'>$ctaText</a>" : '';
  return "<!DOCTYPE html><html><head><meta charset='utf-8'></head><body style='margin:0;background:#F3F1FA;padding:24px 12px'>
<table role='presentation' width='100%' cellspacing='0' cellpadding='0'><tr><td align='center'>
<table role='presentation' width='100%' style='max-width:520px;background:#fff;border-radius:22px;box-shadow:0 10px 30px rgba(15,10,31,.08)' cellspacing='0' cellpadding='0'>
<tr><td align='center' style='padding:36px 32px 8px'><img src='$logo' width='64' height='64' style='border-radius:18px;display:block' alt='ScanPlay'><div style='font:800 22px Arial,Helvetica,sans-serif;color:#0F0A1F;margin-top:12px;letter-spacing:-.5px'>Scan<span style=\"color:#7C3AED\">Play</span></div></td></tr>
<tr><td align='center' style='padding:10px 32px 8px'><div style='font:800 22px Arial,Helvetica,sans-serif;color:#0F0A1F;letter-spacing:-.4px'>$title</div><div style='font:15px/1.6 Arial,Helvetica,sans-serif;color:#4B4661;margin-top:8px'>$lead</div></td></tr>
<tr><td align='center' style='padding:6px 32px 8px;font:15px/1.6 Arial,Helvetica,sans-serif;color:#4B4661'>$body$codeBlock$cta</td></tr>
<tr><td style='padding:22px 32px 0'><div style='height:1px;background:#EDE9F7'></div></td></tr>
<tr><td align='center' style='padding:18px 32px 30px;font:12px/1.7 Arial,Helvetica,sans-serif;color:#8B84A0'>
<b style='color:#4B4661'>Need help?</b> WhatsApp <a href='https://wa.me/918985849710' style='color:#7C3AED;text-decoration:none'>+91 89858 49710</a> &middot; <a href='mailto:info@scanplay.in' style='color:#7C3AED;text-decoration:none'>info@scanplay.in</a><br>
<a href='https://scanplay.in' style='color:#7C3AED;text-decoration:none'>scanplay.in</a> &middot; <a href='https://play.google.com/store/apps/details?id=in.scanplay.app' style='color:#7C3AED;text-decoration:none'>Android app</a> &middot; <a href='https://scanplay.in/privacy.html' style='color:#8B84A0'>Privacy</a> &middot; <a href='https://scanplay.in/terms.html' style='color:#8B84A0'>Terms</a><br>
Made with <span style='color:#FF4D6D'>&hearts;</span> in India &middot; &copy; ScanPlay LLP, Visakhapatnam &middot; All rights reserved.</td></tr>
</table></td></tr></table></body></html>";
}
function codeMail($name, $code, $what) {
  $t = $what==='password reset' ? 'Reset your password' : 'Verify your email';
  return mailTpl($t, "Hi ".htmlspecialchars($name).", use this code to ".($what==='password reset'?'set a new password.':'finish creating your ScanPlay account.'), "<div style='font:13px Arial;color:#8B84A0'>If you didn't request this, you can ignore this email.</div>", null, null, $code);
}
function issueCode($u, $what) {
  $code = str_pad((string)random_int(0,999999), 6, '0', STR_PAD_LEFT);
  q("UPDATE users SET code=?, code_exp=? WHERE id=?", [password_hash($code, PASSWORD_DEFAULT), now()+900, $u['id']]);
  return sendMail($u['email'], "ScanPlay $what code: $code", codeMail($u['name'], $code, $what));
}
function checkCode($u, $code) { return $u['code'] && (int)$u['code_exp'] > now() && password_verify(trim($code), $u['code']); }
/* Turn share links into direct-download links; reject streaming sites */
function youtubeId($url) {
  if (preg_match('#(?:youtu\.be/|youtube\.com/(?:watch\?v=|shorts/|embed/|v/))([\w-]{11})#i', $url, $m)) return $m[1];
  return null;
}
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
  return 'active';   // token model: no subscriptions, nothing expires
  if (($u['plan'] ?? 'free') === 'free') return 'active';   // Free is forever
  $until = (int)$u['plan_until'];
  if (now() <= $until) return 'active';
  if (now() <= $until + GRACE_DAYS*86400) return 'grace';
  return 'expired';
}
function userInfo($u) {
  $p = PLANS[$u['plan']] ?? PLANS['free']; $p['photos'] += (int)($u['extra_photos'] ?? 0); if ($p['accounts']) $p['accounts'] += (int)($u['extra_accounts'] ?? 0);
  $photos = (int)row("SELECT COUNT(*) c FROM items i JOIN accounts a ON a.code=i.code WHERE a.user_id=?", [$u['id']])['c'];
  $accs   = (int)row("SELECT COUNT(*) c FROM accounts WHERE user_id=?", [$u['id']])['c'];
  $p = ['name'=>'Tokens','photos'=>0,'accounts'=>0,'ppp'=>0,'logo'=>true,'analytics'=>true,'sub'=>true,'domain'=>false,'watermark'=>false];   // token model: no caps, all features
  $parent = !empty($u['parent_id']) ? row("SELECT * FROM users WHERE id=? AND deleted=0", [$u['parent_id']]) : null;
  $children = (int)row("SELECT COUNT(*) c FROM users WHERE parent_id=? AND deleted=0", [$u['id']])['c'];
  return ['id'=>(int)$u['id'],'email'=>$u['email'],'name'=>$u['name'],'phone'=>$u['phone'],'plan'=>'tokens','planName'=>'Tokens',
    'planUntil'=>0,'state'=>'active','limits'=>$p,'used'=>['photos'=>$photos,'accounts'=>$accs],
    'tokens'=>(int)($u['tokens']??0),'tokensUsed'=>(int)($u['tokens_used']??0),'role'=>$u['role'] ?: 'user','isPartner'=>in_array($u['role'],['distributor','promoter']) || $children>0,
    'partnerCode'=>partnerCode($u),'inviteLink'=>baseUrl().'/studio.html?partner='.$u['partner_code'],
    'parent'=>$parent ? contactOf($parent) : adminContact(), 'hasParent'=>(bool)$parent, 'children'=>$children,
    'business'=>$u['business'],'lat'=>$u['lat'],'lng'=>$u['lng'],'address'=>$u['address'],'whatsapp'=>$u['whatsapp'],'pay_details'=>$u['pay_details'],'area'=>$u['area'],'listed'=>(int)($u['listed']??0),
    'logo'=>$u['logo'] ? "data/users/{$u['id']}/logo.png?v={$u['logo']}" : null, 'graceDays'=>GRACE_DAYS];
}
function ip() { return $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '0'; }
function rateLimit($bucket, $max, $windowSec) {
  global $db; $db->exec("CREATE TABLE IF NOT EXISTS ratelimit (k TEXT PRIMARY KEY, n INTEGER, reset INTEGER)");
  $k = $bucket.':'.ip(); $r = row("SELECT n,reset FROM ratelimit WHERE k=?", [$k]); $now = now();
  if (!$r || $r['reset'] < $now) { q("INSERT INTO ratelimit (k,n,reset) VALUES (?,1,?) ON CONFLICT(k) DO UPDATE SET n=1, reset=excluded.reset", [$k, $now+$windowSec]); return; }
  if ($r['n'] >= $max) { http_response_code(429); out(false, ['error'=>'Too many attempts. Please wait a few minutes and try again.']); }
  q("UPDATE ratelimit SET n=n+1 WHERE k=?", [$k]);
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
function ownerAuth() {
  rateLimit('admin', 300, 600);
  if (OWNER_PASS === '') out(false, ['error'=>'Admin disabled: set owner_pass in config.php']);
  $ip = $_SERVER['REMOTE_ADDR'] ?? '?'; $ff = DATA_DIR.'/admin_fails.json';
  $fails = @json_decode(@file_get_contents($ff), true) ?: [];
  $rec = $fails[$ip] ?? ['n'=>0,'t'=>0];
  if ($rec['n'] >= 10 && now() - $rec['t'] < 900) out(false, ['error'=>'Too many attempts. Try again in 15 minutes.']);
  if (!hash_equals(OWNER_PASS, $_SERVER['HTTP_X_ADMIN_PASS'] ?? '')) {
    $rec['n'] = (now() - $rec['t'] < 900) ? $rec['n'] + 1 : 1; $rec['t'] = now(); $fails[$ip] = $rec;
    @file_put_contents($ff, json_encode($fails)); out(false, ['error'=>'Not allowed']);
  }
  if (isset($fails[$ip])) { unset($fails[$ip]); @file_put_contents($ff, json_encode($fails)); }
}
function pubAccount($a) {
  $items = rows("SELECT * FROM items WHERE code=? ORDER BY created", [$a['code']]);
  foreach ($items as &$it) { $it['thumb'] = "data/{$a['code']}/{$it['id']}/target.jpg"; $it['hits'] = (int)(row("SELECT SUM(n) s FROM item_hits WHERE item_id=?", [$it['id']])['s'] ?? 0); }
  $scans = (int)(row("SELECT SUM(n) s FROM scans WHERE code=?", [$a['code']])['s'] ?? 0);
  $scans30 = (int)(row("SELECT SUM(n) s FROM scans WHERE code=? AND day>=?", [$a['code'], date('Y-m-d', now()-30*86400)])['s'] ?? 0);
  return ['code'=>$a['code'],'name'=>$a['name'],'blocked'=>(int)($a['blocked']??0),'showcase'=>(int)($a['showcase']??0),'public'=>(int)($a['public']??1),'created'=>(int)$a['created'],'items'=>$items,'qrUrl'=>baseUrl().'/view.html?c='.$a['code'],'scans'=>$scans,'scans30'=>$scans30];
}

$action = $_REQUEST['action'] ?? '';
if ($_SERVER['REQUEST_METHOD']==='POST' && !$_POST && !$_FILES && ($_SERVER['CONTENT_LENGTH'] ?? 0) > 1048576)
  out(false, ['error'=>'Upload too large for server ('.round($_SERVER['CONTENT_LENGTH']/1048576).' MB). Use a smaller video.']);

switch ($action) {

  /* ---------- auth ---------- */
  case 'config': out(true, ['google'=> GOOGLE_CLIENT_ID !== 'CHANGE_ME.apps.googleusercontent.com' ? GOOGLE_CLIENT_ID : null, 'mail'=>mailConfigured()]);

  case 'invite_info': { $pc=clean($_GET['code']??''); $p=$pc!==''?row("SELECT name,business,role FROM users WHERE upper(partner_code)=? AND deleted=0",[strtoupper($pc)]):null;
    if (!$p) out(false, ['error'=>'Invalid invite link']); out(true, ['from'=>$p['business']?:$p['name'], 'fromRole'=>$p['role'], 'youBecome'=>childRole($p['role'])]); }
  case 'signup': {
    rateLimit('signup', 6, 3600);
    $email = strtolower(trim($_POST['email'] ?? '')); $pass = $_POST['pass'] ?? ''; $name = trim(strip_tags($_POST['name'] ?? '')); $phone = preg_replace('/\D/','',$_POST['phone'] ?? '');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) out(false, ['error'=>'Enter a valid email']);
    if (strlen($pass) < 6) out(false, ['error'=>'Password must be at least 6 characters']);
    if ($name === '') out(false, ['error'=>'Enter your name']);
    $pc = clean($_POST['partner'] ?? ''); $pp = $pc!=='' ? row("SELECT role FROM users WHERE upper(partner_code)=? AND deleted=0", [strtoupper($pc)]) : null;
    if ($pp && $pp['role']==='distributor' && (empty($_POST['lat']) || empty($_POST['lng']))) out(false, ['error'=>'Please set your business location on the map']);
    $ex = row("SELECT * FROM users WHERE email=?", [$email]);
    if ($ex && $ex['verified']) out(false, ['error'=>'An account with this email already exists. Sign in instead.']);
    if ($ex) q("UPDATE users SET pass=?, name=?, phone=? WHERE id=?", [password_hash($pass, PASSWORD_DEFAULT), $name, $phone, $ex['id']]);
    else q("INSERT INTO users (email,pass,name,phone,plan,plan_until,created,verified) VALUES (?,?,?,?,'free',?,?,0)", [$email, password_hash($pass, PASSWORD_DEFAULT), $name, $phone, now()+7*86400, now()]);
    $isNew = !$ex;
    $u = row("SELECT * FROM users WHERE email=?", [$email]);
    saveLocation($u['id']);
    /* referral: only brand-new accounts count; linked once at creation, self-referral blocked */
    $ref = clean($_POST['ref'] ?? '');
    if ($isNew && $ref !== '') { $rr = row("SELECT id FROM users WHERE lower(referral_code)=?", [$ref]); if ($rr && (int)$rr['id'] !== (int)$u['id']) q("UPDATE users SET referrer_id=? WHERE id=? AND referrer_id IS NULL", [$rr['id'], $u['id']]); }
    if ($isNew && !empty($_POST['partner'])) linkParent($u['id'], clean($_POST['partner']));
    if ($isNew) { q("UPDATE users SET tokens=COALESCE(tokens,0)+2 WHERE id=?", [$u['id']]); ledger(0, $u['id'], 2, 'grant', 'welcome — 2 free tokens'); }   // every new account: 2 free tokens to try it
    logAct($u['id'],'signup',$email);
    if (!mailConfigured()) { q("UPDATE users SET verified=1 WHERE id=?", [$u['id']]); out(true, ['token'=>issueToken($u['id']), 'user'=>userInfo(row("SELECT * FROM users WHERE id=?", [$u['id']]))]); }
    issueCode($u, 'verification'); out(true, ['needVerify'=>true, 'email'=>$email]);
  }
  case 'verify': {
    rateLimit('verify', 15, 900);
    $email = strtolower(trim($_POST['email'] ?? '')); $u = row("SELECT * FROM users WHERE email=? AND deleted=0", [$email]);
    if (!$u || !checkCode($u, $_POST['code'] ?? '')) out(false, ['error'=>'Wrong or expired code']);
    q("UPDATE users SET verified=1, plan_until=MAX(plan_until, ?) WHERE id=?", [now()+7*86400, $u['id']]); logAct($u['id'],'verified');
    out(true, ['token'=>issueToken($u['id']), 'user'=>userInfo(row("SELECT * FROM users WHERE id=?", [$u['id']]))]);
  }
  case 'resend': {
    rateLimit('resend', 5, 900);
    $email = strtolower(trim($_POST['email'] ?? '')); $u = row("SELECT * FROM users WHERE email=? AND deleted=0", [$email]);
    if ($u) issueCode($u, isset($_POST['reset']) ? 'password reset' : 'verification'); out(true);
  }
  case 'login': {
    rateLimit('login', 20, 600);
    $email = strtolower(trim($_POST['email'] ?? '')); $pass = $_POST['pass'] ?? '';
    $u = row("SELECT * FROM users WHERE email=? AND deleted=0", [$email]);
    if (!$u || !$u['pass'] || !password_verify($pass, $u['pass'])) { if ($u) logAct($u['id'],'login_failed'); out(false, ['error'=>'Wrong email or password']); }
    if (!$u['verified'] && mailConfigured()) { issueCode($u, 'verification'); out(true, ['needVerify'=>true, 'email'=>$email]); }
    if (empty($u['parent_id']) && !empty($_POST['partner'])) { linkParent($u['id'], clean($_POST['partner'])); $u = row("SELECT * FROM users WHERE id=?", [$u['id']]); }   // came in through an invite link: attach
    logAct($u['id'],'login'); out(true, ['token'=>issueToken($u['id']), 'user'=>userInfo($u)]);
  }
  case 'forgot': {
    rateLimit('forgot', 5, 900);
    $email = strtolower(trim($_POST['email'] ?? '')); $u = row("SELECT * FROM users WHERE email=? AND deleted=0", [$email]);
    if (!mailConfigured()) out(false, ['error'=>'Password reset by email is not set up yet. Message us on WhatsApp.']);
    if ($u) issueCode($u, 'password reset'); out(true);   // same answer whether or not the email exists
  }
  case 'reset': {
    rateLimit('reset', 10, 900);
    $email = strtolower(trim($_POST['email'] ?? '')); $u = row("SELECT * FROM users WHERE email=? AND deleted=0", [$email]);
    if (!$u || !checkCode($u, $_POST['code'] ?? '')) out(false, ['error'=>'Wrong or expired code']);
    if (strlen($_POST['newpass'] ?? '') < 6) out(false, ['error'=>'Password must be at least 6 characters']);
    q("UPDATE users SET pass=?, verified=1 WHERE id=?", [password_hash($_POST['newpass'], PASSWORD_DEFAULT), $u['id']]); logAct($u['id'],'password_reset');
    out(true, ['token'=>issueToken($u['id']), 'user'=>userInfo(row("SELECT * FROM users WHERE id=?", [$u['id']]))]);
  }
  case 'google': {
    rateLimit('google', 20, 600);
    $cred = $_POST['credential'] ?? ''; if (!$cred) out(false, ['error'=>'No Google credential']);
    $info = json_decode(@file_get_contents('https://oauth2.googleapis.com/tokeninfo?id_token='.urlencode($cred)), true);
    if (!$info || ($info['aud'] ?? '') !== GOOGLE_CLIENT_ID || empty($info['email']) || ($info['email_verified'] ?? 'false') !== 'true') out(false, ['error'=>'Google sign-in could not be verified']);
    $email = strtolower($info['email']); $u = row("SELECT * FROM users WHERE email=?", [$email]);
    if (!$u) { q("INSERT INTO users (email,pass,name,phone,plan,plan_until,created,verified,google_id) VALUES (?,NULL,?,'','free',?,?,1,?)", [$email, $info['name'] ?? $email, now()+7*86400, now(), $info['sub']]); $u = row("SELECT * FROM users WHERE email=?", [$email]);
      $ref = clean($_POST['ref'] ?? ''); if ($ref !== '') { $rr = row("SELECT id FROM users WHERE lower(referral_code)=?", [$ref]); if ($rr && (int)$rr['id'] !== (int)$u['id']) q("UPDATE users SET referrer_id=? WHERE id=?", [$rr['id'], $u['id']]); }
      if (!empty($_POST['partner'])) linkParent($u['id'], clean($_POST['partner']));
      q("UPDATE users SET tokens=COALESCE(tokens,0)+2 WHERE id=?", [$u['id']]); ledger(0, $u['id'], 2, 'grant', 'welcome — 2 free tokens'); saveLocation($u['id']); }
    else { q("UPDATE users SET verified=1, google_id=?, deleted=0 WHERE id=?", [$info['sub'], $u['id']]); if (empty($u['lat'])) saveLocation($u['id']); if (empty($u['parent_id']) && !empty($_POST['partner'])) linkParent($u['id'], clean($_POST['partner'])); }
    logAct($u['id'],'login_google',$email);
    out(true, ['token'=>issueToken($u['id']), 'user'=>userInfo(row("SELECT * FROM users WHERE id=?", [$u['id']]))]);
  }
  case 'logout': { $u = auth(); q("UPDATE users SET token=NULL WHERE id=?", [$u['id']]); out(true); }
  case 'referral': {
    $u = auth();
    if (empty($u['referral_code'])) { $c = strtoupper(substr(bin2hex(random_bytes(5)),0,8)); q("UPDATE users SET referral_code=? WHERE id=?", [$c, $u['id']]); $u['referral_code'] = $c; }
    $earned = (int)(row("SELECT COUNT(*) c FROM users WHERE referrer_id=? AND ref_awarded=1", [$u['id']])['c'] ?? 0);
    out(true, ['code'=>$u['referral_code'], 'earned'=>$earned, 'link'=>'https://scanplay.in/studio.html?ref='.$u['referral_code']]);
  }
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
    /* token model: unlimited projects; tokens are spent per photo */
    $code = substr(bin2hex(random_bytes(4)),0,8);
    q("INSERT INTO accounts (code,user_id,name,created) VALUES (?,?,?,?)", [$code, $u['id'], $name, now()]);
    mkdir(DATA_DIR."/$code", 0755, true); logAct($u['id'],'project_create',"$code $name");
    out(true, ['code'=>$code]);
  }
  case 'account_public': {
    $u = auth(); $code = clean($_POST['code'] ?? '');
    if (!row("SELECT code FROM accounts WHERE code=? AND user_id=?", [$code, $u['id']])) out(false, ['error'=>'Project not found']);
    q("UPDATE accounts SET public=? WHERE code=?", [(int)!!($_POST['public']??1), $code]); logAct($u['id'],'project_public',"$code=".(int)!!($_POST['public']??1)); out(true);
  }
  case 'account_delete': {
    $u = auth(); $code = clean($_POST['code'] ?? '');
    if (!row("SELECT code FROM accounts WHERE code=? AND user_id=?", [$code, $u['id']])) out(false, ['error'=>'Account not found']);
    q("DELETE FROM item_hits WHERE item_id IN (SELECT id FROM items WHERE code=?)", [$code]); q("DELETE FROM items WHERE code=?", [$code]); q("DELETE FROM scans WHERE code=?", [$code]); q("DELETE FROM accounts WHERE code=?", [$code]);
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
    if ((int)($u['tokens']??0) < 1) { $inf = userInfo($u); $pc = $inf['parent'];
      $msg = $inf['hasParent'] ? 'You have no tokens left. 1 token = 1 photo + 1 video. Buy tokens from '.htmlspecialchars($pc['business'] ?: $pc['name']).($pc['whatsapp'] ? ' · WhatsApp '.$pc['whatsapp'] : '')
                               : 'Your free tokens are used. 1 token = 1 photo + 1 video. Find a ScanPlay partner near you to buy more.';
      out(false, ['error'=>$msg, 'tokens'=>0, 'findPartner'=>!$inf['hasParent']]); }
    $videoUrl = trim($_POST['video_url'] ?? ''); $upId = preg_replace('/[^a-z0-9_]/','',$_POST['video_id'] ?? '');
    $upFile = $upId ? DATA_DIR."/tmp/$upId.part" : '';
    if ($upId) { $meta=@json_decode(file_get_contents(DATA_DIR."/tmp/$upId.json"),true); if(!$meta||$meta['user']!=$u['id']||!file_exists($upFile)||filesize($upFile)!=$meta['size']) out(false,['error'=>'Video upload incomplete — please try again']); }
    $bulk = !empty($_POST['bulk']);
    if ((empty($_FILES['mind']) && !$bulk) || empty($_FILES['target']) || (empty($_FILES['video']) && $videoUrl==='' && !$upId))
      out(false, ['error'=>'Photo, a video (file or URL) and compiled file are all required']);
    if (!empty($_FILES['video']) && $_FILES['video']['size'] > MAX_VIDEO_MB*1048576) out(false, ['error'=>"Video must be under ".MAX_VIDEO_MB." MB"]);
    if ($videoUrl!=='' && !preg_match('#^https?://#i', $videoUrl)) out(false, ['error'=>'Video URL must start with http:// or https://']);
    $yt = $videoUrl!=='' ? youtubeId($videoUrl) : null;
    if ($videoUrl!=='' && !$yt) { [$videoUrl, $vErr] = resolveVideoUrl($videoUrl); if ($vErr) out(false, ['error'=>$vErr]); }

    $id = substr(bin2hex(random_bytes(5)),0,8); $dir = DATA_DIR."/$code/$id"; mkdir($dir, 0755, true);
    move_uploaded_file($_FILES['target']['tmp_name'], "$dir/target.jpg");
    if ($yt) { /* YouTube: no file, floating player */ }
    elseif ($upId) { rename($upFile, "$dir/video.mp4"); @unlink(DATA_DIR."/tmp/$upId.json"); }
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
    if (!empty($_FILES['mind'])) move_uploaded_file($_FILES['mind']['tmp_name'], DATA_DIR."/$code/targets.mind");
    if (!$yt) compressVideo($dir);
    q("INSERT INTO items (id,code,title,ratio,vratio,fit,created,yt) VALUES (?,?,?,?,?,?,?,?)",
      [$id, $code, trim(strip_tags($_POST['title'] ?? 'Untitled')), (float)($_POST['ratio']??1), $yt ? 0.5625 : (float)($_POST['vratio']??1), in_array($_POST['fit']??'',['fill','fit','stretch'])?$_POST['fit']:'fit', now(), $yt]);
    logAct($u['id'],'photo_add',"$code/$id");
    /* spend one token - never refunded, deleting the photo does not give it back */
    $st = $db->prepare("UPDATE users SET tokens=tokens-1, tokens_used=COALESCE(tokens_used,0)+1 WHERE id=? AND tokens>0"); $st->execute([$u['id']]);
    if ($st->rowCount() !== 1) { @array_map('unlink', glob("$dir/*")); @rmdir($dir); q("DELETE FROM items WHERE id=?", [$id]); out(false, ['error'=>'You have no tokens left', 'tokens'=>0]); }
    ledger($u['id'], null, 1, 'spent', "$code/$id");
    /* referral reward: when a referred user adds their first photo, referrer gets +1 photo and +1 project */
    if (!empty($u['referrer_id']) && !(int)($u['ref_awarded'] ?? 0)) {
      q("UPDATE users SET ref_awarded=1 WHERE id=?", [$u['id']]);
      q("UPDATE users SET extra_accounts=COALESCE(extra_accounts,0)+1 WHERE id=?", [$u['referrer_id']]);
      $rf = row("SELECT * FROM users WHERE id=?", [$u['referrer_id']]);
      if ($rf) @sendMail($rf['email'], "You earned a free project on ScanPlay", mailTpl("Referral reward &#127873;", "Hi ".htmlspecialchars($rf['name']).", your friend just created their first AR photo. You earned <b>+1 free project</b> on your plan.", "Keep sharing your link to earn more.", "Open Studio", baseUrl()."/studio.html"));
      logAct($u['referrer_id'],'referral_reward','from user '.$u['id']);
    }
    $acc = row("SELECT name FROM accounts WHERE code=?", [$code]); $qr = baseUrl()."/view.html?c=$code";
    @sendMail($u['email'], "Your AR photo is ready — ".$acc['name'], mailTpl("Your AR photo is ready &#127881;", "Hi ".htmlspecialchars($u['name']).", <b>".htmlspecialchars(trim(strip_tags($_POST['title'] ?? 'Untitled')))."</b> in project <b>".htmlspecialchars($acc['name'])."</b> is linked and live.", "Open the ScanPlay Scanner, point at the printed photo, and it plays. Print the QR too if you like &mdash; it's optional.<br><span style='font-size:13px;color:#8B84A0'>Tip: matte paper, good light, at least 4&times;6 inches.</span>", "Open the player", $qr));
    out(true, ['id'=>$id]);
  }
  case 'mind_update': {   // bulk add: one re-analysis for the whole batch
    $u = auth(); requireWritable($u); $code = clean($_POST['code'] ?? '');
    if (!row("SELECT code FROM accounts WHERE code=? AND user_id=?", [$code, $u['id']])) out(false, ['error'=>'Account not found']);
    if (empty($_FILES['mind'])) out(false, ['error'=>'Compiled file missing']);
    move_uploaded_file($_FILES['mind']['tmp_name'], DATA_DIR."/$code/targets.mind"); out(true);
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

  /* ---------- universal scanner index (public projects of active users) ---------- */
  case 'scanner': {
    $cut = now() - GRACE_DAYS*86400;
    $accs = rows("SELECT a.code, a.name, u.plan, u.plan_until, u.logo, u.id uid, (SELECT MAX(created) FROM items i WHERE i.code=a.code) last FROM accounts a JOIN users u ON u.id=a.user_id
                  WHERE a.blocked=0 AND u.deleted=0 AND ? > 0 AND EXISTS (SELECT 1 FROM items i WHERE i.code=a.code) ORDER BY last DESC LIMIT 40", [$cut]);
    $list = [];
    foreach ($accs as $x) {
      if (!file_exists(DATA_DIR."/{$x['code']}/targets.mind")) continue;
      $items = rows("SELECT id,title,ratio,vratio,fit,yt FROM items WHERE code=? ORDER BY created", [$x['code']]);
      foreach ($items as &$it) { $it['video'] = $it['yt'] ? null : "data/{$x['code']}/{$it['id']}/video.mp4"; $it['thumb'] = "data/{$x['code']}/{$it['id']}/target.jpg"; }
      $p = PLANS[$x['plan']] ?? PLANS['free'];
      $list[] = ['code'=>$x['code'], 'name'=>$x['name'], 'mind'=>"data/{$x['code']}/targets.mind?v=".filemtime(DATA_DIR."/{$x['code']}/targets.mind"), 'items'=>$items,
                 'watermark'=>$p['watermark'], 'logo'=>($p['logo'] && $x['logo']) ? "data/users/{$x['uid']}/logo.png?v={$x['logo']}" : null];
    }
    out(true, ['projects'=>$list, 'count'=>count($list)]);
  }
  /* homepage showcase: only projects an admin has explicitly marked, owner plan still active, uploaded (non-YouTube) videos only */
  case 'showcase': {
    $cut = now() - GRACE_DAYS*86400;
    $rows = rows("SELECT a.code, a.name, i.id iid, i.title, i.ratio FROM accounts a JOIN users u ON u.id=a.user_id JOIN items i ON i.code=a.code
                  WHERE a.showcase=1 AND a.blocked=0 AND u.deleted=0 AND ? > 0 AND (i.yt IS NULL OR i.yt='') ORDER BY RANDOM() LIMIT 6", [$cut]);
    $list = [];
    foreach ($rows as $r) { $d = DATA_DIR."/{$r['code']}/{$r['iid']}"; if (!file_exists("$d/target.jpg") || !file_exists("$d/video.mp4")) continue;
      $list[] = ['img'=>"data/{$r['code']}/{$r['iid']}/target.jpg", 'video'=>"data/{$r['code']}/{$r['iid']}/video.mp4", 'title'=>$r['name'], 'sub'=>$r['title']]; }
    header('Cache-Control: no-store'); out(true, ['items'=>$list]);
  }
  case 'scan_hit': { $code = clean($_POST['c'] ?? ''); $iid = clean($_POST['i'] ?? '');
    if ($iid !== '') q("INSERT INTO item_hits (item_id,day,n) VALUES (?,?,1) ON CONFLICT(item_id,day) DO UPDATE SET n=n+1", [$iid, date('Y-m-d')]);
    elseif ($code) q("INSERT INTO scans (code,day,n) VALUES (?,?,1) ON CONFLICT(code,day) DO UPDATE SET n=n+1", [$code, date('Y-m-d')]);
    out(true); }

  /* ---------- public player ---------- */
  case 'get': {
    $code = clean($_GET['c'] ?? '');
    $a = row("SELECT a.*, u.plan, u.plan_until, u.logo, u.id uid FROM accounts a JOIN users u ON u.id=a.user_id WHERE a.code=? AND u.deleted=0", [$code]);
    if (!$a) out(false, ['error'=>'This QR is not linked to any account']);
    if (!empty($a['blocked'])) out(false, ['error'=>'This content is unavailable']);
    $state = planState($a);
    
    $items = rows("SELECT id,title,ratio,vratio,fit,yt FROM items WHERE code=? ORDER BY created", [$code]);
    foreach ($items as &$it) $it['video'] = $it['yt'] ? null : "data/$code/{$it['id']}/video.mp4";
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
    if (empty($res['id'])) { logAct($u['id'],'payment_error',json_encode($res['error'] ?? $res)); out(false, ['error'=>'Could not start payment: '.($res['error']['description'] ?? 'Razorpay did not respond. Check that the Razorpay account is activated and the live keys are in config.php.')]); }
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
      $pn = PLANS[$pay['plan']]['name']; $amt = number_format($pay['amount']/100);
      @sendMail($u['email'], "Payment received — ScanPlay $pn", mailTpl("Payment received &#9989;", "Hi ".htmlspecialchars($u['name']).", thank you! Your <b>$pn</b> plan (".($pay['period']==='year'?'1 year':'1 month').") is now active.",
        "<table role='presentation' style='margin:16px auto;font:14px Arial;color:#4B4661;text-align:left' cellpadding='6'><tr><td>Amount</td><td><b>&#8377;$amt</b></td></tr><tr><td>Payment ID</td><td>$pid</td></tr><tr><td>Order ID</td><td>$oid</td></tr><tr><td>Valid until</td><td>".date('d M Y', $base+$add)."</td></tr></table><div style='font:12px Arial;color:#8B84A0'>Keep this email as your receipt. For a GST invoice, reply with your GSTIN.</div>", "Open ScanPlay Studio", baseUrl().'/studio.html'));
      $add = $pay['period']==='year' ? 365*86400 : 30*86400;
      $base = ($u['plan']===$pay['plan'] && (int)$u['plan_until'] > now()) ? (int)$u['plan_until'] : now();   // extend if same plan still active
      q("UPDATE users SET plan=?, plan_until=? WHERE id=?", [$pay['plan'], $base+$add, $u['id']]);
    }
    out(true, ['user'=>userInfo(row("SELECT * FROM users WHERE id=?", [$u['id']]))]);
  }

  /* ---------- Razorpay webhook (backup activation: payment.captured / order.paid) ---------- */
  case 'rzp_webhook': {
    $raw = file_get_contents('php://input'); $sig = $_SERVER['HTTP_X_RAZORPAY_SIGNATURE'] ?? '';
    if (!RZP_WEBHOOK_SECRET || !hash_equals(hash_hmac('sha256', $raw, RZP_WEBHOOK_SECRET), $sig)) { http_response_code(400); out(false, ['error'=>'bad signature']); }
    $ev = json_decode($raw, true); $pmt = $ev['payload']['payment']['entity'] ?? null;
    if ($pmt && in_array($ev['event'] ?? '', ['payment.captured','order.paid'])) {
      $pay = row("SELECT * FROM payments WHERE order_id=?", [$pmt['order_id'] ?? '']);
      if ($pay && $pay['status'] !== 'paid') {
        q("UPDATE payments SET payment_id=?, status='paid' WHERE id=?", [$pmt['id'], $pay['id']]);
        $u = row("SELECT * FROM users WHERE id=?", [$pay['user_id']]);
        $add = $pay['period']==='year' ? 365*86400 : 30*86400; $base = ($u['plan']===$pay['plan'] && (int)$u['plan_until'] > now()) ? (int)$u['plan_until'] : now();
        q("UPDATE users SET plan=?, plan_until=? WHERE id=?", [$pay['plan'], $base+$add, $u['id']]);
        if (!empty($pay['promo'])) q("UPDATE promos SET uses=uses+1 WHERE code=?", [$pay['promo']]);
        logAct($u['id'],'payment_webhook',"{$pay['plan']} {$pay['period']} {$pmt['id']}");
      }
    }
    out(true);
  }

  /* ---------- analytics ---------- */
  case 'stats': {
    $u = auth(); $code = clean($_GET['code'] ?? $_POST['code'] ?? '');
    if (!(PLANS[$u['plan']]['analytics'] ?? false)) out(false, ['error'=>'Analytics is included from the Business plan.','upgrade'=>true]);
    if (!row("SELECT code FROM accounts WHERE code=? AND user_id=?", [$code, $u['id']])) out(false, ['error'=>'Account not found']);
    out(true, ['days'=>rows("SELECT day, n FROM scans WHERE code=? AND day>=? ORDER BY day", [$code, date('Y-m-d', now()-30*86400)])]);
  }

  /* ---------- owner admin ---------- */
  /* ---------- partner panel (distributor / retailer / anyone with children) ---------- */
  case 'partner_children': {
    $u = auth();
    $tree = function($pid, $depth) use (&$tree) {
      $k = rows("SELECT id,name,email,phone,business,role,tokens,tokens_used,created,address,area FROM users WHERE parent_id=? AND deleted=0 ORDER BY created DESC", [$pid]);
      foreach ($k as &$c) { $c['subs'] = $depth < 4 ? $tree($c['id'], $depth+1) : []; $c['subCount'] = count($c['subs']); } return $k; };
    $kids = $tree($u['id'], 1);
    $led = rows("SELECT l.ts,l.from_id,l.to_id,l.qty,l.kind,l.note,f.name fname,t.name tname FROM ledger l LEFT JOIN users f ON f.id=l.from_id LEFT JOIN users t ON t.id=l.to_id WHERE l.from_id=? OR l.to_id=? ORDER BY l.id DESC LIMIT 100", [$u['id'],$u['id']]);
    out(true, ['children'=>$kids, 'ledger'=>$led, 'user'=>userInfo($u)]);
  }
  case 'partner_transfer': {
    rateLimit('partner_transfer', 60, 600);
    $u = auth(); $to=(int)($_POST['to']??0); $qty=(int)($_POST['qty']??0); $note=trim(strip_tags($_POST['note']??''));
    if ($qty < 1) out(false, ['error'=>'Enter how many tokens to give']);
    $c = row("SELECT * FROM users WHERE id=? AND parent_id=? AND deleted=0", [$to, $u['id']]); if (!$c) out(false, ['error'=>'That account is not under you']);
    $fresh = row("SELECT tokens FROM users WHERE id=?", [$u['id']]); if ((int)$fresh['tokens'] < $qty) out(false, ['error'=>"You only have {$fresh['tokens']} tokens"]);
    $db->beginTransaction();
    $st = $db->prepare("UPDATE users SET tokens=tokens-? WHERE id=? AND tokens>=?"); $st->execute([$qty, $u['id'], $qty]);
    if ($st->rowCount() !== 1) { $db->rollBack(); out(false, ['error'=>'Not enough tokens']); }
    q("UPDATE users SET tokens=COALESCE(tokens,0)+? WHERE id=?", [$qty, $to]); $db->commit();
    ledger($u['id'], $to, $qty, 'transfer', $note); logAct($u['id'],'token_transfer',"$qty to user $to");
    @sendMail($c['email'], "You received $qty ScanPlay tokens", mailTpl("Tokens received &#127873;", "Hi ".htmlspecialchars($c['name']).", <b>".htmlspecialchars($u['business'] ?: $u['name'])."</b> just sent you <b>$qty token".($qty>1?'s':'')."</b>. 1 token = 1 photo + 1 video.", $note ? "Note: ".htmlspecialchars($note) : "", "Open Studio", baseUrl()."/studio.html"));
    out(true, ['tokens'=>(int)row("SELECT tokens FROM users WHERE id=?", [$u['id']])['tokens']]);
  }
  case 'partner_link': {
    rateLimit('partner_link', 20, 600);   // attach an existing account (by email) under me, if it has no parent yet
    $u = auth(); $email = strtolower(trim($_POST['email']??''));
    $c = row("SELECT * FROM users WHERE email=? AND deleted=0", [$email]); if (!$c) out(false, ['error'=>'No account with that email. Ask them to sign up with your invite link.']);
    if ((int)$c['id']===(int)$u['id']) out(false, ['error'=>'That is you']);
    if (!empty($c['parent_id'])) out(false, ['error'=>'That account is already linked to another partner']);
    $cr = childRole($u['role']); q("UPDATE users SET parent_id=?, role=?, listed=? WHERE id=?", [$u['id'], $cr, $cr==='user'?0:1, $c['id']]); logAct($u['id'],'partner_link',$email); out(true);
  }
  case 'partner_profile': {   // what my child accounts see when they need to buy tokens
    $u = auth();
    if (in_array($u['role'],['distributor','promoter']) && !empty($_POST['listed']) && (empty($_POST['lat']) || empty($_POST['lng']))) out(false, ['error'=>'Set your business location on the map to be shown in "Partners near me"']);
    q("UPDATE users SET business=?, whatsapp=?, pay_details=?, area=?, listed=? WHERE id=?",
      [trim(strip_tags($_POST['business']??'')), preg_replace('/\D/','',$_POST['whatsapp']??''), trim(strip_tags($_POST['pay_details']??'')), trim(strip_tags($_POST['area']??'')), (int)!!($_POST['listed']??0), $u['id']]);
    saveLocation($u['id']);
    out(true, ['user'=>userInfo(row("SELECT * FROM users WHERE id=?", [$u['id']]))]);
  }
  case 'retailers': {   // public: partners who chose to be listed on the website
    $list = rows("SELECT name,business,area,whatsapp,phone,role,lat,lng,address FROM users WHERE listed=1 AND role IN ('promoter','distributor') AND deleted=0 ORDER BY area, business");
    $lat=(float)($_REQUEST['lat']??0); $lng=(float)($_REQUEST['lng']??0);
    if ($lat && $lng) { foreach ($list as &$x) $x['km'] = ($x['lat']&&$x['lng']) ? round(kmBetween($lat,$lng,(float)$x['lat'],(float)$x['lng']),1) : null; unset($x); usort($list, fn($p,$q)=>($p['km']??9e9) <=> ($q['km']??9e9)); }
    out(true, ['retailers'=>$list, 'admin'=>adminContact(), 'near'=>(bool)($lat&&$lng)]);
  }
  /* ---------- corporate outreach: their ad + their video become a live demo, and the pitch email goes out ---------- */
  case 'admin_outreach_send': {
    ownerAuth(); set_time_limit(600);
    $email=strtolower(trim($_POST['email']??'')); $name=trim(strip_tags($_POST['name']??'')); $company=trim(strip_tags($_POST['company']??'')); $where=trim(strip_tags($_POST['where']??'')); $variant=($_POST['variant']??'a')==='b'?'b':'a';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) out(false, ['error'=>'Enter a valid email']);
    if ($company==='') out(false, ['error'=>'Enter the company name']);
    if (empty($_FILES['target']['tmp_name']) || empty($_FILES['video']['tmp_name']) || empty($_FILES['mind']['tmp_name'])) out(false, ['error'=>'Their ad photo and video are both required']);
    /* system account that holds all outreach demos */
    $su = row("SELECT * FROM users WHERE email='outreach@scanplay.in'");
    if (!$su) { q("INSERT INTO users (email,pass,name,phone,plan,plan_until,created,verified,role,tokens) VALUES (?,?,?,?,?,?,?,1,'user',0)", ['outreach@scanplay.in', password_hash(bin2hex(random_bytes(16)),PASSWORD_DEFAULT), 'ScanPlay Outreach', '', 'free', 0, now()]); $su = row("SELECT * FROM users WHERE email='outreach@scanplay.in'"); }
    $code = substr(bin2hex(random_bytes(5)),0,8); mkdir(DATA_DIR."/$code", 0755, true);
    q("INSERT INTO accounts (code,user_id,name,created) VALUES (?,?,?,?)", [$code, $su['id'], "Outreach — $company", now()]);
    $id = substr(bin2hex(random_bytes(5)),0,8); $dir = DATA_DIR."/$code/$id"; mkdir($dir, 0755, true);
    move_uploaded_file($_FILES['target']['tmp_name'], "$dir/target.jpg"); move_uploaded_file($_FILES['video']['tmp_name'], "$dir/video.mp4"); move_uploaded_file($_FILES['mind']['tmp_name'], DATA_DIR."/$code/targets.mind");
    q("INSERT INTO items (id,code,title,ratio,vratio,fit,created,yt) VALUES (?,?,?,?,?,?,?,NULL)", [$id, $code, $company.' ad', (float)($_POST['ratio']??1), (float)($_POST['vratio']??1), 'fit', now()]);
    compressVideo($dir);
    /* the email */
    $me = adminContact(); $first = $name !== '' ? explode(' ', $name)[0] : 'there';
    $view = baseUrl()."/view.html?c=$code"; $scan = baseUrl()."/scan.html";
    $whereTxt = $where !== '' ? " (the one running in $where)" : '';
    if ($variant==='a') { $subject = "Your newspaper ad can play your TV commercial"; $lead = "Dear ".htmlspecialchars($first).", your brand invests heavily in newspaper and print campaigns. Right now, every one of those pages is silent. <b>We have already made your current ad play video</b> &mdash; try it before you read further."; }
    else { $subject = "What if your print ad could talk? Try it on your own ad"; $lead = "Dear ".htmlspecialchars($first).", a full-page ad reaches millions and is forgotten when the page turns &mdash; while the video you produced for the same campaign lives only on TV and YouTube. <b>We connected the two, on your own ad.</b> Try it before you read further."; }
    $body = "<div style='background:#F6F3FF;border-radius:14px;padding:18px 20px;text-align:left;margin:18px 0'>
      <div style='font:800 15px Arial;color:#141032;margin-bottom:8px'>Try it now &mdash; 30 seconds, no app</div>
      <div style='font:15px/1.6 Arial;color:#3f3a5a'>1. Your <b>".htmlspecialchars($company)." ad</b> is attached to this email$whereTxt &mdash; open it on a screen, or use any printed copy.<br>2. On another phone, open <a href='$scan' style='color:#7C3AED;font-weight:700'>scanplay.in</a> and tap <b>Play a photo</b>.<br>3. Point the camera at the ad. <b>Your video plays right on it.</b></div>
      <div style='font:13px Arial;color:#5B5670;margin-top:8px'>Or open <a href='$view' style='color:#7C3AED'>this direct link</a> on your phone and point it at the ad.</div></div>
      <div style='font:15px/1.6 Arial;color:#3f3a5a;text-align:left'><b>What this means for your next campaign</b><br>
      &bull; The same ad space now carries 30 seconds of video, not just a headline<br>
      &bull; Every scan is counted &mdash; real engagement numbers from print, by city and by day<br>
      &bull; Works on newspapers, magazines, hoardings, brochures, packaging and point-of-sale material<br>
      &bull; Nothing changes in your media plan; a small &ldquo;ScanPlay me&rdquo; line is added to the artwork<br><br>
      We can run a pilot on your next insertion at no risk to your schedule. May I show you a two-minute live demonstration this week &mdash; a printed page and a phone are all we need?<br><br>
      Warm regards,<br><b>Team ScanPlay</b><br>".htmlspecialchars($me['business'] ?: 'ScanPlay LLP')." &middot; Visakhapatnam<br>".($me['phone']?htmlspecialchars($me['phone']).' &middot; ':'').htmlspecialchars($me['email'] ?: 'info@scanplay.in')." &middot; scanplay.in</div>";
    $html = mailTpl("Your ad, playing your video", $lead, $body, "Watch your ad play", $view);
    $ok = false; $err = '';
    try { $ok = sendMail($email, $subject, $html, [['name'=>preg_replace('/[^A-Za-z0-9]+/','-',$company).'-ad.jpg','mime'=>'image/jpeg','data'=>file_get_contents("$dir/target.jpg")]]); if (!$ok) $err = $GLOBALS['MAIL_ERR'] ?: 'Mail server refused the message'; } catch (Throwable $t) { $err = $t->getMessage(); }
    q("INSERT INTO outreach (ts,email,name,company,code,item,subject,sent,error) VALUES (?,?,?,?,?,?,?,?,?)", [now(), $email, $name, $company, $code, $id, $subject, $ok?1:0, $err]);
    logAct($su['id'], 'outreach', "$company <$email> ".($ok?'sent':'FAILED '.$err), 'admin');
    out($ok, ['code'=>$code, 'view'=>$view, 'error'=>$ok?null:('Demo created but email not sent: '.$err)]);
  }
  case 'admin_mail_test': { ownerAuth(); $to=strtolower(trim($_POST['to']??'')); if(!filter_var($to,FILTER_VALIDATE_EMAIL)) out(false,['error'=>'Enter an email']); $ok=sendMail($to,'ScanPlay test email',mailTpl('Test email','If you can read this, email from '.SMTP_USER.' is working.')); out($ok, ['error'=>$ok?null:$GLOBALS['MAIL_ERR'], 'from'=>SMTP_USER, 'host'=>SMTP_HOST]); }
  case 'admin_outreach_list': { ownerAuth(); out(true, ['list'=>rows("SELECT o.*, (SELECT COALESCE(SUM(n),0) FROM scans s WHERE s.code=o.code) scans, (SELECT COALESCE(SUM(n),0) FROM item_hits h WHERE h.item_id=o.item) plays FROM outreach o ORDER BY o.id DESC LIMIT 200")]); }
  case 'admin_outreach_delete': { ownerAuth(); $id=(int)($_POST['id']??0); $o=row("SELECT * FROM outreach WHERE id=?",[$id]); if($o){ q("DELETE FROM items WHERE code=?",[$o['code']]); q("DELETE FROM scans WHERE code=?",[$o['code']]); q("DELETE FROM accounts WHERE code=?",[$o['code']]); rrmdir(DATA_DIR."/{$o['code']}"); q("DELETE FROM outreach WHERE id=?",[$id]); } out(true); }
  /* ---------- homepage partner slides ---------- */
  case 'slides': { header('Cache-Control: no-store'); out(true, ['slides'=>array_map(fn($s)=>$s+['img'=>$s['photo']?"data/slides/{$s['id']}.jpg?v={$s['photo']}":($s['img_url']?:null)], rows("SELECT id,city,status,title,text,whatsapp,photo,img_url FROM slides ORDER BY sort, id")), 'admin'=>adminContact()]); }
  case 'admin_slides': { ownerAuth(); out(true, ['slides'=>rows("SELECT * FROM slides ORDER BY sort, id")]); }
  case 'admin_slide_save': {
    ownerAuth(); $id=(int)($_POST['id']??0); $f=['city'=>trim(strip_tags($_POST['city']??'')),'status'=>($_POST['status']??'open')==='partner'?'partner':'open','title'=>trim(strip_tags($_POST['title']??'')),'text'=>trim(strip_tags($_POST['text']??'')),'whatsapp'=>preg_replace('/\D/','',$_POST['whatsapp']??''),'sort'=>(int)($_POST['sort']??0)];
    if ($f['city']==='') out(false, ['error'=>'City is required']);
    if ($id) q("UPDATE slides SET city=?,status=?,title=?,text=?,whatsapp=?,sort=? WHERE id=?", [$f['city'],$f['status'],$f['title'],$f['text'],$f['whatsapp'],$f['sort'],$id]);
    else { q("INSERT INTO slides (sort,city,status,title,text,whatsapp,created) VALUES (?,?,?,?,?,?,?)", [$f['sort'],$f['city'],$f['status'],$f['title'],$f['text'],$f['whatsapp'],now()]); $id=(int)$db->lastInsertId(); }
    if (!empty($_FILES['photo']['tmp_name'])) {
      @mkdir(DATA_DIR.'/slides', 0755, true); $dst = DATA_DIR."/slides/$id.jpg"; $src=$_FILES['photo']['tmp_name'];
      $ok=false; if (function_exists('imagecreatefromstring')) { $im=@imagecreatefromstring(file_get_contents($src)); if ($im) { $w=imagesx($im); $h=imagesy($im); $s=min(1,1200/max($w,$h)); $nw=(int)($w*$s); $nh=(int)($h*$s); $o=imagecreatetruecolor($nw,$nh); imagecopyresampled($o,$im,0,0,0,0,$nw,$nh,$w,$h); $ok=imagejpeg($o,$dst,82); imagedestroy($o); imagedestroy($im); } }
      if (!$ok) move_uploaded_file($src,$dst);
      q("UPDATE slides SET photo=? WHERE id=?", [now(), $id]);
    }
    out(true, ['id'=>$id]);
  }
  case 'admin_slide_delete': { ownerAuth(); $id=(int)($_POST['id']??0); q("DELETE FROM slides WHERE id=?", [$id]); @unlink(DATA_DIR."/slides/$id.jpg"); out(true); }
  /* ---------- distributor / promoter agreements ---------- */
  case 'admin_agreement_create': {
    ownerAuth(); $uid=(int)($_POST['user_id']??0); $u=row("SELECT * FROM users WHERE id=? AND deleted=0",[$uid]); if(!$u) out(false,['error'=>'No such user']);
    $t = json_decode($_POST['terms'] ?? '{}', true); if (!is_array($t)) out(false, ['error'=>'Bad terms']);
    $t = array_map(fn($v)=>is_string($v)?trim(strip_tags($v)):$v, $t);
    $id = date('Ymd').'-'.strtoupper(substr(bin2hex(random_bytes(3)),0,5));
    $t['number'] = 'SP-AGR-'.$id; $t['date'] = date('d F Y');
    $t['party'] = ['name'=>$u['business'] ?: $u['name'], 'person'=>$u['name'], 'address'=>$u['address'] ?: $u['area'], 'email'=>$u['email'], 'phone'=>$u['phone'], 'role'=>$u['role']];
    $t['scanplay'] = adminContact();
    q("INSERT INTO agreements (id,user_id,created,status,terms) VALUES (?,?,?,?,?)", [$id, $uid, now(), 'sent', json_encode($t, JSON_UNESCAPED_UNICODE)]);
    @mkdir(DATA_DIR."/agreements/$id", 0755, true);
    if (!empty($_POST['admin_sign'])) { $png = base64_decode(preg_replace('/^data:image\/\w+;base64,/','',$_POST['admin_sign'])); if ($png) { file_put_contents(DATA_DIR."/agreements/$id/admin.png", $png); q("UPDATE agreements SET admin_signed_at=? WHERE id=?", [now(), $id]); } }
    logAct($uid,'agreement_sent',$id,'admin');
    $ok = agreementNotify(['id'=>$id,'terms'=>json_encode($t),'email'=>$u['email'],'name'=>$u['name'],'role'=>$u['role'],'business'=>$u['business']]);
    out(true, ['id'=>$id, 'mail'=>$ok, 'mail_error'=>$ok?null:$GLOBALS['MAIL_ERR']]);
  }
  case 'admin_agreement_resend': { ownerAuth(); $id=agrId($_POST['id']??''); $g=row("SELECT g.*,u.email,u.name,u.role,u.business FROM agreements g JOIN users u ON u.id=g.user_id WHERE g.id=?",[$id]); if(!$g) out(false,['error'=>'Not found']); $ok=agreementNotify($g); out($ok, ['error'=>$ok?null:$GLOBALS['MAIL_ERR']]); }
  case 'admin_agreements': { ownerAuth(); out(true, ['agreements'=>rows("SELECT g.id,g.user_id,g.created,g.status,g.sign_name,g.signed_at,g.sign_kind,g.admin_signed_at,g.mail_sent,g.mail_err,g.mail_at,u.name,u.email,u.business,u.role FROM agreements g JOIN users u ON u.id=g.user_id ORDER BY g.created DESC")]); }
  case 'admin_agreement_delete': { ownerAuth(); $id=agrId($_POST['id']??''); $g=row("SELECT * FROM agreements WHERE id=?",[$id]); if(!$g) out(false,['error'=>'Not found']); if($g['status']==='signed'&&empty($_POST['force'])) out(false,['error'=>'Signed agreements cannot be deleted']); q("DELETE FROM agreements WHERE id=?",[$id]); @array_map('unlink', glob(DATA_DIR."/agreements/$id/*")); @rmdir(DATA_DIR."/agreements/$id"); out(true); }
  case 'admin_agreement_sign': {
    ownerAuth(); $id=agrId($_POST['id']??''); if(!row("SELECT id FROM agreements WHERE id=?",[$id])) out(false,['error'=>'Not found']);
    $png = base64_decode(preg_replace('/^data:image\/\w+;base64,/','',$_POST['sign']??'')); if(!$png) out(false,['error'=>'No signature']);
    file_put_contents(DATA_DIR."/agreements/$id/admin.png", $png); q("UPDATE agreements SET admin_signed_at=? WHERE id=?", [now(), $id]); out(true);
  }
  case 'agreement_get': {   // the party (own agreement) or admin (any)
    $id = agrId($_REQUEST['id'] ?? ''); $g = row("SELECT * FROM agreements WHERE id=?", [$id]); if (!$g) out(false, ['error'=>'Agreement not found']);
    $isAdmin = false; $hp = $_SERVER['HTTP_X_ADMIN_PASS'] ?? '';
    if ($hp !== '' && OWNER_PASS !== '' && hash_equals(OWNER_PASS, $hp)) $isAdmin = true;
    if (!$isAdmin) { $u = auth(); if ((int)$u['id'] !== (int)$g['user_id']) out(false, ['error'=>'Not your agreement']); }
    $dir = DATA_DIR."/agreements/$id"; $f = fn($n)=>file_exists("$dir/$n") ? 'data:'.(str_ends_with($n,'.pdf')?'application/pdf':'image/png').';base64,'.base64_encode(file_get_contents("$dir/$n")) : null;
    $scan = null; foreach (glob("$dir/scan.*") ?: [] as $p) { $scan = 'data:'.mime_content_type($p).';base64,'.base64_encode(file_get_contents($p)); }
    out(true, ['agreement'=>['id'=>$g['id'],'status'=>$g['status'],'created'=>(int)$g['created'],'terms'=>json_decode($g['terms'],true),'sign_name'=>$g['sign_name'],'signed_at'=>(int)$g['signed_at'],'sign_kind'=>$g['sign_kind'],'admin_signed_at'=>(int)$g['admin_signed_at'],'sign_img'=>$f('sign.png'),'admin_img'=>$f('admin.png'),'scan'=>$scan]]);
  }
  case 'my_agreements': { $u=auth(); out(true, ['agreements'=>rows("SELECT id,status,created,signed_at FROM agreements WHERE user_id=? ORDER BY created DESC", [$u['id']])]); }
  case 'agreement_sign': {
    $u = auth(); $id = agrId($_POST['id'] ?? ''); $g = row("SELECT * FROM agreements WHERE id=? AND user_id=?", [$id, $u['id']]); if (!$g) out(false, ['error'=>'Agreement not found']);
    if ($g['status']==='signed') out(false, ['error'=>'Already signed']);
    if (empty($_POST['agree'])) out(false, ['error'=>'Please tick "I have read and agree"']);
    $name = trim(strip_tags($_POST['sign_name'] ?? '')); if ($name==='') out(false, ['error'=>'Type your full name']);
    $dir = DATA_DIR."/agreements/$id"; @mkdir($dir, 0755, true); $kind = '';
    if (!empty($_POST['sign'])) { $png = base64_decode(preg_replace('/^data:image\/\w+;base64,/','',$_POST['sign'])); if (strlen($png) < 500) out(false, ['error'=>'Please draw your signature']); file_put_contents("$dir/sign.png", $png); $kind = 'drawn'; }
    elseif (!empty($_FILES['scan']['tmp_name'])) { $mime = mime_content_type($_FILES['scan']['tmp_name']); $ext = ['image/jpeg'=>'jpg','image/png'=>'png','application/pdf'=>'pdf'][$mime] ?? null; if (!$ext) out(false, ['error'=>'Upload a JPG, PNG or PDF']); if ($_FILES['scan']['size'] > 15*1048576) out(false, ['error'=>'File too large (max 15 MB)']); move_uploaded_file($_FILES['scan']['tmp_name'], "$dir/scan.$ext"); $kind = 'scan'; }
    else out(false, ['error'=>'Draw your signature or upload the signed copy']);
    q("UPDATE agreements SET status='signed', sign_name=?, signed_at=?, sign_ip=?, sign_kind=? WHERE id=?", [$name, now(), ip(), $kind, $id]);
    logAct($u['id'],'agreement_signed',$id);
    @sendMail(setting('admin_email','info@scanplay.in'), "Agreement signed: ".($u['business']?:$u['name']), mailTpl("Agreement signed &#9989;", htmlspecialchars($u['business']?:$u['name'])." signed agreement ".$id." (".$kind.").", "", "Open admin", baseUrl()."/admin.html"));
    out(true);
  }
  /* ---------- admin: token control ---------- */
  case 'admin_tokens': {   // delta may be negative: only admin can remove tokens
    ownerAuth(); $id=(int)($_POST['id']??0); $d=(int)($_POST['delta']??0); $note=trim(strip_tags($_POST['note']??'')); if (!$d) out(false, ['error'=>'Enter a number']);
    $u=row("SELECT tokens,email,name FROM users WHERE id=?", [$id]); if(!$u) out(false, ['error'=>'No such user']);
    if ($d<0 && (int)$u['tokens']+$d<0) $d = -(int)$u['tokens'];
    q("UPDATE users SET tokens=COALESCE(tokens,0)+? WHERE id=?", [$d, $id]); ledger($d>0?0:$id, $d>0?$id:0, abs($d), $d>0?'grant':'remove', $note); logAct($id,'admin_tokens',"$d $note",'admin');
    if ($d>0) @sendMail($u['email'], "You received $d ScanPlay tokens", mailTpl("Tokens received &#127873;", "Hi ".htmlspecialchars($u['name']).", ScanPlay added <b>$d token".($d>1?'s':'')."</b> to your account.", $note?htmlspecialchars($note):"", "Open Studio", baseUrl()."/studio.html"));
    out(true, ['tokens'=>(int)$u['tokens']+$d]);
  }
  case 'admin_role': {
    ownerAuth(); $id=(int)($_POST['id']??0); $role=$_POST['role']??''; if (!in_array($role, ROLES)) out(false, ['error'=>'Bad role']);
    q("UPDATE users SET role=?, listed=CASE WHEN ?='user' THEN 0 ELSE 1 END WHERE id=?", [$role, $role, $id]);   // partners are listed on the website by default
    if (isset($_POST['parent_email'])) { $pe=strtolower(trim($_POST['parent_email'])); if ($pe==='') q("UPDATE users SET parent_id=NULL WHERE id=?", [$id]); else { $p=row("SELECT id FROM users WHERE email=? AND deleted=0",[$pe]); if(!$p) out(false,['error'=>'No account with that parent email']); if((int)$p['id']===$id) out(false,['error'=>'Cannot be its own parent']); q("UPDATE users SET parent_id=? WHERE id=?", [$p['id'],$id]); } }
    logAct($id,'admin_role',$role,'admin'); out(true);
  }
  case 'admin_ledger': { ownerAuth(); out(true, ['ledger'=>rows("SELECT l.*,f.name fname,f.email femail,t.name tname,t.email temail FROM ledger l LEFT JOIN users f ON f.id=l.from_id LEFT JOIN users t ON t.id=l.to_id ORDER BY l.id DESC LIMIT 300")]); }
  case 'admin_settings': {
    ownerAuth(); if (!empty($_POST['save'])) { foreach (['admin_name','admin_business','admin_phone','admin_whatsapp','admin_email','admin_pay'] as $k) q("INSERT INTO settings (k,v) VALUES (?,?) ON CONFLICT(k) DO UPDATE SET v=excluded.v", [$k, trim(strip_tags($_POST[$k]??''))]); }
    out(true, ['settings'=>adminContact()]);
  }
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
    $led = rows("SELECT l.ts,l.from_id,l.to_id,l.qty,l.kind,l.note,f.name fname,t.name tname FROM ledger l LEFT JOIN users f ON f.id=l.from_id LEFT JOIN users t ON t.id=l.to_id WHERE l.from_id=? OR l.to_id=? ORDER BY l.id DESC LIMIT 100", [$id,$id]);
    $parent = !empty($u['parent_id']) ? row("SELECT id,name,email,role FROM users WHERE id=?", [$u['parent_id']]) : null;
    out(true, ['user'=>$u, 'accounts'=>$accs, 'payments'=>$pays, 'activity'=>$act, 'ledger'=>$led, 'parent'=>$parent, 'info'=>userInfo(row("SELECT * FROM users WHERE id=?", [$id]))]);
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
  case 'admin_showcase': { ownerAuth(); $code=clean($_POST['code']??''); q("UPDATE accounts SET showcase=? WHERE code=?", [(int)!!($_POST['showcase']??0), $code]); out(true); }
  case 'admin_block': { ownerAuth(); logAct(0,'admin_block',json_encode($_POST),'admin'); $code=clean($_POST['code']??''); q("UPDATE accounts SET blocked=? WHERE code=?", [(int)!!($_POST['blocked']??0), $code]); out(true); }
  case 'admin_account_delete': {
    ownerAuth(); logAct(0,'admin_account_delete',json_encode($_POST),'admin'); $code=clean($_POST['code']??'');
    q("DELETE FROM item_hits WHERE item_id IN (SELECT id FROM items WHERE code=?)", [$code]); q("DELETE FROM items WHERE code=?", [$code]); q("DELETE FROM scans WHERE code=?", [$code]); q("DELETE FROM accounts WHERE code=?", [$code]); rrmdir(DATA_DIR."/$code"); out(true);
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
    $html = mailTpl(htmlspecialchars($subj), "Hi {name},", nl2br(htmlspecialchars($body))."<div style='font:12px Arial;color:#8B84A0;margin-top:14px'>You receive this because you have a ScanPlay account.</div>", "Open ScanPlay Studio", baseUrl().'/studio.html');
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
    if (CRON_KEY === '' || ($_GET['key'] ?? '') !== CRON_KEY) out(false, ['error'=>'Bad key']);
    // 1. daily DB backup (keeps 30)
    $bdir = DATA_DIR.'/backups'; if (!is_dir($bdir)) mkdir($bdir, 0755, true);
    $bfile = "$bdir/db-".date('Ymd').".sqlite"; if (!file_exists($bfile)) $db->exec("VACUUM INTO '$bfile'");
    $old = glob("$bdir/db-*.sqlite"); sort($old); foreach (array_slice($old, 0, max(0, count($old)-30)) as $f) @unlink($f);
    // 2. delete data after grace
    $cut = now() - GRACE_DAYS*86400; $n=0;
    foreach (rows("SELECT id FROM users WHERE plan_until < ? AND deleted=0 AND plan!='free'", [$cut]) as $u) {
      foreach (rows("SELECT code FROM accounts WHERE user_id=?", [$u['id']]) as $a) { rrmdir(DATA_DIR."/{$a['code']}"); q("DELETE FROM items WHERE code=?", [$a['code']]); q("DELETE FROM scans WHERE code=?", [$a['code']]); $n++; }
      q("DELETE FROM accounts WHERE user_id=?", [$u['id']]);
    }
    out(true, ['accounts_removed'=>$n, 'backup'=>basename($bfile), 'backups_kept'=>count(glob("$bdir/db-*.sqlite"))]);
  }

  default: out(false, ['error'=>'Unknown action']);
}
