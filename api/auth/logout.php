<?php
/**
 * API: Đăng xuất
 * POST /api/auth/logout.php
 */
require_once dirname(__FILE__, 2) . '/config/bootstrap.php';

session_destroy();
json_response(['status' => 'ok', 'message' => 'Đăng xuất thành công!', 'redirect' => '/WebsiteDatVe/login/login.php']);
