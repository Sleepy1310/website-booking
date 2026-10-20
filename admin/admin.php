<?php
session_start();
require_once __DIR__ . '/../api/config/database.php';
require_once __DIR__ . '/../api/config/authorization.php';
require_once __DIR__ . '/../api/config/aircraft_seats.php';

// Flash message helper
function setFlash($msg, $type = 'success') {
    $_SESSION['admin_msg'] = $msg;
    $_SESSION['admin_msg_type'] = $type;
}

if (empty($_SESSION['user']['id'])) {
    header('Location: /WebsiteDatVe/login/login.php');
    exit;
}
if (!$conn) {
    http_response_code(503);
    exit('Không thể kết nối cơ sở dữ liệu để xác thực quyền truy cập.');
}
try {
    $adminRole = current_role($conn);
} catch (RuntimeException $error) {
    error_log($error->getMessage());
    http_response_code(500);
    exit('Không thể xác thực vai trò tài khoản.');
}
if (!in_array($adminRole, ['super_admin', 'flight_manager', 'support', 'accountant'], true)) {
    http_response_code(403);
    exit('Tài khoản không có quyền truy cập khu vực quản trị.');
}

$csrfToken = $_SESSION['admin_csrf'] ??= bin2hex(random_bytes(32));
$canDashboard = has_permission($conn, 'dashboard_view');
$canFlights = has_permission($conn, 'flights_view');
$canBookings = has_permission($conn, 'bookings_view');
$canCustomers = has_permission($conn, 'customers_view');
$canManageRoles = has_permission($conn, 'user_role_manage');
$canGrantPermissions = has_permission($conn, 'permission_grant');
$canDeleteFlights = has_permission($conn, 'flight_delete');
$canDeleteBookings = has_permission($conn, 'booking_delete');
$canDeleteUsers = has_permission($conn, 'user_delete');
$canCreateFlights = has_permission($conn, 'flight_create');
$canEditFlightDetails = has_permission($conn, 'flight_details_edit');
$canEditFlightSchedule = has_permission($conn, 'flight_schedule_manage');
$canEditFlightStatus = has_permission($conn, 'flight_status_manage');
$canUpdatePaymentStatus = has_permission($conn, 'payment_status_manage');
$canApproveBookings = has_permission($conn, 'booking_approve');
$canViewRevenue = has_permission($conn, 'revenue_view');
$defaultTab = $canDashboard ? 'dashboard' : ($canFlights ? 'flights' : ($canBookings ? 'bookings' : 'users'));
$tab = $_GET['tab'] ?? $defaultTab;
$tabPermissions = [
    'dashboard' => 'dashboard_view',
    'flights' => 'flights_view',
    'bookings' => 'bookings_view',
    'users' => 'customers_view',
];
if (!isset($tabPermissions[$tab]) || !has_permission($conn, $tabPermissions[$tab])) {
    setFlash('Bạn không được cấp quyền truy cập mục này.', 'error');
    header('Location: /WebsiteDatVe/admin/admin.php?tab=' . $defaultTab);
    exit;
}

function requireAdminPermission(mysqli $conn, string $permission, string $tab): void
{
    if (!has_permission($conn, $permission)) {
        setFlash('Vai trò của bạn không được phép thực hiện thao tác này.', 'error');
        header('Location: /WebsiteDatVe/admin/admin.php?tab=' . urlencode($tab));
        exit;
    }
}

function verifyAdminCsrf(): void
{
    $submitted = (string)($_POST['csrf_token'] ?? '');
    if ($submitted === '' || !hash_equals((string)($_SESSION['admin_csrf'] ?? ''), $submitted)) {
        http_response_code(403);
        exit('Yêu cầu không hợp lệ. Vui lòng tải lại trang và thử lại.');
    }
}

// -------------------------------------------------------------
// XỬ LÝ ACTIONS (GET)
// -------------------------------------------------------------

// 1. Xóa chuyến bay
if (isset($_GET['action']) && $_GET['action'] === 'delete_flight' && !empty($_GET['id']) && $conn) {
    if (!hash_equals($csrfToken, (string)($_GET['csrf_token'] ?? ''))) {
        http_response_code(403);
        exit('Yêu cầu xóa chuyến bay không hợp lệ.');
    }
    requireAdminPermission($conn, 'flight_delete', 'flights');
    $delId = (int)$_GET['id'];
    if (mysqli_query($conn, "DELETE FROM flights WHERE id = {$delId}")) {
        setFlash("Đã xóa thành công chuyến bay #{$delId}!");
    } else {
        setFlash("Lỗi xóa chuyến bay: " . mysqli_error($conn), 'error');
    }
    header('Location: /WebsiteDatVe/admin/admin.php?tab=flights');
    exit;
}

// 2. Xóa đơn đặt vé
if (isset($_GET['action']) && $_GET['action'] === 'delete_booking' && !empty($_GET['id']) && $conn) {
    if (!hash_equals($csrfToken, (string)($_GET['csrf_token'] ?? ''))) {
        http_response_code(403);
        exit('Yêu cầu xóa đơn đặt vé không hợp lệ.');
    }
    requireAdminPermission($conn, 'booking_delete', 'bookings');
    $delId = (int)$_GET['id'];
    if (mysqli_query($conn, "DELETE FROM bookings WHERE id = {$delId}")) {
        setFlash("Đã xóa đơn đặt vé #{$delId}!");
    } else {
        setFlash("Lỗi xóa đơn đặt vé: " . mysqli_error($conn), 'error');
    }
    header('Location: /WebsiteDatVe/admin/admin.php?tab=bookings');
    exit;
}

// 3. Xóa người dùng
if (isset($_GET['action']) && $_GET['action'] === 'delete_user' && !empty($_GET['id']) && $conn) {
    if (!hash_equals($csrfToken, (string)($_GET['csrf_token'] ?? ''))) {
        http_response_code(403);
        exit('Yêu cầu xóa người dùng không hợp lệ.');
    }
    requireAdminPermission($conn, 'user_delete', 'users');
    $delId = (int)$_GET['id'];
    $currentUserId = $_SESSION['user']['id'] ?? 0;
    if ($delId === (int)$currentUserId) {
        setFlash("Bạn không thể tự xóa tài khoản của chính mình!", 'error');
    } else {
        if (mysqli_query($conn, "DELETE FROM users WHERE id = {$delId} AND role <> 'Quan_Tri_Vien'")) {
            setFlash("Đã xóa tài khoản người dùng #{$delId}!");
        } else {
            setFlash("Lỗi xóa người dùng: " . mysqli_error($conn), 'error');
        }
    }
    header('Location: /WebsiteDatVe/admin/admin.php?tab=users');
    exit;
}

// -------------------------------------------------------------
// XỬ LÝ FORMS (POST)
// -------------------------------------------------------------

// 1. Thêm chuyến bay mới
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_flight']) && $conn) {
    verifyAdminCsrf();
    requireAdminPermission($conn, 'flight_create', 'flights');
    $fn       = trim($_POST['flight_number'] ?? '');
    $airline  = trim($_POST['airline'] ?? '');
    $aircraft = trim($_POST['aircraft'] ?? 'Airbus A321');
    $fromAp   = trim($_POST['from_airport'] ?? 'SGN');
    $toAp     = trim($_POST['to_airport'] ?? 'HAN');
    $depAt    = trim($_POST['depart_at'] ?? '');
    $arrAt    = trim($_POST['arrive_at'] ?? '');
    $stops    = (int)($_POST['stops'] ?? 0);
    $priceEconomy = max(0, (int)($_POST['price_economy'] ?? 990000));
    $priceBusiness = max(0, (int)($_POST['price_business'] ?? 0));
    $tax      = (int)($_POST['tax_price'] ?? 420000);
    $seatPreset = aircraft_seat_row_preset($aircraft);
    $economyRows = $seatPreset['economy_rows'];
    $businessRows = $seatPreset['business_rows'];
    $gate     = trim($_POST['gate'] ?? 'Gate 04');
    $status   = trim($_POST['status'] ?? 'scheduled');

    if (!empty($fn) && !empty($airline) && !empty($depAt) && !empty($arrAt)) {
        $stmt = mysqli_prepare($conn, 
            "INSERT INTO flights (flight_number, airline, aircraft, from_airport, to_airport, depart_at, arrive_at, stops,
                                 price_economy, price_business, tax_price, seats_economy_left, seats_business_left, status, gate)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $economySeats = $economyRows * 6;
        $businessSeats = $businessRows * 6;
        mysqli_stmt_bind_param($stmt, "sssssssiiiiiiss", $fn, $airline, $aircraft, $fromAp, $toAp, $depAt, $arrAt, $stops, $priceEconomy, $priceBusiness, $tax, $economySeats, $businessSeats, $status, $gate);
        if (mysqli_stmt_execute($stmt)) {
            $newFlightId = mysqli_insert_id($conn);
            mysqli_stmt_close($stmt);
            $seatGenerator = mysqli_prepare($conn, 'CALL sp_generate_seats(?, ?, ?)');
            if (!$seatGenerator) {
                setFlash('Đã tạo chuyến bay nhưng không thể chuẩn bị tạo sơ đồ ghế: ' . mysqli_error($conn), 'error');
            } else {
                mysqli_stmt_bind_param($seatGenerator, 'iii', $newFlightId, $businessRows, $economyRows);
                if (mysqli_stmt_execute($seatGenerator)) {
                    mysqli_stmt_close($seatGenerator);
                    while (mysqli_more_results($conn) && mysqli_next_result($conn)) {
                        $result = mysqli_store_result($conn);
                        if ($result instanceof mysqli_result) mysqli_free_result($result);
                    }
                    setFlash("Thêm chuyến bay {$fn} và sơ đồ ghế thành công!");
                } else {
                    setFlash('Đã tạo chuyến bay nhưng không thể tạo sơ đồ ghế: ' . mysqli_stmt_error($seatGenerator), 'error');
                    mysqli_stmt_close($seatGenerator);
                }
            }
        } else {
            setFlash("Lỗi thêm chuyến bay: " . mysqli_error($conn), 'error');
            mysqli_stmt_close($stmt);
        }
    } else {
        setFlash("Vui lòng điền đầy đủ các trường bắt buộc!", 'error');
    }
    header('Location: /WebsiteDatVe/admin/admin.php?tab=flights');
    exit;
}

// 2. Cập nhật chuyến bay
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_flight']) && $conn) {
    verifyAdminCsrf();
    $fId      = (int)($_POST['flight_id'] ?? 0);
    $fn       = trim($_POST['flight_number'] ?? '');
    $airline  = trim($_POST['airline'] ?? '');
    $aircraft = trim($_POST['aircraft'] ?? 'Airbus A321');
    $fromAp   = trim($_POST['from_airport'] ?? 'SGN');
    $toAp     = trim($_POST['to_airport'] ?? 'HAN');
    $depAt    = trim($_POST['depart_at'] ?? '');
    $arrAt    = trim($_POST['arrive_at'] ?? '');
    $stops    = (int)($_POST['stops'] ?? 0);
    $priceEconomy = (int)($_POST['price_economy'] ?? 0);
    $priceBusiness = (int)($_POST['price_business'] ?? 0);
    $tax      = (int)($_POST['tax_price'] ?? 420000);
    $gate     = trim($_POST['gate'] ?? 'Gate 04');
    $status   = trim($_POST['status'] ?? 'scheduled');

    if ($fId > 0 && $canEditFlightDetails && !empty($fn) && !empty($airline)) {
        $stmt = mysqli_prepare($conn, 
            "UPDATE flights SET flight_number = ?, airline = ?, aircraft = ?, from_airport = ?, to_airport = ?, depart_at = ?, arrive_at = ?, stops = ?, price_economy = ?, price_business = ?, tax_price = ?, status = ?, gate = ? WHERE id = ?"
        );
        mysqli_stmt_bind_param($stmt, "sssssssiiiissi", $fn, $airline, $aircraft, $fromAp, $toAp, $depAt, $arrAt, $stops, $priceEconomy, $priceBusiness, $tax, $status, $gate, $fId);
        if (mysqli_stmt_execute($stmt)) {
            setFlash("Cập nhật chuyến bay {$fn} thành công!");
        } else {
            setFlash("Lỗi cập nhật: " . mysqli_error($conn), 'error');
        }
        mysqli_stmt_close($stmt);
    } elseif ($fId > 0 && ($canEditFlightSchedule || $canEditFlightStatus)) {
        $sets = [];
        $types = '';
        $values = [];
        if ($canEditFlightSchedule) {
            $sets[] = 'depart_at = ?';
            $sets[] = 'arrive_at = ?';
            $types .= 'ss';
            $values[] = $depAt;
            $values[] = $arrAt;
        }
        if ($canEditFlightStatus) {
            $allowedStatuses = ['scheduled', 'delayed', 'departed', 'landed', 'cancelled'];
            if (!in_array($status, $allowedStatuses, true)) {
                setFlash('Trạng thái chuyến bay không hợp lệ.', 'error');
                header('Location: /WebsiteDatVe/admin/admin.php?tab=flights');
                exit;
            }
            $sets[] = 'status = ?';
            $sets[] = 'gate = ?';
            $types .= 'ss';
            $values[] = $status;
            $values[] = $gate;
        }
        $types .= 'i';
        $values[] = $fId;
        $stmt = mysqli_prepare($conn, 'UPDATE flights SET ' . implode(', ', $sets) . ' WHERE id = ?');
        if (!$stmt) {
            setFlash('Không thể chuẩn bị cập nhật chuyến bay: ' . mysqli_error($conn), 'error');
        } else {
            mysqli_stmt_bind_param($stmt, $types, ...$values);
            if (mysqli_stmt_execute($stmt)) {
                setFlash('Cập nhật lịch hoặc trạng thái chuyến bay thành công!');
            } else {
                setFlash('Lỗi cập nhật chuyến bay: ' . mysqli_stmt_error($stmt), 'error');
            }
            mysqli_stmt_close($stmt);
        }
    }
    header('Location: /WebsiteDatVe/admin/admin.php?tab=flights');
    exit;
}

