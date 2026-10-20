<?php
session_start();
require_once __DIR__ . '/../api/config/database.php';

// Nhận tham số từ URL — để trống nếu không có (người dùng phải nhập)
$pnrParam      = trim($_GET['pnr'] ?? $_GET['code'] ?? '');
$lastNameParam = trim($_GET['lastName'] ?? '');
$stepParam     = (int)($_GET['step'] ?? 1);
if ($stepParam < 1 || $stepParam > 5) $stepParam = 1;
$lookupError = '';

// Tra cứu dữ liệu thực tế từ CSDL
$dbBooking = null;
$dbPassengers = [];
$flightInfo = null;

if ($pnrParam !== '') {
    $pnrParam = strtoupper($pnrParam);
    $lastNameParam = preg_replace('/\s+/u', ' ', trim($lastNameParam));
    if (!$conn) {
        $lookupError = 'Không thể kết nối cơ sở dữ liệu. Vui lòng thử lại sau.';
    } elseif ($lastNameParam === '') {
        $lookupError = 'Vui lòng nhập họ của một hành khách trong mã đặt chỗ.';
    } else {
        $stmt = mysqli_prepare($conn,
            "SELECT b.*, f.flight_number, f.airline, f.from_airport, f.to_airport, f.depart_at, f.arrive_at, f.gate, f.aircraft,
                    dep.city AS from_city, arr.city AS to_city
             FROM bookings b
             JOIN flights f ON b.flight_id = f.id
             JOIN airports dep ON f.from_airport = dep.code
             JOIN airports arr ON f.to_airport = arr.code
             WHERE b.booking_code = ? LIMIT 1"
        );
        mysqli_stmt_bind_param($stmt, "s", $pnrParam);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $dbBooking = mysqli_fetch_assoc($res);
        mysqli_stmt_close($stmt);

        if (!$dbBooking) {
            $lookupError = 'Không tìm thấy mã đặt chỗ này. Vui lòng kiểm tra lại PNR.';
        } else {
            $normalizedLastName = function_exists('mb_strtoupper')
                ? mb_strtoupper($lastNameParam, 'UTF-8')
                : strtoupper($lastNameParam);
            $nameStmt = mysqli_prepare($conn,
                'SELECT id FROM passengers WHERE booking_id = ? AND UPPER(TRIM(last_name)) = ? LIMIT 1'
            );
            $bookingId = (int)$dbBooking['id'];
            mysqli_stmt_bind_param($nameStmt, 'is', $bookingId, $normalizedLastName);
            mysqli_stmt_execute($nameStmt);
            $nameMatch = mysqli_fetch_assoc(mysqli_stmt_get_result($nameStmt));
            mysqli_stmt_close($nameStmt);
            if (!$nameMatch) {
                $lookupError = 'Họ hành khách không khớp với mã đặt chỗ này.';
                $dbBooking = null;
            } elseif (($dbBooking['payment_status'] ?? '') !== 'paid') {
                $lookupError = 'Vé đặt chỗ này chưa thanh toán nên chưa thể làm thủ tục check-in.';
                $dbBooking = null;
            } elseif (($dbBooking['booking_status'] ?? '') !== 'confirmed') {
                $lookupError = 'Đặt chỗ chưa được xác nhận hoặc đã bị hủy nên không thể làm thủ tục check-in.';
                $dbBooking = null;
            } else {
                $pRes = mysqli_query($conn,
                    "SELECT p.*, fs.seat_code
                     FROM passengers p LEFT JOIN flight_seats fs ON fs.id = p.flight_seat_id
                     WHERE p.booking_id = {$bookingId}"
                );
                if ($pRes) {
                    $pIdx = 0;
                    while ($pRow = mysqli_fetch_assoc($pRes)) {
                        $dbPassengers[] = [
                            'id'    => $pIdx++,
                            'paxId' => (int)$pRow['id'],
                            'name'  => strtoupper($pRow['first_name'] . ' ' . $pRow['last_name']),
                            'doc'   => $pRow['id_number'] ?? '',
                            'seat'  => $pRow['seat_code']  ?? '',
                            'type'  => 'Ghế tiêu chuẩn',
                            'seatClass' => $pRow['seat_class'],
                            'checkinStatus' => (int)$pRow['checkin_status'],
                            'boardingPass' => $pRow['boarding_pass_code'] ?? '',
                            'baggageWeight' => (int)($pRow['baggage_weight'] ?? 0),
                            'baggagePrice' => (int)($pRow['baggage_price'] ?? 0),
                            'mealName' => $pRow['meal_name'] ?? '',
                            'mealPrice' => (int)($pRow['meal_price'] ?? 0),
                        ];
                    }
                }
            }
        }
    }
    if ($lookupError !== '') {
        $stepParam = 1;
    }
}
if ($dbBooking && $dbPassengers && count(array_filter(
    $dbPassengers,
    static fn(array $passenger): bool => $passenger['checkinStatus'] === 1
)) === count($dbPassengers)) {
    $checkinCompleted = true;
} else {
    $checkinCompleted = false;
}
if ($dbBooking && $stepParam === 5 && !$checkinCompleted) {
    $stepParam = 4;
}
if (!$dbBooking) {
    $stepParam = 1;
}
$seatRowsData = [];
$seatClassByCode = [];
$seatCodeByPosition = [];
if ($conn && $dbBooking) {
    $seatStmt = mysqli_prepare($conn,
        "SELECT seat_code, seat_class, is_booked FROM flight_seats
         WHERE flight_id = ? ORDER BY CAST(LEFT(seat_code, LENGTH(seat_code) - 1) AS UNSIGNED), seat_code"
    );
    if ($seatStmt) {
        $flightId = (int)$dbBooking['flight_id'];
        mysqli_stmt_bind_param($seatStmt, 'i', $flightId);
        if (mysqli_stmt_execute($seatStmt)) {
            $seatResult = mysqli_stmt_get_result($seatStmt);
            while ($seat = mysqli_fetch_assoc($seatResult)) {
                if (!preg_match('/^(\d+)([A-F])$/', $seat['seat_code'], $matches)) {
                    continue;
                }
                $rowNumber = (int)$matches[1];
                $seatRowsData[$rowNumber] ??= ['row' => $rowNumber, 'occupied' => []];
                $seatCodeByPosition[$rowNumber][$matches[2]] = $seat['seat_code'];
                if ((int)$seat['is_booked'] === 1) {
                    $seatRowsData[$rowNumber]['occupied'][] = $seat['seat_code'];
                }
                $seatClassByCode[$seat['seat_code']] = $seat['seat_class'];
            }
        }
        mysqli_stmt_close($seatStmt);
    }
    $seatRowsData = array_values($seatRowsData);
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Online Check-in - Làm thủ tục chuyến bay trực tuyến</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
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
    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; }
        .custom-scrollbar::-webkit-scrollbar { width: 6px; }
        .custom-scrollbar::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 9999px; }
        @media print {
            body * { visibility: hidden; }
            #printableBoardingPasses, #printableBoardingPasses * { visibility: visible; }
            #printableBoardingPasses { position: absolute; left: 0; top: 0; width: 100%; }
            .no-print { display: none !important; }
        }
    </style>
