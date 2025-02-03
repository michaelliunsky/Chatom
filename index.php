<?php
// 使用PHP做的单页面在线聊天。
// 20250123 BY MKLIU
// 基本功能：
// 1. 多人聊天
// 2. 多房间
// 3. 传输信息加密，基于base64+字符替换实现
// 4. 基于长连接读取（ngnix使用PHP sleep有问题）
// 5. 支持昵称自定义，并使用浏览器保存。
// 6. 需要在程序目录创建chat_data文件夹，用来存储历史聊天数据
// 7. 支持新建房间，自动生成密码
// 8. 支持密码保护房间
// 9. 在config.php中设置网站标题和logo

include 'config.php';
date_default_timezone_set("PRC");
error_reporting(E_ALL & ~E_NOTICE);
set_time_limit(30);

$room = $_REQUEST['room'] ?? 'default';
$type = $_REQUEST['type'] ?? 'enter';
$type = strtolower($type);

// 获取所有聊天室
function getChatrooms() {
    $files = glob('./chat_data/*.txt');
    $chatrooms = [];
    foreach ($files as $file) {
        $filename = basename($file, '.txt');
        $chatrooms[] = $filename;
    }
    return $chatrooms;
}

// 生成新房间
function newRoom($room, $custompassword = null) {
    $room_file = './chat_data/' . $room . '.txt';
    $key_list = array_merge(range(48, 57), range(65, 90), range(97, 122), [43, 47, 61]);
    $key1_list = $key_list;
    shuffle($key1_list);

    if ($room !== 'default' && !$custompassword) {
        $custompassword = generateRandomPassword();
    }

    $room_data = [
        'name'   => $room,
        'encode' => array_combine($key_list, $key1_list),
        'list'   => [],
        'time'   => date('Y-m-d H:i:s'),
        'password' => $room === 'default' ? null : password_hash($custompassword, PASSWORD_DEFAULT),
    ];
    file_put_contents($room_file, json_encode($room_data));
    return $custompassword;
}

// 检测密码是否正确
function checkPassword() {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $password = $_POST['password'] ?? '';
        $roominput = $_POST['room'] ?? '';
        $room_file = './chat_data/' . $roominput . '.txt';
        if (file_exists($room_file)) {
            $room_data = json_decode(file_get_contents($room_file), true);
            $correctPassword = $room_data['password']; // 获取正确的密码哈希
            if (password_verify($password, $correctPassword)) {
                return true;
            } else {
                echo '<script>
                    alert("密码错误，请重试。");
                    window.location.reload();
                </script>';
                return false;
            }
        } else {
            echo '<script>
                alert("房间不存在，请重试。");
                window.location.reload();
            </script>';
            return false;
        }
    } else {
        echo '<div class="overlay">
    <div class="form-container">
        <form id="passwordForm" method="post" action="">
            <h2>请输入房间号和密码</h2>
            <label for="room">房间号：</label>
            <input type="text" name="room" id="userRoom" required>
            <br>
            <label for="password">密码：</label>
            <input type="password" name="password" id="userPassword" required>
            <br>
            <input type="submit" value="提交">
        </form>
    </div>
</div>
<style>
.overlay {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: linear-gradient(135deg, #FFD700, #1E90FF); /* 黄蓝渐变色 */
    display: flex;
    justify-content: center;
    align-items: center;
}
.form-container {
    background: #fff;
    padding: 20px;
    border-radius: 8px;
    box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
    max-width: 400px;
    width: 100%;
}
h2 {
    text-align: center;
    margin-bottom: 20px;
    color: #444;
}
label {
    display: block;
    margin-bottom: 5px;
    font-weight: bold;
    color: #333;
}
input[type="text"], input[type="password"] {
    width: calc(100% - 20px);
    padding: 10px;
    margin: 10px 0;
    border: 1px solid #ccc;
    border-radius: 4px;
    font-size: 16px;
    box-sizing: border-box;
    box-shadow: inset 2px 2px 5px rgba(0, 0, 0, 0.1), inset -2px -2px 5px rgba(255, 255, 255, 0.7);
    background: #f9f9f9;
}
input[type="submit"] {
    background-color: #007BFF;
    color: #fff;
    padding: 10px 20px;
    border: none;
    border-radius: 4px;
    font-size: 16px;
    cursor: pointer;
    transition: background-color 0.3s ease;
    box-shadow: 2px 2px 5px rgba(0, 0, 0, 0.1), -2px -2px 5px rgba(255, 255, 255, 0.7);
    width: 100%;
}
input[type="submit"]:hover {
    background-color: #0056b3;
}
</style>
';
        exit;
    }
}

