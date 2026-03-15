<?php
// 单页面在线聊天
// 20250123 20260315 BY MKLIU
include 'config.php';
date_default_timezone_set("PRC");
error_reporting(E_ALL & ~E_NOTICE);
set_time_limit(30);

$room = preg_replace('/[^a-zA-Z0-9_\-]/', '', $_REQUEST['room'] ?? 'default');
if ($room === '') $room = 'default';
$type = strtolower($_REQUEST['type'] ?? 'enter');

function getChatrooms() {
    $files = glob('./chat_data/*.txt');
    $rooms = [];
    foreach ($files as $f) $rooms[] = basename($f, '.txt');
    return $rooms;
}

function generateRandomPassword() {
    $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
    $pw = '';
    for ($i = 0; $i < 8; $i++) $pw .= $chars[rand(0, strlen($chars) - 1)];
    return $pw;
}

function newRoom($room, $custompassword = null) {
    $room_file = './chat_data/' . $room . '.txt';
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
    file_put_contents($room_file, json_encode($data));
    return $custompassword;
}

function getMsg($room, $last_id) {
    $room_file = './chat_data/' . $room . '.txt';
    $data      = json_decode(file_get_contents($room_file), true);
    $list      = $data['list'];
    $del_time  = date('Y-m-d H:i:s', time() - 604800);
    $cur = array_values(array_filter($list, fn($r) => $r['time'] > $del_time));
    if (count($cur) !== count($list)) {
        $data['list'] = $cur;
        file_put_contents($room_file, json_encode($data));
    }
    return array_values(array_filter($cur, fn($r) => $r['id'] > $last_id));
}

if ($type === 'get') {
    $last_id  = (int)($_REQUEST['last_id'] ?? -1);
    $room_file_get = './chat_data/' . $room . '.txt';
    if (!file_exists($room_file_get)) {
        header('Content-Type: application/json');
        echo json_encode(['result' => 'ok', 'list' => []]);
        exit;
    }
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
    header('Content-Type: application/json');
    echo json_encode(['result' => 'ok', 'list' => $msg_list]);
    exit;
}

if ($type === 'send') {
    $room_file = './chat_data/' . $room . '.txt';
    if (!file_exists($room_file)) {
        header('Content-Type: application/json');
        echo json_encode(['result' => 'error', 'msg' => 'room not found']);
        exit;
    }
    $item = [
        'id'      => round(microtime(true) * 1000),
        'user'    => htmlspecialchars(substr($_REQUEST['user'] ?? 'anon', 0, 50)),
        'content' => $_REQUEST['content'] ?? '',
        'time'    => date('Y-m-d H:i:s'),
    ];
    $data           = json_decode(file_get_contents($room_file), true);
    $data['list'][] = $item;
    file_put_contents($room_file, json_encode($data));
    header('Content-Type: application/json');
    echo json_encode(['result' => 'ok']);
    exit;
}

if ($type === 'new') {
    mt_srand();
    $newroom  = strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 10));
    $pw_input = $_POST['password'] ?? null;
    $gen_pw   = newRoom($newroom, $pw_input ?: null);
    header('Content-Type: application/json');
    echo json_encode(['result' => 'ok', 'room' => $newroom, 'password' => $gen_pw]);
    exit;
}

// Page render
$room_file   = './chat_data/' . $room . '.txt';
$requireAuth = false;
$authError   = '';

if ($room === 'default') {
    if (!file_exists($room_file)) newRoom($room);
} else {
    if (!file_exists($room_file)) { header('Location: index.php'); exit; }
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['auth_submit'])) {
        $input_pw      = $_POST['password'] ?? '';
        $room_data_tmp = json_decode(file_get_contents($room_file), true);
        if (!password_verify($input_pw, $room_data_tmp['password'])) {
            $requireAuth = true;
            $authError   = '密码错误';
        }
    } else {
        $requireAuth = true;
    }
}

$room_data = json_decode(file_get_contents($room_file), true);
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
  border-radius: 5px;
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
  padding: 4px 10px;
  border: 1px solid var(--c3);
  border-radius: 4px;
  background: transparent;
  font-size: 12px;
  color: var(--c5);
  cursor: pointer;
  transition: color .15s, border-color .15s;
}
.clear-btn:hover { color: var(--c7); border-color: var(--c6); }

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
  display: flex; gap: 8px; align-items: flex-end;
}

#txtContent {
  flex: 1;
  border: 1px solid var(--c3);
  border-radius: 6px;
  padding: 8px 11px;
  font-size: 14px;
  font-family: var(--sans);
  color: var(--c7);
  background: var(--c0);
  outline: none;
  resize: none;
  min-height: 36px;
  max-height: 108px;
  line-height: 1.5;
  transition: border-color .15s;
}
#txtContent:focus { border-color: var(--c6); }
#txtContent::placeholder { color: var(--c4); }

.send {
  height: 36px; padding: 0 14px;
  background: var(--c7);
  border: none; border-radius: 6px;
  color: var(--c0);
  font-size: 13px; font-weight: 500;
  cursor: pointer;
  flex-shrink: 0;
  transition: background .15s;
}
.send:hover { background: var(--c6); }

