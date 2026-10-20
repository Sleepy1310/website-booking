<?php
/**
 * API: Tạo đơn đặt vé (booking + passengers + payment) — Transaction an toàn
 * POST /api/bookings/create.php
 *
 * Body JSON:
 * {
 *   "flightId": 2,
 *   "passengers": [{"title":"Mr","firstName":"VAN AN","lastName":"NGUYEN","dob":"1995-08-15",
 *                    "idNumber":"079095012345","nationality":"Vietnam","seatCode":"12A"}],
 *   "contactName": "Nguyễn Văn An",
 *   "contactEmail": "an@example.com",
 *   "contactPhone": "0912345678",
 *   "seatPrice": 150000,
 *   "baggageWeight": 20,
 *   "baggagePrice": 0,
 *   "mealName": "Cơm gà",
 *   "mealPrice": 0,
 *   "insurancePrice": 89000,
 *   "promoCode": "WELCOME10",
 *   "payMethod": "VietQR"
 * }
 */
require_once dirname(__FILE__, 2) . '/config/database.php';
require_once dirname(__FILE__, 2) . '/config/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['status' => 'error', 'message' => 'Method not allowed'], 405);
}
if (!$conn) {
    json_response(['status' => 'error', 'message' => 'Không thể kết nối cơ sở dữ liệu!'], 500);
}

// Nhận dữ liệu JSON hoặc POST form
$input = json_decode(file_get_contents('php://input'), true) ?: $_POST;

$flightId     = (int)($input['flightId'] ?? 0);
$passengers   = $input['passengers'] ?? [];
$contactName  = sanitize($input['contactName'] ?? '');
$contactEmail = sanitize($input['contactEmail'] ?? '');
$contactPhone = sanitize($input['contactPhone'] ?? '');
$legacySeatCode = trim(sanitize($input['seatCode'] ?? ''));
$seatClass    = strtoupper(trim($input['seatClass'] ?? $input['cabinClass'] ?? 'ECONOMY')) === 'BUSINESS' ? 'BUSINESS' : 'ECONOMY';
$seatPrice    = 0;
$baggageWeight= (int)($input['baggageWeight'] ?? 0);
$baggagePrice = (int)($input['baggagePrice'] ?? 0);
$mealName     = sanitize($input['mealName'] ?? '');
$mealPrice    = (int)($input['mealPrice'] ?? 0);
$insurancePrice=(int)($input['insurancePrice'] ?? 0);
$promoCode    = strtoupper(trim($input['promoCode'] ?? ''));
$payMethod    = $input['payMethod'] ?? 'VietQR';
$userId       = $_SESSION['user']['id'] ?? null;

// Validation cơ bản
if ($flightId <= 0 || !is_array($passengers) || empty($passengers) || count($passengers) > 9 || empty($contactEmail)) {
    json_response(['status' => 'error', 'message' => 'Dữ liệu đầu vào không hợp lệ!'], 422);
}
$passengers = array_values($passengers);
$seatCodes = [];
foreach ($passengers as $index => $pax) {
    if (!is_array($pax)) {
        json_response(['status' => 'error', 'message' => 'Thông tin hành khách không hợp lệ!'], 422);
    }
    $seatCode = trim(sanitize($pax['seatCode'] ?? ''));
    if ($seatCode === '' && $index === 0) {
        $seatCode = $legacySeatCode;
    }
    if ($seatCode !== '' && !preg_match('/^\d{1,2}[A-F]$/', $seatCode)) {
        json_response(['status' => 'error', 'message' => 'Ghế được chọn không hợp lệ!'], 422);
    }
    $seatCodes[] = $seatCode;
}
$selectedSeatCodes = array_filter($seatCodes, static fn($code) => $code !== '');
if (count($selectedSeatCodes) !== count(array_unique($selectedSeatCodes))) {
    json_response(['status' => 'error', 'message' => 'Mỗi hành khách phải chọn một ghế riêng!'], 422);
}

// 1. Lấy thông tin chuyến bay
$inventoryColumn = $seatClass === 'BUSINESS' ? 'seats_business_left' : 'seats_economy_left';
$priceColumn = $seatClass === 'BUSINESS' ? 'price_business' : 'price_economy';
$fStmt = mysqli_prepare($conn, "SELECT * FROM flights WHERE id = ? AND {$inventoryColumn} >= ? AND status <> 'cancelled' LIMIT 1");
$numPax = count($passengers);
mysqli_stmt_bind_param($fStmt, "ii", $flightId, $numPax);
mysqli_stmt_execute($fStmt);
$fRes   = mysqli_stmt_get_result($fStmt);
$flight = mysqli_fetch_assoc($fRes);
mysqli_stmt_close($fStmt);

if (!$flight) {
    json_response(['status' => 'error', 'message' => 'Chuyến bay không tồn tại hoặc không đủ chỗ!'], 400);
}

