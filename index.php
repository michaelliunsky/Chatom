<?php
// 单页面在线聊天
// 20250123 20260315 20260321 BY MKLIU
include 'config.php';
date_default_timezone_set("PRC");
error_reporting(E_ALL & ~E_NOTICE);
set_time_limit(30);
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
ob_start();

$room = preg_replace('/[^a-zA-Z0-9_\-]/', '', $_REQUEST['room'] ?? 'default');
if ($room === '') $room = 'default';
$type = strtolower($_REQUEST['type'] ?? 'enter');

function chatJson($payload) {
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/json');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function readJsonFile($path, $default = []) {
    if (!file_exists($path)) return $default;
    $raw = file_get_contents($path);
    if ($raw === false || $raw === '') return $default;
    $data = json_decode($raw, true);
    return is_array($data) ? $data : $default;
}

function writeJsonFile($path, $data) {
    file_put_contents($path, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
}

function getRoomFile($room) {
    return './chat_data/' . $room . '.txt';
}

function getUploadDir($room) {
    return './chat_data/uploads/' . $room;
}

function hasRoomAccess($room) {
    return $room === 'default' || !empty($_SESSION['chat_rooms'][$room]);
}

function grantRoomAccess($room) {
    if ($room === 'default') return;
    if (!isset($_SESSION['chat_rooms']) || !is_array($_SESSION['chat_rooms'])) $_SESSION['chat_rooms'] = [];
    $_SESSION['chat_rooms'][$room] = true;
}

function ensureRoomAccess($room, $room_file, $asJson = true) {
    if (!file_exists($room_file)) {
        if ($asJson) chatJson(['result' => 'error', 'msg' => 'room not found']);
        http_response_code(404);
        exit('Not found');
    }

    $roomData = readJsonFile($room_file, []);
    if (empty($roomData['password']) || hasRoomAccess($room)) return $roomData;

    if ($asJson) {
        http_response_code(403);
        chatJson(['result' => 'error', 'msg' => 'forbidden']);
    }

    http_response_code(403);
    exit('Forbidden');
}

function ensureUploadDir($room) {
    $dir = getUploadDir($room);
    if (!is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir)) {
        throw new RuntimeException('上传目录创建失败');
    }
    return $dir;
}

function normalizeFileName($name) {
    $name = trim($name);
    if ($name === '') return 'file';
    $name = preg_replace('/[^\w.\-\x{4e00}-\x{9fa5}]+/u', '_', $name);
    return trim($name, '._') ?: 'file';
}

function mimeFromExtension($name) {
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $map = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'bmp' => 'image/bmp',
        'svg' => 'image/svg+xml',
        'pdf' => 'application/pdf',
        'txt' => 'text/plain',
        'md' => 'text/markdown',
        'json' => 'application/json',
        'zip' => 'application/zip',
        'rar' => 'application/vnd.rar',
        '7z' => 'application/x-7z-compressed',
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls' => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'ppt' => 'application/vnd.ms-powerpoint',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    ];
    return $map[$ext] ?? 'application/octet-stream';
}

function detectMimeType($path, $originalName = '') {
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo) {
            $mime = finfo_file($finfo, $path);
            finfo_close($finfo);
            if (is_string($mime) && $mime !== '') return $mime;
        }
    }

    if (function_exists('mime_content_type')) {
        $mime = @mime_content_type($path);
        if (is_string($mime) && $mime !== '') return $mime;
    }

    if (function_exists('getimagesize')) {
        $imageInfo = @getimagesize($path);
        if (!empty($imageInfo['mime'])) return $imageInfo['mime'];
    }

    return mimeFromExtension($originalName !== '' ? $originalName : basename($path));
}

