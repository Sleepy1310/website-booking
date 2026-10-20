<?php
/**
 * API: Kiểm tra mã giảm giá
 * POST /api/promotions/check.php
 * Body JSON: {"code": "WELCOME10", "orderAmount": 1670000}
 */
require_once dirname(__FILE__, 2) . '/config/database.php';
require_once dirname(__FILE__, 2) . '/config/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['status' => 'error', 'message' => 'Method not allowed'], 405);
}
if (!$conn) {
    json_response(['status' => 'error', 'message' => 'Không thể kết nối cơ sở dữ liệu!'], 500);
}

$input       = json_decode(file_get_contents('php://input'), true) ?: $_POST;
$code        = strtoupper(trim($input['code'] ?? ''));
$orderAmount = (int)($input['orderAmount'] ?? 0);

if (empty($code)) {
    json_response(['status' => 'error', 'message' => 'Vui lòng nhập mã giảm giá!'], 422);
}

$stmt = mysqli_prepare($conn,
    "SELECT * FROM promotions 
     WHERE code = ? AND is_active = 1 AND CURDATE() BETWEEN start_date AND end_date
     AND used_count < usage_limit
     LIMIT 1"
);
mysqli_stmt_bind_param($stmt, "s", $code);
mysqli_stmt_execute($stmt);
$res   = mysqli_stmt_get_result($stmt);
$promo = mysqli_fetch_assoc($res);
mysqli_stmt_close($stmt);

if (!$promo) {
    json_response(['status' => 'error', 'message' => 'Mã giảm giá không hợp lệ hoặc đã hết hạn!'], 400);
}

if ($orderAmount < (int)$promo['min_order_value']) {
    json_response([
        'status'  => 'error',
        'message' => 'Đơn hàng chưa đạt giá trị tối thiểu ' . number_format($promo['min_order_value']) . 'đ!'
    ], 400);
}

// Tính giảm giá
$discount = 0;
if ($promo['discount_type'] === 'percentage') {
    $discount = (int)round($orderAmount * $promo['discount_value'] / 100);
    if ($promo['max_discount']) {
        $discount = min($discount, (int)$promo['max_discount']);
    }
} else {
    $discount = (int)$promo['discount_value'];
}

json_response([
    'status'        => 'ok',
    'promoId'       => (int)$promo['id'],
    'code'          => $promo['code'],
    'description'   => $promo['description'],
    'discountType'  => $promo['discount_type'] === 'percentage' ? 'percent' : 'fixed',
    'discountValue' => (int)$promo['discount_value'],
    'discountAmount'=> $discount,
    'message'       => 'Áp dụng mã thành công! Giảm ' . number_format($discount) . 'đ'
]);
