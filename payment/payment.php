<?php
session_start();
require_once __DIR__ . '/../api/config/database.php';

$payError = '';
$bookingCode = strtoupper(trim((string)($_GET['code'] ?? $_POST['bookingCode'] ?? '')));
$isConfirm = isset($_POST['confirm_payment']);
$payMethod = $_POST['payMethod'] ?? 'VietQR';

function bookingSeatPrice(string $seatCode): ?int
{
    if (!preg_match('/^(\d{1,2})[A-F]$/', $seatCode, $matches)) {
        return null;
    }
    $rowNumber = (int)$matches[1];
    if ($rowNumber < 1) {
        return null;
    }
    if ($rowNumber >= 4 && $rowNumber <= 9) {
        return 100000;
    }
    if ($rowNumber >= 10 && $rowNumber <= 13) {
        return 150000;
    }
    return 0;
}

if (!$conn) {
    $payError = 'Không kết nối được cơ sở dữ liệu. Vui lòng thử lại.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && !$isConfirm) {
    $flightId = (int)($_POST['flightId'] ?? 0);
    $passengers = $_POST['passengers'] ?? [];
    $passengers = is_array($passengers) ? array_values($passengers) : [];
    $passengerCount = count($passengers);
    $cabinClass = stripos((string)($_POST['cabin'] ?? ''), 'thương gia') !== false ? 'BUSINESS' : 'ECONOMY';
    $legacySeatCode = trim((string)($_POST['seatCode'] ?? ''));
    $seatCodes = [];
    $seatPrices = [];
    $invalidSeatSelection = false;
    foreach ($passengers as $index => $pax) {
        $seatCode = is_array($pax) ? trim((string)($pax['seatCode'] ?? '')) : '';
        if ($seatCode === '' && $index === 0) {
            $seatCode = $legacySeatCode;
        }
        if ($seatCode !== '' && !preg_match('/^\d{1,2}[A-F]$/', $seatCode)) {
            $invalidSeatSelection = true;
        }
        $seatCodes[] = $seatCode;
        $seatPrices[] = 0;
    }
    if (count(array_filter($seatCodes, static fn($code) => $code !== '')) !==
        count(array_unique(array_filter($seatCodes, static fn($code) => $code !== '')))) {
        $invalidSeatSelection = true;
    }
    $contactName = trim((string)($_POST['passengerName'] ?? ''));
    $contactEmail = trim((string)($_POST['passengerEmail'] ?? ''));
    $contactPhone = trim((string)($_POST['passengerPhone'] ?? ''));

    if (!preg_match('/^BK[A-F0-9]{6}$/', $bookingCode) || $flightId <= 0 ||
        $passengerCount < 1 || $passengerCount > 9 || $contactEmail === '') {
        $payError = 'Thông tin đặt chỗ không hợp lệ. Vui lòng quay lại và kiểm tra lại.';
    } else {
        $existingStmt = mysqli_prepare($conn, "SELECT payment_status, booking_status, expires_at, expires_at > NOW() AS hold_active FROM bookings WHERE booking_code = ? LIMIT 1");
        mysqli_stmt_bind_param($existingStmt, 's', $bookingCode);
        mysqli_stmt_execute($existingStmt);
        $existing = mysqli_fetch_assoc(mysqli_stmt_get_result($existingStmt));
        mysqli_stmt_close($existingStmt);

        if ($existing) {
            if ($existing['payment_status'] === 'paid') {
                header('Location: /WebsiteDatVe/booking/success/success.php?code=' . urlencode($bookingCode));
                exit;
            }
            if ($existing['payment_status'] === 'unpaid' && $existing['booking_status'] === 'pending' &&
                (int)$existing['hold_active'] === 1) {
                header('Location: /WebsiteDatVe/payment/payment.php?code=' . urlencode($bookingCode));
                exit;
            }
            $payError = 'Mã giữ chỗ này đã hết hạn. Vui lòng chọn chuyến bay và đặt chỗ lại.';
        } else {
            $seatPrice = 0;
            foreach ($seatCodes as $index => $seatCode) {
                if ($seatCode === '') {
                    continue;
                }
                $seatPriceForPassenger = bookingSeatPrice($seatCode);
                if ($seatPriceForPassenger === null) {
                    $invalidSeatSelection = true;
                    break;
                }
                $seatPrices[$index] = $seatPriceForPassenger;
                $seatPrice += $seatPriceForPassenger;
            }
            $passengerServices = [];
            $baggagePrice = 0;
            $mealPrice = 0;
            $insurancePrice = 0;
            $invalidServiceSelection = false;
            foreach ($passengers as $index => $pax) {
                if (!is_array($pax)) {
                    $invalidServiceSelection = true;
                    break;
                }
                $baggageWeight = filter_var($pax['baggageWeight'] ?? '0', FILTER_VALIDATE_INT);
                $baggageOptions = [0 => 0, 15 => 220000, 20 => 290000, 30 => 450000];
                if ($baggageWeight === false || !array_key_exists($baggageWeight, $baggageOptions)) {
                    $invalidServiceSelection = true;
                    break;
                }
                $mealOptions = [
                    'none' => ['name' => 'Không đặt trước', 'price' => 0],
                    'chicken' => ['name' => 'Cơm gà Hội An + Nước ép', 'price' => 65000],
                    'vegetarian' => ['name' => 'Suất ăn chay', 'price' => 55000],
                ];
                $mealOption = (string)($pax['mealOption'] ?? 'none');
                if (!isset($mealOptions[$mealOption])) {
                    $invalidServiceSelection = true;
                    break;
                }
                $insurance = (string)($pax['insurance'] ?? '0');
                if (!in_array($insurance, ['0', '1'], true)) {
                    $invalidServiceSelection = true;
                    break;
                }
                $passengerServices[$index] = [
                    'baggageWeight' => $baggageWeight,
                    'baggagePrice' => $baggageOptions[$baggageWeight],
                    'mealName' => $mealOptions[$mealOption]['name'],
                    'mealPrice' => $mealOptions[$mealOption]['price'],
                    'insurancePrice' => $insurance === '1' ? 89000 : 0,
                ];
                $baggagePrice += $baggageOptions[$baggageWeight];
                $mealPrice += $mealOptions[$mealOption]['price'];
                $insurancePrice += $insurance === '1' ? 89000 : 0;
            }
            $discountPrice = max(0, (int)($_POST['discountPrice'] ?? 0));
            $servicePrice = ($seatPrice ?? 0) + $baggagePrice + $mealPrice + $insurancePrice;
            $userId = isset($_SESSION['user']['id']) ? (int)$_SESSION['user']['id'] : null;

            if ($invalidSeatSelection) {
                $payError = 'Ghế được chọn không hợp lệ. Vui lòng quay lại và chọn ghế khác.';
            } elseif ($invalidServiceSelection) {
                $payError = 'Dịch vụ đã chọn không hợp lệ. Vui lòng quay lại và chọn lại.';
            } elseif (!mysqli_begin_transaction($conn)) {
                $payError = 'Không thể bắt đầu giữ chỗ. Vui lòng thử lại.';
            } else {
                try {
                    $flightStmt = mysqli_prepare($conn, "SELECT id, price_economy, price_business, tax_price, seats_economy_left, seats_business_left, status FROM flights WHERE id = ? FOR UPDATE");
                    mysqli_stmt_bind_param($flightStmt, 'i', $flightId);
                    mysqli_stmt_execute($flightStmt);
                    $flight = mysqli_fetch_assoc(mysqli_stmt_get_result($flightStmt));
                    mysqli_stmt_close($flightStmt);

                    $seatInventory = $cabinClass === 'BUSINESS' ? 'seats_business_left' : 'seats_economy_left';
                    if (!$flight || $flight['status'] === 'cancelled' || (int)$flight[$seatInventory] < $passengerCount) {
                        throw new RuntimeException('Chuyến bay không còn đủ chỗ. Vui lòng chọn chuyến khác.');
                    }

                    $flightSeatIds = array_fill(0, $passengerCount, null);
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
                        if (!$seat || $seat['seat_class'] !== $cabinClass) {
                            throw new RuntimeException("Ghế {$seatCode} vừa được người khác chọn. Vui lòng chọn ghế khác hoặc bỏ chọn ghế.");
                        }
                        $flightSeatIds[$index] = (int)$seat['id'];
                    }

                    $basePrice = (int)($cabinClass === 'BUSINESS' ? $flight['price_business'] : $flight['price_economy']) * $passengerCount;
                    $taxPrice = (int)$flight['tax_price'] * $passengerCount;
                    $total = max(0, $basePrice + $taxPrice + $servicePrice - $discountPrice);
                    $earnedPoints = (int)floor($total / 10000);
                    $stmt = mysqli_prepare($conn,
                        "INSERT INTO bookings (booking_code, user_id, flight_id, promotion_id, contact_name, contact_email, contact_phone,
                            num_passengers, base_price, tax_price, service_price, discount_price, total_price, earned_points,
                            payment_status, booking_status, expires_at)
                         VALUES (?, ?, ?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'unpaid', 'pending', DATE_ADD(NOW(), INTERVAL 15 MINUTE))"
                    );
                    mysqli_stmt_bind_param($stmt, 'siisssiiiiiii',
                        $bookingCode, $userId, $flightId, $contactName, $contactEmail, $contactPhone,
                        $passengerCount, $basePrice, $taxPrice, $servicePrice, $discountPrice, $total, $earnedPoints
                    );
                    if (!mysqli_stmt_execute($stmt)) {
                        throw new RuntimeException('Không thể tạo đơn giữ chỗ: ' . mysqli_stmt_error($stmt));
                    }
                    $bookingId = mysqli_insert_id($conn);
                    mysqli_stmt_close($stmt);

                    $paxStmt = mysqli_prepare($conn,
                        "INSERT INTO passengers (booking_id, passenger_type, title, first_name, last_name,
                            dob, id_number, nationality, seat_class, flight_seat_id, seat_price, baggage_weight, baggage_price,
                            meal_name, meal_price, insurance_price, checkin_status, boarding_pass_code)
                         VALUES (?, 'adult', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, NULL)"
                    );
                    foreach ($passengers as $index => $pax) {
                        $title = strtoupper((string)($pax['title'] ?? 'MR'));
                        if (!in_array($title, ['MR', 'MRS', 'MS', 'MSTR', 'MISS'], true)) {
                            $title = 'MR';
                        }
                        $firstName = trim((string)($pax['firstName'] ?? ''));
                        $lastName = trim((string)($pax['lastName'] ?? ''));
                        $dob = !empty($pax['dob']) ? date('Y-m-d', strtotime(str_replace('/', '-', $pax['dob']))) : null;
                        $idNumber = trim((string)($pax['idNumber'] ?? ''));
                        $nationality = trim((string)($pax['nationality'] ?? 'Vietnam'));
                        $passengerSeatId = $flightSeatIds[$index] ?? null;
                        $passengerSeatPrice = $seatPrices[$index] ?? 0;
                        $passengerService = $passengerServices[$index] ?? [
                            'baggageWeight' => 0,
                            'baggagePrice' => 0,
                            'mealName' => 'Không đặt trước',
                            'mealPrice' => 0,
                            'insurancePrice' => 0,
                        ];
                        $passengerBaggageWeight = $passengerService['baggageWeight'];
                        $passengerBaggagePrice = $passengerService['baggagePrice'];
                        $mealName = $passengerService['mealName'];
                        $passengerMealPrice = $passengerService['mealPrice'];
                        $passengerInsurance = $passengerService['insurancePrice'];

                        mysqli_stmt_bind_param($paxStmt, 'isssssssiiiisii',
                            $bookingId, $title, $firstName, $lastName, $dob, $idNumber,
                            $nationality, $cabinClass, $passengerSeatId, $passengerSeatPrice, $passengerBaggageWeight, $passengerBaggagePrice,
                            $mealName, $passengerMealPrice, $passengerInsurance
                        );
                        if (!mysqli_stmt_execute($paxStmt)) {
                            throw new RuntimeException('Không thể lưu hành khách: ' . mysqli_stmt_error($paxStmt));
                        }
                    }
                    mysqli_stmt_close($paxStmt);

                    $decrementStmt = mysqli_prepare($conn, "UPDATE flights SET {$seatInventory} = {$seatInventory} - ? WHERE id = ? AND {$seatInventory} >= ?");
                    mysqli_stmt_bind_param($decrementStmt, 'iii', $passengerCount, $flightId, $passengerCount);
                    if (!mysqli_stmt_execute($decrementStmt) || mysqli_stmt_affected_rows($decrementStmt) !== 1) {
                        throw new RuntimeException('Chuyến bay vừa hết chỗ. Vui lòng chọn chuyến khác.');
                    }
                    mysqli_stmt_close($decrementStmt);

                    foreach ($flightSeatIds as $index => $flightSeatId) {
                        if ($flightSeatId === null) {
                            continue;
                        }
                        $reserveSeatStmt = mysqli_prepare($conn, 'UPDATE flight_seats SET is_booked = 1 WHERE id = ? AND is_booked = 0');
                        mysqli_stmt_bind_param($reserveSeatStmt, 'i', $flightSeatId);
                        if (!mysqli_stmt_execute($reserveSeatStmt) || mysqli_stmt_affected_rows($reserveSeatStmt) !== 1) {
                            throw new RuntimeException("Ghế {$seatCodes[$index]} vừa được đặt. Vui lòng chọn ghế khác.");
                        }
                        mysqli_stmt_close($reserveSeatStmt);
                    }

                    $dbPayMethod = [
                        'VietQR' => 'bank_transfer',
                        'Thẻ' => 'vnpay',
                        'ATM' => 'bank_transfer',
                        'Ví điện tử' => 'momo',
                    ][$payMethod] ?? 'bank_transfer';
                    $paymentStmt = mysqli_prepare($conn,
                        "INSERT INTO payments (booking_id, method, amount, status) VALUES (?, ?, ?, 'pending')"
                    );
                    mysqli_stmt_bind_param($paymentStmt, 'isi', $bookingId, $dbPayMethod, $total);
                    if (!mysqli_stmt_execute($paymentStmt)) {
                        throw new RuntimeException('Không thể tạo thông tin thanh toán: ' . mysqli_stmt_error($paymentStmt));
                    }
                    mysqli_stmt_close($paymentStmt);

                    mysqli_commit($conn);
                    header('Location: /WebsiteDatVe/payment/payment.php?code=' . urlencode($bookingCode));
                    exit;
                } catch (Throwable $e) {
                    mysqli_rollback($conn);
                    $payError = $e->getMessage();
                }
            }
        }
    }
} elseif ($isConfirm && $conn) {
    if (!preg_match('/^BK[A-F0-9]{6}$/', $bookingCode)) {
        $payError = 'Mã đặt chỗ không hợp lệ.';
    } elseif (!in_array($payMethod, ['VietQR', 'Thẻ', 'ATM', 'Ví điện tử'], true)) {
        $payError = 'Phương thức thanh toán không hợp lệ.';
    } elseif (!mysqli_begin_transaction($conn)) {
        $payError = 'Không thể xác nhận thanh toán. Vui lòng thử lại.';
    } else {
        try {
            $stmt = mysqli_prepare($conn, "SELECT id, user_id, earned_points, payment_status, booking_status FROM bookings WHERE booking_code = ? FOR UPDATE");
            mysqli_stmt_bind_param($stmt, 's', $bookingCode);
            mysqli_stmt_execute($stmt);
            $booking = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
            mysqli_stmt_close($stmt);
            if (!$booking) {
                throw new RuntimeException('Không tìm thấy đơn giữ chỗ.');
            }
            if ($booking['payment_status'] === 'paid') {
                mysqli_commit($conn);
                header('Location: /WebsiteDatVe/booking/success/success.php?code=' . urlencode($bookingCode));
                exit;
            }

            $bookingId = (int)$booking['id'];
            $updateStmt = mysqli_prepare($conn,
                "UPDATE bookings
                 SET payment_status = 'paid', booking_status = 'confirmed', expires_at = NOW()
                 WHERE id = ? AND payment_status = 'unpaid'
                   AND booking_status = 'pending' AND expires_at > NOW()"
            );
            mysqli_stmt_bind_param($updateStmt, 'i', $bookingId);
            if (!mysqli_stmt_execute($updateStmt) || mysqli_stmt_affected_rows($updateStmt) !== 1) {
                throw new RuntimeException('Thời gian giữ chỗ đã hết. Vui lòng đặt lại chuyến bay.');
            }
            mysqli_stmt_close($updateStmt);

            $txRef = 'DEMO-' . date('YmdHis') . '-' . random_int(1000, 9999);
            $dbPayMethod = [
                'VietQR' => 'bank_transfer',
                'Thẻ' => 'vnpay',
                'ATM' => 'bank_transfer',
                'Ví điện tử' => 'momo',
            ][$payMethod] ?? 'bank_transfer';
            $paymentStmt = mysqli_prepare($conn,
                "UPDATE payments SET method = ?, transaction_ref = ?, status = 'success', paid_at = NOW()
                 WHERE booking_id = ? AND status = 'pending'"
            );
            mysqli_stmt_bind_param($paymentStmt, 'ssi', $dbPayMethod, $txRef, $bookingId);
            if (!mysqli_stmt_execute($paymentStmt) || mysqli_stmt_affected_rows($paymentStmt) !== 1) {
                throw new RuntimeException('Không thể cập nhật trạng thái thanh toán.');
            }
            mysqli_stmt_close($paymentStmt);

            if (!empty($booking['user_id']) && (int)$booking['earned_points'] > 0) {
                $pointsStmt = mysqli_prepare($conn, 'UPDATE users SET points = points + ? WHERE id = ? AND role = ?');
                $customerRole = 'Khach_Hang';
                mysqli_stmt_bind_param($pointsStmt, 'iis', $booking['earned_points'], $booking['user_id'], $customerRole);
                if (!mysqli_stmt_execute($pointsStmt)) {
                    throw new RuntimeException('Không thể cộng điểm hội viên: ' . mysqli_stmt_error($pointsStmt));
                }
                mysqli_stmt_close($pointsStmt);
                $tierStmt = mysqli_prepare($conn, 'CALL sp_update_member_tier(?)');
                mysqli_stmt_bind_param($tierStmt, 'i', $booking['user_id']);
                if (!mysqli_stmt_execute($tierStmt)) {
                    throw new RuntimeException('Không thể cập nhật hạng hội viên: ' . mysqli_stmt_error($tierStmt));
                }
                mysqli_stmt_close($tierStmt);
                while (mysqli_more_results($conn) && mysqli_next_result($conn)) {
                    $result = mysqli_store_result($conn);
                    if ($result instanceof mysqli_result) mysqli_free_result($result);
                }
            }

            mysqli_commit($conn);
            header('Location: /WebsiteDatVe/booking/success/success.php?code=' . urlencode($bookingCode));
            exit;
        } catch (Throwable $e) {
            mysqli_rollback($conn);
            $payError = $e->getMessage();
        }
    }
}

