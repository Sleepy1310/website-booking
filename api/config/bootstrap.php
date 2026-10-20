<?php
/**
 * BOOTSTRAP - Khởi tạo session, helper function, kiểm tra đăng nhập
 * Include file này ĐẦU TIÊN trong mọi API endpoint (sau database.php).
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/authorization.php';

/**
 * Trả về JSON và kết thúc script
 */
function json_response(array $data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Yêu cầu đăng nhập — trả 401 nếu chưa login
 */
function require_login(): void {
    if (empty($_SESSION['user']['id'])) {
        json_response(['status' => 'error', 'message' => 'Bạn chưa đăng nhập!'], 401);
    }
}

/**
 * Yêu cầu quyền admin
 */
function require_admin(): void {
    require_login();
    global $conn;
    if (!$conn || !in_array(current_role($conn), ['super_admin', 'flight_manager', 'support', 'accountant'], true)) {
        json_response(['status' => 'error', 'message' => 'Bạn không có quyền truy cập!'], 403);
    }
}

/**
 * Sanitize string đầu vào
 */
function sanitize(string $str): string {
    return htmlspecialchars(trim($str), ENT_QUOTES, 'UTF-8');
}