// 3. Cập nhật trạng thái đơn đặt vé
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_booking']) && $conn) {
    verifyAdminCsrf();
    $bId           = (int)($_POST['booking_id'] ?? 0);
    $bookingStatus = trim($_POST['booking_status'] ?? '');
    $paymentStatus = trim($_POST['payment_status'] ?? '');

    if ($bId > 0) {
        $sets = [];
        $types = '';
        $values = [];
        if ($canApproveBookings) {
            requireAdminPermission($conn, 'booking_approve', 'bookings');
            if (!in_array($bookingStatus, ['pending', 'confirmed', 'cancelled'], true)) {
                setFlash('Trạng thái vé không hợp lệ.', 'error');
                header('Location: /WebsiteDatVe/admin/admin.php?tab=bookings');
                exit;
            }
            $sets[] = 'booking_status = ?';
            $types .= 's';
            $values[] = $bookingStatus;
        }
        if ($canUpdatePaymentStatus) {
            requireAdminPermission($conn, 'payment_status_manage', 'bookings');
            if (!in_array($paymentStatus, ['unpaid', 'paid', 'refunded'], true)) {
                setFlash('Trạng thái thanh toán không hợp lệ.', 'error');
                header('Location: /WebsiteDatVe/admin/admin.php?tab=bookings');
                exit;
            }
            $sets[] = 'payment_status = ?';
            $types .= 's';
            $values[] = $paymentStatus;
        }
        if ($sets === []) {
            requireAdminPermission($conn, 'booking_approve', 'bookings');
        }
        $types .= 'i';
        $values[] = $bId;
        $stmt = mysqli_prepare($conn, 'UPDATE bookings SET ' . implode(', ', $sets) . ' WHERE id = ?');
        mysqli_stmt_bind_param($stmt, $types, ...$values);
        if (mysqli_stmt_execute($stmt)) {
            setFlash("Cập nhật đơn đặt vé #{$bId} thành công!");
        } else {
            setFlash("Lỗi cập nhật: " . mysqli_error($conn), 'error');
        }
        mysqli_stmt_close($stmt);
    }
    header('Location: /WebsiteDatVe/admin/admin.php?tab=bookings');
    exit;
}

// 4. Thêm người dùng mới
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_user']) && $conn) {
    verifyAdminCsrf();
    requireAdminPermission($conn, 'user_role_manage', 'users');
    $fullname = trim($_POST['fullname'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $password = trim($_POST['password'] ?? '');
    $phone    = trim($_POST['phone'] ?? '');
    $role     = normalize_role(trim($_POST['role'] ?? 'customer'));
    if (!in_array($role, ['flight_manager', 'support', 'accountant', 'customer'], true)) {
        $role = 'customer';
    }

    if (!empty($fullname) && !empty($email) && !empty($password)) {
        // Kiểm tra email trùng
        $chk = mysqli_query($conn, "SELECT id FROM users WHERE email = '" . mysqli_real_escape_string($conn, $email) . "'");
        if (mysqli_num_rows($chk) > 0) {
            setFlash("Email này đã được sử dụng bởi tài khoản khác!", 'error');
        } else {
            $hashed = password_hash($password, PASSWORD_DEFAULT);
            $dbRole = database_role($role);
            $stmt = mysqli_prepare($conn, "INSERT INTO users (fullname, email, password, phone, role) VALUES (?, ?, ?, ?, ?)");
            mysqli_stmt_bind_param($stmt, "sssss", $fullname, $email, $hashed, $phone, $dbRole);
            if (mysqli_stmt_execute($stmt)) {
                setFlash("Thêm tài khoản {$fullname} thành công!");
            } else {
                setFlash("Lỗi tạo tài khoản: " . mysqli_error($conn), 'error');
            }
            mysqli_stmt_close($stmt);
        }
    } else {
        setFlash("Vui lòng điền đầy đủ họ tên, email và mật khẩu!", 'error');
    }
    header('Location: /WebsiteDatVe/admin/admin.php?tab=users');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['set_user_role']) && $conn) {
    verifyAdminCsrf();
    requireAdminPermission($conn, 'user_role_manage', 'users');
    $targetId = (int)($_POST['user_id'] ?? 0);
    $newRole = normalize_role(trim((string)($_POST['role'] ?? '')));
    if ($targetId <= 0 || !in_array($newRole, ['flight_manager', 'support', 'accountant', 'customer'], true)) {
        setFlash('Vai trò người dùng không hợp lệ.', 'error');
    } else {
        $dbRole = database_role($newRole);
        $stmt = mysqli_prepare($conn, "UPDATE users SET role = ? WHERE id = ? AND role <> 'Quan_Tri_Vien' AND id <> ?");
        mysqli_stmt_bind_param($stmt, 'sii', $dbRole, $targetId, $_SESSION['user']['id']);
        if (mysqli_stmt_execute($stmt) && mysqli_stmt_affected_rows($stmt) === 1) {
            setFlash('Đã cập nhật vai trò thành ' . role_label($newRole) . '.');
        } else {
            setFlash('Không thể đổi vai trò tài khoản này. Không thể hạ cấp hoặc sửa Super Admin.', 'error');
        }
        mysqli_stmt_close($stmt);
    }
    header('Location: /WebsiteDatVe/admin/admin.php?tab=users');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['grant_permission']) && $conn) {
    verifyAdminCsrf();
    requireAdminPermission($conn, 'permission_grant', 'users');
    $targetId = (int)($_POST['user_id'] ?? 0);
    $permission = trim((string)($_POST['permission_key'] ?? ''));
    $hours = (int)($_POST['duration_hours'] ?? 0);
    $grantablePermissions = [
        'dashboard_view', 'revenue_view', 'flight_create', 'flight_details_edit', 'flight_delete',
        'payment_status_manage', 'booking_delete', 'user_delete',
    ];
    if ($targetId <= 0 || !in_array($permission, $grantablePermissions, true) || $hours < 1 || $hours > 72) {
        setFlash('Quyền hoặc thời hạn không hợp lệ. Thời hạn cấp quyền từ 1 đến 72 giờ.', 'error');
    } else {
        $stmt = mysqli_prepare($conn,
            "INSERT INTO user_permission_grants (user_id, permission_key, granted_by, expires_at)
             SELECT id, ?, ?, DATE_ADD(NOW(), INTERVAL ? HOUR)
             FROM users
             WHERE id = ? AND status = 'Hoat_Dong'
               AND role IN ('Quan_Ly_Chuyen_Bay', 'Cham_Soc_Khach_Hang', 'Ke_Toan')"
        );
        mysqli_stmt_bind_param($stmt, 'siii', $permission, $_SESSION['user']['id'], $hours, $targetId);
        if (mysqli_stmt_execute($stmt) && mysqli_stmt_affected_rows($stmt) === 1) {
            setFlash('Đã cấp quyền ' . $permission . ' trong ' . $hours . ' giờ.');
        } else {
            setFlash('Không thể cấp quyền: chỉ cấp cho tài khoản nhân viên hợp lệ.', 'error');
        }
        mysqli_stmt_close($stmt);
    }
    header('Location: /WebsiteDatVe/admin/admin.php?tab=users');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['revoke_permission']) && $conn) {
    verifyAdminCsrf();
    requireAdminPermission($conn, 'permission_grant', 'users');
    $grantId = (int)($_POST['grant_id'] ?? 0);
    $stmt = mysqli_prepare($conn,
        'UPDATE user_permission_grants SET revoked_at = NOW()
         WHERE id = ? AND revoked_at IS NULL'
    );
    mysqli_stmt_bind_param($stmt, 'i', $grantId);
    if (mysqli_stmt_execute($stmt) && mysqli_stmt_affected_rows($stmt) === 1) {
        setFlash('Đã thu hồi quyền bổ sung.');
    } else {
        setFlash('Không tìm thấy quyền đang hoạt động để thu hồi.', 'error');
    }
    mysqli_stmt_close($stmt);
    header('Location: /WebsiteDatVe/admin/admin.php?tab=users');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_passenger']) && $conn) {
    verifyAdminCsrf();
    requireAdminPermission($conn, 'passenger_edit', 'bookings');
    $passengerId = (int)($_POST['passenger_id'] ?? 0);
    $firstName = trim((string)($_POST['first_name'] ?? ''));
    $lastName = trim((string)($_POST['last_name'] ?? ''));
    $dobInput = trim((string)($_POST['dob'] ?? ''));
    $idNumber = trim((string)($_POST['id_number'] ?? ''));
    $dob = null;
    $dateValid = $dobInput === '';
    if ($dobInput !== '') {
        $parsedDob = DateTime::createFromFormat('!Y-m-d', $dobInput);
        $dateValid = $parsedDob && $parsedDob->format('Y-m-d') === $dobInput;
        $dob = $dateValid ? $dobInput : null;
    }
    if ($passengerId <= 0 || $firstName === '' || $lastName === '' ||
        mb_strlen($firstName) > 50 || mb_strlen($lastName) > 50 || mb_strlen($idNumber) > 50 || !$dateValid) {
        setFlash('Thông tin hành khách không hợp lệ. Hãy kiểm tra họ tên, ngày sinh và hộ chiếu.', 'error');
    } else {
        $stmt = mysqli_prepare($conn,
            'UPDATE passengers SET first_name = ?, last_name = ?, dob = ?, id_number = ? WHERE id = ?'
        );
        if (!$stmt) {
            setFlash('Không thể chuẩn bị cập nhật hành khách: ' . mysqli_error($conn), 'error');
        } else {
            mysqli_stmt_bind_param($stmt, 'ssssi', $firstName, $lastName, $dob, $idNumber, $passengerId);
            if (mysqli_stmt_execute($stmt)) {
                setFlash('Đã cập nhật thông tin hành khách.');
            } else {
                setFlash('Không thể cập nhật thông tin hành khách: ' . mysqli_stmt_error($stmt), 'error');
            }
            mysqli_stmt_close($stmt);
        }
    }
    header('Location: /WebsiteDatVe/admin/admin.php?tab=bookings');
    exit;
}

// -------------------------------------------------------------
// TRUY VẤN DỮ LIỆU HIỂN THỊ
// -------------------------------------------------------------
$totalFlights = 0;
$todayTickets = 0;
$totalRevenue = 0;
$totalUsers   = 0;
$airports     = getAirports($conn);

$flightsList  = [];
$bookingsList = [];
$usersList    = [];
$activeGrants = [];
$passengerCorrectionList = [];
$recentBookings = [];
$recentFlights  = [];

