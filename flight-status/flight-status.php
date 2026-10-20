<?php
session_start();
require_once __DIR__ . '/../api/config/database.php';

$searchFn = trim($_GET['fn'] ?? '');
$searchDate = trim($_GET['date'] ?? '');
$flightStatusList = [];
$statusCounts = [
    'scheduled' => 0,
    'delayed' => 0,
    'departed' => 0,
    'landed' => 0,
    'cancelled' => 0,
];

if ($conn) {
    $sql = "SELECT f.*, 
                   dep.city AS from_city, arr.city AS to_city
            FROM flights f
            JOIN airports dep ON f.from_airport = dep.code
            JOIN airports arr ON f.to_airport = arr.code
            WHERE 1=1";
    $params = [];
    $types = "";

    if (!empty($searchFn)) {
        $cleanFn = str_replace(' ', '', $searchFn);
        $sql .= " AND REPLACE(f.flight_number, ' ', '') LIKE ?";
        $params[] = "%{$cleanFn}%";
        $types .= "s";
    }
    if (!empty($searchDate)) {
        $sql .= " AND DATE(f.depart_at) = ?";
        $params[] = $searchDate;
        $types .= "s";
    }

    if ($searchFn !== '') {
        $sql .= " ORDER BY f.depart_at ASC";
    } else {
        $sql .= " ORDER BY ABS(TIMESTAMPDIFF(SECOND, f.depart_at, NOW())) ASC LIMIT 10";
    }

    $stmt = mysqli_prepare($conn, $sql);
    if ($stmt) {
        if (!empty($types)) {
            mysqli_stmt_bind_param($stmt, $types, ...$params);
        }
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        while ($row = mysqli_fetch_assoc($res)) {
            if (isset($statusCounts[$row['status']])) {
                $statusCounts[$row['status']]++;
            }
            $row['status'] = flightStatusLabel($row['status']);
            $flightStatusList[] = $row;
        }
        mysqli_stmt_close($stmt);
    }
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tình trạng chuyến bay - Booking Online</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: { sans: ['"Plus Jakarta Sans"', 'sans-serif'] },
                    colors: {
                        primary: { DEFAULT: '#0284c7', hover: '#0369a1', light: '#e0f2fe' }
                    }
                }
            }
        }
    </script>
    <style>body { font-family: 'Plus Jakarta Sans', sans-serif; }</style>
</head>
<body class="bg-slate-50 text-slate-800 flex flex-col min-h-screen">
    <!-- NAVBAR -->
    <header class="sticky top-0 z-50 bg-white/90 backdrop-blur-md border-b border-slate-200">
        <div class="max-w-6xl mx-auto px-4 sm:px-6 h-16 flex items-center justify-between">
            <a href="/WebsiteDatVe/index.php" class="flex items-center gap-2">
                <span class="w-10 h-10 rounded-xl bg-primary text-white flex items-center justify-center shadow-md shadow-sky-500/20">
                    <i class="fa-solid fa-plane text-lg"></i>
                </span>
                <span class="text-xl font-extrabold tracking-tight text-slate-900">Booking Online</span>
            </a>

            <nav class="hidden md:flex items-center gap-6 text-sm font-medium text-slate-600">
                <a href="/WebsiteDatVe/index.php" class="hover:text-primary transition">Trang chủ</a>
                <a href="/WebsiteDatVe/check-in/check-in.php" class="hover:text-primary transition">Check-in</a>
                <a href="/WebsiteDatVe/flight-status/flight-status.php" class="text-primary font-bold border-b-2 border-primary pb-1">Tình trạng bay</a>
                <a href="/WebsiteDatVe/manage-booking/manage-booking.php" class="hover:text-primary transition">Quản lý vé</a>
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
                    <a href="/WebsiteDatVe/register/register.php" class="px-4 py-2 text-sm font-semibold text-white bg-primary hover:bg-primary-hover rounded-xl shadow-md shadow-sky-500/20 transition">Đăng ký</a>
                <?php endif; ?>
            </div>
        </div>
    </header>

