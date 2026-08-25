<?php
/**
 * AR Video Player — backend (accounts model)
 * One account = one QR (view.html?c=CODE) = many target/video pairs.
 * Storage: data/accounts.json
 *          data/{code}/targets.mind            (all targets of the account, compiled together)
 *          data/{code}/{itemId}/target.jpg + video.mp4
 */
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

define('ADMIN_PASS', 'arAdmin@2026');          // change this
define('DATA_DIR', __DIR__ . '/data');
define('DB', DATA_DIR . '/accounts.json');
define('MAX_VIDEO_MB', 200);

if (!is_dir(DATA_DIR)) mkdir(DATA_DIR, 0755, true);
if (!file_exists(DB)) file_put_contents(DB, '[]');

function out($ok, $data = []) { echo json_encode(['ok' => $ok] + $data); exit; }
function load() { return json_decode(file_get_contents(DB), true) ?: []; }
function save($l) { file_put_contents(DB, json_encode(array_values($l), JSON_PRETTY_PRINT), LOCK_EX); }
function auth() {
  $h = $_SERVER['HTTP_X_ADMIN_PASS'] ?? ($_POST['pass'] ?? '');
  if ($h !== ADMIN_PASS) out(false, ['error' => 'Wrong password']);
}
function clean($s) { return preg_replace('/[^a-z0-9]/', '', strtolower($s)); }
function baseUrl() {
  $s = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
  return $s . '://' . $_SERVER['HTTP_HOST'] . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
}
function findAcc(&$list, $code) { foreach ($list as $i => $a) if ($a['code'] === $code) return $i; return -1; }
function rrmdir($d) { if (!is_dir($d)) return; foreach (scandir($d) as $f) { if ($f==='.'||$f==='..') continue; $p="$d/$f"; is_dir($p)?rrmdir($p):unlink($p); } rmdir($d); }
function pub($a) {
  $a['qrUrl'] = baseUrl() . '/view.html?c=' . $a['code'];
  foreach ($a['items'] as &$it) $it['thumb'] = "data/{$a['code']}/{$it['id']}/target.jpg";
  return $a;
}

$action = $_REQUEST['action'] ?? '';
if ($_SERVER['REQUEST_METHOD']==='POST' && !$_POST && !$_FILES && ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0)
  out(false, ['error' => 'Upload too large for server (' . round($_SERVER['CONTENT_LENGTH']/1048576) . ' MB). Raise post_max_size / upload_max_filesize in hPanel PHP config, or use a smaller video.']);

