<?php
session_start();
require_once __DIR__ . '/../api/config/database.php';

$from          = trim($_GET['from']       ?? '');
$to            = trim($_GET['to']         ?? '');
$departDate    = $_GET['departDate'] ?? date('Y-m-d');
$adults        = max(1, (int)($_GET['adults']   ?? 1));
$children      = max(0, (int)($_GET['children'] ?? 0));
$infants       = max(0, (int)($_GET['infants']  ?? 0));
$totalPassengers = max(1, $adults + $children + $infants);
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $departDate)) {
    $departDate = date('Y-m-d');
}

$airports = getAirports($conn);
$fromCode = resolveAirportCode($conn, $from, '');
$toCode   = resolveAirportCode($conn, $to, '');
if ($from === '' && $fromCode === '') {
    $fromCode = '';
}
if ($to === '' && $toCode === '') {
    $toCode = '';
}
if ($from === '' && $fromCode !== '') {
    $from = airportLabel($airports, $fromCode);
}
if ($to === '' && $toCode !== '') {
    $to = airportLabel($airports, $toCode);
}
$routeError = '';
if ($fromCode === '' || $toCode === '') {
    $routeError = 'Vui lòng chọn điểm đi và điểm đến trước khi xem chuyến bay.';
} elseif ($fromCode === $toCode) {
    $routeError = 'Điểm đi và điểm đến phải khác nhau. Vui lòng chọn lại hành trình.';
}

function mapDbFlight($row, $totalPassengers) {
    $depTimestamp = strtotime($row['depart_at']);
    $arrTimestamp = strtotime($row['arrive_at']);
    $durationMin = max(30, round(($arrTimestamp - $depTimestamp) / 60));
    $durationText = floor($durationMin / 60) . 'h ' . str_pad($durationMin % 60, 2, '0', STR_PAD_LEFT) . 'm';

    $airline = $row['airline'];
    $code = 'XX';
    $logoBg = 'bg-slate-600';
    if (stripos($airline, 'Vietnam') !== false) {
        $code = 'VN';
        $logoBg = 'bg-sky-700';
    } elseif (stripos($airline, 'Bamboo') !== false) {
        $code = 'QH';
        $logoBg = 'bg-emerald-600';
    } elseif (stripos($airline, 'Vietravel') !== false) {
        $code = 'VU';
        $logoBg = 'bg-amber-600';
    } elseif (stripos($airline, 'VietJet') !== false || stripos($airline, 'Vietjet') !== false) {
        $code = 'VJ';
        $logoBg = 'bg-rose-600';
    }

    $hour = (int)date('H', $depTimestamp);
    $period = 'morning';
    if ($hour >= 12 && $hour < 18) $period = 'afternoon';
    elseif ($hour >= 18 && $hour < 21) $period = 'evening';
    elseif ($hour >= 21 || $hour < 5) $period = 'night';

    $stops = (int)$row['stops'];
    $economySeatsLeft = (int)$row['seats_economy_left'];
    $businessSeatsLeft = (int)$row['seats_business_left'];
    $economyAvailable = $economySeatsLeft >= $totalPassengers;
    $businessAvailable = $businessSeatsLeft >= $totalPassengers;
    $availablePrices = [];
    if ($economyAvailable) $availablePrices[] = (int)$row['price_economy'];
    if ($businessAvailable) $availablePrices[] = (int)$row['price_business'];
    $flightStatus = [
        'scheduled' => 'Đúng giờ',
        'delayed' => 'Chậm chuyến',
        'departed' => 'Đang boarding',
        'landed' => 'Đã hạ cánh',
        'cancelled' => 'Đã hủy',
    ][$row['status']] ?? $row['status'];
    return [
        'id' => (int)$row['id'],
        'airline' => $row['airline'],
        'code' => $code,
        'flightNumber' => $row['flight_number'],
        'aircraft' => $row['aircraft'] ?: 'Airbus A321',
        'logoBg' => $logoBg,
        'departTime' => date('H:i', $depTimestamp),
        'arriveTime' => date('H:i', $arrTimestamp),
        'departDate' => date('Y-m-d', $depTimestamp),
        'departDateLabel' => date('d/m/Y', $depTimestamp),
        'duration' => $durationText,
        'durationMinutes' => $durationMin,
        'fromCode' => $row['from_airport'],
        'toCode' => $row['to_airport'],
        'fromCity' => $row['from_city'],
        'toCity' => $row['to_city'],
        'stops' => $stops,
        'stopText' => $stops === 0 ? 'Bay thẳng' : "{$stops} điểm dừng",
        'period' => $period,
        'economyPrice' => (int)$row['price_economy'],
        'businessPrice' => (int)$row['price_business'],
        'economySeatsLeft' => $economySeatsLeft,
        'businessSeatsLeft' => $businessSeatsLeft,
        'economyAvailable' => $economyAvailable,
        'businessAvailable' => $businessAvailable,
        'price' => min($availablePrices),
        'baggage' => '20kg',
        'taxPrice' => (int)($row['tax_price'] ?? 0),
        'status' => $flightStatus,
        'gate' => $row['gate'] ?? ''
    ];
}

$allFlights = [];
$dateFares = [];

if ($conn && $routeError === '') {
    $sql = "SELECT f.*,
                   dep.city AS from_city, dep.name AS from_airport_name,
                   arr.city AS to_city, arr.name AS to_airport_name
            FROM flights f
            JOIN airports dep ON f.from_airport = dep.code
            JOIN airports arr ON f.to_airport = arr.code
            WHERE (f.seats_economy_left >= ? OR f.seats_business_left >= ?)
              AND f.status <> 'cancelled'";
    $params = [$totalPassengers, $totalPassengers];
    $types = 'ii';

    if ($fromCode !== '') {
        $sql .= " AND f.from_airport = ?";
        $params[] = $fromCode;
        $types .= 's';
    }
    if ($toCode !== '') {
        $sql .= " AND f.to_airport = ?";
        $params[] = $toCode;
        $types .= 's';
    }

    $fareSelect = "SELECT DATE(f.depart_at) AS fly_date,
                   MIN(LEAST(
                       IF(f.seats_economy_left >= ?, f.price_economy, 999999999999),
                       IF(f.seats_business_left >= ?, f.price_business, 999999999999)
                   )) AS min_price
            FROM flights f
            JOIN airports dep ON f.from_airport = dep.code
            JOIN airports arr ON f.to_airport = arr.code
            WHERE (f.seats_economy_left >= ? OR f.seats_business_left >= ?)
              AND f.status <> 'cancelled'";
    $fareParams = [$totalPassengers, $totalPassengers, $totalPassengers, $totalPassengers];
    $fareTypes = 'iiii';
    if ($fromCode !== '') {
        $fareSelect .= " AND f.from_airport = ?";
        $fareParams[] = $fromCode;
        $fareTypes .= 's';
    }
    if ($toCode !== '') {
        $fareSelect .= " AND f.to_airport = ?";
        $fareParams[] = $toCode;
        $fareTypes .= 's';
    }
    $fareSelect .= " GROUP BY DATE(f.depart_at) ORDER BY fly_date ASC";

    $fareStmt = mysqli_prepare($conn, $fareSelect);
    if ($fareStmt) {
        mysqli_stmt_bind_param($fareStmt, $fareTypes, ...$fareParams);
        mysqli_stmt_execute($fareStmt);
        $fareRes = mysqli_stmt_get_result($fareStmt);
        while ($fr = mysqli_fetch_assoc($fareRes)) {
            $dateFares[$fr['fly_date']] = (int)$fr['min_price'];
        }
        mysqli_stmt_close($fareStmt);
    }

    $sql .= " AND DATE(f.depart_at) = ? ORDER BY LEAST(f.price_economy, f.price_business) ASC, f.depart_at ASC";
    $params[] = $departDate;
    $types .= 's';

    $stmt = mysqli_prepare($conn, $sql);
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, $types, ...$params);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        while ($row = mysqli_fetch_assoc($res)) {
            $allFlights[] = mapDbFlight($row, $totalPassengers);
        }
        mysqli_stmt_close($stmt);
    }
}

$weekDates = [];
$cheapestPrice = null;
foreach ($dateFares as $p) {
    if ($cheapestPrice === null || $p < $cheapestPrice) {
        $cheapestPrice = $p;
    }
}
for ($i = -3; $i <= 3; $i++) {
    $d = date('Y-m-d', strtotime($departDate . " {$i} day"));
    $price = $dateFares[$d] ?? null;
    $weekDates[] = [
        'ymd' => $d,
        'short' => formatVnWeekday($d) . ', ' . date('d/m', strtotime($d)),
        'full'  => formatVnWeekday($d) . ', ' . date('d/m/Y', strtotime($d)),
        'price' => $price,
        'isCheapest' => ($price !== null && $price === $cheapestPrice),
        'active' => ($d === $departDate),
    ];
}