function isImageMime($mime) {
    return in_array($mime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/bmp'], true);
}

function buildAttachmentUrl($room, $storedName, $download = false) {
    $params = ['type' => 'asset', 'room' => $room, 'file' => $storedName];
    if ($download) $params['download'] = '1';
    return 'index.php?' . http_build_query($params);
}

function parseAttachments($room) {
    if (empty($_FILES['attachments']) || !is_array($_FILES['attachments']['name'])) return [];

    $maxFileSize = 10 * 1024 * 1024;
    $files = [];
    $count = count($_FILES['attachments']['name']);
    if ($count > 6) throw new RuntimeException('一次最多发送 6 个附件');

    $dir = ensureUploadDir($room);

    for ($i = 0; $i < $count; $i++) {
        $error = $_FILES['attachments']['error'][$i] ?? UPLOAD_ERR_NO_FILE;
        if ($error === UPLOAD_ERR_NO_FILE) continue;
        if ($error !== UPLOAD_ERR_OK) throw new RuntimeException('附件上传失败');

        $tmpName = $_FILES['attachments']['tmp_name'][$i] ?? '';
        if ($tmpName === '' || !is_uploaded_file($tmpName)) throw new RuntimeException('附件上传失败');

        $size = (int)($_FILES['attachments']['size'][$i] ?? 0);
        if ($size <= 0) throw new RuntimeException('附件不能为空');
        if ($size > $maxFileSize) throw new RuntimeException('单个附件不能超过 10MB');

        $originalName = normalizeFileName($_FILES['attachments']['name'][$i] ?? 'file');
        $mime = detectMimeType($tmpName, $originalName);

        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $storedName = bin2hex(random_bytes(16));
        if ($ext !== '') $storedName .= '.' . $ext;
        $storedPath = $dir . '/' . $storedName;

        if (!move_uploaded_file($tmpName, $storedPath)) throw new RuntimeException('附件保存失败');

        $files[] = [
            'name' => $originalName,
            'stored_name' => $storedName,
            'mime' => $mime,
            'size' => $size,
            'kind' => isImageMime($mime) ? 'image' : 'file',
            'url' => buildAttachmentUrl($room, $storedName, false),
            'download_url' => buildAttachmentUrl($room, $storedName, true),
        ];
    }

    return $files;
}

function getChatrooms() {
    $files = glob('./chat_data/*.txt');
    $rooms = [];
    foreach ($files as $f) $rooms[] = basename($f, '.txt');
    return $rooms;
}

function generateRandomPassword() {
    $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
    $pw = '';
    for ($i = 0; $i < 8; $i++) $pw .= $chars[random_int(0, strlen($chars) - 1)];
    return $pw;
}

function newRoom($room, $custompassword = null) {
    $room_file = getRoomFile($room);
    $key_list  = array_merge(range(48, 57), range(65, 90), range(97, 122), [43, 47, 61]);
    $key1_list = $key_list;
    shuffle($key1_list);
    if ($room !== 'default' && !$custompassword) $custompassword = generateRandomPassword();
    $data = [
        'name'     => $room,
        'encode'   => array_combine($key_list, $key1_list),
        'list'     => [],
        'time'     => date('Y-m-d H:i:s'),
        'password' => ($room === 'default') ? null : password_hash($custompassword, PASSWORD_DEFAULT),
    ];
    writeJsonFile($room_file, $data);
    return $custompassword;
}

function getMsg($room, $last_id) {
    $room_file = getRoomFile($room);
    $data      = readJsonFile($room_file, ['list' => []]);
    $list      = $data['list'] ?? [];
    $del_time  = date('Y-m-d H:i:s', time() - 604800);
    $cur = array_values(array_filter($list, fn($r) => $r['time'] > $del_time));
    if (count($cur) !== count($list)) {
        $data['list'] = $cur;
        writeJsonFile($room_file, $data);
    }
    return array_values(array_filter($cur, fn($r) => $r['id'] > $last_id));
}

if ($type === 'get') {
    $last_id  = (int)($_REQUEST['last_id'] ?? -1);
    $room_file_get = getRoomFile($room);
    ensureRoomAccess($room, $room_file_get);
    $msg_list = [];
    if (strpos($_SERVER['SERVER_SOFTWARE'] ?? '', 'nginx') !== false) {
        $msg_list = getMsg($room, $last_id);
    } else {
        for ($i = 0; $i < 20; $i++) {
            $msg_list = getMsg($room, $last_id);
            if (!empty($msg_list)) break;
            usleep(500000);
        }
    }
    chatJson(['result' => 'ok', 'list' => $msg_list]);
}

if ($type === 'asset') {
    $room_file = getRoomFile($room);
    $file = basename($_REQUEST['file'] ?? '');
    $path = getUploadDir($room) . '/' . $file;
    $roomData = ensureRoomAccess($room, $room_file, false);

    if ($file === '' || !is_file($path)) {
        http_response_code(404);
        exit('Not found');
    }

    $download = isset($_REQUEST['download']) && $_REQUEST['download'] === '1';
    $mime = detectMimeType($path, $file);
    $name = $file;

    foreach ($roomData['list'] ?? [] as $item) {
        foreach ($item['attachments'] ?? [] as $attachment) {
            if (($attachment['stored_name'] ?? '') === $file) {
                $name = $attachment['name'] ?? $name;
                $mime = $attachment['mime'] ?? $mime;
                break 2;
            }
        }
    }

    header('Content-Type: ' . $mime);
    header('X-Content-Type-Options: nosniff');
    header('Content-Length: ' . filesize($path));
    $disposition = $download || !isImageMime($mime) ? 'attachment' : 'inline';
    $safeName = str_replace(['"', "\r", "\n"], '', $name);
    header('Content-Disposition: ' . $disposition . '; filename="' . $safeName . '"; filename*=UTF-8\'\'' . rawurlencode($safeName));
    readfile($path);
    exit;
}

if ($type === 'send') {
    $room_file = getRoomFile($room);
    ensureRoomAccess($room, $room_file);
    try {
        $attachments = parseAttachments($room);
    } catch (Throwable $e) {
        chatJson(['result' => 'error', 'msg' => $e->getMessage()]);
    }
    $content = $_REQUEST['content'] ?? '';
    if (trim($content) === '' && empty($attachments)) {
        chatJson(['result' => 'error', 'msg' => 'empty message']);
    }
    $item = [
        'id'          => round(microtime(true) * 1000),
        'user'        => trim(substr($_REQUEST['user'] ?? 'anon', 0, 50)) ?: 'anon',
        'content'     => $content,
        'time'        => date('Y-m-d H:i:s'),
        'attachments' => $attachments,
    ];
    $data           = readJsonFile($room_file, ['list' => []]);
    $data['list'][] = $item;
    writeJsonFile($room_file, $data);
    chatJson(['result' => 'ok']);
}

if ($type === 'new') {
    $newroom  = strtoupper(bin2hex(random_bytes(5)));
    $pw_input = $_POST['password'] ?? null;
    $gen_pw   = newRoom($newroom, $pw_input ?: null);
    chatJson(['result' => 'ok', 'room' => $newroom, 'password' => $gen_pw]);
}

// Page render
$room_file   = getRoomFile($room);
$requireAuth = false;
$authError   = '';

if ($room === 'default') {
    if (!file_exists($room_file)) newRoom($room);
} else {
    if (!file_exists($room_file)) { header('Location: index.php'); exit; }
    $room_data_tmp = readJsonFile($room_file, []);
    if (hasRoomAccess($room)) {
        $requireAuth = false;
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['auth_submit'])) {
        $input_pw = $_POST['password'] ?? '';
        if (!password_verify($input_pw, $room_data_tmp['password'] ?? '')) {
            $requireAuth = true;
            $authError   = '密码错误';
        } else {
            grantRoomAccess($room);
            $requireAuth = false;
        }
    } else {
        $requireAuth = true;
    }
}

