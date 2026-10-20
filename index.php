<?php
session_start();
require_once __DIR__ . '/api/config/database.php';

// Cấu hình cơ bản
$pageTitle = "Booking Online - Đặt vé máy bay trực tuyến";

// 1. Lấy danh sách sân bay từ CSDL
$airports = getAirports($conn);
// Không dùng dữ liệu mẫu — nếu DB chưa có sân bay thì $airports = []

// 2. Chặng bay phổ biến: lấy từ CSDL — top 5 tuyến có nhiều chuyến nhất
$popularRoutes = [];
if ($conn) {
    $prRes = mysqli_query($conn,
        "SELECT f.from_airport, f.to_airport,
                dep.city AS from_city, arr.city AS to_city,
                MIN(f.price_economy) AS min_price,
                COUNT(*) AS flight_count,
                dep.code AS dep_code, arr.code AS arr_code,
                MIN(TIMESTAMPDIFF(MINUTE, f.depart_at, f.arrive_at)) AS duration_min
         FROM flights f
         JOIN airports dep ON f.from_airport = dep.code
         JOIN airports arr ON f.to_airport = arr.code
         WHERE (f.seats_economy_left + f.seats_business_left) > 0 AND f.status <> 'cancelled'
         GROUP BY f.from_airport, f.to_airport
         ORDER BY flight_count DESC, min_price ASC
         LIMIT 4"
    );
    $cityImages = [
        'SGN' => 'images/ho-chi-minh.png',
        'HAN' => 'images/ha-noi.png',
        'DAD' => 'images/da-nang.png',
        'PQC' => 'images/phu-quoc.png',
        'DLI' => 'images/da-lat.png',
    ];
    $cityLabels = [
        'SGN' => 'TP. Hồ Chí Minh',
        'HAN' => 'Hà Nội',
        'DAD' => 'Đà Nẵng',
        'DLI' => 'Đà Lạt',
        'PQC' => 'Phú Quốc',
        'HPH' => 'Hải Phòng',
        'HUI' => 'Huế',
        'CXR' => 'Nha Trang',
        'VCA' => 'Cần Thơ',
    ];
    $cityRegions = [
        'HAN' => 'Thủ đô ngàn năm văn hiến',
        'HPH' => 'Cảng biển miền Bắc',
        'DAD' => 'Duyên hải miền Trung',
        'HUI' => 'Cố đô thơ mộng',
        'CXR' => 'Thiên đường biển đảo',
        'SGN' => 'Thành phố thương mại năng động',
        'PQC' => 'Đảo ngọc thiên đường',
        'VCA' => 'Tây Đô miền Tây',
        'DLI' => 'Tây Nguyên mộng mơ',
    ];
    if ($prRes) {
        while ($pr = mysqli_fetch_assoc($prRes)) {
            $durMin = max(30, (int)$pr['duration_min']);
            $durationText = floor($durMin / 60) . 'h ' . str_pad($durMin % 60, 2, '0', STR_PAD_LEFT) . 'm';
            $popularRoutes[] = [
                'city'     => $cityLabels[$pr['arr_code']] ?? $pr['to_city'],
                'from'     => $pr['from_city'] . ' (' . $pr['dep_code'] . ')',
                'image'    => $cityImages[$pr['arr_code']] ?? 'images/da-nang.png',
                'price'    => number_format((int)$pr['min_price']) . 'đ',
                'from_code'=> $pr['dep_code'],
                'to_code'  => $pr['arr_code'],
                'duration' => $durationText,
                'region'   => $cityRegions[$pr['arr_code']] ?? ('Từ ' . $pr['from_city']),
            ];
        }
    }
}

