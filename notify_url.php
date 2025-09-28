<?php
/**
 * 支付异步通知回调接口
 * 功能：校验通知合法性、处理订单状态、返回结果给支付平台
 */

// -------------------------- 基础配置（需与Python支付请求一致）--------------------------
$merchant_key = "TXz060E3LFXqtF650E0007aE70f6V70p"; // 商户密钥（和Python代码中一致）
$merchant_id = 1405; // 商户ID（和Python代码中一致）
// -----------------------------------------------------------------------------------

// 1. 接收支付平台的POST通知参数
$notify_params = $_POST;
// 日志记录（可选，用于调试，建议开启）
file_put_contents('notify_log.txt', date('Y-m-d H:i:s') . " 接收到通知：" . json_encode($notify_params) . "\n", FILE_APPEND);

// 2. 校验参数是否完整（至少包含核心字段）
$required_fields = ['pid', 'out_trade_no', 'trade_no', 'money', 'trade_status', 'sign'];
foreach ($required_fields as $field) {
    if (empty($notify_params[$field])) {
        exit("fail: 参数缺失（{$field}）"); // 返回fail，支付平台会重试通知
    }
}

// 3. 校验商户ID是否匹配（防止非法通知）
if ($notify_params['pid'] != $merchant_id) {
    file_put_contents('notify_log.txt', date('Y-m-d H:i:s') . " 商户ID不匹配：" . $notify_params['pid'] . "\n", FILE_APPEND);
    exit("fail: 商户ID错误");
}

// 4. 校验支付状态（仅处理“支付成功”的通知）
if ($notify_params['trade_status'] != 'TRADE_SUCCESS') {
    file_put_contents('notify_log.txt', date('Y-m-d H:i:s') . " 支付状态非成功：" . $notify_params['trade_status'] . "\n", FILE_APPEND);
    exit("success"); // 非成功状态返回success，避免支付平台重复通知
}

// 5. 校验签名（核心！防止伪造通知）
$sign = $notify_params['sign']; // 支付平台返回的签名
unset($notify_params['sign']); // 排除sign字段，用于重新计算签名
unset($notify_params['sign_type']); // 排除sign_type字段（若存在）

// 5.1 按参数名ASCII升序排序（和Python签名逻辑一致）
ksort($notify_params);

// 5.2 拼接签名字符串（格式：key=value&key=value&...&key=商户密钥）
$sign_str = '';
foreach ($notify_params as $key => $value) {
    $sign_str .= "{$key}={$value}&";
}
$sign_str .= "key={$merchant_key}";

// 5.3 计算MD5签名（小写，和Python一致）
$local_sign = md5($sign_str);

// 5.4 对比签名是否一致
if ($local_sign != $sign) {
    file_put_contents('notify_log.txt', date('Y-m-d H:i:s') . " 签名校验失败！本地签名：{$local_sign}，平台签名：{$sign}，签名字符串：{$sign_str}\n", FILE_APPEND);
    exit("fail: 签名错误");
}

// 6. 防重复通知处理（核心！避免同一订单重复处理）
// 思路：查询数据库中该订单的状态，若已处理则直接返回success
$out_trade_no = $notify_params['out_trade_no']; // 商户订单号
// 示例：假设使用MySQL数据库（需替换为你的数据库逻辑）
$db_host = 'localhost';
$db_user = 'root';
$db_pwd = '你的数据库密码';
$db_name = '你的数据库名';

// 连接数据库
$conn = mysqli_connect($db_host, $db_user, $db_pwd, $db_name);
if (!$conn) {
    file_put_contents('notify_log.txt', date('Y-m-d H:i:s') . " 数据库连接失败：" . mysqli_connect_error() . "\n", FILE_APPEND);
    exit("fail: 数据库错误");
}

// 查询订单当前状态（假设订单表名为order，状态字段为status：0=未支付，1=已支付）
$sql = "SELECT status FROM `order` WHERE out_trade_no = '{$out_trade_no}' LIMIT 1";
$result = mysqli_query($conn, $sql);
$order = mysqli_fetch_assoc($result);

if (!$order) {
    file_put_contents('notify_log.txt', date('Y-m-d H:i:s') . " 订单不存在：{$out_trade_no}\n", FILE_APPEND);
    exit("fail: 订单不存在");
}

// 若订单已处理（status=1），直接返回success
if ($order['status'] == 1) {
    file_put_contents('notify_log.txt', date('Y-m-d H:i:s') . " 订单已处理：{$out_trade_no}\n", FILE_APPEND);
    mysqli_close($conn);
    exit("success"); // 必须返回success，支付平台才会停止重试
}

// 7. 处理订单逻辑（核心业务：更新订单状态、给用户开通权限等）
$trade_no = $notify_params['trade_no']; // 支付平台订单号
$money = $notify_params['money']; // 实际支付金额（需校验是否和订单金额一致）

// 7.1 校验支付金额（防止金额篡改，可选但建议做）
$sql_check_money = "SELECT money FROM `order` WHERE out_trade_no = '{$out_trade_no}' LIMIT 1";
$result_money = mysqli_query($conn, $sql_check_money);
$order_money = mysqli_fetch_assoc($result_money)['money'];
if (number_format($money, 2) != number_format($order_money, 2)) {
    file_put_contents('notify_log.txt', date('Y-m-d H:i:s') . " 金额不匹配：订单金额{$order_money}，支付金额{$money}\n", FILE_APPEND);
    mysqli_close($conn);
    exit("fail: 金额不匹配");
}

// 7.2 更新订单状态（示例：标记为已支付，并记录支付平台订单号）
$sql_update = "UPDATE `order` SET 
    status = 1, 
    trade_no = '{$trade_no}', 
    pay_time = '" . date('Y-m-d H:i:s') . "',
    actual_money = '{$money}' 
    WHERE out_trade_no = '{$out_trade_no}'";

if (mysqli_query($conn, $sql_update)) {
    // 订单更新成功后，可执行其他业务（如给用户开通VIP、发送邮件通知等）
    // 示例：调用开通VIP的函数（需根据你的业务实现）
    // open_vip($out_trade_no); 
    
    file_put_contents('notify_log.txt', date('Y-m-d H:i:s') . " 订单处理成功：{$out_trade_no}\n", FILE_APPEND);
    mysqli_close($conn);
    exit("success"); // 必须返回success，支付平台停止重试
} else {
    file_put_contents('notify_log.txt', date('Y-m-d H:i:s') . " 订单更新失败：" . mysqli_error($conn) . "\n", FILE_APPEND);
    mysqli_close($conn);
    exit("fail: 订单更新失败");
}
?>