$room_data = readJsonFile($room_file, []);
unset($room_data['list']);

$user      = 'User' . str_pad((time() % 99 + 1), 2, '0', STR_PAD_LEFT);
$chatrooms = getChatrooms();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($title) ?></title>
<link rel="icon" href="<?= htmlspecialchars($logoUrl) ?>" type="image/x-icon">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=Noto+Sans+SC:wght@400;500;600&display=swap" rel="stylesheet">
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

:root {
  --c0: #ffffff;
  --c1: #f7f7f5;
  --c2: #efefed;
  --c3: #e2e2e0;
  --c4: #c7c7c4;
  --c5: #888885;
  --c6: #444442;
  --c7: #111110;
  --red:  #cc2200;
  --sans: 'Inter', 'Noto Sans SC', sans-serif;
  --mono: 'Menlo', 'Monaco', 'Consolas', monospace;
  --sw: 260px;
  --r-btn: 6px;
  --h-btn: 32px;
}

html, body { height: 100dvh; background: var(--c0); color: var(--c7); font-family: var(--sans); font-size: 15px; overflow: hidden; -webkit-font-smoothing: antialiased; }

.layout { display: grid; grid-template-columns: var(--sw) 1fr; height: 100dvh; }

/* Sidebar */
.side {
  border-right: 1px solid var(--c3);
  display: flex; flex-direction: column; overflow: hidden;
  background: var(--c1);
}

.side-top {
  padding: 14px 14px 12px;
  border-bottom: 1px solid var(--c3);
  flex-shrink: 0;
}

.site-name {
  font-size: 13px;
  font-weight: 600;
  color: var(--c7);
  letter-spacing: -.2px;
}

.nick-row { margin-top: 10px; }

.nick-label {
  font-size: 11px;
  color: var(--c5);
  margin-bottom: 4px;
}

.nick-input {
  width: 100%;
  border: 1px solid var(--c3);
  border-radius: 5px;
  padding: 6px 8px;
  font-size: 13px;
  font-family: var(--sans);
  color: var(--c7);
  background: var(--c0);
  outline: none;
  transition: border-color .15s;
}
.nick-input:focus { border-color: var(--c6); }

.side-rooms {
  flex: 1; overflow-y: auto; min-height: 0;
  padding: 8px 8px 10px;
}
.side-rooms::-webkit-scrollbar { width: 3px; }
.side-rooms::-webkit-scrollbar-thumb { background: var(--c3); }

.side-footer {
  padding: 8px 14px 10px;
  font-size: 11px;
  color: var(--c4);
  border-top: 1px solid var(--c3);
  flex-shrink: 0;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.side-close {
  display: none;
  background: none;
  border: none;
  font-size: 18px;
  line-height: 1;
  color: var(--c5);
  cursor: pointer;
  padding: 0 2px;
  transition: color .15s;
}
.side-close:hover { color: var(--c7); }

.rooms-label {
  font-size: 11px;
  color: var(--c5);
  padding: 4px 6px 6px;
}

.room-link {
  display: flex; align-items: center; justify-content: space-between;
  padding: 6px 8px;
  border-radius: 5px;
  font-size: 13px;
  color: var(--c6);
  text-decoration: none;
  transition: background .1s;
  margin-bottom: 1px;
  font-family: var(--mono);
  letter-spacing: -.3px;
}
.room-link:hover { background: var(--c2); color: var(--c7); }
.room-link.on { background: var(--c7); color: var(--c0); }

.room-lock { font-size: 11px; opacity: .5; }

.add-room {
  width: 100%;
  margin-top: 8px;
  padding: 6px 8px;
  border: 1px solid var(--c3);
  border-radius: var(--r-btn);
  background: transparent;
  font-size: 12px;
  color: var(--c5);
  cursor: pointer;
  text-align: left;
  transition: color .15s, border-color .15s;
  font-family: var(--sans);
}
.add-room:hover { color: var(--c7); border-color: var(--c6); }

/* Main */
.main { display: flex; flex-direction: column; overflow: hidden; background: var(--c0); }

.topbar {
  height: 48px; padding: 0 16px;
  display: flex; align-items: center; justify-content: space-between;
  border-bottom: 1px solid var(--c3);
  flex-shrink: 0;
}

.topbar-room { font-size: 13px; font-weight: 600; font-family: var(--mono); }
.topbar-meta { font-size: 11px; color: var(--c5); margin-left: 8px; font-weight: 400; font-family: var(--sans); }

.clear-btn {
  height: var(--h-btn);
  padding: 0 12px;
  border: 1px solid var(--c3);
  border-radius: var(--r-btn);
  background: transparent;
  font-size: 12px;
  color: var(--c5);
  cursor: pointer;
  transition: color .15s, border-color .15s;
}
.clear-btn:hover { color: var(--c7); border-color: var(--c6); }

.icon-btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: var(--h-btn);
  min-width: var(--h-btn);
  padding: 0;
  line-height: 1;
  font-size: 16px;
}

/* Messages */
.msgs {
  flex: 1; overflow-y: auto;
  display: flex; flex-direction: column-reverse;
  padding: 12px 16px;
}
.msgs::-webkit-scrollbar { width: 4px; }
.msgs::-webkit-scrollbar-thumb { background: var(--c3); border-radius: 2px; }

