<?php
session_start();
require_once __DIR__ . '/../api/config/database.php';

if (empty($_SESSION['user']['id'])) {
    header('Location: /WebsiteDatVe/login/login.php');
    exit;
}

$userId = (int)$_SESSION['user']['id'];
$accountError = '';
$user = null;
$stats = ['booking_count' => 0, 'points' => 0];
$bookings = [];

if (!$conn) {
    $accountError = 'Không thể kết nối cơ sở dữ liệu. Vui lòng thử lại sau.';
} else {
    $userStmt = mysqli_prepare($conn, 'SELECT fullname, email, phone, created_at, tier, points FROM users WHERE id = ? AND status = \'Hoat_Dong\' LIMIT 1');
    if (!$userStmt) {
        error_log('Account user query prepare failed: ' . mysqli_error($conn));
        $accountError = 'Không thể tải thông tin tài khoản. Vui lòng thử lại sau.';
    } else {
        mysqli_stmt_bind_param($userStmt, 'i', $userId);
        if (!mysqli_stmt_execute($userStmt)) {
            error_log('Account user query failed: ' . mysqli_stmt_error($userStmt));
            $accountError = 'Không thể tải thông tin tài khoản. Vui lòng thử lại sau.';
        } else {
            $user = mysqli_fetch_assoc(mysqli_stmt_get_result($userStmt));
            if (!$user) {
                $accountError = 'Không tìm thấy tài khoản. Vui lòng đăng nhập lại.';
            }
        }
        mysqli_stmt_close($userStmt);
    }

    if ($accountError === '') {
        $statsStmt = mysqli_prepare($conn, 'SELECT COUNT(*) AS booking_count FROM bookings WHERE user_id = ?');
        if (!$statsStmt) {
            error_log('Account stats query prepare failed: ' . mysqli_error($conn));
            $accountError = 'Không thể tải điểm hội viên. Vui lòng thử lại sau.';
        } else {
            mysqli_stmt_bind_param($statsStmt, 'i', $userId);
            if (!mysqli_stmt_execute($statsStmt)) {
                error_log('Account stats query failed: ' . mysqli_stmt_error($statsStmt));
                $accountError = 'Không thể tải điểm hội viên. Vui lòng thử lại sau.';
            } else {
                $stats = array_merge($stats, mysqli_fetch_assoc(mysqli_stmt_get_result($statsStmt)) ?: []);
                $stats['points'] = (int)$user['points'];
            }
            mysqli_stmt_close($statsStmt);
        }
    }

    if ($accountError === '') {
        $bookingStmt = mysqli_prepare($conn,
            "SELECT b.booking_code, b.total_price, b.payment_status, b.booking_status, b.created_at,
                    f.flight_number, f.depart_at, dep.city AS from_city, arr.city AS to_city
             FROM bookings b
             JOIN flights f ON f.id = b.flight_id
             JOIN airports dep ON dep.code = f.from_airport
             JOIN airports arr ON arr.code = f.to_airport
             WHERE b.user_id = ?
             ORDER BY b.created_at DESC
             LIMIT 8"
        );
        if (!$bookingStmt) {
            error_log('Account bookings query prepare failed: ' . mysqli_error($conn));
            $accountError = 'Không thể tải lịch sử đặt vé. Vui lòng thử lại sau.';
        } else {
            mysqli_stmt_bind_param($bookingStmt, 'i', $userId);
            if (!mysqli_stmt_execute($bookingStmt)) {
                error_log('Account bookings query failed: ' . mysqli_stmt_error($bookingStmt));
                $accountError = 'Không thể tải lịch sử đặt vé. Vui lòng thử lại sau.';
            } else {
                $bookingResult = mysqli_stmt_get_result($bookingStmt);
                while ($booking = mysqli_fetch_assoc($bookingResult)) {
                    $bookings[] = $booking;
                }
            }
            mysqli_stmt_close($bookingStmt);
        }
    }
}

$points = (int)($user['points'] ?? $stats['points']);
$tiers = [
    ['code' => 'PhoThong', 'name' => 'Phổ thông', 'min_points' => 0, 'icon' => 'fa-medal', 'color' => 'amber',
        'perks' => ['Tích lũy 1 điểm cho mỗi 10.000đ chi tiêu', 'Theo dõi lịch sử đặt vé và điểm thưởng']],
    ['code' => 'Bac', 'name' => 'Bạc', 'min_points' => 1000, 'icon' => 'fa-award', 'color' => 'slate',
        'perks' => ['Ưu đãi dành riêng cho hội viên hạng Bạc', 'Ưu tiên hỗ trợ khi cần trợ giúp']],
    ['code' => 'Vang', 'name' => 'Vàng', 'min_points' => 3000, 'icon' => 'fa-crown', 'color' => 'yellow',
        'perks' => ['Mở khóa ưu đãi hội viên hạng Vàng', 'Được ưu tiên hỗ trợ đặt chỗ']],
    ['code' => 'BachKim', 'name' => 'Bạch kim', 'min_points' => 6000, 'icon' => 'fa-gem', 'color' => 'sky',
        'perks' => ['Đặc quyền cao nhất dành cho hội viên', 'Ưu tiên hỗ trợ các yêu cầu dịch vụ']],
];

