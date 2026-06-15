# 🎨 Admin Panel - Hướng Dẫn Sử Dụng

## 📋 Mục lục
1. [Đăng nhập](#đăng-nhập)
2. [Quản lý Kỳ Lương](#quản-lý-kỳ-lương)
3. [Quản lý File](#quản-lý-file)
4. [Cấu hình Xác thực](#cấu-hình-xác-thực)
5. [Giao Diện & Thương Hiệu](#giao-diện--thương-hiệu)
6. [Cấu hình Cột Dữ Liệu](#cấu-hình-cột-dữ-liệu)
7. [Đổi Mật khẩu](#đổi-mật-khẩu)

---

## 🔐 Đăng nhập

1. Truy cập trang **Admin** (`admin.php`)
2. Nhập **mật khẩu Admin** được cấu hình
3. Nhấp **Đăng Nhập**

**Lưu ý**: Phiên làm việc Admin hết hạn sau **30 phút** không hoạt động.

---

## 📅 Quản lý Kỳ Lương

Tại đây bạn có thể tạo/chỉnh sửa các kỳ lương (tháng) để nhân viên tra cứu.

### Thêm Kỳ Lương Mới
1. Nhấp **+ Thêm kỳ mới**
2. Điền **Tên kỳ** (VD: "Tháng 10/2026")
3. Chọn **Loại nguồn dữ liệu**:
   - **Google Sheets** ☁️: Dữ liệu từ Google Sheets
   - **File Excel** 💾: Dữ liệu từ file Excel tải lên

### Nếu chọn Google Sheets
- Dán **link Google Sheet** để tự động trích xuất
- Hoặc nhập thủ công **Sheet ID** và **GID**
- Cấu hình **Cột hiển thị**: Chọn các cột cần hiển thị
- Cấu hình **Cột Pill** (highlight): Các cột nổi bật
- Cấu hình **Cột Tiền** (VND): Các cột định dạng tiền tệ

### Nếu chọn File Excel
- Tải **file Excel/CSV**
- Chỉ định **Sheet index** (0 = sheet đầu tiên)
- Cấu hình các cột tương tự như Google Sheets

### Cấu hình Ngày Mở Xem
- Để **trống** = Mở xem ngay
- Chỉ định **ngày/giờ** = Mở xem lúc đó

---

## 📁 Quản lý File

Xem danh sách tất cả **file Excel được tải lên**:
- **Tên File**: Tên file
- **Dung lượng**: Kích thước file (KB)
- **Ngày tạo**: Thời gian tải lên
- **Trạng thái**: 
  - 🟢 **Dùng**: File đang được sử dụng
  - ⚪ **Trống**: File không sử dụng
- **Xóa**: Nút xóa file (nhấp 🗑️)

**Lưu ý**: Chỉ xóa file không sử dụng để tránh làm hỏng dữ liệu.

---

## 🔐 Cấu hình Xác thực

Tại đây cấu hình **nguồn dữ liệu xác thực** cho nhân viên đăng nhập.

### Google Sheets (Mặc định)
1. Chọn **☁️ Google Sheets**
2. Dán **link Google Sheet**
3. System tự động trích xuất **Sheet ID** và **GID**
4. Hoặc nhập thủ công

### File Excel
1. Chọn **💾 File Excel (.xlsx/.csv)**
2. Tải lên **file xác thực**
3. File sẽ được lưu trong thư mục `/uploads/`

**Yêu cầu dữ liệu**:
- **Cột Mã NV**: ID nhân viên
- **Cột Mật khẩu**: Mật khẩu (có thể plain-text hoặc hash)

---

## 🎨 Giao Diện & Thương Hiệu

Tùy chỉnh giao diện công khai theo yêu cầu công ty.

### Thông Tin Công Ty
- **Tên công ty**: Tên hiển thị trên ứng dụng
- **Logo (3 ký tự)**: Logo viết tắt (VD: HR, VT)

### Nội Dung Trang Chính
- **Phụ đề (Subtitle)**: Dòng chữ dưới tên công ty
- **Tiêu đề chính (Hero Title)**: Tiêu đề lớn trên banner
- **Mô tả (Hero Description)**: Mô tả chi tiết dưới tiêu đề

### Chân Trang (Footer)
- **Footer Text**: Văn bản ở cuối trang (VD: © 2026...)

---

## 📋 Cấu hình Cột Dữ Liệu

Đặt tên các cột hiển thị trong bảng lương theo tên trong file dữ liệu.

### Cột Xác Thực
- **Mã Nhân Viên**: Tên cột chứa ID nhân viên
- **Mật khẩu**: Tên cột chứa mật khẩu

### Thông Tin Nhân Viên
- **Họ Tên Nhân Viên**: Tên cột chứa họ tên
- **Bộ Phận**: Tên cột chứa bộ phận/phòng ban

### Cột Thống Kê (Summary)
- **Các cột Thống kê**: Danh sách các cột hiển thị ở phần tóm tắt
- Cách nhau bằng dấu phẩy (`,`)
- VD: `NGÀY CÔNG, GIỜ TĂNG CA, THỰC LÃNH, TỔNG LƯƠNG`

---

## 🔑 Đổi Mật Khẩu

Cập nhật mật khẩu bảo vệ trang Admin.

### Quy trình
1. Nhập **mật khẩu Admin mới**
2. Mật khẩu sẽ được **hash** bằng `PASSWORD_DEFAULT`
3. Nhấp **Cập Nhật Mật Khẩu**

### ⚠️ Lưu ý Quan Trọng
- 💾 **Nhớ mật khẩu** hoặc lưu ở nơi an toàn
- 🔒 **Không chia sẻ** mật khẩu với ai
- 📝 Nếu quên, sẽ cần truy cập file config trực tiếp

---

## 💡 Mẹo & Thủ Thuật

### Sao chép Link Google Sheets
1. Mở **Google Sheet**
2. Nhấp **Share** → **Copy Link**
3. Dán vào trường **Paste link...**

### Định dạng File Excel
- Hỗ trợ: `.csv`, `.xls`, `.xlsx`
- Tối đa: **10MB**
- Tối đa **50,000 dòng** và **1,000 cột**

### Thứ tự Hiện Tại
- Các kỳ lương **sắp xếp từ trên xuống**
- Xóa bằng nút **Xóa** trên mỗi hàng

---

## 🆘 Xử lý Sự Cố

### Trang Admin không hiển thị
- Kiểm tra **phiên làm việc** (hết hạn sau 30 phút)
- Đăng nhập lại

### Không thể tải file
- Kiểm tra **kích thước file** (< 10MB)
- Kiểm tra **định dạng** (CSV, XLS, XLSX)
- Kiểm tra **quyền ghi** vào thư mục `/uploads/`

### Cột không hiển thị đúng
- Kiểm tra **tên cột** trong file dữ liệu
- Đảm bảo **chính xác** (phân biệt chữ hoa/thường)

---

## 📞 Hỗ Trợ

Nếu gặp vấn đề, kiểm tra **Diagnostics** (`diagnostics.php`) để xem log lỗi.

**Version**: Admin Panel v2.0 (2026)
**Last Updated**: April 9, 2026
