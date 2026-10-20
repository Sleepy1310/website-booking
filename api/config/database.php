<?php
// CẤU HÌNH KẾT NỐI CƠ SỞ DỮ LIỆU WEBSITEDATVE (MySQL 8.0 / MariaDB)
$host = "localhost";
$user = "root";
$pass = "";
$dbname = "websitedatve";

// 1. Kết nối MySQLi
try {
    $conn = mysqli_connect($host, $user, $pass, $dbname);
} catch (mysqli_sql_exception $e) {
    error_log('MySQL connection failed: ' . $e->getMessage());
    $conn = false;
}

// 2. Kết nối PDO (dùng cho Transaction an toàn, Prepared Statement)
$pdo = null;
if ($conn) {
    try {
        $pdo = new PDO("mysql:host={$host};dbname={$dbname};charset=utf8mb4", $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    } catch (PDOException $e) {
        // Không ném lỗi ngay để trang web vẫn có thể fallback
        $pdoError = $e->getMessage();
    }
}

if (!$conn) {
    $isApi = (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false) 
             || (isset($_SERVER['REQUEST_URI']) && strpos($_SERVER['REQUEST_URI'], '/api/') !== false);
    
    if ($isApi) {
        http_response_code(503);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'status' => 'error',
            'message' => 'Chưa kết nối được CSDL websitedatve. Vui lòng mở MySQL trên XAMPP!'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
} else {
    mysqli_set_charset($conn, "utf8mb4");
}

/**
 * Tìm mã sân bay từ chuỗi người dùng nhập (SGN, "Hà Nội (HAN)", "Đà Lạt"...)
 */
function resolveAirportCode($conn, $input, $fallback = '') {
    $input = trim((string)$input);
    if ($input === '') {
        return $fallback;
    }
    if (preg_match('/\b([A-Z]{3})\b/', strtoupper($input), $m)) {
        return $m[1];
    }
    if (!$conn) {
        return $fallback;
    }
    $like = '%' . $input . '%';
    $stmt = mysqli_prepare($conn, "SELECT code FROM airports WHERE city LIKE ? OR name LIKE ? OR code LIKE ? LIMIT 1");
    if (!$stmt) {
        return $fallback;
    }
    mysqli_stmt_bind_param($stmt, 'sss', $like, $like, $like);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $row = $res ? mysqli_fetch_assoc($res) : null;
    mysqli_stmt_close($stmt);
    return $row['code'] ?? $fallback;
}

function airportLabel($airports, $code) {
    foreach ($airports as $ap) {
        if (($ap['code'] ?? '') === $code) {
            return $ap['city'] . ' (' . $ap['code'] . ')';
        }
    }
    return $code;
}

function formatVnWeekday($ymd) {
    $ts = strtotime($ymd);
    if (!$ts) {
        return $ymd;
    }
    $days = ['Chủ nhật', 'Thứ 2', 'Thứ 3', 'Thứ 4', 'Thứ 5', 'Thứ 6', 'Thứ 7'];
    return $days[(int)date('w', $ts)];
}

function expirePendingBookings(mysqli $conn): void {
    if (!mysqli_begin_transaction($conn)) {
        throw new RuntimeException('Không thể bắt đầu xử lý đơn giữ chỗ hết hạn: ' . mysqli_error($conn));
    }

    try {
        $expired = mysqli_query($conn,
            "SELECT id, flight_id, num_passengers
             FROM bookings
             WHERE payment_status = 'unpaid'
               AND booking_status = 'pending'
               AND expires_at <= NOW()
             ORDER BY id
             FOR UPDATE"
        );
        if (!$expired) {
            throw new RuntimeException('Không thể tìm đơn giữ chỗ hết hạn: ' . mysqli_error($conn));
        }

        $bookingStmt = mysqli_prepare($conn,
            "UPDATE bookings
             SET booking_status = 'cancelled'
             WHERE id = ? AND payment_status = 'unpaid'
               AND booking_status = 'pending' AND expires_at <= NOW()"
        );
        $seatClassStmt = mysqli_prepare($conn,
            "SELECT seat_class FROM passengers WHERE booking_id = ? ORDER BY id LIMIT 1"
        );
        $passengerStmt = mysqli_prepare($conn,
            "UPDATE passengers SET flight_seat_id = NULL, seat_price = 0 WHERE booking_id = ?"
        );
        $flightStmt = mysqli_prepare($conn,
            "UPDATE flights
             SET seats_economy_left = seats_economy_left + ?,
                 seats_business_left = seats_business_left + ?
             WHERE id = ?"
        );
        $paymentStmt = mysqli_prepare($conn,
            "UPDATE payments SET status = 'failed'
             WHERE booking_id = ? AND status = 'pending'"
        );
        $releaseSeatsStmt = mysqli_prepare($conn,
            "UPDATE flight_seats fs
             JOIN passengers p ON p.flight_seat_id = fs.id
             SET fs.is_booked = 0
             WHERE p.booking_id = ? AND fs.is_booked = 1"
        );
        if (!$bookingStmt || !$seatClassStmt || !$passengerStmt || !$flightStmt || !$paymentStmt || !$releaseSeatsStmt) {
            throw new RuntimeException('Không thể chuẩn bị xử lý đơn giữ chỗ hết hạn: ' . mysqli_error($conn));
        }

        while ($booking = mysqli_fetch_assoc($expired)) {
            $bookingId = (int)$booking['id'];
            $flightId = (int)$booking['flight_id'];
            $passengerCount = (int)$booking['num_passengers'];

            mysqli_stmt_bind_param($bookingStmt, 'i', $bookingId);
            if (!mysqli_stmt_execute($bookingStmt)) {
                throw new RuntimeException('Không thể hủy đơn hết hạn: ' . mysqli_stmt_error($bookingStmt));
            }
            if (mysqli_stmt_affected_rows($bookingStmt) !== 1) {
                continue;
            }

            mysqli_stmt_bind_param($seatClassStmt, 'i', $bookingId);
            if (!mysqli_stmt_execute($seatClassStmt)) {
                throw new RuntimeException('Không thể xác định hạng vé của đơn hết hạn: ' . mysqli_stmt_error($seatClassStmt));
            }
            $passenger = mysqli_fetch_assoc(mysqli_stmt_get_result($seatClassStmt));
            if (!$passenger || !in_array($passenger['seat_class'], ['ECONOMY', 'BUSINESS'], true)) {
                throw new RuntimeException("Không thể xác định hạng ghế của đơn hết hạn #{$bookingId}.");
            }
            $economyCount = $passenger['seat_class'] === 'ECONOMY' ? $passengerCount : 0;
            $businessCount = $passenger['seat_class'] === 'BUSINESS' ? $passengerCount : 0;

            mysqli_stmt_bind_param($releaseSeatsStmt, 'i', $bookingId);
            if (!mysqli_stmt_execute($releaseSeatsStmt)) {
                throw new RuntimeException('Không thể nhả ghế của đơn hết hạn: ' . mysqli_stmt_error($releaseSeatsStmt));
            }

            mysqli_stmt_bind_param($passengerStmt, 'i', $bookingId);
            if (!mysqli_stmt_execute($passengerStmt)) {
                throw new RuntimeException('Không thể xóa liên kết ghế của đơn hết hạn: ' . mysqli_stmt_error($passengerStmt));
            }

            mysqli_stmt_bind_param($flightStmt, 'iii', $economyCount, $businessCount, $flightId);
            if (!mysqli_stmt_execute($flightStmt)) {
                throw new RuntimeException('Không thể hoàn lại chỗ của chuyến bay: ' . mysqli_stmt_error($flightStmt));
            }

            mysqli_stmt_bind_param($paymentStmt, 'i', $bookingId);
            if (!mysqli_stmt_execute($paymentStmt)) {
                throw new RuntimeException('Không thể cập nhật thanh toán của đơn hết hạn: ' . mysqli_stmt_error($paymentStmt));
            }
        }

        mysqli_stmt_close($bookingStmt);
        mysqli_stmt_close($seatClassStmt);
        mysqli_stmt_close($passengerStmt);
        mysqli_stmt_close($flightStmt);
        mysqli_stmt_close($paymentStmt);
        mysqli_stmt_close($releaseSeatsStmt);
        mysqli_free_result($expired);

        if (!mysqli_commit($conn)) {
            throw new RuntimeException('Không thể hoàn tất xử lý đơn giữ chỗ hết hạn: ' . mysqli_error($conn));
        }
    } catch (Throwable $e) {
        mysqli_rollback($conn);
        throw $e;
    }
}

if ($conn) {
    expirePendingBookings($conn);
}

function flightStatusLabel(string $status): string
{
    return [
        'scheduled' => 'Đúng giờ',
        'delayed' => 'Chậm chuyến',
        'departed' => 'Đang boarding',
        'landed' => 'Đã hạ cánh',
        'cancelled' => 'Đã hủy',
    ][$status] ?? $status;
}

function bookingStatusLabel(string $status): string
{
    return [
        'pending' => 'Giữ chỗ',
        'confirmed' => 'Đã xác nhận',
        'cancelled' => 'Đã hủy',
    ][$status] ?? $status;
}

function paymentStatusLabel(string $status): string
{
    return [
        'unpaid' => 'Chưa thanh toán',
        'paid' => 'Đã thanh toán',
        'refunded' => 'Đã hoàn tiền',
        'pending' => 'Chờ xử lý',
        'success' => 'Thành công',
        'failed' => 'Thất bại',
    ][$status] ?? $status;
}

function paymentMethodLabel(string $method): string
{
    return [
        'vnpay' => 'Thẻ / VNPay',
        'momo' => 'Ví MoMo',
        'bank_transfer' => 'Chuyển khoản / VietQR',
        'cod' => 'Thanh toán khi nhận',
    ][$method] ?? $method;
}

/**
 * Helper: Lấy danh sách tất cả sân bay
 */
function getAirports($conn) {
    $airports = [];
    if (!$conn) return $airports;
    $res = mysqli_query($conn, "SELECT * FROM airports ORDER BY city ASC");
    if ($res) {
        while ($row = mysqli_fetch_assoc($res)) {
            $airports[] = $row;
        }
    }
    return $airports;
}

/**
 * Helper: Tìm chuyến bay theo tiêu chí 1 chiều
 */
function searchFlights($conn, $from = '', $to = '', $date = '', $passengers = 1) {
    $flights = [];
    if (!$conn) return $flights;

    // Làm sạch đầu vào mã sân bay hoặc tên thành phố
    $fromCode = '';
    $toCode = '';
    if (preg_match('/\b([A-Z]{3})\b/', $from, $m)) $fromCode = $m[1];
    if (preg_match('/\b([A-Z]{3})\b/', $to, $m)) $toCode = $m[1];

    $sql = "SELECT f.*,
                   f.price_economy AS price,
                   (f.seats_economy_left + f.seats_business_left) AS seats_left,
                   dep.city AS from_city, dep.name AS from_airport_name,
                   arr.city AS to_city, arr.name AS to_airport_name
            FROM flights f
            JOIN airports dep ON f.from_airport = dep.code
            JOIN airports arr ON f.to_airport = arr.code
            WHERE (f.seats_economy_left + f.seats_business_left) >= ?
              AND f.status <> 'cancelled'";
    $params = [(int)$passengers];
    $types = "i";

    if (!empty($fromCode)) {
        $sql .= " AND f.from_airport = ?";
        $params[] = $fromCode;
        $types .= "s";
    }
    if (!empty($toCode)) {
        $sql .= " AND f.to_airport = ?";
        $params[] = $toCode;
        $types .= "s";
    }
    if (!empty($date)) {
        $sql .= " AND DATE(f.depart_at) = ?";
        $params[] = $date;
        $types .= "s";
    }

    $sql .= " ORDER BY f.price_economy ASC, f.depart_at ASC";

    $stmt = mysqli_prepare($conn, $sql);
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, $types, ...$params);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        while ($row = mysqli_fetch_assoc($res)) {
            $flights[] = $row;
        }
        mysqli_stmt_close($stmt);
    }
    return $flights;
}

?>
