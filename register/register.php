<?php
session_start();
require_once __DIR__ . '/../api/config/database.php';

$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fullname = trim($_POST['fullname'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = trim($_POST['password'] ?? '');
    $phone = trim($_POST['phone'] ?? '');

    if (empty($fullname) || empty($email) || empty($password)) {
        $message = 'Vui lòng nhập đầy đủ họ tên, email và mật khẩu!';
        $messageType = 'error';
    } else {
        if ($conn) {
            // Kiểm tra email trùng
            $chk = mysqli_prepare($conn, "SELECT id FROM users WHERE email = ? LIMIT 1");
            mysqli_stmt_bind_param($chk, "s", $email);
            mysqli_stmt_execute($chk);
            mysqli_stmt_store_result($chk);
            if (mysqli_stmt_num_rows($chk) > 0) {
                $message = 'Email này đã được sử dụng! Vui lòng chọn email khác.';
                $messageType = 'error';
                mysqli_stmt_close($chk);
            } else {
                mysqli_stmt_close($chk);
                // Mã hóa mật khẩu bcrypt
                $hashedPassword = password_hash($password, PASSWORD_BCRYPT);
                $stmt = mysqli_prepare($conn, "INSERT INTO users (fullname, email, password, phone, role) VALUES (?, ?, ?, ?, 'Khach_Hang')");
                mysqli_stmt_bind_param($stmt, "ssss", $fullname, $email, $hashedPassword, $phone);
                if (mysqli_stmt_execute($stmt)) {
                    $newId = mysqli_insert_id($conn);
                    $_SESSION['user'] = [
                        'id' => $newId,
                        'email' => $email,
                        'fullname' => $fullname,
                        'phone' => $phone,
                        'role' => 'customer'
                    ];
                    mysqli_stmt_close($stmt);
                    header('Location: /WebsiteDatVe/index.php');
                    exit;
                } else {
                    $message = 'Đăng ký thất bại, vui lòng thử lại!';
                    $messageType = 'error';
                }
            }
        } else {
            $message = 'Lỗi kết nối cơ sở dữ liệu!';
            $messageType = 'error';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Đăng ký tài khoản - Booking Online</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>body { font-family: 'Plus Jakarta Sans', sans-serif; }</style>
</head>
<body class="bg-slate-50 min-h-screen flex flex-col">

    <header class="bg-white/80 backdrop-blur border-b border-slate-200">
        <div class="max-w-6xl mx-auto px-4 h-16 flex items-center justify-between">
            <a href="/WebsiteDatVe/index.php" class="flex items-center gap-2">
                <span class="w-9 h-9 rounded-xl bg-sky-600 text-white flex items-center justify-center">
                    <i class="fa-solid fa-plane"></i>
                </span>
                <span class="text-lg font-bold text-slate-900">Booking Online</span>
            </a>
            <a href="/WebsiteDatVe/index.php" class="text-sm font-semibold text-slate-600 hover:text-sky-600">
                <i class="fa-solid fa-arrow-left mr-1"></i> Về trang chủ
            </a>
        </div>
    </header>

    <main class="flex-1 flex items-center justify-center p-4 py-12">
        <div class="w-full max-w-md bg-white rounded-3xl shadow-xl shadow-slate-200/50 border border-slate-100 p-8">
            <div class="text-center mb-8">
                <div class="inline-flex items-center justify-center w-12 h-12 rounded-2xl bg-sky-600 text-white shadow-lg shadow-sky-500/30 mb-4">
                    <i class="fa-solid fa-plane text-xl"></i>
                </div>
                <h1 class="text-2xl font-black text-slate-900 mb-2">Tạo tài khoản mới</h1>
                <p class="text-sm text-slate-500">Gia nhập Booking Online để nhận ưu đãi thành viên</p>
            </div>

            <?php if (!empty($message)): ?>
                <div class="mb-5 p-3 rounded-xl text-xs font-semibold <?= $messageType === 'error' ? 'bg-rose-50 text-rose-600 border border-rose-200' : 'bg-emerald-50 text-emerald-600 border border-emerald-200' ?>">
                    <?= htmlspecialchars($message) ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="" class="space-y-4" id="registerForm">
                <div>
                    <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1.5 ml-1">Họ và tên</label>
                    <div class="relative">
                        <i class="fa-regular fa-user absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400"></i>
                        <input type="text" name="fullname" id="fullname" required placeholder="Nguyễn Văn A" class="w-full pl-10 pr-4 py-3 rounded-xl border border-slate-200 bg-slate-50 text-sm font-medium focus:bg-white focus:outline-none focus:border-sky-600 focus:ring-2 focus:ring-sky-100 transition">
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1.5 ml-1">Email</label>
                    <div class="relative">
                        <i class="fa-regular fa-envelope absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400"></i>
                        <input type="email" name="email" id="email" required placeholder="nhapemail@example.com" class="w-full pl-10 pr-4 py-3 rounded-xl border border-slate-200 bg-slate-50 text-sm font-medium focus:bg-white focus:outline-none focus:border-sky-600 focus:ring-2 focus:ring-sky-100 transition">
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1.5 ml-1">Số điện thoại</label>
                    <div class="relative">
                        <i class="fa-solid fa-phone absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400"></i>
                        <input type="tel" name="phone" id="phone" placeholder="0912345678" class="w-full pl-10 pr-4 py-3 rounded-xl border border-slate-200 bg-slate-50 text-sm font-medium focus:bg-white focus:outline-none focus:border-sky-600 focus:ring-2 focus:ring-sky-100 transition">
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1.5 ml-1">Mật khẩu</label>
                    <div class="relative">
                        <i class="fa-solid fa-lock absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400"></i>
                        <input type="password" name="password" id="password" required placeholder="Tối thiểu 6 ký tự" class="w-full pl-10 pr-4 py-3 rounded-xl border border-slate-200 bg-slate-50 text-sm font-medium focus:bg-white focus:outline-none focus:border-sky-600 focus:ring-2 focus:ring-sky-100 transition">
                    </div>
                </div>

                <button type="submit" class="w-full py-3.5 rounded-xl text-sm font-bold text-white bg-sky-600 hover:bg-sky-700 shadow-lg shadow-sky-500/25 transition mt-2">
                    Tạo tài khoản <i class="fa-solid fa-arrow-right ml-1"></i>
                </button>
            </form>

            <p class="text-center text-xs text-slate-500 mt-6">
                Đã có tài khoản? <a href="/WebsiteDatVe/login/login.php" class="text-sky-600 font-bold hover:underline">Đăng nhập</a>
            </p>
        </div>
    </main>

    <!-- VALIDATION JAVASCRIPT CHO ĐỒ ÁN JS -->
    <script>
        document.getElementById('registerForm').addEventListener('submit', function(e) {
            const fullname = document.getElementById('fullname').value.trim();
            const email = document.getElementById('email').value.trim();
            const password = document.getElementById('password').value;
            if (!fullname || !email || !password) {
                alert('Vui lòng điền đầy đủ họ tên, email và mật khẩu!');
                e.preventDefault();
            } else if (password.length < 6) {
                alert('Mật khẩu phải có tối thiểu 6 ký tự!');
                e.preventDefault();
            }
        });
    </script>
</body>
</html>
