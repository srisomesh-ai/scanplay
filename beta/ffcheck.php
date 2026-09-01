<?php
/* one-off environment check for video compression — delete after use */
header('Content-Type: text/plain');
echo "PHP ".PHP_VERSION."\n";
echo "shell_exec: ".(function_exists('shell_exec')&&!in_array('shell_exec',array_map('trim',explode(',',ini_get('disable_functions'))))?'available':'DISABLED')."\n";
echo "exec: ".(function_exists('exec')&&!in_array('exec',array_map('trim',explode(',',ini_get('disable_functions'))))?'available':'DISABLED')."\n";
foreach (['ffmpeg','/usr/bin/ffmpeg','/usr/local/bin/ffmpeg','/opt/ffmpeg/ffmpeg'] as $b) { $o=@shell_exec("$b -version 2>&1 | head -1"); echo "$b: ".($o?trim($o):'not found')."\n"; }
echo "max_execution_time: ".ini_get('max_execution_time')."s\n";
echo "memory_limit: ".ini_get('memory_limit')."\n";
echo "upload_max_filesize: ".ini_get('upload_max_filesize')." · post_max_size: ".ini_get('post_max_size')."\n";
echo "disk free: ".round(disk_free_space(__DIR__)/1073741824,1)." GB\n";