function generateRandomPassword() {
    $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
    $passwordgen = '';
    for ($i = 0; $i < 8; $i++) {
        $passwordgen .= $chars[rand(0, strlen($chars) - 1)];
    }
    return $passwordgen;
}

//20250123 BY MKLIU
// 获取消息列表
function getMsg($room, $last_id) {
    $room_file = './chat_data/' . $room . '.txt';
    $msg_list = [];

    $room_data = json_decode(file_get_contents($room_file), true);
    $list = $room_data['list'];

    // 清除一周前消息
    $cur_list = [];
    $del_time = date('Y-m-d H:i:s', time() - 604800);
    foreach ($list as $r) {
        if ($r['time'] > $del_time) {
            $cur_list[] = $r;
        }
    }

    if (count($cur_list) != count($list) && count($list) > 0) {
        $room_data['list'] = $cur_list;
        file_put_contents($room_file, json_encode($room_data));
    }

    // 查找最新消息
    foreach ($list as $r) {
        if ($r['id'] > $last_id) {
            $msg_list[] = $r;
        }
    }

    return $msg_list;
}

$room_file = './chat_data/' . $room . '.txt';

switch ($type) {
    case 'enter':   // 进入房间
        $authenticated = false;
        
        // 如果房间名称为 'default'，直接通过身份验证
        if ($room === 'default') {
            $authenticated = true;
        } else {
            if (checkPassword()) {
                $authenticated = true;
            }
        }
    
        if ($authenticated) {
            // 密码正确或房间为 'default'，继续执行聊天功能
            break;
        } else {
            // 如果验证失败，直接退出
            exit;
        }
        break;

    // 进入房间，显示聊天窗口
    case 'get':     // 获取消息
        $last_id = $_REQUEST['last_id'];
        $msg_list = [];

        if (strpos($_SERVER['SERVER_SOFTWARE'], 'nginx') !== false) {
            $msg_list = getMsg($room, $last_id);
        } else {
            // nginx 使用sleep将会把整个网站卡死
            for ($i = 0; $i < 20; $i++) {
                $msg_list = getMsg($room, $last_id);
                
                if (!empty($msg_list)) {
                    break;
                }
    
                usleep(500000);
            }
        }

        echo json_encode(['result' => 'ok', 'list' => $msg_list]);

        break;
    case 'send':    // 发送消息
        $item = [
            'id' => round(microtime(true) * 1000),
            'user' => $_REQUEST['user'],
            'content' => $_REQUEST['content'],
            'time' => date('Y-m-d H:i:s'),
        ];
        $room_data = json_decode(file_get_contents($room_file), true);
        $room_data['list'][] = $item;
        file_put_contents($room_file, json_encode($room_data));
        echo json_encode(['result' => 'ok']);
        break;
    case 'new':     // 新建房间
        mt_srand();
        $room = strtoupper(md5(uniqid(mt_rand(), true)));
        $room = substr($room, 0, 10);
        $passwordinput = $_REQUEST['password'] ?? null;
        $generatedPassword = newRoom($room, $passwordinput);
        echo '<script>alert("房间号是：' . $room . '，房间密码是：' . $generatedPassword . '，请保存好。"); window.location.href="index.php?room=' . $room . '";</script>';
        exit;
        break;
    default:
        echo 'ERROR:no type!';
        break;
}

if ($type != 'enter') {
    exit;
}

if (!file_exists($room_file)) {
    if ($room == 'default') {
        newRoom($room);
    } else {
        echo 'ERROR:room not exists!';
        exit;
    }
}

