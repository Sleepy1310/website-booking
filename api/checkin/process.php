<?php
/**
 * API: Check-in trực tuyến
 * POST /api/checkin/process.php
 * Body JSON: {"bookingCode":"BK892104","lastName":"NGUYEN","seats":[{"passengerId":1,"seatCode":"14A"}]}
 */
require_once dirname(__FILE__, 2) . '/config/database.php';
require_once dirname(__FILE__, 2) . '/config/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['status' => 'error', 'message' => 'Method not allowed'], 405);
}
if (!$conn) {
    json_response(['status' => 'error', 'message' => 'Không thể kết nối cơ sở dữ liệu!'], 500);
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = $_POST;
}
$bookingCode = strtoupper(trim((string)($input['bookingCode'] ?? '')));
$lastName = preg_replace('/\s+/u', ' ', trim((string)($input['lastName'] ?? '')));
$seatAssignments = $input['seats'] ?? [];
$addonSelections = $input['addons'] ?? [];
$addonsPaid = ($input['addonsPaid'] ?? false) === true;
$paymentMethod = (string)($input['paymentMethod'] ?? 'VietQR');

if (!preg_match('/^BK[A-F0-9]{6,8}$/', $bookingCode) || $lastName === '') {
    json_response(['status' => 'error', 'message' => 'Vui lòng nhập mã đặt chỗ và họ hành khách hợp lệ!'], 422);
}
if (!is_array($seatAssignments)) {
    json_response(['status' => 'error', 'message' => 'Danh sách ghế không hợp lệ.'], 422);
}
if (!is_array($addonSelections)) {
    json_response(['status' => 'error', 'message' => 'Lựa chọn dịch vụ không hợp lệ.'], 422);
}
if (!in_array($paymentMethod, ['VietQR', 'Thẻ', 'Ví điện tử'], true)) {
    json_response(['status' => 'error', 'message' => 'Phương thức thanh toán không hợp lệ.'], 422);
}

$bookingStmt = mysqli_prepare($conn,
    "SELECT b.id, b.booking_code, b.flight_id, b.booking_status, b.payment_status,
            f.status AS flight_status
     FROM bookings b
     JOIN flights f ON b.flight_id = f.id
     WHERE b.booking_code = ? LIMIT 1"
);
mysqli_stmt_bind_param($bookingStmt, 's', $bookingCode);
if (!mysqli_stmt_execute($bookingStmt)) {
    json_response(['status' => 'error', 'message' => 'Không thể tra cứu đặt chỗ.'], 500);
}
$booking = mysqli_fetch_assoc(mysqli_stmt_get_result($bookingStmt));
mysqli_stmt_close($bookingStmt);

if (!$booking) {
    json_response(['status' => 'error', 'message' => 'Không tìm thấy mã đặt chỗ!'], 404);
}
if ($booking['payment_status'] !== 'paid' || $booking['booking_status'] !== 'confirmed') {
    json_response(['status' => 'error', 'message' => 'Vé chưa thanh toán hoặc đặt chỗ chưa được xác nhận!'], 400);
}
if ($booking['flight_status'] === 'cancelled') {
    json_response(['status' => 'error', 'message' => 'Chuyến bay đã bị hủy!'], 400);
}

$bookingId = (int)$booking['id'];
$normalizedLastName = function_exists('mb_strtoupper') ? mb_strtoupper($lastName, 'UTF-8') : strtoupper($lastName);
$nameStmt = mysqli_prepare($conn,
    'SELECT id FROM passengers WHERE booking_id = ? AND UPPER(TRIM(last_name)) = ? LIMIT 1'
);
mysqli_stmt_bind_param($nameStmt, 'is', $bookingId, $normalizedLastName);
if (!mysqli_stmt_execute($nameStmt)) {
    json_response(['status' => 'error', 'message' => 'Không thể xác minh họ hành khách.'], 500);
}
$nameMatch = mysqli_fetch_assoc(mysqli_stmt_get_result($nameStmt));
mysqli_stmt_close($nameStmt);
if (!$nameMatch) {
    json_response(['status' => 'error', 'message' => 'Họ hành khách không khớp với mã đặt chỗ!'], 400);
}

if (!mysqli_begin_transaction($conn)) {
    json_response(['status' => 'error', 'message' => 'Không thể bắt đầu check-in. Vui lòng thử lại.'], 500);
}

