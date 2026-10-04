# Jankx E-Invoice

Tự động cấp **hóa đơn điện tử** cho đơn hàng trong `base-ecommerce`, theo hồ sơ pháp lý
từng quốc gia. Mặc định là hồ sơ Việt Nam; các quốc gia khác dùng hồ sơ chung cho tới
khi bổ sung profile riêng.

Extension tự hoạt động khi kích hoạt: bắt sự kiện đơn hàng, dựng hóa đơn bất biến, gửi
email, hiển thị trong My Account, và cung cấp REST + trang quản trị.

---

## ⚠️ Cảnh báo về pháp lý — đọc trước khi dùng thật

1. **Extension này KHÔNG tự gửi hóa đơn tới cơ quan thuế.** Ở Việt Nam, hóa đơn điện tử
   có mã của cơ quan thuế chỉ hợp lệ khi dữ liệu đã được gửi và lưu trữ bởi một **nhà
   cung cấp hóa đơn điện tử hợp lệ**. Extension này chỉ tạo và lưu trữ bản kê.
2. **Mã số thuế người bán là bắt buộc** trên mọi hóa đơn Việt Nam. Trang cài đặt hiện cảnh
   báo nếu để trống, nhưng extension vẫn cho phát hành — trách nhiệm thuộc về người bán.
3. **Văn bản pháp lý dưới đây chưa được đối chiếu trực tiếp với nguồn chính thức** khi
   phát triển (nguồn `thuvienphapluat.vn` trả `403`). Trước khi dùng production, hãy đối
   chiếu với văn bản gốc và ý kiến kế toán của bạn:

   | Văn bản | Nội dung liên quan |
   | --- | --- |
   | Nghị định 254/2026/NĐ-CP | Hóa đơn, chứng từ; người bán hộ kinh doanh phải ghi *mã, địa chỉ địa điểm kinh doanh* |
   | Thông tư 91/2026/TT-BTC | Hướng dẫn về hóa đơn, chứng từ |
   | Luật Thuế TNCN 54/2024/QH15 | Nghĩa vụ kê khai, cấp phép hóa đơn |
   | Luật Kế toán 108/2025/QH15 | Hóa đơn là chứng từ kế toán |
   | Thông tư 99/2025/TT-BTC | Hướng dẫn kế toán doanh nghiệp nhỏ và vừa |

4. **Thời điểm lập hóa đơn.** Đối với hàng hóa thường, hóa đơn thường phải lập **tại thời
   điểm giao hàng**. Mặc định extension phát hành khi đơn `completed`; đó là quyết định
   nghiệp vụ, không phải khẳng định pháp lý. Hãy chọn trigger khớp với quy trình vận
   chuyển của bạn.

---

## Yêu cầu

| Thành phần | Bắt buộc | Ghi chú |
| --- | --- | --- |
| PHP | ≥ 7.4 | Đã kiểm thử với 8.1 |
| WordPress | 5.9+ | Dùng `register_rest_route`, `wp_get_upload_dir` |
| `base-ecommerce` | ✅ | Extension sẽ không kích hoạt nếu thiếu |
| `dompdf/dompdf` | ❌ | Tùy chọn — bật xuất PDF |

Không có Dompdf, hóa đơn vẫn xuất HTML tối ưu cho in và gửi kèm email.

## Cài đặt

Extension nằm ở `extensions/e-invoice/` và được theme loader tự động phát hiện. Kích hoạt
trong *Theme → Extensions*, hoặc để `auto_activate: true` lo.

Khi kích hoạt, extension tạo hai bảng:

- `{prefix}jankx_invoices` — bản ghi hóa đơn (mỗi dòng là một hóa đơn đã phát hành)
- `{prefix}jankx_invoice_sequences` — bộ đếm số hóa đơn theo năm

Và tạo sẵn vài giá trị mặc định (ký hiệu `HD`, mẫu số `01`) **chỉ khi chưa có cấu hình nào** —
không bao giờ ghi đè cài đặt của bạn.

## Cài đặt nhanh

1. *Ecommerce → Cài đặt → Hóa đơn điện tử*
2. Bật *Cho phép xuất hóa đơn*
3. Điền **Mã số thuế người bán** (bắt buộc)
4. Chọn thời điểm xuất: `on_payment` / `on_shipping` / `on_completed`
5. Chọn quốc gia: để trống để tự nhận diện, hoặc chọn `VN`
6. Lưu