if ($conn) {
    // 1. Thống kê cơ bản
    $r1 = mysqli_query($conn, "SELECT COUNT(*) AS cnt FROM flights");
    if ($r1) $totalFlights = (int)mysqli_fetch_assoc($r1)['cnt'];

    $r2 = mysqli_query($conn, "SELECT COALESCE(SUM(num_passengers), 0) AS cnt FROM bookings WHERE DATE(created_at) = CURDATE() AND payment_status = 'paid'");
    if ($r2) $todayTickets = (int)mysqli_fetch_assoc($r2)['cnt'];

    $r3 = mysqli_query($conn, "SELECT COALESCE(SUM(amount), 0) AS total FROM payments WHERE status = 'success'");
    if ($r3) $totalRevenue = (int)mysqli_fetch_assoc($r3)['total'];

    $r4 = mysqli_query($conn, "SELECT COUNT(*) AS cnt FROM users");
    if ($r4) $totalUsers = (int)mysqli_fetch_assoc($r4)['cnt'];

    // 2. Danh sách Chuyến bay
    $fRes = mysqli_query($conn, 
        "SELECT f.*, f.price_economy AS price,
                (f.seats_economy_left + f.seats_business_left) AS seats_left,
                dep.city as from_city, dep.name as from_airport_name, arr.city as to_city, arr.name as to_airport_name
         FROM flights f 
         JOIN airports dep ON f.from_airport = dep.code 
         JOIN airports arr ON f.to_airport = arr.code 
         ORDER BY f.depart_at ASC"
    );
    if ($fRes) {
        while ($row = mysqli_fetch_assoc($fRes)) {
            $flightsList[] = $row;
        }
    }

    // 3. Danh sách Đơn đặt vé
    $bRes = mysqli_query($conn,
        "SELECT b.*, f.flight_number, f.airline, f.depart_at, f.from_airport, f.to_airport,
                dep.city AS from_city, arr.city AS to_city,
                p.method AS pay_method, p.status AS pay_status, p.transaction_ref
         FROM bookings b
         JOIN flights f ON b.flight_id = f.id
         JOIN airports dep ON f.from_airport = dep.code
         JOIN airports arr ON f.to_airport = arr.code
         LEFT JOIN payments p ON b.id = p.booking_id
         ORDER BY b.id DESC"
    );
    if ($bRes) {
        while ($row = mysqli_fetch_assoc($bRes)) {
            $bookingsList[] = $row;
        }

        if (has_permission($conn, 'passenger_edit')) {
            $passengerRes = mysqli_query($conn,
                "SELECT p.id, p.first_name, p.last_name, p.dob, p.id_number,
                        b.booking_code, b.contact_name, f.flight_number
                 FROM passengers p
                 JOIN bookings b ON b.id = p.booking_id
                 JOIN flights f ON f.id = b.flight_id
                 ORDER BY b.created_at DESC, p.id
                 LIMIT 100"
            );
            if ($passengerRes) {
                while ($passenger = mysqli_fetch_assoc($passengerRes)) {
                    $passengerCorrectionList[] = $passenger;
                }
            }
        }
    }

    // 4. Danh sách Người dùng
    $userRoleFilter = $canManageRoles ? '' : "WHERE u.role = 'Khach_Hang'";
    $uRes = mysqli_query($conn,
        "SELECT u.id, u.fullname, u.email, u.phone,
                CASE u.role
                    WHEN 'Quan_Tri_Vien' THEN 'super_admin'
                    WHEN 'Quan_Ly_Chuyen_Bay' THEN 'flight_manager'
                    WHEN 'Cham_Soc_Khach_Hang' THEN 'support'
                    WHEN 'Ke_Toan' THEN 'accountant'
                    ELSE 'customer'
                END AS role,
                u.created_at,
                COUNT(b.id) AS booking_count,
                COALESCE(SUM(CASE WHEN b.payment_status = 'paid' THEN b.total_price ELSE 0 END), 0) AS total_spent
         FROM users u
         LEFT JOIN bookings b ON u.id = b.user_id
         {$userRoleFilter}
         GROUP BY u.id
         ORDER BY CASE WHEN u.role = 'Khach_Hang' THEN 0 ELSE 1 END, u.id DESC"
    );
    if ($uRes) {
        while ($row = mysqli_fetch_assoc($uRes)) {
            $usersList[] = $row;
        }

        if ($canGrantPermissions) {
            $gRes = mysqli_query($conn,
                "SELECT g.id, g.user_id, g.permission_key, g.expires_at, u.fullname, u.email
                 FROM user_permission_grants g JOIN users u ON u.id = g.user_id
                 WHERE g.revoked_at IS NULL AND g.expires_at > NOW()
                 ORDER BY g.expires_at ASC"
            );
            if ($gRes) {
                while ($grant = mysqli_fetch_assoc($gRes)) {
                    $activeGrants[] = $grant;
                }
            }
        }
    }

    // Cho Dashboard: 5 đơn vé gần nhất & 5 chuyến bay sắp tới
    $recentBookings = array_slice($bookingsList, 0, 5);
    $recentFlights  = array_slice($flightsList, 0, 5);
}

// Flash message
$flashMsg = $_SESSION['admin_msg'] ?? '';
$flashType = $_SESSION['admin_msg_type'] ?? 'success';
unset($_SESSION['admin_msg'], $_SESSION['admin_msg_type']);
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Portal - Hệ thống Quản trị Booking Online</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
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
    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; }
        .custom-scroll::-webkit-scrollbar { width: 5px; height: 5px; }
        .custom-scroll::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 9999px; }
    </style>
