<?php
/**
 * 支付同步跳转回调接口
 * 功能：向用户展示支付结果（最终状态以异步通知为准）
 */

// -------------------------- 基础配置（需与Python支付请求一致）--------------------------
$merchant_key = "TXz060E3LFXqtF650E0007aE70f6V70p"; // 商户密钥（和Python代码中一致）
$merchant_id = 1405; // 商户ID（和Python代码中一致）
// -----------------------------------------------------------------------------------

// 1. 接收支付平台的GET参数（同步跳转通常用GET，部分平台用POST，需确认）
$return_params = $_GET;
// 日志记录（可选）
file_put_contents('return_log.txt', date('Y-m-d H:i:s') . " 接收到跳转：" . json_encode($return_params) . "\n", FILE_APPEND);

// 2. 校验参数是否完整
$required_fields = ['pid', 'out_trade_no', 'trade_status', 'sign'];
foreach ($required_fields as $field) {
    if (empty($return_params[$field])) {
        show_result('失败', '参数缺失，支付结果未知', $return_params['out_trade_no'] ?? '');
    }
}

// 3. 校验商户ID
if ($return_params['pid'] != $merchant_id) {
    show_result('失败', '商户ID错误，可能是非法跳转', $return_params['out_trade_no']);
}

// 4. 校验签名（防止伪造跳转）
$sign = $return_params['sign'];
unset($return_params['sign']);
unset($return_params['sign_type']);

// 按ASCII升序排序
ksort($return_params);

// 拼接签名字符串
$sign_str = '';
foreach ($return_params as $key => $value) {
    $sign_str .= "{$key}={$value}&";
}
$sign_str .= "key={$merchant_key}";

// 计算MD5签名
$local_sign = md5($sign_str);
if ($local_sign != $sign) {
    show_result('失败', '签名校验错误，可能是非法跳转', $return_params['out_trade_no']);
}

// 5. 查询数据库确认订单状态（最终以异步通知为准，避免跳转延迟导致的状态不一致）
$out_trade_no = $return_params['out_trade_no'];
$db_host = 'localhost';
$db_user = 'root';
$db_pwd = '你的数据库密码';
$db_name = '你的数据库名';

$conn = mysqli_connect($db_host, $db_user, $db_pwd, $db_name);
if (!$conn) {
    show_result('待确认', '数据库连接失败，请稍后查询订单状态', $out_trade_no);
}

// 查询订单状态
$sql = "SELECT status, money, trade_no FROM `order` WHERE out_trade_no = '{$out_trade_no}' LIMIT 1";
$result = mysqli_query($conn, $sql);
$order = mysqli_fetch_assoc($result);
mysqli_close($conn);

if (!$order) {
    show_result('失败', '订单不存在', $out_trade_no);
}

// 6. 展示支付结果
if ($order['status'] == 1) {
    // 订单已支付（异步通知已处理）
    show_result(
        '支付成功',
        "订单号：{$out_trade_no}<br>支付平台订单号：{$order['trade_no']}<br>支付金额：{$order['money']}元",
        $out_trade_no
    );
} elseif ($return_params['trade_status'] == 'TRADE_SUCCESS') {
    // 平台返回成功，但本地订单未更新（可能异步通知延迟）
    show_result(
        '支付待确认',
        "支付平台已返回成功，但订单状态待确认<br>订单号：{$out_trade_no}<br>请稍后刷新页面或联系客服",
        $out_trade_no
    );
} else {
    // 支付失败或未完成
    show_result(
        '支付失败',
        "订单号：{$out_trade_no}<br>支付状态：{$return_params['trade_status']}<br>请重新发起支付",
        $out_trade_no
    );
}

/**
 * 统一展示支付结果页面
 * @param string $title 结果标题（成功/失败/待确认）
 * @param string $content 结果详情
 * @param string $order_no 订单号
 */
function show_result($title, $content, $order_no) {
    echo <<<HTML
    <!DOCTYPE html>
    <html lang="zh-CN">
    <head>
        <meta charset="UTF-8">
        <title>{$title} - 支付结果</title>
        <style>
            .result-container {
                width: 600px;
                margin: 100px auto;
                text-align: center;
                border: 1px solid #eee;
                padding: 30px;
                border-radius: 8px;
            }
            .success { color: #4CAF50; }
            .fail { color: #F44336; }
            .pending { color: #FF9800; }
            .order-no { margin: 20px 0; color: #666; }
            .btn {
                display: inline-block;
                padding: 10px 20px;
                background: #2196F3;
                color: #fff;
                text-decoration: none;
                border-radius: 4px;
                margin-top: 20px;
            }
        </style>
    </head>
    <body>
        <div class="result-container">
            <h1 class="{$title == '支付成功' ? 'success' : ($title == '支付待确认' ? 'pending' : 'fail')}">
                {$title}
            </h1>
            <div class="content">{$content}</div>
            <div class="order-no">订单号：{$order_no}</div>
            <a href="你的订单列表地址.php" class="btn">查看订单</a>
            <a href="你的首页地址.php" class="btn" style="background: #666; margin-left: 10px;">返回首页</a>
        </div>
    </body>
    </html>
    HTML;
    exit;
}
?>