<main class="flex-1 w-full">
    <section class="relative overflow-hidden bg-slate-950 text-white">
        <div class="absolute inset-0 bg-[radial-gradient(ellipse_at_top_right,_var(--tw-gradient-stops))] from-sky-700/40 via-slate-950 to-slate-950"></div>
        <div class="relative mx-auto max-w-6xl px-4 py-12 sm:px-6 sm:py-16">
            <div class="max-w-3xl">
                <span class="inline-flex items-center gap-2 rounded-full border border-white/15 bg-white/10 px-3 py-1.5 text-xs font-bold text-sky-100">
                    <span class="relative flex h-2 w-2"><span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-emerald-400 opacity-70"></span><span class="relative inline-flex h-2 w-2 rounded-full bg-emerald-400"></span></span>
                    Trung tâm thông tin chuyến bay
                </span>
                <h1 class="mt-5 text-3xl font-black tracking-tight sm:text-5xl">Theo dõi hành trình,<br><span class="text-sky-300">chủ động mỗi chuyến đi</span></h1>
                <p class="mt-4 max-w-2xl text-sm leading-7 text-slate-300 sm:text-base">Tra cứu lịch khởi hành, giờ đến, cửa ra máy bay và trạng thái mới nhất được ghi nhận trong hệ thống. Danh sách mặc định hiển thị 10 chuyến gần thời điểm hiện tại; nhập mã chuyến bay để tìm chuyến khác.</p>
            </div>

            <form method="GET" action="" class="mt-8 grid gap-4 rounded-3xl border border-white/10 bg-white p-4 text-slate-800 shadow-2xl shadow-slate-950/30 sm:grid-cols-[1fr_1fr_auto] sm:p-5">
                <label class="block">
                    <span class="mb-2 block text-xs font-extrabold uppercase tracking-wider text-slate-500"><i class="fa-solid fa-plane-departure mr-1.5 text-sky-600"></i>Mã chuyến bay</span>
                    <input type="text" name="fn" value="<?= htmlspecialchars($searchFn, ENT_QUOTES, 'UTF-8') ?>" placeholder="VD: VN210, VJ123..." class="w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm font-semibold outline-none transition placeholder:font-normal placeholder:text-slate-400 focus:border-sky-500 focus:bg-white focus:ring-4 focus:ring-sky-100">
                </label>
                <label class="block">
                    <span class="mb-2 block text-xs font-extrabold uppercase tracking-wider text-slate-500"><i class="fa-regular fa-calendar mr-1.5 text-sky-600"></i>Ngày khởi hành</span>
                    <input type="date" name="date" value="<?= htmlspecialchars($searchDate, ENT_QUOTES, 'UTF-8') ?>" class="w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm font-semibold outline-none transition focus:border-sky-500 focus:bg-white focus:ring-4 focus:ring-sky-100">
                </label>
                <div class="flex items-end gap-2">
                    <button type="submit" class="inline-flex min-h-[48px] flex-1 items-center justify-center gap-2 rounded-2xl bg-sky-600 px-5 text-sm font-extrabold text-white shadow-lg shadow-sky-600/20 transition hover:bg-sky-700 sm:flex-none">
                        <i class="fa-solid fa-magnifying-glass"></i> Tra cứu
                    </button>
                    <?php if ($searchFn !== '' || $searchDate !== ''): ?>
                        <a href="/WebsiteDatVe/flight-status/flight-status.php" class="inline-flex min-h-[48px] items-center justify-center rounded-2xl border border-slate-200 px-4 text-sm font-bold text-slate-600 transition hover:bg-slate-50" aria-label="Xóa bộ lọc" title="Xóa bộ lọc">
                            <i class="fa-solid fa-rotate-left"></i>
                        </a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </section>

    <section class="mx-auto max-w-6xl px-4 py-8 sm:px-6 sm:py-10">
        <div class="grid grid-cols-2 gap-3 md:grid-cols-4">
            <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5">
                <p class="text-xs font-bold text-slate-500">Kết quả tra cứu</p>
                <p class="mt-2 text-2xl font-black text-slate-900"><?= count($flightStatusList) ?><span class="ml-1 text-sm font-semibold text-slate-400">chuyến</span></p>
            </div>
            <div class="rounded-2xl border border-emerald-100 bg-emerald-50/70 p-4 shadow-sm sm:p-5">
                <p class="text-xs font-bold text-emerald-800">Theo lịch trình</p>
                <p class="mt-2 text-2xl font-black text-emerald-700"><?= $statusCounts['scheduled'] + $statusCounts['landed'] ?></p>
            </div>
            <div class="rounded-2xl border border-amber-100 bg-amber-50/70 p-4 shadow-sm sm:p-5">
                <p class="text-xs font-bold text-amber-800">Đang khai thác</p>
                <p class="mt-2 text-2xl font-black text-amber-700"><?= $statusCounts['departed'] ?></p>
            </div>
            <div class="rounded-2xl border border-rose-100 bg-rose-50/70 p-4 shadow-sm sm:p-5">
                <p class="text-xs font-bold text-rose-800">Cần lưu ý</p>
                <p class="mt-2 text-2xl font-black text-rose-700"><?= $statusCounts['delayed'] + $statusCounts['cancelled'] ?></p>
            </div>
        </div>

        <div class="mt-8 flex flex-col justify-between gap-2 sm:flex-row sm:items-end">
            <div>
                <p class="text-xs font-extrabold uppercase tracking-[.16em] text-sky-700">Bảng khởi hành & hạ cánh</p>
                <h2 class="mt-1 text-xl font-black text-slate-900 sm:text-2xl">Lịch trình chuyến bay</h2>
            </div>
            <span class="text-xs font-medium text-slate-500"><i class="fa-regular fa-clock mr-1"></i>Hiển thị theo thời gian khởi hành</span>
        </div>

        <div class="mt-4 space-y-3">
            <?php if (empty($flightStatusList)): ?>
                <div class="rounded-3xl border border-dashed border-slate-300 bg-white px-6 py-14 text-center shadow-sm">
                    <span class="mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-slate-100 text-2xl text-slate-400"><i class="fa-solid fa-plane-slash"></i></span>
                    <h3 class="mt-4 text-base font-extrabold text-slate-800">Chưa tìm thấy chuyến bay phù hợp</h3>
                    <p class="mx-auto mt-2 max-w-md text-sm leading-6 text-slate-500">Kiểm tra lại số hiệu chuyến bay hoặc ngày khởi hành, rồi thử tra cứu lại.</p>
                    <?php if ($searchFn !== '' || $searchDate !== ''): ?>
                        <a href="/WebsiteDatVe/flight-status/flight-status.php" class="mt-5 inline-flex items-center gap-2 rounded-xl bg-sky-600 px-4 py-2.5 text-sm font-bold text-white hover:bg-sky-700">Về 10 chuyến gần nhất</a>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <?php foreach ($flightStatusList as $fl): ?>
                    <?php
                        $statusStyles = [
                            'Đúng giờ' => ['badge' => 'border-emerald-200 bg-emerald-50 text-emerald-800', 'icon' => 'fa-circle-check', 'dot' => 'bg-emerald-500'],
                            'Đã hạ cánh' => ['badge' => 'border-sky-200 bg-sky-50 text-sky-800', 'icon' => 'fa-plane-arrival', 'dot' => 'bg-sky-500'],
                            'Chậm chuyến' => ['badge' => 'border-rose-200 bg-rose-50 text-rose-800', 'icon' => 'fa-triangle-exclamation', 'dot' => 'bg-rose-500'],
                            'Đang boarding' => ['badge' => 'border-amber-200 bg-amber-50 text-amber-800', 'icon' => 'fa-person-walking-luggage', 'dot' => 'bg-amber-500'],
                            'Đã hủy' => ['badge' => 'border-slate-200 bg-slate-100 text-slate-600', 'icon' => 'fa-circle-xmark', 'dot' => 'bg-slate-400'],
                        ];
                        $statusStyle = $statusStyles[$fl['status']] ?? $statusStyles['Đúng giờ'];
                        $departTimestamp = strtotime($fl['depart_at']);
                        $arriveTimestamp = strtotime($fl['arrive_at']);
                        $durationMinutes = max(0, (int)round(($arriveTimestamp - $departTimestamp) / 60));
                        $durationLabel = floor($durationMinutes / 60) . ' giờ ' . str_pad((string)($durationMinutes % 60), 2, '0', STR_PAD_LEFT) . ' phút';
                    ?>
                    <article class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm transition hover:border-sky-200 hover:shadow-md">
                        <div class="grid gap-5 p-5 sm:p-6 lg:grid-cols-[1fr_auto] lg:items-center">
                            <div>
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="rounded-lg bg-slate-900 px-2.5 py-1 text-xs font-black tracking-wide text-white"><?= htmlspecialchars($fl['flight_number'], ENT_QUOTES, 'UTF-8') ?></span>
                                    <span class="text-sm font-bold text-slate-800"><?= htmlspecialchars($fl['airline'], ENT_QUOTES, 'UTF-8') ?></span>
                                    <span class="text-xs text-slate-400"><?= htmlspecialchars($fl['aircraft'] ?? '', ENT_QUOTES, 'UTF-8') ?></span>
                                </div>

                                <div class="mt-5 grid grid-cols-[1fr_auto_1fr] items-center gap-3 sm:max-w-2xl sm:gap-6">
                                    <div>
                                        <p class="text-2xl font-black tracking-tight text-slate-900 sm:text-3xl"><?= date('H:i', $departTimestamp) ?></p>
                                        <p class="mt-1 text-sm font-extrabold text-slate-700"><?= htmlspecialchars($fl['from_airport'], ENT_QUOTES, 'UTF-8') ?></p>
                                        <p class="text-xs text-slate-500"><?= htmlspecialchars($fl['from_city'], ENT_QUOTES, 'UTF-8') ?></p>
                                        <p class="mt-1 text-[11px] font-semibold text-slate-400"><?= date('d/m/Y', $departTimestamp) ?></p>
                                    </div>
                                    <div class="flex w-24 flex-col items-center sm:w-36">
                                        <span class="text-[10px] font-semibold text-slate-400"><?= htmlspecialchars($durationLabel, ENT_QUOTES, 'UTF-8') ?></span>
                                        <div class="relative mt-2 flex w-full items-center">
                                            <span class="h-2 w-2 rounded-full border-2 border-sky-600 bg-white"></span>
                                            <span class="h-px flex-1 bg-slate-200"></span>
                                            <i class="fa-solid fa-plane text-xs text-sky-600"></i>
                                            <span class="h-px flex-1 bg-slate-200"></span>
                                            <span class="h-2 w-2 rounded-full border-2 border-sky-600 bg-white"></span>
                                        </div>
                                        <span class="mt-2 text-[10px] font-bold text-slate-400">HÀNH TRÌNH</span>
                                    </div>
                                    <div class="text-right">
                                        <p class="text-2xl font-black tracking-tight text-slate-900 sm:text-3xl"><?= date('H:i', $arriveTimestamp) ?></p>
                                        <p class="mt-1 text-sm font-extrabold text-slate-700"><?= htmlspecialchars($fl['to_airport'], ENT_QUOTES, 'UTF-8') ?></p>
                                        <p class="text-xs text-slate-500"><?= htmlspecialchars($fl['to_city'], ENT_QUOTES, 'UTF-8') ?></p>
                                        <p class="mt-1 text-[11px] font-semibold text-slate-400"><?= date('d/m/Y', $arriveTimestamp) ?></p>
                                    </div>
                                </div>
                            </div>

                            <div class="flex flex-row items-center justify-between gap-4 border-t border-slate-100 pt-4 lg:min-w-48 lg:flex-col lg:items-end lg:justify-center lg:border-l lg:border-t-0 lg:pl-6 lg:pt-0">
                                <div class="flex items-center gap-2 rounded-full border px-3 py-1.5 text-xs font-extrabold <?= $statusStyle['badge'] ?>">
                                    <span class="h-2 w-2 rounded-full <?= $statusStyle['dot'] ?>"></span>
                                    <i class="fa-solid <?= $statusStyle['icon'] ?>"></i>
                                    <?= htmlspecialchars($fl['status'], ENT_QUOTES, 'UTF-8') ?>
                                </div>
                                <div class="text-right">
                                    <p class="text-[10px] font-extrabold uppercase tracking-wider text-slate-400">Cửa khởi hành</p>
                                    <p class="mt-1 text-lg font-black text-slate-900"><?= htmlspecialchars($fl['gate'] ?: 'Chưa cập nhật', ENT_QUOTES, 'UTF-8') ?></p>
                                </div>
                            </div>
                        </div>
                    </article>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </section>
</main>

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
</body>
</html>