$tierIndex = 0;
foreach ($tiers as $index => $tier) {
    if (($user['tier'] ?? 'PhoThong') === $tier['code']) {
        $tierIndex = $index;
        break;
    }
}
$currentTier = $tiers[$tierIndex];
$nextTier = $tiers[$tierIndex + 1] ?? null;
$progress = $nextTier
    ? min(100, max(0, (($points - $currentTier['min_points']) / ($nextTier['min_points'] - $currentTier['min_points'])) * 100))
    : 100;
$pointsToNext = $nextTier ? max(0, $nextTier['min_points'] - $points) : 0;
$displayName = $user['fullname'] ?? ($_SESSION['user']['fullname'] ?? 'Hội viên');
$statusStyles = [
    'paid' => 'bg-emerald-50 text-emerald-700',
    'unpaid' => 'bg-amber-50 text-amber-700',
    'refunded' => 'bg-rose-50 text-rose-700',
];
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tài khoản hội viên - Booking Online</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>body { font-family: 'Plus Jakarta Sans', sans-serif; }</style>
</head>
<body class="min-h-screen bg-slate-50 text-slate-800">
    <header class="sticky top-0 z-20 border-b border-slate-200 bg-white/90 backdrop-blur">
        <div class="mx-auto flex h-16 max-w-6xl items-center justify-between px-4 sm:px-6">
            <a href="/WebsiteDatVe/index.php" class="flex items-center gap-2">
                <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-sky-600 text-white"><i class="fa-solid fa-plane"></i></span>
                <span class="text-lg font-extrabold text-slate-900">Booking Online</span>
            </a>
            <nav class="flex items-center gap-4 text-sm font-semibold">
                <a href="/WebsiteDatVe/manage-booking/manage-booking.php" class="hidden text-slate-600 hover:text-sky-600 sm:inline">Quản lý vé</a>
                <a href="/WebsiteDatVe/login/logout.php" class="rounded-xl px-3 py-2 text-rose-600 hover:bg-rose-50">Đăng xuất</a>
            </nav>
        </div>
    </header>

    <main class="mx-auto max-w-6xl px-4 py-8 sm:px-6 sm:py-12">
        <div class="mb-8">
            <p class="text-sm font-bold uppercase tracking-[0.16em] text-sky-600">Khu vực hội viên</p>
            <h1 class="mt-2 text-3xl font-black tracking-tight text-slate-900 sm:text-4xl">Tài khoản của bạn</h1>
            <p class="mt-2 text-sm text-slate-500">Theo dõi thông tin cá nhân, hành trình bay và quyền lợi tích lũy.</p>
        </div>

        <?php if ($accountError !== ''): ?>
            <div role="alert" class="mb-6 rounded-2xl border border-rose-200 bg-rose-50 p-4 text-sm font-semibold text-rose-700">
                <i class="fa-solid fa-circle-exclamation mr-2"></i><?= htmlspecialchars($accountError, ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>

        <?php if ($user): ?>
            <section class="grid gap-6 lg:grid-cols-[1.4fr_1fr]">
                <div class="overflow-hidden rounded-3xl bg-gradient-to-br from-sky-700 via-blue-700 to-indigo-800 p-6 text-white shadow-xl shadow-blue-900/10 sm:p-8">
                    <div class="flex flex-wrap items-start justify-between gap-4">
                        <div class="flex items-center gap-4">
                            <div class="flex h-14 w-14 items-center justify-center rounded-2xl bg-white/15 text-2xl">
                                <i class="fa-solid fa-user"></i>
                            </div>
                            <div>
                                <p class="text-sm font-medium text-blue-100">Xin chào,</p>
                                <h2 class="text-xl font-extrabold sm:text-2xl"><?= htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8') ?></h2>
                            </div>
                        </div>
                        <span class="rounded-full border border-white/25 bg-white/15 px-3 py-1.5 text-xs font-bold uppercase tracking-wide">
                            Hội viên <?= htmlspecialchars($currentTier['name'], ENT_QUOTES, 'UTF-8') ?>
                        </span>
                    </div>
                    <div class="mt-8 grid gap-4 border-t border-white/15 pt-5 sm:grid-cols-2">
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-wider text-blue-200">Email</p>
                            <p class="mt-1 break-all text-sm font-semibold"><?= htmlspecialchars($user['email'], ENT_QUOTES, 'UTF-8') ?></p>
                        </div>
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-wider text-blue-200">Số điện thoại</p>
                            <p class="mt-1 text-sm font-semibold"><?= htmlspecialchars($user['phone'] ?: 'Chưa cập nhật', ENT_QUOTES, 'UTF-8') ?></p>
                        </div>
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-wider text-blue-200">Ngày tham gia</p>
                            <p class="mt-1 text-sm font-semibold"><?= htmlspecialchars(date('d/m/Y', strtotime($user['created_at'])), ENT_QUOTES, 'UTF-8') ?></p>
                        </div>
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-wider text-blue-200">Tổng đơn đặt vé</p>
                            <p class="mt-1 text-sm font-semibold"><?= number_format((int)$stats['booking_count']) ?> đơn</p>
                        </div>
                    </div>
                </div>

                <div class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8">
                    <div class="flex items-center justify-between gap-4">
                        <div>
                            <p class="text-sm font-semibold text-slate-500">Điểm tích lũy</p>
                            <p class="mt-1 text-4xl font-black text-slate-900"><?= number_format($points) ?></p>
                        </div>
                        <span class="flex h-14 w-14 items-center justify-center rounded-2xl bg-amber-50 text-2xl text-amber-500">
                            <i class="fa-solid fa-star"></i>
                        </span>
                    </div>
                    <p class="mt-2 text-sm text-slate-500">1 điểm cho mỗi 10.000đ từ đơn đã thanh toán.</p>
                    <div class="mt-7">
                        <div class="mb-2 flex items-center justify-between gap-3 text-xs font-bold">
                            <span class="text-slate-700">Hạng <?= htmlspecialchars($currentTier['name'], ENT_QUOTES, 'UTF-8') ?></span>
                            <?php if ($nextTier): ?>
                                <span class="text-slate-500"><?= number_format($pointsToNext) ?> điểm đến hạng <?= htmlspecialchars($nextTier['name'], ENT_QUOTES, 'UTF-8') ?></span>
                            <?php else: ?>
                                <span class="text-sky-700">Bạn đang ở hạng cao nhất</span>
                            <?php endif; ?>
                        </div>
                        <div class="h-3 overflow-hidden rounded-full bg-slate-100">
                            <div class="h-full rounded-full bg-gradient-to-r from-sky-500 to-indigo-600 transition-all" style="width: <?= $progress ?>%"></div>
                        </div>
                        <?php if ($nextTier): ?>
                            <div class="mt-2 flex justify-between text-[11px] font-medium text-slate-400">
                                <span><?= number_format($currentTier['min_points']) ?> điểm</span>
                                <span><?= number_format($nextTier['min_points']) ?> điểm</span>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </section>

            <section class="mt-8">
                <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <p class="text-sm font-bold uppercase tracking-[0.14em] text-sky-600">Chương trình tích lũy</p>
                        <h2 class="mt-1 text-2xl font-extrabold text-slate-900">Hạng thành viên & đặc quyền</h2>
                    </div>
                    <p class="text-xs text-slate-500">Điểm được tính từ tổng chi tiêu của đơn đã thanh toán.</p>
                </div>
                <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <?php foreach ($tiers as $index => $tier): ?>
                        <?php $isCurrentTier = $index === $tierIndex; ?>
                        <article class="rounded-2xl border p-5 <?= $isCurrentTier ? 'border-sky-300 bg-sky-50 ring-2 ring-sky-100' : 'border-slate-200 bg-white' ?>">
                            <div class="flex items-center justify-between">
                                <span class="flex h-11 w-11 items-center justify-center rounded-xl <?= $isCurrentTier ? 'bg-sky-600 text-white' : 'bg-slate-100 text-slate-500' ?>">
                                    <i class="fa-solid <?= htmlspecialchars($tier['icon'], ENT_QUOTES, 'UTF-8') ?>"></i>
                                </span>
                                <?php if ($isCurrentTier): ?>
                                    <span class="rounded-full bg-sky-100 px-2.5 py-1 text-[10px] font-extrabold uppercase tracking-wide text-sky-700">Hạng hiện tại</span>
                                <?php endif; ?>
                            </div>
                            <h3 class="mt-4 text-lg font-extrabold text-slate-900"><?= htmlspecialchars($tier['name'], ENT_QUOTES, 'UTF-8') ?></h3>
                            <p class="mt-1 text-xs font-semibold text-slate-500">Từ <?= number_format($tier['min_points']) ?> điểm</p>
                            <ul class="mt-4 space-y-2 border-t border-slate-200 pt-4">
                                <?php foreach ($tier['perks'] as $perk): ?>
                                    <li class="flex gap-2 text-xs leading-relaxed text-slate-600">
                                        <i class="fa-solid fa-check mt-0.5 text-sky-600"></i>
                                        <span><?= htmlspecialchars($perk, ENT_QUOTES, 'UTF-8') ?></span>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </article>
                    <?php endforeach; ?>
                </div>
            </section>

            <section class="mt-10">
                <div class="mb-4 flex items-end justify-between gap-4">
                    <div>
                        <p class="text-sm font-bold uppercase tracking-[0.14em] text-sky-600">Hành trình của bạn</p>
                        <h2 class="mt-1 text-2xl font-extrabold text-slate-900">Lịch sử đặt vé</h2>
                    </div>
                    <a href="/WebsiteDatVe/manage-booking/manage-booking.php" class="text-sm font-bold text-sky-700 hover:text-sky-900">Quản lý vé <i class="fa-solid fa-arrow-right ml-1"></i></a>
                </div>
                <?php if (!$bookings): ?>
                    <div class="rounded-3xl border border-dashed border-slate-300 bg-white px-6 py-12 text-center">
                        <span class="mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-sky-50 text-xl text-sky-600"><i class="fa-solid fa-ticket"></i></span>
                        <h3 class="mt-4 font-extrabold text-slate-900">Chưa có chuyến bay nào</h3>
                        <p class="mt-1 text-sm text-slate-500">Đặt chuyến bay đầu tiên để bắt đầu tích lũy điểm hội viên.</p>
                        <a href="/WebsiteDatVe/flights/flights.php" class="mt-5 inline-flex rounded-xl bg-sky-600 px-5 py-3 text-sm font-bold text-white hover:bg-sky-700">Tìm chuyến bay</a>
                    </div>
                <?php else: ?>
                    <div class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">
                        <div class="divide-y divide-slate-100">
                            <?php foreach ($bookings as $booking): ?>
                                <?php $statusClass = $statusStyles[$booking['payment_status']] ?? 'bg-slate-100 text-slate-600'; ?>
                                <article class="flex flex-col gap-4 p-5 sm:flex-row sm:items-center sm:justify-between sm:px-6">
                                    <div class="min-w-0">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <span class="font-extrabold text-slate-900"><?= htmlspecialchars($booking['booking_code'], ENT_QUOTES, 'UTF-8') ?></span>
                                            <span class="rounded-full px-2.5 py-1 text-[10px] font-bold <?= $statusClass ?>"><?= htmlspecialchars(paymentStatusLabel($booking['payment_status']), ENT_QUOTES, 'UTF-8') ?></span>
                                        </div>
                                        <p class="mt-1 text-sm font-semibold text-slate-700">
                                            <?= htmlspecialchars($booking['from_city'], ENT_QUOTES, 'UTF-8') ?>
                                            <i class="fa-solid fa-arrow-right mx-1 text-xs text-slate-400"></i>
                                            <?= htmlspecialchars($booking['to_city'], ENT_QUOTES, 'UTF-8') ?>
                                        </p>
                                        <p class="mt-1 text-xs text-slate-500">
                                            <?= htmlspecialchars($booking['flight_number'], ENT_QUOTES, 'UTF-8') ?>
                                            <span class="mx-1">·</span>
                                            Khởi hành <?= htmlspecialchars(date('d/m/Y H:i', strtotime($booking['depart_at'])), ENT_QUOTES, 'UTF-8') ?>
                                        </p>
                                    </div>
                                    <div class="flex items-center justify-between gap-4 sm:justify-end">
                                        <div class="text-left sm:text-right">
                                            <p class="text-xs text-slate-500">Tổng thanh toán</p>
                                            <p class="mt-0.5 font-extrabold text-slate-900"><?= number_format((int)$booking['total_price']) ?>đ</p>
                                        </div>
                                        <?php if ($booking['payment_status'] === 'paid' && $booking['booking_status'] === 'confirmed'): ?>
                                            <span class="text-xs font-bold text-emerald-700">+<?= number_format((int)floor((int)$booking['total_price'] / 10000)) ?> điểm</span>
                                        <?php endif; ?>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </section>
        <?php endif; ?>
    </main>

    <footer class="border-t border-slate-200 bg-white py-5 text-center text-xs text-slate-500">
        © <?= date('Y') ?> Booking Online · Cảm ơn bạn đã đồng hành cùng chúng tôi.
    </footer>
</body>
</html>