.msg {
  display: grid;
  grid-template-columns: auto 1fr;
  gap: 0 10px;
  padding: 5px 0;
  animation: up .15s ease-out;
}
@keyframes up { from { opacity:0; transform:translateY(4px); } }

.msg-who {
  font-size: 12px; font-weight: 600;
  color: var(--c7);
  white-space: nowrap;
  padding-top: 1px;
  min-width: 80px;
  max-width: 120px;
  overflow: hidden;
  text-overflow: ellipsis;
}

.msg-right { min-width: 0; }

.msg-time {
  font-size: 11px;
  color: var(--c4);
  font-family: var(--mono);
  margin-bottom: 2px;
}

.msg-text {
  font-size: 13.5px;
  line-height: 1.6;
  color: var(--c6);
  word-break: break-word;
}

.msg-media {
  display: grid;
  gap: 8px;
  margin-top: 8px;
  width: min(560px, 100%);
}

.msg-image {
  display: block;
  max-width: min(320px, 100%);
  border: 1px solid var(--c3);
  border-radius: 10px;
  background: var(--c1);
}

.msg-file {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding: 10px 12px;
  border: 1px solid var(--c3);
  border-radius: 10px;
  background: var(--c1);
}

.msg-file-meta {
  min-width: 0;
}

.msg-file-name {
  font-size: 13px;
  color: var(--c7);
  font-weight: 600;
  word-break: break-word;
}

.msg-file-size {
  margin-top: 2px;
  font-size: 11px;
  color: var(--c5);
  font-family: var(--mono);
}

.file-download {
  flex-shrink: 0;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-width: 72px;
  height: var(--h-btn);
  padding: 0 12px;
  border: 1px solid var(--c3);
  border-radius: var(--r-btn);
  color: var(--c7);
  text-decoration: none;
  font-size: 12px;
  transition: border-color .15s, background .15s;
  background: var(--c0);
}
.file-download:hover { border-color: var(--c6); background: var(--c2); }

.no-msgs {
  display: flex; align-items: center; justify-content: center;
  flex: 1;
  font-size: 13px;
  color: var(--c4);
}

/* Input */
.input-area {
  padding: 10px 14px 14px;
  border-top: 1px solid var(--c3);
  flex-shrink: 0;
}

.input-row {
  display: block;
}

.composer {
  flex: 1;
  min-width: 0;
  border: 1px solid var(--c3);
  border-radius: 8px;
  background: var(--c0);
  padding: 10px 12px;
  transition: border-color .15s, background .15s, box-shadow .15s;
}

.composer:focus-within {
  border-color: var(--c6);
  box-shadow: 0 0 0 3px rgba(17,17,16,.03);
}

.composer.drag-on {
  border-color: var(--c6);
  background: var(--c1);
  box-shadow: inset 0 0 0 1px rgba(17,17,16,.04);
}

#txtContent {
  width: 100%;
  border: none;
  border-radius: 0;
  padding: 0;
  font-size: 14px;
  font-family: var(--sans);
  color: var(--c7);
  background: transparent;
  outline: none;
  resize: none;
  min-height: 28px;
  max-height: 108px;
  line-height: 1.55;
}
#txtContent::placeholder { color: var(--c4); }

.attach-list {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  margin-top: 8px;
}

.attach-list:empty {
  display: none;
}

.pick-btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  height: var(--h-btn);
  padding: 0 12px;
  border: 1px solid var(--c3);
  border-radius: var(--r-btn);
  background: var(--c1);
  color: var(--c6);
  font-size: 12px;
  cursor: pointer;
  transition: color .15s, border-color .15s, background .15s;
}
.pick-btn:hover { color: var(--c7); border-color: var(--c6); background: var(--c2); }

.pick-input { display: none; }

.composer-footer {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  margin-top: 6px;
  padding-top: 6px;
  border-top: 1px solid var(--c2);
}

.composer-actions {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-left: auto;
}

.attach-chip {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  max-width: 100%;
  padding: 5px 8px;
  border-radius: 5px;
  background: var(--c1);
  border: 1px solid var(--c3);
  font-size: 12px;
  color: var(--c6);
}

