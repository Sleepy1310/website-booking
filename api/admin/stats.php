<?php
/**
 * API ADMIN: Thống kê tổng quan dashboard
 * GET /api/admin/stats.php
 */
require_once dirname(__FILE__, 2) . '/config/database.php';
require_once dirname(__FILE__, 2) . '/config/bootstrap.php';

if (!$conn) {
    json_response(['status' => 'error', 'message' => 'Không thể kết nối cơ sở dữ liệu!'], 500);
}
require_permission($conn, 'revenue_view');

$stats = [];

// Tổng chuyến bay
$r = mysqli_query($conn, "SELECT COUNT(*) AS cnt FROM flights"); 
$stats['total_flights'] = (int)mysqli_fetch_assoc($r)['cnt'];

// Vé bán hôm nay
$r = mysqli_query($conn, "SELECT COALESCE(SUM(num_passengers),0) AS cnt FROM bookings WHERE DATE(created_at)=CURDATE() AND payment_status='paid'");
$stats['today_tickets'] = (int)mysqli_fetch_assoc($r)['cnt'];

// Tổng doanh thu (từ payments thành công)
$r = mysqli_query($conn, "SELECT COALESCE(SUM(amount),0) AS total FROM payments WHERE status='success'");
$stats['total_revenue'] = (int)mysqli_fetch_assoc($r)['total'];

// Tổng khách hàng
$r = mysqli_query($conn, "SELECT COUNT(*) AS cnt FROM users WHERE role='Khach_Hang'");
$stats['total_users'] = (int)mysqli_fetch_assoc($r)['cnt'];

// Tổng đơn đặt
$r = mysqli_query($conn, "SELECT COUNT(*) AS cnt FROM bookings");
$stats['total_bookings'] = (int)mysqli_fetch_assoc($r)['cnt'];

// Đơn tháng này
$r = mysqli_query($conn, "SELECT COUNT(*) AS cnt, COALESCE(SUM(total_price),0) AS rev FROM bookings WHERE MONTH(created_at)=MONTH(NOW()) AND YEAR(created_at)=YEAR(NOW()) AND payment_status='paid'");
$m = mysqli_fetch_assoc($r);
$stats['month_bookings'] = (int)$m['cnt'];
$stats['month_revenue']  = (int)$m['rev'];

json_response(['status' => 'ok', 'data' => $stats]);
