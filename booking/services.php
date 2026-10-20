<?php
session_start();
require_once __DIR__ . '/../api/config/database.php';

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $bookingCode = strtoupper(trim((string)($_POST['bookingCode'] ?? '')));
    $passengers = $_POST['passengers'] ?? [];
    $passengers = is_array($passengers) ? array_values($passengers) : [];
    $flightId = (int)($_POST['flightId'] ?? 0);
    $contactName = trim((string)($_POST['passengerName'] ?? ''));
    $contactEmail = trim((string)($_POST['passengerEmail'] ?? ''));
    $contactPhone = trim((string)($_POST['passengerPhone'] ?? ''));

    if (preg_match('/^BK[A-F0-9]{6}$/', $bookingCode) && $flightId > 0 &&
        count($passengers) >= 1 && count($passengers) <= 9) {
        $_SESSION['booking_drafts'][$bookingCode] = [
            'flightId' => $flightId,
            'cabin' => trim((string)($_POST['cabin'] ?? 'Hạng Phổ thông')),
            'passengers' => $passengers,
            'contactName' => $contactName,
            'contactEmail' => $contactEmail,
            'contactPhone' => $contactPhone,
            'from' => trim((string)($_POST['from'] ?? '')),
            'to' => trim((string)($_POST['to'] ?? '')),
            'departDate' => trim((string)($_POST['departDate'] ?? '')),
            'adults' => max(1, (int)($_POST['adults'] ?? 1)),
            'children' => max(0, (int)($_POST['children'] ?? 0)),
            'infants' => max(0, (int)($_POST['infants'] ?? 0)),
        ];
    }

    if (!preg_match('/^BK[A-F0-9]{6}$/', $bookingCode) || $flightId <= 0 ||
        count($passengers) < 1 || count($passengers) > 9 ||
        $contactName === '' || !filter_var($contactEmail, FILTER_VALIDATE_EMAIL) || $contactPhone === '') {
        http_response_code(422);
        $error = 'Thông tin hành khách không hợp lệ. Vui lòng quay lại và kiểm tra.';
    } else {
        foreach ($passengers as $passenger) {
            if (!is_array($passenger) || trim((string)($passenger['firstName'] ?? '')) === '' ||
                trim((string)($passenger['lastName'] ?? '')) === '') {
                http_response_code(422);
                $error = 'Vui lòng nhập họ và tên cho tất cả hành khách.';
                break;
            }
        }
    }

    if ($error === '') {
        header('Location: /WebsiteDatVe/booking/services.php?code=' . urlencode($bookingCode));
        exit;
    }
}

$bookingCode = strtoupper(trim((string)($_GET['code'] ?? $_POST['bookingCode'] ?? '')));
$draft = $_SESSION['booking_drafts'][$bookingCode] ?? null;
if (!$draft && $error === '') {
    header('Location: /WebsiteDatVe/flights/flights.php');
    exit;
}

