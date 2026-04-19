<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
date_default_timezone_set('Asia/Shanghai');

// ========== 配置 ==========
$API_ID = 39160773;
$API_HASH = "8ce41906a3466cb8b3b7182f512a7748";
$SESSION_DIR = "data";

// 代理
$USE_SOCKS5_PROXY = true;
$SOCKS5_SERVER = "127.0.0.1";
$SOCKS5_PORT = 7890;

// ========== 工具函数 ==========
function init_dir() {
    global $SESSION_DIR;
    if (!file_exists($SESSION_DIR)) {
        mkdir($SESSION_DIR, 0777, true);
    }
}

// 下载文件
function download_file($path) {
    if (!file_exists($path)) return;
    header('Content-Description: File Transfer');
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="'.basename($path).'"');
    header('Expires: 0');
    header('Cache-Control: must-revalidate');
    header('Pragma: public');
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
}

// ========== 依赖检查 ==========
if (!extension_loaded('curl')) {
    die("❌ 请开启 PHP curl 扩展");
}

// ========== 主逻辑 ==========
init_dir();
$session_file = "";
$error = "";
$success = false;

// 处理表单提交
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $phone = trim($_POST['phone']);
        $code = isset($_POST['code']) ? trim($_POST['code']) : '';
        $password = isset($_POST['password']) ? trim($_POST['password']) : '';
        
        if (empty($phone)) {
            throw new Exception("请输入手机号");
        }

        $session_file = $SESSION_DIR . DIRECTORY_SEPARATOR . $phone;
        $session_full = $session_file . ".session";

        // 加载 MadelineProto（自动下载）
        if (!file_exists('vendor/autoload.php')) {
            file_put_contents('madeline.php', file_get_contents('https://phar.madelineproto.xyz/madeline.php'));
            require 'madeline.php';
        } else {
            require 'vendor/autoload.php';
        }

        // 配置
        $settings = [
            'app_info' => [
                'api_id' => $API_ID,
                'api_hash' => $API_HASH,
            ],
            'session_path' => $SESSION_DIR,
        ];

        // 代理
        if ($USE_SOCKS5_PROXY) {
            $settings['connection']['proxy'] = [
                'type' => 'socks5',
                'address' => $SOCKS5_SERVER,
                'port' => (int)$SOCKS5_PORT,
            ];
        }

        // 登录流程
        $MadelineProto = new \danog\MadelineProto\API($session_file, $settings);
        $MadelineProto->start();

        if ($MadelineProto->isAuthorized()) {
            $me = $MadelineProto->getSelf();
            $success = true;
        }

    } catch (\danog\MadelineProto\RPCErrorException $e) {
        $error = "登录失败：" . $e->getMessage();
    } catch (\Exception $e) {
        $error = "错误：" . $e->getMessage();
    }
}

// 下载生成的 session
if (isset($_GET['download']) && !empty($_GET['file'])) {
    $file = base64_decode($_GET['file']);
    if (file_exists($file)) {
        download_file($file);
    } else {
        die "文件不存在";
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Telegram Session 生成</title>
    <style>
        body {max-width: 600px;margin: 30px auto;font-family: Arial;}
        .box {padding: 20px;border: 1px solid #ddd;border-radius: 8px;}
        .btn {background: #0088cc;color: white;padding: 10px 20px;border: none;border-radius: 5px;cursor: pointer;}
        .success {color: green;background: #f0f9f3;padding: 10px;border-radius: 5px;}
        .error {color: red;background: #fff0f0;padding: 10px;border-radius: 5px;}
    </style>
</head>
<body>
    <div class="box">
        <h2>📶 Telegram Session 生成器</h2>
        <p>生成后自动下载 .session 文件</p>

        <?php if ($error): ?>
            <div class="error"><?= $error ?></div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="success">
                ✅ 登录成功！<br>
                👤 用户：<?= $me['first_name'] ?><br>
                📁 Session 文件已生成
            </div>
            <br>
            <a href="?download=1&file=<?= base64_encode($session_full) ?>" class="btn">
                📥 点击下载 .session 文件
            </a>
        <?php else: ?>
            <form method="post">
                <p>手机号（带国家码，如 +13276666995）：</p>
                <input type="text" name="phone" required style="width: 100%;padding: 8px;margin: 5px 0;">
                
                <p>验证码（登录后收到）：</p>
                <input type="text" name="code" style="width: 100%;padding: 8px;margin: 5px 0;">
                
                <p>二级密码（如开启）：</p>
                <input type="password" name="password" style="width: 100%;padding: 8px;margin: 5px 0;">
                
                <br>
                <button type="submit" class="btn">🚀 登录并生成 Session</button>
            </form>
        <?php endif; ?>
    </div>
</body>
</html>
