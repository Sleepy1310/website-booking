<?php
session_start();
$msgSent = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $msgSent = true;
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Liên hệ hỗ trợ - Booking Online</title>
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
                <a href="/WebsiteDatVe/flight-status/flight-status.php" class="hover:text-primary transition">Tình trạng bay</a>
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

<main class="max-w-3xl mx-auto px-4 sm:px-6 py-12 flex-1 w-full">
    <div class="text-center mb-8">
        <h1 class="text-2xl font-black text-slate-900">Liên hệ với chúng tôi</h1>
        <p class="text-sm text-slate-500">Đội ngũ hỗ trợ luôn sẵn sàng lắng nghe và giải đáp mọi yêu cầu của bạn</p>
    </div>

    <?php if ($msgSent): ?>
        <div class="mb-6 p-4 rounded-2xl bg-emerald-50 border border-emerald-200 text-emerald-700 text-sm font-bold text-center">
            Cảm ơn bạn! Tin nhắn của bạn đã được gửi thành công. Chúng tôi sẽ phản hồi sớm nhất.
        </div>
    <?php endif; ?>

    <div class="bg-white p-6 sm:p-8 rounded-3xl border border-slate-200 shadow-sm">
        <form method="POST" action="" class="space-y-4">
            <div>
                <label class="block text-xs font-bold text-slate-700 uppercase mb-1">Họ và tên *</label>
                <input type="text" name="name" required class="w-full px-4 py-3 rounded-xl border border-slate-200 bg-slate-50 text-sm focus:bg-white focus:border-primary focus:outline-none">
            </div>
            <div>
                <label class="block text-xs font-bold text-slate-700 uppercase mb-1">Email *</label>
                <input type="email" name="email" required class="w-full px-4 py-3 rounded-xl border border-slate-200 bg-slate-50 text-sm focus:bg-white focus:border-primary focus:outline-none">
            </div>
            <div>
                <label class="block text-xs font-bold text-slate-700 uppercase mb-1">Nội dung tin nhắn *</label>
                <textarea name="message" rows="4" required class="w-full px-4 py-3 rounded-xl border border-slate-200 bg-slate-50 text-sm focus:bg-white focus:border-primary focus:outline-none"></textarea>
            </div>
            <button type="submit" class="w-full py-3.5 bg-primary hover:bg-primary-hover text-white font-bold rounded-xl text-sm shadow transition">
                Gửi phản hồi
            </button>
        </form>
    </div>
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