// 3. Lấy khuyến mãi từ CSDL (bảng promotions)
$promos = [];
if ($conn) {
    $promoRes = mysqli_query($conn, "SELECT * FROM promotions WHERE is_active = 1 ORDER BY id DESC LIMIT 3");
    if ($promoRes && mysqli_num_rows($promoRes) > 0) {
        $promoImages = ['images/promo-flight.png', 'images/promo-hotel.png', 'images/promo-train.png'];
        $idx = 0;
        while ($p = mysqli_fetch_assoc($promoRes)) {
            $discountText = $p['discount_type'] === 'percentage' ? ('Giảm ' . $p['discount_value'] . '%') : ('Giảm ' . number_format($p['discount_value']) . 'đ');
            $promos[] = [
                'title' => 'Mã ưu đãi ' . $p['code'],
                'discount' => $discountText,
                'code' => $p['code'],
                'image' => $promoImages[$idx % 3],
                'description' => $p['description'] ?: 'Áp dụng cho mọi đơn đặt vé một chiều nội địa.'
            ];
            $idx++;
        }
    }
}
// Không dùng khuyến mãi mẫu — nếu DB không có promo thì ẩn section
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?></title>
    <!-- Tailwind CSS CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['"Plus Jakarta Sans"', 'sans-serif'],
                    },
                    colors: {
                        primary: {
                            DEFAULT: '#2563eb',
                            hover: '#1d4ed8',
                            light: '#eff6ff'
                        }
                    }
                }
            }
        }
    </script>
    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; }
        input[type="date"]::-webkit-calendar-picker-indicator {
            position: absolute;
            inset: 0;
            width: 100%;
            height: 100%;
            opacity: 0;
            cursor: pointer;
        }
    </style>