$room_data = json_decode(file_get_contents($room_file), true);
unset($room_data['list']);

$user = 'User' . str_pad((time() % 99 + 1), 2, '0', STR_PAD_LEFT);

$chatrooms = getChatrooms(); // 获取所有聊天室

?>

<!--html页面-->
<!--20250123 BY MKLIU-->
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta http-equiv="X-UA-Compatible" content="IE=edge">
<meta name="renderer" content="webkit">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo $title; ?></title>
<link rel="icon" href="<?php echo $logoUrl; ?>" type="image/x-icon">
<link href="https://lib.baomitu.com/normalize/latest/normalize.min.css" rel="stylesheet">
<style>
/* css style */
body {
    padding: 0 10px;
}
.divMain {
    font-size: 14px;
    line-height: 2;
}

#divList span {
    color: gray;
}
body {
    margin: 0;
    padding: 0;
    font-family: 'Arial', sans-serif;
    background-color: #f4f4f9;
    color: #333;
}

/* 主标题样式 */
h1 {
    text-align: center;
    font-size: 2.5em;
    color: #444;
    margin-top: 0px;
}

/* 在线聊天室列表 */
#chatroomList {
    max-width: 800px;
    margin: 20px auto;
    background: #ffffff;
    padding: 20px;
    border-radius: 8px;
    box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
    line-height: 1.6;
}

/* 主体容器 */
.divMain {
    max-width: 800px;
    margin: 20px auto;
    background: #ffffff;
    padding: 20px;
    border-radius: 8px;
    box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
    line-height: 1.6;
}

/* 输入框样式 */
input[type="text"], input[type="password"] {
    width: calc(100% - 120px);
    padding: 10px;
    margin: 10px 0;
    border: 1px solid #ccc;
    border-radius: 4px;
    font-size: 16px;
    box-sizing: border-box;
}

/* 按钮样式 */
button {
    background-color: #007BFF;
    color: #fff;
    padding: 10px 20px;
    border: none;
    border-radius: 4px;
    font-size: 16px;
    cursor: pointer;
    transition: background-color 0.3s ease;
}

button:hover {
    background-color: #0056b3;
}

/* 链接样式 */
a {
    color: #007BFF;
    text-decoration: none;
    font-size: 14px;
    margin-left: 10px;
}

a:hover {
    text-decoration: underline;
}

/* 消息列表 */
#divList {
    margin-top: 20px;
    padding: 10px;
    border-top: 1px solid #ddd;
    background-color: rgba(249, 249, 249, 0.8); /* 浅灰色半透明背景 */
}

#divList div {
    margin-bottom: 10px;
    padding: 8px;
    background: #ffffff; /* 消息背景为白色 */
    border: 1px solid #e3e3e3;
    border-radius: 4px;
}

#divList span {
    color: #888;
    font-size: 12px;
    margin-right: 10px;
}

/* 消息用户名加粗 */
#divList b {
    font-weight: bold;
    color: #333;
}

/* 响应式支持 */
@media (max-width: 600px) {
    .divMain {
        padding: 15px;
    }

    input[type="text"] {
        width: calc(100% - 90px);
    }

    button {
        padding: 8px 15px;
        font-size: 14px;
    }
}
</style>

<script src="https://lib.baomitu.com/jquery/3.4.1/jquery.min.js"></script>
</head>
<body>
    
<h1><?php echo $title; ?></h1>
<h2 align="center">在线房间</h2>
<div id="chatroomList"></div>
<div class="divMain">
昵称：<input id="txtUser" type="text" maxlength="50" value="<?=$user?>" />
<button onclick="$('#divList').html('');">清空</button>
<br>
内容：<input id="txtContent" type="text" value="" maxlength="100" style="width: 300px;" />
<button onclick="sendMsg();">发送</button>
<br>
<label for="password">密码：</label>
<input type="password" id="txtPassword" maxlength="50" />
<button onclick="createRoom();">新房间</button><p>若密码为空，则将自动生成密码</p>