$basePrice  = (int)$flight[$priceColumn] * $numPax;
$taxPrice   = (int)$flight['tax_price'] * $numPax;
$servicePrice = $seatPrice + $baggagePrice + $mealPrice + $insurancePrice;
$discountPrice = 0;
$promoId   = null;

// 2. Kiểm tra & áp dụng mã giảm giá
if (!empty($promoCode)) {
    $pStmt = mysqli_prepare($conn, 
        "SELECT * FROM promotions WHERE code = ? AND is_active = 1 AND NOW() BETWEEN start_date AND end_date
         AND used_count < usage_limit LIMIT 1"
    );
    mysqli_stmt_bind_param($pStmt, "s", $promoCode);
    mysqli_stmt_execute($pStmt);
    $pRes  = mysqli_stmt_get_result($pStmt);
    $promo = mysqli_fetch_assoc($pRes);
    mysqli_stmt_close($pStmt);

    if ($promo) {
        $orderTotal = $basePrice + $taxPrice + $servicePrice;
        if ($orderTotal >= (int)$promo['min_order_value']) {
            $promoId = (int)$promo['id'];
            if ($promo['discount_type'] === 'percentage') {
                $discountPrice = (int)round($orderTotal * $promo['discount_value'] / 100);
                if ($promo['max_discount']) {
                    $discountPrice = min($discountPrice, (int)$promo['max_discount']);
                }
            } else {
                $discountPrice = (int)$promo['discount_value'];
            }
        }
    }
}

$totalPrice = $basePrice + $taxPrice + $servicePrice - $discountPrice;
$earnedPoints = (int)floor($totalPrice / 10000);
$bookingCode = 'BK' . strtoupper(substr(md5(uniqid()), 0, 6));