switch ($action) {

  case 'login': auth(); out(true);

  case 'accounts':
    auth();
    out(true, ['accounts' => array_map('pub', load())]);

  case 'account_create':
    auth();
    $name = trim(strip_tags($_POST['name'] ?? ''));
    if ($name === '') out(false, ['error' => 'Account name is required']);
    $list = load();
    $code = substr(bin2hex(random_bytes(4)), 0, 8);
    $list[] = ['code' => $code, 'name' => $name, 'created' => date('c'), 'items' => []];
    save($list);
    mkdir(DATA_DIR . "/$code", 0755, true);
    out(true, ['code' => $code]);

  case 'account_delete':
    auth();
    $code = clean($_POST['code'] ?? '');
    $list = load(); $i = findAcc($list, $code);
    if ($i < 0) out(false, ['error' => 'Account not found']);
    array_splice($list, $i, 1); save($list);
    rrmdir(DATA_DIR . "/$code");
    out(true);

  /* Add a target/video pair. Client sends a freshly compiled targets.mind that contains
     ALL items of the account (existing ones in stored order + this new one last). */
  case 'item_add':
    auth();
    $code = clean($_POST['code'] ?? '');
    $list = load(); $i = findAcc($list, $code);
    if ($i < 0) out(false, ['error' => 'Account not found']);
    $videoUrl = trim($_POST['video_url'] ?? '');
    if (empty($_FILES['mind']) || empty($_FILES['target']) || (empty($_FILES['video']) && $videoUrl === ''))
      out(false, ['error' => 'Target image, a video (file or URL) and compiled file are all required']);
    if (!empty($_FILES['video']) && $_FILES['video']['size'] > MAX_VIDEO_MB * 1024 * 1024)
      out(false, ['error' => "Video must be under " . MAX_VIDEO_MB . " MB"]);
    if ($videoUrl !== '' && !preg_match('#^https?://#i', $videoUrl))
      out(false, ['error' => 'Video URL must start with http:// or https://']);

    $id  = substr(bin2hex(random_bytes(5)), 0, 8);
    $dir = DATA_DIR . "/$code/$id";
    mkdir($dir, 0755, true);
    move_uploaded_file($_FILES['target']['tmp_name'], "$dir/target.jpg");

    if (!empty($_FILES['video'])) {
      move_uploaded_file($_FILES['video']['tmp_name'], "$dir/video.mp4");
    } else {
      // Download the video server-side so the player never hits cross-origin limits
      set_time_limit(600);
      $fp = fopen("$dir/video.mp4", 'w');
      $ch = curl_init($videoUrl);
      curl_setopt_array($ch, [CURLOPT_FILE => $fp, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 5,
        CURLOPT_TIMEOUT => 560, CURLOPT_USERAGENT => 'Mozilla/5.0 ARVideo/1.0',
        CURLOPT_MAXFILESIZE => MAX_VIDEO_MB * 1024 * 1024]);
      $okDl = curl_exec($ch); $http = curl_getinfo($ch, CURLINFO_HTTP_CODE); $ctype = curl_getinfo($ch, CURLINFO_CONTENT_TYPE) ?: '';
      $err = curl_error($ch); curl_close($ch); fclose($fp);
      $size = filesize("$dir/video.mp4");
      if (!$okDl || $http >= 400 || $size < 10000 || stripos($ctype, 'text/html') !== false) {
        rrmdir($dir);
        out(false, ['error' => 'Could not download video from that URL' . ($err ? " ($err)" : ($http>=400 ? " (HTTP $http)" : '')) .
          '. It must be a direct link to an MP4 file — YouTube, Google Drive or Instagram pages will not work.']);
      }
    }
    move_uploaded_file($_FILES['mind']['tmp_name'],   DATA_DIR . "/$code/targets.mind");

    $list[$i]['items'][] = [
      'id' => $id,
      'title' => trim(strip_tags($_POST['title'] ?? 'Untitled')),
      'ratio' => (float)($_POST['ratio'] ?? 1),
      'vratio' => (float)($_POST['vratio'] ?? 1),
      'fit' => in_array($_POST['fit'] ?? '', ['fill','fit','stretch']) ? $_POST['fit'] : 'fill',
      'created' => date('c'),
    ];
    save($list);
    out(true, ['id' => $id]);

  /* Remove a pair. Client sends recompiled targets.mind of the remaining items (or none if empty). */
  case 'item_delete':
    auth();
    $code = clean($_POST['code'] ?? ''); $id = clean($_POST['id'] ?? '');
    $list = load(); $i = findAcc($list, $code);
    if ($i < 0) out(false, ['error' => 'Account not found']);
    $list[$i]['items'] = array_values(array_filter($list[$i]['items'], fn($it) => $it['id'] !== $id));
    save($list);
    rrmdir(DATA_DIR . "/$code/$id");
    $mindPath = DATA_DIR . "/$code/targets.mind";
    if (!empty($_FILES['mind'])) move_uploaded_file($_FILES['mind']['tmp_name'], $mindPath);
    elseif (!$list[$i]['items'] && file_exists($mindPath)) unlink($mindPath);
    out(true);

  case 'get':   // public — view.html
    $code = clean($_GET['c'] ?? '');
    foreach (load() as $a) if ($a['code'] === $code) {
      $items = array_map(fn($it) => ['id'=>$it['id'],'title'=>$it['title'],'ratio'=>$it['ratio'],'vratio'=>$it['vratio'],'fit'=>$it['fit']??'fill',
                                     'video'=>"data/$code/{$it['id']}/video.mp4"], $a['items']);
      out(true, ['name' => $a['name'], 'mind' => "data/$code/targets.mind", 'items' => $items]);
    }
    out(false, ['error' => 'This QR is not linked to any account']);

  default: out(false, ['error' => 'Unknown action']);
}
