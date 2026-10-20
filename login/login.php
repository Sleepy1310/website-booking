<?php
session_start();
require_once __DIR__ . '/../api/config/database.php';
require_once __DIR__ . '/../api/config/authorization.php';

$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if (empty($email) || empty($password)) {
        $message = 'Vui lòng nhập đầy đủ email và mật khẩu!';
        $messageType = 'error';
    } else {
        if ($conn) {
            $stmt = mysqli_prepare($conn, "SELECT * FROM users WHERE email = ? LIMIT 1");
            mysqli_stmt_bind_param($stmt, "s", $email);
            mysqli_stmt_execute($stmt);
            $res = mysqli_stmt_get_result($stmt);
            $user = mysqli_fetch_assoc($res);
            mysqli_stmt_close($stmt);

            if ($user && ($user['status'] ?? 'Hoat_Dong') === 'Hoat_Dong' &&
                (password_verify($password, $user['password']) || $password === $user['password'])) {
                $role = normalize_role($user['role']);
                $_SESSION['user'] = [
                    'id' => $user['id'],
                    'email' => $user['email'],
                    'fullname' => $user['fullname'],
                    'phone' => $user['phone'],
                    'role' => $role
                ];
                if (in_array($role, ['super_admin', 'flight_manager', 'support', 'accountant'], true)) {
                    header('Location: /WebsiteDatVe/admin/admin.php');
                } else {
                    header('Location: /WebsiteDatVe/index.php');
                }
                exit;
            } else {
                $message = $user && ($user['status'] ?? 'Hoat_Dong') !== 'Hoat_Dong'
                    ? 'Tài khoản đã bị khóa. Vui lòng liên hệ hỗ trợ!'
                    : 'Email hoặc mật khẩu không chính xác!';
                $messageType = 'error';
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
    <title>Đăng nhập - Booking Online</title>
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
                <h1 class="text-2xl font-black text-slate-900 mb-2">Chào mừng trở lại!</h1>
                <p class="text-sm text-slate-500">Đăng nhập để tiếp tục khám phá cùng Booking Online</p>
            </div>

            <?php if (!empty($message)): ?>
                <div class="mb-5 p-3 rounded-xl text-xs font-semibold <?= $messageType === 'error' ? 'bg-rose-50 text-rose-600 border border-rose-200' : 'bg-emerald-50 text-emerald-600 border border-emerald-200' ?>">
                    <?= htmlspecialchars($message) ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="login.php" class="space-y-4" id="loginForm">
                <div>
                    <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1.5 ml-1">Email</label>
                    <div class="relative">
                        <i class="fa-regular fa-envelope absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400"></i>
                        <input type="email" name="email" id="email" required placeholder="nhapemail@example.com" class="w-full pl-10 pr-4 py-3 rounded-xl border border-slate-200 bg-slate-50 text-sm font-medium focus:bg-white focus:outline-none focus:border-sky-600 focus:ring-2 focus:ring-sky-100 transition">
                    </div>
                </div>

                <div>
                    <div class="flex items-center justify-between mb-1.5 ml-1">
                        <label class="text-xs font-bold text-slate-700 uppercase tracking-wider">Mật khẩu</label>
                        <a href="#" class="text-xs font-bold text-sky-600 hover:underline">Quên mật khẩu?</a>
                    </div>
                    <div class="relative">
                        <i class="fa-solid fa-lock absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400"></i>
                        <input type="password" name="password" id="password" required placeholder="••••••••" class="w-full pl-10 pr-4 py-3 rounded-xl border border-slate-200 bg-slate-50 text-sm font-medium focus:bg-white focus:outline-none focus:border-sky-600 focus:ring-2 focus:ring-sky-100 transition">
                    </div>
                </div>

                <div class="flex items-center gap-2 pt-1 ml-1">
                    <input type="checkbox" id="remember" class="w-4 h-4 rounded text-sky-600 focus:ring-sky-500">
                    <label for="remember" class="text-xs font-medium text-slate-600 cursor-pointer">Ghi nhớ đăng nhập</label>
                </div>

                <button type="submit" class="w-full py-3.5 rounded-xl text-sm font-bold text-white bg-sky-600 hover:bg-sky-700 shadow-lg shadow-sky-500/25 transition mt-2">
                    Đăng nhập <i class="fa-solid fa-arrow-right ml-1"></i>
                </button>
            </form>

            <p class="text-center text-xs text-slate-500 mt-6">
                Chưa có tài khoản? <a href="register.php" class="text-sky-600 font-bold hover:underline">Đăng ký ngay</a>
            </p>
        </div>
    </main>

    <!-- VALIDATION JAVASCRIPT CHO ĐỒ ÁN JS -->
    <script>
        document.getElementById('loginForm').addEventListener('submit', function(e) {
            const email = document.getElementById('email').value.trim();
            const password = document.getElementById('password').value;
            if (!email || !password) {
                alert('Vui lòng điền đầy đủ email và mật khẩu!');
                e.preventDefault();
            } else if (password.length < 6) {
                alert('Mật khẩu phải có tối thiểu 6 ký tự!');
                e.preventDefault();
            }
        });
    </script>
</body>
</html>
