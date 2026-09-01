<?php
/* One-time: install a static ffmpeg into data/bin (protected by .htaccess). Visit once with ?key=<beta admin password>, then delete this file. */
$cfg = require __DIR__.'/config.php';
if (($_GET['key'] ?? '') === '' || ($_GET['key'] ?? '') !== ($cfg['owner_pass'] ?? '~')) { http_response_code(403); exit('forbidden'); }
header('Content-Type: text/plain'); set_time_limit(600); ini_set('display_errors',1);
$bin = __DIR__.'/data/bin'; @mkdir($bin, 0755, true); $ff = "$bin/ffmpeg";
echo "arch: ".php_uname('m')."\n";
if (!file_exists($ff)) {
  $url = 'https://raw.githubusercontent.com/srisomesh-ai/scanplay-tokens/main/bin/ffmpeg.gz'; $gz = "$bin/ffmpeg.gz";
  echo "downloading ffmpeg.gz (54 MB)…\n"; $fp=fopen($gz,'w'); $ch=curl_init($url); curl_setopt_array($ch,[CURLOPT_FILE=>$fp,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_TIMEOUT=>500]); $ok=curl_exec($ch); $err=curl_error($ch); curl_close($ch); fclose($fp);
  if (!$ok || filesize($gz) < 10000000) exit("download failed: $err (".@filesize($gz)." bytes)\n");
  echo "unpacking with PHP (no xz/tar needed)…\n"; $in=gzopen($gz,'rb'); $out=fopen($ff,'wb'); while(!gzeof($in)) fwrite($out, gzread($in, 1048576)); gzclose($in); fclose($out); @unlink($gz);
  @chmod($ff, 0755); echo "unpacked: ".round(filesize($ff)/1048576)." MB\n";
}
echo "ffmpeg present: ".(file_exists($ff)?'yes':'no')." · executable: ".(is_executable($ff)?'yes':'no')."\n";
echo shell_exec(escapeshellarg($ff)." -version 2>&1 | head -1");
$t = "$bin/test.mp4"; @unlink($t);
$o = shell_exec(escapeshellarg($ff)." -y -f lavfi -i testsrc=size=1280x720:rate=25 -t 1 -c:v libx264 -preset veryfast -pix_fmt yuv420p ".escapeshellarg($t)." 2>&1 | tail -3");
echo "\ntest encode: ".(file_exists($t)&&filesize($t)>1000 ? "OK (".filesize($t)." bytes)" : "FAILED\n$o")."\n"; @unlink($t);
echo "\nIf you see 'test encode: OK', compression is ready. Delete ffsetup.php now.\n";
