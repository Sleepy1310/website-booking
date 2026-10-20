<?php

function normalize_role(string $role): string
{
    return [
        'Quan_Tri_Vien' => 'super_admin',
        'Quan_Ly_Chuyen_Bay' => 'flight_manager',
        'Cham_Soc_Khach_Hang' => 'support',
        'Ke_Toan' => 'accountant',
        'Khach_Hang' => 'customer',
        'super_admin' => 'super_admin',
        'staff' => 'flight_manager',
        'support' => 'support',
        'accountant' => 'accountant',
        'customer' => 'customer',
    ][$role] ?? 'customer';
}

function database_role(string $role): string
{
    return [
        'super_admin' => 'Quan_Tri_Vien',
        'flight_manager' => 'Quan_Ly_Chuyen_Bay',
        'support' => 'Cham_Soc_Khach_Hang',
        'accountant' => 'Ke_Toan',
        'customer' => 'Khach_Hang',
    ][normalize_role($role)];
}

function current_role(mysqli $conn): ?string
{
    $userId = (int)($_SESSION['user']['id'] ?? 0);
    if ($userId <= 0) {
        return null;
    }

    $stmt = mysqli_prepare($conn, 'SELECT role, status FROM users WHERE id = ? LIMIT 1');
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare role lookup: ' . mysqli_error($conn));
    }
    mysqli_stmt_bind_param($stmt, 'i', $userId);
    if (!mysqli_stmt_execute($stmt)) {
        $error = mysqli_stmt_error($stmt);
        mysqli_stmt_close($stmt);
        throw new RuntimeException('Unable to load current role: ' . $error);
    }
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);

    if (!$row) {
        unset($_SESSION['user']);
        return null;
    }

    if (($row['status'] ?? 'Hoat_Dong') !== 'Hoat_Dong') {
        unset($_SESSION['user']);
        return null;
    }

    $role = normalize_role($row['role']);
    $_SESSION['user']['role'] = $role;
    return $role;
}

function has_permission(mysqli $conn, string $permission): bool
{
    $role = current_role($conn);
    if ($role === null) {
        return false;
    }
    if ($role === 'super_admin') {
        return true;
    }
    if ($permission === 'permission_grant') {
        return false;
    }

    $rolePermissions = [
        'flight_manager' => [
            'flights_view',
            'flight_schedule_manage',
            'flight_status_manage',
        ],
        'support' => [
            'customers_view',
            'bookings_view',
            'passenger_edit',
        ],
        'accountant' => [
            'dashboard_view',
            'bookings_view',
            'revenue_view',
            'payment_status_manage',
        ],
        'customer' => [],
    ];
    if (in_array($permission, $rolePermissions[$role] ?? [], true)) {
        return true;
    }

    if (!in_array($role, ['flight_manager', 'support', 'accountant'], true)) {
        return false;
    }

    $userId = (int)$_SESSION['user']['id'];
    $stmt = mysqli_prepare($conn,
        'SELECT 1 FROM user_permission_grants
         WHERE user_id = ? AND permission_key = ?
           AND revoked_at IS NULL AND expires_at > NOW()
         LIMIT 1'
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare permission grant lookup: ' . mysqli_error($conn));
    }
    mysqli_stmt_bind_param($stmt, 'is', $userId, $permission);
    if (!mysqli_stmt_execute($stmt)) {
        $error = mysqli_stmt_error($stmt);
        mysqli_stmt_close($stmt);
        throw new RuntimeException('Unable to load temporary permission grant: ' . $error);
    }
    $result = mysqli_stmt_get_result($stmt);
    $granted = $result && mysqli_fetch_row($result) !== null;
    mysqli_stmt_close($stmt);

    return $granted;
}

function require_permission(mysqli $conn, string $permission): void
{
    if (!has_permission($conn, $permission)) {
        json_response(['status' => 'error', 'message' => 'Bạn không có quyền thực hiện thao tác này.'], 403);
    }
}

function role_label(string $role): string
{
    return [
        'super_admin' => 'Super Admin',
        'flight_manager' => 'Quản lý chuyến bay',
        'support' => 'Chăm sóc khách hàng',
        'accountant' => 'Kế toán',
        'customer' => 'Khách hàng',
    ][$role] ?? 'Không xác định';
}

function require_admin_page_permission(mysqli $conn, string $permission, string $tab): void
{
    if (!has_permission($conn, $permission)) {
        $_SESSION['admin_msg'] = 'Vai trò của bạn không được phép thực hiện thao tác này.';
        $_SESSION['admin_msg_type'] = 'error';
        header('Location: /WebsiteDatVe/admin/admin.php?tab=' . urlencode($tab));
        exit;
    }
}