</head>
<body class="bg-slate-100 flex min-h-screen text-slate-800">

    <!-- ================= SIDEBAR ================= -->
    <aside class="w-64 bg-slate-900 text-white flex flex-col shrink-0 min-h-screen border-r border-slate-800">
        <!-- Logo -->
        <div class="h-16 flex items-center px-6 gap-3 border-b border-slate-800">
            <span class="w-9 h-9 rounded-xl bg-sky-500 text-white flex items-center justify-center font-black shadow-md shadow-sky-500/30">
                <i class="fa-solid fa-plane"></i>
            </span>
            <span class="font-extrabold text-base tracking-wide">Admin Portal</span>
        </div>

        <!-- Menu Navigation -->
        <nav class="p-4 space-y-1.5 flex-1 text-sm font-medium">
            <!-- 1. Bảng điều khiển -->
            <?php if ($canDashboard): ?><a href="/WebsiteDatVe/admin/admin.php?tab=dashboard" 
               class="flex items-center gap-3 px-4 py-3 rounded-xl transition <?= $tab === 'dashboard' ? 'bg-sky-600 text-white font-bold shadow-md shadow-sky-600/30' : 'text-slate-400 hover:text-white hover:bg-slate-800' ?>">
                <i class="fa-solid fa-chart-line w-4 text-center"></i>
                <span>Bảng điều khiển</span>
            </a><?php endif; ?>

            <!-- 2. Quản lý Chuyến bay -->
            <?php if ($canFlights): ?><a href="/WebsiteDatVe/admin/admin.php?tab=flights" 
               class="flex items-center gap-3 px-4 py-3 rounded-xl transition <?= $tab === 'flights' ? 'bg-sky-600 text-white font-bold shadow-md shadow-sky-600/30' : 'text-slate-400 hover:text-white hover:bg-slate-800' ?>">
                <i class="fa-solid fa-plane-departure w-4 text-center"></i>
                <span>Quản lý Chuyến bay</span>
                <span class="ml-auto text-xs px-2 py-0.5 rounded-full <?= $tab === 'flights' ? 'bg-white/20 text-white' : 'bg-slate-800 text-slate-400' ?>"><?= count($flightsList) ?></span>
            </a><?php endif; ?>

            <!-- 3. Quản lý Đơn đặt vé -->
            <?php if ($canBookings): ?><a href="/WebsiteDatVe/admin/admin.php?tab=bookings" 
               class="flex items-center gap-3 px-4 py-3 rounded-xl transition <?= $tab === 'bookings' ? 'bg-sky-600 text-white font-bold shadow-md shadow-sky-600/30' : 'text-slate-400 hover:text-white hover:bg-slate-800' ?>">
                <i class="fa-solid fa-ticket w-4 text-center"></i>
                <span>Quản lý Đơn đặt vé</span>
                <span class="ml-auto text-xs px-2 py-0.5 rounded-full <?= $tab === 'bookings' ? 'bg-white/20 text-white' : 'bg-slate-800 text-slate-400' ?>"><?= count($bookingsList) ?></span>
            </a><?php endif; ?>

            <!-- 4. Người dùng -->
            <?php if ($canCustomers): ?><a href="/WebsiteDatVe/admin/admin.php?tab=users" 
               class="flex items-center gap-3 px-4 py-3 rounded-xl transition <?= $tab === 'users' ? 'bg-sky-600 text-white font-bold shadow-md shadow-sky-600/30' : 'text-slate-400 hover:text-white hover:bg-slate-800' ?>">
                <i class="fa-solid fa-users w-4 text-center"></i>
                <span>Người dùng</span>
                <span class="ml-auto text-xs px-2 py-0.5 rounded-full <?= $tab === 'users' ? 'bg-white/20 text-white' : 'bg-slate-800 text-slate-400' ?>"><?= count($usersList) ?></span>
            </a><?php endif; ?>
        </nav>

        <!-- Footer Sidebar -->
        <div class="p-4 border-t border-slate-800 space-y-2">
            <a href="/WebsiteDatVe/index.php" class="flex items-center gap-2.5 px-3 py-2 text-xs font-bold text-slate-400 hover:text-white hover:bg-slate-800 rounded-lg transition">
                <i class="fa-solid fa-arrow-left"></i>
                <span>Quay lại trang chủ</span>
            </a>
            <a href="/WebsiteDatVe/login/logout.php" class="flex items-center gap-2.5 px-3 py-2 text-xs font-bold text-rose-400 hover:text-rose-300 hover:bg-rose-950/40 rounded-lg transition">
                <i class="fa-solid fa-arrow-right-from-bracket"></i>
                <span>Đăng xuất</span>
            </a>
        </div>
    </aside>

    <!-- ================= NỘI DUNG CHÍNH (MAIN WRAPPER) ================= -->
    <div class="flex-1 flex flex-col min-w-0">
        <!-- TOP HEADER -->
        <header class="h-16 bg-white border-b border-slate-200 flex items-center justify-between px-8 sticky top-0 z-30 shadow-sm">
            <div class="flex items-center gap-3">
                <h1 class="text-lg font-black text-slate-900">
                    <?php 
                        if ($tab === 'dashboard') echo 'Bảng điều khiển hệ thống';
                        elseif ($tab === 'flights') echo 'Quản lý Chuyến bay';
                        elseif ($tab === 'bookings') echo 'Quản lý Đơn đặt vé';
                        elseif ($tab === 'users') echo 'Quản lý Người dùng';
                    ?>
                </h1>
                <span class="text-xs px-2.5 py-0.5 rounded-full bg-slate-100 text-slate-600 font-semibold border border-slate-200">
                    MySQL Connected
                </span>
            </div>

            <div class="flex items-center gap-4">
                <div class="flex items-center gap-2.5">
                    <span class="w-8 h-8 rounded-full bg-sky-100 text-sky-600 flex items-center justify-center font-extrabold text-xs">
                        AD
                    </span>
                    <div class="text-left hidden sm:block">
                        <p class="text-xs font-bold text-slate-800 leading-tight"><?= htmlspecialchars($_SESSION['user']['fullname'] ?? role_label($adminRole), ENT_QUOTES, 'UTF-8') ?></p>
                        <p class="text-[11px] text-slate-400"><?= htmlspecialchars(role_label($adminRole), ENT_QUOTES, 'UTF-8') ?></p>
                    </div>
                </div>
            </div>
        </header>

        <!-- FLASH NOTIFICATION -->
        <?php if (!empty($flashMsg)): ?>
            <div class="mx-8 mt-6 p-4 rounded-2xl <?= $flashType === 'error' ? 'bg-rose-50 border border-rose-200 text-rose-700' : 'bg-emerald-50 border border-emerald-200 text-emerald-700' ?> flex items-center justify-between shadow-sm animate-fade-in">
                <div class="flex items-center gap-3 text-xs font-bold">
                    <i class="fa-solid <?= $flashType === 'error' ? 'fa-triangle-exclamation' : 'fa-circle-check' ?> text-base"></i>
                    <span><?= htmlspecialchars($flashMsg) ?></span>
                </div>
                <button onclick="this.parentElement.remove()" class="text-slate-400 hover:text-slate-600"><i class="fa-solid fa-xmark"></i></button>
            </div>
        <?php endif; ?>

        <!-- MAIN CONTENT AREA -->
        <main class="p-8 space-y-8 flex-1">

            <!-- ============================================================ -->
            <!-- TAB 1: BẢNG ĐIỀU KHIỂN (DASHBOARD)                           -->
            <!-- ============================================================ -->
            <?php if ($tab === 'dashboard'): ?>
                <!-- 4 Thẻ thống kê tổng quan -->
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-5">
                    <a href="/WebsiteDatVe/admin/admin.php?tab=flights" class="bg-white p-5 rounded-2xl border border-slate-200 shadow-sm hover:shadow-md hover:border-sky-300 transition group">
                        <div class="flex items-center justify-between">
                            <p class="text-xs text-slate-400 font-bold uppercase">Tổng chuyến bay</p>
                            <span class="w-8 h-8 rounded-xl bg-sky-50 text-sky-600 flex items-center justify-center text-sm group-hover:scale-110 transition"><i class="fa-solid fa-plane"></i></span>
                        </div>
                        <p class="text-2xl font-black text-slate-900 mt-2"><?= $totalFlights ?></p>
                        <p class="text-[11px] text-slate-400 mt-1">Đang hoạt động trong CSDL</p>
                    </a>

                    <div class="bg-white p-5 rounded-2xl border border-slate-200 shadow-sm">
                        <div class="flex items-center justify-between">
                            <p class="text-xs text-slate-400 font-bold uppercase">Vé đã bán hôm nay</p>
                            <span class="w-8 h-8 rounded-xl bg-blue-50 text-blue-600 flex items-center justify-center text-sm"><i class="fa-solid fa-ticket"></i></span>
                        </div>
                        <p class="text-2xl font-black text-sky-600 mt-2"><?= $todayTickets ?></p>
                        <p class="text-[11px] text-slate-400 mt-1">Hành khách xác nhận hôm nay</p>
                    </div>

                    <a href="/WebsiteDatVe/admin/admin.php?tab=bookings" class="bg-white p-5 rounded-2xl border border-slate-200 shadow-sm hover:shadow-md hover:border-emerald-300 transition group">
                        <div class="flex items-center justify-between">
                            <p class="text-xs text-slate-400 font-bold uppercase">Tổng doanh thu</p>
                            <span class="w-8 h-8 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center text-sm group-hover:scale-110 transition"><i class="fa-solid fa-dong-sign"></i></span>
                        </div>
                        <?php if ($canViewRevenue): ?>
                            <p class="text-2xl font-black text-emerald-600 mt-2"><?= number_format($totalRevenue) ?>đ</p>
                        <?php else: ?>
                            <p class="text-sm font-bold text-slate-400 mt-3">Chưa được cấp quyền xem</p>
                        <?php endif; ?>
                        <p class="text-[11px] text-slate-400 mt-1">Từ các thanh toán thành công</p>
                    </a>

                    <a href="/WebsiteDatVe/admin/admin.php?tab=users" class="bg-white p-5 rounded-2xl border border-slate-200 shadow-sm hover:shadow-md hover:border-purple-300 transition group">
                        <div class="flex items-center justify-between">
                            <p class="text-xs text-slate-400 font-bold uppercase">Người dùng hệ thống</p>
                            <span class="w-8 h-8 rounded-xl bg-purple-50 text-purple-600 flex items-center justify-center text-sm group-hover:scale-110 transition"><i class="fa-solid fa-users"></i></span>
                        </div>
                        <p class="text-2xl font-black text-purple-600 mt-2"><?= $totalUsers ?></p>
                        <p class="text-[11px] text-slate-400 mt-1">Tài khoản thành viên & Admin</p>
                    </a>
                </div>

                <!-- Lưới 2 cột: Đơn vé gần đây & Chuyến bay gần đây -->
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                    <!-- 5 Đơn đặt vé mới nhất -->
                    <div class="bg-white rounded-3xl border border-slate-200 shadow-sm overflow-hidden">
                        <div class="p-5 border-b border-slate-100 flex items-center justify-between">
                            <div>
                                <h3 class="text-sm font-bold text-slate-900">Đơn đặt vé mới nhất</h3>
                                <p class="text-xs text-slate-400">Các giao dịch đặt chỗ vừa tạo trên hệ thống</p>
                            </div>
                            <a href="/WebsiteDatVe/admin/admin.php?tab=bookings" class="text-xs font-bold text-sky-600 hover:text-sky-700">Xem tất cả &rarr;</a>
                        </div>
                        <div class="divide-y divide-slate-100 text-xs">
                            <?php if (empty($recentBookings)): ?>
                                <p class="p-6 text-center text-slate-400">Chưa có đơn đặt vé nào.</p>
                            <?php else: ?>
                                <?php foreach ($recentBookings as $b): ?>
                                    <div class="p-4 flex items-center justify-between hover:bg-slate-50 transition">
                                        <div>
                                            <p class="font-extrabold text-slate-900"><?= $b['booking_code'] ?> · <?= htmlspecialchars($b['contact_name']) ?></p>
                                            <p class="text-slate-400 text-[11px] mt-0.5">
                                                <?= $b['flight_number'] ?> (<?= $b['from_airport'] ?> ➔ <?= $b['to_airport'] ?>) · <?= $b['num_passengers'] ?> khách
                                            </p>
                                        </div>
                                        <div class="text-right">
                                            <p class="font-extrabold text-emerald-600"><?= number_format($b['total_price']) ?>đ</p>
                                            <span class="inline-block mt-1 px-2 py-0.5 rounded-full text-[10px] font-bold <?= $b['payment_status'] === 'paid' ? 'bg-emerald-50 text-emerald-600' : ($b['payment_status'] === 'unpaid' ? 'bg-amber-50 text-amber-600' : 'bg-rose-50 text-rose-600') ?>">
                                                <?= htmlspecialchars(paymentStatusLabel($b['payment_status'])) ?>
                                            </span>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- 5 Chuyến bay gần nhất -->
                    <div class="bg-white rounded-3xl border border-slate-200 shadow-sm overflow-hidden">
                        <div class="p-5 border-b border-slate-100 flex items-center justify-between">
                            <div>
                                <h3 class="text-sm font-bold text-slate-900">Lịch trình chuyến bay</h3>
                                <p class="text-xs text-slate-400">Các chuyến bay gần nhất trong cơ sở dữ liệu</p>
                            </div>
                            <a href="/WebsiteDatVe/admin/admin.php?tab=flights" class="text-xs font-bold text-sky-600 hover:text-sky-700">Xem tất cả &rarr;</a>
                        </div>
                        <div class="divide-y divide-slate-100 text-xs">
                            <?php if (empty($recentFlights)): ?>
                                <p class="p-6 text-center text-slate-400">Chưa có chuyến bay nào.</p>
                            <?php else: ?>
                                <?php foreach ($recentFlights as $fl): ?>
                                    <div class="p-4 flex items-center justify-between hover:bg-slate-50 transition">
                                        <div>
                                            <p class="font-extrabold text-slate-900"><?= $fl['flight_number'] ?> · <?= htmlspecialchars($fl['airline']) ?></p>
                                            <p class="text-slate-400 text-[11px] mt-0.5">
                                                <?= $fl['from_city'] ?> (<?= $fl['from_airport'] ?>) ➔ <?= $fl['to_city'] ?> (<?= $fl['to_airport'] ?>) · <?= date('H:i - d/m/Y', strtotime($fl['depart_at'])) ?>
                                            </p>
                                        </div>
                                        <div class="text-right">
                                            <p class="font-bold text-sky-600"><?= number_format($fl['price_economy']) ?>đ</p>
                                            <span class="inline-block mt-1 px-2 py-0.5 rounded-full text-[10px] font-bold <?= $fl['status'] === 'scheduled' ? 'bg-emerald-50 text-emerald-600' : ($fl['status'] === 'delayed' ? 'bg-rose-50 text-rose-600' : 'bg-slate-100 text-slate-500') ?>">
                                                <?= htmlspecialchars(flightStatusLabel($fl['status'])) ?>
                                            </span>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Phím tắt thao tác nhanh -->
                <div class="bg-gradient-to-r from-sky-600 to-blue-700 rounded-3xl p-6 text-white shadow-lg flex flex-col sm:flex-row items-center justify-between gap-4">
                    <div>
                        <h3 class="text-base font-extrabold">Thao tác nhanh cho Quản trị viên</h3>
                        <p class="text-xs text-sky-100 mt-1">Thêm chuyến bay mới, duyệt đơn vé hoặc quản trị danh sách người dùng.</p>
                    </div>
                    <div class="flex flex-wrap gap-2.5">
                        <button onclick="document.getElementById('addFlightModal').classList.remove('hidden')" class="px-4 py-2 bg-white text-sky-700 font-bold text-xs rounded-xl shadow hover:bg-sky-50 transition">
                            <i class="fa-solid fa-plus mr-1"></i> Thêm chuyến bay
                        </button>
                        <a href="/WebsiteDatVe/admin/admin.php?tab=bookings" class="px-4 py-2 bg-sky-500 hover:bg-sky-400 text-white font-bold text-xs rounded-xl shadow transition">
                            <i class="fa-solid fa-ticket mr-1"></i> Quản lý đơn vé
                        </a>
                        <button onclick="document.getElementById('addUserModal').classList.remove('hidden')" class="px-4 py-2 bg-slate-900/40 hover:bg-slate-900/60 text-white font-bold text-xs rounded-xl transition">
                            <i class="fa-solid fa-user-plus mr-1"></i> Thêm người dùng
                        </button>
                    </div>
                </div>
            <?php endif; ?>

            <!-- ============================================================ -->
            <!-- TAB 2: QUẢN LÝ CHUYẾN BAY (FLIGHTS)                          -->
            <!-- ============================================================ -->
            <?php if ($tab === 'flights'): ?>
                <div class="bg-white rounded-3xl border border-slate-200 shadow-sm overflow-hidden">
                    <div class="p-6 border-b border-slate-100 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                        <div>
                            <h2 class="text-base font-bold text-slate-900">Danh sách Chuyến bay (<?= count($flightsList) ?> chuyến)</h2>
                            <p class="text-xs text-slate-400">Xem, chỉnh sửa giờ bay, giá vé, ghế trống hoặc xóa chuyến bay khỏi CSDL</p>
                        </div>
                        <div class="flex items-center gap-3">
                            <div class="relative">
                                <i class="fa-solid fa-magnifying-glass absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400 text-xs"></i>
                                <input type="text" id="flightSearchInput" onkeyup="filterFlightsTable()" placeholder="Tìm số hiệu, hãng, sân bay..." class="pl-9 pr-4 py-2 rounded-xl border border-slate-200 text-xs font-semibold focus:outline-none focus:border-sky-500 w-64">
                            </div>
                            <?php if ($canCreateFlights): ?><button onclick="document.getElementById('addFlightModal').classList.remove('hidden')" class="px-4 py-2 bg-sky-600 hover:bg-sky-700 text-white font-bold text-xs rounded-xl shadow transition shrink-0 flex items-center gap-1.5">
                                <i class="fa-solid fa-plus"></i> Thêm chuyến bay
                            </button><?php endif; ?>
                        </div>
                    </div>

                    <div class="overflow-x-auto custom-scroll">
                        <table class="w-full text-left text-xs" id="flightsTable">
                            <thead class="bg-slate-50 text-slate-500 font-bold uppercase border-b border-slate-100">
                                <tr>
                                    <th class="p-4">Số hiệu</th>
                                    <th class="p-4">Hãng & Tàu bay</th>
                                    <th class="p-4">Hành trình</th>
                                    <th class="p-4">Giờ cất cánh</th>
                                    <th class="p-4">Giờ hạ cánh</th>
                                    <th class="p-4">Giá vé</th>
                                    <th class="p-4 text-center">Ghế trống</th>
                                    <th class="p-4">Cổng</th>
                                    <th class="p-4">Trạng thái</th>
                                    <th class="p-4 text-center">Thao tác</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100 font-medium">
                                <?php foreach ($flightsList as $fl): ?>
                                    <tr class="hover:bg-slate-50 transition flight-row" data-search="<?= strtolower($fl['flight_number'] . ' ' . $fl['airline'] . ' ' . $fl['from_city'] . ' ' . $fl['to_city'] . ' ' . $fl['from_airport'] . ' ' . $fl['to_airport']) ?>">
                                        <td class="p-4 font-black text-slate-900"><?= htmlspecialchars($fl['flight_number']) ?></td>
                                        <td class="p-4">
                                            <p class="font-bold text-slate-800"><?= htmlspecialchars($fl['airline']) ?></p>
                                            <p class="text-[11px] text-slate-400"><?= htmlspecialchars($fl['aircraft']) ?></p>
                                        </td>
                                        <td class="p-4">
                                            <span class="font-bold text-slate-800"><?= htmlspecialchars($fl['from_airport']) ?></span>
                                            <i class="fa-solid fa-arrow-right text-[10px] text-slate-400 mx-1"></i>
                                            <span class="font-bold text-slate-800"><?= htmlspecialchars($fl['to_airport']) ?></span>
                                            <p class="text-[11px] text-slate-400"><?= $fl['from_city'] ?> ➔ <?= $fl['to_city'] ?></p>
                                        </td>
                                        <td class="p-4 font-bold text-slate-700"><?= date('H:i - d/m/Y', strtotime($fl['depart_at'])) ?></td>
                                        <td class="p-4 text-slate-600"><?= date('H:i - d/m/Y', strtotime($fl['arrive_at'])) ?></td>
                                        <td class="p-4 font-bold text-sky-600"><?= number_format($fl['price_economy']) ?>đ <span class="block text-[10px] text-slate-400">Phổ thông</span></td>
                                        <td class="p-4 text-center">
                                            <span class="px-2 py-0.5 rounded-md font-bold <?= $fl['seats_left'] <= 10 ? 'bg-rose-50 text-rose-600' : 'bg-slate-100 text-slate-700' ?>">
                                                <?= $fl['seats_left'] ?>
                                            </span>
                                        </td>
                                        <td class="p-4 font-semibold text-slate-600"><?= htmlspecialchars($fl['gate'] ?: 'Gate 04') ?></td>
                                        <td class="p-4">
                                            <?php
                                                $st = $fl['status'];
                                                $badgeClass = 'bg-emerald-50 text-emerald-600';
                                                if ($st === 'delayed') $badgeClass = 'bg-rose-50 text-rose-600';
                                                elseif ($st === 'departed') $badgeClass = 'bg-amber-50 text-amber-600';
                                                elseif ($st === 'cancelled') $badgeClass = 'bg-slate-100 text-slate-500';
                                            ?>
                                            <span class="px-2.5 py-1 rounded-full font-bold text-[11px] <?= $badgeClass ?>">
                                                <?= htmlspecialchars(flightStatusLabel($st)) ?>
                                            </span>
                                        </td>
                                        <td class="p-4 text-center space-x-1.5 whitespace-nowrap">
                                            <?php if ($canEditFlightDetails || $canEditFlightSchedule || $canEditFlightStatus): ?><button type="button" 
                                                    onclick='openEditFlightModal(<?= json_encode($fl, JSON_UNESCAPED_UNICODE) ?>)'
                                                    class="p-2 text-sky-600 hover:bg-sky-50 rounded-lg inline-block transition" title="Sửa chuyến bay">
                                                <i class="fa-solid fa-pen-to-square"></i>
                                            </button><?php endif; ?>
                                            <?php if ($canDeleteFlights): ?><a href="/WebsiteDatVe/admin/admin.php?action=delete_flight&id=<?= $fl['id'] ?>&csrf_token=<?= urlencode($csrfToken) ?>" 
                                               onclick="return confirm('Bạn có chắc chắn muốn xóa chuyến bay <?= $fl['flight_number'] ?> khỏi CSDL?')" 
                                               class="p-2 text-rose-600 hover:bg-rose-50 rounded-lg inline-block transition" title="Xóa chuyến bay">
                                                <i class="fa-solid fa-trash"></i>
                                            </a><?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>

            <!-- ============================================================ -->
            <!-- TAB 3: QUẢN LÝ ĐƠN ĐẶT VÉ (BOOKINGS)                          -->
            <!-- ============================================================ -->
            <?php if ($tab === 'bookings'): ?>
                <div class="bg-white rounded-3xl border border-slate-200 shadow-sm overflow-hidden">
                    <div class="p-6 border-b border-slate-100 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                        <div>
                            <h2 class="text-base font-bold text-slate-900">Danh sách Đơn đặt vé (<?= count($bookingsList) ?> đơn)</h2>
                            <p class="text-xs text-slate-400">Quản lý mã PNR, thông tin khách hàng, tình trạng thanh toán và vé máy bay</p>
                        </div>
                        <div class="flex items-center gap-3">
                            <div class="relative">
                                <i class="fa-solid fa-magnifying-glass absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400 text-xs"></i>
                                <input type="text" id="bookingSearchInput" onkeyup="filterBookingsTable()" placeholder="Tìm mã PNR, tên, SĐT..." class="pl-9 pr-4 py-2 rounded-xl border border-slate-200 text-xs font-semibold focus:outline-none focus:border-sky-500 w-64">
                            </div>
                            <select id="bookingStatusFilter" onchange="filterBookingsTable()" class="px-3 py-2 rounded-xl border border-slate-200 text-xs font-bold focus:outline-none focus:border-sky-500">
                                <option value="">Tất cả thanh toán</option>
                                <option value="paid">Đã thanh toán</option>
                                <option value="unpaid">Chưa thanh toán</option>
                                <option value="refunded">Đã hoàn tiền</option>
                            </select>
                        </div>
                    </div>

                    <div class="overflow-x-auto custom-scroll">
                        <table class="w-full text-left text-xs" id="bookingsTable">
                            <thead class="bg-slate-50 text-slate-500 font-bold uppercase border-b border-slate-100">
                                <tr>
                                    <th class="p-4">Mã đặt chỗ (PNR)</th>
                                    <th class="p-4">Khách hàng liên hệ</th>
                                    <th class="p-4">Chuyến bay & Lộ trình</th>
                                    <th class="p-4 text-center">Số khách</th>
                                    <th class="p-4">Tổng tiền</th>
                                    <th class="p-4">Thanh toán</th>
                                    <th class="p-4">Trạng thái vé</th>
                                    <th class="p-4">Ngày tạo</th>
                                    <th class="p-4 text-center">Thao tác</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100 font-medium">
                                <?php if (empty($bookingsList)): ?>
                                    <tr><td colspan="9" class="p-8 text-center text-slate-400">Không có đơn đặt vé nào trong cơ sở dữ liệu.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($bookingsList as $b): ?>
                                        <tr class="hover:bg-slate-50 transition booking-row" 
                                            data-search="<?= strtolower($b['booking_code'] . ' ' . $b['contact_name'] . ' ' . $b['contact_phone'] . ' ' . $b['contact_email'] . ' ' . $b['flight_number']) ?>"
                                            data-paystatus="<?= $b['payment_status'] ?>"
                                        >
                                            <td class="p-4">
                                                <span class="font-black text-sky-600 bg-sky-50 px-2.5 py-1 rounded-lg border border-sky-100 text-xs">
                                                    <?= htmlspecialchars($b['booking_code']) ?>
                                                </span>
                                            </td>
                                            <td class="p-4">
                                                <p class="font-bold text-slate-900"><?= htmlspecialchars($b['contact_name']) ?></p>
                                                <p class="text-[11px] text-slate-400"><?= htmlspecialchars($b['contact_phone']) ?> · <?= htmlspecialchars($b['contact_email']) ?></p>
                                            </td>
                                            <td class="p-4">
                                                <p class="font-bold text-slate-800"><?= $b['flight_number'] ?> (<?= htmlspecialchars($b['airline']) ?>)</p>
                                                <p class="text-[11px] text-slate-400"><?= $b['from_city'] ?> (<?= $b['from_airport'] ?>) ➔ <?= $b['to_city'] ?> (<?= $b['to_airport'] ?>)</p>
                                            </td>
                                            <td class="p-4 text-center">
                                                <span class="font-bold px-2 py-0.5 rounded-full bg-slate-100 text-slate-700">
                                                    <?= $b['num_passengers'] ?>
                                                </span>
                                            </td>
                                            <td class="p-4 font-black text-emerald-600 text-sm">
                                                <?= number_format($b['total_price']) ?>đ
                                            </td>
                                            <td class="p-4">
                                                <?php
                                                    $paySt = $b['payment_status'];
                                                    $pBadge = 'bg-emerald-50 text-emerald-600 border border-emerald-200';
                                                    if ($paySt === 'unpaid') $pBadge = 'bg-amber-50 text-amber-600 border border-amber-200';
                                                    elseif ($paySt === 'refunded') $pBadge = 'bg-rose-50 text-rose-600 border border-rose-200';
                                                ?>
                                                <span class="px-2.5 py-1 rounded-full font-bold text-[11px] inline-block <?= $pBadge ?>">
                                                    <?= htmlspecialchars(paymentStatusLabel($paySt)) ?>
                                                </span>
                                                <?php if (!empty($b['pay_method'])): ?>
                                                    <p class="text-[10px] text-slate-400 mt-0.5"><?= htmlspecialchars(paymentMethodLabel($b['pay_method'])) ?></p>
                                                <?php endif; ?>
                                            </td>
                                            <td class="p-4">
                                                <?php
                                                    $bkSt = $b['booking_status'];
                                                    $bBadge = 'bg-blue-50 text-blue-600';
                                                    if ($bkSt === 'pending') $bBadge = 'bg-amber-50 text-amber-600';
                                                    elseif ($bkSt === 'cancelled') $bBadge = 'bg-slate-100 text-slate-500';
                                                ?>
                                                <span class="px-2.5 py-1 rounded-full font-bold text-[11px] inline-block <?= $bBadge ?>">
                                                    <?= htmlspecialchars(bookingStatusLabel($bkSt)) ?>
                                                </span>
                                            </td>
                                            <td class="p-4 text-slate-400 text-[11px]">
                                                <?= date('H:i - d/m/Y', strtotime($b['created_at'])) ?>
                                            </td>
                                            <td class="p-4 text-center space-x-1.5 whitespace-nowrap">
                                                <button type="button" 
                                                        onclick="viewBookingDetail('<?= $b['booking_code'] ?>')" 
                                                        class="p-2 text-sky-600 hover:bg-sky-50 rounded-lg inline-block transition" title="Xem chi tiết đơn vé">
                                                    <i class="fa-solid fa-eye"></i>
                                                </button>
                                                <?php if ($canApproveBookings || $canUpdatePaymentStatus): ?><button type="button" 
                                                        onclick="openEditBookingModal(<?= $b['id'] ?>, '<?= $b['booking_code'] ?>', '<?= $b['booking_status'] ?>', '<?= $b['payment_status'] ?>')" 
                                                        class="p-2 text-amber-600 hover:bg-amber-50 rounded-lg inline-block transition" title="Cập nhật trạng thái">
                                                    <i class="fa-solid fa-pen"></i>
                                                </button><?php endif; ?>
                                                <?php if ($canDeleteBookings): ?><a href="/WebsiteDatVe/admin/admin.php?action=delete_booking&id=<?= $b['id'] ?>&csrf_token=<?= urlencode($csrfToken) ?>" 
                                                   onclick="return confirm('Bạn có chắc chắn muốn xóa đơn đặt vé <?= $b['booking_code'] ?>?')" 
                                                   class="p-2 text-rose-600 hover:bg-rose-50 rounded-lg inline-block transition" title="Xóa đơn vé">
                                                    <i class="fa-solid fa-trash"></i>
                                                </a><?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php if ($canCustomers && has_permission($conn, 'passenger_edit')): ?>
                    <details class="mt-6 overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">
                        <summary class="cursor-pointer px-6 py-5 text-sm font-extrabold text-slate-900">
                            Sửa thông tin hành khách
                            <span class="ml-2 text-xs font-medium text-slate-500">Chỉ chỉnh họ tên, ngày sinh, hộ chiếu — không sửa giá vé.</span>
                        </summary>
                        <div class="overflow-x-auto border-t border-slate-100">
                            <table class="w-full min-w-[900px] text-left text-xs">
                                <thead class="bg-slate-50 text-slate-500">
                                    <tr>
                                        <th class="p-3">Đơn / chuyến</th>
                                        <th class="p-3">Hành khách</th>
                                        <th class="p-3">Ngày sinh</th>
                                        <th class="p-3">Hộ chiếu/CCCD</th>
                                        <th class="p-3">Lưu</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100">
                                    <?php foreach ($passengerCorrectionList as $passenger): ?>
                                        <tr>
                                            <td class="p-3 font-semibold text-slate-600">
                                                <?= htmlspecialchars($passenger['booking_code'], ENT_QUOTES, 'UTF-8') ?>
                                                · <?= htmlspecialchars($passenger['flight_number'], ENT_QUOTES, 'UTF-8') ?>
                                            </td>
                                            <td colspan="4" class="p-3">
                                                <form method="POST" class="grid grid-cols-1 items-center gap-2 sm:grid-cols-4">
                                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                                    <input type="hidden" name="edit_passenger" value="1">
                                                    <input type="hidden" name="passenger_id" value="<?= (int)$passenger['id'] ?>">
                                                    <input type="text" name="first_name" maxlength="50" required value="<?= htmlspecialchars($passenger['first_name'], ENT_QUOTES, 'UTF-8') ?>" aria-label="Tên hành khách" class="rounded-lg border border-slate-200 px-2.5 py-2">
                                                    <input type="text" name="last_name" maxlength="50" required value="<?= htmlspecialchars($passenger['last_name'], ENT_QUOTES, 'UTF-8') ?>" aria-label="Họ hành khách" class="rounded-lg border border-slate-200 px-2.5 py-2">
                                                    <input type="date" name="dob" value="<?= htmlspecialchars($passenger['dob'] ?? '', ENT_QUOTES, 'UTF-8') ?>" aria-label="Ngày sinh" class="rounded-lg border border-slate-200 px-2.5 py-2">
                                                    <div class="flex gap-2">
                                                        <input type="text" name="id_number" maxlength="50" value="<?= htmlspecialchars($passenger['id_number'] ?? '', ENT_QUOTES, 'UTF-8') ?>" aria-label="Số hộ chiếu hoặc CCCD" placeholder="Hộ chiếu/CCCD" class="min-w-0 flex-1 rounded-lg border border-slate-200 px-2.5 py-2">
                                                        <button class="rounded-lg bg-sky-600 px-3 py-2 font-bold text-white hover:bg-sky-700">Lưu</button>
                                                    </div>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    <?php if (!$passengerCorrectionList): ?>
                                        <tr><td colspan="5" class="p-5 text-center text-slate-400">Chưa có thông tin hành khách để chỉnh sửa.</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </details>
                <?php endif; ?>
            <?php endif; ?>

            <!-- ============================================================ -->
            <!-- TAB 4: QUẢN LÝ NGƯỜI DÙNG (USERS)                            -->
            <!-- ============================================================ -->
            <?php if ($tab === 'users'): ?>
                <div class="bg-white rounded-3xl border border-slate-200 shadow-sm overflow-hidden">
                    <div class="p-6 border-b border-slate-100 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                        <div>
                            <h2 class="text-base font-bold text-slate-900">Danh sách Người dùng (<?= count($usersList) ?> tài khoản)</h2>
                            <p class="text-xs text-slate-400">Xem danh sách thành viên, quản trị viên, thêm mới hoặc phân quyền người dùng</p>
                        </div>
                        <div class="flex items-center gap-3">
                            <div class="relative">
                                <i class="fa-solid fa-magnifying-glass absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400 text-xs"></i>
                                <input type="text" id="userSearchInput" onkeyup="filterUsersTable()" placeholder="Tìm tên, email, SĐT..." class="pl-9 pr-4 py-2 rounded-xl border border-slate-200 text-xs font-semibold focus:outline-none focus:border-sky-500 w-64">
                            </div>
                            <?php if ($canManageRoles): ?><button onclick="document.getElementById('addUserModal').classList.remove('hidden')" class="px-4 py-2 bg-sky-600 hover:bg-sky-700 text-white font-bold text-xs rounded-xl shadow transition shrink-0 flex items-center gap-1.5">
                                <i class="fa-solid fa-user-plus"></i> Thêm người dùng
                            </button><?php endif; ?>
                        </div>
                    </div>

                    <div class="overflow-x-auto custom-scroll">
                        <table class="w-full text-left text-xs" id="usersTable">
                            <thead class="bg-slate-50 text-slate-500 font-bold uppercase border-b border-slate-100">
                                <tr>
                                    <th class="p-4">ID</th>
                                    <th class="p-4">Họ và tên</th>
                                    <th class="p-4">Email</th>
                                    <th class="p-4">Số điện thoại</th>
                                    <th class="p-4">Vai trò (Role)</th>
                                    <th class="p-4 text-center">Đơn đã đặt</th>
                                    <th class="p-4">Tổng chi tiêu</th>
                                    <th class="p-4">Ngày đăng ký</th>
                                    <th class="p-4 text-center">Thao tác</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100 font-medium">
                                <?php $lastUserGroup = null; ?>
                                <?php foreach ($usersList as $u): ?>
                                    <?php
                                        $userGroup = $u['role'] === 'customer' ? 'customers' : 'staff';
                                        if ($lastUserGroup !== $userGroup):
                                            $lastUserGroup = $userGroup;
                                    ?>
                                        <tr class="bg-slate-100 text-slate-700">
                                            <td colspan="9" class="px-4 py-3 font-extrabold uppercase tracking-wide">
                                                <?= $userGroup === 'customers' ? 'Khách hàng' : 'Nhân viên' ?>
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                    <tr class="hover:bg-slate-50 transition user-row" data-search="<?= strtolower($u['fullname'] . ' ' . $u['email'] . ' ' . ($u['phone'] ?? '')) ?>">
                                        <td class="p-4 font-bold text-slate-400">#<?= $u['id'] ?></td>
                                        <td class="p-4">
                                            <div class="flex items-center gap-2.5">
                                                <span class="w-7 h-7 rounded-full <?= $u['role'] === 'customer' ? 'bg-sky-100 text-sky-700' : 'bg-purple-100 text-purple-700' ?> flex items-center justify-center font-bold text-xs">
                                                    <?= strtoupper(substr($u['fullname'] ?? 'U', 0, 1)) ?>
                                                </span>
                                                <span class="font-bold text-slate-900"><?= htmlspecialchars($u['fullname']) ?></span>
                                            </div>
                                        </td>
                                        <td class="p-4 text-slate-600 font-semibold"><?= htmlspecialchars($u['email']) ?></td>
                                        <td class="p-4 text-slate-500"><?= htmlspecialchars($u['phone'] ?: 'Chưa cập nhật') ?></td>
                                        <td class="p-4">
                                            <?php if ($u['role'] !== 'customer'): ?>
                                                <span class="px-2.5 py-1 rounded-full font-black text-[11px] bg-purple-50 text-purple-700 border border-purple-200 flex items-center gap-1 w-fit">
                                                    <i class="fa-solid fa-shield-halved text-[10px]"></i> <?= htmlspecialchars(role_label($u['role']), ENT_QUOTES, 'UTF-8') ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="px-2.5 py-1 rounded-full font-bold text-[11px] bg-slate-100 text-slate-600 border border-slate-200 flex items-center gap-1 w-fit">
                                                    <i class="fa-solid fa-user text-[10px]"></i> Khách hàng
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="p-4 text-center">
                                            <span class="px-2.5 py-0.5 rounded-full font-bold text-xs <?= $u['booking_count'] > 0 ? 'bg-sky-50 text-sky-700' : 'bg-slate-100 text-slate-400' ?>">
                                                <?= $u['booking_count'] ?> đơn
                                            </span>
                                        </td>
                                        <td class="p-4 font-bold text-emerald-600">
                                            <?= $u['role'] === 'customer' ? number_format($u['total_spent']) . 'đ' : '—' ?>
                                        </td>
                                        <td class="p-4 text-slate-400 text-[11px]">
                                            <?= date('d/m/Y', strtotime($u['created_at'])) ?>
                                        </td>
                                        <td class="p-4 text-center space-x-1.5 whitespace-nowrap">
                                            <?php if ($canManageRoles && $u['role'] !== 'super_admin' && (int)$u['id'] !== (int)$_SESSION['user']['id']): ?>
                                                <form method="POST" class="inline-flex items-center gap-1">
                                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                                    <input type="hidden" name="set_user_role" value="1">
                                                    <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                                                    <select name="role" class="max-w-32 rounded-lg border border-slate-200 px-2 py-1 text-[10px] font-bold">
                                                        <?php foreach (['customer', 'flight_manager', 'support', 'accountant'] as $roleOption): ?>
                                                            <option value="<?= $roleOption ?>" <?= $u['role'] === $roleOption ? 'selected' : '' ?>><?= htmlspecialchars(role_label($roleOption), ENT_QUOTES, 'UTF-8') ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                    <button type="submit" class="rounded-lg p-2 text-purple-600 hover:bg-purple-50" title="Lưu vai trò"><i class="fa-solid fa-user-gear"></i></button>
                                                </form>
                                            <?php endif; ?>
                                            <?php if ($canDeleteUsers && $u['role'] !== 'super_admin'): ?><a href="/WebsiteDatVe/admin/admin.php?action=delete_user&id=<?= $u['id'] ?>&csrf_token=<?= urlencode($csrfToken) ?>" 
                                               onclick="return confirm('Bạn có chắc chắn muốn xóa tài khoản <?= $u['fullname'] ?>?')" 
                                               class="p-2 text-rose-600 hover:bg-rose-50 rounded-lg inline-block transition" title="Xóa người dùng">
                                                <i class="fa-solid fa-trash"></i>
                                            </a><?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php if ($canGrantPermissions && in_array($u['role'], ['flight_manager', 'support', 'accountant'], true)): ?>
                                        <tr class="bg-purple-50/50">
                                            <td colspan="9" class="px-4 py-3">
                                                <form method="POST" class="flex flex-wrap items-center gap-2">
                                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                                    <input type="hidden" name="grant_permission" value="1">
                                                    <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                                                    <span class="text-[11px] font-bold text-purple-800">Cấp quyền tạm cho <?= htmlspecialchars($u['fullname'], ENT_QUOTES, 'UTF-8') ?>:</span>
                                                    <select name="permission_key" class="rounded-lg border border-purple-200 bg-white px-2 py-1.5 text-[11px] font-semibold">
                                                        <option value="revenue_view">Xem doanh thu</option>
                                                        <option value="flight_create">Thêm chuyến bay</option>
                                                        <option value="flight_details_edit">Sửa thông tin/giá chuyến bay</option>
                                                        <option value="flight_delete">Xóa chuyến bay</option>
                                                        <option value="payment_status_manage">Sửa trạng thái thanh toán</option>
                                                        <option value="booking_delete">Xóa đơn đặt vé</option>
                                                        <option value="user_delete">Xóa người dùng</option>
                                                    </select>
                                                    <input type="number" name="duration_hours" min="1" max="72" value="2" class="w-20 rounded-lg border border-purple-200 px-2 py-1.5 text-[11px]" aria-label="Thời hạn cấp quyền theo giờ">
                                                    <span class="text-[11px] text-slate-500">giờ (tối đa 72)</span>
                                                    <button class="rounded-lg bg-purple-700 px-3 py-1.5 text-[11px] font-bold text-white hover:bg-purple-800">Cấp quyền</button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php if ($canGrantPermissions && $activeGrants): ?>
                        <div class="border-t border-slate-100 p-5">
                            <h3 class="mb-3 text-sm font-extrabold text-slate-900">Quyền tạm thời đang hoạt động</h3>
                            <div class="space-y-2">
                                <?php foreach ($activeGrants as $grant): ?>
                                    <div class="flex flex-wrap items-center justify-between gap-3 rounded-xl bg-purple-50 px-3 py-2">
                                        <p class="text-xs text-purple-900">
                                            <strong><?= htmlspecialchars($grant['fullname'], ENT_QUOTES, 'UTF-8') ?></strong>
                                            · <?= htmlspecialchars($grant['permission_key'], ENT_QUOTES, 'UTF-8') ?>
                                            · hết hạn <?= htmlspecialchars(date('d/m/Y H:i', strtotime($grant['expires_at'])), ENT_QUOTES, 'UTF-8') ?>
                                        </p>
                                        <form method="POST">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                            <input type="hidden" name="revoke_permission" value="1">
                                            <input type="hidden" name="grant_id" value="<?= (int)$grant['id'] ?>">
                                            <button class="text-xs font-bold text-rose-700 hover:underline">Thu hồi</button>
                                        </form>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

        </main>
    </div>

    <!-- ============================================================ -->
    <!-- MODAL 1: THÊM CHUYẾN BAY MỚI                                -->
    <!-- ============================================================ -->
    <div id="addFlightModal" class="hidden fixed inset-0 z-50 bg-black/50 backdrop-blur-sm flex items-center justify-center p-4">
        <div class="bg-white rounded-3xl p-6 max-w-xl w-full shadow-2xl space-y-4 max-h-[90vh] overflow-y-auto custom-scroll">
            <div class="flex items-center justify-between border-b border-slate-100 pb-3">
                <h3 class="text-base font-bold text-slate-900">Thêm chuyến bay 1 chiều mới</h3>
                <button onclick="document.getElementById('addFlightModal').classList.add('hidden')" class="text-slate-400 hover:text-slate-600"><i class="fa-solid fa-xmark text-lg"></i></button>
            </div>
            <form method="POST" action="" class="space-y-3.5 text-xs">
                <input type="hidden" name="add_flight" value="1">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="font-bold text-slate-700">Số hiệu bay *</label>
                        <input type="text" name="flight_number" required placeholder="VN 312" class="w-full mt-1 px-3 py-2 border rounded-xl font-bold uppercase">
                    </div>
                    <div>
                        <label class="font-bold text-slate-700">Hãng hàng không *</label>
                        <input type="text" name="airline" required placeholder="Vietnam Airlines" class="w-full mt-1 px-3 py-2 border rounded-xl font-bold">
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="font-bold text-slate-700">Loại máy bay</label>
                        <input type="text" id="add_aircraft" name="aircraft" value="Airbus A321" placeholder="Airbus A321" onchange="applyAircraftSeatPreset()" class="w-full mt-1 px-3 py-2 border rounded-xl font-semibold">
                        <p class="mt-1 text-[10px] text-slate-400">Số ghế được tự thiết lập theo loại máy bay.</p>
                    </div>
                    <div>
                        <label class="font-bold text-slate-700">Điểm dừng (Stops)</label>
                        <select name="stops" class="w-full mt-1 px-3 py-2 border rounded-xl font-bold">
                            <option value="0">0 (Bay thẳng)</option>
                            <option value="1">1 điểm dừng</option>
                        </select>
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="font-bold text-slate-700">Sân bay đi *</label>
                        <select name="from_airport" class="w-full mt-1 px-3 py-2 border rounded-xl font-bold">
                            <?php foreach ($airports as $ap): ?>
                                <option value="<?= $ap['code'] ?>"><?= $ap['city'] ?> (<?= $ap['code'] ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="font-bold text-slate-700">Sân bay đến *</label>
                        <select name="to_airport" class="w-full mt-1 px-3 py-2 border rounded-xl font-bold">
                            <?php foreach ($airports as $ap): ?>
                                <option value="<?= $ap['code'] ?>" <?= $ap['code'] === 'HAN' ? 'selected' : '' ?>><?= $ap['city'] ?> (<?= $ap['code'] ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="font-bold text-slate-700">Thời điểm cất cánh (depart_at) *</label>
                        <input type="datetime-local" name="depart_at" required value="<?= date('Y-m-d\TH:i') ?>" class="w-full mt-1 px-3 py-2 border rounded-xl font-bold">
                    </div>
                    <div>
                        <label class="font-bold text-slate-700">Thời điểm hạ cánh (arrive_at) *</label>
                        <input type="datetime-local" name="arrive_at" required value="<?= date('Y-m-d\TH:i', strtotime('+2 hours')) ?>" class="w-full mt-1 px-3 py-2 border rounded-xl font-bold">
                    </div>
                </div>
                <div class="grid grid-cols-3 gap-3">
                    <div>
                        <label class="font-bold text-slate-700">Giá Phổ thông (VNĐ) *</label>
                        <input type="number" name="price_economy" required value="1250000" class="w-full mt-1 px-3 py-2 border rounded-xl font-bold">
                    </div>
                    <div>
                        <label class="font-bold text-slate-700">Giá Thương gia (VNĐ) *</label>
                        <input type="number" name="price_business" required value="2500000" class="w-full mt-1 px-3 py-2 border rounded-xl font-bold">
                    </div>
                    <div>
                        <label class="font-bold text-slate-700">Thuế phí (VNĐ)</label>
                        <input type="number" name="tax_price" value="420000" class="w-full mt-1 px-3 py-2 border rounded-xl font-bold">
                    </div>
                </div>
                <p id="seat_count_preview" class="text-[11px] text-slate-500">Sức chứa mẫu: 60 ghế Phổ thông, 12 ghế Thương gia.</p>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="font-bold text-slate-700">Trạng thái chuyến bay</label>
                        <select name="status" class="w-full mt-1 px-3 py-2 border rounded-xl font-bold">
                            <option value="scheduled">Đúng giờ</option>
                            <option value="delayed">Chậm chuyến</option>
                            <option value="departed">Đang boarding</option>
                            <option value="landed">Đã hạ cánh</option>
                            <option value="cancelled">Đã hủy</option>
                        </select>
                    </div>
                    <div>
                        <label class="font-bold text-slate-700">Cửa ra máy bay (Gate)</label>
                        <input type="text" name="gate" value="Gate 04" class="w-full mt-1 px-3 py-2 border rounded-xl font-bold">
                    </div>
                </div>
                <div class="pt-3 flex gap-2">
                    <button type="button" onclick="document.getElementById('addFlightModal').classList.add('hidden')" class="flex-1 py-2.5 rounded-xl border font-bold text-slate-600">Hủy</button>
                    <button type="submit" class="flex-1 py-2.5 rounded-xl bg-sky-600 hover:bg-sky-700 text-white font-bold">Lưu chuyến bay</button>
                </div>
            </form>
        </div>
    </div>

    <!-- ============================================================ -->
    <!-- MODAL 2: SỬA CHUYẾN BAY                                     -->
    <!-- ============================================================ -->
    <div id="editFlightModal" class="hidden fixed inset-0 z-50 bg-black/50 backdrop-blur-sm flex items-center justify-center p-4">
        <div class="bg-white rounded-3xl p-6 max-w-xl w-full shadow-2xl space-y-4 max-h-[90vh] overflow-y-auto custom-scroll">
            <div class="flex items-center justify-between border-b border-slate-100 pb-3">
                <h3 class="text-base font-bold text-slate-900">Cập nhật thông tin chuyến bay</h3>
                <button onclick="document.getElementById('editFlightModal').classList.add('hidden')" class="text-slate-400 hover:text-slate-600"><i class="fa-solid fa-xmark text-lg"></i></button>
            </div>
            <form method="POST" action="" class="space-y-3.5 text-xs">
                <input type="hidden" name="edit_flight" value="1">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="flight_id" id="edit_f_id">
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="font-bold text-slate-700">Số hiệu bay *</label>
                        <input type="text" name="flight_number" id="edit_f_number" required <?= !$canEditFlightDetails ? 'disabled' : '' ?> class="w-full mt-1 px-3 py-2 border rounded-xl font-bold uppercase disabled:bg-slate-100">
                    </div>
                    <div>
                        <label class="font-bold text-slate-700">Hãng hàng không *</label>
                        <input type="text" name="airline" id="edit_f_airline" required <?= !$canEditFlightDetails ? 'disabled' : '' ?> class="w-full mt-1 px-3 py-2 border rounded-xl font-bold disabled:bg-slate-100">
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="font-bold text-slate-700">Loại máy bay</label>
                        <input type="text" name="aircraft" id="edit_f_aircraft" <?= !$canEditFlightDetails ? 'disabled' : '' ?> class="w-full mt-1 px-3 py-2 border rounded-xl font-semibold disabled:bg-slate-100">
                    </div>
                    <div>
                        <label class="font-bold text-slate-700">Điểm dừng (Stops)</label>
                        <select name="stops" id="edit_f_stops" <?= !$canEditFlightDetails ? 'disabled' : '' ?> class="w-full mt-1 px-3 py-2 border rounded-xl font-bold disabled:bg-slate-100">
                            <option value="0">0 (Bay thẳng)</option>
                            <option value="1">1 điểm dừng</option>
                        </select>
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="font-bold text-slate-700">Sân bay đi *</label>
                        <select name="from_airport" id="edit_f_from" <?= !$canEditFlightDetails ? 'disabled' : '' ?> class="w-full mt-1 px-3 py-2 border rounded-xl font-bold disabled:bg-slate-100">
                            <?php foreach ($airports as $ap): ?>
                                <option value="<?= $ap['code'] ?>"><?= $ap['city'] ?> (<?= $ap['code'] ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="font-bold text-slate-700">Sân bay đến *</label>
                        <select name="to_airport" id="edit_f_to" <?= !$canEditFlightDetails ? 'disabled' : '' ?> class="w-full mt-1 px-3 py-2 border rounded-xl font-bold disabled:bg-slate-100">
                            <?php foreach ($airports as $ap): ?>
                                <option value="<?= $ap['code'] ?>"><?= $ap['city'] ?> (<?= $ap['code'] ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="font-bold text-slate-700">Thời điểm cất cánh *</label>
                        <input type="datetime-local" name="depart_at" id="edit_f_dep" required class="w-full mt-1 px-3 py-2 border rounded-xl font-bold">
                    </div>
                    <div>
                        <label class="font-bold text-slate-700">Thời điểm hạ cánh *</label>
                        <input type="datetime-local" name="arrive_at" id="edit_f_arr" required class="w-full mt-1 px-3 py-2 border rounded-xl font-bold">
                    </div>
                </div>
                <div class="grid grid-cols-3 gap-3">
                    <div>
                        <label class="font-bold text-slate-700">Giá Phổ thông (VNĐ) *</label>
                        <input type="number" name="price_economy" id="edit_f_price" required <?= !$canEditFlightDetails ? 'disabled' : '' ?> class="w-full mt-1 px-3 py-2 border rounded-xl font-bold disabled:bg-slate-100">
                    </div>
                    <div>
                        <label class="font-bold text-slate-700">Giá Thương gia (VNĐ) *</label>
                        <input type="number" name="price_business" id="edit_f_business_price" required <?= !$canEditFlightDetails ? 'disabled' : '' ?> class="w-full mt-1 px-3 py-2 border rounded-xl font-bold disabled:bg-slate-100">
                    </div>
                    <div>
                        <label class="font-bold text-slate-700">Thuế phí (VNĐ)</label>
                        <input type="number" name="tax_price" id="edit_f_tax" <?= !$canEditFlightDetails ? 'disabled' : '' ?> class="w-full mt-1 px-3 py-2 border rounded-xl font-bold disabled:bg-slate-100">
                    </div>
                </div>
                <p class="text-[11px] text-slate-500">Số ghế còn lại được quản lý theo sơ đồ ghế của chuyến bay.</p>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="font-bold text-slate-700">Trạng thái chuyến bay</label>
                        <select name="status" id="edit_f_status" <?= !$canEditFlightStatus ? 'disabled' : '' ?> class="w-full mt-1 px-3 py-2 border rounded-xl font-bold disabled:bg-slate-100">
                            <option value="scheduled">Đúng giờ</option>
                            <option value="delayed">Chậm chuyến</option>
                            <option value="departed">Đang boarding</option>
                            <option value="landed">Đã hạ cánh</option>
                            <option value="cancelled">Đã hủy</option>
                        </select>
                    </div>
                    <div>
                        <label class="font-bold text-slate-700">Cửa ra máy bay (Gate)</label>
                        <input type="text" name="gate" id="edit_f_gate" <?= !$canEditFlightStatus ? 'disabled' : '' ?> class="w-full mt-1 px-3 py-2 border rounded-xl font-bold disabled:bg-slate-100">
                    </div>
                </div>
                <div class="pt-3 flex gap-2">
                    <button type="button" onclick="document.getElementById('editFlightModal').classList.add('hidden')" class="flex-1 py-2.5 rounded-xl border font-bold text-slate-600">Hủy</button>
                    <button type="submit" class="flex-1 py-2.5 rounded-xl bg-sky-600 hover:bg-sky-700 text-white font-bold">Cập nhật chuyến bay</button>
                </div>
            </form>
        </div>
    </div>

    <!-- ============================================================ -->
    <!-- MODAL 3: XEM CHI TIẾT ĐƠN VÉ (VIEW BOOKING DETAIL)          -->
    <!-- ============================================================ -->
    <div id="viewBookingModal" class="hidden fixed inset-0 z-50 bg-black/50 backdrop-blur-sm flex items-center justify-center p-4">
        <div class="bg-white rounded-3xl p-6 sm:p-8 max-w-2xl w-full shadow-2xl space-y-5 max-h-[90vh] overflow-y-auto custom-scroll">
            <div class="flex items-center justify-between border-b border-slate-100 pb-4">
                <div>
                    <h3 class="text-lg font-black text-slate-900 flex items-center gap-2">
                        Chi tiết đơn đặt chỗ: <span id="v_booking_code" class="text-sky-600">...</span>
                    </h3>
                    <p class="text-xs text-slate-400 mt-0.5">Dữ liệu từ MySQL gồm thông tin vé, hành khách và thanh toán</p>
                </div>
                <button onclick="document.getElementById('viewBookingModal').classList.add('hidden')" class="text-slate-400 hover:text-slate-600"><i class="fa-solid fa-xmark text-lg"></i></button>
            </div>

            <!-- Loader -->
            <div id="v_loading" class="py-8 text-center text-slate-400 text-sm">
                <i class="fa-solid fa-spinner fa-spin mr-2"></i> Đang tải dữ liệu...
            </div>

            <!-- Nội dung chi tiết -->
            <div id="v_content" class="hidden space-y-5 text-xs">
                <!-- Thông tin người liên hệ & Chuyến bay -->
                <div class="grid grid-cols-2 gap-4 bg-slate-50 p-4 rounded-2xl border border-slate-100">
                    <div>
                        <p class="text-[11px] font-bold text-slate-400 uppercase">Khách hàng liên hệ</p>
                        <p class="font-extrabold text-slate-900 mt-1" id="v_contact_name">...</p>
                        <p class="text-slate-500 mt-0.5" id="v_contact_info">...</p>
                    </div>
                    <div>
                        <p class="text-[11px] font-bold text-slate-400 uppercase">Chuyến bay</p>
                        <p class="font-extrabold text-slate-900 mt-1" id="v_flight_info">...</p>
                        <p class="text-slate-500 mt-0.5" id="v_flight_route">...</p>
                    </div>
                </div>

                <!-- Bảng danh sách hành khách -->
                <div>
                    <p class="font-bold text-slate-800 uppercase tracking-wider text-[11px] mb-2">Danh sách hành khách trong đơn</p>
                    <div class="border border-slate-200 rounded-2xl overflow-hidden">
                        <table class="w-full text-left text-xs">
                            <thead class="bg-slate-50 text-slate-500 font-bold border-b border-slate-100">
                                <tr>
                                    <th class="p-3">Họ và tên</th>
                                    <th class="p-3">CCCD/Hộ chiếu</th>
                                    <th class="p-3 text-center">Ghế ngồi</th>
                                    <th class="p-3">Hành lý</th>
                                    <th class="p-3">Suất ăn</th>
                                    <th class="p-3 text-center">Trạng thái</th>
                                </tr>
                            </thead>
                            <tbody id="v_passengers_tbody" class="divide-y divide-slate-100 font-medium">
                                <!-- Render qua JS -->
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Tóm tắt thanh toán -->
                <div class="grid grid-cols-3 gap-3 bg-slate-50 p-4 rounded-2xl border border-slate-100 text-center">
                    <div>
                        <p class="text-[11px] text-slate-400 font-bold uppercase">Phương thức</p>
                        <p class="font-extrabold text-slate-800 mt-1" id="v_pay_method">VietQR</p>
                    </div>
                    <div>
                        <p class="text-[11px] text-slate-400 font-bold uppercase">Trạng thái thanh toán</p>
                        <p class="font-extrabold mt-1" id="v_pay_status">...</p>
                    </div>
                    <div>
                        <p class="text-[11px] text-slate-400 font-bold uppercase">Tổng thanh toán</p>
                        <p class="font-black text-emerald-600 text-sm mt-1" id="v_total_price">0đ</p>
                    </div>
                </div>
            </div>

            <div class="pt-3 border-t border-slate-100 flex justify-end">
                <button type="button" onclick="document.getElementById('viewBookingModal').classList.add('hidden')" class="px-5 py-2.5 rounded-xl bg-slate-200 text-slate-700 font-bold text-xs hover:bg-slate-300 transition">Đóng</button>
            </div>
        </div>
    </div>

    <!-- ============================================================ -->
    <!-- MODAL 4: CẬP NHẬT TRẠNG THÁI ĐƠN VÉ                         -->
    <!-- ============================================================ -->
    <div id="editBookingModal" class="hidden fixed inset-0 z-50 bg-black/50 backdrop-blur-sm flex items-center justify-center p-4">
        <div class="bg-white rounded-3xl p-6 max-w-md w-full shadow-2xl space-y-4">
            <div class="flex items-center justify-between border-b border-slate-100 pb-3">
                <h3 class="text-base font-bold text-slate-900">Cập nhật đơn: <span id="eb_code" class="text-sky-600">...</span></h3>
                <button onclick="document.getElementById('editBookingModal').classList.add('hidden')" class="text-slate-400 hover:text-slate-600"><i class="fa-solid fa-xmark text-lg"></i></button>
            </div>
            <form method="POST" action="" class="space-y-4 text-xs">
                <input type="hidden" name="update_booking" value="1">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="booking_id" id="eb_id">

                <?php if ($canUpdatePaymentStatus): ?><div>
                    <label class="font-bold text-slate-700 block mb-1">Trạng thái thanh toán</label>
                    <select name="payment_status" id="eb_payment_status" class="w-full px-3 py-2.5 border rounded-xl font-bold">
                        <option value="paid">Đã thanh toán</option>
                        <option value="unpaid">Chưa thanh toán</option>
                        <option value="refunded">Đã hoàn tiền</option>
                    </select>
                </div>
                <?php endif; ?>

                <?php if ($canApproveBookings): ?><div>
                    <label class="font-bold text-slate-700 block mb-1">Trạng thái đơn vé</label>
                    <select name="booking_status" id="eb_booking_status" class="w-full px-3 py-2.5 border rounded-xl font-bold">
                        <option value="confirmed">Đã xác nhận</option>
                        <option value="pending">Giữ chỗ</option>
                        <option value="cancelled">Đã hủy</option>
                    </select>
                </div>
                <?php endif; ?>

                <div class="pt-3 flex gap-2">
                    <button type="button" onclick="document.getElementById('editBookingModal').classList.add('hidden')" class="flex-1 py-2.5 rounded-xl border font-bold text-slate-600">Hủy</button>
                    <button type="submit" class="flex-1 py-2.5 rounded-xl bg-sky-600 hover:bg-sky-700 text-white font-bold">Lưu thay đổi</button>
                </div>
            </form>
        </div>
    </div>

    <!-- ============================================================ -->
    <!-- MODAL 5: THÊM NGƯỜI DÙNG MỚI                                -->
    <!-- ============================================================ -->
    <div id="addUserModal" class="hidden fixed inset-0 z-50 bg-black/50 backdrop-blur-sm flex items-center justify-center p-4">
        <div class="bg-white rounded-3xl p-6 max-w-md w-full shadow-2xl space-y-4">
            <div class="flex items-center justify-between border-b border-slate-100 pb-3">
                <h3 class="text-base font-bold text-slate-900">Thêm người dùng mới</h3>
                <button onclick="document.getElementById('addUserModal').classList.add('hidden')" class="text-slate-400 hover:text-slate-600"><i class="fa-solid fa-xmark text-lg"></i></button>
            </div>
            <form method="POST" action="" class="space-y-3.5 text-xs">
                <input type="hidden" name="add_user" value="1">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">

                <div>
                    <label class="font-bold text-slate-700 block mb-1">Họ và tên *</label>
                    <input type="text" name="fullname" required placeholder="Nguyễn Văn A" class="w-full px-3 py-2.5 border rounded-xl font-bold">
                </div>

                <div>
                    <label class="font-bold text-slate-700 block mb-1">Email đăng nhập *</label>
                    <input type="email" name="email" required placeholder="user@gmail.com" class="w-full px-3 py-2.5 border rounded-xl font-bold">
                </div>

                <div>
                    <label class="font-bold text-slate-700 block mb-1">Mật khẩu *</label>
                    <input type="password" name="password" required placeholder="Tối thiểu 6 ký tự" class="w-full px-3 py-2.5 border rounded-xl font-bold">
                </div>

                <div>
                    <label class="font-bold text-slate-700 block mb-1">Số điện thoại</label>
                    <input type="text" name="phone" placeholder="0912345678" class="w-full px-3 py-2.5 border rounded-xl font-semibold">
                </div>

                <div>
                    <label class="font-bold text-slate-700 block mb-1">Vai trò</label>
                    <select name="role" class="w-full px-3 py-2.5 border rounded-xl font-bold">
                        <option value="customer">Khách hàng</option>
                        <option value="flight_manager">Quản lý chuyến bay</option>
                        <option value="support">Chăm sóc khách hàng</option>
                        <option value="accountant">Kế toán</option>
                    </select>
                </div>

                <div class="pt-3 flex gap-2">
                    <button type="button" onclick="document.getElementById('addUserModal').classList.add('hidden')" class="flex-1 py-2.5 rounded-xl border font-bold text-slate-600">Hủy</button>
                    <button type="submit" class="flex-1 py-2.5 rounded-xl bg-sky-600 hover:bg-sky-700 text-white font-bold">Tạo tài khoản</button>
                </div>
            </form>
        </div>
    </div>

    <!-- ================= JAVASCRIPT CONTROLLERS ================= -->
    <script>
        const aircraftSeatPresets = <?= json_encode(
            array_change_key_case(aircraft_seat_row_presets(), CASE_LOWER),
            JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT
        ) ?>;

        function updateSeatCountPreview() {
            const aircraft = document.getElementById('add_aircraft')?.value.trim().toLowerCase() || '';
            const preset = aircraftSeatPresets[aircraft] || { economy_rows: 10, business_rows: 2 };
            const preview = document.getElementById('seat_count_preview');
            if (preview) {
                preview.textContent = `Sức chứa mẫu: ${preset.economy_rows * 6} ghế Phổ thông, ${preset.business_rows * 6} ghế Thương gia.`;
            }
        }

        function applyAircraftSeatPreset() {
            updateSeatCountPreview();
        }

        updateSeatCountPreview();

        // 1. Mở modal sửa chuyến bay và điền dữ liệu
        function openEditFlightModal(flight) {
            document.getElementById('edit_f_id').value = flight.id;
            document.getElementById('edit_f_number').value = flight.flight_number;
            document.getElementById('edit_f_airline').value = flight.airline;
            document.getElementById('edit_f_aircraft').value = flight.aircraft || 'Airbus A321';
            document.getElementById('edit_f_stops').value = flight.stops || 0;
            document.getElementById('edit_f_from').value = flight.from_airport;
            document.getElementById('edit_f_to').value = flight.to_airport;
            
            // Format datetime-local
            if (flight.depart_at) {
                document.getElementById('edit_f_dep').value = flight.depart_at.replace(' ', 'T').substring(0, 16);
            }
            if (flight.arrive_at) {
                document.getElementById('edit_f_arr').value = flight.arrive_at.replace(' ', 'T').substring(0, 16);
            }
            document.getElementById('edit_f_price').value = flight.price_economy;
            document.getElementById('edit_f_business_price').value = flight.price_business;
            document.getElementById('edit_f_tax').value = flight.tax_price || 420000;
            document.getElementById('edit_f_status').value = flight.status || 'scheduled';
            document.getElementById('edit_f_gate').value = flight.gate || 'Gate 04';

            document.getElementById('editFlightModal').classList.remove('hidden');
        }

        // 2. Mở modal sửa trạng thái đơn đặt vé
        function openEditBookingModal(id, code, bookingStatus, paymentStatus) {
            document.getElementById('eb_id').value = id;
            document.getElementById('eb_code').textContent = code;
            const bookingStatusSelect = document.getElementById('eb_booking_status');
            const paymentStatusSelect = document.getElementById('eb_payment_status');
            if (bookingStatusSelect) bookingStatusSelect.value = bookingStatus;
            if (paymentStatusSelect) paymentStatusSelect.value = paymentStatus;
            document.getElementById('editBookingModal').classList.remove('hidden');
        }

        // 3. Xem chi tiết đơn vé qua API lookup
        function viewBookingDetail(code) {
            document.getElementById('v_booking_code').textContent = code;
            document.getElementById('v_loading').classList.remove('hidden');
            document.getElementById('v_content').classList.add('hidden');
            document.getElementById('viewBookingModal').classList.remove('hidden');

            fetch(`/WebsiteDatVe/api/bookings/lookup.php?code=${encodeURIComponent(code)}`)
                .then(res => res.json())
                .then(data => {
                    document.getElementById('v_loading').classList.add('hidden');
                    if (data.status === 'ok' && data.booking) {
                        const b = data.booking;
                        document.getElementById('v_contact_name').textContent = b.contact_name;
                        document.getElementById('v_contact_info').textContent = `${b.contact_phone} · ${b.contact_email}`;
                        document.getElementById('v_flight_info').textContent = `${b.flight_number} - ${b.airline}`;
                        document.getElementById('v_flight_route').textContent = `${b.from_city} (${b.from_airport}) ➔ ${b.to_city} (${b.to_airport}) · Khởi hành: ${b.depart_at}`;
                        
                        document.getElementById('v_pay_method').textContent = b.pay_method_label || '—';
                        document.getElementById('v_pay_status').textContent = b.payment_status_label;
                        document.getElementById('v_pay_status').className = 'font-extrabold mt-1 ' + (b.payment_status === 'paid' ? 'text-emerald-600' : 'text-amber-600');
                        document.getElementById('v_total_price').textContent = Number(b.total_price).toLocaleString('vi-VN') + 'đ';

                        // Render passengers
                        const tbody = document.getElementById('v_passengers_tbody');
                        tbody.innerHTML = '';
                        if (data.passengers && data.passengers.length > 0) {
                            data.passengers.forEach(p => {
                                const tr = document.createElement('tr');
                                tr.className = 'hover:bg-slate-50 transition';
                                tr.innerHTML = `
                                    <td class="p-3 font-bold text-slate-800">${p.first_name} ${p.last_name} <span class="text-slate-400 text-[10px]">(${p.passenger_type})</span></td>
                                    <td class="p-3 text-slate-600">${p.id_number || '—'}</td>
                                    <td class="p-3 text-center"><span class="px-2 py-0.5 rounded font-black bg-blue-50 text-blue-700">${p.seat_code || 'Chưa chọn'}</span></td>
                                    <td class="p-3 text-slate-600">${p.baggage_weight ? p.baggage_weight + 'kg' : 'Mặc định (20kg)'}</td>
                                    <td class="p-3 text-slate-600">${p.meal_name || 'Không'}</td>
                                    <td class="p-3 text-center"><span class="px-2 py-0.5 rounded-full text-[10px] font-bold ${p.checkin_status === 'Đã check-in' ? 'bg-emerald-50 text-emerald-600' : 'bg-slate-100 text-slate-500'}">${p.checkin_status}</span></td>
                                `;
                                tbody.appendChild(tr);
                            });
                        } else {
                            tbody.innerHTML = '<tr><td colspan="6" class="p-4 text-center text-slate-400">Chưa có thông tin hành khách chi tiết.</td></tr>';
                        }

                        document.getElementById('v_content').classList.remove('hidden');
                    } else {
                        alert(data.message || 'Không thể tải thông tin đơn đặt chỗ!');
                        document.getElementById('viewBookingModal').classList.add('hidden');
                    }
                })
                .catch(err => {
                    document.getElementById('v_loading').classList.add('hidden');
                    alert('Lỗi kết nối máy chủ!');
                    document.getElementById('viewBookingModal').classList.add('hidden');
                });
        }

        // 4. Lọc bảng Chuyến bay (Client-side instant filter)
        function filterFlightsTable() {
            const query = (document.getElementById('flightSearchInput')?.value || '').toLowerCase().trim();
            const rows = document.querySelectorAll('.flight-row');
            rows.forEach(r => {
                const search = r.getAttribute('data-search') || '';
                r.style.display = search.includes(query) ? '' : 'none';
            });
        }

        // 5. Lọc bảng Đơn đặt vé (Client-side instant filter)
        function filterBookingsTable() {
            const query = (document.getElementById('bookingSearchInput')?.value || '').toLowerCase().trim();
            const statusFilter = document.getElementById('bookingStatusFilter')?.value || '';
            const rows = document.querySelectorAll('.booking-row');
            rows.forEach(r => {
                const search = r.getAttribute('data-search') || '';
                const payst = r.getAttribute('data-paystatus') || '';
                const matchQuery = search.includes(query);
                const matchStatus = !statusFilter || payst === statusFilter;
                r.style.display = (matchQuery && matchStatus) ? '' : 'none';
            });
        }

        // 6. Lọc bảng Người dùng (Client-side instant filter)
        function filterUsersTable() {
            const query = (document.getElementById('userSearchInput')?.value || '').toLowerCase().trim();
            const rows = document.querySelectorAll('.user-row');
            rows.forEach(r => {
                const search = r.getAttribute('data-search') || '';
                r.style.display = search.includes(query) ? '' : 'none';
            });
        }
    </script>
</body>
</html>