.input-hint { font-size: 11px; color: var(--c4); margin-top: 5px; }

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
  flex: 1; padding: 8px;
  background: var(--c7); border: none; border-radius: 5px;
  color: var(--c0); font-size: 13px; font-weight: 500;
  cursor: pointer; transition: background .15s;
}
.btn-dark:hover { background: var(--c6); }

.btn-line {
  padding: 8px 14px;
  background: transparent;
  border: 1px solid var(--c3); border-radius: 5px;
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
  .clear-btn   { font-size: 13px; padding: 5px 12px; }
  .msgs        { padding: 16px 20px; }
  .msg         { padding: 6px 0; gap: 0 12px; }
  .msg-who     { font-size: 14px; min-width: 90px; max-width: 140px; }
  .msg-time    { font-size: 12px; }
  .msg-text    { font-size: 15px; }
  .no-msgs     { font-size: 14px; }
  .input-area  { padding: 12px 18px 16px; }
  #txtContent  { font-size: 15px; padding: 9px 12px; }
  .send        { font-size: 14px; height: 38px; padding: 0 18px; }
  .input-hint  { font-size: 12px; }
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
</style>
</head>
<body>

<?php if ($requireAuth): ?>
<div class="auth-wrap">
  <div class="auth-box">
    <div class="auth-title"><?= htmlspecialchars($room) ?></div>
    <div class="auth-sub">此房间需要密码。</div>
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
        <button class="clear-btn" id="menuBtn" style="display:none"
                onclick="document.getElementById('side').classList.toggle('open')">☰</button>
        <span class="topbar-room"><?= htmlspecialchars($room_data['name']) ?></span>
        <span class="topbar-meta"><?= date('Y-m-d') ?></span>
      </div>
      <button class="clear-btn" onclick="clearMsgs()">清空</button>
    </div>

    <div class="msgs" id="msgs">
      <div class="no-msgs" id="es">暂无消息</div>
    </div>

    <div class="input-area">
      <div class="input-row">
        <textarea id="txtContent" rows="1" placeholder="输入消息…"></textarea>
        <button class="send" onclick="sendMsg()">发送</button>
      </div>
      <div class="input-hint">Enter 发送 · Shift+Enter 换行</div>
    </div>
  </section>
</div>

<!-- Create modal -->
<div class="veil" id="vCreate">
  <div class="panel">
    <div class="panel-title">新建房间</div>
    <div class="panel-sub">房间 ID 随机生成。密码留空则自动生成。</div>
    <div class="field-label">密码（可选）</div>
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
    <div class="panel-sub">请保存以下信息，密码无法找回。</div>
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

var wk=new Worker(URL.createObjectURL(new Blob([document.getElementById('wk').textContent])));
var seen={};

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
  var d=document.createElement('div');d.className='msg';
  d.innerHTML='<div class="msg-who">'+esc(r.user)+'</div>'
    +'<div class="msg-right">'
    +'<div class="msg-time">'+r.time+'</div>'
    +'<div class="msg-text">'+esc(text)+'</div>'
    +'</div>';
  return d;
}

var lastSend=0;
function sendMsg(){
  var user=document.getElementById('txtUser').value.trim();
  var txt=document.getElementById('txtContent').value.trim();
  if(!txt)return;
  if(!user){toast('请输入昵称','err');return;}
  if(Date.now()-lastSend<300)return;
  lastSend=Date.now();
  localStorage.setItem('r_'+R.name,user);
  fetch('index.php?type=send',{
    method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},
    body:new URLSearchParams({room:R.name,user:user,content:enc(txt)})
  }).then(function(){document.getElementById('txtContent').value='';resize();})
    .catch(function(){toast('发送失败，请重试','err');});
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
    .catch(function(){btn.textContent='创建';btn.disabled=false;toast('创建失败，请重试','err');});
}
function goRoom(){if(newRes)window.location.href='index.php?room='+newRes.room;}

function clearMsgs(){
  document.getElementById('msgs').innerHTML='<div class="no-msgs" id="es">暂无消息</div>';
  seen={};
}

function closeSide(){document.getElementById('side').classList.remove('open');}

var ta=document.getElementById('txtContent');
function resize(){ta.style.height='auto';ta.style.height=Math.min(ta.scrollHeight,108)+'px';}
ta.addEventListener('input',resize);
ta.addEventListener('keydown',function(e){if(e.key==='Enter'&&!e.shiftKey){e.preventDefault();sendMsg();}});

(function(){
  var s=localStorage.getItem('r_'+R.name);if(s)document.getElementById('txtUser').value=s;
  document.getElementById('txtContent').value='🥳 我来了！';sendMsg();
})();

function checkW(){document.getElementById('menuBtn').style.display=window.innerWidth<=600?'flex':'none';}
checkW();window.addEventListener('resize',checkW);
<?php endif; ?>
</script>
</body>
</html>