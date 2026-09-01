<?php
/* Per-project share page: link preview shows the target photo; humans get redirected to the player. */
$code = preg_replace('/[^a-z0-9]/','', $_GET['c'] ?? '');
$db = new PDO('sqlite:'.__DIR__.'/data/scanplay.db');
$st = $db->prepare("SELECT a.name, (SELECT id FROM items i WHERE i.code=a.code ORDER BY created LIMIT 1) item FROM accounts a WHERE a.code=? AND a.blocked=0"); $st->execute([$code]); $a = $st->fetch(PDO::FETCH_ASSOC);
$base = 'https://'.$_SERVER['HTTP_HOST']; $player = "$base/view.html?c=$code";
$img = $a && $a['item'] ? "$base/data/$code/{$a['item']}/target.jpg" : "$base/og-image.jpg";
$name = htmlspecialchars($a['name'] ?? 'ScanPlay');
$ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
$isBot = preg_match('/WhatsApp|facebookexternalhit|Twitterbot|LinkedInBot|TelegramBot|Slackbot|Discordbot|Googlebot|bingbot/i', $ua);
if (!$isBot) { header("Location: $player", true, 302); exit; }
?><!DOCTYPE html><html><head><meta charset="UTF-8"><title><?=$name?> — find this photo and scan</title>
<meta property="og:type" content="website"><meta property="og:site_name" content="ScanPlay"><meta property="og:url" content="<?=$base?>/s.php?c=<?=$code?>">
<meta property="og:title" content="<?=$name?> — this photo plays a video">
<meta property="og:description" content="Find this photo, scan the QR, point your phone at it and watch the video play. No app needed.">
<meta property="og:image" content="<?=$img?>"><meta property="og:image:width" content="800"><meta property="og:image:height" content="800">
<meta name="twitter:card" content="summary_large_image"><meta name="twitter:image" content="<?=$img?>">
<meta http-equiv="refresh" content="0;url=<?=$player?>"></head><body><a href="<?=$player?>"><?=$name?></a></body></html>
