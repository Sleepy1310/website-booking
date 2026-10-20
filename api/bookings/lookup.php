<?php
/**
 * API: Tra cứu đơn đặt vé theo mã booking
 * GET /api/bookings/lookup.php?code=BK892104
 */
require_once dirname(__FILE__, 2) . '/config/database.php';
require_once dirname(__FILE__, 2) . '/config/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(['status' => 'error', 'message' => 'Method not allowed'], 405);
}
if (!$conn) {
    json_response(['status' => 'error', 'message' => 'Không thể kết nối cơ sở dữ liệu!'], 500);
}

$code = strtoupper(trim($_GET['code'] ?? ''));
if (empty($code)) {
    json_response(['status' => 'error', 'message' => 'Vui lòng nhập mã đặt chỗ!'], 422);
}

$stmt = mysqli_prepare($conn,
    "SELECT b.*, f.flight_number, f.airline, f.aircraft, f.from_airport, f.to_airport, f.depart_at, f.arrive_at, f.gate, f.status AS flight_status,
            dep.city AS from_city, dep.name AS from_airport_name,
            arr.city AS to_city, arr.name AS to_airport_name,
            p.method AS pay_method, p.status AS pay_status, p.paid_at, p.transaction_ref,
            f.price_economy, f.price_business,
            (f.seats_economy_left + f.seats_business_left) AS seats_left
     FROM bookings b
     JOIN flights f ON b.flight_id = f.id
     JOIN airports dep ON f.from_airport = dep.code
     JOIN airports arr ON f.to_airport = arr.code
     LEFT JOIN payments p ON b.id = p.booking_id
     WHERE b.booking_code = ?
     LIMIT 1"
);
mysqli_stmt_bind_param($stmt, "s", $code);
mysqli_stmt_execute($stmt);
$res     = mysqli_stmt_get_result($stmt);
$booking = mysqli_fetch_assoc($res);
mysqli_stmt_close($stmt);

if (!$booking) {
    json_response(['status' => 'error', 'message' => "Không tìm thấy mã đặt chỗ '{$code}'!"], 404);
}

// Lấy danh sách hành khách
$bId  = (int)$booking['id'];
$pRes = mysqli_query($conn,
    "SELECT p.*, fs.seat_code
     FROM passengers p
     LEFT JOIN flight_seats fs ON fs.id = p.flight_seat_id
     WHERE p.booking_id = {$bId}
     ORDER BY p.id ASC"
);
$passengers = [];
if ($pRes) {
    while ($row = mysqli_fetch_assoc($pRes)) {
        $row['checkin_status'] = (int)$row['checkin_status'] === 1 ? 'Đã check-in' : 'Chưa check-in';
        $passengers[] = $row;
    }
}
$booking['payment_status_label'] = paymentStatusLabel($booking['payment_status']);
$booking['booking_status_label'] = bookingStatusLabel($booking['booking_status']);
$booking['flight_status_label'] = flightStatusLabel($booking['flight_status']);
$booking['pay_status_label'] = paymentStatusLabel($booking['pay_status'] ?? '');
$booking['pay_method_label'] = paymentMethodLabel($booking['pay_method'] ?? '');

json_response([
    'status'     => 'ok',
    'booking'    => $booking,
    'passengers' => $passengers
]);