// 3. Transaction
mysqli_begin_transaction($conn);
try {
    // Insert booking
    $flightSeatIds = array_fill(0, $numPax, null);
    foreach ($seatCodes as $index => $seatCode) {
        if ($seatCode === '') {
            continue;
        }
        $seatStmt = mysqli_prepare($conn,
            "SELECT id, seat_class FROM flight_seats
             WHERE flight_id = ? AND seat_code = ? AND is_booked = 0 FOR UPDATE"
        );
        mysqli_stmt_bind_param($seatStmt, 'is', $flightId, $seatCode);
        mysqli_stmt_execute($seatStmt);
        $seat = mysqli_fetch_assoc(mysqli_stmt_get_result($seatStmt));
        mysqli_stmt_close($seatStmt);
        if (!$seat || $seat['seat_class'] !== $seatClass) {
            throw new RuntimeException("Ghế {$seatCode} không còn trống hoặc không đúng hạng vé.");
        }
        $flightSeatIds[$index] = (int)$seat['id'];
    }

    $bStmt = mysqli_prepare($conn,
        "INSERT INTO bookings (booking_code, user_id, flight_id, promotion_id, contact_name, contact_email, contact_phone,
                               num_passengers, base_price, tax_price, service_price, discount_price, total_price, 
                               earned_points, payment_status, booking_status, expires_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'paid', 'confirmed', NOW())"
    );
    mysqli_stmt_bind_param($bStmt, "siiisssiiiiiii",
        $bookingCode, $userId, $flightId, $promoId,
        $contactName, $contactEmail, $contactPhone,
        $numPax, $basePrice, $taxPrice, $servicePrice, $discountPrice, $totalPrice, $earnedPoints
    );
    mysqli_stmt_execute($bStmt);
    $bookingId = mysqli_insert_id($conn);
    mysqli_stmt_close($bStmt);

    // Insert passengers
    $paxStmt = mysqli_prepare($conn,
        "INSERT INTO passengers (booking_id, passenger_type, title, first_name, last_name, dob, id_number,
                                 nationality, seat_class, flight_seat_id, seat_price, baggage_weight, baggage_price,
                                 meal_name, meal_price, insurance_price, checkin_status, boarding_pass_code)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, NULL)"
    );
    foreach ($passengers as $idx => $pax) {
        $passengerType = $pax['passengerType'] ?? 'adult';
        $pType = in_array($passengerType, ['adult', 'child', 'infant'], true) ? $passengerType : 'adult';
        $pTitle   = strtoupper($pax['title'] ?? 'MR');
        if (!in_array($pTitle, ['MR', 'MRS', 'MS', 'MSTR', 'MISS'], true)) $pTitle = 'MR';
        $pFirst   = strtoupper(trim($pax['firstName'] ?? 'VAN'));
        $pLast    = strtoupper(trim($pax['lastName'] ?? 'NGUYEN'));
        $pDob     = !empty($pax['dob']) ? date('Y-m-d', strtotime(str_replace('/', '-', $pax['dob']))) : '1990-01-01';
        $pIdNum   = $pax['idNumber'] ?? '';
        $pNat     = $pax['nationality'] ?? 'Vietnam';
        $pSeat    = $flightSeatIds[$idx] ?? null;
        $pSeatPr  = ($pSeat !== null) ? $seatPrice : 0;
        $pBagW    = ($idx === 0) ? $baggageWeight : 0;
        $pBagPr   = ($idx === 0) ? $baggagePrice : 0;
        $pMeal    = ($idx === 0) ? $mealName : null;
        $pMealPr  = ($idx === 0) ? $mealPrice : 0;
        $pIns     = ($idx === 0) ? $insurancePrice : 0;

        mysqli_stmt_bind_param($paxStmt, "issssssssiiiisii",
            $bookingId, $pType, $pTitle, $pFirst, $pLast, $pDob, $pIdNum, $pNat,
            $seatClass, $pSeat, $pSeatPr, $pBagW, $pBagPr, $pMeal, $pMealPr, $pIns
        );
        mysqli_stmt_execute($paxStmt);
    }
    mysqli_stmt_close($paxStmt);

    // Giảm số ghế
    $decrementStmt = mysqli_prepare($conn, "UPDATE flights SET {$inventoryColumn} = {$inventoryColumn} - ? WHERE id = ? AND {$inventoryColumn} >= ?");
    mysqli_stmt_bind_param($decrementStmt, 'iii', $numPax, $flightId, $numPax);
    if (!mysqli_stmt_execute($decrementStmt) || mysqli_stmt_affected_rows($decrementStmt) !== 1) {
        throw new RuntimeException('Chuyến bay vừa hết chỗ.');
    }
    mysqli_stmt_close($decrementStmt);
    foreach ($flightSeatIds as $index => $flightSeatId) {
        if ($flightSeatId === null) {
            continue;
        }
        $reserveStmt = mysqli_prepare($conn, 'UPDATE flight_seats SET is_booked = 1 WHERE id = ? AND is_booked = 0');
        mysqli_stmt_bind_param($reserveStmt, 'i', $flightSeatId);
        if (!mysqli_stmt_execute($reserveStmt) || mysqli_stmt_affected_rows($reserveStmt) !== 1) {
            throw new RuntimeException("Ghế {$seatCodes[$index]} vừa được hành khách khác chọn.");
        }
        mysqli_stmt_close($reserveStmt);
    }

    // Cập nhật số lần dùng promo
    if ($promoId) {
        mysqli_query($conn, "UPDATE promotions SET used_count = used_count + 1 WHERE id = {$promoId}");
    }
    if ($userId !== null) {
        $pointsStmt = mysqli_prepare($conn, 'UPDATE users SET points = points + ? WHERE id = ?');
        mysqli_stmt_bind_param($pointsStmt, 'ii', $earnedPoints, $userId);
        mysqli_stmt_execute($pointsStmt);
        mysqli_stmt_close($pointsStmt);
        $tierStmt = mysqli_prepare($conn, 'CALL sp_update_member_tier(?)');
        mysqli_stmt_bind_param($tierStmt, 'i', $userId);
        mysqli_stmt_execute($tierStmt);
        mysqli_stmt_close($tierStmt);
        while (mysqli_more_results($conn) && mysqli_next_result($conn)) {
            $result = mysqli_store_result($conn);
            if ($result instanceof mysqli_result) mysqli_free_result($result);
        }
    }

    // Insert payment
    $txRef = 'TXN-' . date('Ymd') . '-' . random_int(1000, 9999);
    $dbPayMethod = [
        'VietQR' => 'bank_transfer', 'Thẻ' => 'vnpay', 'ATM' => 'bank_transfer', 'Ví điện tử' => 'momo',
    ][$payMethod] ?? 'bank_transfer';
    $payStmt = mysqli_prepare($conn,
        "INSERT INTO payments (booking_id, method, amount, transaction_ref, status, paid_at) VALUES (?, ?, ?, ?, 'success', NOW())"
    );
    mysqli_stmt_bind_param($payStmt, "isis", $bookingId, $dbPayMethod, $totalPrice, $txRef);
    mysqli_stmt_execute($payStmt);
    mysqli_stmt_close($payStmt);

    mysqli_commit($conn);

    json_response([
        'status'       => 'ok',
        'message'      => 'Đặt vé thành công!',
        'bookingCode'  => $bookingCode,
        'bookingId'    => $bookingId,
        'totalPrice'   => $totalPrice,
        'redirectUrl'  => '/WebsiteDatVe/booking/success/success.php?code=' . urlencode($bookingCode)
    ]);

} catch (Exception $e) {
    mysqli_rollback($conn);
    json_response(['status' => 'error', 'message' => 'Lỗi hệ thống: ' . $e->getMessage()], 500);
}
