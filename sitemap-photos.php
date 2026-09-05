<?php
/* Image sitemap of photos whose owners chose "Let Google find this photo" */
header('Content-Type: application/xml; charset=UTF-8');
$db = new PDO('sqlite:'.__DIR__.'/data/scanplay.db'); $base = 'https://'.$_SERVER['HTTP_HOST'];
$rows = $db->query("SELECT a.code,i.id,i.title,i.created FROM accounts a JOIN users u ON u.id=a.user_id JOIN items i ON i.code=a.code WHERE a.discover=1 AND a.blocked=0 AND u.deleted=0 ORDER BY i.created DESC LIMIT 5000")->fetchAll(PDO::FETCH_ASSOC);
$h = fn($s)=>htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
echo '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">'."\n";
foreach ($rows as $r) { if (!file_exists(__DIR__."/data/{$r['code']}/{$r['id']}/target.jpg")) continue;
  echo "<url><loc>$base/p.php?c={$r['code']}&amp;i={$r['id']}</loc><lastmod>".date('Y-m-d',(int)$r['created'])."</lastmod><image:image><image:loc>$base/data/{$r['code']}/{$r['id']}/target.jpg</image:loc><image:title>".$h($r['title'])."</image:title></image:image></url>\n"; }
echo "</urlset>";