$booking = null;
$passengers = [];
$canPay = false;
$expiresAt = '';
if ($conn && preg_match('/^BK[A-F0-9]{6}$/', $bookingCode)) {
    $stmt = mysqli_prepare($conn,
        "SELECT b.*, TIMESTAMPDIFF(SECOND, NOW(), b.expires_at) AS seconds_left,
                f.flight_number, f.airline, f.from_airport, f.to_airport, f.depart_at,
                dep.city AS from_city, arr.city AS to_city
         FROM bookings b
         JOIN flights f ON b.flight_id = f.id
         JOIN airports dep ON f.from_airport = dep.code
         JOIN airports arr ON f.to_airport = arr.code
         WHERE b.booking_code = ? LIMIT 1"
    );
    mysqli_stmt_bind_param($stmt, 's', $bookingCode);
    mysqli_stmt_execute($stmt);
    $booking = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);

    if ($booking) {
        $bookingId = (int)$booking['id'];
        $paxStmt = mysqli_prepare($conn,
            "SELECT p.*, fs.seat_code FROM passengers p
             LEFT JOIN flight_seats fs ON fs.id = p.flight_seat_id
             WHERE p.booking_id = ? ORDER BY p.id"
        );
        mysqli_stmt_bind_param($paxStmt, 'i', $bookingId);
        mysqli_stmt_execute($paxStmt);
        $paxResult = mysqli_stmt_get_result($paxStmt);
        while ($pax = mysqli_fetch_assoc($paxResult)) {
            $passengers[] = $pax;
        }
        mysqli_stmt_close($paxStmt);
        $canPay = $booking['payment_status'] === 'unpaid'
            && $booking['booking_status'] === 'pending'
            && !empty($booking['expires_at'])
            && (int)$booking['seconds_left'] > 0;
        $expiresAt = $booking['expires_at'] ?? '';
    }
}

