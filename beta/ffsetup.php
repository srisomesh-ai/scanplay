<?php
/* One-time: install a static ffmpeg into data/bin (protected by .htaccess). Visit once with ?key=<your admin password>, then this file can be deleted. */
require __DIR__.'/config.php'; $cfg = require __DIR__.'/config.php';
if (($_GET['key'] ?? '') === '' || ($_GET['key'] ?? '') !== ($cfg['owner_pass'] ?? '~')) { http_response_code(403); exit('forbidden'); }
header('Content-Type: text/plain'); set_time_limit(600); ini_set('display_errors',1);
$bin = __DIR__.'/data/bin'; @mkdir($bin, 0755, true);
$tgz = "$bin/ffmpeg.tar.xz"; $url = 'https://johnvansickle.com/ffmpeg/releases/ffmpeg-release-amd64-static.tar.xz';
echo "arch: ".php_uname('m')."\n";
if (!file_exists("$bin/ffmpeg")) {
  echo "downloading static ffmpeg (~40 MB)…\n"; $fp=fopen($tgz,'w'); $ch=curl_init($url); curl_setopt_array($ch,[CURLOPT_FILE=>$fp,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_TIMEOUT=>500]); $ok=curl_exec($ch); $err=curl_error($ch); curl_close($ch); fclose($fp);
  if (!$ok || filesize($tgz) < 1000000) exit("download failed: $err\n");
  echo "extracting…\n"; $out = shell_exec("cd ".escapeshellarg($bin)." && tar -xJf ffmpeg.tar.xz --wildcards --strip-components=1 '*/ffmpeg' '*/ffprobe' 2>&1"); echo $out;
  @unlink($tgz); @chmod("$bin/ffmpeg", 0755); @chmod("$bin/ffprobe", 0755);
}
echo "ffmpeg present: ".(file_exists("$bin/ffmpeg")?'yes':'no')."\n";
echo shell_exec(escapeshellarg("$bin/ffmpeg")." -version 2>&1 | head -2");
/* real test: encode 1 second of colour bars to 720p */
$t = "$bin/test.mp4"; @unlink($t);
$o = shell_exec(escapeshellarg("$bin/ffmpeg")." -y -f lavfi -i testsrc=size=1280x720:rate=25 -t 1 -c:v libx264 -preset veryfast -pix_fmt yuv420p ".escapeshellarg($t)." 2>&1 | tail -2");
echo "\ntest encode: ".(file_exists($t)&&filesize($t)>1000 ? "OK (".filesize($t)." bytes)" : "FAILED\n$o")."\n"; @unlink($t);
echo "\nIf you see 'test encode: OK', compression is ready. You can delete ffsetup.php now.\n";
