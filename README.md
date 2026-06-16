# HRM Portal

HRM Portal là cổng tra cứu nội bộ cho nhân viên xem bảng công, phiếu lương và các khoản thu nhập theo từng kỳ. Dự án được viết bằng PHP thuần, dễ triển khai trên hosting phổ thông, VPS, Apache/Nginx hoặc Docker.

## Dành cho ai?

- Nhân viên: đăng nhập bằng mã nhân viên và mật khẩu để xem dữ liệu cá nhân.
- Nhân sự/kế toán: tải lên file Excel/CSV hoặc kết nối Google Sheets để quản lý dữ liệu lương.
- Quản trị viên: cấu hình kỳ lương, cột dữ liệu, giao diện, file xác thực và bảo mật trong Admin Panel.

## Tính năng chính

- Tra cứu bảng lương theo mã nhân viên.
- Hỗ trợ nhiều kỳ lương, bật/tắt từng kỳ riêng biệt.
- Nguồn dữ liệu linh hoạt: Google Sheets hoặc file local `.xlsx`/`.csv`.
- Quản lý danh sách nhân viên và file xác thực trong Admin Panel.
- Tự động backup trước khi cập nhật dữ liệu quan trọng.
- Mã hóa file local nếu cấu hình `APP_FILE_ENCRYPTION_KEY`.
- Chống CSRF, session timeout, header bảo mật và kiểm soát file upload.
- Có PHPUnit test, smoke test và tài liệu vận hành kèm theo.

## Cấu trúc nhanh

```text
.
├── index.php                 # Trang nhân viên tra cứu
├── admin.php                 # Trang quản trị
├── api/                      # API phụ trợ
├── assets/                   # CSS/JS giao diện
├── config/                   # Cấu hình ứng dụng
├── docs/                     # Tài liệu chi tiết
├── scripts/                  # Công cụ vận hành/migration
├── src/                      # Mã nguồn PHP chính
├── tests/                    # PHPUnit tests
├── uploads/                  # File Excel/CSV tải lên (không commit)
└── runtime/                  # Cache/share/runtime data (không commit)
```

## Yêu cầu

- PHP 7.4 trở lên, khuyến nghị PHP 8.x.
- Extension PHP `zip` nếu cần ghi file XLSX.
- Quyền ghi cho thư mục `uploads/` và `runtime/`.
- Composer nếu muốn chạy test hoặc cài dependency dev.

## Chạy local nhanh

1. Cài dependency dev:

```bash
composer install
```

2. Tạo cấu hình ban đầu nếu chưa có:

```bash
cp config/hr_config.json.example config/hr_config.json
```

3. Tạo file `.env` ở thư mục gốc:

```env
APP_FILE_ENCRYPTION_KEY=
```

Có thể tạo khóa mã hóa bằng:

```bash
php scripts/generate_file_encryption_key.php
```

Sau đó copy dòng khóa được in ra vào `.env`.

4. Chạy server local:

```bash
php -S localhost:8000
```

5. Mở trình duyệt:

- Trang nhân viên: `http://localhost:8000/index.php`
- Trang quản trị: `http://localhost:8000/admin.php`

## Chạy bằng Docker

```bash
docker compose up --build
```

Sau đó mở:

```text
http://localhost:8080
```

## Cấu hình dữ liệu

Toàn bộ cấu hình chính nằm trong:

```text
config/hr_config.json
```

Ứng dụng nạp cấu hình theo thứ tự:

1. Giá trị mặc định trong `src/Config.php`
2. `config/hr_config.json`
3. Biến môi trường trong `.env` hoặc môi trường hệ thống

Trong Admin Panel, bạn có thể cấu hình:

- Nguồn xác thực nhân viên.
- Danh sách kỳ lương.
- File Excel/CSV hoặc Google Sheet cho từng kỳ.
- Các cột hiển thị, cột tiền, cột nổi bật.
- Thông tin thương hiệu hiển thị ở trang nhân viên.
- Mật khẩu Admin.
- Cấu hình bảng công và chia sẻ phiếu lương.

## Quy trình sử dụng cơ bản

1. Truy cập `admin.php` và đăng nhập.
2. Cấu hình nguồn xác thực nhân viên: Google Sheets hoặc file Excel.
3. Tạo kỳ lương mới.
4. Chọn nguồn dữ liệu cho kỳ lương: Google Sheets hoặc upload `.xlsx`/`.csv`.
5. Nhập tên các cột cần hiển thị.
6. Bấm lưu cấu hình.
7. Nhân viên vào `index.php`, nhập mã nhân viên và mật khẩu để tra cứu.

## Bảo mật cần biết

Các dữ liệu sau không nên commit lên Git:

- `.env`
- `vendor/`
- `uploads/`
- `runtime/`
- file log, cache, file backup, file Excel thật

Repo đã có `.gitignore` để loại các dữ liệu này.

Khuyến nghị khi deploy:

- Luôn đặt `APP_FILE_ENCRYPTION_KEY` trong `.env`.
- Không để web server public trực tiếp thư mục `config/`, `runtime/`, `scripts/`.
- Đảm bảo `uploads/.htaccess` có hiệu lực nếu dùng Apache.
- Đổi mật khẩu Admin ngay sau khi cài đặt.
- Đọc checklist bảo mật tại `docs/SECURE_DEPLOYMENT_CHECKLIST.md`.

## Kiểm tra chất lượng

Chạy smoke test:

```bash
php scripts/smoke_test.php
```

Chạy PHPUnit:

```bash
./vendor/bin/phpunit
```

Hoặc chạy bằng Composer:

```bash
composer check
```

## Mã hóa file đã upload

1. Tạo khóa:

```bash
php scripts/generate_file_encryption_key.php
```

2. Thêm khóa vào `.env`.

3. Kiểm tra migration trước:

```bash
php scripts/migrate_encrypted_storage.php --dry-run
```

4. Chạy migration thật:

```bash
php scripts/migrate_encrypted_storage.php
```

## Tài liệu chi tiết

- Hướng dẫn Admin: `docs/ADMIN_GUIDE.md`
- Kiến trúc hệ thống: `docs/ARCHITECTURE.md`
- Hướng dẫn phát triển: `docs/DEVELOPMENT.md`
- Vận hành production: `docs/OPERATIONS.md`
- Checklist deploy an toàn: `docs/SECURE_DEPLOYMENT_CHECKLIST.md`
- Chính sách bảo mật: `docs/SECURITY_POLICY.md`

## Deploy production

Tối thiểu cần chuẩn bị:

- PHP runtime ổn định.
- Web root trỏ vào thư mục dự án.
- `.env` có khóa mã hóa.
- `uploads/` và `runtime/` có quyền ghi.
- Backup định kỳ cho `config/`, `uploads/` và dữ liệu production.
- HTTPS trước khi cho nhân viên sử dụng thật.

Với VPS hoặc hosting có Apache/Nginx, có thể deploy như PHP app thông thường. Với Docker, dùng `docker-compose.yml` có sẵn làm điểm bắt đầu.

## License

Xem file `LICENSE`.
