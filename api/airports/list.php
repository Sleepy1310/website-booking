<?php
/**
 * API: Lấy danh sách sân bay
 * GET /api/airports/list.php
 */
require_once dirname(__FILE__, 2) . '/config/database.php';
require_once dirname(__FILE__, 2) . '/config/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(['status' => 'error', 'message' => 'Method not allowed'], 405);
}

if (!$conn) {
    json_response(['status' => 'error', 'message' => 'Không thể kết nối cơ sở dữ liệu!'], 500);
}

$airports = getAirports($conn);
json_response(['status' => 'ok', 'data' => $airports]);