</head>
<body class="bg-slate-50 text-slate-800 flex flex-col min-h-screen">

    <!-- HEADER / NAVBAR -->
    <header class="sticky top-0 z-50 bg-white/90 backdrop-blur-md border-b border-slate-200">
        <div class="max-w-6xl mx-auto px-4 sm:px-6 h-16 flex items-center justify-between">
            <a href="/WebsiteDatVe/index.php" class="flex items-center gap-2">
                <span class="w-10 h-10 rounded-xl bg-primary text-white flex items-center justify-center shadow-md shadow-blue-500/20">
                    <i class="fa-solid fa-plane text-lg"></i>
                </span>
                <span class="text-xl font-extrabold tracking-tight text-slate-900">Booking Online</span>
            </a>

            <nav class="hidden md:flex items-center gap-7 text-sm font-medium text-slate-600">
                <a href="/WebsiteDatVe/index.php" class="text-primary font-bold border-b-2 border-primary pb-1">Trang chủ</a>
                <a href="/WebsiteDatVe/check-in/check-in.php" class="hover:text-primary transition-colors">Check-in</a>
                <a href="/WebsiteDatVe/flight-status/flight-status.php" class="hover:text-primary transition-colors">Tình trạng chuyến bay</a>
                <a href="/WebsiteDatVe/manage-booking/manage-booking.php" class="hover:text-primary transition-colors">Quản lý đặt chỗ</a>
                <a href="/WebsiteDatVe/services/services.php" class="hover:text-primary transition-colors">Dịch vụ</a>
                <?php if (in_array($_SESSION['user']['role'] ?? '', ['super_admin', 'flight_manager', 'support', 'accountant'], true)): ?>
                    <a href="/WebsiteDatVe/admin/admin.php" class="text-purple-600 font-semibold hover:text-purple-700 transition-colors flex items-center gap-1">
                        <i class="fa-solid fa-gauge"></i> Admin
                    </a>
                <?php endif; ?>
            </nav>

            <div class="flex items-center gap-3">
                <?php if (!empty($_SESSION['user'])): ?>
                    <a href="/WebsiteDatVe/account/account.php" aria-label="Tài khoản hội viên" title="Tài khoản hội viên" class="flex h-10 w-10 items-center justify-center rounded-full border border-slate-200 bg-slate-100 text-primary transition hover:border-primary hover:bg-primary-light focus:outline-none focus:ring-2 focus:ring-primary focus:ring-offset-2">
                        <i class="fa-solid fa-user text-base" aria-hidden="true"></i>
                    </a>
                    <a href="/WebsiteDatVe/login/logout.php" class="px-3 py-2 text-xs font-semibold text-rose-600 hover:bg-rose-50 rounded-xl transition">
                        Đăng xuất
                    </a>
                <?php else: ?>
                    <a href="/WebsiteDatVe/login/login.php" class="px-4 py-2 text-sm font-semibold text-slate-700 hover:text-primary hover:bg-slate-100 rounded-xl transition">
                        Đăng nhập
                    </a>
                    <a href="/WebsiteDatVe/register/register.php" class="px-4 py-2 text-sm font-semibold text-white bg-primary hover:bg-primary-hover rounded-xl shadow-md shadow-blue-500/20 transition">
                        Đăng ký
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </header>

    <!-- HERO SEARCH SECTION -->
    <section class="relative z-20">
        <div class="absolute inset-0 overflow-hidden">
            <img src="images/hero-travel.png" alt="Hero banner" class="w-full h-full object-cover brightness-[0.88]">
            <div class="absolute inset-0 bg-gradient-to-b from-black/40 via-transparent to-slate-50"></div>
        </div>

        <div class="relative max-w-7xl mx-auto px-4 sm:px-6 pt-16 sm:pt-20 xl:pt-24 pb-20 sm:pb-28 xl:pb-36">
            <div class="max-w-3xl text-white mb-8 sm:mb-10">
                <span class="inline-flex items-center gap-2 px-4 py-2 rounded-full bg-white/20 backdrop-blur-md text-sm font-semibold tracking-wide mb-4">
                    <i class="fa-solid fa-sparkles text-amber-300"></i> Được hơn 2 triệu khách hàng tin dùng
                </span>
                <h1 class="max-w-3xl text-4xl sm:text-6xl xl:text-7xl font-extrabold tracking-tight leading-[1.08]">
                    Đặt vé máy bay giá rẻ trong tích tắc
                </h1>
                <p class="mt-5 max-w-3xl text-slate-100 text-base sm:text-xl leading-relaxed">
                    So sánh giá vé từ tất cả các hãng hàng không nội địa và quốc tế.<br class="hidden sm:block">
                    Tìm chuyến bay tốt nhất, không phụ phí ẩn.
                </p>
            </div>

            <!-- SEARCH BOX: thanh pill theo mockup -->
            <div class="rounded-[28px] bg-white/95 p-3 shadow-[0_16px_50px_rgba(15,23,42,0.2)] backdrop-blur sm:rounded-[32px] sm:p-4">
                <form action="/WebsiteDatVe/flights/flights.php" method="GET" id="searchFlightForm">
                    <input type="hidden" name="tripType" value="one-way">
                    <input type="hidden" id="adultsInput" name="adults" value="1">
                    <input type="hidden" id="childrenInput" name="children" value="0">
                    <input type="hidden" id="infantsInput" name="infants" value="0">
                    <input type="hidden" id="passengersInput" name="passengers" value="1">

                    <datalist id="airportList">
                        <?php foreach ($airports as $ap): ?>
                            <option value="<?= htmlspecialchars($ap['city']) ?> (<?= $ap['code'] ?>)"><?= htmlspecialchars($ap['name']) ?></option>
                        <?php endforeach; ?>
                    </datalist>

                    <div class="flex flex-col gap-3 xl:flex-row xl:items-center">

                        <!-- Điểm đi / Điểm đến: 2 ô riêng, nút đảo ở giữa -->
                        <div class="relative flex min-w-0 flex-col gap-2 sm:flex-row xl:flex-[1.6]">
                            <div class="flex h-[80px] min-w-0 flex-1 items-center gap-3 rounded-[20px] border border-slate-200 bg-white px-5 pr-9 sm:h-[92px]">
                                <span class="shrink-0 text-xl text-[#2b7de9]"><i class="fa-solid fa-location-dot"></i></span>
                                <div class="flex-1 min-w-0">
                                    <span class="block text-sm font-medium leading-tight text-slate-500">Điểm đi</span>
                                    <input type="text" id="fromInput" name="from" list="airportList" placeholder="Chọn điểm đi" required class="w-full truncate bg-transparent text-base font-bold text-slate-800 focus:outline-none">
                                </div>
                            </div>

                            <button type="button" onclick="swapLocations()" title="Đổi chiều" class="absolute left-1/2 top-1/2 z-10 flex h-10 w-10 -translate-x-1/2 -translate-y-1/2 items-center justify-center rounded-full border border-slate-200 bg-white text-[#2b7de9] shadow-md transition hover:bg-sky-50">
                                <i class="fa-solid fa-arrow-right-arrow-left text-xs"></i>
                            </button>

                            <div class="flex h-[80px] min-w-0 flex-1 items-center gap-3 rounded-[20px] border border-slate-200 bg-white px-5 pl-9 sm:h-[92px]">
                                <span class="shrink-0 text-xl text-[#2b7de9]"><i class="fa-solid fa-location-dot"></i></span>
                                <div class="flex-1 min-w-0">
                                    <span class="block text-sm font-medium leading-tight text-slate-500">Điểm đến</span>
                                    <input type="text" id="toInput" name="to" list="airportList" placeholder="Chọn điểm đến" required class="w-full truncate bg-transparent text-base font-bold text-slate-800 focus:outline-none">
                                </div>
                            </div>
                        </div>

                        <!-- Ngày đi -->
                        <div class="relative flex h-[80px] shrink-0 cursor-pointer items-center gap-3 rounded-[20px] border border-slate-200 bg-white px-5 sm:h-[92px] xl:w-[230px]">
                            <span class="shrink-0 text-xl text-[#2b7de9]"><i class="fa-regular fa-calendar"></i></span>
                            <div class="flex-1 min-w-0">
                                <span class="block text-sm font-medium leading-tight text-slate-500">Ngày đi</span>
                                <span id="departDateDisplay" class="block text-base font-bold leading-tight text-slate-800"><?= date('d/m/Y') ?></span>
                            </div>
                            <span class="text-slate-400 text-sm shrink-0"><i class="fa-regular fa-calendar"></i></span>
                            <input type="date" id="departDateInput" name="departDate" value="<?= date('Y-m-d') ?>" required class="absolute inset-0 w-full h-full opacity-0 cursor-pointer">
                        </div>

                        <!-- Hành khách -->
                        <div class="relative shrink-0 xl:w-[200px]" id="passengerDropdownContainer">
                            <button type="button" id="passengerBtn" class="flex h-[80px] w-full items-center justify-between gap-2 rounded-[20px] border border-slate-200 bg-white px-5 text-left transition hover:border-sky-300 sm:h-[92px]">
                                <div class="flex items-center gap-2 min-w-0">
                                    <span class="shrink-0 text-xl text-[#2b7de9]"><i class="fa-regular fa-user"></i></span>
                                    <div class="min-w-0">
                                        <span class="block text-sm font-medium leading-tight text-slate-500">Hành khách</span>
                                        <span id="passengerDisplayText" class="block max-w-[7rem] text-base font-bold leading-tight text-slate-800">01 Hành khách</span>
                                    </div>
                                </div>
                                <i id="passengerChevron" class="fa-solid fa-chevron-down text-[10px] text-slate-400 transition-transform shrink-0"></i>
                            </button>

                            <div id="passengerPopup" class="hidden absolute right-0 top-full mt-2 w-80 sm:w-96 bg-white rounded-3xl p-5 shadow-2xl border border-slate-100 z-50">
                                <div class="border-b border-slate-100 pb-3 mb-4">
                                    <h3 class="text-base font-bold text-slate-900">Hành khách</h3>
                                </div>
                                <div class="space-y-4">
                                    <div class="flex items-center justify-between">
                                        <div>
                                            <p id="adultsLabel" class="text-sm font-bold text-slate-900">1 Người lớn</p>
                                            <p class="text-xs text-slate-400">Từ 12 tuổi trở lên</p>
                                        </div>
                                        <div class="flex items-center gap-3">
                                            <button type="button" id="btnAdultsMinus" onclick="changeCount('adults', -1)" class="w-8 h-8 rounded-full border border-dashed border-primary text-primary flex items-center justify-center hover:bg-sky-50 disabled:opacity-30 disabled:pointer-events-none transition">
                                                <i class="fa-solid fa-minus text-xs"></i>
                                            </button>
                                            <span id="adultsCount" class="w-5 text-center text-sm font-bold text-slate-900">1</span>
                                            <button type="button" id="btnAdultsPlus" onclick="changeCount('adults', 1)" class="w-8 h-8 rounded-full border border-dashed border-primary text-primary flex items-center justify-center hover:bg-sky-50 transition">
                                                <i class="fa-solid fa-plus text-xs"></i>
                                            </button>
                                        </div>
                                    </div>
                                    <div class="flex items-center justify-between border-t border-slate-100 pt-3">
                                        <div>
                                            <p id="childrenLabel" class="text-sm font-bold text-slate-900">0 Trẻ em</p>
                                            <p class="text-xs text-slate-400">2 - 12 tuổi</p>
                                        </div>
                                        <div class="flex items-center gap-3">
                                            <button type="button" id="btnChildrenMinus" onclick="changeCount('children', -1)" disabled class="w-8 h-8 rounded-full border border-dashed border-primary text-primary flex items-center justify-center hover:bg-sky-50 disabled:opacity-30 disabled:pointer-events-none transition">
                                                <i class="fa-solid fa-minus text-xs"></i>
                                            </button>
                                            <span id="childrenCount" class="w-5 text-center text-sm font-bold text-slate-900">0</span>
                                            <button type="button" id="btnChildrenPlus" onclick="changeCount('children', 1)" class="w-8 h-8 rounded-full border border-dashed border-primary text-primary flex items-center justify-center hover:bg-sky-50 transition">
                                                <i class="fa-solid fa-plus text-xs"></i>
                                            </button>
                                        </div>
                                    </div>
                                    <div class="flex items-center justify-between border-t border-slate-100 pt-3">
                                        <div>
                                            <p id="infantsLabel" class="text-sm font-bold text-slate-900">0 Em bé</p>
                                            <p class="text-xs text-slate-400">Dưới 2 tuổi</p>
                                        </div>
                                        <div class="flex items-center gap-3">
                                            <button type="button" id="btnInfantsMinus" onclick="changeCount('infants', -1)" disabled class="w-8 h-8 rounded-full border border-dashed border-primary text-primary flex items-center justify-center hover:bg-sky-50 disabled:opacity-30 disabled:pointer-events-none transition">
                                                <i class="fa-solid fa-minus text-xs"></i>
                                            </button>
                                            <span id="infantsCount" class="w-5 text-center text-sm font-bold text-slate-900">0</span>
                                            <button type="button" id="btnInfantsPlus" onclick="changeCount('infants', 1)" class="w-8 h-8 rounded-full border border-dashed border-primary text-primary flex items-center justify-center hover:bg-sky-50 transition">
                                                <i class="fa-solid fa-plus text-xs"></i>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                                <div class="mt-5 flex justify-end border-t border-slate-100 pt-3">
                                    <button type="button" onclick="closePassengerPopup()" class="px-6 py-2.5 rounded-xl bg-primary hover:bg-primary-hover text-white text-xs font-bold shadow-md shadow-blue-500/20 transition">
                                        Chọn
                                    </button>
                                </div>
                            </div>
                        </div>

                        <!-- Nút tìm -->
                        <button type="submit" class="h-[80px] shrink-0 rounded-[20px] bg-[#2563eb] px-6 text-base font-bold text-white transition hover:bg-[#1d4ed8] sm:h-[92px] xl:w-[225px]">
                            <span class="flex items-center justify-center gap-3">
                            <i class="fa-solid fa-magnifying-glass"></i> Tìm chuyến bay
                            </span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </section>

    <!-- CHẶNG BAY PHỔ BIẾN -->
    <section class="max-w-7xl mx-auto px-4 sm:px-6 py-14">
        <div class="flex items-end justify-between mb-8">
            <div>
                <h2 class="text-3xl font-bold tracking-tight text-slate-900">Chặng bay phổ biến</h2>
                <p class="mt-1 text-slate-500 text-sm">Các điểm đến thịnh hành được nhiều du khách lựa chọn nhất trong tháng.</p>
            </div>
            <a href="#searchFlightForm" class="hidden sm:inline-flex items-center gap-1.5 text-sm font-semibold text-primary hover:underline">
                Chọn hành trình <i class="fa-solid fa-arrow-right text-xs"></i>
            </a>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6">
            <?php foreach ($popularRoutes as $route): ?>
                <a href="/WebsiteDatVe/flights/flights.php?from=<?= urlencode($route['from_code']) ?>&to=<?= urlencode($route['to_code']) ?>" class="group bg-white rounded-[24px] overflow-hidden border border-slate-100 shadow-sm hover:shadow-xl hover:shadow-blue-500/10 hover:-translate-y-1 transition-all duration-300">
                    <div class="relative h-52 overflow-hidden">
                        <img src="<?= htmlspecialchars($route['image']) ?>" alt="<?= htmlspecialchars($route['city']) ?>" class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-500">
                        <span class="absolute top-3 left-3 px-3 py-1.5 rounded-full bg-white/95 backdrop-blur-md text-xs font-semibold text-slate-700 flex items-center gap-1.5 shadow-sm">
                            <i class="fa-regular fa-clock text-primary"></i> <?= htmlspecialchars($route['duration']) ?>
                        </span>
                    </div>
                    <div class="p-5">
                        <p class="text-xs font-medium text-slate-400"><?= htmlspecialchars($route['region']) ?></p>
                        <h3 class="text-xl font-bold text-slate-900 mt-1"><?= htmlspecialchars($route['city']) ?></h3>
                        <div class="mt-5 flex items-end justify-between">
                            <div>
                                <span class="text-xs text-slate-400">Giá từ</span>
                                <p class="text-lg font-extrabold text-primary leading-tight"><?= htmlspecialchars($route['price']) ?></p>
                            </div>
                            <span class="w-9 h-9 rounded-full bg-blue-50 text-primary flex items-center justify-center text-sm group-hover:bg-primary group-hover:text-white transition">
                                <i class="fa-solid fa-arrow-right"></i>
                            </span>
                        </div>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    </section>

    <!-- ƯU ĐÃI & KHUYẾN MÃI -->
    <section class="bg-slate-100/70 py-16">
        <div class="max-w-6xl mx-auto px-4 sm:px-6">
            <div class="mb-8">
                <h2 class="text-2xl font-bold tracking-tight text-slate-900 sm:text-3xl">Ưu đãi & Khuyến mãi hot</h2>
                <p class="mt-1 text-slate-500 text-sm">Nhận ngay các mã giảm giá hấp dẫn có thời hạn khi đặt vé sớm.</p>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                <?php foreach ($promos as $promo): ?>
                    <div class="bg-white rounded-2xl overflow-hidden border border-slate-200 shadow-sm hover:shadow-lg transition group">
                        <div class="relative h-44 overflow-hidden">
                            <img src="<?= htmlspecialchars($promo['image']) ?>" alt="<?= htmlspecialchars($promo['title']) ?>" class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-500">
                            <span class="absolute top-3 right-3 px-3 py-1 rounded-full bg-primary text-white text-xs font-bold shadow-md">
                                <?= $promo['discount'] ?>
                            </span>
                        </div>
                        <div class="p-5">
                            <h3 class="text-base font-bold text-slate-900"><?= $promo['title'] ?></h3>
                            <p class="text-xs text-slate-500 mt-2 leading-relaxed"><?= $promo['description'] ?></p>
                            <div class="mt-4 pt-4 border-t border-slate-100 flex items-center justify-between">
                                <span class="px-2.5 py-1 rounded-lg border border-dashed border-blue-400 bg-blue-50 text-xs font-bold text-primary">
                                    Mã: <?= $promo['code'] ?>
                                </span>
                                <a href="/WebsiteDatVe/flights/flights.php?code=<?= $promo['code'] ?>" class="text-xs font-bold text-primary hover:underline">
                                    Dùng ngay
                                </a>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <!-- VÌ SAO CHỌN CHÚNG TÔI -->
    <section class="max-w-6xl mx-auto px-4 sm:px-6 py-16">
        <div class="text-center max-w-2xl mx-auto mb-12">
            <h2 class="text-2xl font-bold tracking-tight text-slate-900 sm:text-3xl">Vì sao chọn Booking Online</h2>
            <p class="mt-2 text-slate-500 text-sm">Tất cả những gì bạn cần cho một hành trình trọn vẹn và suôn sẻ.</p>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6">
            <div class="p-6 rounded-2xl bg-white border border-slate-200 hover:border-primary/40 hover:shadow-md transition">
                <span class="w-12 h-12 rounded-xl bg-blue-50 text-primary flex items-center justify-center text-xl mb-4">
                    <i class="fa-solid fa-tag"></i>
                </span>
                <h3 class="font-bold text-slate-900 mb-1">Giá tốt nhất</h3>
                <p class="text-xs text-slate-500 leading-relaxed">So sánh giá từ hàng ngàn chuyến bay để đảm bảo bạn luôn có mức giá tiết kiệm nhất.</p>
            </div>
            <div class="p-6 rounded-2xl bg-white border border-slate-200 hover:border-primary/40 hover:shadow-md transition">
                <span class="w-12 h-12 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center text-xl mb-4">
                    <i class="fa-solid fa-shield-halved"></i>
                </span>
                <h3 class="font-bold text-slate-900 mb-1">Thanh toán an toàn</h3>
                <p class="text-xs text-slate-500 leading-relaxed">Bảo mật chuẩn mã hóa giúp mọi giao dịch thanh toán của bạn an toàn tuyệt đối.</p>
            </div>
            <div class="p-6 rounded-2xl bg-white border border-slate-200 hover:border-primary/40 hover:shadow-md transition">
                <span class="w-12 h-12 rounded-xl bg-amber-50 text-amber-600 flex items-center justify-center text-xl mb-4">
                    <i class="fa-solid fa-headset"></i>
                </span>
                <h3 class="font-bold text-slate-900 mb-1">Hỗ trợ 24/7</h3>
                <p class="text-xs text-slate-500 leading-relaxed">Đội ngũ chuyên viên luôn sẵn sàng giải đáp thắc mắc của bạn mọi lúc, mọi nơi.</p>
            </div>
            <div class="p-6 rounded-2xl bg-white border border-slate-200 hover:border-primary/40 hover:shadow-md transition">
                <span class="w-12 h-12 rounded-xl bg-purple-50 text-purple-600 flex items-center justify-center text-xl mb-4">
                    <i class="fa-solid fa-rotate-left"></i>
                </span>
                <h3 class="font-bold text-slate-900 mb-1">Đổi vé dễ dàng</h3>
                <p class="text-xs text-slate-500 leading-relaxed">Chính sách hoàn đổi vé linh hoạt, thủ tục nhanh chóng, tiện lợi cho hành khách.</p>
            </div>
        </div>
    </section>

    <!-- FOOTER -->
    <footer class="mt-auto bg-white border-t border-slate-200">
        <div class="max-w-6xl mx-auto px-4 sm:px-6 py-12">
            <div class="grid grid-cols-1 md:grid-cols-4 gap-8 mb-8">
                <div class="md:col-span-2">
                    <div class="flex items-center gap-2 mb-3">
                        <span class="w-8 h-8 rounded-lg bg-primary text-white flex items-center justify-center">
                            <i class="fa-solid fa-plane text-sm"></i>
                        </span>
                        <span class="text-lg font-bold text-slate-900">Booking Online</span>
                    </div>
                    <p class="text-xs text-slate-500 max-w-sm leading-relaxed mb-4">
                        Hệ thống đặt vé máy bay trực tuyến nhanh chóng, tiện lợi, giá rẻ và bảo mật hàng đầu Việt Nam.
                    </p>
                    <div class="flex items-center gap-3 text-slate-400">
                        <a href="#" class="w-8 h-8 rounded-full bg-slate-100 flex items-center justify-center hover:text-primary transition"><i class="fa-brands fa-facebook-f text-xs"></i></a>
                        <a href="#" class="w-8 h-8 rounded-full bg-slate-100 flex items-center justify-center hover:text-primary transition"><i class="fa-brands fa-youtube text-xs"></i></a>
                        <a href="#" class="w-8 h-8 rounded-full bg-slate-100 flex items-center justify-center hover:text-primary transition"><i class="fa-brands fa-tiktok text-xs"></i></a>
                    </div>
                </div>

                <div>
                    <h4 class="text-xs font-bold text-slate-900 uppercase tracking-wider mb-3">Về chúng tôi</h4>
                    <ul class="space-y-2 text-xs text-slate-500">
                        <li><a href="#" class="hover:text-primary transition">Giới thiệu công ty</a></li>
                        <li><a href="#" class="hover:text-primary transition">Quy chế hoạt động</a></li>
                        <li><a href="#" class="hover:text-primary transition">Chính sách bảo mật</a></li>
                        <li><a href="#" class="hover:text-primary transition">Điều khoản sử dụng</a></li>
                    </ul>
                </div>

                <div>
                    <h4 class="text-xs font-bold text-slate-900 uppercase tracking-wider mb-3">Hỗ trợ</h4>
                    <ul class="space-y-2 text-xs text-slate-500">
                        <li><a href="#" class="hover:text-primary transition">Hướng dẫn đặt vé</a></li>
                        <li><a href="#" class="hover:text-primary transition">Hình thức thanh toán</a></li>
                        <li><a href="#" class="hover:text-primary transition">Câu hỏi thường gặp</a></li>
                        <li><a href="#" class="hover:text-primary transition">Hotline: 1900 6868</a></li>
                    </ul>
                </div>
            </div>

            <div class="pt-8 border-t border-slate-100 text-center text-xs text-slate-400">
                &copy; <?= date('Y') ?> Booking Online. Đồ án xây dựng Website Đặt vé máy bay (PHP & JavaScript).
            </div>
        </div>
    </footer>

    <!-- JAVASCRIPT XỬ LÝ TƯƠNG TÁC POPUP HÀNH KHÁCH & ĐẢO CHIỀU -->
    <script>
        // Trạng thái số lượng hành khách
        let adults = 1;
        let children = 0;
        let infants = 0;
        let isPassengerOpen = false;

        const passengerBtn = document.getElementById('passengerBtn');
        const passengerPopup = document.getElementById('passengerPopup');
        const passengerChevron = document.getElementById('passengerChevron');
        const passengerContainer = document.getElementById('passengerDropdownContainer');

        function togglePassengerPopup() {
            isPassengerOpen = !isPassengerOpen;
            if (isPassengerOpen) {
                passengerPopup.classList.remove('hidden');
                passengerChevron.classList.add('rotate-180');
                passengerBtn.classList.add('border-primary', 'ring-2', 'ring-primary/20');
            } else {
                closePassengerPopup();
            }
        }

        function closePassengerPopup() {
            isPassengerOpen = false;
            passengerPopup.classList.add('hidden');
            passengerChevron.classList.remove('rotate-180');
            passengerBtn.classList.remove('border-primary', 'ring-2', 'ring-primary/20');
        }

        passengerBtn.addEventListener('click', function(e) {
            e.stopPropagation();
            togglePassengerPopup();
        });

        // Đóng popup khi click ra ngoài
        document.addEventListener('click', function(e) {
            if (!passengerContainer.contains(e.target)) {
                closePassengerPopup();
            }
        });

        function changeCount(type, delta) {
            if (type === 'adults') {
                adults = Math.max(1, Math.min(9, adults + delta));
                if (infants > adults) infants = adults; // Số em bé không vượt quá số người lớn
            } else if (type === 'children') {
                children = Math.max(0, Math.min(9, children + delta));
            } else if (type === 'infants') {
                infants = Math.max(0, Math.min(adults, infants + delta));
            }
            updatePassengerUI();
        }

        function updatePassengerUI() {
            const total = adults + children + infants;
            
            // Cập nhật số liệu hiển thị
            document.getElementById('adultsCount').textContent = adults;
            document.getElementById('childrenCount').textContent = children;
            document.getElementById('infantsCount').textContent = infants;

            document.getElementById('adultsLabel').textContent = adults + ' Người lớn';
            document.getElementById('childrenLabel').textContent = children + ' Trẻ em';
            document.getElementById('infantsLabel').textContent = infants + ' Em bé';

            // Cập nhật text trên nút dropdown (VD: 01 Hành khách)
            const paddedTotal = String(total).padStart(2, '0');
            document.getElementById('passengerDisplayText').textContent = paddedTotal + ' Hành khách';

            // Cập nhật các input ẩn của form
            document.getElementById('adultsInput').value = adults;
            document.getElementById('childrenInput').value = children;
            document.getElementById('infantsInput').value = infants;
            document.getElementById('passengersInput').value = total;

            // Bật/tắt trạng thái disable các nút bấm
            document.getElementById('btnAdultsMinus').disabled = (adults <= 1);
            document.getElementById('btnAdultsPlus').disabled = (adults >= 9);

            document.getElementById('btnChildrenMinus').disabled = (children <= 0);
            document.getElementById('btnChildrenPlus').disabled = (children >= 9);

            document.getElementById('btnInfantsMinus').disabled = (infants <= 0);
            document.getElementById('btnInfantsPlus').disabled = (infants >= adults);
        }

        // Đảo chiều điểm đi - điểm đến
        function swapLocations() {
            const fromInput = document.getElementById('fromInput');
            const toInput = document.getElementById('toInput');
            const temp = fromInput.value;
            fromInput.value = toInput.value;
            toInput.value = temp;
        }

        // Hiển thị ngày đi dạng dd/mm/yyyy
        const departDateInput = document.getElementById('departDateInput');
        const departDateDisplay = document.getElementById('departDateDisplay');
        function formatDateVN(iso) {
            if (!iso) return '';
            const parts = iso.split('-');
            return parts[2] + '/' + parts[1] + '/' + parts[0];
        }
        if (departDateInput && departDateDisplay) {
            departDateInput.addEventListener('change', function () {
                departDateDisplay.textContent = formatDateVN(this.value);
            });
            departDateDisplay.textContent = formatDateVN(departDateInput.value);
        }
    </script>
</body>
</html>