try {
    $passengerStmt = mysqli_prepare($conn,
        'SELECT p.id, p.first_name, p.last_name, p.checkin_status, p.boarding_pass_code,
                p.flight_seat_id, p.seat_class, p.baggage_weight, p.baggage_price,
                p.meal_name, p.meal_price, fs.seat_code
         FROM passengers p
         LEFT JOIN flight_seats fs ON fs.id = p.flight_seat_id
         WHERE p.booking_id = ? ORDER BY p.id FOR UPDATE'
    );
    mysqli_stmt_bind_param($passengerStmt, 'i', $bookingId);
    if (!mysqli_stmt_execute($passengerStmt)) {
        throw new RuntimeException('Không thể tải hành khách để làm thủ tục.');
    }
    $passengerResult = mysqli_stmt_get_result($passengerStmt);
    $passengers = [];
    while ($passenger = mysqli_fetch_assoc($passengerResult)) {
        $passengers[] = $passenger;
    }
    mysqli_stmt_close($passengerStmt);
    if (!$passengers) {
        throw new RuntimeException('Đặt chỗ chưa có thông tin hành khách.');
    }

    $passengerById = [];
    foreach ($passengers as $passenger) {
        $passengerById[(int)$passenger['id']] = $passenger;
    }
    $requestedSeats = [];
    foreach ($seatAssignments as $assignment) {
        if (!is_array($assignment) || !isset($assignment['passengerId']) ||
            !array_key_exists('seatCode', $assignment)) {
            throw new InvalidArgumentException('Thông tin ghế của hành khách không hợp lệ.');
        }
        $passengerId = filter_var($assignment['passengerId'], FILTER_VALIDATE_INT);
        $seatCode = strtoupper(trim((string)$assignment['seatCode']));
        if ($passengerId === false || !isset($passengerById[$passengerId]) ||
            isset($requestedSeats[$passengerId]) ||
            ($seatCode !== '' && !preg_match('/^\d{1,2}[A-F]$/', $seatCode))) {
            throw new InvalidArgumentException('Hành khách hoặc ghế được chọn không hợp lệ.');
        }
        $requestedSeats[$passengerId] = $seatCode;
    }

    $baggageOptions = [
        'free' => ['weight' => 0, 'price' => 0],
        '10kg' => ['weight' => 10, 'price' => 150000],
        '20kg' => ['weight' => 20, 'price' => 290000],
        '30kg' => ['weight' => 30, 'price' => 420000],
    ];
    $mealOptions = [
        'free' => ['name' => '', 'price' => 0],
        'beef' => ['name' => 'Cơm bò sốt tiêu đen', 'price' => 85000],
        'veggie' => ['name' => 'Cơm chay dưỡng sinh', 'price' => 70000],
    ];
    $requestedAddons = [];
    $addonTotal = 0;
    foreach ($addonSelections as $selection) {
        if (!is_array($selection) || !isset($selection['passengerId'])) {
            throw new InvalidArgumentException('Thông tin dịch vụ của hành khách không hợp lệ.');
        }
        $passengerId = filter_var($selection['passengerId'], FILTER_VALIDATE_INT);
        $baggage = (string)($selection['baggage'] ?? 'free');
        $meal = (string)($selection['meal'] ?? 'free');
        if ($passengerId === false || !isset($passengerById[$passengerId]) ||
            isset($requestedAddons[$passengerId]) ||
            !isset($baggageOptions[$baggage]) || !isset($mealOptions[$meal])) {
            throw new InvalidArgumentException('Lựa chọn dịch vụ không hợp lệ.');
        }
        $requestedAddons[$passengerId] = [
            'baggageWeight' => $baggageOptions[$baggage]['weight'],
            'baggagePrice' => $baggageOptions[$baggage]['price'],
            'mealName' => $mealOptions[$meal]['name'],
            'mealPrice' => $mealOptions[$meal]['price'],
        ];
        $addonTotal += $baggageOptions[$baggage]['price'] + $mealOptions[$meal]['price'];
    }
    if ($addonTotal > 0 && !$addonsPaid) {
        throw new RuntimeException('Vui lòng xác nhận thanh toán mô phỏng cho dịch vụ đã chọn.');
    }
    $addonPaymentRef = 'CI-' . $bookingCode . '-' .
        substr(hash('sha256', json_encode($requestedAddons, JSON_UNESCAPED_UNICODE)), 0, 24);

    $checkedInCount = count(array_filter(
        $passengers,
        static fn(array $passenger): bool => (int)$passenger['checkin_status'] === 1
    ));
    if ($checkedInCount > 0 && $checkedInCount !== count($passengers)) {
        throw new RuntimeException('Đặt chỗ đang ở trạng thái check-in chưa hoàn tất. Vui lòng liên hệ hỗ trợ.');
    }
    if ($checkedInCount === count($passengers)) {
        if ($addonTotal > 0) {
            $retryPaymentStmt = mysqli_prepare($conn,
                "SELECT amount FROM payments
                 WHERE booking_id = ? AND transaction_ref = ? AND status = 'success' LIMIT 1"
            );
            if (!$retryPaymentStmt) {
                throw new RuntimeException('Không thể xác minh thanh toán dịch vụ trước đó.');
            }
            mysqli_stmt_bind_param($retryPaymentStmt, 'is', $bookingId, $addonPaymentRef);
            if (!mysqli_stmt_execute($retryPaymentStmt)) {
                mysqli_stmt_close($retryPaymentStmt);
                throw new RuntimeException('Không thể xác minh thanh toán dịch vụ trước đó.');
            }
            $retryPayment = mysqli_fetch_assoc(mysqli_stmt_get_result($retryPaymentStmt));
            mysqli_stmt_close($retryPaymentStmt);
            if (!$retryPayment || (int)$retryPayment['amount'] !== $addonTotal) {
                throw new RuntimeException('Check-in đã hoàn tất; không thể thêm dịch vụ sau khi nhận thẻ lên máy bay.');
            }
        }
        if ($requestedSeats) {
            foreach ($passengers as $passenger) {
                $passengerId = (int)$passenger['id'];
                $requested = $requestedSeats[$passengerId] ?? (string)($passenger['seat_code'] ?? '');
                if ($requested !== (string)($passenger['seat_code'] ?? '')) {
                    throw new RuntimeException('Hành khách đã check-in; không thể đổi ghế tại đây.');
                }
            }
        }
        mysqli_commit($conn);
        $boardingPasses = array_map(static fn(array $passenger): array => [
            'passengerId' => (int)$passenger['id'],
            'passengerName' => $passenger['first_name'] . ' ' . $passenger['last_name'],
            'seat' => $passenger['seat_code'] ?? 'N/A',
            'boardingPass' => $passenger['boarding_pass_code'],
        ], $passengers);
        json_response([
            'status' => 'ok',
            'message' => 'Đặt chỗ này đã check-in từ trước!',
            'bookingCode' => $bookingCode,
            'boardingPasses' => $boardingPasses
        ]);
    }

    $seatLookup = mysqli_prepare($conn,
        'SELECT id, seat_class, is_booked FROM flight_seats
         WHERE flight_id = ? AND seat_code = ? LIMIT 1 FOR UPDATE'
    );
    $reserveSeat = mysqli_prepare($conn,
        'UPDATE flight_seats SET is_booked = 1 WHERE id = ? AND is_booked = 0'
    );
    $releaseSeat = mysqli_prepare($conn,
        'UPDATE flight_seats SET is_booked = 0 WHERE id = ? AND is_booked = 1'
    );
    $updatePassenger = mysqli_prepare($conn,
        'UPDATE passengers
         SET flight_seat_id = ?, seat_price = ?, baggage_weight = baggage_weight + ?,
             baggage_price = baggage_price + ?, meal_name = ?, meal_price = meal_price + ?,
             checkin_status = 1, boarding_pass_code = ?
         WHERE id = ? AND booking_id = ? AND checkin_status = 0'
    );
    $updateBookingServices = mysqli_prepare($conn,
        'UPDATE bookings SET service_price = service_price + ?, total_price = total_price + ?
         WHERE id = ? AND payment_status = "paid" AND booking_status = "confirmed"'
    );
    $insertAddonPayment = mysqli_prepare($conn,
        "INSERT INTO payments (booking_id, method, amount, transaction_ref, status, paid_at)
         VALUES (?, ?, ?, ?, 'success', NOW())"
    );
    if (!$seatLookup || !$reserveSeat || !$releaseSeat || !$updatePassenger ||
        !$updateBookingServices || !$insertAddonPayment) {
        throw new RuntimeException('Không thể chuẩn bị lưu ghế và thông tin check-in.');
    }

    $boardingPasses = [];
    $checkedIn = 0;
    foreach ($passengers as $passenger) {
        $passengerId = (int)$passenger['id'];
        $oldSeatId = $passenger['flight_seat_id'] === null ? null : (int)$passenger['flight_seat_id'];
        $oldSeatCode = (string)($passenger['seat_code'] ?? '');
        $seatCode = $requestedSeats[$passengerId] ?? $oldSeatCode;
        $newSeatId = $oldSeatId;
        $seatPrice = 0;

        if ($seatCode !== '') {
            if (!preg_match('/^(\d{1,2})[A-F]$/', $seatCode, $seatMatches)) {
                throw new InvalidArgumentException('Mã ghế không hợp lệ.');
            }
            $rowNumber = (int)$seatMatches[1];
            $seatPrice = $rowNumber >= 4 && $rowNumber <= 9
                ? 100000
                : ($rowNumber >= 10 && $rowNumber <= 13 ? 150000 : 0);

            $flightId = (int)$booking['flight_id'];
            mysqli_stmt_bind_param($seatLookup, 'is', $flightId, $seatCode);
            if (!mysqli_stmt_execute($seatLookup)) {
                throw new RuntimeException('Không thể xác minh ghế được chọn.');
            }
            $seat = mysqli_fetch_assoc(mysqli_stmt_get_result($seatLookup));
            if (!$seat || $seat['seat_class'] !== $passenger['seat_class']) {
                throw new InvalidArgumentException("Ghế {$seatCode} không thuộc hạng vé của hành khách.");
            }
            $newSeatId = (int)$seat['id'];
            if ($newSeatId !== $oldSeatId) {
                if ((int)$seat['is_booked'] === 1) {
                    throw new RuntimeException("Ghế {$seatCode} vừa được người khác chọn. Vui lòng chọn ghế khác.");
                }
                mysqli_stmt_bind_param($reserveSeat, 'i', $newSeatId);
                if (!mysqli_stmt_execute($reserveSeat) || mysqli_stmt_affected_rows($reserveSeat) !== 1) {
                    throw new RuntimeException("Ghế {$seatCode} không còn trống. Vui lòng chọn ghế khác.");
                }
            }
        } else {
            $newSeatId = null;
        }

        $boardingPassCode = 'BP-' . $bookingCode . '-' . str_pad((string)$passengerId, 2, '0', STR_PAD_LEFT);
        $addon = $requestedAddons[$passengerId] ?? [
            'baggageWeight' => 0,
            'baggagePrice' => 0,
            'mealName' => '',
            'mealPrice' => 0,
        ];
        $mealName = $addon['mealName'];
        if ($mealName !== '' && !empty($passenger['meal_name']) &&
            $passenger['meal_name'] !== 'Không đặt trước') {
            $mealName = $passenger['meal_name'] . '; ' . $mealName;
        }
        if ($mealName === '' && !empty($passenger['meal_name'])) {
            $mealName = $passenger['meal_name'];
        }
        mysqli_stmt_bind_param($updatePassenger, 'iiiisisii',
            $newSeatId, $seatPrice,
            $addon['baggageWeight'], $addon['baggagePrice'], $mealName, $addon['mealPrice'],
            $boardingPassCode, $passengerId, $bookingId
        );
        if (!mysqli_stmt_execute($updatePassenger) || mysqli_stmt_affected_rows($updatePassenger) !== 1) {
            throw new RuntimeException('Không thể lưu check-in cho hành khách.');
        }
        if ($oldSeatId !== null && $oldSeatId !== $newSeatId) {
            mysqli_stmt_bind_param($releaseSeat, 'i', $oldSeatId);
            if (!mysqli_stmt_execute($releaseSeat) || mysqli_stmt_affected_rows($releaseSeat) !== 1) {
                throw new RuntimeException('Không thể giải phóng ghế cũ của hành khách.');
            }
        }

        $checkedIn++;
        $boardingPasses[] = [
            'passengerId' => $passengerId,
            'passengerName' => $passenger['first_name'] . ' ' . $passenger['last_name'],
            'seat' => $seatCode !== '' ? $seatCode : 'N/A',
            'boardingPass' => $boardingPassCode,
        ];
    }
    mysqli_stmt_close($seatLookup);
    mysqli_stmt_close($reserveSeat);
    mysqli_stmt_close($releaseSeat);
    mysqli_stmt_close($updatePassenger);
    if ($addonTotal > 0) {
        $bookingIdForPayment = $bookingId;
        mysqli_stmt_bind_param($updateBookingServices, 'iii', $addonTotal, $addonTotal, $bookingIdForPayment);
        if (!mysqli_stmt_execute($updateBookingServices) || mysqli_stmt_affected_rows($updateBookingServices) !== 1) {
            throw new RuntimeException('Không thể cập nhật tổng tiền dịch vụ.');
        }
        $dbPaymentMethod = [
            'VietQR' => 'bank_transfer',
            'Thẻ' => 'vnpay',
            'Ví điện tử' => 'momo',
        ][$paymentMethod];
        mysqli_stmt_bind_param($insertAddonPayment, 'isis',
            $bookingId, $dbPaymentMethod, $addonTotal, $addonPaymentRef
        );
        if (!mysqli_stmt_execute($insertAddonPayment)) {
            throw new RuntimeException('Không thể lưu thanh toán dịch vụ mua thêm.');
        }
    }
    mysqli_stmt_close($updateBookingServices);
    mysqli_stmt_close($insertAddonPayment);
    if (!mysqli_commit($conn)) {
        throw new RuntimeException('Không thể xác nhận hoàn tất check-in.');
    }

    json_response([
        'status' => 'ok',
        'message' => "Check-in thành công cho {$checkedIn} hành khách!",
        'bookingCode' => $bookingCode,
        'boardingPasses' => $boardingPasses
    ]);
} catch (Throwable $exception) {
    mysqli_rollback($conn);
    $statusCode = $exception instanceof InvalidArgumentException ? 400 : 409;
    json_response(['status' => 'error', 'message' => $exception->getMessage()], $statusCode);
}
