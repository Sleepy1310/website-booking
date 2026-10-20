<?php
/**
 * API: Tìm kiếm chuyến bay 1 chiều
 * GET /api/flights/search.php?from=SGN&to=HAN&date=2026-10-20&passengers=2
 */
require_once dirname(__FILE__, 2) . '/config/database.php';
require_once dirname(__FILE__, 2) . '/config/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(['status' => 'error', 'message' => 'Method not allowed'], 405);
}

if (!$conn) {
    json_response(['status' => 'error', 'message' => 'Không thể kết nối cơ sở dữ liệu!'], 500);
}

$from       = strtoupper(trim($_GET['from'] ?? ''));
$to         = strtoupper(trim($_GET['to'] ?? ''));
$date       = trim($_GET['date'] ?? date('Y-m-d'));
$passengers = max(1, (int)($_GET['passengers'] ?? 1));
$cabin = strtoupper(trim($_GET['cabin'] ?? 'ECONOMY')) === 'BUSINESS' ? 'BUSINESS' : 'ECONOMY';
$priceColumn = $cabin === 'BUSINESS' ? 'price_business' : 'price_economy';
$seatsColumn = $cabin === 'BUSINESS' ? 'seats_business_left' : 'seats_economy_left';

// Trích xuất mã 3 chữ nếu người dùng gửi kèm tên thành phố (vd: "Hà Nội (HAN)")
if (preg_match('/\b([A-Z]{3})\b/', $from, $m)) $from = $m[1];
if (preg_match('/\b([A-Z]{3})\b/', $to, $m))   $to   = $m[1];

$sql = "SELECT f.*,
               f.{$priceColumn} AS price,
               f.{$seatsColumn} AS seats_left,
               '{$cabin}' AS cabin_class,
               dep.city AS from_city, dep.name AS from_airport_name,
               arr.city AS to_city, arr.name AS to_airport_name
        FROM flights f
        JOIN airports dep ON f.from_airport = dep.code
        JOIN airports arr ON f.to_airport = arr.code
        WHERE f.{$seatsColumn} >= ?
          AND f.status <> 'cancelled'";
$params = [$passengers];
$types  = "i";

if (!empty($from)) { $sql .= " AND f.from_airport = ?"; $params[] = $from; $types .= "s"; }
if (!empty($to))   { $sql .= " AND f.to_airport = ?";   $params[] = $to;   $types .= "s"; }
if (!empty($date)) { $sql .= " AND DATE(f.depart_at) = ?"; $params[] = $date; $types .= "s"; }

$sql .= " ORDER BY f.{$priceColumn} ASC, f.depart_at ASC";

$stmt = mysqli_prepare($conn, $sql);
if (!$stmt) {
    json_response(['status' => 'error', 'message' => mysqli_error($conn)], 500);
}
mysqli_stmt_bind_param($stmt, $types, ...$params);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);

$flights = [];
while ($row = mysqli_fetch_assoc($res)) {
    $depTs    = strtotime($row['depart_at']);
    $arrTs    = strtotime($row['arrive_at']);
    $durMin   = max(30, round(($arrTs - $depTs) / 60));
    $row['duration_text'] = floor($durMin / 60) . 'h ' . str_pad($durMin % 60, 2, '0', STR_PAD_LEFT) . 'm';
    $row['depart_time']   = date('H:i', $depTs);
    $row['arrive_time']   = date('H:i', $arrTs);
    $row['depart_date']   = date('d/m/Y', $depTs);
    $row['price']         = (int)$row['price'];
    $row['tax_price']     = (int)$row['tax_price'];
    $row['seats_left']    = (int)$row['seats_left'];
    $row['status']        = flightStatusLabel($row['status']);
    $flights[] = $row;
}
mysqli_stmt_close($stmt);

json_response(['status' => 'ok', 'count' => count($flights), 'data' => $flights]);