$flight = null;
$seatCodes = [];
$seatMap = [];
$seatRows = [];
$seatClass = stripos((string)($draft['cabin'] ?? ''), 'thương gia') !== false ? 'BUSINESS' : 'ECONOMY';
if ($draft && !$conn) {
    http_response_code(500);
    $error = 'Không thể kết nối cơ sở dữ liệu để tải dịch vụ chuyến bay. Vui lòng thử lại.';
}
if ($draft && $conn) {
    $flightId = (int)$draft['flightId'];
    $stmt = mysqli_prepare($conn,
        "SELECT f.*, dep.city AS from_city, arr.city AS to_city
         FROM flights f
         JOIN airports dep ON f.from_airport = dep.code
         JOIN airports arr ON f.to_airport = arr.code
         WHERE f.id = ? LIMIT 1"
    );
    mysqli_stmt_bind_param($stmt, 'i', $flightId);
    mysqli_stmt_execute($stmt);
    $flight = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    if ($flight) {
        $seatStmt = mysqli_prepare($conn,
            'SELECT seat_code, seat_class, is_booked FROM flight_seats
             WHERE flight_id = ?
             ORDER BY CAST(LEFT(seat_code, LENGTH(seat_code) - 1) AS UNSIGNED), seat_code'
        );
        mysqli_stmt_bind_param($seatStmt, 'i', $flightId);
        mysqli_stmt_execute($seatStmt);
        $seatResult = mysqli_stmt_get_result($seatStmt);
        while ($seat = mysqli_fetch_assoc($seatResult)) {
            if (!preg_match('/^(\d+)([A-F])$/', $seat['seat_code'], $matches)) {
                continue;
            }
            $rowNumber = (int)$matches[1];
            $seatMap[$rowNumber][$matches[2]] = [
                'code' => $seat['seat_code'],
                'class' => $seat['seat_class'],
                'booked' => (int)$seat['is_booked'] === 1,
                'price' => $rowNumber >= 4 && $rowNumber <= 9 ? 100000 : ($rowNumber >= 10 && $rowNumber <= 13 ? 150000 : 0),
                'type' => $rowNumber >= 4 && $rowNumber <= 9 ? 'Gần cửa ra' : ($rowNumber >= 10 && $rowNumber <= 13 ? 'Chỗ để chân rộng' : ($rowNumber <= 2 ? 'Thương gia' : 'Tiêu chuẩn')),
            ];
            if ($seat['seat_class'] === $seatClass && (int)$seat['is_booked'] === 0) {
                $seatCodes[] = $seat['seat_code'];
            }
        }
        mysqli_stmt_close($seatStmt);
        $seatRows = array_keys($seatMap);
        sort($seatRows, SORT_NUMERIC);
    }
}
if ($draft && $conn && !$flight && $error === '') {
    header('Location: /WebsiteDatVe/flights/flights.php');
    exit;
}

$passengerCount = count($draft['passengers'] ?? []);
$basePrice = $flight ? (int)($seatClass === 'BUSINESS' ? $flight['price_business'] : $flight['price_economy']) : 0;
$taxPrice = (int)($flight['tax_price'] ?? 0);
$baseTotal = $basePrice * $passengerCount;
$taxTotal = $taxPrice * $passengerCount;
$returnUrl = '/WebsiteDatVe/booking/booking.php?' . http_build_query([
    'flightId' => (int)($draft['flightId'] ?? 0),
    'cabin' => $draft['cabin'] ?? 'Hạng Phổ thông',
    'from' => $draft['from'] ?? ($flight['from_airport'] ?? ''),
    'to' => $draft['to'] ?? ($flight['to_airport'] ?? ''),
    'departDate' => $draft['departDate'] ?? date('Y-m-d', strtotime($flight['depart_at'] ?? 'now')),
    'adults' => (int)($draft['adults'] ?? $passengerCount),
    'children' => (int)($draft['children'] ?? 0),
    'infants' => (int)($draft['infants'] ?? 0),
    'bookingCode' => $bookingCode,
]);
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dịch vụ chuyến bay - Booking Online</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <script>
        tailwind.config = { theme: { extend: { fontFamily: { sans: ['"Plus Jakarta Sans"', 'sans-serif'] }, colors: { primary: { DEFAULT: '#2563eb', hover: '#1d4ed8' } } } } };
    </script>
    <style>body { font-family: 'Plus Jakarta Sans', sans-serif; }</style>
</head>
<body class="bg-slate-50 text-slate-800 flex min-h-screen flex-col">
<header class="sticky top-0 z-40 border-b border-slate-200 bg-white">
    <div class="relative mx-auto flex h-16 max-w-7xl items-center justify-between px-4 sm:px-6">
        <a href="<?= htmlspecialchars($returnUrl, ENT_QUOTES, 'UTF-8') ?>" class="flex items-center gap-2 text-sm font-semibold text-slate-700 hover:text-primary">
            <i class="fa-solid fa-arrow-left text-xs"></i> Quay lại thông tin hành khách
        </a>
        <nav class="absolute left-1/2 hidden -translate-x-1/2 items-center gap-3 whitespace-nowrap text-sm font-medium xl:flex">
            <span class="text-emerald-600">1. Chọn chuyến</span><span class="text-slate-300">—</span>
            <span class="text-emerald-600">2. Hành khách</span><span class="text-slate-300">—</span>
            <span class="font-bold text-primary">3. Dịch vụ</span><span class="text-slate-300">—</span>
            <span class="text-slate-400">4. Thanh toán</span><span class="text-slate-300">—</span>
            <span class="text-slate-400">5. Hoàn tất</span>
        </nav>
    </div>
