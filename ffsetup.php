<?php
/* One-time: install a static ffmpeg into data/bin. Visit once with ?key=<admin password>, then delete this file. */
$cfg = require __DIR__.'/config.php';
if (($_GET['key'] ?? '') === '' || ($_GET['key'] ?? '') !== ($cfg['owner_pass'] ?? '~')) { http_response_code(403); exit('forbidden'); }
header('Content-Type: text/plain'); set_time_limit(600);
$bin = __DIR__.'/data/bin'; @mkdir($bin, 0755, true); $ff = "$bin/ffmpeg";
if (!file_exists($ff)) {
  $url = 'https://raw.githubusercontent.com/srisomesh-ai/scanplay-tokens/main/bin/ffmpeg.gz'; $gz = "$bin/ffmpeg.gz";
  echo "downloading ffmpeg.gz (54 MB)…\n"; $fp=fopen($gz,'w'); $ch=curl_init($url); curl_setopt_array($ch,[CURLOPT_FILE=>$fp,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_TIMEOUT=>500]); $ok=curl_exec($ch); $err=curl_error($ch); curl_close($ch); fclose($fp);
  if (!$ok || filesize($gz) < 10000000) exit("download failed: $err\n");
  $in=gzopen($gz,'rb'); $out=fopen($ff,'wb'); while(!gzeof($in)) fwrite($out, gzread($in, 1048576)); gzclose($in); fclose($out); @unlink($gz); @chmod($ff, 0755);
}
echo "ffmpeg: ".(is_executable($ff)?'ready':'NOT executable')."\n";
$t="$bin/test.mp4"; @unlink($t);
shell_exec(escapeshellarg($ff)." -y -nostdin -threads 1 -i ".escapeshellarg(__DIR__.'/assets/sample-test.mp4')." -c:v libx264 -preset veryfast -crf 26 ".escapeshellarg($t)." 2>&1");
echo "test encode: ".(file_exists($t)&&filesize($t)>1000?'OK':'FAILED')."\n"; @unlink($t);
echo "Delete ffsetup.php now.\n";
