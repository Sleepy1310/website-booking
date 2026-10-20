<?php
/**
 * API: Đăng nhập
 * POST /api/auth/login.php
 * Body JSON: {"email": "admin@booking.com", "password": "123456"}
 */
require_once dirname(__FILE__, 2) . '/config/database.php';
require_once dirname(__FILE__, 2) . '/config/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['status' => 'error', 'message' => 'Method not allowed'], 405);
}
if (!$conn) {
    json_response(['status' => 'error', 'message' => 'Không thể kết nối cơ sở dữ liệu!'], 500);
}

$input    = json_decode(file_get_contents('php://input'), true) ?: $_POST;
$email    = strtolower(trim($input['email'] ?? ''));
$password = $input['password'] ?? '';

if (empty($email) || empty($password)) {
    json_response(['status' => 'error', 'message' => 'Vui lòng nhập đầy đủ email và mật khẩu!'], 422);
}

$stmt = mysqli_prepare($conn, "SELECT * FROM users WHERE email = ? LIMIT 1");
mysqli_stmt_bind_param($stmt, "s", $email);
mysqli_stmt_execute($stmt);
$res  = mysqli_stmt_get_result($stmt);
$user = mysqli_fetch_assoc($res);
mysqli_stmt_close($stmt);

if (!$user || !password_verify($password, $user['password'])) {
    json_response(['status' => 'error', 'message' => 'Email hoặc mật khẩu không chính xác!'], 401);
}
if (($user['status'] ?? 'Hoat_Dong') !== 'Hoat_Dong') {
    json_response(['status' => 'error', 'message' => 'Tài khoản đã bị khóa. Vui lòng liên hệ hỗ trợ!'], 403);
}
$role = normalize_role($user['role']);

$_SESSION['user'] = [
    'id'       => $user['id'],
    'email'    => $user['email'],
    'fullname' => $user['fullname'],
    'phone'    => $user['phone'],
    'role'     => $role,
];

json_response([
    'status'   => 'ok',
    'message'  => 'Đăng nhập thành công!',
    'user'     => [
        'id'       => $user['id'],
        'email'    => $user['email'],
        'fullname' => $user['fullname'],
        'role'     => $role,
    ],
    'redirect' => in_array($role, ['super_admin', 'flight_manager', 'support', 'accountant'], true)
        ? '/WebsiteDatVe/admin/admin.php'
        : '/WebsiteDatVe/index.php'
]);