Danh sách hóa đơn: menu *Hóa đơn* trong trong quản trị.

## Thời điểm phát hành

| Trigger | Bắn khi |
| --- | --- |
| `on_payment` | Cổng thanh toán xác nhận thành công (`jankx/ecommerce/payment/paid`) |
| `on_shipping` | Đơn chuyển sang `shipping` |
| `on_completed` | Đơn chuyển sang `completed` *(mặc định)* |

Phát hành là **idempotent**: mỗi đơn chỉ nhận một hóa đơn (ràng buộc UNIQUE trên
`order_invoice_key`). Nếu hóa đơn không cân bằng (`validate()` trả về vấn đề), nó **không**
được lưu và không gửi email — lỗi được ghi log thay vì phát hành hóa đơn sai.

`OrderAdmin` cập nhật status trực tiếp qua `OrderModel::update()`, **bỏ qua** hook
`order/status_changed`. Vì vậy extension cũng lắng nghe `admin_init` trên màn hình đơn hàng
để đối soát lại sau khi redirect, thay vì tin vào hook.

## Hóa đơn là bản chụp bất biến

`jankx_orders` chỉ lưu **một** con số `total` đã bao gồm thuế, và không lưu thuế suất,
chiến lược giá, mã giảm giá hay tỷ giá tại thời điểm đó. Nếu tính lại từ dữ liệu sống, hóa
đơn cũ sẽ thay đổi — vi phạm yêu cầu lưu giữ chứng từ kế toán.

Vì vậy khi phát hành, extension chụp lại toàn bộ: tỷ giá thuế, chiến lược, dòng hàng,
chiết khấu, tổng, và thông tin hai bên. **Không có gì sau đó được tính lại từ cài đặt.**

Chi tiết này nằm trong `src/Snapshot/OrderSnapshotFactory.php`.

## Làm tròn

Tiền tệ VND có 0 chữ số thập phân (`Amount::decimalsFor()`). Với các dòng hàng nhiều, tổng
tính từ dòng có thể lệch vài đồng so với tổng đơn hàng. `AbstractInvoiceProfile::reconcileLines()`
xử lý việc này bằng `Amount::distribute()` — phân bổ chênh lệch theo trọng số để **tổng các
dòng luôn khớp đúng tổng hóa đơn**, thay vì làm tròn từng dòng và hy vọng tổng khớp.

## Hồ sơ quốc gia (Strategy)

```
InvoiceProfileInterface
├── VietnamInvoiceProfile   (vn)
└── GenericInvoiceProfile   (generic) — dự phòng an toàn
```

`InvoiceProfileRegistry::get_instance()` chọn profile theo quốc gia của cửa hàng, có filter:

```php
add_filter('jankx/einvoice/profiles', function (array $profiles): array {
    $profiles['JP'] = new MyJapanInvoiceProfile();
    return $profiles;
});
```

Profile quyết định: mẫu số, ký hiệu, nhãn hiển thị, viết số thành chữ, cách gom nhóm thuế,
tên người mua khi không xác định, và các trường bắt buộc.

Profile `generic` **không** phải là hồ sơ pháp lý. Trang cài đặt hiển thị cảnh báo khi
profile đang dùng là `generic`.

### Thêm một quốc gia

```php
use Jankx\Extensions\EInvoice\Profile\AbstractInvoiceProfile;

class JapanInvoiceProfile extends AbstractInvoiceProfile
{
    public function getId(): string    { return 'jp'; }
    public function getCountryCode(): string { return 'JP'; }
    public function getCountryName(): string { return 'Japan'; }
    public function getLocale(): string { return 'ja'; }
    public function getCurrency(): string { return 'JPY'; }
    public function getLegalBasis(): array { return ['Loi ...']; }
}
```

`AbstractInvoiceProfile` xử lý toàn bộ phần khó (tổng, thuế, làm tròn, số tiền bằng chữ);
profile con chỉ khai báo danh tính và nhãn. Thêm `views/jp.php` nếu cần bố cục riêng.

## Giao diện cho người dùng