$bestPrice = !empty($allFlights) ? min(array_column($allFlights, 'price')) : null;
$fastestMin = !empty($allFlights) ? min(array_column($allFlights, 'durationMinutes')) : null;
$airlinesAvail = array_values(array_unique(array_column($allFlights, 'airline')));
$fastestLabel = $fastestMin !== null
    ? floor($fastestMin / 60) . ' giờ ' . str_pad($fastestMin % 60, 2, '0', STR_PAD_LEFT) . ' phút'
    : '—';
$summaryDateText = formatVnWeekday($departDate) . ', ' . date('d/m/Y', strtotime($departDate));
$departDateDisplay = date('d/m/Y', strtotime($departDate));
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Danh sách chuyến bay - <?= htmlspecialchars($from) ?> ➔ <?= htmlspecialchars($to) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: { sans: ['"Plus Jakarta Sans"', 'sans-serif'] },
                    colors: {
                        primary: { DEFAULT: '#2563eb', hover: '#1d4ed8', light: '#eff6ff' }
                    }
                }
            }
        }
    </script>
    <style>body { font-family: 'Plus Jakarta Sans', sans-serif; }</style>
</head>
<body class="bg-slate-50 text-slate-800 flex flex-col min-h-screen">

    <!-- HEADER / NAVBAR -->
    <header class="sticky top-0 z-50 bg-white/95 backdrop-blur-md border-b border-slate-200">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 h-16 flex items-center justify-between">
            <a href="/WebsiteDatVe/index.php" class="flex items-center gap-2">
                <span class="w-10 h-10 rounded-xl bg-primary text-white flex items-center justify-center shadow-md shadow-blue-500/20">
                    <i class="fa-solid fa-plane text-lg"></i>
                </span>
                <span class="text-xl font-extrabold tracking-tight text-slate-900">Booking Online</span>
            </a>

            <nav class="hidden md:flex items-center gap-7 text-sm font-medium text-slate-600">
                <a href="/WebsiteDatVe/index.php" class="hover:text-primary transition">Trang chủ</a>
                <a href="/WebsiteDatVe/check-in/check-in.php" class="hover:text-primary transition">Check-in</a>
                <a href="/WebsiteDatVe/flight-status/flight-status.php" class="hover:text-primary transition">Tình trạng chuyến bay</a>
                <a href="/WebsiteDatVe/manage-booking/manage-booking.php" class="hover:text-primary transition">Quản lý đặt chỗ</a>
                <a href="/WebsiteDatVe/services/services.php" class="hover:text-primary transition">Dịch vụ</a>
                <?php if (in_array($_SESSION['user']['role'] ?? '', ['super_admin', 'flight_manager', 'support', 'accountant'], true)): ?>
                    <a href="/WebsiteDatVe/admin/admin.php" class="text-purple-600 font-semibold hover:text-purple-700 transition flex items-center gap-1">
                        <i class="fa-solid fa-gauge"></i> Admin
                    </a>
                <?php endif; ?>
            </nav>

            <div class="flex items-center gap-3">
                <?php if (!empty($_SESSION['user'])): ?>
                    <a href="/WebsiteDatVe/account/account.php" aria-label="Tài khoản hội viên" title="Tài khoản hội viên" class="flex h-10 w-10 items-center justify-center rounded-full border border-slate-200 bg-slate-100 text-primary transition hover:border-primary hover:bg-primary-light focus:outline-none focus:ring-2 focus:ring-primary focus:ring-offset-2">
                        <i class="fa-solid fa-user text-base" aria-hidden="true"></i>
                    </a>
                    <a href="/WebsiteDatVe/login/logout.php" class="px-3 py-2 text-xs font-semibold text-rose-600 hover:bg-rose-50 rounded-xl transition">Đăng xuất</a>
                <?php else: ?>
                    <a href="/WebsiteDatVe/login/login.php" class="px-4 py-2 text-sm font-semibold text-slate-700 hover:text-primary hover:bg-slate-100 rounded-xl transition">Đăng nhập</a>
                    <a href="/WebsiteDatVe/register/register.php" class="px-4 py-2 text-sm font-semibold text-white bg-primary hover:bg-primary-hover rounded-xl shadow-md shadow-blue-500/20 transition">Đăng ký</a>
                <?php endif; ?>
            </div>
        </div>
    </header>

    <?php if ($routeError !== ''): ?>
        <div role="alert" class="mx-auto mt-6 flex w-full max-w-7xl flex-col gap-3 px-4 sm:flex-row sm:items-center sm:justify-between sm:px-6">
            <p class="rounded-2xl border border-amber-200 bg-amber-50 px-5 py-4 text-sm font-semibold text-amber-800">
                <i class="fa-solid fa-circle-info mr-2"></i><?= htmlspecialchars($routeError, ENT_QUOTES, 'UTF-8') ?>
            </p>
            <a href="/WebsiteDatVe/index.php#searchFlightForm" class="inline-flex shrink-0 items-center justify-center gap-2 rounded-xl bg-primary px-5 py-3 text-sm font-bold text-white hover:bg-primary-hover">
                Chọn hành trình <i class="fa-solid fa-arrow-right"></i>
            </a>
        </div>
    <?php endif; ?>

    <!-- 1. TOP SUMMARY BAR + INLINE SEARCH + DATE FARE MATRIX (KHÔNG RELOAD TRANG KHI ĐỔI TÌM KIẾM) -->
    <div class="bg-white border-b border-slate-200 py-4 shadow-sm">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 space-y-4">
            <!-- DÒNG 1: TỔNG QUAN CHẶNG BAY & NÚT ĐÓNG / MỞ -->
            <div class="flex flex-col md:flex-row items-start md:items-center justify-between gap-3">
                <div class="flex flex-wrap items-center gap-2.5 text-sm">
                    <span class="px-3.5 py-1 rounded-full bg-blue-50 text-primary font-bold text-xs border border-blue-100">
                        1 chiều
                    </span>
                    <span class="font-extrabold text-slate-900 text-base flex items-center gap-2" id="summaryRoute">
                        <?= htmlspecialchars($from !== '' ? $from : 'Tất cả điểm đi') ?>
                        <i class="fa-solid fa-arrow-right text-primary text-xs mx-1"></i>
                        <?= htmlspecialchars($to !== '' ? $to : 'Tất cả điểm đến') ?>
                    </span>
                    <span class="text-slate-400 font-medium">•</span>
                    <span class="text-slate-600 font-medium" id="summaryDate"><?= htmlspecialchars($summaryDateText) ?></span>
                    <span class="text-slate-400 font-medium">•</span>
                    <span class="text-slate-600 font-medium" id="summaryPassenger"><?= $totalPassengers ?> người lớn</span>
                    <span class="text-slate-400 font-medium">•</span>
                    <span class="text-slate-600 font-medium" id="summaryCabin">Chọn hạng vé trong chuyến bay</span>
                </div>

                <button type="button" id="toggleSearchBtn" onclick="toggleSearchBox()" class="inline-flex items-center gap-1.5 px-4 py-1.5 rounded-full border border-slate-200 bg-white hover:bg-slate-50 text-slate-700 font-bold text-xs shadow-sm transition">
                    <i class="fa-solid fa-magnifying-glass text-slate-400"></i>
                    <span id="toggleSearchBtnText">Đổi tìm kiếm</span>
                </button>
            </div>

            <!-- DÒNG 2: THANH CHỈNH SỬA TÌM KIẾM (INLINE EDIT BAR - MẶC ĐỊNH ẨN) -->
            <div id="inlineSearchBox" class="hidden pt-3 border-t border-slate-100 transition-all duration-300">
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-12 gap-3 items-end">
                    <!-- Điểm khởi hành -->
                    <div class="lg:col-span-3">
                        <label class="block text-xs font-bold text-slate-700 mb-1.5">Điểm khởi hành</label>
                        <div class="relative">
                            <input type="text" id="editFromInput" list="popularAirports" value="<?= htmlspecialchars($from) ?>" placeholder="TP. Hồ Chí Minh (SGN)" class="w-full px-4 py-2.5 rounded-2xl border border-slate-200 bg-white text-sm font-semibold text-slate-900 focus:outline-none focus:border-primary focus:ring-2 focus:ring-primary/20 transition">
                        </div>
                    </div>

                    <!-- Điểm đến -->
                    <div class="lg:col-span-3">
                        <label class="block text-xs font-bold text-slate-700 mb-1.5">Điểm đến</label>
                        <div class="relative">
                            <input type="text" id="editToInput" list="popularAirports" value="<?= htmlspecialchars($to) ?>" placeholder="Hà Nội (HAN)" class="w-full px-4 py-2.5 rounded-2xl border border-slate-200 bg-white text-sm font-semibold text-slate-900 focus:outline-none focus:border-primary focus:ring-2 focus:ring-primary/20 transition">
                        </div>
                    </div>

                    <datalist id="popularAirports">
                        <?php foreach ($airports as $ap): ?>
                            <option value="<?= htmlspecialchars($ap['city']) ?> (<?= htmlspecialchars($ap['code']) ?>)"><?= htmlspecialchars($ap['name']) ?></option>
                        <?php endforeach; ?>
                    </datalist>

                    <!-- Ngày đi -->
                    <div class="lg:col-span-2">
                        <label class="block text-xs font-bold text-slate-700 mb-1.5">Ngày đi</label>
                        <div class="relative flex items-center">
                            <input type="text" id="editDateDisplay" readonly value="<?= htmlspecialchars($departDateDisplay) ?>" onclick="document.getElementById('editDateHidden').showPicker ? document.getElementById('editDateHidden').showPicker() : document.getElementById('editDateHidden').focus()" class="w-full px-4 py-2.5 rounded-2xl border border-slate-200 bg-white text-sm font-semibold text-slate-900 focus:outline-none focus:border-primary focus:ring-2 focus:ring-primary/20 transition cursor-pointer pr-9">
                            <i class="fa-regular fa-calendar absolute right-3.5 top-1/2 -translate-y-1/2 text-slate-600 pointer-events-none text-sm"></i>
                            <input type="date" id="editDateHidden" value="<?= $departDate ?>" onchange="onDateInputChange(this.value)" class="absolute inset-0 opacity-0 cursor-pointer pointer-events-auto w-full h-full">
                        </div>
                    </div>

                    <!-- Hành khách -->
                    <div class="lg:col-span-2 relative" id="passengerDropdownContainer">
                        <label class="block text-xs font-bold text-slate-700 mb-1.5">Hành khách</label>
                        <button type="button" id="passengerBtn" onclick="togglePassengerDropdown(event)" class="w-full px-4 py-2.5 rounded-2xl border border-slate-200 bg-white text-sm font-semibold text-slate-900 flex items-center justify-between hover:border-primary focus:outline-none focus:border-primary focus:ring-2 focus:ring-primary/20 transition">
                            <div class="flex items-center gap-2 truncate">
                                <i class="fa-solid fa-users text-primary text-xs"></i>
                                <span id="passengerDisplayText"><?= str_pad($totalPassengers, 2, '0', STR_PAD_LEFT) ?> Hành khách</span>
                            </div>
                            <i id="passengerChevron" class="fa-solid fa-chevron-down text-[10px] text-slate-400 transition-transform"></i>
                        </button>

                        <!-- Popup hành khách -->
                        <div id="passengerPopup" class="hidden absolute left-0 sm:right-0 sm:left-auto top-full mt-2 w-72 sm:w-80 bg-white rounded-3xl p-5 shadow-2xl border border-slate-100 z-50 space-y-4">
                            <div class="border-b border-slate-100 pb-2 flex items-center justify-between">
                                <h4 class="text-sm font-bold text-slate-900">Số lượng hành khách</h4>
                                <span class="text-xs text-primary font-semibold" id="popupTotalText"><?= $totalPassengers ?> khách</span>
                            </div>

                            <!-- Người lớn -->
                            <div class="flex items-center justify-between">
                                <div>
                                    <p class="text-xs font-bold text-slate-900">Người lớn</p>
                                    <p class="text-[11px] text-slate-400">≥ 12 tuổi</p>
                                </div>
                                <div class="flex items-center gap-2.5">
                                    <button type="button" onclick="modifyPassenger('adults', -1)" class="w-7 h-7 rounded-full border border-slate-200 text-slate-600 hover:border-primary hover:text-primary flex items-center justify-center text-xs transition">
                                        <i class="fa-solid fa-minus text-[10px]"></i>
                                    </button>
                                    <span id="adultCountDisplay" class="w-4 text-center text-xs font-bold text-slate-900"><?= $adults ?></span>
                                    <button type="button" onclick="modifyPassenger('adults', 1)" class="w-7 h-7 rounded-full border border-slate-200 text-slate-600 hover:border-primary hover:text-primary flex items-center justify-center text-xs transition">
                                        <i class="fa-solid fa-plus text-[10px]"></i>
                                    </button>
                                </div>
                            </div>

                            <!-- Trẻ em -->
                            <div class="flex items-center justify-between pt-2 border-t border-slate-50">
                                <div>
                                    <p class="text-xs font-bold text-slate-900">Trẻ em</p>
                                    <p class="text-[11px] text-slate-400">2 - 11 tuổi</p>
                                </div>
                                <div class="flex items-center gap-2.5">
                                    <button type="button" onclick="modifyPassenger('children', -1)" class="w-7 h-7 rounded-full border border-slate-200 text-slate-600 hover:border-primary hover:text-primary flex items-center justify-center text-xs transition">
                                        <i class="fa-solid fa-minus text-[10px]"></i>
                                    </button>
                                    <span id="childCountDisplay" class="w-4 text-center text-xs font-bold text-slate-900"><?= $children ?></span>
                                    <button type="button" onclick="modifyPassenger('children', 1)" class="w-7 h-7 rounded-full border border-slate-200 text-slate-600 hover:border-primary hover:text-primary flex items-center justify-center text-xs transition">
                                        <i class="fa-solid fa-plus text-[10px]"></i>
                                    </button>
                                </div>
                            </div>

                            <!-- Em bé -->
                            <div class="flex items-center justify-between pt-2 border-t border-slate-50">
                                <div>
                                    <p class="text-xs font-bold text-slate-900">Em bé</p>
                                    <p class="text-[11px] text-slate-400">&lt; 2 tuổi</p>
                                </div>
                                <div class="flex items-center gap-2.5">
                                    <button type="button" onclick="modifyPassenger('infants', -1)" class="w-7 h-7 rounded-full border border-slate-200 text-slate-600 hover:border-primary hover:text-primary flex items-center justify-center text-xs transition">
                                        <i class="fa-solid fa-minus text-[10px]"></i>
                                    </button>
                                    <span id="infantCountDisplay" class="w-4 text-center text-xs font-bold text-slate-900"><?= $infants ?></span>
                                    <button type="button" onclick="modifyPassenger('infants', 1)" class="w-7 h-7 rounded-full border border-slate-200 text-slate-600 hover:border-primary hover:text-primary flex items-center justify-center text-xs transition">
                                        <i class="fa-solid fa-plus text-[10px]"></i>
                                    </button>
                                </div>
                            </div>

                            <button type="button" onclick="closePassengerPopup()" class="w-full py-2 rounded-xl bg-slate-900 text-white text-xs font-bold hover:bg-slate-800 transition">
                                Xong
                            </button>
                        </div>
                    </div>

                    <!-- Nút Cập nhật tìm kiếm -->
                    <div class="lg:col-span-2">
                        <button type="button" onclick="updateSearchInline()" class="w-full py-2.5 px-4 rounded-2xl bg-primary hover:bg-primary-hover text-white font-bold text-sm shadow-md shadow-blue-500/20 transition hover:-translate-y-0.5 flex items-center justify-center gap-2">
                            <span>Cập nhật tìm kiếm</span>
                        </button>
                    </div>
                </div>
            </div>

            <!-- DÒNG 3: GIÁ THẤP NHẤT CÁC NGÀY (DATE FARE MATRIX) -->
            <div class="pt-3 border-t border-slate-100 flex flex-col lg:flex-row items-start lg:items-center gap-4">
                <span class="text-xs font-bold text-slate-500 uppercase tracking-wider shrink-0">
                    Giá thấp nhất các ngày:
                </span>

                <div class="flex items-center gap-2 w-full min-w-0">
                    <button type="button" onclick="shiftDateTab(-1)" aria-label="Lùi một ngày"
                        class="w-9 h-9 shrink-0 rounded-xl border border-slate-200 text-slate-500 hover:border-primary hover:text-primary transition">
                        <i class="fa-solid fa-chevron-left"></i>
                    </button>
                    <div class="flex items-center gap-2.5 overflow-x-auto flex-1 min-w-0 pb-1 [scrollbar-width:none]" id="dateTabsContainer">
                        <?php foreach ($weekDates as $tab): ?>
                            <button type="button"
                                onclick="selectDateTab('<?= $tab['ymd'] ?>')"
                                <?= $tab['active'] ? 'id="activeDateTab"' : '' ?>
                                class="date-tab p-2.5 <?= $tab['active'] ? 'px-5 bg-primary text-white shadow-md shadow-blue-500/25' : 'px-4 border border-slate-200 hover:border-primary' ?> rounded-2xl text-center shrink-0 cursor-pointer transition">
                                <p class="text-[11px] font-medium <?= $tab['active'] ? 'text-blue-100' : 'text-slate-500' ?>"><?= htmlspecialchars($tab['short']) ?></p>
                                <p class="text-xs font-bold <?= $tab['active'] ? 'text-white' : 'text-slate-900' ?> mt-0.5">
                                    <?= $tab['price'] !== null ? number_format($tab['price']) . 'đ' : '—' ?>
                                </p>
                                <?php if ($tab['isCheapest'] && $tab['price'] !== null): ?>
                                    <span class="inline-block mt-0.5 text-[9px] font-extrabold uppercase px-1.5 py-0.5 rounded <?= $tab['active'] ? 'bg-white/20' : 'bg-blue-50 text-primary' ?>">
                                        Rẻ nhất
                                    </span>
                                <?php endif; ?>
                            </button>
                        <?php endforeach; ?>
                    </div>
                    <button type="button" onclick="shiftDateTab(1)" aria-label="Tiến một ngày"
                        class="w-9 h-9 shrink-0 rounded-xl border border-slate-200 text-slate-500 hover:border-primary hover:text-primary transition">
                        <i class="fa-solid fa-chevron-right"></i>
                    </button>
                </div>
            </div>

        </div>
    </div>

    <main class="max-w-7xl mx-auto px-4 sm:px-6 py-6 w-full flex-1 space-y-6">

        <!-- 3. HIGHLIGHT METRICS (3 THẺ TỔNG QUAN) -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div class="bg-white p-4 rounded-2xl border border-slate-200 shadow-sm flex items-center gap-4">
                <span class="w-12 h-12 rounded-2xl bg-emerald-50 text-emerald-600 flex items-center justify-center text-xl shrink-0">
                    <i class="fa-solid fa-arrow-trend-down"></i>
                </span>
                <div>
                    <p class="text-xs text-slate-400 font-semibold">Giá vé tốt nhất hôm nay</p>
                    <p class="text-lg font-black text-slate-900 mt-0.5"><?= $bestPrice !== null ? number_format($bestPrice) . 'đ' : '—' ?></p>
                </div>
            </div>

            <div class="bg-white p-4 rounded-2xl border border-slate-200 shadow-sm flex items-center gap-4">
                <span class="w-12 h-12 rounded-2xl bg-sky-50 text-primary flex items-center justify-center text-xl shrink-0">
                    <i class="fa-solid fa-bolt"></i>
                </span>
                <div>
                    <p class="text-xs text-slate-400 font-semibold">Thời gian bay nhanh nhất</p>
                    <p class="text-lg font-black text-slate-900 mt-0.5"><?= htmlspecialchars($fastestLabel) ?></p>
                </div>
            </div>

            <div class="bg-white p-4 rounded-2xl border border-slate-200 shadow-sm flex items-center gap-4">
                <span class="w-12 h-12 rounded-2xl bg-purple-50 text-purple-600 flex items-center justify-center text-xl shrink-0">
                    <i class="fa-solid fa-wand-magic-sparkles"></i>
                </span>
                <div>
                    <p class="text-xs text-slate-400 font-semibold">Hãng bay khả dụng</p>
                    <p class="text-sm font-black text-slate-900 mt-1"><?= !empty($airlinesAvail) ? htmlspecialchars(implode(', ', $airlinesAvail)) : '—' ?></p>
                </div>
            </div>
        </div>

        <!-- 4. MAIN CONTENT (BỘ LỌC + DANH SÁCH CHUYẾN BAY) -->
        <div class="grid grid-cols-1 lg:grid-cols-12 gap-6 items-start">
            
            <!-- CỘT TRÁI: BỘ LỌC TÌM KIẾM (4 cols) -->
            <aside class="lg:col-span-4 bg-white rounded-3xl border border-slate-200 p-6 shadow-sm space-y-6 sticky top-20">
                <div class="flex items-center justify-between border-b border-slate-100 pb-4">
                    <h2 class="text-base font-extrabold text-slate-900">Bộ lọc tìm kiếm</h2>
                    <button type="button" onclick="resetFilters()" class="text-xs font-bold text-primary hover:underline">
                        Đặt lại
                    </button>
                </div>

                <!-- KHUNG GIỜ KHỞI HÀNH -->
                <div>
                    <h3 class="text-xs font-bold text-slate-400 uppercase tracking-wider mb-3">Khung giờ khởi hành</h3>
                    <div class="grid grid-cols-2 gap-2.5">
                        <label class="period-option flex flex-col items-center justify-center p-3 rounded-2xl border border-slate-200 cursor-pointer hover:border-primary transition text-center" data-period="morning">
                            <input type="checkbox" value="morning" class="hidden filter-period">
                            <i class="fa-regular fa-sun text-lg text-amber-500 mb-1"></i>
                            <span class="font-bold text-xs text-slate-900">SÁNG</span>
                            <span class="text-[10px] text-slate-400 font-medium">06:00 - 12:00</span>
                        </label>
                        <label class="period-option flex flex-col items-center justify-center p-3 rounded-2xl border border-slate-200 cursor-pointer hover:border-primary transition text-center" data-period="afternoon">
                            <input type="checkbox" value="afternoon" class="hidden filter-period">
                            <i class="fa-solid fa-cloud-sun text-lg text-orange-500 mb-1"></i>
                            <span class="font-bold text-xs text-slate-900">CHIỀU</span>
                            <span class="text-[10px] text-slate-400 font-medium">12:00 - 18:00</span>
                        </label>
                        <label class="period-option flex flex-col items-center justify-center p-3 rounded-2xl border border-slate-200 cursor-pointer hover:border-primary transition text-center" data-period="evening">
                            <input type="checkbox" value="evening" class="hidden filter-period">
                            <i class="fa-regular fa-moon text-lg text-indigo-500 mb-1"></i>
                            <span class="font-bold text-xs text-slate-900">TỐI</span>
                            <span class="text-[10px] text-slate-400 font-medium">18:00 - 00:00</span>
                        </label>
                        <label class="period-option flex flex-col items-center justify-center p-3 rounded-2xl border border-slate-200 cursor-pointer hover:border-primary transition text-center" data-period="night">
                            <input type="checkbox" value="night" class="hidden filter-period">
                            <i class="fa-solid fa-moon text-lg text-slate-600 mb-1"></i>
                            <span class="font-bold text-xs text-slate-900">ĐÊM</span>
                            <span class="text-[10px] text-slate-400 font-medium">00:00 - 06:00</span>
                        </label>
                    </div>
                </div>

                <!-- SỐ ĐIỂM DỪNG -->
                <div class="border-t border-slate-100 pt-5">
                    <h3 class="text-xs font-bold text-slate-400 uppercase tracking-wider mb-3">Số điểm dừng</h3>
                    <div class="space-y-2.5 text-sm">
                        <label class="flex items-center gap-2.5 cursor-pointer font-medium text-slate-700">
                            <input type="checkbox" value="0" class="filter-stops w-4 h-4 rounded text-primary focus:ring-primary">
                            <span>Bay thẳng</span>
                        </label>
                        <label class="flex items-center gap-2.5 cursor-pointer font-medium text-slate-700">
                            <input type="checkbox" value="1" class="filter-stops w-4 h-4 rounded text-primary focus:ring-primary">
                            <span>1 điểm dừng</span>
                        </label>
                        <label class="flex items-center gap-2.5 cursor-pointer font-medium text-slate-700">
                            <input type="checkbox" value="2" class="filter-stops w-4 h-4 rounded text-primary focus:ring-primary">
                            <span>2+ điểm dừng</span>
                        </label>
                    </div>
                </div>

                <!-- KHOẢNG GIÁ TỐI ĐA -->
                <div class="border-t border-slate-100 pt-5">
                    <div class="flex justify-between items-center mb-2">
                        <h3 class="text-xs font-bold text-slate-400 uppercase tracking-wider">Khoảng giá tối đa</h3>
                        <span id="priceRangeDisplay" class="text-xs font-bold text-primary">Tối đa 4.750.000đ</span>
                    </div>
                    <input type="range" id="priceRange" min="990000" max="4750000" step="100000" value="4750000" class="w-full accent-primary cursor-pointer">
                    <div class="flex justify-between text-[11px] text-slate-400 font-semibold mt-1">
                        <span>990.000đ</span>
                        <span>4.750.000đ</span>
                    </div>
                </div>

                <!-- HẠNG VÉ -->
                <div class="border-t border-slate-100 pt-5">
                    <h3 class="text-xs font-bold text-slate-400 uppercase tracking-wider mb-3">Hạng vé</h3>
                    <div class="space-y-2.5 text-sm">
                        <label class="flex items-center gap-2.5 cursor-pointer font-medium text-slate-700">
                            <input type="checkbox" value="economy" class="filter-cabin w-4 h-4 rounded text-primary focus:ring-primary">
                            <span>Phổ thông (Economy)</span>
                        </label>
                        <label class="flex items-center gap-2.5 cursor-pointer font-medium text-slate-700">
                            <input type="checkbox" value="business" class="filter-cabin w-4 h-4 rounded text-primary focus:ring-primary">
                            <span>Thương gia (Business)</span>
                        </label>
                    </div>
                </div>

                <!-- HÃNG HÀNG KHÔNG -->
                <div class="border-t border-slate-100 pt-5">
                    <h3 class="text-xs font-bold text-slate-400 uppercase tracking-wider mb-3">Hãng hàng không</h3>
                    <div class="space-y-2.5 text-sm">
                        <label class="flex items-center gap-2.5 cursor-pointer font-medium text-slate-700">
                            <input type="checkbox" value="Vietnam Airlines" class="filter-airline w-4 h-4 rounded text-primary focus:ring-primary">
                            <span>Vietnam Airlines</span>
                        </label>
                        <label class="flex items-center gap-2.5 cursor-pointer font-medium text-slate-700">
                            <input type="checkbox" value="VietJet Air" class="filter-airline w-4 h-4 rounded text-primary focus:ring-primary">
                            <span>VietJet Air</span>
                        </label>
                        <label class="flex items-center gap-2.5 cursor-pointer font-medium text-slate-700">
                            <input type="checkbox" value="Bamboo Airways" class="filter-airline w-4 h-4 rounded text-primary focus:ring-primary">
                            <span>Bamboo Airways</span>
                        </label>
                        <label class="flex items-center gap-2.5 cursor-pointer font-medium text-slate-700">
                            <input type="checkbox" value="Vietravel Airlines" class="filter-airline w-4 h-4 rounded text-primary focus:ring-primary">
                            <span>Vietravel Airlines</span>
                        </label>
                    </div>
                </div>
            </aside>

            <!-- CỘT PHẢI: DANH SÁCH CHUYẾN BAY (8 cols) -->
            <section class="lg:col-span-8 space-y-4">
                <!-- THANH TIÊU ĐỀ & SẮP XẾP -->
                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 bg-white p-4 rounded-2xl border border-slate-200 shadow-sm">
                    <div>
                        <h2 class="text-lg font-black text-slate-900">
                            Tìm thấy <span id="flightCountText" class="text-primary"><?= count($allFlights) ?></span> chuyến bay
                        </h2>
                        <p class="text-xs text-slate-400 mt-0.5">Giá đã bao gồm thuế và các khoản phụ phí sân bay</p>
                    </div>

                    <div class="flex items-center gap-2">
                        <span class="text-xs font-bold text-slate-500">Sắp xếp:</span>
                        <select id="sortSelect" onchange="applyFilters()" class="text-xs font-bold text-slate-800 bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 focus:outline-none focus:border-primary cursor-pointer">
                            <option value="price-asc">Giá thấp nhất</option>
                            <option value="price-desc">Giá cao nhất</option>
                            <option value="time-asc">Cất cánh sớm nhất</option>
                            <option value="duration-asc">Thời gian ngắn nhất</option>
                        </select>
                    </div>
                </div>

                <!-- LỌC NHANH (QUICK CHIPS) -->
                <div class="flex flex-wrap items-center gap-2 text-xs font-bold">
                    <span class="text-slate-400">Lọc nhanh:</span>
                    <button type="button" onclick="quickFilter('direct')" class="px-3 py-1.5 rounded-full bg-white border border-slate-200 hover:border-primary text-slate-700 hover:text-primary transition shadow-sm">
                        Bay thẳng
                    </button>
                    <button type="button" onclick="quickFilter('vna')" class="px-3 py-1.5 rounded-full bg-white border border-slate-200 hover:border-primary text-slate-700 hover:text-primary transition shadow-sm">
                        Vietnam Airlines
                    </button>
                    <button type="button" onclick="quickFilter('vj')" class="px-3 py-1.5 rounded-full bg-white border border-slate-200 hover:border-primary text-slate-700 hover:text-primary transition shadow-sm">
                        VietJet Air
                    </button>
                </div>

                <!-- DANH SÁCH THẺ CHUYẾN BAY (FLIGHT CARDS) -->
                <div id="flightCardsList" class="space-y-4">
                    <?php foreach ($allFlights as $f): ?>
                        <?php
                            $fareOptions = [
                                ['key' => 'economy', 'label' => 'Phổ thông', 'price' => $f['economyPrice'], 'seats' => $f['economySeatsLeft'], 'available' => $f['economyAvailable']],
                                ['key' => 'business', 'label' => 'Thương gia', 'price' => $f['businessPrice'], 'seats' => $f['businessSeatsLeft'], 'available' => $f['businessAvailable']],
                            ];
                        ?>
                        <article class="flight-item relative rounded-3xl border border-slate-200 bg-white shadow-sm transition-all p-6 overflow-hidden hover:shadow-lg hover:border-primary/50"
                            data-airline="<?= htmlspecialchars($f['airline']) ?>"
                            data-price="<?= $f['price'] ?>"
                            data-stops="<?= $f['stops'] ?>"
                            data-period="<?= $f['period'] ?>"
                            data-depart="<?= $f['departTime'] ?>"
                            data-duration="<?= $f['durationMinutes'] ?>"
                        >
                            <div class="grid grid-cols-1 gap-x-6 gap-y-4 lg:grid-cols-[minmax(0,0.9fr)_minmax(0,1.1fr)]">
                                
                                <!-- Logo & Tên hãng -->
                                <div class="flex min-w-0 items-center gap-4">
                                    <div class="w-12 h-12 rounded-2xl <?= $f['logoBg'] ?> text-white flex items-center justify-center font-black text-sm shrink-0 shadow-md">
                                        <?= $f['code'] ?>
                                    </div>
                                    <div class="min-w-0">
                                        <h3 class="font-extrabold text-slate-900 text-base leading-tight"><?= $f['airline'] ?></h3>
                                        <p class="mt-0.5 break-words text-xs font-medium text-slate-400">Chuyến <?= $f['flightNumber'] ?> • <?= $f['aircraft'] ?></p>
                                        <div class="mt-2">
                                            <span class="inline-flex items-center gap-1 rounded-md bg-slate-100 px-2 py-0.5 text-[11px] font-bold text-slate-600">
                                                <i class="fa-solid fa-suitcase text-[10px]"></i> <?= $f['baggage'] ?>
                                            </span>
                                        </div>
                                    </div>
                                </div>

                                <!-- Lộ trình & Giờ bay -->
                                <div class="flex min-w-0 w-full items-center justify-between gap-2 sm:gap-5">
                                    <div class="shrink-0 text-left">
                                        <p class="text-2xl font-black text-slate-900"><?= $f['departTime'] ?></p>
                                        <p class="text-xs font-bold text-slate-500 mt-0.5 flight-from-code"><?= htmlspecialchars($f['fromCode']) ?></p>
                                        <p class="text-[10px] text-slate-400 font-medium"><?= htmlspecialchars($f['departDateLabel']) ?></p>
                                    </div>

                                    <div class="flex min-w-0 flex-1 flex-col items-center px-1 sm:px-2">
                                        <span class="text-[11px] text-slate-400 font-semibold mb-1 flex items-center gap-1">
                                            <i class="fa-regular fa-clock text-[10px]"></i> <?= $f['duration'] ?>
                                        </span>
                                        <!-- Dòng kẻ thể hiện điểm dừng -->
                                        <div class="relative flex w-full items-center justify-between py-1">
                                            <div class="w-2 h-2 rounded-full border-2 border-primary bg-white z-10"></div>
                                            <div class="flex-1 h-0.5 bg-slate-200 mx-1 relative">
                                                <i class="fa-solid fa-plane absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 text-primary text-xs"></i>
                                            </div>
                                            <div class="w-2 h-2 rounded-full border-2 border-primary bg-white z-10"></div>
                                        </div>
                                        <span class="mt-1 text-center text-[11px] font-bold <?= $f['stops'] === 0 ? 'text-primary' : 'text-amber-600' ?>">
                                            <?= $f['stopText'] ?>
                                        </span>
                                    </div>

                                    <div class="shrink-0 text-right">
                                        <p class="text-2xl font-black text-slate-900"><?= $f['arriveTime'] ?></p>
                                        <p class="text-xs font-bold text-slate-500 mt-0.5 flight-to-code"><?= $f['toCode'] ?></p>
                                        <p class="text-[10px] text-slate-400 font-medium">Hạ cánh</p>
                                    </div>
                                </div>

                                <!-- Hai hạng vé của cùng chuyến bay -->
                                <div class="grid min-w-0 grid-cols-1 gap-3 border-t border-slate-100 pt-4 sm:grid-cols-2 lg:col-span-2">
                                    <?php foreach ($fareOptions as $fare): ?>
                                        <div class="fare-option min-w-0 rounded-2xl border <?= $fare['key'] === 'business' ? 'border-amber-200 bg-amber-50/50' : 'border-blue-100 bg-blue-50/40' ?> p-3"
                                             data-cabin="<?= $fare['key'] ?>"
                                             data-price="<?= $fare['price'] ?>"
                                             data-available="<?= $fare['available'] ? '1' : '0' ?>">
                                            <p class="text-xs font-extrabold <?= $fare['key'] === 'business' ? 'text-amber-800' : 'text-slate-700' ?>">
                                                <?php if ($fare['key'] === 'business'): ?><i class="fa-solid fa-crown mr-1 text-amber-500"></i><?php endif; ?>
                                                <?= $fare['label'] ?>
                                            </p>
                                            <p class="mt-1 whitespace-nowrap text-lg font-black <?= $fare['key'] === 'business' ? 'text-amber-700' : 'text-primary' ?>">
                                                <?= number_format($fare['price']) ?>đ
                                            </p>
                                            <p class="mb-2 text-[10px] text-slate-500">/ khách · <?= $fare['available'] ? $fare['seats'] . ' ghế còn' : 'Không đủ chỗ' ?></p>
                                            <?php if ($fare['available']): ?>
                                                <?php
                                                    $bookingUrl = '/WebsiteDatVe/booking/booking.php?' . http_build_query([
                                                        'flightId' => $f['id'],
                                                        'price' => $fare['price'],
                                                        'code' => $f['flightNumber'],
                                                        'airline' => $f['airline'],
                                                        'flightNumber' => $f['flightNumber'],
                                                        'departTime' => $f['departTime'],
                                                        'arriveTime' => $f['arriveTime'],
                                                        'duration' => $f['duration'],
                                                        'fromCode' => $f['fromCode'],
                                                        'toCode' => $f['toCode'],
                                                        'from' => $from,
                                                        'to' => $to,
                                                        'departDate' => $departDate,
                                                        'stopText' => $f['stopText'],
                                                        'cabin' => $fare['key'] === 'business' ? 'Hạng Thương gia' : 'Hạng Phổ thông',
                                                        'adults' => $adults,
                                                        'children' => $children,
                                                        'infants' => $infants,
                                                        'passengers' => $totalPassengers,
                                                    ]);
                                                ?>
                                                <a href="<?= htmlspecialchars($bookingUrl, ENT_QUOTES, 'UTF-8') ?>"
                                                   class="booking-flight-btn block rounded-xl <?= $fare['key'] === 'business' ? 'bg-amber-500 hover:bg-amber-600' : 'bg-primary hover:bg-primary-hover' ?> px-3 py-2 text-center text-xs font-bold text-white shadow-sm transition"
                                                   data-price="<?= $fare['price'] ?>">
                                                    Chọn hạng này
                                                </a>
                                            <?php else: ?>
                                                <button type="button" disabled class="block w-full rounded-xl bg-slate-200 px-3 py-2 text-center text-xs font-bold text-slate-400 cursor-not-allowed">
                                                    Hết chỗ
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>

                            <!-- TIỆN ÍCH DƯỚI THẺ -->
                            <div class="mt-5 pt-4 border-t border-slate-100 flex flex-wrap items-center justify-between gap-4 text-xs font-medium text-slate-500">
                                <div class="flex items-center gap-5">
                                    <span class="flex items-center gap-1.5 text-emerald-600 font-semibold">
                                        <i class="fa-solid fa-utensils text-[11px]"></i> Suất ăn nhẹ
                                    </span>
                                    <span class="flex items-center gap-1.5 text-amber-600 font-semibold">
                                        <i class="fa-solid fa-bolt text-[11px]"></i> Cổng sạc USB
                                    </span>
                                    <span class="flex items-center gap-1.5 text-sky-600 font-semibold">
                                        <i class="fa-solid fa-wifi text-[11px]"></i> Wifi trên tàu bay
                                    </span>
                                </div>

                                <button type="button"
                                        onclick="toggleFlightDetails(this)"
                                        aria-expanded="false"
                                        class="flight-details-toggle inline-flex items-center gap-1 font-bold text-primary hover:underline">
                                    Chi tiết chuyến bay <i class="fa-solid fa-chevron-down text-[10px] transition-transform"></i>
                                </button>
                            </div>
                            <div class="flight-details-panel hidden mt-4 border-t border-slate-100 pt-4" aria-hidden="true">
                                <h4 class="text-sm font-extrabold text-slate-800">Thông tin chuyến bay</h4>
                                <div class="mt-3 grid grid-cols-1 gap-3 text-xs sm:grid-cols-2 lg:grid-cols-4">
                                    <div class="rounded-xl bg-slate-50 p-3">
                                        <p class="text-slate-400">Số hiệu chuyến bay</p>
                                        <p class="mt-1 font-bold text-slate-800"><?= htmlspecialchars($f['flightNumber'], ENT_QUOTES, 'UTF-8') ?></p>
                                    </div>
                                    <div class="rounded-xl bg-slate-50 p-3">
                                        <p class="text-slate-400">Hãng hàng không</p>
                                        <p class="mt-1 font-bold text-slate-800"><?= htmlspecialchars($f['airline'], ENT_QUOTES, 'UTF-8') ?></p>
                                    </div>
                                    <div class="rounded-xl bg-slate-50 p-3">
                                        <p class="text-slate-400">Loại máy bay</p>
                                        <p class="mt-1 font-bold text-slate-800"><?= htmlspecialchars($f['aircraft'], ENT_QUOTES, 'UTF-8') ?></p>
                                    </div>
                                    <div class="rounded-xl bg-slate-50 p-3">
                                        <p class="text-slate-400">Hành trình</p>
                                        <p class="mt-1 font-bold text-slate-800"><?= htmlspecialchars($f['fromCity'] . ' (' . $f['fromCode'] . ') → ' . $f['toCity'] . ' (' . $f['toCode'] . ')', ENT_QUOTES, 'UTF-8') ?></p>
                                    </div>
                                    <div class="rounded-xl bg-slate-50 p-3">
                                        <p class="text-slate-400">Thời gian bay</p>
                                        <p class="mt-1 font-bold text-slate-800"><?= htmlspecialchars($f['duration'], ENT_QUOTES, 'UTF-8') ?></p>
                                    </div>
                                    <div class="rounded-xl bg-slate-50 p-3">
                                        <p class="text-slate-400">Điểm dừng</p>
                                        <p class="mt-1 font-bold text-slate-800"><?= htmlspecialchars($f['stopText'], ENT_QUOTES, 'UTF-8') ?></p>
                                    </div>
                                    <div class="rounded-xl bg-slate-50 p-3">
                                        <p class="text-slate-400">Hành lý xách tay</p>
                                        <p class="mt-1 font-bold text-slate-800">7 kg</p>
                                    </div>
                                    <div class="rounded-xl bg-slate-50 p-3">
                                        <p class="text-slate-400">Hành lý ký gửi</p>
                                        <p class="mt-1 font-bold text-slate-800"><?= htmlspecialchars($f['baggage'], ENT_QUOTES, 'UTF-8') ?></p>
                                    </div>
                                </div>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>

                <div id="noFlightMessage" class="<?= empty($allFlights) ? '' : 'hidden' ?> bg-white p-12 rounded-3xl border border-slate-200 text-center">
                    <i class="fa-solid fa-plane-slash text-4xl text-slate-300 mb-3"></i>
                    <h3 class="text-base font-bold text-slate-800">Không có chuyến bay ngày <?= htmlspecialchars($departDateDisplay) ?></h3>
                    <p class="text-xs text-slate-400 mt-1">Chọn ngày khác trên thanh giá thấp nhất, hoặc đổi điểm đi / điểm đến.</p>
                </div>
            </section>
        </div>
    </main>

    <!-- FOOTER -->
    <footer class="mt-auto bg-white border-t border-slate-200">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 py-12">
            <div class="grid grid-cols-1 md:grid-cols-4 gap-8 mb-8">
                <div class="md:col-span-2">
                    <div class="flex items-center gap-2 mb-3">
                        <span class="w-8 h-8 rounded-lg bg-primary text-white flex items-center justify-center">
                            <i class="fa-solid fa-plane text-sm"></i>
                        </span>
                        <span class="text-lg font-bold text-slate-900">Booking Online</span>
                    </div>
                    <p class="text-xs text-slate-500 max-w-sm leading-relaxed mb-4">
                        Hệ thống đặt vé máy bay trực tuyến chuẩn PHP & MySQL cho đồ án sinh viên.
                    </p>
                </div>
                <div>
                    <h4 class="text-xs font-bold text-slate-900 uppercase tracking-wider mb-3">Đường dẫn nhanh</h4>
                    <ul class="space-y-2 text-xs text-slate-500">
                        <li><a href="/WebsiteDatVe/flights/flights.php" class="hover:text-primary">Tìm chuyến bay</a></li>
                        <li><a href="/WebsiteDatVe/check-in/check-in.php" class="hover:text-primary">Check-in online</a></li>
                        <li><a href="/WebsiteDatVe/flight-status/flight-status.php" class="hover:text-primary">Lịch trình bay</a></li>
                        <li><a href="/WebsiteDatVe/contact/contact.php" class="hover:text-primary">Liên hệ hỗ trợ</a></li>
                    </ul>
                </div>
                <div>
                    <h4 class="text-xs font-bold text-slate-900 uppercase tracking-wider mb-3">Hỗ trợ khách hàng</h4>
                    <ul class="space-y-2 text-xs text-slate-500">
                        <li>Hotline: 1900 6868</li>
                        <li>Email: support@bookingonline.vn</li>
                        <li>Thời gian: 24/7 cả ngày lễ</li>
                    </ul>
                </div>
            </div>
            <div class="pt-8 border-t border-slate-100 text-center text-xs text-slate-400">
                &copy; <?= date('Y') ?> Booking Online. Đồ án lập trình Web PHP & JavaScript.
            </div>
        </div>
    </footer>

    <!-- JAVASCRIPT XỬ LÝ BỘ LỌC CLIENT-SIDE REAL-TIME (CHUẨN ĐỒ ÁN JS) -->
    <script>
        function toggleFlightDetails(button) {
            const card = button.closest('.flight-item');
            const panel = card?.querySelector('.flight-details-panel');
            const icon = button.querySelector('i');
            if (!panel) return;

            const isExpanded = button.getAttribute('aria-expanded') === 'true';
            button.setAttribute('aria-expanded', String(!isExpanded));
            panel.classList.toggle('hidden', isExpanded);
            panel.setAttribute('aria-hidden', String(isExpanded));
            if (icon) icon.classList.toggle('rotate-180', !isExpanded);
        }

        const priceRange = document.getElementById('priceRange');
        const priceRangeDisplay = document.getElementById('priceRangeDisplay');
        const periodCards = document.querySelectorAll('.period-option');
        const periodCheckboxes = document.querySelectorAll('.filter-period');
        const stopCheckboxes = document.querySelectorAll('.filter-stops');
        const cabinCheckboxes = document.querySelectorAll('.filter-cabin');
        const airlineCheckboxes = document.querySelectorAll('.filter-airline');
        const flightCards = Array.from(document.querySelectorAll('.flight-item'));
        const flightCountText = document.getElementById('flightCountText');
        const noFlightMessage = document.getElementById('noFlightMessage');
        const sortSelect = document.getElementById('sortSelect');

        // Bấm chọn khung giờ (Toggle giao diện card)
        periodCards.forEach(card => {
            card.addEventListener('click', function(e) {
                const cb = this.querySelector('input[type="checkbox"]');
                setTimeout(() => {
                    if (cb.checked) {
                        this.classList.add('border-primary', 'bg-blue-50/50', 'ring-2', 'ring-blue-100');
                    } else {
                        this.classList.remove('border-primary', 'bg-blue-50/50', 'ring-2', 'ring-blue-100');
                    }
                    applyFilters();
                }, 10);
            });
        });

        // Thanh trượt giá
        priceRange.addEventListener('input', function() {
            const val = Number(this.value);
            priceRangeDisplay.textContent = 'Tối đa ' + val.toLocaleString('vi-VN') + 'đ';
            applyFilters();
        });

        // Bắt sự kiện thay đổi các checkbox
        [...stopCheckboxes, ...cabinCheckboxes, ...airlineCheckboxes].forEach(cb => {
            cb.addEventListener('change', applyFilters);
        });

        function applyFilters() {
            const maxPrice = Number(priceRange.value);
            const selectedPeriods = Array.from(periodCheckboxes).filter(c => c.checked).map(c => c.value);
            const selectedStops = Array.from(stopCheckboxes).filter(c => c.checked).map(c => c.value);
            const selectedCabins = Array.from(cabinCheckboxes).filter(c => c.checked).map(c => c.value);
            const selectedAirlines = Array.from(airlineCheckboxes).filter(c => c.checked).map(c => c.value);

            let visibleCount = 0;

            flightCards.forEach(card => {
                const period = card.dataset.period;
                const stops = card.dataset.stops;
                const airline = card.dataset.airline;
                const fares = Array.from(card.querySelectorAll('.fare-option'));
                const matchingPrices = [];

                let match = true;

                if (selectedPeriods.length > 0 && !selectedPeriods.includes(period)) match = false;
                if (selectedStops.length > 0 && !selectedStops.includes(stops)) match = false;
                if (selectedAirlines.length > 0 && !selectedAirlines.includes(airline)) match = false;

                fares.forEach(fare => {
                    const fareMatches = fare.dataset.available === '1'
                        && Number(fare.dataset.price) <= maxPrice
                        && (selectedCabins.length === 0 || selectedCabins.includes(fare.dataset.cabin));
                    fare.classList.toggle('hidden', !fareMatches);
                    if (fareMatches) matchingPrices.push(Number(fare.dataset.price));
                });

                if (matchingPrices.length === 0) match = false;

                if (match) {
                    card.classList.remove('hidden');
                    card.dataset.visiblePrice = Math.min(...matchingPrices);
                    card.dataset.visibleMaxPrice = Math.max(...matchingPrices);
                    visibleCount++;
                } else {
                    card.classList.add('hidden');
                }
            });

            // Sắp xếp các card hiển thị
            sortFlights();

            flightCountText.textContent = visibleCount;
            if (visibleCount === 0) {
                noFlightMessage.classList.remove('hidden');
            } else {
                noFlightMessage.classList.add('hidden');
            }
        }

        function sortFlights() {
            const sortBy = sortSelect.value;
            const container = document.getElementById('flightCardsList');
            const items = Array.from(container.children);

            items.sort((a, b) => {
                const priceA = Number(a.dataset.visiblePrice || a.dataset.price || 0);
                const priceB = Number(b.dataset.visiblePrice || b.dataset.price || 0);
                const maxPriceA = Number(a.dataset.visibleMaxPrice || priceA);
                const maxPriceB = Number(b.dataset.visibleMaxPrice || priceB);
                const durA = Number(a.dataset.duration || 0);
                const durB = Number(b.dataset.duration || 0);
                const depA = a.dataset.depart || '';
                const depB = b.dataset.depart || '';

                if (sortBy === 'price-asc') return priceA - priceB;
                if (sortBy === 'price-desc') return maxPriceB - maxPriceA;
                if (sortBy === 'duration-asc') return durA - durB;
                if (sortBy === 'time-asc') return depA.localeCompare(depB);
                return 0;
            });

            items.forEach(node => container.appendChild(node));
        }

        function resetFilters() {
            priceRange.value = 4750000;
            priceRangeDisplay.textContent = 'Tối đa 4.750.000đ';
            periodCheckboxes.forEach(c => {
                c.checked = false;
                c.closest('.period-option').classList.remove('border-primary', 'bg-blue-50/50', 'ring-2', 'ring-blue-100');
            });
            [...stopCheckboxes, ...cabinCheckboxes, ...airlineCheckboxes].forEach(c => c.checked = false);
            sortSelect.value = 'price-asc';
            applyFilters();
        }

        // ================= XỬ LÝ ĐỔI TÌM KIẾM TRỰC TIẾP KHÔNG CẦN LOAD LẠI TRANG =================
        let isSearchOpen = false;
        let currentAdults = <?= $adults ?>;
        let currentChildren = <?= $children ?>;
        let currentInfants = <?= $infants ?>;

        // Bật / tắt khung tìm kiếm trực tiếp
        function toggleSearchBox() {
            isSearchOpen = !isSearchOpen;
            const box = document.getElementById('inlineSearchBox');
            const text = document.getElementById('toggleSearchBtnText');
            if (isSearchOpen) {
                box.classList.remove('hidden');
                text.textContent = 'Đóng';
            } else {
                box.classList.add('hidden');
                text.textContent = 'Đổi tìm kiếm';
            }
        }

        // Bật / tắt Popup Hành khách
        const passengerPopup = document.getElementById('passengerPopup');
        const passengerChevron = document.getElementById('passengerChevron');

        function togglePassengerDropdown(e) {
            e.stopPropagation();
            const isHidden = passengerPopup.classList.contains('hidden');
            if (isHidden) {
                passengerPopup.classList.remove('hidden');
                passengerChevron.classList.add('rotate-180');
            } else {
                closePassengerPopup();
            }
        }

        function closePassengerPopup() {
            passengerPopup.classList.add('hidden');
            passengerChevron.classList.remove('rotate-180');
        }

        document.addEventListener('click', function(e) {
            const container = document.getElementById('passengerDropdownContainer');
            if (container && !container.contains(e.target)) {
                closePassengerPopup();
            }
        });

        function modifyPassenger(type, delta) {
            if (type === 'adults') {
                currentAdults = Math.max(1, Math.min(9, currentAdults + delta));
                if (currentInfants > currentAdults) currentInfants = currentAdults;
            } else if (type === 'children') {
                currentChildren = Math.max(0, Math.min(9, currentChildren + delta));
            } else if (type === 'infants') {
                currentInfants = Math.max(0, Math.min(currentAdults, currentInfants + delta));
            }

            const total = currentAdults + currentChildren + currentInfants;
            document.getElementById('adultCountDisplay').textContent = currentAdults;
            document.getElementById('childCountDisplay').textContent = currentChildren;
            document.getElementById('infantCountDisplay').textContent = currentInfants;
            document.getElementById('popupTotalText').textContent = total + ' khách';
            document.getElementById('passengerDisplayText').textContent = String(total).padStart(2, '0') + ' Hành khách';
            document.getElementById('summaryPassenger').textContent = total + ' hành khách';
            updateFlightBookingLinks();
        }

        function updateFlightBookingLinks() {
            const totalPassengers = currentAdults + currentChildren + currentInfants;
            document.querySelectorAll('.booking-flight-btn').forEach(button => {
                const bookingUrl = new URL(button.href, window.location.href);
                bookingUrl.searchParams.set('adults', currentAdults);
                bookingUrl.searchParams.set('children', currentChildren);
                bookingUrl.searchParams.set('infants', currentInfants);
                bookingUrl.searchParams.set('passengers', totalPassengers);
                button.href = bookingUrl.toString();
            });
        }

        // Khi thay đổi ngày từ ô input date
        function onDateInputChange(val) {
            if (!val) return;
            const parts = val.split('-');
            if (parts.length === 3) {
                document.getElementById('editDateDisplay').value = `${parts[2]}/${parts[1]}/${parts[0]}`;
                document.getElementById('summaryDate').textContent = formatDateToVietnamese(val);
            }
        }

        function formatDateToVietnamese(dateStr) {
            const d = new Date(dateStr);
            if (isNaN(d.getTime())) return dateStr;
            const days = ['Chủ nhật', 'Thứ 2', 'Thứ 3', 'Thứ 4', 'Thứ 5', 'Thứ 6', 'Thứ 7'];
            const dayName = days[d.getDay()];
            const dayNum = String(d.getDate()).padStart(2, '0');
            const monthNum = String(d.getMonth() + 1).padStart(2, '0');
            const yearNum = d.getFullYear();
            return `${dayName}, ${dayNum} Th${monthNum} ${yearNum}`;
        }

        function selectDateTab(dateYmd) {
            document.getElementById('editDateHidden').value = dateYmd;
            reloadFlightSearch();
        }

        function shiftDateTab(days) {
            const selectedDate = document.getElementById('editDateHidden').value;
            const date = new Date(`${selectedDate}T00:00:00`);
            date.setDate(date.getDate() + days);
            const year = date.getFullYear();
            const month = String(date.getMonth() + 1).padStart(2, '0');
            const day = String(date.getDate()).padStart(2, '0');
            selectDateTab(`${year}-${month}-${day}`);
        }

        const dateTabsContainer = document.getElementById('dateTabsContainer');
        const activeDateTab = document.getElementById('activeDateTab');
        if (dateTabsContainer && activeDateTab) {
            dateTabsContainer.scrollLeft = activeDateTab.offsetLeft - (dateTabsContainer.clientWidth - activeDateTab.offsetWidth) / 2;
        }

        // Trích xuất mã sân bay
        function extractAirportCode(str, fallback) {
            const match = str.match(/\((.*?)\)/);
            if (match && match[1]) return match[1].trim().toUpperCase();
            const upper = str.toUpperCase();
            if (upper.includes('HỒ CHÍ MINH') || upper.includes('SÀI GÒN')) return 'SGN';
            if (upper.includes('HÀ NỘI')) return 'HAN';
            if (upper.includes('ĐÀ NẴNG')) return 'DAD';
            if (upper.includes('NHA TRANG') || upper.includes('CAM RANH')) return 'CXR';
            if (upper.includes('PHÚ QUỐC')) return 'PQC';
            if (upper.includes('ĐÀ LẠT')) return 'DLI';
            if (upper.includes('HẢI PHÒNG')) return 'HPH';
            if (upper.includes('CẦN THƠ')) return 'VCA';
            return fallback;
        }

        function reloadFlightSearch() {
            const fromVal = document.getElementById('editFromInput').value.trim();
            const toVal = document.getElementById('editToInput').value.trim();
            const dateVal = document.getElementById('editDateHidden').value;
            const url = `/WebsiteDatVe/flights/flights.php?from=${encodeURIComponent(fromVal)}&to=${encodeURIComponent(toVal)}&departDate=${encodeURIComponent(dateVal)}&adults=${currentAdults}&children=${currentChildren}&infants=${currentInfants}`;
            window.location.href = url;
        }

        function updateSearchInline(notify = true) {
            reloadFlightSearch();
        }

        document.getElementById('editFromInput')?.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') updateSearchInline();
        });
        document.getElementById('editToInput')?.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') updateSearchInline();
        });

        function escapeHtml(text) {
            const map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
            return text.replace(/[&<>"']/g, m => map[m]);
        }

        // Hiển thị thông báo Toast nổi (khớp giao diện thông báo xanh có dấu check tròn trắng) và tự động ẩn
        function showToast(message, type = 'success') {
            let toast = document.getElementById('flyingToast');
            if (toast) {
                toast.remove();
            }

            toast = document.createElement('div');
            toast.id = 'flyingToast';
            toast.className = 'fixed bottom-8 left-1/2 -translate-x-1/2 z-[9999] flex items-center gap-3 px-6 py-3.5 rounded-2xl shadow-2xl text-white text-xs sm:text-sm font-semibold transition-all duration-300 transform translate-y-6 opacity-0 pointer-events-auto border border-white/20 max-w-[92vw]';
            
            if (type === 'warning') {
                toast.style.backgroundColor = '#d97706';
                toast.style.boxShadow = '0 20px 25px -5px rgba(217, 119, 6, 0.4)';
            } else {
                toast.style.backgroundColor = '#00875a';
                toast.style.boxShadow = '0 20px 25px -5px rgba(0, 135, 90, 0.4)';
            }
            
            const badgeIcon = type === 'warning' 
                ? `<div class="w-6 h-6 rounded-full bg-white flex items-center justify-center text-amber-600 font-black text-xs shrink-0 shadow-sm"><i class="fa-solid fa-triangle-exclamation"></i></div>`
                : `<div class="w-6 h-6 rounded-full bg-white flex items-center justify-center text-[#00875a] font-black text-xs shrink-0 shadow-sm"><i class="fa-solid fa-check"></i></div>`;
            
            toast.innerHTML = `
                ${badgeIcon} 
                <span class="truncate">${message}</span>
                <button type="button" onclick="this.closest('#flyingToast').remove()" class="ml-2 text-white/70 hover:text-white transition text-xs shrink-0">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            `;
            document.body.appendChild(toast);

            // Trigger animation trượt mượt lên từ dưới
            setTimeout(() => {
                toast.classList.remove('translate-y-6', 'opacity-0');
                toast.classList.add('translate-y-0', 'opacity-100');
            }, 30);

            // Tự động thu gọn và biến mất sau 4.5 giây (hiển thị 1 lúc rồi tự động ẩn đi)
            clearTimeout(window.toastTimer);
            window.toastTimer = setTimeout(() => {
                toast.classList.remove('translate-y-0', 'opacity-100');
                toast.classList.add('translate-y-6', 'opacity-0');
                setTimeout(() => {
                    if (toast && toast.parentNode) {
                        toast.parentNode.removeChild(toast);
                    }
                }, 350);
            }, 4500);
        }
    </script>
</body>
</html>
