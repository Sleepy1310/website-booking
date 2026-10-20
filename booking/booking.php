<?php
session_start();
require_once __DIR__ . '/../api/config/database.php';

// Nhận tham số flightId từ URL (bắt buộc phải có — không fallback sang chuyến mặc định)
$flightIdParam = isset($_GET['flightId']) && is_numeric($_GET['flightId']) ? (int)$_GET['flightId'] : 0;
$flightDb = null;

if ($conn && $flightIdParam > 0) {
    $stmt = mysqli_prepare($conn, "SELECT f.*, dep.city as from_city, dep.name as from_airport_name, arr.city as to_city, arr.name as to_airport_name FROM flights f JOIN airports dep ON f.from_airport = dep.code JOIN airports arr ON f.to_airport = arr.code WHERE f.id = ?");
    mysqli_stmt_bind_param($stmt, "i", $flightIdParam);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $flightDb = mysqli_fetch_assoc($res);
    mysqli_stmt_close($stmt);
}

// Nếu không tìm thấy chuyến bay → chuyển về trang tìm kiếm
if (!$flightDb) {
    header('Location: /WebsiteDatVe/flights/flights.php');
    exit;
}

$flightId    = (int)$flightDb['id'];
$airline     = $flightDb['airline'];
$flightNumber= $flightDb['flight_number'];
$fromCode    = $flightDb['from_airport'];
$toCode      = $flightDb['to_airport'];
$fromCity    = $flightDb['from_city'] . ' (' . $fromCode . ')';
$toCity      = $flightDb['to_city'] . ' (' . $toCode . ')';

$depTs       = strtotime($flightDb['depart_at']);
$arrTs       = strtotime($flightDb['arrive_at']);
$departTime  = date('H:i', $depTs);
$arriveTime  = date('H:i', $arrTs);

$durMin  = max(30, round(($arrTs - $depTs) / 60));
$duration = floor($durMin / 60) . 'h ' . str_pad($durMin % 60, 2, '0', STR_PAD_LEFT) . 'm';
$stopText = ((int)$flightDb['stops'] > 0) ? ($flightDb['stops'] . ' điểm dừng') : 'Bay thẳng';
$cabin = (string)($_GET['cabin'] ?? 'Hạng Phổ thông');
$cabinClass = stripos($cabin, 'thương gia') !== false ? 'BUSINESS' : 'ECONOMY';

$basePrice = (int)($cabinClass === 'BUSINESS' ? $flightDb['price_business'] : $flightDb['price_economy']);
$taxPrice  = (int)$flightDb['tax_price'];
$adults    = max(1, (int)($_GET['adults'] ?? 1));
$children  = max(0, (int)($_GET['children'] ?? 0));
$infants   = max(0, (int)($_GET['infants'] ?? 0));
$requestedBookingCode = strtoupper(trim((string)($_GET['bookingCode'] ?? '')));
$bookingDraft = preg_match('/^BK[A-F0-9]{6}$/', $requestedBookingCode)
    ? ($_SESSION['booking_drafts'][$requestedBookingCode] ?? null)
    : null;
if ($bookingDraft) {
    $adults = max(1, (int)($bookingDraft['adults'] ?? $adults));
    $children = max(0, (int)($bookingDraft['children'] ?? $children));
    $infants = max(0, (int)($bookingDraft['infants'] ?? $infants));
}
$totalPassengers = max(1, $adults + $children + $infants);
$searchDate = (string)($_GET['departDate'] ?? '');
$parsedSearchDate = DateTime::createFromFormat('!Y-m-d', $searchDate);
if (!$parsedSearchDate || $parsedSearchDate->format('Y-m-d') !== $searchDate) {
    $searchDate = date('Y-m-d', $depTs);
}
$returnUrl = '/WebsiteDatVe/flights/flights.php?' . http_build_query([
    'from' => trim((string)($_GET['from'] ?? '')) ?: $fromCode,
    'to' => trim((string)($_GET['to'] ?? '')) ?: $toCode,
    'departDate' => $searchDate,
    'adults' => $adults,
    'children' => $children,
    'infants' => $infants,
]);
$bookingCode = $bookingDraft ? $requestedBookingCode : 'BK' . strtoupper(bin2hex(random_bytes(3)));
$draftPassengers = $bookingDraft['passengers'] ?? [];

// Lấy thông tin liên hệ từ session (nếu đã đăng nhập), để trống nếu chưa
$userFullName = $_SESSION['user']['fullname'] ?? '';
$userEmail    = $_SESSION['user']['email']    ?? '';
$userPhone    = $_SESSION['user']['phone']    ?? '';