<hr>
<div id="divList"></div>
</div>
<!--20250123 BY MKLIU-->
<!--使用worker获取消息数据，注意ngnix会阻塞整个进程-->
<script id="worker" type="app/worker">
    var room = '<?=$room_data['name']?>';
    var isBusy = false;
    var lastId = -1;

    var urlBase = '';
    addEventListener('message', function (evt) {
        urlBase = evt.data;
    }, false);
    setInterval(function(){
        if (isBusy) return;
        isBusy = true;

        let url = new URL( 'index.php?type=get&room=' + room + '&last_id=' + lastId, urlBase );
        fetch(url)
        .then(res=>res.json())
        .then(function(res){
            isBusy = false;
            if (res.list.length > 0)
            {
                lastId = res.list[res.list.length-1].id;
            }
            self.postMessage(res);
        })
        .catch(function(err){
            isBusy = false;
        });
    }, 1000);
</script>
<script>
    var blob = new Blob([document.querySelector('#worker').textContent]);
    var url = window.URL.createObjectURL(blob);
    var worker = new Worker(url);

    worker.onmessage = function (e) {
        let res = e.data;
        let html = '';
        for (let k in res.list)
        {
            let r = res.list[k];
            html = '<div><span>' + r.time + '</span> <b>' + r.user + ':</b>   ' + decodeContent(r.content) + '</div>' + html;
        }

        $('#divList').prepend(html);
    };

    worker.postMessage(document.baseURI);
</script>

<script>
var room = <?=json_encode($room_data)?>;
room['decode'] = {};
for (let k in room.encode)
{
    room['decode'][room.encode[k]] = k;
}

//20250123 BY MKLIU
// 发送消息
var lastSendTime = 0;
function sendMsg()
{
    let user = $('#txtUser').val().trim();
    let content = $('#txtContent').val().trim();

    if (content == '')
    {
        return;
    }

    if (user == '')
    {
        alert('昵称不能为空');
        return;
    }

    window.localStorage.setItem('r_' + room.name, user);
    
    // 限制0.3秒内仅允许发送1条消息
    let curTime = new Date().getTime();
    if (curTime - lastSendTime < 300)
    {
        return;
    }
    lastSendTime = curTime;

    $.ajax({
        url:'index.php?type=send',
        data:{room:room.name, user:user, content:encodeContent(content)},
        type:'POST',
        dataType:'json',
        success:function(){
            $('#txtContent').val('');
            $('#txtContent').focus();
        },
    });
}

//20250123 BY MKLIU
// 消息加密
function encodeContent(content)
{
    content = encodeURIComponent(content);
    content = window.btoa(content);

    let str = '';
    for (let i=0; i<content.length; i++)
    {
        str += String.fromCharCode(room.encode[content.charCodeAt(i)]);
    }

    return str;
}

//20250123 BY MKLIU
// 消息解密
function decodeContent(content)
{
    let str = '';
    for (let i=0; i<content.length; i++)
    {
        str += String.fromCharCode(room.decode[content.charCodeAt(i)]);
    }

    str = window.atob(str);
    str = decodeURIComponent(str);

    return str;
}

$(function(){
    let userName = window.localStorage.getItem('r_' + room.name);
    if (userName)
    {
        $('#txtUser').val(userName);
    }

    $('#txtContent').keydown(function(e){
        if(e.keyCode==13){
            event.preventDefault();
            sendMsg();
        }
    });

    $('#txtContent').val('🥳 我来了!');
    sendMsg();
});

function createRoom() {
    let password = document.getElementById('txtPassword').value;
    window.location.href = 'index.php?type=new&password=' + encodeURIComponent(password);
}

// 呈现在线chatrooms
var chatrooms = <?= json_encode($chatrooms) ?>;
var chatroomList = document.getElementById('chatroomList');
chatrooms.forEach(function(room) {
    var roomLink = document.createElement('a');
    roomLink.href = 'index.php?room=' + room;
    roomLink.textContent = room;
    chatroomList.appendChild(roomLink);
    chatroomList.appendChild(document.createElement('br'));
});
</script>
<div align="center">
    Copyright © 2025 By <a href="https://www.mkliu.top/"><strong>michaelliunsky</strong></a> & <strong>Yuer6327</strong>
</div>
</body>
</html>