.attach-chip-name {
  max-width: 240px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.attach-chip-remove {
  border: none;
  background: transparent;
  color: var(--c5);
  font-size: 14px;
  line-height: 1;
  cursor: pointer;
  padding: 0;
}
.attach-chip-remove:hover { color: var(--red); }

.drop-hint {
  display: block;
  flex: 1;
  min-width: 0;
  color: var(--c5);
  font-size: 11px;
  line-height: 1.45;
  min-height: 16px;
  transition: color .18s, opacity .18s;
}

.composer.drag-on .drop-hint {
  color: var(--c6);
}

.send {
  height: var(--h-btn); padding: 0 12px;
  background: var(--c7);
  border: none; border-radius: var(--r-btn);
  color: var(--c0);
  font-size: 12px; font-weight: 600;
  cursor: pointer;
  flex-shrink: 0;
  transition: background .15s, transform .15s;
}
.send:hover { background: var(--c6); }
.send:active { transform: translateY(1px); }
.send:disabled {
  cursor: default;
  background: var(--c5);
  transform: none;
}

.input-hint {
  margin-top: 9px;
  font-size: 11px;
  line-height: 1.5;
  color: var(--c4);
}

/* Modals */
.veil {
  position: fixed; inset: 0;
  background: rgba(0,0,0,.3);
  display: flex; align-items: center; justify-content: center;
  z-index: 80;
  opacity: 0; pointer-events: none;
  transition: opacity .18s;
}
.veil.on { opacity: 1; pointer-events: all; }

.panel {
  background: var(--c0);
  border: 1px solid var(--c3);
  border-radius: 8px;
  padding: 24px;
  width: 100%; max-width: 360px;
  box-shadow: 0 8px 32px rgba(0,0,0,.12);
  transform: translateY(6px);
  transition: transform .18s;
}
.veil.on .panel { transform: translateY(0); }

.panel-title { font-size: 15px; font-weight: 600; margin-bottom: 4px; }
.panel-sub   { font-size: 12px; color: var(--c5); margin-bottom: 18px; line-height: 1.6; }

.field-label { font-size: 11px; color: var(--c5); margin-bottom: 4px; }

.field {
  width: 100%;
  border: 1px solid var(--c3);
  border-radius: 5px;
  padding: 8px 10px;
  font-size: 13px;
  font-family: var(--mono);
  color: var(--c7);
  background: var(--c0);
  outline: none;
  margin-bottom: 14px;
  transition: border-color .15s;
}
.field:focus { border-color: var(--c6); }

.panel-row { display: flex; gap: 8px; }

.btn-dark {
  flex: 1; min-height: 38px; padding: 8px 14px;
  background: var(--c7); border: none; border-radius: var(--r-btn);
  color: var(--c0); font-size: 13px; font-weight: 500;
  cursor: pointer; transition: background .15s;
}
.btn-dark:hover { background: var(--c6); }

.btn-line {
  min-height: 38px;
  padding: 8px 14px;
  background: transparent;
  border: 1px solid var(--c3); border-radius: var(--r-btn);
  color: var(--c5); font-size: 13px;
  cursor: pointer; transition: color .15s, border-color .15s;
}
.btn-line:hover { color: var(--c7); border-color: var(--c6); }

.result-block {
  background: var(--c1); border: 1px solid var(--c3);
  border-radius: 5px; padding: 10px 12px; margin-bottom: 16px;
}
.result-item {
  display: flex; justify-content: space-between; align-items: center;
  padding: 3px 0; gap: 12px;
}
.rk { font-size: 11px; color: var(--c5); flex-shrink: 0; }
.rv { font-size: 13px; font-weight: 600; font-family: var(--mono); color: var(--c7); word-break: break-all; text-align: right; }

/* Auth */
.auth-wrap {
  position: fixed; inset: 0;
  background: var(--c1);
  display: flex; align-items: center; justify-content: center;
  z-index: 200;
}

.auth-box {
  background: var(--c0);
  border: 1px solid var(--c3);
  border-radius: 8px;
  padding: 28px 26px 24px;
  width: 100%; max-width: 340px;
  box-shadow: 0 4px 20px rgba(0,0,0,.07);
}

.auth-title { font-size: 15px; font-weight: 600; margin-bottom: 4px; }
.auth-sub   { font-size: 12px; color: var(--c5); margin-bottom: 20px; line-height: 1.6; }
.auth-err   { font-size: 12px; color: var(--red); margin-bottom: 10px; }

/* Toast */
.toasts {
  position: fixed; bottom: 18px; right: 18px;
  z-index: 999; display: flex; flex-direction: column; gap: 6px; align-items: flex-end;
}
.toast {
  background: var(--c7); color: var(--c0);
  border-radius: 5px; padding: 8px 14px;
  font-size: 12px;
  box-shadow: 0 4px 14px rgba(0,0,0,.15);
  animation: tin .15s ease-out;
}
.toast.err { background: var(--red); }
@keyframes tin  { from { opacity:0; transform:translateY(6px); } }
@keyframes tout { to   { opacity:0; transform:translateY(6px); } }

.side-overlay {
  display: none;
  position: fixed; inset: 0;
  z-index: 59;
  background: rgba(0,0,0,.25);
}

/* Mobile */
@media (max-width: 600px) {
  .layout { grid-template-columns: 1fr; }
  .side { display:none; position:fixed; inset:0 auto 0 0; width:var(--sw); z-index:60; box-shadow:4px 0 16px rgba(0,0,0,.1); }
  .side.open { display:flex; }
  .side.open ~ .side-overlay { display:block; }
  .side-close { display:block; }
  #menuBtn { display:flex !important; }
}

/* Desktop — bump everything up */
@media (min-width: 601px) {
  :root { --sw: 280px; }
  .site-name   { font-size: 15px; }
  .nick-label  { font-size: 13px; }
  .nick-input  { font-size: 15px; padding: 8px 10px; }
  .rooms-label { font-size: 12px; }
  .room-link   { font-size: 14px; padding: 7px 9px; }
  .add-room    { font-size: 13px; padding: 7px 9px; }
  .topbar      { height: 52px; padding: 0 20px; }
  .topbar-room { font-size: 15px; }
  .topbar-meta { font-size: 13px; }
  .clear-btn   { font-size: 13px; padding: 0 14px; }
  .msgs        { padding: 16px 20px; }
  .msg         { padding: 6px 0; gap: 0 12px; }
  .msg-who     { font-size: 14px; min-width: 90px; max-width: 140px; }
  .msg-time    { font-size: 12px; }
  .msg-text    { font-size: 15px; }
  .msg-file-name { font-size: 14px; }
  .no-msgs     { font-size: 14px; }
  .input-area  { padding: 12px 18px 16px; }
  #txtContent  { font-size: 15px; padding: 0; }
  .send        { font-size: 13px; height: 34px; padding: 0 14px; }
  .pick-btn    { font-size: 13px; height: 34px; padding: 0 14px; }
  .file-download { font-size: 13px; height: 34px; }
  .input-hint  { font-size: 12px; }
  .drop-hint   { font-size: 12px; }
  .panel-title { font-size: 16px; }
  .panel-sub   { font-size: 13px; }
  .field-label { font-size: 12px; }
  .field       { font-size: 14px; }
  .btn-dark, .btn-line { font-size: 14px; padding: 9px 16px; }
  .auth-title  { font-size: 16px; }
  .auth-sub    { font-size: 13px; }
  .auth-err    { font-size: 13px; }
  .toast       { font-size: 13px; }
}

@media (max-width: 600px) {
  #menuBtn {
    align-items: center;
    justify-content: center;
  }
  .composer-footer {
    flex-direction: column;
    align-items: stretch;
  }
  .composer-actions {
    width: 100%;
    justify-content: space-between;
  }
  .attach-list {
    width: 100%;
  }
  .drop-hint {
    width: 100%;
  }
}
</style>
</head>
<body>

