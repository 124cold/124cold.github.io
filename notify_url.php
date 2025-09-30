<?php
/**
 * 支付异步通知回调接口
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
$table_name = "`order`"; // 订单表名（若表名不同可修改，建议保留反引号避免关键字冲突）
// ----------------------------------------------------------------

// 1. 接收支付平台POST通知参数
$notify_params = $_POST;
// 日志记录（用于调试，建议保留）
file_put_contents('notify_log.txt', date('Y-m-d H:i:s') . " 接收到通知：" . json_encode($notify_params) . "\n", FILE_APPEND);

// 2. 校验核心参数是否完整
$required_fields = ['pid', 'out_trade_no', 'trade_no', 'money', 'trade_status', 'sign'];
foreach ($required_fields as $field) {
    if (empty($notify_params[$field])) {
        file_put_contents('notify_log.txt', date('Y-m-d H:i:s') . " 缺失参数：{$field}\n", FILE_APPEND);
        exit("fail: 参数缺失（{$field}）"); // 返回fail，支付平台会重试
    }
}

// 3. 校验商户ID是否匹配（防止非法通知）
if ($notify_params['pid'] != $merchant_id) {
    file_put_contents('notify_log.txt', date('Y-m-d H:i:s') . " 商户ID不匹配：{$notify_params['pid']}\n", FILE_APPEND);
    exit("fail: 商户ID错误");
}

// 4. 仅处理“支付成功”的通知（其他状态无需处理）
if ($notify_params['trade_status'] != 'TRADE_SUCCESS') {
    file_put_contents('notify_log.txt', date('Y-m-d H:i:s') . " 支付状态非成功：{$notify_params['trade_status']}\n", FILE_APPEND);
    exit("success"); // 返回success，避免支付平台重复通知
}

// 5. 签名校验（核心！防止伪造通知）
$sign = $notify_params['sign'];
unset($notify_params['sign'], $notify_params['sign_type']); // 排除不参与签名的字段
ksort($notify_params); // 按参数名ASCII升序排序（与Python逻辑一致）

// 拼接签名字符串
$sign_str = http_build_query($notify_params); // 自动生成 key=value&key=value 格式
$sign_str .= "&key={$merchant_key}"; // 末尾拼接商户密钥

// 计算本地签名并对比
$local_sign = md5($sign_str);
if ($local_sign != $sign) {
    file_put_contents('notify_log.txt', date('Y-m-d H:i:s') . " 签名失败！签名字符串：{$sign_str} | 本地签名：{$local_sign} | 平台签名：{$sign}\n", FILE_APPEND);
    exit("fail: 签名错误");
}

// 6. 数据库连接（使用MySQLi面向对象方式，兼容PHP7+）
$conn = new mysqli($db_host, $db_user, $db_pwd, $db_name);
if ($conn->connect_error) {
    $error_msg = "数据库连接失败：{$conn->connect_errno} - {$conn->connect_error}";
    file_put_contents('notify_log.txt', date('Y-m-d H:i:s') . " {$error_msg}\n", FILE_APPEND);
    exit("fail: {$error_msg}");
}

// 7. 防重复处理（查询订单当前状态，避免同一订单重复更新）
$out_trade_no = $conn->real_escape_string($notify_params['out_trade_no']); // 转义特殊字符，防止SQL注入
$sql_check = "SELECT status FROM {$table_name} WHERE out_trade_no = '{$out_trade_no}' LIMIT 1";
$result_check = $conn->query($sql_check);

if (!$result_check) {
    $error_msg = "查询订单失败：{$conn->error}";
    file_put_contents('notify_log.txt', date('Y-m-d H:i:s') . " {$error_msg}\n", FILE_APPEND);
    $conn->close();
    exit("fail: {$error_msg}");
}

$order = $result_check->fetch_assoc();
if (!$order) {
    file_put_contents('notify_log.txt', date('Y-m-d H:i:s') . " 订单不存在：{$out_trade_no}\n", FILE_APPEND);
    $conn->close();
    exit("fail: 订单不存在");
}

// 若订单已处理（status=1），直接返回success
if ($order['status'] == 1) {
    file_put_contents('notify_log.txt', date('Y-m-d H:i:s') . " 订单已处理：{$out_trade_no}\n", FILE_APPEND);
    $conn->close();
    exit("success");
}

// 8. 处理订单：更新状态、记录支付平台订单号和支付时间
$trade_no = $conn->real_escape_string($notify_params['trade_no']);
$money = $conn->real_escape_string($notify_params['money']);
$pay_time = date('Y-m-d H:i:s'); // 当前时间（格式：2025-09-30 20:00:00）

$sql_update = "UPDATE {$table_name} SET 
    status = 1, 
    trade_no = '{$trade_no}', 
    pay_time = '{$pay_time}', 
    actual_money = '{$money}' 
    WHERE out_trade_no = '{$out_trade_no}'";

if ($conn->query($sql_update)) {
    // 订单更新成功（可在此添加额外业务逻辑，如开通VIP、发送邮件等）
    file_put_contents('notify_log.txt', date('Y-m-d H:i:s') . " 订单处理成功：{$out_trade_no}\n", FILE_APPEND);
    $conn->close();
    exit("success"); // 必须返回success，支付平台停止重试
} else {
    $error_msg = "更新订单失败：{$conn->error}";
    file_put_contents('notify_log.txt', date('Y-m-d H:i:s') . " {$error_msg}\n", FILE_APPEND);
    $conn->close();
    exit("fail: {$error_msg}");
}
?>
