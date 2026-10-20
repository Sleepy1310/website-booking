# Hướng dẫn cài đặt cơ sở dữ liệu

Website sử dụng MySQL/MariaDB và mặc định kết nối đến database `websitedatve`.

## Các bảng chính

- `users`: tài khoản, mật khẩu đã băm và vai trò.
- `airports`: sân bay.
- `flights`: chuyến bay, giá vé và số ghế còn.
- `flight_seats`: ghế trên từng chuyến bay.
- `bookings`: đơn đặt vé.
- `passengers`: hành khách và dịch vụ đi kèm.
- `payments`: trạng thái thanh toán.
- `promotions`: mã khuyến mãi.
- `user_permission_grants`: quyền bổ sung có thời hạn cho nhân viên.

Một số quan hệ quan trọng: `flights` liên kết với `airports`; `bookings` liên kết với `users`, `flights` và `promotions`; `passengers` và `payments` thuộc về `bookings`; `flight_seats` thuộc về `flights`.

## Tạo file SQL để gửi kèm đồ án

Hiện thư mục dự án chưa có bản export đầy đủ. File migration riêng (nếu có) không thay thế cho bản export database.

1. Mở XAMPP Control Panel và khởi động Apache, MySQL.
2. Mở `http://localhost/phpmyadmin` và chọn database `websitedatve`.
3. Chọn **Export**. Nếu cần tùy chỉnh, chọn **Custom**, định dạng **SQL**, và bao gồm cả **Structure** và **Data**.
4. Nếu phpMyAdmin có tùy chọn export routines, hãy bao gồm stored procedure mà ứng dụng cần. `sp_generate_seats` được gọi khi tạo chuyến bay để sinh ghế.
5. Lưu file export tại `database/websitedatve.sql`.
6. Thử import file vào một database rỗng tên `websitedatve` trước khi gửi cho giảng viên.

## Import database trên XAMPP

1. Khởi động Apache và MySQL.
2. Trong phpMyAdmin, tạo database mới tên `websitedatve`, collation `utf8mb4_unicode_ci`.
3. Chọn database vừa tạo, vào tab **Import**, chọn file `database/websitedatve.sql` và bấm **Import/Go**.
4. Kiểm tra các bảng đã được tạo. Không import file dump lặp lại vào database đã có bảng; việc này có thể gây lỗi trùng bảng/dữ liệu.
5. Chép project vào `C:\xampp\htdocs\WebsiteDatVe` và mở `http://localhost/WebsiteDatVe/`.

## Cấu hình kết nối

Cấu hình hiện tại nằm trong `api/config/database.php`:

- Host: `localhost`
- Database: `websitedatve`
- User: `root`
- Password: rỗng (mặc định XAMPP)

## Kiểm tra sau khi cài

- Mở trang chủ và tìm thử chuyến bay.
- Đăng ký tài khoản demo hoặc đăng nhập bằng tài khoản demo đã tạo.
- Thử tạo đặt chỗ và tra cứu tình trạng chuyến bay.
- Thử tạo một chuyến bay từ trang quản trị để kiểm tra stored procedure tạo ghế.