<?php if ($requireAuth): ?>
<div class="auth-wrap">
  <div class="auth-box">
    <div class="auth-title"><?= htmlspecialchars($room) ?></div>
    <div class="auth-sub">输入密码后进入</div>
    <?php if ($authError): ?>
      <div class="auth-err"><?= htmlspecialchars($authError) ?></div>
    <?php endif; ?>
    <form method="post" action="index.php?room=<?= urlencode($room) ?>">
      <input type="hidden" name="auth_submit" value="1">
      <div class="field-label">密码</div>
      <input type="password" name="password" class="field" autofocus required style="margin-bottom:16px">
      <div class="panel-row">
        <a href="index.php" class="btn-line" style="text-decoration:none;text-align:center">返回</a>
        <button type="submit" class="btn-dark">进入</button>
      </div>
    </form>
  </div>
</div>

<?php else: ?>
<div class="layout">

  <aside class="side" id="side">
    <div class="side-top">
      <div style="display:flex;align-items:center;justify-content:space-between">
        <div class="site-name"><?= htmlspecialchars($title) ?></div>
        <button class="side-close" onclick="closeSide()">×</button>
      </div>
      <div class="nick-row">
        <div class="nick-label">昵称</div>
        <input id="txtUser" class="nick-input" type="text" maxlength="50"
               value="<?= htmlspecialchars($user) ?>" placeholder="输入昵称">
      </div>
    </div>

    <div class="side-rooms">
      <div class="rooms-label">房间</div>
      <?php foreach ($chatrooms as $cr): ?>
        <a class="room-link <?= ($cr === $room_data['name']) ? 'on' : '' ?>"
           href="index.php?room=<?= urlencode($cr) ?>">
          <span><?= htmlspecialchars($cr) ?></span>
          <?php if ($cr !== 'default'): ?>
            <span class="room-lock">🔒</span>
          <?php endif; ?>
        </a>
      <?php endforeach; ?>
      <button class="add-room" onclick="openCreate()">+ 新建房间</button>
    </div>
    <div class="side-footer">
      © 2025-2026 <a href="https://www.mkliu.top/" style="color:var(--c5);text-decoration:none">michaelliunsky</a> & Yuer6327
    </div>
  </aside>
  <div class="side-overlay" id="sideOverlay" onclick="closeSide()"></div>

  <section class="main">
    <div class="topbar">
      <div style="display:flex;align-items:center;gap:8px">
        <button class="clear-btn icon-btn" id="menuBtn" style="display:none"
                onclick="document.getElementById('side').classList.toggle('open')">☰</button>
        <span class="topbar-room"><?= htmlspecialchars($room_data['name']) ?></span>
        <span class="topbar-meta"><?= date('Y-m-d') ?></span>
      </div>
      <button class="clear-btn" onclick="clearMsgs()">清屏</button>
    </div>

    <div class="msgs" id="msgs">
      <div class="no-msgs" id="es">暂无消息</div>
    </div>

    <div class="input-area">
      <div class="input-row">
        <div class="composer">
          <textarea id="txtContent" rows="1" placeholder="输入消息 / 添加附件 / 拖拽 / 粘贴"></textarea>
          <div class="attach-list" id="attachList"></div>
          <div class="composer-footer">
            <div class="drop-hint" id="dropHint">添加附件 / 拖拽 / 粘贴 / 10MB 内</div>
            <div class="composer-actions">
              <label class="pick-btn" for="fileInput">添加附件</label>
              <input id="fileInput" class="pick-input" type="file" multiple>
              <button class="send" id="sendBtn" type="button" onclick="sendMsg()">发送</button>
            </div>
          </div>
        </div>
      </div>
      <div class="input-hint">Enter 发送 / Shift+Enter 换行</div>
    </div>
  </section>
</div>

<!-- Create modal -->
<div class="veil" id="vCreate">
  <div class="panel">
    <div class="panel-title">新建房间</div>
    <div class="panel-sub">房间号自动生成 / 密码留空自动生成</div>
    <div class="field-label">密码 可选</div>
    <input type="password" id="newPw" class="field" placeholder="留空自动生成">
    <div class="panel-row">
      <button class="btn-line" onclick="closeCreate()">取消</button>
      <button class="btn-dark" id="createBtn" onclick="doCreate()">创建</button>
    </div>
  </div>
</div>

<!-- Result modal -->
<div class="veil" id="vResult">
  <div class="panel">
    <div class="panel-title">房间已创建</div>
    <div class="panel-sub">请保存房间号和密码</div>
    <div class="result-block">
      <div class="result-item"><span class="rk">房间号</span><span class="rv" id="rRoom">—</span></div>
      <div class="result-item"><span class="rk">密码</span><span class="rv" id="rPw">—</span></div>
    </div>
    <div class="panel-row">
      <button class="btn-dark" onclick="goRoom()">进入房间</button>
    </div>
  </div>
</div>

<?php endif; ?>

<div class="toasts" id="toasts"></div>

