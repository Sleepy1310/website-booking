# Website đặt vé máy bay

Đồ án website đặt vé máy bay xây dựng bằng PHP và MySQL/MariaDB. Khách hàng có thể tìm chuyến bay, chọn hạng vé, nhập thông tin hành khách và tạo đặt chỗ; nhân viên có thể quản lý các nghiệp vụ theo vai trò được cấp.

## Chức năng chính

- Đăng ký, đăng nhập và quản lý tài khoản.
- Tìm chuyến bay, xem giá vé Phổ thông/Thương gia và tình trạng chuyến bay.
- Đặt vé, nhập thông tin hành khách và chọn dịch vụ.
- Tra cứu đặt chỗ và làm thủ tục check-in.
- Quản trị chuyến bay, đơn đặt vé và tài khoản theo quyền.

## Công nghệ

- PHP 8+
- MySQL/MariaDB
- XAMPP (Apache và MySQL)
- Tailwind CSS, HTML, JavaScript

## Cài đặt trên máy local

1. Cài XAMPP, khởi động **Apache** và **MySQL**.
2. Đặt project vào thư mục `C:\xampp\htdocs\WebsiteDatVe`.
3. Mở phpMyAdmin tại `http://localhost/phpmyadmin`.
4. Tạo database tên `websitedatve` với collation `utf8mb4_unicode_ci`.
5. Chọn database vừa tạo, vào **Import**, chọn `database/websitedatve.sql` trong project và chạy import.
6. Mở `http://localhost/WebsiteDatVe/`.

File SQL là bản dump gồm cấu trúc và dữ liệu mẫu. Chỉ import vào database mới/rỗng để tránh lỗi trùng bảng hoặc dữ liệu. Không cần chạy thêm các file migration nếu dùng bản dump đầy đủ.

## Cấu hình kết nối

Cấu hình MySQL nằm trong `api/config/database.php`. Mặc định dành cho XAMPP local:

- Host: `localhost`
- Database: `websitedatve`
- User: `root`
- Password: rỗng

Nếu cấu hình MySQL trên máy khác không giống mặc định, cập nhật các giá trị kết nối trong file này.

## Tài liệu database

Xem [database/README.md](database/README.md) để biết thêm về các bảng, cách export/import database và lưu ý chuẩn bị dữ liệu mẫu.

## Lưu ý

Database trong repository chỉ nên chứa dữ liệu giả lập. Không đưa mật khẩu đang sử dụng hoặc thông tin cá nhân thật vào repository công khai. Các chức năng thanh toán trong đồ án là môi trường mô phỏng, không dùng để xử lý giao dịch thật.
