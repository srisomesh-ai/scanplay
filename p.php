<?php
/* Public, indexable page for one ScanPlay photo — only when the project owner turned on "Let Google find this photo".
   Purpose: Google Lens / Google Images match the printed photo to this page, and one tap opens the player. */
$cfgFile = __DIR__.'/config.php'; if (!file_exists($cfgFile)) { http_response_code(404); exit; }
$db = new PDO('sqlite:'.__DIR__.'/data/scanplay.db'); $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$code = preg_replace('/[^a-z0-9]/','',strtolower($_GET['c'] ?? '')); $id = preg_replace('/[^a-z0-9]/','',strtolower($_GET['i'] ?? ''));
$st = $db->prepare("SELECT a.code,a.name aname,a.discover,a.blocked,i.id,i.title,i.ratio,u.business,u.name uname,u.role,u.deleted FROM accounts a JOIN users u ON u.id=a.user_id JOIN items i ON i.code=a.code WHERE a.code=? AND i.id=?"); $st->execute([$code,$id]); $r = $st->fetch(PDO::FETCH_ASSOC);
if (!$r || !$r['discover'] || $r['blocked'] || $r['deleted'] || !file_exists(__DIR__."/data/$code/$id/target.jpg")) { http_response_code(404); header('X-Robots-Tag: noindex'); echo '<!doctype html><title>Not found</title><p style="font-family:sans-serif;padding:40px">This photo is not public.</p>'; exit; }
$base = 'https://'.$_SERVER['HTTP_HOST']; $img = "$base/data/$code/$id/target.jpg"; $play = "$base/view.html?c=$code"; $self = "$base/p.php?c=$code&i=$id";
$title = trim($r['title']) && $r['title']!=='Untitled' ? $r['title'] : $r['aname']; $by = $r['business'] ?: $r['uname'];
$h = fn($s)=>htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$desc = "$title — a printed photo that plays video. Point your phone at it on ScanPlay and the video plays on the print. No app.";
header('Content-Type: text/html; charset=UTF-8'); header('Cache-Control: public, max-age=600');
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?=$h($title)?> · plays video on ScanPlay</title>
<meta name="description" content="<?=$h($desc)?>"><link rel="canonical" href="<?=$h($self)?>">
<meta property="og:type" content="website"><meta property="og:title" content="<?=$h($title)?> · plays video on ScanPlay"><meta property="og:description" content="<?=$h($desc)?>"><meta property="og:image" content="<?=$h($img)?>"><meta property="og:url" content="<?=$h($self)?>">
<meta name="twitter:card" content="summary_large_image">
<link rel="icon" href="/assets/brand/favicon-32.png"><link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@500;700;800&display=swap" rel="stylesheet">
<script type="application/ld+json"><?=json_encode(['@context'=>'https://schema.org','@type'=>'ImageObject','contentUrl'=>$img,'url'=>$self,'name'=>$title,'description'=>$desc,'creator'=>['@type'=>'Organization','name'=>$by],'copyrightNotice'=>$by,'creditText'=>"$by via ScanPlay",'isPartOf'=>['@type'=>'WebSite','name'=>'ScanPlay','url'=>$base]], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)?></script>
<style>body{margin:0;background:#FBFAFF;font-family:'Plus Jakarta Sans',system-ui,sans-serif;color:#141032}.w{max-width:560px;margin:0 auto;padding:24px 18px 60px}.top{display:flex;align-items:center;gap:8px;font-weight:800;font-size:18px;margin-bottom:18px}.top i{width:28px;height:28px;border-radius:8px;background:linear-gradient(135deg,#7C3AED,#FF4D6D)}
.card{background:#fff;border-radius:22px;padding:14px;box-shadow:0 20px 50px rgba(20,16,50,.12)}.card img{width:100%;border-radius:14px;display:block}
h1{font-size:24px;letter-spacing:-.02em;margin:18px 0 4px}.by{color:#5B5670;font-size:14px;margin:0 0 16px}
.btn{display:block;text-align:center;padding:16px;border-radius:999px;color:#fff;font-weight:800;font-size:16px;text-decoration:none;background:linear-gradient(135deg,#7C3AED,#FF4D6D);box-shadow:0 10px 24px rgba(124,58,237,.3)}
.how{margin-top:18px;background:#F6F4FF;border-radius:14px;padding:14px 16px;font-size:14px;line-height:1.6;color:#3f3a5a}.how b{display:block;color:#141032}
.f{margin-top:26px;font-size:12px;color:#8a85a3;text-align:center}.f a{color:#7C3AED}</style></head><body><div class="w">
<div class="top"><i></i>ScanPlay</div>
<div class="card"><img src="<?=$h($img)?>" alt="<?=$h($title)?> — printed photo that plays video on ScanPlay" width="1200" height="<?=(int)round(1200/max(0.2,(float)$r['ratio']))?>"></div>
<h1><?=$h($title)?></h1><p class="by">by <?=$h($by)?> · This printed photo plays a video.</p>
<a class="btn" href="<?=$h($play)?>">▶ Play this photo</a>
<div class="how"><b>How to watch</b>Tap the button, allow the camera, and point your phone at the printed photo. The video plays right on it — no app needed.</div>
<p class="f">Make your own printed photos play video at <a href="<?=$h($base)?>">scanplay.in</a></p>
</div></body></html>