if (!$booking && $payError === '') {
    header('Location: /WebsiteDatVe/index.php');
    exit;
}

$passengerName = $booking['contact_name'] ?? '';
$passengerEmail = $booking['contact_email'] ?? '';
$passengerPhone = $booking['contact_phone'] ?? '';
$flightId = (int)($booking['flight_id'] ?? 0);
$flightCode = $booking['flight_number'] ?? '';
$airline = $booking['airline'] ?? '';
$seatAssignments = [];
foreach ($passengers as $index => $passenger) {
    if (!empty($passenger['seat_code'])) {
        $seatAssignments[] = 'Hành khách ' . ($index + 1) . ': ' . $passenger['seat_code'] .
            ((int)($passenger['seat_price'] ?? 0) > 0 ? ' (' . number_format((int)$passenger['seat_price']) . 'đ)' : ' (miễn phí)');
    }
}
$seatSummary = $seatAssignments ? implode(', ', $seatAssignments) : '';
$total = (int)($booking['total_price'] ?? 0);
$passengerCount = (int)($booking['num_passengers'] ?? 0);
$fromCity = ($booking['from_city'] ?? '') . ' (' . ($booking['from_airport'] ?? '') . ')';
$toCity = ($booking['to_city'] ?? '') . ' (' . ($booking['to_airport'] ?? '') . ')';
$bookingDraft = $_SESSION['booking_drafts'][$bookingCode] ?? [];
$searchDate = trim((string)($bookingDraft['departDate'] ?? ''));
$parsedSearchDate = DateTime::createFromFormat('!Y-m-d', $searchDate);
if (!$parsedSearchDate || $parsedSearchDate->format('Y-m-d') !== $searchDate) {
    $searchDate = date('Y-m-d', strtotime($booking['depart_at'] ?? 'now'));
}
$flightSearchUrl = '/WebsiteDatVe/flights/flights.php?' . http_build_query([
    'from' => trim((string)($bookingDraft['from'] ?? '')) ?: (string)($booking['from_airport'] ?? ''),
    'to' => trim((string)($bookingDraft['to'] ?? '')) ?: (string)($booking['to_airport'] ?? ''),
    'departDate' => $searchDate,
    'adults' => max(1, (int)($bookingDraft['adults'] ?? $passengerCount)),
    'children' => max(0, (int)($bookingDraft['children'] ?? 0)),
    'infants' => max(0, (int)($bookingDraft['infants'] ?? 0)),
]);
if ($booking && !$canPay && $payError === '' && $booking['payment_status'] !== 'paid') {
    $payError = 'Thời gian giữ chỗ đã hết hoặc đơn đã bị hủy. Vui lòng đặt lại chuyến bay.';
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Thanh toán vé máy bay - Booking Online</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: { sans: ['"Plus Jakarta Sans"', 'sans-serif'] },
                    colors: { primary: { DEFAULT: '#2563eb', hover: '#1d4ed8', light: '#eff6ff' } }
                }
            }
        };
    </script>
    <style>body { font-family: 'Plus Jakarta Sans', sans-serif; }</style>