<script id="wk" type="app/worker">
var room='<?= $room_data['name'] ?>',busy=false,lastId=-1,base='';
addEventListener('message',function(e){base=e.data;});
setInterval(function(){
  if(busy)return;busy=true;
  fetch(new URL('index.php?type=get&room='+room+'&last_id='+lastId,base))
    .then(function(r){return r.json();})
    .then(function(d){
      busy=false;
      if(d.list.length)lastId=d.list[d.list.length-1].id;
      self.postMessage(d);
    }).catch(function(){busy=false;});
},1000);
</script>

<script>
function toast(msg,type){
  var t=document.createElement('div');
  t.className='toast'+(type?' '+type:'');
  t.textContent=msg;
  document.getElementById('toasts').appendChild(t);
  setTimeout(function(){t.style.animation='tout .15s forwards';setTimeout(function(){t.remove();},150);},2500);
}

<?php if (!$requireAuth): ?>
var R=<?= json_encode($room_data) ?>;
R.dec={};
for(var k in R.encode) R.dec[R.encode[k]]=k;

function enc(s){s=encodeURIComponent(s);s=btoa(s);var o='';for(var i=0;i<s.length;i++)o+=String.fromCharCode(R.encode[s.charCodeAt(i)]);return o;}
function dec(s){var o='';for(var i=0;i<s.length;i++)o+=String.fromCharCode(R.dec[s.charCodeAt(i)]);return decodeURIComponent(atob(o));}
function esc(s){return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');}
function escAttr(s){return esc(s).replace(/"/g,'&quot;');}
function fmtText(s){return esc(s).replace(/\n/g,'<br>');}
function fmtSize(size){
  if(size<1024)return size+' B';
  if(size<1024*1024)return (size/1024).toFixed(size<10240?1:0)+' KB';
  return (size/1024/1024).toFixed(size<10*1024*1024?1:0)+' MB';
}
function fileIcon(kind){return kind==='image'?'图片':'文件';}
function hintText(text){
  document.getElementById('dropHint').textContent=text;
}

var wk=new Worker(URL.createObjectURL(new Blob([document.getElementById('wk').textContent])));
var seen={};
var pickedFiles=[];
var dragDepth=0;

wk.onmessage=function(e){
  if(!e.data.list.length)return;
  var f=document.createDocumentFragment();
  e.data.list.forEach(function(r){
    if(seen[r.id])return;seen[r.id]=1;
    f.prepend(buildMsg(r));
  });
  var es=document.getElementById('es');if(es)es.remove();
  document.getElementById('msgs').prepend(f);
};
wk.postMessage(document.baseURI);

function buildMsg(r){
  var text='';try{text=dec(r.content);}catch(e){text=r.content;}
  var attachments=Array.isArray(r.attachments)?r.attachments:[];
  var media='';
  if(attachments.length){
    media='<div class="msg-media">'+attachments.map(function(file){
      if(file.kind==='image'){
        return '<a href="'+escAttr(file.url)+'" target="_blank" rel="noopener">'
          +'<img class="msg-image" src="'+escAttr(file.url)+'" alt="'+escAttr(file.name||'image')+'" loading="lazy"></a>';
      }
      return '<div class="msg-file">'
        +'<div class="msg-file-meta">'
        +'<div class="msg-file-name">'+esc(file.name||'file')+'</div>'
        +'<div class="msg-file-size">'+fileIcon(file.kind)+' · '+fmtSize(Number(file.size)||0)+'</div>'
        +'</div>'
        +'<a class="file-download" href="'+escAttr(file.download_url||file.url)+'" download>下载</a>'
        +'</div>';
    }).join('')+'</div>';
  }
  var textHtml=text?'<div class="msg-text">'+fmtText(text)+'</div>':'';
  var d=document.createElement('div');d.className='msg';
  d.innerHTML='<div class="msg-who">'+esc(r.user)+'</div>'
    +'<div class="msg-right">'
    +'<div class="msg-time">'+r.time+'</div>'
    +textHtml
    +media
    +'</div>';
  return d;
}

var lastSend=0;
var sending=false;
function setComposerDrag(on){
  composer.classList.toggle('drag-on',!!on);
  updateInputHint();
}

function setSending(on){
  sending=!!on;
  sendBtn.disabled=sending;
  sendBtn.textContent=sending?'发送中':'发送';
}

function addPickedFiles(next){
  next=(next||[]).filter(function(file){return file&&file.size>=0;});
  if(!next.length)return false;
  if(pickedFiles.length+next.length>6){
    toast('一次最多发送 6 个附件','err');
    return false;
  }
  pickedFiles=pickedFiles.concat(next);
  renderAttachList();
  updateInputHint();
  return true;
}

function renderAttachList(){
  var el=document.getElementById('attachList');
  el.innerHTML=pickedFiles.map(function(file,idx){
    return '<div class="attach-chip">'
      +'<span class="attach-chip-name">'+esc(file.name)+'</span>'
      +'<button class="attach-chip-remove" type="button" onclick="removePickedFile('+idx+')">×</button>'
      +'</div>';
  }).join('');
}

function removePickedFile(idx){
  pickedFiles.splice(idx,1);
  renderAttachList();
  updateInputHint();
}

function clearPickedFiles(){
  pickedFiles=[];
  document.getElementById('fileInput').value='';
  renderAttachList();
  updateInputHint();
}

function filesFromDataTransfer(dt){
  if(!dt)return [];
  if(dt.files&&dt.files.length)return Array.prototype.slice.call(dt.files);
  return [];
}

function filesFromClipboard(e){
  var files=[];
  var cd=e.clipboardData;
  if(!cd)return files;
  if(cd.items&&cd.items.length){
    Array.prototype.forEach.call(cd.items,function(item){
      if(item.kind==='file'){
        var file=item.getAsFile();
        if(file)files.push(file);
      }
    });
  }
  if(!files.length&&cd.files&&cd.files.length){
    files=Array.prototype.slice.call(cd.files);
  }
  return files;
}

function updateInputHint(){
  var txt=ta.value.trim();
  var dragging=composer.classList.contains('drag-on');
  if(dragging){
    hintText('松手添加');
    return;
  }
  if(pickedFiles.length){
    hintText('已选 '+pickedFiles.length+' 个附件 / 可继续输入');
    return;
  }
  if(txt){
    hintText('可继续添加附件 / 拖拽 / 粘贴');
    return;
  }
  hintText('添加附件 / 拖拽 / 粘贴 / 10MB 内');
}

function sendMsg(){
  var user=document.getElementById('txtUser').value.trim();
  var textEl=document.getElementById('txtContent');
  var rawTxt=textEl.value;
  var txt=rawTxt.trim();
  if(!txt&&!pickedFiles.length)return;
  if(!user){toast('请输入昵称','err');return;}
  if(sending||Date.now()-lastSend<300)return;
  lastSend=Date.now();
  setSending(true);
  localStorage.setItem('r_'+R.name,user);
  var body=new FormData();
  body.append('room',R.name);
  body.append('user',user);
  body.append('content',txt?enc(rawTxt):'');
  pickedFiles.forEach(function(file){body.append('attachments[]',file,file.name);});
  fetch('index.php?type=send',{
    method:'POST',
    body:body
  }).then(function(r){return r.text();})
    .then(function(text){
      try{return JSON.parse(text);}
      catch(e){throw new Error(text.indexOf('<')!==-1?'服务器异常 / 请检查 PHP 配置':'发送失败 / 请重试');}
    })
    .then(function(d){
      if(d.result!=='ok')throw new Error(d.msg||'发送失败');
      textEl.value='';
      clearPickedFiles();
      resize();
      updateInputHint();
    })
    .catch(function(err){toast(err.message||'发送失败 / 请重试','err');})
    .finally(function(){setSending(false);});
}

var newRes=null;
function openCreate(){document.getElementById('vCreate').classList.add('on');}
function closeCreate(){document.getElementById('vCreate').classList.remove('on');}
function doCreate(){
  var pw=document.getElementById('newPw').value;
  var btn=document.getElementById('createBtn');
  btn.textContent='…';btn.disabled=true;
  fetch('index.php?type=new',{
    method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},
    body:new URLSearchParams({password:pw})
  })
    .then(function(r){return r.json();})
    .then(function(d){
      btn.textContent='创建';btn.disabled=false;
      if(d.result==='ok'){newRes=d;closeCreate();document.getElementById('rRoom').textContent=d.room;document.getElementById('rPw').textContent=d.password;document.getElementById('vResult').classList.add('on');}
    })
    .catch(function(){btn.textContent='创建';btn.disabled=false;toast('创建失败 / 请重试','err');});
}
function goRoom(){if(newRes)window.location.href='index.php?room='+newRes.room;}

function clearMsgs(){
  document.getElementById('msgs').innerHTML='<div class="no-msgs" id="es">暂无消息</div>';
  seen={};
}

function closeSide(){document.getElementById('side').classList.remove('open');}

var ta=document.getElementById('txtContent');
var composer=document.querySelector('.composer');
var sendBtn=document.getElementById('sendBtn');
function resize(){ta.style.height='auto';ta.style.height=Math.min(ta.scrollHeight,108)+'px';}
ta.addEventListener('input',function(){resize();updateInputHint();});
ta.addEventListener('keydown',function(e){if(e.key==='Enter'&&!e.shiftKey){e.preventDefault();sendMsg();}});
ta.addEventListener('paste',function(e){
  var files=filesFromClipboard(e);
  if(!files.length)return;
  e.preventDefault();
  addPickedFiles(files);
});
composer.addEventListener('dragenter',function(e){
  e.preventDefault();
  dragDepth++;
  setComposerDrag(true);
  if(e.dataTransfer)e.dataTransfer.dropEffect='copy';
});
composer.addEventListener('dragover',function(e){
  e.preventDefault();
  setComposerDrag(true);
  if(e.dataTransfer)e.dataTransfer.dropEffect='copy';
});
composer.addEventListener('dragleave',function(e){
  e.preventDefault();
  dragDepth=Math.max(0,dragDepth-1);
  if(!dragDepth||!composer.contains(e.relatedTarget)){
    dragDepth=0;
    setComposerDrag(false);
  }
});
composer.addEventListener('drop',function(e){
  e.preventDefault();
  dragDepth=0;
  setComposerDrag(false);
  addPickedFiles(filesFromDataTransfer(e.dataTransfer));
});
window.addEventListener('dragover',function(e){
  if(e.dataTransfer&&Array.prototype.indexOf.call(e.dataTransfer.types||[],'Files')!==-1)e.preventDefault();
});
window.addEventListener('drop',function(e){
  if(e.dataTransfer&&Array.prototype.indexOf.call(e.dataTransfer.types||[],'Files')!==-1)e.preventDefault();
});
document.getElementById('fileInput').addEventListener('change',function(e){
  var next=Array.prototype.slice.call(e.target.files||[]);
  if(!next.length)return;
  addPickedFiles(next);
  e.target.value='';
});

(function(){
  var s=localStorage.getItem('r_'+R.name);if(s)document.getElementById('txtUser').value=s;
  resize();
  updateInputHint();
})();
<?php endif; ?>
</script>
</body>
</html>