</head>
<body class="bg-[#f8fafc] text-slate-800 flex flex-col min-h-screen">

    <!-- NAVBAR HEADER -->
    <header class="sticky top-0 z-50 bg-white/95 backdrop-blur-md border-b border-slate-200 no-print">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 h-16 flex items-center justify-between">
            <a href="/WebsiteDatVe/index.php" class="flex items-center gap-2">
                <span class="w-10 h-10 rounded-xl bg-primary text-white flex items-center justify-center shadow-md shadow-blue-500/20">
                    <i class="fa-solid fa-plane text-lg"></i>
                </span>
                <span class="text-xl font-extrabold tracking-tight text-slate-900">Booking Online</span>
            </a>

            <nav class="hidden md:flex items-center gap-7 text-sm font-medium text-slate-600">
                <a href="/WebsiteDatVe/index.php" class="hover:text-primary transition">Trang chủ</a>
                <a href="/WebsiteDatVe/check-in/check-in.php" class="text-primary font-bold border-b-2 border-primary pb-1">Check-in online</a>
                <a href="/WebsiteDatVe/flight-status/flight-status.php" class="hover:text-primary transition">Tình trạng bay</a>
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

    <!-- MAIN CONTAINER -->
    <main class="max-w-6xl mx-auto px-4 sm:px-6 py-10 flex-1 w-full space-y-8">

        <!-- TIÊU ĐỀ TRANG CHECK-IN ONLINE -->
        <div class="text-center space-y-2 no-print">
            <h1 class="text-3xl sm:text-5xl font-black text-slate-900 tracking-tight">
                Online <span class="text-primary">Check-in</span>
            </h1>
            <p class="text-sm sm:text-base text-slate-500 max-w-2xl mx-auto font-medium">
                Nhập 1 mã PNR duy nhất để làm thủ tục đồng thời cho tất cả hành khách trong đoàn, chọn ghế và in thẻ vé riêng cho từng người.
            </p>
        </div>

        <!-- THANH TIẾN TRÌNH 5 BƯỚC (5 STEPS PROGRESS BAR) -->
        <div class="grid grid-cols-2 sm:grid-cols-5 gap-2.5 sm:gap-3.5 max-w-5xl mx-auto no-print" id="stepProgressTabs">
            <!-- BƯỚC 1 -->
            <div id="stepTab1" onclick="goToStep(1)" class="step-tab p-3 sm:p-3.5 rounded-2xl border-2 border-primary bg-white shadow-sm flex items-center gap-3 cursor-pointer transition">
                <span class="step-badge w-7 h-7 rounded-full bg-primary text-white font-bold text-xs flex items-center justify-center shrink-0 shadow-sm shadow-blue-500/30">
                    1
                </span>
                <div class="truncate">
                    <p class="text-xs sm:text-sm font-bold text-primary step-title">1. Tra cứu</p>
                    <p class="text-[11px] text-slate-400 font-medium truncate">PNR & Họ</p>
                </div>
            </div>

            <!-- BƯỚC 2 -->
            <div id="stepTab2" onclick="goToStep(2)" class="step-tab p-3 sm:p-3.5 rounded-2xl border border-slate-200 bg-white/70 flex items-center gap-3 cursor-pointer transition">
                <span class="step-badge w-7 h-7 rounded-full border border-slate-300 text-slate-400 font-bold text-xs flex items-center justify-center shrink-0">
                    2
                </span>
                <div class="truncate">
                    <p class="text-xs sm:text-sm font-bold text-slate-600 step-title">2. Hành khách</p>
                    <p class="text-[11px] text-slate-400 font-medium truncate">Thông tin</p>
                </div>
            </div>

            <!-- BƯỚC 3 -->
            <div id="stepTab3" onclick="goToStep(3)" class="step-tab p-3 sm:p-3.5 rounded-2xl border border-slate-200 bg-white/70 flex items-center gap-3 cursor-pointer transition">
                <span class="step-badge w-7 h-7 rounded-full border border-slate-300 text-slate-400 font-bold text-xs flex items-center justify-center shrink-0">
                    3
                </span>
                <div class="truncate">
                    <p class="text-xs sm:text-sm font-bold text-slate-600 step-title">3. Chọn ghế</p>
                    <p class="text-[11px] text-slate-400 font-medium truncate">Sơ đồ cabin</p>
                </div>
            </div>

            <!-- BƯỚC 4 -->
            <div id="stepTab4" onclick="goToStep(4)" class="step-tab p-3 sm:p-3.5 rounded-2xl border border-slate-200 bg-white/70 flex items-center gap-3 cursor-pointer transition">
                <span class="step-badge w-7 h-7 rounded-full border border-slate-300 text-slate-400 font-bold text-xs flex items-center justify-center shrink-0">
                    4
                </span>
                <div class="truncate">
                    <p class="text-xs sm:text-sm font-bold text-slate-600 step-title">4. Dịch vụ</p>
                    <p class="text-[11px] text-slate-400 font-medium truncate">Mua thêm</p>
                </div>
            </div>

            <!-- BƯỚC 5 -->
            <div id="stepTab5" onclick="goToStep(5)" class="step-tab col-span-2 sm:col-span-1 p-3 sm:p-3.5 rounded-2xl border border-slate-200 bg-white/70 flex items-center gap-3 cursor-pointer transition">
                <span class="step-badge w-7 h-7 rounded-full border border-slate-300 text-slate-400 font-bold text-xs flex items-center justify-center shrink-0">
                    5
                </span>
                <div class="truncate">
                    <p class="text-xs sm:text-sm font-bold text-slate-600 step-title">5. Nhận vé</p>
                    <p class="text-[11px] text-slate-400 font-medium truncate">In thẻ vé</p>
                </div>
            </div>
        </div>

        <!-- ================= BƯỚC 1: TRA CỨU ĐẶT CHỖ (LOOKUP) ================= -->
        <section id="stepSection1" class="step-section max-w-xl mx-auto space-y-4">
            <div class="bg-white rounded-3xl border border-slate-200 p-6 sm:p-8 shadow-sm space-y-6">
                <!-- MÃ ĐẶT CHỖ PNR -->
                <div>
                    <label class="block text-xs font-extrabold text-slate-700 uppercase tracking-wider mb-2">
                        MÃ ĐẶT CHỖ (PNR) <span class="text-rose-500">*</span>
                    </label>
                    <div class="relative flex items-center">
                        <span class="absolute left-4 text-slate-400 text-sm">
                            <i class="fa-solid fa-ticket-simple"></i>
                        </span>
                        <input type="text" id="pnrInput" value="<?= htmlspecialchars($pnrParam) ?>" placeholder="VD: BK892104, BK510237..."
                            class="w-full pl-11 pr-4 py-3.5 rounded-2xl border border-slate-200 bg-white text-sm font-bold text-slate-900 uppercase focus:outline-none focus:border-primary focus:ring-4 focus:ring-primary/10 transition">
                    </div>
                </div>

                <!-- HỌ ĐẠI DIỆN HÀNH KHÁCH -->
                <div>
                    <label class="block text-xs font-extrabold text-slate-700 uppercase tracking-wider mb-2">
                        HỌ ĐẠI DIỆN HÀNH KHÁCH <span class="text-rose-500">*</span>
                    </label>
                    <div class="relative flex items-center">
                        <span class="absolute left-4 text-slate-400 text-sm">
                            <i class="fa-regular fa-user"></i>
                        </span>
                        <input type="text" id="lastNameInput" value="<?= htmlspecialchars($lastNameParam) ?>" placeholder="VD: SMITH hoặc NGUYEN"
                            class="w-full pl-11 pr-4 py-3.5 rounded-2xl border border-slate-200 bg-white text-sm font-bold text-slate-900 uppercase focus:outline-none focus:border-primary focus:ring-4 focus:ring-primary/10 transition">
                    </div>
                </div>

                <!-- NÚT TÌM KIẾM ĐẶT CHỖ -->
                <button type="button" onclick="submitLookup()" class="w-full py-3.5 px-6 rounded-2xl bg-primary hover:bg-primary-hover text-white font-bold text-sm sm:text-base shadow-lg shadow-blue-500/25 transition hover:-translate-y-0.5 flex items-center justify-center gap-2">
                    <span>Tìm kiếm đặt chỗ</span>
                    <i class="fa-solid fa-arrow-right text-xs"></i>
                </button>
                <?php if ($lookupError !== ''): ?>
                    <div role="alert" class="rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-700">
                        <i class="fa-solid fa-circle-exclamation mr-1"></i><?= htmlspecialchars($lookupError, ENT_QUOTES, 'UTF-8') ?>
                    </div>
                <?php endif; ?>
            </div>


        </section>

        <!-- ================= BƯỚC 2: DANH SÁCH HÀNH KHÁCH (PASSENGERS) ================= -->
        <section id="stepSection2" class="step-section max-w-4xl mx-auto space-y-6 hidden">
            <?php if ($dbBooking): ?>
            <!-- THẺ THÔNG TIN CHUYẾN BAY TỪ DB -->
            <div class="bg-white rounded-3xl border border-slate-200 p-6 sm:p-7 shadow-sm space-y-6">
                <div class="flex flex-col md:flex-row md:items-center justify-between gap-6 pb-6 border-b border-slate-100">
                    <div class="flex items-center gap-3.5">
                        <div class="w-12 h-12 rounded-2xl bg-blue-50 border border-blue-100 text-primary flex items-center justify-center text-xl shadow-sm">
                            <i class="fa-solid fa-plane"></i>
                        </div>
                        <div>
                            <p class="text-[11px] font-extrabold text-slate-400 tracking-wider uppercase"><?= htmlspecialchars($dbBooking['airline']) ?></p>
                            <h2 class="text-2xl font-black text-slate-900 tracking-tight"><?= htmlspecialchars($dbBooking['flight_number']) ?></h2>
                        </div>
                    </div>
                    <div class="flex items-center justify-between md:justify-end gap-5 sm:gap-8 flex-1">
                        <div class="text-left md:text-right">
                            <p class="text-2xl sm:text-3xl font-black text-slate-900"><?= htmlspecialchars($dbBooking['from_airport']) ?></p>
                            <p class="text-xs font-semibold text-slate-500 mt-0.5"><?= htmlspecialchars($dbBooking['from_city']) ?></p>
                        </div>
                        <div class="flex flex-col items-center px-2">
                            <span class="text-xs font-bold text-primary mb-1"><?= date('H:i', strtotime($dbBooking['depart_at'])) ?></span>
                            <div class="relative flex items-center justify-center w-28 sm:w-36">
                                <div class="w-full h-0.5 bg-blue-200"></div>
                                <span class="absolute bg-white px-1 text-primary text-xs"><i class="fa-solid fa-plane"></i></span>
                            </div>
                            <span class="text-[11px] font-medium text-slate-400 mt-1"><?= date('d/m/Y', strtotime($dbBooking['depart_at'])) ?></span>
                        </div>
                        <div class="text-right">
                            <p class="text-2xl sm:text-3xl font-black text-slate-900"><?= htmlspecialchars($dbBooking['to_airport']) ?></p>
                            <p class="text-xs font-semibold text-slate-500 mt-0.5"><?= htmlspecialchars($dbBooking['to_city']) ?></p>
                        </div>
                    </div>
                </div>
                <div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
                    <div class="p-3.5 rounded-2xl bg-slate-50/80 border border-slate-100">
                        <p class="text-[11px] font-extrabold text-slate-400 uppercase tracking-wider flex items-center gap-1.5"><i class="fa-solid fa-users text-primary text-xs"></i> SỐ LƯỢNG KHÁCH</p>
                        <p class="text-sm font-bold text-slate-900 mt-1" id="paxCountText"><?= count($dbPassengers) ?> Hành khách</p>
                    </div>
                    <div class="p-3.5 rounded-2xl bg-slate-50/80 border border-slate-100">
                        <p class="text-[11px] font-extrabold text-slate-400 uppercase tracking-wider flex items-center gap-1.5"><i class="fa-solid fa-ticket text-primary text-xs"></i> MÃ PNR</p>
                        <p class="text-sm font-black text-slate-900 mt-1" id="pnrSummaryText"><?= htmlspecialchars($dbBooking['booking_code']) ?></p>
                    </div>
                    <div class="p-3.5 rounded-2xl bg-slate-50/80 border border-slate-100">
                        <p class="text-[11px] font-extrabold text-slate-400 uppercase tracking-wider flex items-center gap-1.5"><i class="fa-solid fa-star text-amber-500 text-xs"></i> TRẠNG THÁI</p>
                        <p class="text-sm font-bold text-slate-900 mt-1"><?= htmlspecialchars(bookingStatusLabel($dbBooking['booking_status'] ?? 'confirmed')) ?></p>
                    </div>
                    <div class="p-3.5 rounded-2xl bg-slate-50/80 border border-slate-100">
                        <p class="text-[11px] font-extrabold text-slate-400 uppercase tracking-wider flex items-center gap-1.5"><i class="fa-regular fa-building text-primary text-xs"></i> CỔNG (GATE)</p>
                        <p class="text-sm font-bold text-slate-900 mt-1"><?= htmlspecialchars($dbBooking['gate'] ?? '—') ?></p>
                    </div>
                </div>
            </div>

            <!-- DANH SÁCH HÀNH KHÁCH TỪ DB -->
            <div class="bg-white rounded-3xl border border-slate-200 p-6 sm:p-7 shadow-sm space-y-6">
                <div class="flex items-center justify-between pb-3 border-b border-slate-100">
                    <div class="flex items-center gap-3">
                        <span class="w-8 h-8 rounded-full bg-blue-100 text-primary flex items-center justify-center text-sm"><i class="fa-solid fa-users"></i></span>
                        <div>
                            <h3 class="text-base sm:text-lg font-bold text-slate-900">Danh sách Hành khách làm thủ tục</h3>
                            <p class="text-xs text-slate-400 mt-0.5">Mã PNR <strong class="text-slate-700" id="pnrSubText"><?= htmlspecialchars($dbBooking['booking_code']) ?></strong> gồm <?= count($dbPassengers) ?> hành khách</p>
                        </div>
                    </div>
                    <span class="px-3.5 py-1.5 rounded-full bg-blue-50 text-primary font-bold text-xs border border-blue-100"><?= count($dbPassengers) ?> vé riêng</span>
                </div>
                <div class="space-y-3.5">
                    <?php foreach ($dbPassengers as $pi => $paxRow): ?>
                    <div class="p-4 sm:p-5 rounded-2xl border border-slate-200 bg-white hover:border-primary/50 transition flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                        <div class="flex items-center gap-3.5">
                            <span class="w-8 h-8 rounded-full bg-blue-50 text-primary font-black text-xs flex items-center justify-center shrink-0"><?= str_pad($pi+1, 2, '0', STR_PAD_LEFT) ?></span>
                            <div>
                                <h4 class="text-sm sm:text-base font-extrabold text-slate-900 uppercase"><?= htmlspecialchars($paxRow['name']) ?></h4>
                                <div class="flex items-center gap-2 mt-1">
                                    <span class="text-xs text-slate-500 font-medium">CCCD/Hộ chiếu:</span>
                                    <span class="text-xs font-bold text-slate-900 font-mono"><?= htmlspecialchars($paxRow['doc'] ?: '—') ?></span>
                                </div>
                            </div>
                        </div>
                        <div class="flex items-center gap-3 flex-wrap">
                            <?php if ($paxRow['seat']): ?>
                            <span class="px-3 py-1.5 rounded-xl bg-blue-50 text-primary font-bold text-xs border border-blue-100">Ghế: <?= htmlspecialchars($paxRow['seat']) ?></span>
                            <?php else: ?>
                            <span class="px-3 py-1.5 rounded-xl bg-amber-50 text-amber-600 font-bold text-xs border border-amber-200">Chưa chọn ghế</span>
                            <?php endif; ?>
                            <span class="px-3 py-1.5 rounded-xl bg-emerald-50 text-emerald-600 font-bold text-xs border border-emerald-200">Phổ thông</span>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <div class="flex justify-end pt-2">
                    <button type="button" onclick="goToStep(3)" class="px-6 py-3 rounded-2xl bg-primary hover:bg-primary-hover text-white font-bold text-sm shadow-lg shadow-blue-500/25 transition hover:-translate-y-0.5 flex items-center gap-2">
                        <span>Tiếp tục chọn ghế</span><i class="fa-solid fa-arrow-right text-xs"></i>
                    </button>
                </div>
            </div>
            <?php else: ?>
            <div class="bg-white rounded-3xl border border-slate-200 p-10 shadow-sm text-center space-y-3">
                <i class="fa-solid fa-magnifying-glass text-4xl text-slate-300"></i>
                <p class="text-slate-500 font-medium">Vui lòng tra cứu mã PNR ở bước 1 trước.</p>
                <button onclick="goToStep(1)" class="px-5 py-2.5 rounded-xl bg-primary text-white text-sm font-bold">Quay lại tra cứu</button>
            </div>
            <?php endif; ?>
        </section>

        <!-- ================= BƯỚC 3: CHỌN GHẾ ================= -->
        <section id="stepSection3" class="step-section max-w-4xl mx-auto space-y-6 hidden">
            <?php if ($dbBooking && !empty($dbPassengers)): ?>
            <div class="bg-white rounded-3xl border border-slate-200 p-6 sm:p-7 shadow-sm space-y-4">
                <div class="flex items-center gap-3">
                    <span class="w-8 h-8 rounded-full bg-blue-100 text-primary flex items-center justify-center text-sm"><i class="fa-solid fa-chair"></i></span>
                    <div>
                        <h3 class="text-base sm:text-lg font-bold text-slate-900">Chọn ghế ngồi</h3>
                        <p class="text-xs text-slate-400 mt-0.5">Đang chọn ghế cho: <strong class="text-primary font-black" id="curPaxName"><?= htmlspecialchars($dbPassengers[0]['name'] ?? '') ?></strong></p>
                    </div>
                </div>
                <!-- Bộ chọn hành khách -->
                <div class="flex flex-wrap gap-2" id="paxSelectorBtns">
                    <?php foreach ($dbPassengers as $pi => $paxRow): ?>
                    <button type="button" onclick="selectPax(<?= $pi ?>)"
                        class="pax-selector-btn px-4 py-2 rounded-xl text-xs font-bold border transition <?= $pi===0 ? 'bg-primary text-white border-primary' : 'bg-white text-slate-700 border-slate-200 hover:border-primary' ?>">
                        <span><?= htmlspecialchars($paxRow['name']) ?></span>
                        <span class="ml-1 rounded-lg px-2 py-0.5 text-[10px] <?= $paxRow['seat'] ? 'bg-blue-100 text-blue-800' : 'bg-slate-100 text-slate-500' ?>">
                            <?= $paxRow['seat'] ? 'Ghế ' . htmlspecialchars($paxRow['seat']) : 'Chưa chọn' ?>
                        </span>
                    </button>
                    <?php endforeach; ?>
                </div>
                <!-- Sơ đồ cabin lấy trạng thái ghế thật của chuyến bay -->
                <div class="overflow-x-auto">
                    <div class="inline-block min-w-full">
                        <div class="flex gap-1.5 mb-3 justify-center text-[10px] font-bold text-slate-400">
                            <span class="w-8 text-center">A</span><span class="w-8 text-center">B</span><span class="w-8 text-center">C</span>
                            <span class="w-6"></span>
                            <span class="w-8 text-center">D</span><span class="w-8 text-center">E</span><span class="w-8 text-center">F</span>
                        </div>
                        <div id="seatMap" class="space-y-1.5 max-h-72 overflow-y-auto pr-1">
                            <?php foreach ($seatRowsData as $seatRow): $row = (int)$seatRow['row']; ?>
                            <div class="flex items-center gap-1.5 justify-center">
                                <span class="text-[10px] text-slate-400 w-5 text-right shrink-0"><?= $row ?></span>
                                <?php foreach (['A','B','C'] as $col):
                                    $sCode = $seatCodeByPosition[$row][$col] ?? null;
                                    $seatExists = $sCode !== null;
                                    $assignedIndex = null;
                                    foreach ($dbPassengers as $paxIndex => $pp) { if ($sCode !== null && $pp['seat'] === $sCode) { $assignedIndex = $paxIndex; break; } }
                                    $occupiedByOther = in_array($sCode, $seatRow['occupied'], true) && $assignedIndex === null;
                                    $isSelected = $assignedIndex === 0;
                                ?>
                                <?php if ($seatExists): ?>
                                <button type="button" onclick="selectSeat('<?= $sCode ?>')"
                                    data-seat="<?= $sCode ?>" data-assigned-index="<?= $assignedIndex === null ? '' : $assignedIndex ?>"
                                    data-original-assigned-index="<?= $assignedIndex === null ? '' : $assignedIndex ?>"
                                    data-seat-class="<?= htmlspecialchars($seatClassByCode[$sCode], ENT_QUOTES, 'UTF-8') ?>"
                                    data-seat-occupied="<?= $occupiedByOther ? '1' : '0' ?>"
                                    class="seat-btn w-9 h-8 rounded-lg text-[10px] font-bold transition border <?= $isSelected ? 'bg-primary text-white border-primary ring-2 ring-blue-200' : ($assignedIndex !== null ? 'bg-amber-100 text-amber-800 border-amber-300' : ($occupiedByOther ? 'bg-slate-200 text-slate-400 border-slate-200 cursor-not-allowed' : 'bg-emerald-50 text-emerald-700 border-emerald-200 hover:bg-primary hover:text-white hover:border-primary')) ?>"
                                    <?= $occupiedByOther ? 'disabled' : '' ?>
                                    title="<?= $assignedIndex !== null ? 'Đã gán cho ' . htmlspecialchars($dbPassengers[$assignedIndex]['name'], ENT_QUOTES, 'UTF-8') : ($occupiedByOther ? 'Ghế đã được đặt' : 'Ghế trống') ?>">
                                    <?= $isSelected ? '<i class="fa-solid fa-check"></i>' : ($assignedIndex !== null ? '<i class="fa-solid fa-user"></i>' : htmlspecialchars($sCode, ENT_QUOTES, 'UTF-8')) ?>
                                </button>
                                <?php else: ?><span class="w-9 h-8"></span><?php endif; ?>
                                <?php endforeach; ?>
                                <div class="w-4"></div>
                                <?php foreach (['D','E','F'] as $col):
                                    $sCode = $seatCodeByPosition[$row][$col] ?? null;
                                    $seatExists = $sCode !== null;
                                    $assignedIndex = null;
                                    foreach ($dbPassengers as $paxIndex => $pp) { if ($sCode !== null && $pp['seat'] === $sCode) { $assignedIndex = $paxIndex; break; } }
                                    $occupiedByOther = in_array($sCode, $seatRow['occupied'], true) && $assignedIndex === null;
                                    $isSelected = $assignedIndex === 0;
                                ?>
                                <?php if ($seatExists): ?>
                                <button type="button" onclick="selectSeat('<?= $sCode ?>')"
                                    data-seat="<?= $sCode ?>" data-assigned-index="<?= $assignedIndex === null ? '' : $assignedIndex ?>"
                                    data-original-assigned-index="<?= $assignedIndex === null ? '' : $assignedIndex ?>"
                                    data-seat-class="<?= htmlspecialchars($seatClassByCode[$sCode], ENT_QUOTES, 'UTF-8') ?>"
                                    data-seat-occupied="<?= $occupiedByOther ? '1' : '0' ?>"
                                    class="seat-btn w-9 h-8 rounded-lg text-[10px] font-bold transition border <?= $isSelected ? 'bg-primary text-white border-primary ring-2 ring-blue-200' : ($assignedIndex !== null ? 'bg-amber-100 text-amber-800 border-amber-300' : ($occupiedByOther ? 'bg-slate-200 text-slate-400 border-slate-200 cursor-not-allowed' : 'bg-emerald-50 text-emerald-700 border-emerald-200 hover:bg-primary hover:text-white hover:border-primary')) ?>"
                                    <?= $occupiedByOther ? 'disabled' : '' ?>
                                    title="<?= $assignedIndex !== null ? 'Đã gán cho ' . htmlspecialchars($dbPassengers[$assignedIndex]['name'], ENT_QUOTES, 'UTF-8') : ($occupiedByOther ? 'Ghế đã được đặt' : 'Ghế trống') ?>">
                                    <?= $isSelected ? '<i class="fa-solid fa-check"></i>' : ($assignedIndex !== null ? '<i class="fa-solid fa-user"></i>' : htmlspecialchars($sCode, ENT_QUOTES, 'UTF-8')) ?>
                                </button>
                                <?php else: ?><span class="w-9 h-8"></span><?php endif; ?>
                                <?php endforeach; ?>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="flex flex-wrap items-center gap-4 mt-3 justify-center text-xs text-slate-500">
                            <span class="flex items-center gap-1"><span class="w-4 h-4 rounded bg-emerald-50 border border-emerald-200 inline-block"></span> Trống</span>
                            <span class="flex items-center gap-1"><span class="w-4 h-4 rounded bg-primary inline-flex items-center justify-center text-white text-[9px]"><i class="fa-solid fa-check"></i></span> Ghế hành khách đang chọn</span>
                            <span class="flex items-center gap-1"><span class="w-4 h-4 rounded bg-amber-100 border border-amber-300 inline-flex items-center justify-center text-amber-800 text-[9px]"><i class="fa-solid fa-user"></i></span> Ghế người cùng đoàn</span>
                            <span class="flex items-center gap-1"><span class="w-4 h-4 rounded bg-slate-200 inline-block"></span> Đã đặt</span>
                        </div>
                    </div>
                </div>
                <div class="flex justify-between pt-2">
                    <button type="button" onclick="goToStep(2)" class="px-5 py-3 rounded-2xl bg-white border border-slate-200 text-slate-700 font-bold text-sm hover:border-primary hover:text-primary transition flex items-center gap-2">
                        <i class="fa-solid fa-arrow-left text-xs"></i><span>Quay lại</span>
                    </button>
                    <button type="button" onclick="goToStep(4)" class="px-6 py-3 rounded-2xl bg-primary hover:bg-primary-hover text-white font-bold text-sm shadow-lg shadow-blue-500/25 transition hover:-translate-y-0.5 flex items-center gap-2">
                        <span>Tiếp tục dịch vụ</span><i class="fa-solid fa-arrow-right text-xs"></i>
                    </button>
                </div>
            </div>
            <?php else: ?>
            <div class="bg-white rounded-3xl border border-slate-200 p-10 shadow-sm text-center space-y-3">
                <i class="fa-solid fa-chair text-4xl text-slate-300"></i>
                <p class="text-slate-500 font-medium">Vui lòng tra cứu mã PNR ở bước 1 trước.</p>
                <button onclick="goToStep(1)" class="px-5 py-2.5 rounded-xl bg-primary text-white text-sm font-bold">Quay lại tra cứu</button>
            </div>
            <?php endif; ?>
        </section>

        <!-- ================= BƯỚC 4: DỊCH VỤ MUA THÊM ================= -->
        <section id="stepSection4" class="step-section max-w-3xl mx-auto space-y-6 hidden">
            <div class="bg-white rounded-3xl border border-slate-200 p-6 sm:p-7 shadow-sm space-y-5">
                <div class="flex items-center gap-3">
                    <span class="w-8 h-8 rounded-full bg-blue-100 text-primary flex items-center justify-center text-sm"><i class="fa-solid fa-bag-shopping"></i></span>
                    <div>
                        <h3 class="text-base sm:text-lg font-bold text-slate-900">Dịch vụ bổ sung theo hành khách</h3>
                        <p class="text-xs text-slate-500">Chọn hành khách rồi tùy chỉnh hành lý và suất ăn riêng.</p>
                    </div>
                </div>
                <div class="flex flex-wrap gap-2" role="group" aria-label="Chọn hành khách dịch vụ">
                    <?php foreach ($dbPassengers as $pi => $paxRow): ?>
                    <button type="button" id="addonPaxTab<?= $pi ?>" onclick="switchAddonPax(<?= $pi ?>)"
                        class="addon-pax-tab rounded-full border px-4 py-2 text-xs font-bold transition <?= $pi === 0 ? 'border-primary bg-primary text-white' : 'border-slate-200 bg-white text-slate-700 hover:border-primary' ?>">
                        <?= htmlspecialchars($paxRow['name'], ENT_QUOTES, 'UTF-8') ?>
                    </button>
                    <?php endforeach; ?>
                </div>
                <p class="rounded-xl bg-blue-50 px-4 py-3 text-xs font-semibold text-blue-800">
                    Đang chọn dịch vụ cho: <span id="currentAddonPassenger" class="font-black"><?= htmlspecialchars($dbPassengers[0]['name'] ?? '', ENT_QUOTES, 'UTF-8') ?></span>
                </p>
                <!-- Hành lý -->
                <div class="space-y-2">
                    <p class="text-xs font-extrabold text-slate-500 uppercase tracking-wider flex items-center gap-2"><i class="fa-solid fa-suitcase text-primary"></i> Hành lý ký gửi</p>
                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-2">
                        <?php foreach ([['free','Miễn phí (7kg)','0'],['10kg','+ 10kg','150,000đ'],['20kg','+ 20kg','290,000đ'],['30kg','+ 30kg','420,000đ']] as [$val,$label,$price]): ?>
                        <label class="cursor-pointer">
                            <input type="radio" id="baggageInput_<?= $val ?>" name="baggage" value="<?= $val ?>" class="sr-only" <?= $val==='free'?'checked':'' ?> onchange="selectBaggage('<?= $val ?>')">
                            <div id="baggageOpt_<?= $val ?>" class="addon-card p-3 rounded-2xl border border-slate-200 bg-white text-center transition">
                                <p class="text-xs font-bold text-slate-900"><?= $label ?></p>
                                <p class="text-xs text-primary font-extrabold mt-0.5"><?= $price ?></p>
                            </div>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <!-- Suất ăn -->
                <div class="space-y-2">
                    <p class="text-xs font-extrabold text-slate-500 uppercase tracking-wider flex items-center gap-2"><i class="fa-solid fa-utensils text-primary"></i> Suất ăn trên máy bay</p>
                    <div class="grid grid-cols-3 gap-2">
                        <?php foreach ([['free','Không đặt','0đ'],['beef','Thịt bò','85,000đ'],['veggie','Chay','70,000đ']] as [$val,$label,$price]): ?>
                        <label class="cursor-pointer">
                        <input type="radio" id="mealInput_<?= $val ?>" name="meal" value="<?= $val ?>" class="sr-only" <?= $val==='free'?'checked':'' ?> onchange="selectMeal('<?= $val ?>')">
                        <div id="mealOpt_<?= $val ?>" class="addon-card p-3 rounded-2xl border border-slate-200 bg-white text-center transition">
                                <p class="text-xs font-bold text-slate-900"><?= $label ?></p>
                                <p class="text-xs text-primary font-extrabold mt-0.5"><?= $price ?></p>
                            </div>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="flex items-center justify-between border-t border-slate-100 pt-4 text-sm">
                    <span class="font-semibold text-slate-600">Tổng dịch vụ đã chọn</span>
                    <strong id="addonTotalAmount" class="text-lg font-black text-primary">0đ</strong>
                </div>
                <div class="flex justify-between pt-2">
                    <button type="button" onclick="goToStep(3)" class="px-5 py-3 rounded-2xl bg-white border border-slate-200 text-slate-700 font-bold text-sm hover:border-primary hover:text-primary transition flex items-center gap-2">
                        <i class="fa-solid fa-arrow-left text-xs"></i><span>Quay lại</span>
                    </button>
                    <button type="button" id="completeCheckInButton" onclick="handleConfirmStep4()" class="px-6 py-3 rounded-2xl bg-primary hover:bg-primary-hover text-white font-bold text-sm shadow-lg shadow-blue-500/25 transition hover:-translate-y-0.5 flex items-center gap-2">
                        <span id="btnConfirmStep4Text">Xác nhận check-in</span><i class="fa-solid fa-arrow-right text-xs"></i>
                    </button>
                </div>
                <div id="checkinFeedback" role="alert" class="hidden rounded-2xl border px-4 py-3 text-sm font-semibold"></div>
            </div>
        </section>

        <!-- ================= BƯỚC 5: THẺ LÊN MÁY BAY (BOARDING PASS) ================= -->
        <section id="stepSection5" class="step-section max-w-4xl mx-auto space-y-6 hidden">
            <div class="no-print flex items-center justify-between">
                <h3 class="text-lg font-bold text-slate-900">Thẻ lên máy bay của đoàn</h3>
                <?php if ($dbBooking): ?>
                <span class="text-sm text-slate-500">Mã đặt chỗ: <strong class="text-slate-700"><?= htmlspecialchars($dbBooking['booking_code']) ?></strong></span>
                <?php endif; ?>
            </div>
            <div id="printableBoardingPasses" class="space-y-6">
                <?php if ($dbBooking && !empty($dbPassengers)):
                    foreach ($dbPassengers as $pi => $paxRow):
                        $seatCode = $paxRow['seat'] ?: '—';
                        $bpCode   = $paxRow['boardingPass'];
                        $qrData   = urlencode($bpCode ?: ($dbBooking['flight_number'] . '-' . $paxRow['name'] . '-' . $seatCode . '-' . $dbBooking['booking_code']));
                ?>
                <!-- Thẻ lên máy bay hành khách <?= $pi+1 ?> -->
                <div class="boarding-pass bg-gradient-to-r from-blue-700 via-primary to-blue-500 rounded-3xl p-5 sm:p-7 text-white shadow-2xl shadow-blue-500/30 relative overflow-hidden">
                    <div class="absolute inset-0 opacity-5 pointer-events-none" style="background:repeating-linear-gradient(45deg,#fff 0,#fff 1px,transparent 0,transparent 50%) 0/8px 8px"></div>
                    <div class="relative z-10 space-y-5">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-[11px] tracking-wider uppercase text-blue-200 font-bold"><?= htmlspecialchars($dbBooking['airline']) ?></p>
                                <h2 class="text-3xl font-black tracking-tight"><?= htmlspecialchars($dbBooking['flight_number']) ?></h2>
                            </div>
                            <span class="px-3.5 py-1 rounded-full bg-white/20 text-xs font-mono font-bold">PNR: <strong><?= htmlspecialchars($dbBooking['booking_code']) ?></strong></span>
                        </div>
                        <div class="flex items-center gap-6">
                            <div>
                                <p class="text-4xl font-black"><?= htmlspecialchars($dbBooking['from_airport']) ?></p>
                                <p class="text-xs text-blue-200 mt-0.5"><?= htmlspecialchars($dbBooking['from_city']) ?></p>
                            </div>
                            <div class="flex flex-col items-center flex-1">
                                <span class="text-xs font-bold text-blue-200 mb-1"><?= htmlspecialchars($dbBooking['flight_number']) ?></span>
                                <div class="relative w-full flex items-center">
                                    <div class="w-full h-0.5 bg-white/40"></div>
                                    <span class="absolute right-0 text-white text-xs"><i class="fa-solid fa-plane"></i></span>
                                </div>
                                <span class="text-[10px] text-blue-200 mt-1"><?= date('d/m/Y', strtotime($dbBooking['depart_at'])) ?></span>
                            </div>
                            <div class="text-right">
                                <p class="text-4xl font-black"><?= htmlspecialchars($dbBooking['to_airport']) ?></p>
                                <p class="text-xs text-blue-200 mt-0.5"><?= htmlspecialchars($dbBooking['to_city']) ?></p>
                            </div>
                        </div>
                        <div class="border-t border-white/20 pt-4 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                            <div class="space-y-1">
                                <p class="text-[11px] uppercase tracking-wider text-blue-200 font-bold">Hành khách</p>
                                <p class="text-sm sm:text-base font-black uppercase"><?= htmlspecialchars($paxRow['name']) ?></p>
                            </div>
                            <div class="flex items-center gap-5 flex-wrap">
                                <div class="text-center">
                                    <p class="text-[11px] uppercase tracking-wider text-blue-200 font-bold">Ghế</p>
                                    <p class="text-xl font-black"><?= htmlspecialchars($seatCode) ?></p>
                                </div>
                                <div class="text-center">
                                    <p class="text-[11px] uppercase tracking-wider text-blue-200 font-bold">Cổng</p>
                                    <p class="text-xl font-black"><?= htmlspecialchars($dbBooking['gate'] ?? '—') ?></p>
                                </div>
                                <div class="text-center">
                                    <p class="text-[11px] uppercase tracking-wider text-blue-200 font-bold">Giờ bay</p>
                                    <p class="text-xl font-black"><?= date('H:i', strtotime($dbBooking['depart_at'])) ?></p>
                                </div>
                            </div>
                            <div class="flex flex-col items-center gap-1">
                                <img src="https://api.qrserver.com/v1/create-qr-code/?size=100x100&data=<?= $qrData ?>" alt="QR" class="w-20 h-20 sm:w-24 sm:h-24 rounded-xl bg-white p-1">
                                <p class="font-mono text-[10px] text-blue-200 font-bold tracking-widest"><?= htmlspecialchars($bpCode ?: 'CHECK-IN') ?></p>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach;
                else: ?>
                <div class="bg-white rounded-3xl border border-slate-200 p-10 text-center space-y-3">
                    <i class="fa-solid fa-id-card text-4xl text-slate-300"></i>
                    <p class="text-slate-500 font-medium">Chưa có thẻ lên máy bay. Vui lòng hoàn tất tra cứu và check-in trước.</p>
                    <button onclick="goToStep(1)" class="px-5 py-2.5 rounded-xl bg-primary text-white text-sm font-bold">Tra cứu đặt chỗ</button>
                </div>
                <?php endif; ?>
            </div>
            <div class="pt-4 flex flex-wrap items-center justify-center gap-3.5 no-print">
                <button type="button" onclick="window.print()" class="px-6 py-3.5 rounded-2xl bg-primary hover:bg-primary-hover text-white font-bold text-sm shadow-lg shadow-blue-500/25 transition hover:-translate-y-0.5 flex items-center gap-2">
                    <i class="fa-solid fa-print"></i><span>In Tất Cả Vé (<?= count($dbPassengers) ?> Vé)</span>
                </button>
                <a href="/WebsiteDatVe/index.php" class="px-5 py-3.5 rounded-2xl bg-white border border-slate-200 text-slate-700 hover:border-primary hover:text-primary font-bold text-sm shadow-sm transition flex items-center gap-2">
                    <i class="fa-solid fa-house"></i><span>Về trang chủ</span>
                </a>
            </div>
        </section>


    </main>

    <div id="paymentRequiredModal" class="fixed inset-0 z-[100] hidden overflow-y-auto bg-slate-950/60 p-4 backdrop-blur-sm" role="dialog" aria-modal="true" aria-labelledby="paymentModalTitle">
        <div class="mx-auto my-6 w-full max-w-2xl overflow-hidden rounded-3xl bg-white shadow-2xl">
            <div class="flex items-start justify-between gap-4 border-b border-slate-100 px-5 py-5 sm:px-7">
                <div>
                    <p class="text-xs font-extrabold uppercase tracking-widest text-primary">Thanh toán dịch vụ</p>
                    <h2 id="paymentModalTitle" class="mt-1 text-xl font-black text-slate-900">Xác nhận dịch vụ mua thêm</h2>
                    <p class="mt-1 text-sm text-slate-500">Kiểm tra dịch vụ và số tiền trước khi hoàn tất check-in.</p>
                </div>
                <button type="button" onclick="closePaymentModal()" aria-label="Đóng cửa sổ thanh toán" class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-slate-100 text-slate-500 transition hover:bg-slate-200">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>
            <div class="space-y-5 px-5 py-5 sm:px-7">
                <div id="modalAddonBreakdown" class="max-h-44 space-y-3 overflow-y-auto rounded-2xl bg-slate-50 p-4 text-sm"></div>
                <div class="grid gap-4 sm:grid-cols-[1fr_auto] sm:items-center">
                    <div>
                        <p class="mb-2 text-xs font-extrabold uppercase tracking-wide text-slate-500">Phương thức thanh toán demo</p>
                        <div class="grid grid-cols-3 gap-2 text-center text-xs font-bold">
                            <label class="cursor-pointer rounded-xl border-2 border-primary bg-blue-50 p-3 text-primary">
                                <input type="radio" name="checkinPayMethod" value="VietQR" checked onchange="updatePaymentMethodCards()" class="sr-only">
                                <i class="fa-solid fa-qrcode mb-1 block text-lg"></i>VietQR
                            </label>
                            <label class="cursor-pointer rounded-xl border border-slate-200 p-3 text-slate-600">
                                <input type="radio" name="checkinPayMethod" value="Thẻ" onchange="updatePaymentMethodCards()" class="sr-only">
                                <i class="fa-regular fa-credit-card mb-1 block text-lg"></i>Thẻ
                            </label>
                            <label class="cursor-pointer rounded-xl border border-slate-200 p-3 text-slate-600">
                                <input type="radio" name="checkinPayMethod" value="Ví điện tử" onchange="updatePaymentMethodCards()" class="sr-only">
                                <i class="fa-solid fa-wallet mb-1 block text-lg"></i>Ví điện tử
                            </label>
                        </div>
                    </div>
                    <div class="flex items-center gap-3 rounded-2xl border border-slate-200 p-3 sm:flex-col sm:text-center">
                        <img id="modalQrCodeImg" src="" alt="Mã QR thanh toán mô phỏng" class="h-20 w-20 rounded-lg border border-slate-100 bg-white p-1">
                        <div>
                            <p class="text-[10px] font-bold uppercase text-slate-400">Tổng thanh toán</p>
                            <p id="modalTotalAmountText" class="text-lg font-black text-primary">0đ</p>
                        </div>
                    </div>
                </div>
                <div class="flex items-center justify-between border-t border-slate-100 pt-4">
                    <span class="text-sm font-bold text-slate-600">Cần thanh toán</span>
                    <strong id="modalFinalTotalText" class="text-xl font-black text-slate-900">0đ</strong>
                </div>
                <p class="rounded-xl bg-amber-50 px-3 py-2 text-xs text-amber-800">
                    <i class="fa-solid fa-circle-info mr-1"></i> Đây là thanh toán mô phỏng, không phát sinh giao dịch thật.
                </p>
                <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                    <button type="button" onclick="closePaymentModal()" class="rounded-xl border border-slate-200 px-5 py-3 text-sm font-bold text-slate-600 transition hover:bg-slate-50">Quay lại chỉnh dịch vụ</button>
                    <button type="button" id="btnProcessPayment" onclick="executePaymentProcess()" class="rounded-xl bg-primary px-5 py-3 text-sm font-bold text-white shadow-lg shadow-blue-500/20 transition hover:bg-primary-hover">
                        <span id="btnProcessPaymentText"><i class="fa-solid fa-lock mr-1"></i> Xác nhận thanh toán demo</span>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- FOOTER -->
    <footer class="mt-auto bg-white border-t border-slate-200 no-print">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 py-10">
            <div class="flex flex-col sm:flex-row items-center justify-between gap-4 text-xs text-slate-500">
                <div class="flex items-center gap-2">
                    <span class="w-6 h-6 rounded-lg bg-primary text-white flex items-center justify-center text-xs">
                        <i class="fa-solid fa-plane"></i>
                    </span>
                    <span class="font-extrabold text-slate-900">Booking Online · Check-in Service</span>
                </div>
                <p>&copy; <?= date('Y') ?> Booking Online. Dịch vụ làm thủ tục chuyến bay trực tuyến.</p>
            </div>
        </div>
    </footer>

    <!-- ================= JAVASCRIPT ĐIỀU HƯỚNG & DỊCH VỤ MUA THÊM & THANH TOÁN ================= -->
    <script>
        // Dữ liệu hành khách từ CSDL (rỗng nếu chưa tra cứu)
        let passengers = <?= json_encode($dbPassengers, JSON_UNESCAPED_UNICODE) ?>;

        let currentPaxIndex = 0; // Đang chọn ghế cho khách nào (0 hoặc 1)
        let currentStep = <?= $stepParam ?>;
        const checkinCompleted = <?= $checkinCompleted ? 'true' : 'false' ?>;

        // Cấu hình bảng giá dịch vụ mua thêm
        const addonPrices = {
            baggage: {
                free: 0,
                '10kg': 150000,
                '20kg': 290000,
                '30kg': 420000
            },
            meal: {
                free: 0,
                beef: 85000,
                veggie: 70000
            },
            priority: {
                fasttrack: 120000,
                insurance: 65000
            }
        };

        const addonLabels = {
            baggage: {
                free: '7kg Xách tay tiêu chuẩn (Miễn phí)',
                '10kg': '+10kg Ký gửi thêm (150.000đ)',
                '20kg': '+20kg Ký gửi thêm (290.000đ)',
                '30kg': '+30kg Ký gửi thêm (420.000đ)'
            },
            meal: {
                free: 'Suất ăn tiêu chuẩn (Miễn phí)',
                beef: '🥩 Cơm bò sốt tiêu đen (85.000đ)',
                veggie: '🥗 Cơm chay dưỡng sinh (70.000đ)'
            },
            priority: {
                fasttrack: 'Lối đi ưu tiên Fast-Track (120.000đ)',
                insurance: 'Bọc hành lý & Bảo hiểm thất lạc 10tr (65.000đ)'
            }
        };

        // Lưu lựa chọn dịch vụ của từng khách
        let passengerAddons = passengers.map(() => ({ baggage: 'free', meal: 'free', priority: [] }));

        let currentAddonPax = 0; // Đang chọn dịch vụ cho khách 0 hay 1
        let isPaidAddons = false; // Đã thanh toán tiền dịch vụ hay chưa
        let activeBoardingPassView = 0; // Đang xem vé 0 hay 1

        // Cấu hình các hàng ghế mô phỏng trên khoang máy bay (Hàng 8 đến 15)
        const seatRowsData = <?= json_encode($seatRowsData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
        const seatClassByCode = <?= json_encode($seatClassByCode, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

        // Khởi tạo trang
        window.addEventListener('DOMContentLoaded', () => {
            renderSeatMap();
            updateAddonUI();
            goToStep(currentStep);
        });

        function formatVND(amount) {
            return new Intl.NumberFormat('vi-VN').format(amount) + 'đ';
        }


        // Bước 1: Tra cứu PNR
        function submitLookup() {
            const pnr = document.getElementById('pnrInput').value.trim().toUpperCase();
            const lastName = document.getElementById('lastNameInput').value.trim().replace(/\s+/g, ' ').toUpperCase();

            if (!pnr) {
                alert('Vui lòng nhập Mã đặt chỗ (PNR)!');
                return;
            }
            if (!lastName) {
                alert('Vui lòng nhập Họ đại diện hành khách!');
                return;
            }

            const params = new URLSearchParams({ pnr, lastName, step: '2' });
            window.location.assign(`/WebsiteDatVe/check-in/check-in.php?${params.toString()}`);
        }

        // Chuyển bước trong tiến trình 5 bước
        function goToStep(step) {
            if (step === 5 && !checkinCompleted) {
                alert('Vui lòng hoàn tất check-in trước khi xem thẻ lên máy bay.');
                return;
            }
            currentStep = step;

            for (let i = 1; i <= 5; i++) {
                const sec = document.getElementById('stepSection' + i);
                if (sec) sec.classList.add('hidden');
            }

            const targetSec = document.getElementById('stepSection' + step);
            if (targetSec) targetSec.classList.remove('hidden');

            // Cập nhật giao diện 5 tab
            for (let i = 1; i <= 5; i++) {
                const tab = document.getElementById('stepTab' + i);
                const badge = tab.querySelector('.step-badge');
                const title = tab.querySelector('.step-title');

                if (i < step) {
                    tab.className = 'step-tab p-3 sm:p-3.5 rounded-2xl border border-emerald-500 bg-emerald-50/50 flex items-center gap-3 cursor-pointer transition';
                    badge.className = 'step-badge w-7 h-7 rounded-full bg-emerald-600 text-white font-bold text-xs flex items-center justify-center shrink-0 shadow-sm';
                    badge.innerHTML = '<i class="fa-solid fa-check"></i>';
                    title.className = 'text-xs sm:text-sm font-bold text-emerald-800 step-title';
                } else if (i === step) {
                    tab.className = 'step-tab p-3 sm:p-3.5 rounded-2xl border-2 border-primary bg-white shadow-sm flex items-center gap-3 cursor-pointer transition';
                    badge.className = 'step-badge w-7 h-7 rounded-full bg-primary text-white font-bold text-xs flex items-center justify-center shrink-0 shadow-sm shadow-blue-500/30';
                    badge.textContent = i;
                    title.className = 'text-xs sm:text-sm font-bold text-primary step-title';
                } else {
                    tab.className = 'step-tab p-3 sm:p-3.5 rounded-2xl border border-slate-200 bg-white/70 flex items-center gap-3 cursor-pointer transition';
                    badge.className = 'step-badge w-7 h-7 rounded-full border border-slate-300 text-slate-400 font-bold text-xs flex items-center justify-center shrink-0';
                    badge.textContent = i;
                    title.className = 'text-xs sm:text-sm font-bold text-slate-600 step-title';
                }
            }

            // Đồng bộ dữ liệu thẻ vé ở Bước 5
            if (step === 5) {
                syncFinalBoardingPassData();
            }

            window.scrollTo({ top: 0, behavior: 'smooth' });
        }

        // ================= XỬ LÝ CHỌN GHẾ Ở BƯỚC 3 =================
        function switchSeatSelectingPax(idx) {
            selectPax(idx);
        }

        function selectPax(index) {
            currentPaxIndex = index;
            document.querySelectorAll('.pax-selector-btn').forEach((tab, tabIndex) => {
                tab.className = tabIndex === index
                    ? 'pax-selector-btn rounded-xl border border-primary bg-primary px-4 py-2 text-xs font-bold text-white transition'
                    : 'pax-selector-btn rounded-xl border border-slate-200 bg-white px-4 py-2 text-xs font-bold text-slate-700 transition hover:border-primary';
            });
            document.getElementById('curPaxName').textContent = passengers[index].name;
            renderSeatMap();
        }

        function renderSeatMap() {
            document.querySelectorAll('.seat-btn').forEach(button => {
                const seatCode = button.dataset.seat;
                const assignedIndex = passengers.findIndex(passenger => passenger.seat === seatCode);
                const selectedForCurrent = assignedIndex === currentPaxIndex;
                const originalPassengerIndex = button.dataset.originalAssignedIndex === ''
                    ? -1
                    : Number(button.dataset.originalAssignedIndex);
                const heldForCompanion = originalPassengerIndex !== -1 && originalPassengerIndex !== currentPaxIndex;
                const assignedToCompanion = (assignedIndex !== -1 && !selectedForCurrent) ||
                    (assignedIndex === -1 && heldForCompanion);
                const displayedOwnerIndex = assignedIndex !== -1 ? assignedIndex : originalPassengerIndex;
                const seatClassMismatch = button.dataset.seatClass !== passengers[currentPaxIndex].seatClass;
                const occupiedByOtherBooking = button.dataset.seatOccupied === '1';

                button.disabled = occupiedByOtherBooking || assignedToCompanion || seatClassMismatch;
                button.className = 'seat-btn flex h-8 w-9 items-center justify-center rounded-lg border text-[10px] font-bold transition';
                if (selectedForCurrent) {
                    button.className += ' border-primary bg-primary text-white ring-2 ring-blue-200';
                    button.innerHTML = '<i class="fa-solid fa-check"></i>';
                    button.title = `Ghế ${seatCode} của ${passengers[currentPaxIndex].name}`;
                } else if (assignedToCompanion) {
                    button.className += ' border-amber-300 bg-amber-100 text-amber-800 cursor-not-allowed';
                    button.innerHTML = '<i class="fa-solid fa-user"></i>';
                    button.title = `Ghế ${seatCode} của ${passengers[displayedOwnerIndex].name}`;
                } else if (occupiedByOtherBooking || seatClassMismatch) {
                    button.className += ' border-slate-200 bg-slate-200 text-slate-400 cursor-not-allowed';
                    button.innerHTML = '<i class="fa-solid fa-xmark"></i>';
                    button.title = occupiedByOtherBooking ? `Ghế ${seatCode} đã được đặt` : 'Ghế không thuộc hạng vé';
                } else {
                    button.className += ' border-emerald-200 bg-emerald-50 text-emerald-700 hover:border-primary hover:bg-primary hover:text-white';
                    button.textContent = seatCode;
                    button.title = `Chọn ghế ${seatCode}`;
                }
            });
        }

        function selectSeat(seatCode) {
            const seat = document.querySelector(`.seat-btn[data-seat="${seatCode}"]`);
            if (!seat || seat.disabled) return;
            selectNewSeat(seatCode);
        }

        function selectNewSeat(seatCode) {
            const seat = document.querySelector(`.seat-btn[data-seat="${seatCode}"]`);
            if (!seat || seat.disabled) return;
            passengers[currentPaxIndex].seat = seatCode;
            document.querySelectorAll('.pax-selector-btn').forEach((tab, index) => {
                const passenger = passengers[index];
                const name = document.createElement('span');
                name.textContent = passenger.name;
                tab.replaceChildren(name);
                const seatLabel = document.createElement('span');
                seatLabel.className = `ml-1 rounded-lg px-2 py-0.5 text-[10px] ${passenger.seat ? 'bg-blue-100 text-blue-800' : 'bg-slate-100 text-slate-500'}`;
                seatLabel.textContent = passenger.seat ? `Ghế ${passenger.seat}` : 'Chưa chọn';
                tab.append(seatLabel);
            });
            renderSeatMap();
        }

        // ================= XỬ LÝ DỊCH VỤ MUA THÊM Ở BƯỚC 4 =================
        function switchAddonPax(idx) {
            if (!passengerAddons[idx]) return;
            currentAddonPax = idx;

            for (let i = 0; i < passengers.length; i++) {
                const tab = document.getElementById('addonPaxTab' + i);
                if (!tab) continue;
                if (i === idx) {
                    tab.className = 'addon-pax-tab px-4 py-2 rounded-full bg-primary text-white font-bold text-xs shadow-md shadow-blue-500/20 flex items-center gap-1.5 transition';
                } else {
                    tab.className = 'addon-pax-tab px-4 py-2 rounded-full bg-white border border-slate-200 text-slate-700 font-bold text-xs hover:border-primary flex items-center gap-1.5 transition';
                }
            }

            document.getElementById('currentAddonPassenger').textContent = passengers[idx].name;
            updateAddonUI();
        }

        function selectBaggage(type) {
            passengerAddons[currentAddonPax].baggage = type;
            updateAddonUI();
        }

        function selectMeal(type) {
            passengerAddons[currentAddonPax].meal = type;
            updateAddonUI();
        }

        function togglePriority(type) {
            const list = passengerAddons[currentAddonPax].priority;
            const idx = list.indexOf(type);
            if (idx > -1) {
                list.splice(idx, 1);
            } else {
                list.push(type);
            }
            updateAddonUI();
        }

        // Cập nhật trạng thái hiển thị giao diện các card dịch vụ
        function updateAddonUI() {
            const current = passengerAddons[currentAddonPax];
            if (!current) return;

            // 1. Cập nhật thẻ hành lý
            ['free', '10kg', '20kg', '30kg'].forEach(type => {
                const el = document.getElementById('baggageOpt_' + type);
                const input = document.getElementById('baggageInput_' + type);
                if (!el) return;
                if (input) input.checked = current.baggage === type;
                if (current.baggage === type) {
                    el.className = 'addon-card p-4 rounded-2xl border-2 border-primary bg-blue-50/40 cursor-pointer transition flex items-center justify-between shadow-sm';
                } else {
                    el.className = 'addon-card p-4 rounded-2xl border border-slate-200 bg-white hover:border-primary cursor-pointer transition flex items-center justify-between';
                }
            });

            // 2. Cập nhật thẻ suất ăn
            ['free', 'beef', 'veggie'].forEach(type => {
                const el = document.getElementById('mealOpt_' + type);
                const input = document.getElementById('mealInput_' + type);
                if (!el) return;
                if (input) input.checked = current.meal === type;
                if (current.meal === type) {
                    el.className = 'addon-card p-4 rounded-2xl border-2 border-primary bg-blue-50/40 cursor-pointer transition flex items-center justify-between shadow-sm';
                } else {
                    el.className = 'addon-card p-4 rounded-2xl border border-slate-200 bg-white hover:border-primary cursor-pointer transition flex items-center justify-between';
                }
            });

            // 3. Cập nhật dịch vụ ưu tiên
            ['fasttrack', 'insurance'].forEach(type => {
                const el = document.getElementById('priorityOpt_' + type);
                if (!el) return;
                if (current.priority.includes(type)) {
                    el.className = 'addon-card p-4 rounded-2xl border-2 border-primary bg-blue-50/40 cursor-pointer transition flex items-center justify-between shadow-sm';
                } else {
                    el.className = 'addon-card p-4 rounded-2xl border border-slate-200 bg-white hover:border-primary cursor-pointer transition flex items-center justify-between';
                }
            });

            // 4. Tính toán tổng tiền mua thêm của toàn đoàn
            const total = calculateTotalAddons();
            document.getElementById('addonTotalAmount').textContent = formatVND(total);

            // Cập nhật text nút hoàn tất
            const btnText = document.getElementById('btnConfirmStep4Text');
            if (!btnText) return;
            if (total > 0 && !isPaidAddons) {
                btnText.textContent = `Thanh toán mô phỏng & check-in (${formatVND(total)})`;
            } else {
                btnText.textContent = 'Xác nhận & Hoàn tất Check-in';
            }
        }

        // Tính tổng tiền toàn đoàn
        function calculateTotalAddons() {
            let total = 0;
            for (let p = 0; p < passengers.length; p++) {
                const data = passengerAddons[p];
                total += addonPrices.baggage[data.baggage] || 0;
                total += addonPrices.meal[data.meal] || 0;
                data.priority.forEach(pr => {
                    total += addonPrices.priority[pr] || 0;
                });
            }
            return total;
        }

        function handleConfirmStep4() {
            const total = calculateTotalAddons();
            if (total > 0) {
                openPaymentModal(total);
                return;
            }
            completeCheckIn();
        }

        async function completeCheckIn() {
            const button = document.getElementById('completeCheckInButton');
            const buttonText = document.getElementById('btnConfirmStep4Text');
            const feedback = document.getElementById('checkinFeedback');
            button.disabled = true;
            buttonText.textContent = 'Đang lưu check-in...';
            feedback.classList.add('hidden');
            try {
                const response = await fetch('/WebsiteDatVe/api/checkin/process.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                    body: JSON.stringify({
                        bookingCode: document.getElementById('pnrInput').value.trim().toUpperCase(),
                        lastName: document.getElementById('lastNameInput').value.trim().replace(/\s+/g, ' '),
                        seats: passengers.map(passenger => ({
                            passengerId: passenger.paxId,
                            seatCode: passenger.seat || ''
                        })),
                        addonsPaid: isPaidAddons,
                        addons: passengers.map((passenger, index) => ({
                            passengerId: passenger.paxId,
                            baggage: passengerAddons[index].baggage,
                            meal: passengerAddons[index].meal
                        })),
                        paymentMethod: document.querySelector('input[name="checkinPayMethod"]:checked')?.value || 'VietQR'
                    })
                });
                const result = await response.json();
                if (!response.ok || result.status !== 'ok') {
                    throw new Error(result.message || 'Không thể hoàn tất check-in.');
                }
                const params = new URLSearchParams({
                    pnr: result.bookingCode,
                    lastName: document.getElementById('lastNameInput').value.trim().replace(/\s+/g, ' '),
                    step: '5'
                });
                window.location.assign(`/WebsiteDatVe/check-in/check-in.php?${params.toString()}`);
            } catch (error) {
                feedback.textContent = error instanceof Error ? error.message : 'Đã xảy ra lỗi khi check-in.';
                feedback.className = 'rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-700';
                button.disabled = false;
                buttonText.textContent = 'Thử check-in lại';
            }
        }

        // ================= XỬ LÝ MODAL THÔNG BÁO THANH TOÁN =================
        function openPaymentModal(total) {
            document.getElementById('modalTotalAmountText').textContent = formatVND(total);
            document.getElementById('modalFinalTotalText').textContent = formatVND(total);

            const pnrCode = '<?= htmlspecialchars($dbBooking["booking_code"] ?? "") ?>';
            const qrData = `CHECKIN-${pnrCode || 'BOOKING'}-${total}`;
            document.getElementById('modalQrCodeImg').src = `https://api.qrserver.com/v1/create-qr-code/?size=130x130&data=${encodeURIComponent(qrData)}`;

            const breakdownContainer = document.getElementById('modalAddonBreakdown');
            breakdownContainer.replaceChildren();

            for (let i = 0; i < passengers.length; i++) {
                const pax = passengers[i];
                const data = passengerAddons[i];
                const items = [];
                if (data.baggage !== 'free') {
                    items.push(addonLabels.baggage[data.baggage]);
                }
                if (data.meal !== 'free') {
                    items.push(addonLabels.meal[data.meal]);
                }
                data.priority.forEach(pr => {
                    items.push(addonLabels.priority[pr]);
                });

                const row = document.createElement('div');
                row.className = 'flex items-start justify-between gap-3 border-b border-slate-200 pb-3 last:border-0 last:pb-0';
                const detail = document.createElement('div');
                const passengerName = document.createElement('p');
                passengerName.className = 'font-bold text-slate-800';
                passengerName.textContent = pax.name;
                const description = document.createElement('p');
                description.className = 'mt-1 text-xs text-slate-500';
                description.textContent = items.length ? items.join(' · ') : 'Dịch vụ tiêu chuẩn (miễn phí)';
                detail.append(passengerName, description);
                const amount = document.createElement('span');
                amount.className = 'shrink-0 text-xs font-extrabold text-slate-700';
                amount.textContent = formatVND(
                    (addonPrices.baggage[data.baggage] || 0) + (addonPrices.meal[data.meal] || 0)
                );
                row.append(detail, amount);
                breakdownContainer.appendChild(row);
            }

            document.getElementById('paymentRequiredModal').classList.remove('hidden');
        }

        function closePaymentModal() {
            document.getElementById('paymentRequiredModal').classList.add('hidden');
        }

            function updatePaymentMethodCards() {
                document.querySelectorAll('input[name="checkinPayMethod"]').forEach(input => {
                    const label = input.closest('label');
                    if (!label) return;
                    label.className = input.checked
                        ? 'cursor-pointer rounded-xl border-2 border-primary bg-blue-50 p-3 text-primary'
                        : 'cursor-pointer rounded-xl border border-slate-200 p-3 text-slate-600';
                });
            }

            function executePaymentProcess() {
            const btn = document.getElementById('btnProcessPayment');
            const btnText = document.getElementById('btnProcessPaymentText');
            btn.disabled = true;
            btnText.innerHTML = '<i class="fa-solid fa-spinner fa-spin mr-1"></i> Đang lưu thanh toán...';
            isPaidAddons = true;
            closePaymentModal();
            completeCheckIn().finally(() => {
                btn.disabled = false;
                btnText.innerHTML = '<i class="fa-solid fa-lock mr-1"></i> Xác nhận thanh toán demo';
            });
        }

        // ================= XỬ LÝ THẺ VÉ BƯỚC 5 =================
        function syncFinalBoardingPassData() {
            // Đồng bộ ghế & CCCD cho từng thẻ từ passengers array
            const pnr = '<?= htmlspecialchars($dbBooking["booking_code"] ?? "") ?>';
            passengers.forEach(function(p, i) {
                const seatEl = document.getElementById('finalBpSeat' + i);
                const docEl  = document.getElementById('finalBpDoc' + i);
                const barEl  = document.getElementById('barcodeLabel' + i);
                const idEl   = document.getElementById('idDoc' + i);
                const seat   = p.seat || '—';
                const doc    = idEl ? idEl.value.trim() : (p.doc || '—');
                if (seatEl) seatEl.textContent = seat;
                if (docEl)  docEl.textContent  = doc;
                if (barEl)  barEl.textContent  = `*${pnr}-p${i+1}-${seat}*`;
            });
            // Hiển thị badge đã thanh toán nếu có
            const total = calculateTotalAddons();
            const badge = document.getElementById('step5AddonPaidBadge');
            if (isPaidAddons && total > 0) {
                badge.classList.remove('hidden');
                document.getElementById('step5AddonPaidText').textContent = `Đã thanh toán dịch vụ mua thêm: ${formatVND(total)}`;
            } else {
                badge.classList.add('hidden');
            }
        }

        function switchBoardingPassView(idx) {
            activeBoardingPassView = idx;

            const tab0 = document.getElementById('bpViewTab0');
            const tab1 = document.getElementById('bpViewTab1');
            const card0 = document.getElementById('boardingPassCard0');
            const card1 = document.getElementById('boardingPassCard1');

            if (idx === 0) {
                tab0.className = 'px-4 py-2 rounded-xl bg-primary text-white font-bold text-xs shadow-sm flex items-center gap-1.5 transition';
                tab1.className = 'px-4 py-2 rounded-xl bg-white border border-slate-200 text-slate-600 hover:border-primary font-bold text-xs shadow-sm flex items-center gap-1.5 transition';
                card0.classList.remove('hidden');
                card1.classList.add('hidden', 'sm:block');
            } else {
                tab1.className = 'px-4 py-2 rounded-xl bg-primary text-white font-bold text-xs shadow-sm flex items-center gap-1.5 transition';
                tab0.className = 'px-4 py-2 rounded-xl bg-white border border-slate-200 text-slate-600 hover:border-primary font-bold text-xs shadow-sm flex items-center gap-1.5 transition';
                card1.classList.remove('hidden');
                card0.classList.add('hidden');
            }
        }

        function printAllBoardingPasses() {
            // Khi in, hiện cả 2 thẻ
            document.getElementById('boardingPassCard0').classList.remove('hidden');
            document.getElementById('boardingPassCard1').classList.remove('hidden');
            window.print();
        }

        function downloadCurrentPassPDF() {
            alert('Đang tạo và tải tệp PDF Thẻ lên máy bay điện tử (E-Boarding Pass). Vui lòng kiểm tra thư mục Tải về (Downloads)!');
            window.print();
        }

        function saveQRCode() {
            const p = passengers[activeBoardingPassView] || {};
            const pnr = '<?= htmlspecialchars($dbBooking["booking_code"] ?? "") ?>';
            const fn  = '<?= htmlspecialchars($dbBooking["flight_number"] ?? "") ?>';
            const qrUrl = `https://api.qrserver.com/v1/create-qr-code/?size=400x400&data=${encodeURIComponent(fn + '-' + (p.name||'') + '-' + (p.seat||'') + '-' + pnr)}`;
            window.open(qrUrl, '_blank');
        }
    </script>
</body>
</html>
