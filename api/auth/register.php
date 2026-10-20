<?php
/**
 * API: Đăng ký tài khoản
 * POST /api/auth/register.php
 * Body JSON: {"fullname": "Nguyễn Văn B", "email": "b@example.com", "password": "123456", "phone": "0901234567"}
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
$fullname = trim($input['fullname'] ?? '');
$email    = strtolower(trim($input['email'] ?? ''));
$password = $input['password'] ?? '';
$phone    = trim($input['phone'] ?? '');

if (empty($fullname) || empty($email) || empty($password)) {
    json_response(['status' => 'error', 'message' => 'Vui lòng nhập đầy đủ họ tên, email và mật khẩu!'], 422);
}
if (strlen($password) < 6) {
    json_response(['status' => 'error', 'message' => 'Mật khẩu phải có ít nhất 6 ký tự!'], 422);
}

// Kiểm tra email trùng
$chk = mysqli_prepare($conn, "SELECT id FROM users WHERE email = ? LIMIT 1");
mysqli_stmt_bind_param($chk, "s", $email);
mysqli_stmt_execute($chk);
mysqli_stmt_store_result($chk);
$exists = mysqli_stmt_num_rows($chk) > 0;
mysqli_stmt_close($chk);

if ($exists) {
    json_response(['status' => 'error', 'message' => 'Email này đã được sử dụng!'], 409);
}

$hashed = password_hash($password, PASSWORD_BCRYPT);
$stmt   = mysqli_prepare($conn, "INSERT INTO users (fullname, email, password, phone, role) VALUES (?, ?, ?, ?, 'Khach_Hang')");
mysqli_stmt_bind_param($stmt, "ssss", $fullname, $email, $hashed, $phone);

if (!mysqli_stmt_execute($stmt)) {
    json_response(['status' => 'error', 'message' => 'Đăng ký thất bại, vui lòng thử lại!'], 500);
}

$newId = mysqli_insert_id($conn);
mysqli_stmt_close($stmt);

$_SESSION['user'] = [
    'id'       => $newId,
    'email'    => $email,
    'fullname' => $fullname,
    'phone'    => $phone,
    'role'     => 'customer',
];

json_response([
    'status'   => 'ok',
    'message'  => 'Đăng ký thành công!',
    'userId'   => $newId,
    'redirect' => '/WebsiteDatVe/index.php'
]);
