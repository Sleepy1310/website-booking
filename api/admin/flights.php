<?php
/**
 * API ADMIN: Quản lý chuyến bay
 * GET  /api/admin/flights.php          — Danh sách chuyến bay
 * POST /api/admin/flights.php          — Thêm chuyến bay mới
 * PUT  /api/admin/flights.php?id=N     — Cập nhật chuyến bay
 * DELETE /api/admin/flights.php?id=N  — Xóa chuyến bay
 */
require_once dirname(__FILE__, 2) . '/config/database.php';
require_once dirname(__FILE__, 2) . '/config/bootstrap.php';
require_once dirname(__FILE__, 2) . '/config/aircraft_seats.php';
require_admin();

if (!$conn) {
    json_response(['status' => 'error', 'message' => 'Không thể kết nối cơ sở dữ liệu!'], 500);
}

$method = $_SERVER['REQUEST_METHOD'];
$id     = (int)($_GET['id'] ?? 0);

// --- GET: Danh sách chuyến bay ---
if ($method === 'GET') {
    require_permission($conn, 'flights_view');
    $sql = "SELECT f.*, dep.city AS from_city, arr.city AS to_city
            FROM flights f
            JOIN airports dep ON f.from_airport = dep.code
            JOIN airports arr ON f.to_airport = arr.code
            ORDER BY f.depart_at ASC";
    $res = mysqli_query($conn, $sql);
    $flights = [];
    while ($row = mysqli_fetch_assoc($res)) {
        $flights[] = $row;
    }
    json_response(['status' => 'ok', 'data' => $flights]);
}

// --- POST: Thêm chuyến bay ---
if ($method === 'POST') {
    require_permission($conn, 'flight_create');
    $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $fn       = trim($input['flight_number'] ?? '');
    $airline  = trim($input['airline'] ?? '');
    $aircraft = trim($input['aircraft'] ?? 'Airbus A321');
    $fromAp   = strtoupper(trim($input['from_airport'] ?? ''));
    $toAp     = strtoupper(trim($input['to_airport'] ?? ''));
    $depAt    = trim($input['depart_at'] ?? '');
    $arrAt    = trim($input['arrive_at'] ?? '');
    $priceEconomy = (int)($input['price_economy'] ?? 990000);
    $priceBusiness = (int)($input['price_business'] ?? 0);
    $tax      = (int)($input['tax_price'] ?? 420000);
    $seatPreset = aircraft_seat_row_preset($aircraft);
    $economyRows = $seatPreset['economy_rows'];
    $businessRows = $seatPreset['business_rows'];
    $gate     = trim($input['gate'] ?? 'Gate 04');

    if (empty($fn) || empty($airline) || empty($fromAp) || empty($toAp) || empty($depAt) || empty($arrAt)) {
        json_response(['status' => 'error', 'message' => 'Thiếu thông tin bắt buộc!'], 422);
    }

    $stmt = mysqli_prepare($conn,
        "INSERT INTO flights (flight_number, airline, aircraft, from_airport, to_airport, depart_at, arrive_at, stops,
                             price_economy, price_business, tax_price, seats_economy_left, seats_business_left, status, gate)
         VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?, ?, ?, 'scheduled', ?)"
    );
    $economySeats = $economyRows * 6;
    $businessSeats = $businessRows * 6;
    mysqli_stmt_bind_param($stmt, "sssssssiiiiis", $fn, $airline, $aircraft, $fromAp, $toAp, $depAt, $arrAt, $priceEconomy, $priceBusiness, $tax, $economySeats, $businessSeats, $gate);
    if (!mysqli_stmt_execute($stmt)) {
        json_response(['status' => 'error', 'message' => mysqli_error($conn)], 500);
    }
    $newId = mysqli_insert_id($conn);
    mysqli_stmt_close($stmt);
    $seatStmt = mysqli_prepare($conn, 'CALL sp_generate_seats(?, ?, ?)');
    if (!$seatStmt) {
        json_response(['status' => 'error', 'message' => 'Đã tạo chuyến bay nhưng không thể chuẩn bị sơ đồ ghế: ' . mysqli_error($conn)], 500);
    }
    mysqli_stmt_bind_param($seatStmt, 'iii', $newId, $businessRows, $economyRows);
    if (!mysqli_stmt_execute($seatStmt)) {
        json_response(['status' => 'error', 'message' => 'Đã tạo chuyến bay nhưng không thể tạo sơ đồ ghế: ' . mysqli_stmt_error($seatStmt)], 500);
    }
    mysqli_stmt_close($seatStmt);
    while (mysqli_more_results($conn) && mysqli_next_result($conn)) {
        $result = mysqli_store_result($conn);
        if ($result instanceof mysqli_result) {
            mysqli_free_result($result);
        }
    }
    json_response(['status' => 'ok', 'message' => 'Thêm chuyến bay thành công!', 'id' => $newId]);
}

// --- PUT: Cập nhật status chuyến bay ---
if ($method === 'PUT') {
    require_permission($conn, 'flight_status_manage');
    if ($id <= 0) json_response(['status' => 'error', 'message' => 'ID không hợp lệ!'], 422);
    $input  = json_decode(file_get_contents('php://input'), true) ?: [];
    $status = $input['status'] ?? '';
    $gate   = $input['gate'] ?? '';
    $allowed = ['scheduled', 'delayed', 'departed', 'landed', 'cancelled'];
    if (!empty($status) && !in_array($status, $allowed)) {
        json_response(['status' => 'error', 'message' => 'Trạng thái không hợp lệ!'], 422);
    }
    $sets = [];
    if (!empty($status)) $sets[] = "status = '" . mysqli_real_escape_string($conn, $status) . "'";
    if (!empty($gate))   $sets[] = "gate = '"   . mysqli_real_escape_string($conn, $gate)   . "'";
    if (empty($sets)) json_response(['status' => 'error', 'message' => 'Không có trường nào để cập nhật!'], 422);

    mysqli_query($conn, "UPDATE flights SET " . implode(', ', $sets) . " WHERE id = {$id}");
    json_response(['status' => 'ok', 'message' => 'Cập nhật thành công!']);
}

// --- DELETE: Xóa chuyến bay ---
if ($method === 'DELETE') {
    require_permission($conn, 'flight_delete');
    if ($id <= 0) json_response(['status' => 'error', 'message' => 'ID không hợp lệ!'], 422);
    mysqli_query($conn, "DELETE FROM flights WHERE id = {$id}");
    json_response(['status' => 'ok', 'message' => 'Đã xóa chuyến bay #' . $id]);
}

json_response(['status' => 'error', 'message' => 'Method not allowed'], 405);