</head>
<body class="bg-slate-50 text-slate-800 flex flex-col min-h-screen">
    <header class="sticky top-0 z-40 bg-white border-b border-slate-200 shadow-sm">
        <div class="relative max-w-7xl mx-auto px-4 sm:px-6 h-16 flex items-center justify-between">
            <a href="<?= htmlspecialchars($flightSearchUrl, ENT_QUOTES, 'UTF-8') ?>" class="text-sm font-semibold text-slate-700 hover:text-primary flex items-center gap-2">
                <i class="fa-solid fa-arrow-left text-xs"></i><span>Quay lại tìm chuyến</span>
            </a>
            <div class="absolute left-1/2 hidden -translate-x-1/2 items-center gap-2 whitespace-nowrap text-xs font-medium text-slate-400 xl:gap-3 xl:text-sm xl:flex">
                <span class="text-emerald-600">1. Chọn chuyến</span><span>—</span>
                <span class="text-emerald-600">2. Hành khách</span><span>—</span>
                <span class="text-emerald-600">3. Dịch vụ</span><span>—</span>
                <span class="text-primary font-bold">4. Thanh toán</span><span>—</span><span>5. Hoàn tất</span>
            </div>
            <?php if ($canPay): ?>
                <div class="flex items-center gap-2 px-3.5 py-1.5 rounded-full bg-amber-50 border border-amber-200 text-amber-800 text-xs font-bold">
                    <i class="fa-regular fa-clock text-amber-600"></i>
                    <span id="holdCountdown" data-seconds-left="<?= (int)$booking['seconds_left'] ?>">15:00</span>
                </div>
            <?php endif; ?>
        </div>
    </header>

    <main class="max-w-3xl mx-auto px-4 sm:px-6 py-10 flex-1 w-full">
        <div class="text-center mb-8">
            <h1 class="text-2xl sm:text-3xl font-black text-slate-900">Thanh toán vé máy bay</h1>
            <?php if ($booking): ?><p class="text-sm text-slate-500 mt-1">Mã đặt chỗ: <strong class="text-primary"><?= htmlspecialchars($bookingCode) ?></strong></p><?php endif; ?>
        </div>

        <?php if ($payError !== ''): ?>
            <div class="mb-6 flex items-start gap-3 bg-red-50 border border-red-200 text-red-700 rounded-2xl px-5 py-4 text-sm">
                <i class="fa-solid fa-circle-exclamation text-red-500 mt-0.5"></i>
                <div><p class="font-bold">Không thể tiếp tục thanh toán</p><p class="text-xs mt-0.5"><?= htmlspecialchars($payError) ?></p></div>
            </div>
        <?php endif; ?>

        <?php if ($booking): ?>
            <div class="bg-white p-6 sm:p-8 rounded-3xl border border-slate-200 shadow-xl space-y-6">
                <div class="bg-slate-50 p-5 rounded-2xl border border-slate-100 flex flex-col sm:flex-row justify-between gap-4 text-sm">
                    <div>
                        <p class="font-extrabold text-slate-900 text-base"><?= htmlspecialchars($passengerName) ?></p>
                        <p class="text-xs text-slate-500 mt-1"><?= htmlspecialchars($passengerPhone) ?> · <?= htmlspecialchars($passengerEmail) ?></p>
                        <p class="text-xs text-slate-600 mt-1">
                            <?= htmlspecialchars($airline) ?> (<?= htmlspecialchars($flightCode) ?>) · <?= htmlspecialchars($fromCity) ?> → <?= htmlspecialchars($toCity) ?>
                            · <?= $seatSummary !== '' ? 'Ghế ' . htmlspecialchars($seatSummary) : 'Chưa chọn ghế — chọn ghế khi check-in' ?>
                        </p>
                        <p class="text-xs text-slate-500 mt-1"><?= $passengerCount ?> hành khách · <?= htmlspecialchars(date('d/m/Y H:i', strtotime($booking['depart_at']))) ?></p>
                    </div>
                    <div class="sm:text-right shrink-0">
                        <p class="text-xs text-slate-400 font-semibold uppercase">Tổng thanh toán</p>
                        <p class="text-2xl font-black text-primary"><?= number_format($total) ?>đ</p>
                        <?php if ($canPay): ?>
                            <p class="text-[11px] text-slate-500">Hạn giữ chỗ: <?= htmlspecialchars(date('H:i:s d/m/Y', strtotime($expiresAt))) ?></p>
                        <?php else: ?>
                            <p class="text-[11px] font-bold text-rose-600">Trạng thái: <?= htmlspecialchars($booking['payment_status']) ?></p>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if ($canPay): ?>
                    <div>
                        <h3 class="text-xs font-bold text-slate-900 uppercase tracking-wider mb-3">Chọn phương thức thanh toán demo</h3>
                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 text-sm">
                            <label class="border-2 border-primary bg-blue-50/40 p-4 rounded-2xl flex flex-col items-center gap-2 cursor-pointer text-center">
                                <input type="radio" name="payType" checked class="accent-primary"><i class="fa-solid fa-qrcode text-2xl text-primary"></i><span class="font-bold text-xs">VietQR</span>
                            </label>
                            <label class="border border-slate-200 p-4 rounded-2xl flex flex-col items-center gap-2 cursor-pointer text-center">
                                <input type="radio" name="payType" class="accent-primary"><i class="fa-regular fa-credit-card text-2xl text-slate-600"></i><span class="font-bold text-xs">Thẻ ATM / Visa</span>
                            </label>
                            <label class="border border-slate-200 p-4 rounded-2xl flex flex-col items-center gap-2 cursor-pointer text-center">
                                <input type="radio" name="payType" class="accent-primary"><i class="fa-solid fa-wallet text-2xl text-slate-600"></i><span class="font-bold text-xs">Ví điện tử</span>
                            </label>
                        </div>
                    </div>
                    <div class="text-center py-4 border-t border-slate-100">
                        <p class="text-xs font-semibold text-slate-600 mb-3">Mã QR thanh toán mô phỏng</p>
                        <div class="inline-block p-4 bg-white border border-slate-200 rounded-3xl shadow-sm">
                            <img src="https://api.qrserver.com/v1/create-qr-code/?size=180x180&data=BOOKING_<?= urlencode($bookingCode) ?>_<?= $total ?>" alt="QR Code demo" class="w-44 h-44 mx-auto">
                        </div>
                        <p class="text-xs text-slate-500 mt-2">Nội dung chuyển khoản: <strong><?= htmlspecialchars($bookingCode) ?></strong></p>
                        <p class="text-[11px] text-slate-400 mt-1">Đây là thanh toán demo, không phát sinh giao dịch thật.</p>
                    </div>
                    <form method="POST" class="space-y-3">
                        <input type="hidden" name="confirm_payment" value="1">
                        <input type="hidden" name="bookingCode" value="<?= htmlspecialchars($bookingCode) ?>">
                        <label class="block text-xs font-semibold text-slate-600">Phương thức thanh toán
                            <select name="payMethod" class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm">
                                <option value="VietQR">VietQR</option><option value="Thẻ">Thẻ</option><option value="ATM">ATM</option><option value="Ví điện tử">Ví điện tử</option>
                            </select>
                        </label>
                        <button type="submit" class="w-full py-4 rounded-2xl bg-emerald-600 hover:bg-emerald-700 text-white font-bold shadow-lg">
                            <i class="fa-solid fa-check mr-1"></i> Tôi đã hoàn tất thanh toán demo
                        </button>
                    </form>
                <?php else: ?>
                    <a href="<?= htmlspecialchars($flightSearchUrl, ENT_QUOTES, 'UTF-8') ?>" class="block w-full py-3 rounded-2xl bg-primary text-white text-center font-bold">Tìm chuyến bay khác</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </main>

    <footer class="mt-auto bg-white border-t border-slate-200">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 py-8 text-center text-xs text-slate-400">
            &copy; <?= date('Y') ?> Booking Online. Hệ thống đặt vé máy bay trực tuyến.
        </div>
    </footer>
    <?php if ($canPay): ?>
    <script>
        const countdown = document.getElementById('holdCountdown');
        const expiresAt = Date.now() + Number(countdown.dataset.secondsLeft) * 1000;
        function updateHoldCountdown() {
            const secondsLeft = Math.max(0, Math.floor((expiresAt - Date.now()) / 1000));
            countdown.textContent = `${String(Math.floor(secondsLeft / 60)).padStart(2, '0')}:${String(secondsLeft % 60).padStart(2, '0')}`;
            if (secondsLeft <= 0) {
                window.location.reload();
                return;
            }
            setTimeout(updateHoldCountdown, 1000);
        }
        updateHoldCountdown();
    </script>
    <?php endif; ?>
</body>
</html>