</header>
<main class="mx-auto w-full max-w-7xl flex-1 px-4 py-8 sm:px-6">
    <div class="mb-7">
        <h1 class="text-2xl font-black text-slate-900 sm:text-3xl">Dịch vụ bổ sung</h1>
        <p class="mt-1 text-sm text-slate-500">Tùy chọn hành lý, suất ăn và ghế ngồi cho chuyến bay của bạn.</p>
    </div>
    <?php if ($error !== ''): ?>
        <div class="mb-5 rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-700"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>
    <?php if ($draft && $flight): ?>
    <form method="POST" action="/WebsiteDatVe/payment/payment.php" class="grid items-start gap-6 lg:grid-cols-[1fr_360px]">
        <input type="hidden" name="flightId" value="<?= (int)$flight['id'] ?>">
        <input type="hidden" name="bookingCode" value="<?= htmlspecialchars($bookingCode, ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="cabin" value="<?= htmlspecialchars($draft['cabin'], ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="from" value="<?= htmlspecialchars($draft['from'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="to" value="<?= htmlspecialchars($draft['to'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="departDate" value="<?= htmlspecialchars($draft['departDate'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="adults" value="<?= (int)($draft['adults'] ?? $passengerCount) ?>">
        <input type="hidden" name="children" value="<?= (int)($draft['children'] ?? 0) ?>">
        <input type="hidden" name="infants" value="<?= (int)($draft['infants'] ?? 0) ?>">
        <input type="hidden" name="passengerName" value="<?= htmlspecialchars($draft['contactName'], ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="passengerEmail" value="<?= htmlspecialchars($draft['contactEmail'], ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="passengerPhone" value="<?= htmlspecialchars($draft['contactPhone'], ENT_QUOTES, 'UTF-8') ?>">
        <?php foreach ($draft['passengers'] as $index => $passenger): ?>
            <?php foreach (['title', 'firstName', 'lastName', 'dob', 'idNumber', 'nationality', 'passengerType'] as $field): ?>
                <?php if (isset($passenger[$field])): ?>
                    <input type="hidden" name="passengers[<?= $index ?>][<?= $field ?>]" value="<?= htmlspecialchars((string)$passenger[$field], ENT_QUOTES, 'UTF-8') ?>">
                <?php endif; ?>
            <?php endforeach; ?>
        <?php endforeach; ?>
        <input type="hidden" name="discountPrice" value="0">

        <div class="space-y-5">
            <div class="mb-2">
                <h2 class="text-lg font-extrabold text-slate-900">Dịch vụ theo từng hành khách</h2>
                <p class="mt-1 text-xs text-slate-500">Mỗi người có thể chọn hành lý, suất ăn, ghế và bảo hiểm riêng.</p>
            </div>
            <?php foreach ($draft['passengers'] as $index => $passenger): ?>
                <section class="passenger-service-card overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm" data-passenger-index="<?= $index ?>">
                    <div class="border-b border-blue-100 bg-blue-50 px-5 py-4">
                        <h3 class="font-extrabold text-slate-900">
                            <i class="fa-solid fa-user mr-2 text-primary"></i>
                            Hành khách <?= $index + 1 ?>: <?= htmlspecialchars(trim(($passenger['firstName'] ?? '') . ' ' . ($passenger['lastName'] ?? '')), ENT_QUOTES, 'UTF-8') ?>
                        </h3>
                    </div>
                    <div class="space-y-6 p-5 sm:p-6">
                        <div>
                            <h4 class="mb-3 flex items-center gap-2 font-bold text-slate-800"><i class="fa-solid fa-suitcase-rolling text-primary"></i> Hành lý ký gửi thêm</h4>
                            <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                                <?php foreach ([0 => 'Không thêm — Miễn phí', 15 => 'Thêm 15kg — 220.000đ', 20 => 'Thêm 20kg — 290.000đ', 30 => 'Thêm 30kg — 450.000đ'] as $weight => $label): ?>
                                    <label class="service-option cursor-pointer rounded-2xl border border-slate-200 p-3">
                                        <input type="radio" name="passengers[<?= $index ?>][baggageWeight]" value="<?= $weight ?>" <?= $weight === 0 ? 'checked' : '' ?> class="baggage-choice accent-primary">
                                        <span class="ml-1 text-sm font-semibold"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div>
                            <h4 class="mb-3 flex items-center gap-2 font-bold text-slate-800"><i class="fa-solid fa-utensils text-primary"></i> Suất ăn</h4>
                            <div class="grid gap-3 sm:grid-cols-3">
                                <?php foreach (['none' => 'Không đặt trước', 'chicken' => 'Cơm gà + nước ép — 65.000đ', 'vegetarian' => 'Suất ăn chay — 55.000đ'] as $meal => $label): ?>
                                    <label class="service-option cursor-pointer rounded-2xl border border-slate-200 p-3">
                                        <input type="radio" name="passengers[<?= $index ?>][mealOption]" value="<?= $meal ?>" <?= $meal === 'none' ? 'checked' : '' ?> class="meal-choice accent-primary">
                                        <span class="ml-1 text-sm font-semibold"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div class="flex flex-col gap-3 border-t border-slate-100 pt-4 sm:flex-row sm:items-center sm:justify-between">
                            <label class="flex cursor-pointer items-center gap-2 text-sm font-semibold text-slate-700">
                                <input type="checkbox" name="passengers[<?= $index ?>][insurance]" value="1" checked class="insurance-choice accent-primary">
                                Bảo hiểm du lịch — 89.000đ
                            </label>
                        </div>
                        <details class="seat-picker border-t border-slate-100 pt-4" data-passenger-index="<?= $index ?>">
                            <summary class="flex cursor-pointer list-none items-center justify-between gap-3 text-sm font-bold text-slate-700">
                                <span><i class="fa-solid fa-chair mr-2 text-primary"></i>Chọn trước chỗ ngồi</span>
                                <span class="flex items-center gap-2">
                                    <span class="seat-assignment-label rounded-lg bg-slate-100 px-3 py-1.5 font-semibold text-slate-500">Chưa chọn</span>
                                    <i class="seat-picker-arrow fa-solid fa-chevron-down text-xs text-slate-400 transition-transform"></i>
                                </span>
                                <input type="hidden" name="passengers[<?= $index ?>][seatCode]" value="" class="seat-select">
                            </summary>
                            <p class="mt-2 text-xs text-slate-500">Không bắt buộc. Ghế tiêu chuẩn miễn phí; ghế ưu tiên sẽ hiển thị phụ phí trước khi chọn.</p>
                            <div class="seat-map-container mt-4"></div>
                        </details>
                    </div>
                </section>
            <?php endforeach; ?>
            <section id="seatMapPanel" class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6" hidden>
                <div class="mb-5">
                    <h3 class="flex items-center gap-2 text-lg font-extrabold text-slate-900"><i class="fa-solid fa-chair text-primary"></i> Sơ đồ khoang máy bay</h3>
                    <p class="mt-1 text-xs text-slate-500">Chọn ghế trống cho hành khách này. Ghế đã đặt hoặc thuộc khoang khác không thể chọn.</p>
                </div>
                <div class="mb-4 flex flex-wrap gap-x-5 gap-y-2 text-xs font-semibold text-slate-600">
                    <span class="flex items-center gap-2"><i class="inline-block h-4 w-4 rounded border border-slate-300 bg-white"></i> Trống</span>
                    <span class="flex items-center gap-2"><i class="inline-block h-4 w-4 rounded bg-primary"></i> Đang chọn</span>
                    <span class="flex items-center gap-2"><i class="inline-block h-4 w-4 rounded bg-slate-300"></i> Đã đặt / không chọn được</span>
                </div>
                <div class="mb-4 flex flex-wrap gap-x-4 gap-y-2 text-xs font-semibold text-slate-600">
                    <span class="flex items-center gap-2"><i class="inline-block h-4 w-4 rounded border border-slate-300 bg-white"></i> Ghế tiêu chuẩn: Miễn phí</span>
                    <span class="flex items-center gap-2"><i class="inline-block h-4 w-4 rounded border border-amber-200 bg-amber-50"></i> Hàng 4–9: 100.000đ (gần cửa ra)</span>
                    <span class="flex items-center gap-2"><i class="inline-block h-4 w-4 rounded border border-violet-200 bg-violet-50"></i> Hàng 10–13: 150.000đ (để chân rộng)</span>
                </div>
                <div class="mx-auto max-w-md rounded-[3rem] border-2 border-slate-200 bg-slate-50 px-4 pb-6 pt-8 sm:px-8">
                    <div class="mx-auto mb-5 flex h-14 max-w-[14rem] items-center justify-center rounded-t-[50%] border-t-4 border-slate-300 bg-slate-100 text-xs font-bold uppercase tracking-[0.2em] text-slate-400">Phía trước máy bay</div>
                    <div class="mb-2 grid grid-cols-[2rem_repeat(3,minmax(2.5rem,1fr))_1.25rem_repeat(3,minmax(2.5rem,1fr))] items-center gap-x-2 text-center text-[11px] font-extrabold text-slate-400 sm:gap-x-3">
                        <span></span><?php foreach (['A', 'B', 'C'] as $letter): ?><span><?= $letter ?></span><?php endforeach; ?><span></span><?php foreach (['D', 'E', 'F'] as $letter): ?><span><?= $letter ?></span><?php endforeach; ?>
                    </div>
                    <div class="space-y-2">
                        <?php foreach ($seatRows as $rowNumber): ?>
                            <div class="grid grid-cols-[2rem_repeat(3,minmax(2.5rem,1fr))_1.25rem_repeat(3,minmax(2.5rem,1fr))] items-center gap-x-2 text-center sm:gap-x-3">
                                <span class="text-xs font-bold text-slate-400"><?= $rowNumber ?></span>
                                <?php foreach (['A', 'B', 'C'] as $letter): ?>
                                    <?php $seat = $seatMap[$rowNumber][$letter] ?? null; $isSelectable = $seat && !$seat['booked'] && $seat['class'] === $seatClass; ?>
                                                    <?php if ($seat): ?>
                                                        <button type="button" class="seat-map-seat <?= $isSelectable ? ($seat['price'] >= 150000 ? 'border-violet-200 bg-violet-50 text-violet-800 hover:border-violet-400' : ($seat['price'] > 0 ? 'border-amber-200 bg-amber-50 text-amber-800 hover:border-amber-400' : 'border-slate-300 bg-white text-slate-700 hover:border-primary hover:bg-blue-50')) : 'cursor-not-allowed border-slate-300 bg-slate-300 text-slate-500' ?> flex h-9 items-center justify-center rounded-lg border text-[11px] font-extrabold transition sm:h-10" data-seat-code="<?= htmlspecialchars($seat['code'], ENT_QUOTES, 'UTF-8') ?>" data-seat-available="<?= $isSelectable ? '1' : '0' ?>" data-seat-price="<?= $seat['price'] ?>" data-seat-type="<?= htmlspecialchars($seat['type'], ENT_QUOTES, 'UTF-8') ?>" title="<?= $isSelectable ? $seat['type'] . ($seat['price'] ? ' — ' . number_format($seat['price']) . 'đ' : ' — Miễn phí') : ($seat['booked'] ? 'Ghế đã được đặt' : 'Ghế thuộc khoang khác') ?>" aria-label="Ghế <?= htmlspecialchars($seat['code'], ENT_QUOTES, 'UTF-8') ?>, <?= htmlspecialchars($seat['type'], ENT_QUOTES, 'UTF-8') ?>, <?= $seat['price'] ? number_format($seat['price']) . ' đồng' : 'miễn phí' ?><?= $isSelectable ? '' : ', không thể chọn' ?>" <?= $isSelectable ? '' : 'disabled' ?>><?= htmlspecialchars($letter, ENT_QUOTES, 'UTF-8') ?></button>
                                    <?php else: ?><span></span><?php endif; ?>
                                <?php endforeach; ?>
                                <span class="text-[10px] text-slate-300"><i class="fa-solid fa-person"></i></span>
                                <?php foreach (['D', 'E', 'F'] as $letter): ?>
                                    <?php $seat = $seatMap[$rowNumber][$letter] ?? null; $isSelectable = $seat && !$seat['booked'] && $seat['class'] === $seatClass; ?>
                                    <?php if ($seat): ?>
                                        <button type="button" class="seat-map-seat <?= $isSelectable ? ($seat['price'] >= 150000 ? 'border-violet-200 bg-violet-50 text-violet-800 hover:border-violet-400' : ($seat['price'] > 0 ? 'border-amber-200 bg-amber-50 text-amber-800 hover:border-amber-400' : 'border-slate-300 bg-white text-slate-700 hover:border-primary hover:bg-blue-50')) : 'cursor-not-allowed border-slate-300 bg-slate-300 text-slate-500' ?> flex h-9 items-center justify-center rounded-lg border text-[11px] font-extrabold transition sm:h-10" data-seat-code="<?= htmlspecialchars($seat['code'], ENT_QUOTES, 'UTF-8') ?>" data-seat-available="<?= $isSelectable ? '1' : '0' ?>" data-seat-price="<?= $seat['price'] ?>" data-seat-type="<?= htmlspecialchars($seat['type'], ENT_QUOTES, 'UTF-8') ?>" title="<?= $isSelectable ? $seat['type'] . ($seat['price'] ? ' — ' . number_format($seat['price']) . 'đ' : ' — Miễn phí') : ($seat['booked'] ? 'Ghế đã được đặt' : 'Ghế thuộc khoang khác') ?>" aria-label="Ghế <?= htmlspecialchars($seat['code'], ENT_QUOTES, 'UTF-8') ?>, <?= htmlspecialchars($seat['type'], ENT_QUOTES, 'UTF-8') ?>, <?= $seat['price'] ? number_format($seat['price']) . ' đồng' : 'miễn phí' ?><?= $isSelectable ? '' : ', không thể chọn' ?>" <?= $isSelectable ? '' : 'disabled' ?>><?= htmlspecialchars($letter, ENT_QUOTES, 'UTF-8') ?></button>
                                    <?php else: ?><span></span><?php endif; ?>
                                <?php endforeach; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="mx-auto mt-5 h-8 max-w-[14rem] rounded-b-[50%] border-b-4 border-slate-300"></div>
                </div>
                <?php if (!$seatRows): ?><p class="mt-4 text-center text-xs font-semibold text-amber-700">Chuyến bay chưa có sơ đồ ghế để chọn. Bạn vẫn có thể tiếp tục và chọn ghế khi check-in.</p>
                <?php elseif (!$seatCodes): ?><p class="mt-4 text-center text-xs font-semibold text-amber-700">Không còn ghế trống trong khoang này. Bạn vẫn có thể tiếp tục và chọn ghế khi check-in.</p><?php endif; ?>
            </section>
            <div id="seatMapParking"></div>
        </div>

        <aside class="sticky top-20 space-y-4 rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
            <h2 class="border-b border-slate-100 pb-3 text-xs font-black uppercase tracking-wider text-slate-600">Tóm tắt chi phí</h2>
            <div class="space-y-3 text-sm">
                <div class="flex justify-between gap-3"><span>Vé cơ bản (<?= $passengerCount ?> khách)</span><strong><?= number_format($baseTotal) ?>đ</strong></div>
                <div class="flex justify-between gap-3"><span>Thuế, phí sân bay</span><strong><?= number_format($taxTotal) ?>đ</strong></div>
                <div class="space-y-3 border-t border-slate-100 pt-3" id="passengerServiceSummary"></div>
                <div class="flex justify-between gap-3"><span>Tổng dịch vụ</span><strong id="serviceTotal"><?= number_format($passengerCount * 89000) ?>đ</strong></div>
            </div>
            <div class="flex items-center justify-between border-t border-slate-100 pt-4">
                <span class="text-sm font-bold">Tổng tạm tính</span>
                <strong class="text-xl text-primary" id="grandTotal"><?= number_format($baseTotal + $taxTotal + $passengerCount * 89000) ?>đ</strong>
            </div>
            <button type="submit" class="w-full rounded-2xl bg-primary py-3.5 text-sm font-extrabold text-white shadow-lg shadow-blue-500/20 hover:bg-primary-hover">
                Tiếp tục thanh toán <i class="fa-solid fa-arrow-right ml-1"></i>
            </button>
        </aside>
    </form>
    <?php endif; ?>
</main>
<script>
    const baseAndTax = <?= $baseTotal + $taxTotal ?>;
    const baggagePrices = { 0: 0, 15: 220000, 20: 290000, 30: 450000 };
    const mealDetails = {
        none: { name: 'Không đặt trước', price: 0 },
        chicken: { name: 'Cơm gà Hội An + Nước ép', price: 65000 },
        vegetarian: { name: 'Suất ăn chay', price: 55000 }
    };
    const formatVnd = amount => `${amount.toLocaleString('vi-VN')}đ`;
    function refreshPrice() {
        let serviceTotal = 0;
        const summaries = [];
        document.querySelectorAll('.seat-select').forEach((seatSelect, index) => {
            const passenger = seatSelect.closest('.passenger-service-card');
            const baggage = passenger.querySelector('.baggage-choice:checked');
            const meal = passenger.querySelector('.meal-choice:checked');
            const insurance = passenger.querySelector('.insurance-choice').checked;
            const baggageWeight = Number(baggage.value);
            const baggagePrice = baggagePrices[baggageWeight];
            const mealDetail = mealDetails[meal.value];
            const insurancePrice = insurance ? 89000 : 0;
            const selectedSeat = seatButtons.find(button => button.dataset.seatCode === seatSelect.value);
            const seatPrice = selectedSeat ? Number(selectedSeat.dataset.seatPrice) : 0;
            const passengerTotal = baggagePrice + mealDetail.price + insurancePrice + seatPrice;
            serviceTotal += passengerTotal;
            const passengerName = passenger.querySelector('h3').textContent.trim();
            const seatLabel = passenger.querySelector('.seat-assignment-label');
            if (seatSelect.value && selectedSeat) {
                seatLabel.textContent = `${seatSelect.value} · ${selectedSeat.dataset.seatType} · ${seatPrice ? formatVnd(seatPrice) : 'Miễn phí'}`;
            } else {
                seatLabel.textContent = 'Chưa chọn';
            }
            seatLabel.classList.toggle('bg-blue-100', Boolean(seatSelect.value));
            seatLabel.classList.toggle('text-primary', Boolean(seatSelect.value));
            seatLabel.classList.toggle('bg-slate-100', !seatSelect.value);
            seatLabel.classList.toggle('text-slate-500', !seatSelect.value);
            const summary = document.createElement('div');
            summary.className = 'flex justify-between gap-3 text-xs';
            const details = document.createElement('span');
            details.className = 'min-w-0';
            const name = document.createElement('strong');
            name.className = 'block';
            name.textContent = passengerName;
            const services = document.createElement('span');
            services.className = 'text-slate-500';
            const selectedServices = [];
            if (baggageWeight) selectedServices.push(`${baggageWeight}kg hành lý`);
            if (mealDetail.price) selectedServices.push(mealDetail.name);
            if (insurance) selectedServices.push('Bảo hiểm');
            if (selectedSeat) selectedServices.push(`${seatSelect.value} ${seatPrice ? formatVnd(seatPrice) : 'miễn phí'}`);
            services.textContent = selectedServices.join(' · ') || 'Không chọn dịch vụ';
            details.append(name, services);
            const amount = document.createElement('strong');
            amount.className = 'shrink-0';
            amount.textContent = formatVnd(passengerTotal);
            summary.append(details, amount);
            summaries.push(summary);
        });
        document.getElementById('passengerServiceSummary').replaceChildren(...summaries);
        document.getElementById('serviceTotal').textContent = formatVnd(serviceTotal);
        document.getElementById('grandTotal').textContent = formatVnd(baseAndTax + serviceTotal);
    }
    document.querySelectorAll('.baggage-choice, .meal-choice, .insurance-choice').forEach(input => input.addEventListener('change', event => {
        document.querySelectorAll(`input[name="${event.target.name}"]`).forEach(option => {
            option.closest('.service-option')?.classList.toggle('border-2', option.checked);
            option.closest('.service-option')?.classList.toggle('border-primary', option.checked);
            option.closest('.service-option')?.classList.toggle('bg-blue-50/40', option.checked);
        });
        refreshPrice();
    }));
    document.querySelectorAll('.service-option input:checked').forEach(input => {
        input.closest('.service-option').classList.add('border-2', 'border-primary', 'bg-blue-50/40');
    });
    const seatButtons = [...document.querySelectorAll('.seat-map-seat')];
    const passengerSeatInputs = [...document.querySelectorAll('.seat-select')];
    let activePassengerIndex = 0;
    const seatMapPanel = document.getElementById('seatMapPanel');
    const seatMapParking = document.getElementById('seatMapParking');
    const seatPickers = [...document.querySelectorAll('.seat-picker')];
    seatPickers.forEach(picker => picker.addEventListener('toggle', () => {
        picker.querySelector('.seat-picker-arrow').classList.toggle('rotate-180', picker.open);
        if (picker.open) {
            activePassengerIndex = Number(picker.dataset.passengerIndex);
            seatPickers.forEach(other => {
                if (other !== picker) other.open = false;
            });
            picker.querySelector('.seat-map-container').append(seatMapPanel);
            seatMapPanel.hidden = false;
            updateSeatMap();
        } else if (!seatPickers.some(other => other.open)) {
            seatMapPanel.hidden = true;
            seatMapParking.append(seatMapPanel);
        }
    }));
    function updateSeatMap() {
        seatButtons.forEach(button => {
            const seatCode = button.dataset.seatCode;
            const assignedIndex = passengerSeatInputs.findIndex(input => input.value === seatCode);
            const assignedToActive = assignedIndex === activePassengerIndex;
            const assignedToOther = assignedIndex !== -1 && !assignedToActive;
            const activeSeat = assignedToActive;
            const unavailable = button.dataset.seatAvailable !== '1' || assignedToOther;
            button.disabled = unavailable;
            button.setAttribute('aria-pressed', String(activeSeat));
            button.classList.toggle('border-primary', activeSeat);
            button.classList.toggle('bg-primary', activeSeat);
            button.classList.toggle('text-white', activeSeat);
            button.classList.toggle('cursor-not-allowed', unavailable);
            button.classList.toggle('bg-slate-300', unavailable);
            button.classList.toggle('text-slate-500', unavailable);
            const availableAndUnselected = !unavailable && !activeSeat;
            const seatPrice = Number(button.dataset.seatPrice);
            button.classList.toggle('border-slate-300', unavailable || (availableAndUnselected && seatPrice === 0));
            button.classList.toggle('bg-white', availableAndUnselected && seatPrice === 0);
            button.classList.toggle('text-slate-700', availableAndUnselected && seatPrice === 0);
            button.classList.toggle('border-amber-200', availableAndUnselected && seatPrice === 100000);
            button.classList.toggle('bg-amber-50', availableAndUnselected && seatPrice === 100000);
            button.classList.toggle('text-amber-800', availableAndUnselected && seatPrice === 100000);
            button.classList.toggle('border-violet-200', availableAndUnselected && seatPrice === 150000);
            button.classList.toggle('bg-violet-50', availableAndUnselected && seatPrice === 150000);
            button.classList.toggle('text-violet-800', availableAndUnselected && seatPrice === 150000);
            button.title = assignedToOther ? `Đã gán cho hành khách ${assignedIndex + 1}` :
                (button.dataset.seatAvailable === '1'
                    ? `${button.dataset.seatType} · ${seatPrice ? formatVnd(seatPrice) : 'Miễn phí'}${activeSeat ? ' · Bấm để bỏ chọn' : ''}`
                    : button.title);
        });
    }
    seatButtons.forEach(button => button.addEventListener('click', () => {
        const activeInput = passengerSeatInputs[activePassengerIndex];
        const seatCode = button.dataset.seatCode;
        if (activeInput.value === seatCode) {
            activeInput.value = '';
        } else if (!passengerSeatInputs.some((input, index) => index !== activePassengerIndex && input.value === seatCode)) {
            activeInput.value = seatCode;
        }
        updateSeatMap();
        refreshPrice();
    }));
    updateSeatMap();
    refreshPrice();
</script>
</body>
</html>