$logoBg = 'bg-rose-600';
$airlineShort = 'VJ';
if (stripos($airline, 'Vietnam') !== false) {
    $logoBg = 'bg-sky-700';
    $airlineShort = 'VN';
} elseif (stripos($airline, 'Bamboo') !== false) {
    $logoBg = 'bg-emerald-600';
    $airlineShort = 'QH';
} elseif (stripos($airline, 'Vietravel') !== false) {
    $logoBg = 'bg-amber-600';
    $airlineShort = 'VU';
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Thông tin hành khách - Booking Online</title>
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
    </style>
</head>
<body class="bg-slate-50 text-slate-800 flex flex-col min-h-screen">

    <!-- THANH ĐIỀU HƯỚNG BƯỚC ĐẶT VÉ (STEP PROGRESS & COUNTDOWN TIMER) -->
    <header class="sticky top-0 z-40 bg-white border-b border-slate-200 shadow-sm">
        <div class="relative max-w-7xl mx-auto px-4 sm:px-6 h-16 flex items-center justify-between">
            <!-- Nút quay lại -->
            <a href="<?= htmlspecialchars($returnUrl, ENT_QUOTES, 'UTF-8') ?>" class="text-sm font-semibold text-slate-700 hover:text-primary transition flex items-center gap-2 group">
                <i class="fa-solid fa-arrow-left text-xs transition group-hover:-translate-x-0.5"></i>
                <span>Quay lại chọn chuyến</span>
            </a>

            <!-- Tiến trình các bước -->
            <div class="absolute left-1/2 hidden -translate-x-1/2 items-center gap-2 whitespace-nowrap text-xs font-medium xl:gap-4 xl:text-sm xl:flex">
                <!-- Bước 1: Chọn chuyến (Đã xong) -->
                <a href="<?= htmlspecialchars($returnUrl, ENT_QUOTES, 'UTF-8') ?>" class="flex items-center gap-1.5 text-emerald-600 hover:text-emerald-700 transition">
                    <span class="w-5 h-5 rounded-full border border-emerald-600 flex items-center justify-center text-[10px]">
                        <i class="fa-solid fa-check"></i>
                    </span>
                    <span>1. Chọn chuyến</span>
                </a>

                <div class="w-6 h-[1px] bg-slate-200"></div>

                <!-- Bước 2: Thông tin hành khách (Đang thực hiện) -->
                <div class="flex items-center gap-2 text-primary font-bold">
                    <span class="w-5 h-5 rounded-full bg-primary text-white flex items-center justify-center text-xs font-bold shadow-sm shadow-blue-500/30">
                        2
                    </span>
                    <span>2. Thông tin hành khách</span>
                </div>

                <div class="w-6 h-[1px] bg-slate-200"></div>

                <!-- Bước 3: Dịch vụ -->
                <div class="flex items-center gap-1.5 text-slate-400">
                    <span class="w-5 h-5 rounded-full border border-slate-300 flex items-center justify-center text-xs">
                        3
                    </span>
                    <span>3. Dịch vụ</span>
                </div>

                <div class="w-6 h-[1px] bg-slate-200"></div>

                <!-- Bước 4: Thanh toán -->
                <div class="flex items-center gap-1.5 text-slate-400">
                    <span class="w-5 h-5 rounded-full border border-slate-300 flex items-center justify-center text-xs">
                        4
                    </span>
                    <span>4. Thanh toán</span>
                </div>

                <div class="w-6 h-[1px] bg-slate-200"></div>

                <!-- Bước 5: Hoàn tất vé -->
                <div class="flex items-center gap-1.5 text-slate-400">
                    <span class="w-5 h-5 rounded-full border border-slate-300 flex items-center justify-center text-xs">
                        5
                    </span>
                    <span>5. Hoàn tất</span>
                </div>
            </div>

        </div>
    </header>

    <!-- NỘI DUNG CHÍNH -->
    <main class="max-w-7xl mx-auto px-4 sm:px-6 py-8 flex-1 w-full">
        <!-- TIÊU ĐỀ TRANG -->
        <div class="mb-7">
            <h1 class="text-2xl sm:text-3xl font-black text-slate-900 tracking-tight">
                Thông tin hành khách (<?= $totalPassengers ?> khách)
            </h1>
            <p class="text-xs sm:text-sm text-slate-500 mt-1">
                Vui lòng nhập chính xác thông tin theo giấy tờ tùy thân (CCCD / Hộ chiếu) cho từng hành khách.
            </p>
        </div>

        <!-- FORM GỬI THÔNG TIN SANG BƯỚC DỊCH VỤ -->
        <form method="POST" action="/WebsiteDatVe/booking/services.php" id="bookingForm" class="grid grid-cols-1 lg:grid-cols-12 gap-8 items-start">
            <!-- Các input ẩn chuyển dữ liệu -->
            <input type="hidden" name="flightId" value="<?= htmlspecialchars($flightId) ?>">
            <input type="hidden" name="bookingCode" value="<?= htmlspecialchars($bookingCode) ?>">
            <input type="hidden" name="airline" value="<?= htmlspecialchars($airline) ?>">
            <input type="hidden" name="flightCode" value="<?= htmlspecialchars($flightNumber) ?>">
            <input type="hidden" name="fromCity" value="<?= htmlspecialchars($fromCity) ?>">
            <input type="hidden" name="toCity" value="<?= htmlspecialchars($toCity) ?>">
            <input type="hidden" name="departTime" value="<?= htmlspecialchars($departTime) ?>">
            <input type="hidden" name="arriveTime" value="<?= htmlspecialchars($arriveTime) ?>">
            <input type="hidden" name="duration" value="<?= htmlspecialchars($duration) ?>">
            <input type="hidden" name="cabin" value="<?= htmlspecialchars($cabin) ?>">
            <input type="hidden" name="from" value="<?= htmlspecialchars(trim((string)($_GET['from'] ?? $bookingDraft['from'] ?? $fromCode)), ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="to" value="<?= htmlspecialchars(trim((string)($_GET['to'] ?? $bookingDraft['to'] ?? $toCode)), ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="departDate" value="<?= htmlspecialchars($searchDate, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="adults" value="<?= $adults ?>">
            <input type="hidden" name="children" value="<?= $children ?>">
            <input type="hidden" name="infants" value="<?= $infants ?>">
            <!-- ================= CỘT TRÁI: FORM NHẬP THÔNG TIN (7 - 8 COLS) ================= -->
            <div class="lg:col-span-8 space-y-6">

                <!-- 1. THÔNG TIN NGƯỜI LIÊN HỆ -->
                <div class="bg-white rounded-3xl border border-slate-200 p-6 sm:p-7 shadow-sm space-y-5">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-3">
                            <span class="w-7 h-7 rounded-full bg-blue-100 text-primary font-bold text-sm flex items-center justify-center">
                                1
                            </span>
                            <h2 class="text-base sm:text-lg font-bold text-slate-900">
                                Thông tin người liên hệ (Nhận vé)
                            </h2>
                        </div>
                        <span class="px-3 py-1 rounded-full bg-emerald-50 text-emerald-600 text-xs font-semibold">
                            Vé điện tử gửi qua Email
                        </span>
                    </div>

                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1.5">
                            Họ và tên người đặt vé <span class="text-rose-500">*</span>
                        </label>
                        <input type="text" name="passengerName" id="contactName" required
                            value="<?= htmlspecialchars($bookingDraft['contactName'] ?? $userFullName, ENT_QUOTES, 'UTF-8') ?>"
                            placeholder="Nguyễn Văn An"
                            oninput="syncPassengerName()"
                            class="w-full px-4 py-3 rounded-2xl border border-slate-200 bg-white text-sm font-medium text-slate-900 focus:outline-none focus:border-primary focus:ring-4 focus:ring-primary/10 transition">
                        <p class="text-xs text-slate-400 mt-1">Như trên CMND / CCCD (không cần dấu hoặc có dấu)</p>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-bold text-slate-700 mb-1.5">
                                Số điện thoại di động <span class="text-rose-500">*</span>
                            </label>
                            <div class="flex rounded-2xl border border-slate-200 bg-white focus-within:border-primary focus-within:ring-4 focus-within:ring-primary/10 transition overflow-hidden">
                                <span class="inline-flex items-center gap-1.5 px-3.5 bg-slate-50 border-r border-slate-200 text-xs font-bold text-slate-700 select-none">
                                    <span>VN</span>
                                    <span class="text-slate-400 font-medium">+84</span>
                                </span>
                                <input type="tel" name="passengerPhone" id="contactPhone" required
                                    value="<?= htmlspecialchars($bookingDraft['contactPhone'] ?? $userPhone, ENT_QUOTES, 'UTF-8') ?>"
                                    placeholder="0912345678"
                                    class="w-full px-4 py-3 bg-transparent text-sm font-medium text-slate-900 focus:outline-none">
                            </div>
                            <p class="text-xs text-slate-400 mt-1">Nhận SMS thông báo giờ bay</p>
                        </div>

                        <div>
                            <label class="block text-xs font-bold text-slate-700 mb-1.5">
                                Địa chỉ Email nhận vé <span class="text-rose-500">*</span>
                            </label>
                            <input type="email" name="passengerEmail" id="contactEmail" required
                                value="<?= htmlspecialchars($bookingDraft['contactEmail'] ?? $userEmail, ENT_QUOTES, 'UTF-8') ?>"
                                placeholder="an.nguyen@example.com"
                                class="w-full px-4 py-3 rounded-2xl border border-slate-200 bg-white text-sm font-medium text-slate-900 focus:outline-none focus:border-primary focus:ring-4 focus:ring-primary/10 transition">
                            <p class="text-xs text-slate-400 mt-1">Vé E-ticket và hóa đơn gửi qua email này</p>
                        </div>
                    </div>
                </div>

                <!-- 2. THÔNG TIN HÀNH KHÁCH (HIỂN THỊ TƯƠNG ỨNG THEO SỐ NGƯỜI ĐẶT VÉ) -->
                <div class="space-y-4">
                    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 bg-white rounded-3xl border border-slate-200 p-5 sm:p-6 shadow-sm">
                        <div class="flex items-center gap-3">
                            <span class="w-7 h-7 rounded-full bg-blue-100 text-primary font-bold text-sm flex items-center justify-center">
                                2
                            </span>
                            <div>
                                <h2 class="text-base sm:text-lg font-bold text-slate-900">
                                    Thông tin hành khách bay
                                </h2>
                                <p class="text-xs text-slate-500 mt-0.5">Mỗi hành khách tương ứng 1 mục điền thông tin</p>
                            </div>
                        </div>

                    </div>

                    <!-- DANH SÁCH CÁC MỤC NHẬP THÔNG TIN TỪNG HÀNH KHÁCH (RENDER TƯƠNG ỨNG SỐ NGƯỜI) -->
                    <div id="passengersListContainer" class="space-y-4">
                        <?php for ($i = 0; $i < $totalPassengers; $i++): 
                            // Form để trống — người dùng tự nhập thông tin hành khách
                            $pax = $draftPassengers[$i] ?? ['title' => 'Mr', 'firstName' => '', 'lastName' => '', 'dob' => '', 'idNumber' => '', 'nationality' => 'Vietnam'];
                        ?>
                        <div class="passenger-card bg-white rounded-3xl border border-slate-200 p-6 sm:p-7 shadow-sm space-y-4 transition-all duration-300" data-index="<?= $i ?>">
                            <div class="flex items-center justify-between">
                                <div class="flex items-center gap-2.5">
                                    <span class="w-7 h-7 rounded-full bg-slate-100 text-slate-500 flex items-center justify-center text-sm">
                                        <i class="fa-regular fa-user"></i>
                                    </span>
                                    <h3 class="text-sm sm:text-base font-bold text-slate-900 passenger-heading">
                                        <?= $i === 0 ? 'Passenger 1 (Adult) - Primary Contact' : 'Passenger ' . ($i + 1) . ' (Adult)' ?>
                                    </h3>
                                </div>
                            </div>

                            <!-- HÀNG 1: TITLE, FIRST NAME, LAST NAME -->
                            <div class="grid grid-cols-1 sm:grid-cols-12 gap-3 pt-1">
                                <!-- TITLE -->
                                <div class="sm:col-span-3">
                                    <label class="block text-[11px] font-extrabold text-slate-500 tracking-wider uppercase mb-1.5">
                                        TITLE
                                    </label>
                                    <div class="relative">
                                        <select name="passengers[<?= $i ?>][title]" class="w-full appearance-none px-4 py-3 rounded-2xl border border-slate-200 bg-white text-sm font-bold text-slate-900 focus:outline-none focus:border-primary focus:ring-4 focus:ring-primary/10 transition cursor-pointer pr-9">
                                            <option value="Mr" <?= ($pax['title'] ?? 'Mr') === 'Mr' ? 'selected' : '' ?>>Mr</option>
                                            <option value="Mrs" <?= ($pax['title'] ?? '') === 'Mrs' ? 'selected' : '' ?>>Mrs</option>
                                            <option value="Ms" <?= ($pax['title'] ?? '') === 'Ms' ? 'selected' : '' ?>>Ms</option>
                                        </select>
                                        <i class="fa-solid fa-chevron-down absolute right-4 top-1/2 -translate-y-1/2 text-xs text-slate-400 pointer-events-none"></i>
                                    </div>
                                </div>

                                <!-- FIRST NAME -->
                                <div class="sm:col-span-4">
                                    <label class="block text-[11px] font-extrabold text-slate-500 tracking-wider uppercase mb-1.5">
                                        FIRST NAME
                                    </label>
                                    <input type="text" name="passengers[<?= $i ?>][firstName]" value="<?= htmlspecialchars($pax['firstName'] ?? '') ?>" placeholder="FIRST NAME"
                                        oninput="onPassengerNameChange(<?= $i ?>)" required
                                        class="passenger-firstname w-full px-4 py-3 rounded-2xl border border-slate-200 bg-white text-sm font-bold text-slate-900 uppercase focus:outline-none focus:border-primary focus:ring-4 focus:ring-primary/10 transition" <?= $i === 0 ? 'id="passengerFirstName"' : '' ?>>
                                </div>

                                <!-- LAST NAME -->
                                <div class="sm:col-span-5">
                                    <label class="block text-[11px] font-extrabold text-slate-500 tracking-wider uppercase mb-1.5">
                                        LAST NAME
                                    </label>
                                    <input type="text" name="passengers[<?= $i ?>][lastName]" value="<?= htmlspecialchars($pax['lastName'] ?? '') ?>" placeholder="LAST NAME"
                                        oninput="onPassengerNameChange(<?= $i ?>)" required
                                        class="passenger-lastname w-full px-4 py-3 rounded-2xl border border-slate-200 bg-white text-sm font-bold text-slate-900 uppercase focus:outline-none focus:border-primary focus:ring-4 focus:ring-primary/10 transition" <?= $i === 0 ? 'id="passengerLastName"' : '' ?>>
                                </div>
                            </div>

                            <!-- HÀNG 2: DATE OF BIRTH, PASSPORT / NATIONAL ID, NATIONALITY -->
                            <div class="grid grid-cols-1 sm:grid-cols-12 gap-3 pt-1">
                                <!-- DATE OF BIRTH -->
                                <div class="sm:col-span-4">
                                    <label class="block text-[11px] font-extrabold text-slate-500 tracking-wider uppercase mb-1.5">
                                        DATE OF BIRTH
                                    </label>
                                    <div class="relative flex items-center">
                                        <input type="text" name="passengers[<?= $i ?>][dob]" value="<?= htmlspecialchars($pax['dob'] ?? '') ?>" placeholder="DD/MM/YYYY"
                                            class="w-full px-4 py-3 rounded-2xl border border-slate-200 bg-white text-sm font-bold text-slate-900 focus:outline-none focus:border-primary focus:ring-4 focus:ring-primary/10 transition pr-10">
                                        <i class="fa-regular fa-calendar absolute right-4 top-1/2 -translate-y-1/2 text-sm text-slate-400 pointer-events-none"></i>
                                    </div>
                                </div>

                                <!-- PASSPORT / NATIONAL ID -->
                                <div class="sm:col-span-4">
                                    <label class="block text-[11px] font-extrabold text-slate-500 tracking-wider uppercase mb-1.5">
                                        PASSPORT / NATIONAL ID
                                    </label>
                                    <input type="text" name="passengers[<?= $i ?>][idNumber]" value="<?= htmlspecialchars($pax['idNumber'] ?? '') ?>" placeholder="Số CCCD / Hộ chiếu"
                                        class="w-full px-4 py-3 rounded-2xl border border-slate-200 bg-white text-sm font-bold text-slate-900 focus:outline-none focus:border-primary focus:ring-4 focus:ring-primary/10 transition">
                                </div>

                                <!-- NATIONALITY -->
                                <div class="sm:col-span-4">
                                    <label class="block text-[11px] font-extrabold text-slate-500 tracking-wider uppercase mb-1.5">
                                        NATIONALITY
                                    </label>
                                    <div class="relative">
                                        <select name="passengers[<?= $i ?>][nationality]" class="w-full appearance-none px-4 py-3 rounded-2xl border border-slate-200 bg-white text-sm font-bold text-slate-900 focus:outline-none focus:border-primary focus:ring-4 focus:ring-primary/10 transition cursor-pointer pr-9">
                                            <option value="United States" <?= ($pax['nationality'] ?? 'United States') === 'United States' ? 'selected' : '' ?>>United States</option>
                                            <option value="Vietnam" <?= ($pax['nationality'] ?? '') === 'Vietnam' ? 'selected' : '' ?>>Vietnam</option>
                                            <option value="Japan" <?= ($pax['nationality'] ?? '') === 'Japan' ? 'selected' : '' ?>>Japan</option>
                                            <option value="South Korea" <?= ($pax['nationality'] ?? '') === 'South Korea' ? 'selected' : '' ?>>South Korea</option>
                                            <option value="United Kingdom" <?= ($pax['nationality'] ?? '') === 'United Kingdom' ? 'selected' : '' ?>>United Kingdom</option>
                                            <option value="France" <?= ($pax['nationality'] ?? '') === 'France' ? 'selected' : '' ?>>France</option>
                                            <option value="Germany" <?= ($pax['nationality'] ?? '') === 'Germany' ? 'selected' : '' ?>>Germany</option>
                                            <option value="Australia" <?= ($pax['nationality'] ?? '') === 'Australia' ? 'selected' : '' ?>>Australia</option>
                                            <option value="Singapore" <?= ($pax['nationality'] ?? '') === 'Singapore' ? 'selected' : '' ?>>Singapore</option>
                                            <option value="Canada" <?= ($pax['nationality'] ?? '') === 'Canada' ? 'selected' : '' ?>>Canada</option>
                                        </select>
                                        <i class="fa-solid fa-chevron-down absolute right-4 top-1/2 -translate-y-1/2 text-xs text-slate-400 pointer-events-none"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endfor; ?>
                    </div>
                </div>


                <div class="p-4 sm:p-5 rounded-2xl bg-white border border-slate-200 shadow-sm">
                    <label class="flex items-start gap-3 cursor-pointer select-none">
                        <input type="checkbox" id="termsCheck" required checked class="mt-1 w-4 h-4 rounded text-primary accent-primary cursor-pointer">
                        <span class="text-xs text-slate-600 leading-relaxed">
                            Tôi xác nhận rằng thông tin hành khách và người liên hệ đã nhập là chính xác theo CCCD/Hộ chiếu, đồng thời đồng ý với 
                            <a href="#" class="text-primary font-bold hover:underline">Điều khoản vận chuyển</a> và 
                            <a href="#" class="text-primary font-bold hover:underline">Chính sách bảo mật</a> của hãng hàng không.
                        </span>
                    </label>
                </div>

            </div>

            <!-- ================= CỘT PHẢI: TÓM TẮT CHUYẾN BAY (STICKY SIDEBAR) ================= -->
            <div class="lg:col-span-4 sticky top-20 space-y-4">
                <div class="bg-white rounded-3xl border border-slate-200 p-6 shadow-sm space-y-5">
                    <!-- TIÊU ĐỀ TÓM TẮT -->
                    <div class="flex items-center justify-between border-b border-slate-100 pb-3">
                        <span class="text-xs font-black tracking-wider text-slate-600 uppercase">TÓM TẮT CHUYẾN BAY</span>
                        <span class="px-3 py-1 rounded-full bg-blue-50 text-primary text-xs font-bold"><?= htmlspecialchars($cabin) ?></span>
                    </div>

                    <!-- THÔNG TIN HÃNG BAY -->
                    <div class="flex items-center gap-3">
                        <span class="w-10 h-10 rounded-full <?= $logoBg ?> text-white font-extrabold flex items-center justify-center text-sm shadow-sm">
                            <?= $airlineShort ?>
                        </span>
                        <div>
                            <h3 class="text-base font-extrabold text-slate-900 leading-tight"><?= htmlspecialchars($airline) ?></h3>
                            <p class="text-xs text-slate-400 font-medium"><?= htmlspecialchars($flightNumber) ?> · <?= htmlspecialchars($duration) ?> · <?= htmlspecialchars($stopText) ?></p>
                        </div>
                    </div>

                    <!-- LỊCH TRÌNH BAY & SÂN BAY -->
                    <div class="flex items-center justify-between py-2 border-y border-slate-100">
                        <div>
                            <p class="text-2xl font-black text-slate-900"><?= htmlspecialchars($departTime) ?></p>
                            <p class="text-xs font-bold text-slate-500 mt-0.5"><?= htmlspecialchars($fromCity) ?></p>
                        </div>

                        <div class="flex flex-col items-center px-3">
                            <i class="fa-solid fa-plane text-primary text-sm"></i>
                        </div>

                        <div class="text-right">
                            <p class="text-2xl font-black text-slate-900"><?= htmlspecialchars($arriveTime) ?></p>
                            <p class="text-xs font-bold text-slate-500 mt-0.5"><?= htmlspecialchars($toCity) ?></p>
                        </div>
                    </div>

                    <div class="space-y-2.5 text-xs text-slate-600 border-t border-slate-100 pt-4">
                        <div class="flex justify-between items-center">
                            <span>Giá vé cơ bản (<span id="sidebarPaxCount"><?= $totalPassengers ?></span> hành khách)</span>
                            <span class="font-bold text-slate-900" id="basePriceSummary"><?= number_format($basePrice * $totalPassengers) ?>đ</span>
                        </div>

                        <div class="flex justify-between items-center">
                            <span>Thuế, phí sân bay & an ninh</span>
                            <span class="font-bold text-slate-900" id="taxPriceSummary"><?= number_format($taxPrice * $totalPassengers) ?>đ</span>
                        </div>

                    </div>

                    <!-- TỔNG TIỀN VÉ TRƯỚC DỊCH VỤ -->
                    <div class="border-t border-slate-100 pt-4 flex items-baseline justify-between">
                        <div>
                            <p class="text-xs font-semibold text-slate-500">Tạm tính vé:</p>
                            <p class="text-[11px] text-slate-400">Đã gồm thuế phí, chưa gồm dịch vụ</p>
                        </div>
                        <p class="text-3xl font-black text-primary" id="grandTotalDisplay">
                            <?= number_format(($basePrice + $taxPrice) * $totalPassengers) ?>đ
                        </p>
                    </div>

                    <!-- NÚT TIẾP TỤC THANH TOÁN -->
                    <button type="submit" id="submitBtn" class="w-full py-3.5 rounded-2xl bg-primary hover:bg-primary-hover text-white font-bold text-sm sm:text-base shadow-lg shadow-blue-500/25 transition hover:-translate-y-0.5 flex items-center justify-center gap-2">
                        <span>Tiếp tục chọn dịch vụ</span>
                        <i class="fa-solid fa-arrow-right text-sm"></i>
                    </button>

                </div>
            </div>
        </form>
    </main>


    <footer class="mt-auto bg-white border-t border-slate-200">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 py-12">
            <div class="grid grid-cols-1 md:grid-cols-12 gap-8 mb-8">
                <!-- Cột thông tin & Đăng ký nhận ưu đãi (5 cols) -->
                <div class="md:col-span-5 space-y-4">
                    <div class="flex items-center gap-2.5">
                        <span class="w-9 h-9 rounded-full bg-primary text-white flex items-center justify-center text-sm shadow-md shadow-blue-500/20">
                            <i class="fa-solid fa-plane"></i>
                        </span>
                        <span class="text-xl font-extrabold tracking-tight text-slate-900">Booking Online</span>
                    </div>
                    <p class="text-xs text-slate-500 max-w-sm leading-relaxed">
                        Đặt vé máy bay nội địa và quốc tế nhanh chóng, an toàn với giá rẻ nhất. Thanh toán linh hoạt, hỗ trợ 24/7.
                    </p>

                    <!-- Form nhận ưu đãi -->
                    <div class="flex items-center gap-2 pt-2 max-w-md">
                        <input type="email" placeholder="Nhập email của bạn" class="flex-1 px-4 py-2.5 rounded-xl border border-slate-200 bg-white text-xs focus:outline-none focus:border-primary">
                        <button type="button" onclick="alert('Cảm ơn bạn đã đăng ký nhận bản tin khuyến mãi!')" class="px-5 py-2.5 rounded-xl bg-primary hover:bg-primary-hover text-white text-xs font-bold shrink-0 transition shadow-sm">
                            Đăng ký nhận ưu đãi
                        </button>
                    </div>
                </div>

                <!-- Cột Về chúng tôi (2 cols) -->
                <div class="md:col-span-2">
                    <h4 class="text-xs font-extrabold text-slate-900 uppercase tracking-wider mb-3.5">Về chúng tôi</h4>
                    <ul class="space-y-2.5 text-xs text-slate-500 font-medium">
                        <li><a href="#" class="hover:text-primary transition">Giới thiệu</a></li>
                        <li><a href="#" class="hover:text-primary transition">Tuyển dụng</a></li>
                        <li><a href="#" class="hover:text-primary transition">Báo chí</a></li>
                        <li><a href="#" class="hover:text-primary transition">Tin tức & Blog</a></li>
                    </ul>
                </div>

                <!-- Cột Hỗ trợ khách hàng (2-3 cols) -->
                <div class="md:col-span-3">
                    <h4 class="text-xs font-extrabold text-slate-900 uppercase tracking-wider mb-3.5">Hỗ trợ khách hàng</h4>
                    <ul class="space-y-2.5 text-xs text-slate-500 font-medium">
                        <li><a href="#" class="hover:text-primary transition">Trung tâm trợ giúp</a></li>
                        <li><a href="/WebsiteDatVe/contact/contact.php" class="hover:text-primary transition">Liên hệ</a></li>
                        <li><a href="#" class="hover:text-primary transition">Đổi & Hủy vé</a></li>
                        <li><a href="#" class="hover:text-primary transition">Chính sách hoàn tiền</a></li>
                    </ul>
                </div>

                <!-- Cột Hãng hàng không (2 cols) -->
                <div class="md:col-span-2">
                    <h4 class="text-xs font-extrabold text-slate-900 uppercase tracking-wider mb-3.5">Hãng hàng không</h4>
                    <ul class="space-y-2.5 text-xs text-slate-500 font-medium">
                        <li><a href="#" class="hover:text-primary transition">Vietnam Airlines</a></li>
                        <li><a href="#" class="hover:text-primary transition">VietJet Air</a></li>
                        <li><a href="#" class="hover:text-primary transition">Bamboo Airways</a></li>
                        <li><a href="#" class="hover:text-primary transition">Tất cả hãng bay</a></li>
                    </ul>
                </div>
            </div>

            <!-- BẢN QUYỀN VÀ MẠNG XÃ HỘI -->
            <div class="pt-8 border-t border-slate-100 flex flex-col sm:flex-row items-center justify-between gap-4 text-xs text-slate-400">
                <p>&copy; <?= date('Y') ?> Booking Online. Tất cả quyền được bảo lưu.</p>

                <!-- Icon mạng xã hội tròn như ảnh mẫu -->
                <div class="flex items-center gap-3">
                    <a href="#" class="w-8 h-8 rounded-full bg-slate-100 hover:bg-primary hover:text-white text-slate-500 flex items-center justify-center transition">
                        <i class="fa-solid fa-paper-plane text-xs"></i>
                    </a>
                    <a href="#" class="w-8 h-8 rounded-full bg-slate-100 hover:bg-primary hover:text-white text-slate-500 flex items-center justify-center transition">
                        <i class="fa-solid fa-camera text-xs"></i>
                    </a>
                    <a href="#" class="w-8 h-8 rounded-full bg-slate-100 hover:bg-primary hover:text-white text-slate-500 flex items-center justify-center transition">
                        <i class="fa-solid fa-comment-dots text-xs"></i>
                    </a>
                    <a href="#" class="w-8 h-8 rounded-full bg-slate-100 hover:bg-primary hover:text-white text-slate-500 flex items-center justify-center transition">
                        <i class="fa-solid fa-play text-xs"></i>
                    </a>
                </div>
            </div>
        </div>
    </footer>

    <!-- ================= JAVASCRIPT XỬ LÝ TÍNH TOÁN & TƯƠNG TÁC ================= -->
    <script>
        const basePrice = <?= $basePrice ?>;
        const taxPrice = <?= $taxPrice ?>;
        const passengerCount = <?= $totalPassengers ?>;

        function onPassengerNameChange(index) {
            if (index === 0) {
                const first = document.getElementById('passengerFirstName')?.value.trim() || '';
                const last = document.getElementById('passengerLastName')?.value.trim() || '';
                if (first || last) document.getElementById('contactName').value = `${last} ${first}`.trim();
            }
            recalculate();
        }

        function formatMoney(amount) {
            return amount.toLocaleString('vi-VN') + 'đ';
        }

        function syncPassengerName() {
            const rawName = document.getElementById('contactName').value.trim();
            if (!rawName) return;
            const parts = rawName.split(' ');
            const lastName = document.getElementById('passengerLastName');
            const firstName = document.getElementById('passengerFirstName');
            if (parts.length > 1) {
                if (lastName) lastName.value = parts[0].toUpperCase();
                if (firstName) firstName.value = parts.slice(1).join(' ').toUpperCase();
            } else if (firstName) {
                firstName.value = rawName.toUpperCase();
            }
        }

        function recalculate() {
            const totalBase = basePrice * passengerCount;
            const totalTax = taxPrice * passengerCount;
            document.getElementById('basePriceSummary').textContent = formatMoney(totalBase);
            document.getElementById('taxPriceSummary').textContent = formatMoney(totalTax);
            document.getElementById('grandTotalDisplay').textContent = formatMoney(totalBase + totalTax);
        }

        recalculate();
    </script>
</body>
</html>
