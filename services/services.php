<?php
session_start();
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dịch vụ & Tiện ích - Booking Online</title>
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
                <a href="/WebsiteDatVe/services/services.php" class="text-primary font-bold border-b-2 border-primary pb-1">Dịch vụ</a>
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
        <div class="relative mx-auto grid max-w-6xl items-center gap-10 px-4 py-16 sm:px-6 md:grid-cols-[1.2fr_.8fr] md:py-20">
            <div>
                <span class="inline-flex items-center gap-2 rounded-full border border-white/15 bg-white/10 px-3 py-1.5 text-xs font-bold text-sky-100">
                    <i class="fa-solid fa-sparkles text-sky-300"></i>
                    Hành trình thoải mái hơn
                </span>
                <h1 class="mt-5 max-w-2xl text-3xl font-black leading-tight tracking-tight sm:text-5xl">
                    Thêm tiện ích,<br><span class="text-sky-300">thêm an tâm</span> cho chuyến đi
                </h1>
                <p class="mt-5 max-w-xl text-sm leading-7 text-slate-300 sm:text-base">
                    Chủ động chuẩn bị hành lý, chọn suất ăn và chỗ ngồi phù hợp. Bạn có thể chọn các dịch vụ hiện có trong bước nhập thông tin khi đặt vé.
                </p>
                <div class="mt-8 flex flex-col gap-3 sm:flex-row">
                    <a href="/WebsiteDatVe/index.php#searchFlightForm" class="inline-flex items-center justify-center gap-2 rounded-2xl bg-sky-500 px-6 py-3.5 text-sm font-extrabold text-white shadow-lg shadow-sky-950/40 transition hover:bg-sky-400">
                        Tìm chuyến bay <i class="fa-solid fa-arrow-right"></i>
                    </a>
                    <a href="#danh-sach-dich-vu" class="inline-flex items-center justify-center gap-2 rounded-2xl border border-white/20 bg-white/5 px-6 py-3.5 text-sm font-bold text-white transition hover:bg-white/10">
                        Khám phá dịch vụ
                    </a>
                </div>
            </div>
            <div class="grid grid-cols-2 gap-3 sm:gap-4">
                <div class="rounded-3xl border border-white/10 bg-white/[0.07] p-5 backdrop-blur">
                    <span class="flex h-11 w-11 items-center justify-center rounded-2xl bg-sky-400/15 text-xl text-sky-300"><i class="fa-solid fa-suitcase-rolling"></i></span>
                    <p class="mt-5 text-2xl font-black">+15kg</p>
                    <p class="mt-1 text-xs font-medium text-slate-300">Gói hành lý bổ sung</p>
                </div>
                <div class="mt-8 rounded-3xl border border-white/10 bg-white/[0.07] p-5 backdrop-blur">
                    <span class="flex h-11 w-11 items-center justify-center rounded-2xl bg-amber-400/15 text-xl text-amber-300"><i class="fa-solid fa-utensils"></i></span>
                    <p class="mt-5 text-2xl font-black">Từ 50k</p>
                    <p class="mt-1 text-xs font-medium text-slate-300">Suất ăn chọn trước</p>
                </div>
                <div class="rounded-3xl border border-white/10 bg-white/[0.07] p-5 backdrop-blur">
                    <span class="flex h-11 w-11 items-center justify-center rounded-2xl bg-violet-400/15 text-xl text-violet-300"><i class="fa-solid fa-chair"></i></span>
                    <p class="mt-5 text-2xl font-black">Theo sơ đồ</p>
                    <p class="mt-1 text-xs font-medium text-slate-300">Chọn ghế còn trống</p>
                </div>
                <div class="mt-8 rounded-3xl border border-white/10 bg-white/[0.07] p-5 backdrop-blur">
                    <span class="flex h-11 w-11 items-center justify-center rounded-2xl bg-emerald-400/15 text-xl text-emerald-300"><i class="fa-solid fa-shield-heart"></i></span>
                    <p class="mt-5 text-2xl font-black">89k</p>
                    <p class="mt-1 text-xs font-medium text-slate-300">Bảo hiểm tùy chọn</p>
                </div>
            </div>
        </div>
    </section>

    <section class="mx-auto max-w-6xl px-4 py-12 sm:px-6 sm:py-16" id="danh-sach-dich-vu">
        <div class="mb-8 flex flex-col justify-between gap-4 sm:flex-row sm:items-end">
            <div>
                <p class="text-xs font-extrabold uppercase tracking-[.18em] text-sky-700">Dịch vụ trên chuyến bay</p>
                <h2 class="mt-2 text-2xl font-black tracking-tight text-slate-900 sm:text-3xl">Chọn tiện ích theo nhu cầu</h2>
                <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-500">Các lựa chọn dưới đây cũng xuất hiện trong bước nhập thông tin hành khách. Giá hiển thị là giá tham khảo theo gói.</p>
            </div>
            <a href="/WebsiteDatVe/index.php#searchFlightForm" class="inline-flex w-fit items-center gap-2 text-sm font-extrabold text-sky-700 hover:text-sky-900">
                Đặt vé để chọn dịch vụ <i class="fa-solid fa-arrow-right"></i>
            </a>
        </div>

        <div class="grid gap-5 md:grid-cols-2">
            <article class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm transition hover:-translate-y-1 hover:shadow-lg">
                <div class="flex items-start justify-between gap-4 border-b border-slate-100 bg-gradient-to-r from-sky-50 to-white p-6">
                    <div class="flex items-center gap-4">
                        <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl bg-sky-100 text-xl text-sky-700"><i class="fa-solid fa-suitcase-rolling"></i></span>
                        <div><h3 class="text-lg font-extrabold text-slate-900">Hành lý ký gửi</h3><p class="mt-1 text-xs font-medium text-slate-500">Chuẩn bị thêm chỗ cho hành trang</p></div>
                    </div>
                    <span class="rounded-full bg-sky-100 px-3 py-1 text-[10px] font-extrabold uppercase tracking-wide text-sky-800">Linh hoạt</span>
                </div>
                <div class="p-6">
                    <p class="text-sm leading-6 text-slate-600">Chọn gói hành lý bổ sung phù hợp với chuyến đi. Trong bước đặt vé hiện có các gói 15kg, 20kg và 30kg; lựa chọn không mua thêm cũng luôn có sẵn.</p>
                    <div class="mt-5 grid grid-cols-2 gap-2 sm:grid-cols-4">
                        <div class="rounded-2xl bg-slate-50 p-3"><p class="text-xs font-bold text-slate-700">Không thêm</p><p class="mt-1 text-sm font-black text-emerald-700">Miễn phí</p></div>
                        <div class="rounded-2xl bg-slate-50 p-3"><p class="text-xs font-bold text-slate-700">+15kg</p><p class="mt-1 text-sm font-black text-slate-900">220.000đ</p></div>
                        <div class="rounded-2xl bg-slate-50 p-3"><p class="text-xs font-bold text-slate-700">+20kg</p><p class="mt-1 text-sm font-black text-slate-900">290.000đ</p></div>
                        <div class="rounded-2xl bg-slate-50 p-3"><p class="text-xs font-bold text-slate-700">+30kg</p><p class="mt-1 text-sm font-black text-slate-900">450.000đ</p></div>
                    </div>
                </div>
            </article>

            <article class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm transition hover:-translate-y-1 hover:shadow-lg">
                <div class="flex items-start justify-between gap-4 border-b border-slate-100 bg-gradient-to-r from-amber-50 to-white p-6">
                    <div class="flex items-center gap-4">
                        <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl bg-amber-100 text-xl text-amber-700"><i class="fa-solid fa-utensils"></i></span>
                        <div><h3 class="text-lg font-extrabold text-slate-900">Suất ăn đặt trước</h3><p class="mt-1 text-xs font-medium text-slate-500">Chọn món phù hợp với khẩu vị</p></div>
                    </div>
                    <span class="rounded-full bg-amber-100 px-3 py-1 text-[10px] font-extrabold uppercase tracking-wide text-amber-800">Từ 50.000đ</span>
                </div>
                <div class="p-6">
                    <p class="text-sm leading-6 text-slate-600">Đặt trước một phần ăn nhẹ cho hành trình. Thực đơn hiện có các lựa chọn tiện lợi; nếu không chọn món, mặc định là không đặt trước.</p>
                    <div class="mt-5 space-y-2">
                        <div class="flex items-center justify-between gap-3 rounded-2xl bg-slate-50 px-4 py-3"><span class="text-sm font-bold text-slate-700">Bánh mì chả lụa & cà phê sữa</span><span class="shrink-0 text-sm font-black text-slate-900">50.000đ</span></div>
                        <div class="flex items-center justify-between gap-3 rounded-2xl bg-slate-50 px-4 py-3"><span class="text-sm font-bold text-slate-700">Cơm gà Hội An & nước ép</span><span class="shrink-0 text-sm font-black text-slate-900">65.000đ</span></div>
                    </div>
                </div>
            </article>

            <article class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm transition hover:-translate-y-1 hover:shadow-lg">
                <div class="flex items-start justify-between gap-4 border-b border-slate-100 bg-gradient-to-r from-violet-50 to-white p-6">
                    <div class="flex items-center gap-4">
                        <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl bg-violet-100 text-xl text-violet-700"><i class="fa-solid fa-chair"></i></span>
                        <div><h3 class="text-lg font-extrabold text-slate-900">Chọn chỗ ngồi</h3><p class="mt-1 text-xs font-medium text-slate-500">Chủ động chọn ghế trên sơ đồ</p></div>
                    </div>
                    <span class="rounded-full bg-violet-100 px-3 py-1 text-[10px] font-extrabold uppercase tracking-wide text-violet-800">Theo tình trạng ghế</span>
                </div>
                <div class="p-6">
                    <p class="text-sm leading-6 text-slate-600">Xem sơ đồ ghế của đúng chuyến bay và hạng vé rồi chọn một ghế còn trống. Ghế được giữ cùng đơn đặt chỗ; nếu bỏ qua, bạn có thể chọn ghế khi làm thủ tục theo tình trạng chỗ còn lại.</p>
                    <div class="mt-5 flex flex-wrap gap-2 text-xs font-bold">
                        <span class="rounded-full border border-slate-200 bg-slate-50 px-3 py-2 text-slate-600"><i class="fa-solid fa-circle-check mr-1 text-emerald-600"></i>Ghế trống có thể chọn</span>
                        <span class="rounded-full border border-slate-200 bg-slate-50 px-3 py-2 text-slate-600"><i class="fa-solid fa-circle-xmark mr-1 text-slate-400"></i>Ghế đã đặt không khả dụng</span>
                    </div>
                </div>
            </article>

            <article class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm transition hover:-translate-y-1 hover:shadow-lg">
                <div class="flex items-start justify-between gap-4 border-b border-slate-100 bg-gradient-to-r from-emerald-50 to-white p-6">
                    <div class="flex items-center gap-4">
                        <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl bg-emerald-100 text-xl text-emerald-700"><i class="fa-solid fa-shield-heart"></i></span>
                        <div><h3 class="text-lg font-extrabold text-slate-900">Bảo hiểm du lịch</h3><p class="mt-1 text-xs font-medium text-slate-500">Tùy chọn thêm khi đặt vé</p></div>
                    </div>
                    <span class="rounded-full bg-emerald-100 px-3 py-1 text-[10px] font-extrabold uppercase tracking-wide text-emerald-800">89.000đ</span>
                </div>
                <div class="p-6">
                    <p class="text-sm leading-6 text-slate-600">Có thể chọn hoặc bỏ chọn bảo hiểm trong bước dịch vụ. Quyền lợi và điều kiện áp dụng cần được xem theo thông tin gói bảo hiểm trước khi xác nhận đặt vé.</p>
                    <div class="mt-5 rounded-2xl border border-emerald-100 bg-emerald-50/70 p-4 text-xs leading-5 text-emerald-900">
                        <i class="fa-solid fa-circle-info mr-1"></i> Bảo hiểm là dịch vụ tùy chọn, không bắt buộc để hoàn tất đặt vé.
                    </div>
                </div>
            </article>
        </div>
    </section>

    <section class="border-y border-slate-200 bg-white">
        <div class="mx-auto grid max-w-6xl gap-10 px-4 py-12 sm:px-6 md:grid-cols-[.8fr_1.2fr] md:py-14">
            <div>
                <p class="text-xs font-extrabold uppercase tracking-[.18em] text-sky-700">Cách sử dụng</p>
                <h2 class="mt-2 text-2xl font-black text-slate-900">Thêm dịch vụ trong vài bước</h2>
                <p class="mt-3 text-sm leading-6 text-slate-500">Các dịch vụ hiện được chọn trong quá trình đặt vé để thông tin và chi phí được thể hiện cùng đơn hàng.</p>
            </div>
            <ol class="grid gap-3 sm:grid-cols-3">
                <li class="rounded-2xl border border-slate-200 bg-slate-50 p-5">
                    <span class="flex h-8 w-8 items-center justify-center rounded-xl bg-sky-600 text-sm font-black text-white">1</span>
                    <h3 class="mt-4 text-sm font-extrabold text-slate-900">Chọn chuyến bay</h3>
                    <p class="mt-1 text-xs leading-5 text-slate-500">Tìm hành trình và chọn hạng vé phù hợp.</p>
                </li>
                <li class="rounded-2xl border border-slate-200 bg-slate-50 p-5">
                    <span class="flex h-8 w-8 items-center justify-center rounded-xl bg-sky-600 text-sm font-black text-white">2</span>
                    <h3 class="mt-4 text-sm font-extrabold text-slate-900">Chọn tiện ích</h3>
                    <p class="mt-1 text-xs leading-5 text-slate-500">Thêm hành lý, suất ăn, ghế hoặc bảo hiểm tùy nhu cầu.</p>
                </li>
                <li class="rounded-2xl border border-slate-200 bg-slate-50 p-5">
                    <span class="flex h-8 w-8 items-center justify-center rounded-xl bg-sky-600 text-sm font-black text-white">3</span>
                    <h3 class="mt-4 text-sm font-extrabold text-slate-900">Kiểm tra tổng tiền</h3>
                    <p class="mt-1 text-xs leading-5 text-slate-500">Xem lại các dịch vụ đã chọn trong phần tóm tắt trước khi thanh toán.</p>
                </li>
            </ol>
        </div>
    </section>

    <section class="mx-auto max-w-6xl px-4 py-12 sm:px-6 sm:py-14">
        <div class="rounded-3xl bg-sky-950 px-6 py-8 text-white sm:flex sm:items-center sm:justify-between sm:gap-8 sm:px-10">
            <div>
                <h2 class="text-xl font-black sm:text-2xl">Sẵn sàng cho chuyến đi của bạn?</h2>
                <p class="mt-2 text-sm text-sky-100/80">Tìm chuyến bay và chọn dịch vụ phù hợp ngay trong quy trình đặt vé.</p>
            </div>
            <a href="/WebsiteDatVe/index.php#searchFlightForm" class="mt-5 inline-flex shrink-0 items-center justify-center gap-2 rounded-2xl bg-white px-5 py-3 text-sm font-extrabold text-sky-950 transition hover:bg-sky-50 sm:mt-0">
                Bắt đầu đặt vé <i class="fa-solid fa-arrow-right"></i>
            </a>
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
                        <li><a href="/WebsiteDatVe/index.php#searchFlightForm" class="hover:text-primary">Tìm chuyến bay</a></li>
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
