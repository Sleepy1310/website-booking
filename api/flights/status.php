<?php
/**
 * API: Trạng thái chuyến bay
 * GET /api/flights/status.php?flightNumber=VN210&date=2026-10-15
 */
require_once dirname(__FILE__, 2) . '/config/database.php';
require_once dirname(__FILE__, 2) . '/config/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(['status' => 'error', 'message' => 'Method not allowed'], 405);
}
if (!$conn) {
    json_response(['status' => 'error', 'message' => 'Không thể kết nối cơ sở dữ liệu!'], 500);
}

$flightNumber = strtoupper(trim($_GET['flightNumber'] ?? ''));
$date         = trim($_GET['date'] ?? '');
$fromCode     = strtoupper(trim($_GET['from'] ?? ''));
$toCode       = strtoupper(trim($_GET['to'] ?? ''));

$sql = "SELECT f.*, 
               dep.city AS from_city, dep.name AS from_airport_name,
               arr.city AS to_city, arr.name AS to_airport_name
        FROM flights f
        JOIN airports dep ON f.from_airport = dep.code
        JOIN airports arr ON f.to_airport = arr.code
        WHERE 1=1";
$params = [];
$types  = "";

if (!empty($flightNumber)) {
    $sql .= " AND REPLACE(f.flight_number, ' ', '') = REPLACE(?, ' ', '')";
    $params[] = $flightNumber; $types .= "s";
}
if (!empty($date)) {
    $sql .= " AND DATE(f.depart_at) = ?";
    $params[] = $date; $types .= "s";
}
if (!empty($fromCode)) { $sql .= " AND f.from_airport = ?"; $params[] = $fromCode; $types .= "s"; }
if (!empty($toCode))   { $sql .= " AND f.to_airport = ?";   $params[] = $toCode;   $types .= "s"; }

if ($flightNumber !== '') {
    $sql .= " ORDER BY f.depart_at ASC";
} else {
    $sql .= " ORDER BY ABS(TIMESTAMPDIFF(SECOND, f.depart_at, NOW())) ASC LIMIT 10";
}

if (!empty($params)) {
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, $types, ...$params);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
} else {
    $res = mysqli_query($conn, $sql);
}

$flights = [];
if ($res) {
    while ($row = mysqli_fetch_assoc($res)) {
        $depTs = strtotime($row['depart_at']);
        $arrTs = strtotime($row['arrive_at']);
        $durMin = max(30, round(($arrTs - $depTs) / 60));
        $row['depart_time']   = date('H:i', $depTs);
        $row['arrive_time']   = date('H:i', $arrTs);
        $row['depart_date']   = date('d/m/Y', $depTs);
        $row['duration_text'] = floor($durMin / 60) . 'h ' . str_pad($durMin % 60, 2, '0', STR_PAD_LEFT) . 'm';
        $row['status'] = flightStatusLabel($row['status']);
        $flights[] = $row;
    }
}

json_response(['status' => 'ok', 'count' => count($flights), 'data' => $flights]);
