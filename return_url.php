<?php
/**
 * 支付同步跳转回调接口（用户可见）
 * 数据库配置已更新为你的实际信息：mysql2.sqlpub.com:3307/yun2025
 */
// -------------------------- 基础配置 --------------------------
$merchant_key = "TXz060E3LFXqtF650E0007aE70f6V70p"; // 商户密钥（与Python代码一致）
$merchant_id = 1405; // 商户ID（与Python代码一致）
// 数据库配置（你的实际信息）
$db_host = "mysql2.sqlpub.com:3307"; // 主机+端口
$db_user = "root203588"; // 数据库账号
$db_pwd = "ZeoXAiaW1wXsYv9H"; // 数据库密码
$db_name = "yun2025"; // 数据库名称
$table_name = "`order`"; // 订单表名（与notify_url.php保持一致）
// ----------------------------------------------------------------

// 1. 接收支付平台GET参数（同步跳转默认用GET，与文档一致）
$return_params = $_GET;
// 日志记录（用于调试）
file_put_contents('return_log.txt', date('Y-m-d H:i:s') . " 接收到跳转：" . json_encode($return_params) . "\n", FILE_APPEND);

// 2. 校验核心参数是否完整
$required_fields = ['pid', 'out_trade_no', 'trade_status', 'sign'];
foreach ($required_fields as $field) {
    if (empty($return_params[$field])) {
        show_result('支付失败', '参数缺失，无法确认支付结果', $return_params['out_trade_no'] ?? '未知订单号');
    }
}

// 3. 校验商户ID是否匹配
if ($return_params['pid'] != $merchant_id) {
    show_result('支付失败', '商户信息不匹配，可能是非法跳转', $return_params['out_trade_no']);
}

// 4. 签名校验（与异步通知逻辑一致）
$sign = $return_params['sign'];
unset($return_params['sign'], $return_params['sign_type']); // 排除不参与签名的字段
ksort($return_params); // 按参数名ASCII升序排序

// 拼接签名字符串
$sign_str = http_build_query($return_params);
$sign_str .= "&key={$merchant_key}";

// 计算本地签名并对比
$local_sign = md5($sign_str);
if ($local_sign != $sign) {
    file_put_contents('return_log.txt', date('Y-m-d H:i:s') . " 签名失败：{$sign_str} | 本地签名：{$local_sign}\n", FILE_APPEND);
    show_result('支付失败', '签名校验错误，可能是非法跳转', $return_params['out_trade_no']);
}

// 5. 连接数据库，查询订单真实状态（最终以异步通知结果为准）
$conn = new mysqli($db_host, $db_user, $db_pwd, $db_name);
if ($conn->connect_error) {
    $error_msg = "数据库连接失败：{$conn->connect_error}";
    file_put_contents('return_log.txt', date('Y-m-d H:i:s') . " {$error_msg}\n", FILE_APPEND);
    show_result('支付待确认', '数据库暂时无法访问，请5分钟后刷新页面查看结果', $return_params['out_trade_no']);
}

// 6. 查询订单信息（转义特殊字符，防止SQL注入）
$out_trade_no = $conn->real_escape_string($return_params['out_trade_no']);
$sql_query = "SELECT status, money, trade_no FROM {$table_name} WHERE out_trade_no = '{$out_trade_no}' LIMIT 1";
$result_query = $conn->query($sql_query);

if (!$result_query) {
    $error_msg = "查询订单失败：{$conn->error}";
    file_put_contents('return_log.txt', date('Y-m-d H:i:s') . " {$error_msg}\n", FILE_APPEND);
    $conn->close();
    show_result('支付待确认', '订单查询失败，请稍后重试', $out_trade_no);
}

$order = $result_query->fetch_assoc();
$conn->close();

// 7. 展示支付结果（根据订单真实状态判断）
if (!$order) {
    show_result('支付失败', "订单不存在：{$out_trade_no}", $out_trade_no);
} elseif ($order['status'] == 1) {
    // 订单已支付（异步通知已处理完成）
    show_result(
        '支付成功',
        "订单号：{$out_trade_no}<br>支付金额：{$order['money']}元<br>支付平台订单号：{$order['trade_no']}",
        $out_trade_no
    );
} elseif ($return_params['trade_status'] == 'TRADE_SUCCESS') {
    // 平台返回成功，但本地订单未更新（可能异步通知延迟）
    show_result(
        '支付待确认',
        "支付平台已确认支付，但订单状态同步中<br>订单号：{$out_trade_no}<br>建议5分钟后刷新页面或查看订单列表",
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
 * 统一展示支付结果页面（美化样式，适配移动端）
 * @param string $title 结果标题（支付成功/失败/待确认）
 * @param string $content 结果详情（支持HTML换行）
 * @param string $order_no 订单号
 */
function show_result($title, $content, $order_no) {
    // 样式适配PC和移动端，避免乱码
    echo <<<HTML
    <!DOCTYPE html>
    <html lang="zh-CN">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>{$title} - 支付结果</title>
        <style>
            * { margin: 0; padding: 0; box-sizing: border-box; }
            body { font-family: "Microsoft YaHei", sans-serif; background: #f5f5f5; }
            .result-box { 
                width: 90%; max-width: 600px; margin: 50px auto; 
                background: #fff; padding: 30px; border-radius: 10px; 
                box-shadow: 0 2px 10px rgba(0,0,0,0.1); text-align: center;
            }
            .success-icon { color: #4CAF50; font-size: 60px; margin-bottom: 20px; }
            .fail-icon { color: #F44336; font-size: 60px; margin-bottom: 20px; }
            .pending-icon { color: #FF9800; font-size: 60px; margin-bottom: 20px; }
            h1 { margin-bottom: 20px; font-size: 24px; }
            .content { line-height: 2; color: #666; font-size: 16px; margin-bottom: 30px; }
            .order-no { color: #333; font-weight: bold; margin: 10px 0; }
            .btn {
                display: inline-block; padding: 12px 30px;
                background: #2196F3; color: #fff; text-decoration: none;
                border-radius: 5px; font-size: 16px; margin: 0 10px;
                transition: background 0.3s;
            }
            .btn:hover { background: #1976D2; }
            .home-btn { background: #666; }
            .home-btn:hover { background: #333; }
        </style>
        <!-- 引入图标库（美化结果图标） -->
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/font-awesome@4.7.0/css/font-awesome.min.css">
    </head>
    <body>
        <div class="result-box">
            <!-- 根据标题显示对应图标 -->
            <?php if ($title == '支付成功'): ?>
                <i class="fa fa-check-circle success-icon"></i>
            <?php elseif ($title == '支付待确认'): ?>
                <i class="fa fa-clock-o pending-icon"></i>
            <?php else: ?>
                <i class="fa fa-times-circle fail-icon"></i>
            <?php endif; ?>
            
            <h1>{$title}</h1>
            <div class="content">{$content}</div>
            <div class="order-no">订单号：{$order_no}</div>
            <!-- 跳转链接（可替换为你的实际页面） -->
            <a href="https://bbs.outlook163qq.eu.org/order_list.php" class="btn">查看订单列表</a>
            <a href="https://bbs.outlook163qq.eu.org" class="btn home-btn">返回首页</a>
        </div>
    </body>
    </html>
    HTML;
    exit;
}
?>