- **Email** — `wp_mail()` trực tiếp, kèm file tài liệu. Thân email luôn là HTML; khi
  renderer là PDF, bản HTML được dựng riêng (không nhét byte PDF vào MIME `text/html`).
- **My Account** — panel trong chi tiết đơn hàng, qua filter
  `jankx/ecommerce/order_detail/after_payment_info`.
- **REST** — namespace `jankx/e-invoice/v1`:

  | Route | Mô tả |
  | --- | --- |
  | `GET /invoices` | Hóa đơn của người gọi (nhân viên thấy tất cả) |
  | `GET /invoices/<id>` | Chi tiết một hóa đơn |
  | `GET /invoices/<id>/view` | Xem trong trình duyệt (`Content-Disposition: inline`) |
  | `GET /invoices/<id>/download` | Tải xuống |

> `base-ecommerce` gửi email qua `notification-system`, mà `EmailChannel` gọi
> `wp_mail()` với 4 tham số nên **không đính kèm được file**. Hóa đơn phải đi kèm tài
> liệu, nên `InvoiceMailer` gọi `wp_mail()` trực tiếp.

## Bảo mật

- Endpoint đọc yêu cầu đăng nhập; quyền được kiểm tra theo từng hóa đơn (chủ sở hữu qua
  `buyer_email` đã chụp, hoặc nhân viên có `manage_woocommerce`).
- Trả `404` thay vì `403` khi không có quyền, để không tiết lộ hóa đơn tồn tại hay không.
- File tài liệu lưu trong `uploads/jankx-invoices/`, có `index.php` và `.htaccess`
  chặn truy cập trực tiếp — chỉ đọc được qua REST đã kiểm tra quyền.
- Mọi dữ liệu đầu vào qua `sanitize_text_field` / `absint` / `esc_html` / `esc_url`;
  truy vấn dùng `$wpdb->prepare()`.

> Bảng hóa đơn chứa dữ liệu cá nhân và mã số thuế. Khi sao lưu hoặc dọn dữ liệu, hãy xử
> lý như dữ liệu tài chính nhạy cảm.

## Filter

| Filter | Mục đích |
| --- | --- |
| `jankx/einvoice/profiles` | Đăng ký profile quốc gia |
| `jankx/einvoice/renderers` | Đăng ký renderer (`['html' => ..., 'dompdf' => ...]`) |
| `jankx/einvoice/renderer` | Ép dùng renderer cụ thể |
| `jankx/einvoice/seller_identity` | Sửa thông tin người bán in ra hóa đơn |
| `jankx/einvoice/mail_args` | Sửa tham số email |
| `jankx/einvoice/mail_subject` | Sửa tiêu đề email |
| `jankx/einvoice/mail_html` | Sửa toàn bộ HTML email |

| Action | Thời điểm |
| --- | --- |
| `jankx/einvoice/mail_sent` | Sau khi `wp_mail()` nhận email |
| `jankx/einvoice/ready` | Khi mọi service đã sẵn sàng |

## Ghi chú về mã số

Extension không tự ký số điện tử. Trường `signed_at` mặc định bằng `issued_at`; nếu tích hợp
nhà cung cấp, hãy cập nhật qua repository.

## Cấu trúc

```
e-invoice/
├── EInvoiceExtension.php          # Điểm vào: autoloader + wiring
├── manifest.json
├── views/
│   ├── vn.php                      # Bố cục Việt Nam
│   └── generic.php                 # Bố cục chung
└── src/
    ├── Contracts/                  # Interface
    ├── Model/                      # Invoice, dòng, bên, tổng, schema
    ├── Numbering/                  # Cấp số nguyên tử theo năm
    ├── Profile/                    # Strategy + Template Method
    ├── Repository/                 # Truy cập DB
    ├── Snapshot/                   # Chụp dữ liệu tại thời điểm phát hành
    ├── Service/                    # Phát hành, dựng tài liệu
    ├── Render/                     # HTML, Dompdf, template loader
    ├── Mail/ Account/ Rest/ Admin/ Listener/
    └── Support/                    # Tiền tệ, làm tròn, số tiền bằng chữ
```

Ghi đè giao diện: đặt `views/<mã-quốc-gia>.php` vào thư mục con dùng `InvoiceTemplateLoader`
để thay thế bố cục mà không cần sửa extension.