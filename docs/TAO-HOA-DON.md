# Cách tạo hóa đơn điện tử

Tài liệu thao tác cho người quản trị. Mục tiêu: **làm sao để một đơn hàng có hóa đơn**, và
**làm sao khi đơn không có hóa đơn**.

> Extension chỉ tạo và lưu bản kê hóa đơn trong hệ thống. Nó **không** gửi hóa đơn tới cơ
> quan thuế — việc đó cần nhà cung cấp hóa đơn điện tử hợp lệ. Xem mục **Cảnh báo về pháp
> lý** trong [`README.md`](../README.md).

---

## 1. Điều kiện tiên quyết

Trước khi tạo được hóa đơn, cần có đủ 4 điều kiện:

| # | Điều kiện | Kiểm tra ở đâu |
| --- | --- | --- |
| 1 | Extension **E-Invoice** đang bật | *Theme → Extensions* |
| 2 | `base-ecommerce` đang hoạt động | Extension tự cảnh báo nếu thiếu |
| 3 | Bật *Cho phép xuất hóa đơn* | *Ecommerce → Cài đặt → Hóa đơn điện tử* |
| 4 | **Mã số thuế** của người bán đã điền | Cùng tab cài đặt |

Nếu thiếu mã số thuế, trang cài đặt hiện cảnh báo màu đỏ. Hóa đơn vẫn tạo được nhưng **không
hợp lệ** về mặt nghiệp vụ Việt Nam — không dùng thật.

---

## 2. Ba cách tạo hóa đơn

### Cách 1 — Tự động (thông thường)

Đây là cách dùng hàng ngày. Không cần thao tác gì; chỉ cần chọn đúng **thời điểm phát hành**
ở trang cài đặt:

| Thời điểm | Hóa đơn được cấp khi |
| --- | --- |
| `on_payment` | Cổng thanh toán báo **thành công** |
| `on_shipping` | Đơn chuyển sang trạng thái **đang giao** |
| `on_completed` | Đơn chuyển sang trạng thái **hoàn thành** *(mặc định)* |

Luồng thực tế: vào *Đơn hàng → đơn cần xử lý → đổi trạng thái*. Ngay khi trạng thái đạt ngưỡng,
hệ thống tự:

1. Chụp dữ liệu đơn hàng thành bản ghi bất biến (giá, thuế, chiết khấu, hai bên).
2. Cấp số hóa đơn tiếp theo trong năm.
3. Kiểm tra cân bằng — **không cân bằng thì không lưu, không gửi mail**.
4. Lưu vào database.
5. Gửi email cho khách kèm tài liệu.
6. Hiện panel hóa đơn trong *Tài khoản của tôi → Đơn hàng*.

> **Đổi trạng thái trong trang quản trị cũng kích hoạt.** `base-ecommerce` ghi thẳng status
> xuống database và **bỏ qua** hook `order/status_changed`, nên extension tự đối soát lại ở
> `admin_init`. Nên đừng lo phải bấm gì thêm.

### Cách 2 — Cấp cho một đơn cụ thể

Dùng khi đơn **đã hoàn thành nhưng chưa có hóa đơn** — thường là đơn cũ, hoặc đơn hoàn thành
trước khi extension được cài.

**Bước 1.** Vào *Đơn hàng*, mở chi tiết đơn cần cấp hóa đơn.

**Bước 2.** Cuộn xuống cuối trang, khối **Hóa đơn điện tử**:

- Nếu đã có hóa đơn → hiện số hóa đơn và nút *Xem hóa đơn*.
- Nếu chưa có → hiện nút **Cấp hóa đơn cho đơn này**.

**Bước 3.** Bấm nút. Hệ thống cấp hóa đơn và báo kết quả ngay trên trang:

| Thông báo | Ý nghĩa |
| --- | --- |
| *Đã cấp hóa đơn …* | Thành công |
| *Không thể cấp hóa đơn…* | Xem mục [7. Không cấp được](#7-không-cấp--được) |
| *Không tìm thấy đơn hàng* | Sai mã đơn |
| *Yêu cầu không hợp lệ…* | Phiên đăng nhập hết hạn — tải lại trang rồi bấm lại |

### Cách 3 — Cấp hàng loạt cho đơn cũ

Dành cho kế toán cần bổ sung hồ sơ cho nhiều đơn đã xử lý xong.

**Bước 1.** Vào menu **Hóa đơn → Cấp hóa đơn cũ**.

**Bước 2.** Trang liệt kê các đơn **đã đạt ngưỡng phát hành** theo thiết lập hiện tại **và chưa
có hóa đơn nào**. Cột: mã đơn, ngày đặt, khách hàng, trạng thái, tổng.

**Bước 3.** Tick các đơn cần cấp (ô chọn tất cả ở góc trên).

**Bước 4.** Bấm **Cấp hóa đơn cho các đơn đã chọn**.

> Cấp hóa đơn là hành động pháp lý — nó tiêu tốn một số trong dãy số và có thể gửi email.
> Vì vậy trang này **không** có nút "cấp tất cả"; người dùng phải chọn từng đơn.

> Duyệt đơn được chia 50 trang/screen; chuyển trang bằng thanh phân trang bên dưới bảng.

---

## 3. Sau khi cấp hóa đơn

Hóa đơn xuất hiện ở **4 nơi** cùng lúc:

| Nơi | Cách xem |
| --- | --- |
| **Trang quản trị** | Menu *Hóa đơn* — xem số, trạng thái gửi mail, xem trước, tải PDF |
| **Chi tiết đơn hàng** | *Đơn hàng → mở đơn → cuộn xuống cuối* |
| **Email khách hàng** | Tự động gửi kèm tài liệu (nếu bật *Gửi hóa đơn qua email*) |
| **Tài khoản của tôi** | Khách tự tải/xem tại *Tài khoản của tôi → Đơn hàng → mã đơn* |

Khách chỉ thấy hóa đơn **của chính đơn đó** — quyền sở hữu được kiểm tra bằng email đã chụp
lúc phát hành.

Nếu gửi email lỗi, cột *Gửi mail* trong trang quản trị sẽ để trống; hóa đơn **vẫn tồn tại**.
Cấp lại không tạo bản ghi mới (xem [4. Cấp lại](#4-cấp-lại-hóa-đơn)).

---

## 4. Cấp lại hóa đơn

Cấp hóa đơn là **idempotent** — mỗi đơn chỉ có tối đa một hóa đơn:

- Bấm nút lần hai → hệ thống trả về hóa đơn **cũ**, không tạo bản ghi mới.
- Database có ràng buộc `UNIQUE` trên khóa đơn, nên kể cả hai request chạy cùng lúc thì chỉ
  một bản ghi được ghi.

Hệ quả cần nhớ:

- **Số hóa đơn liên tục, không lấp chỗ trống.** Nếu hóa đơn dựng ra không cân bằng, số đã cấp
  bị bỏ và dãy số có lỗ hổng — đây là hành vi đúng, không được lấp lại.
- Muốn **sửa** một hóa đơn đã phát hành (ví dụ sai địa chỉ) thì không cấp lại được; cần sửa dữ
  liệu chụp trong bản ghi, hoặc dùng cơ chế điều chỉnh/hủy bùng của nhà cung cấp hóa đơn.

---

## 5. Cài đặt liên quan

| Cài đặt | Vị trí | Ảnh hưởng |
| --- | --- | --- |
Tất cả nằm trong tab **Hóa đơn điện tử** của *Ecommerce → Cài đặt*.

| Nhãn trên màn hình | Khóa | Ảnh hưởng |
| --- | --- | --- |
| Cho phép xuất hóa đơn | `jankx_einvoice_enabled` | Tắt → **không** cấp được, kể cả thủ công |
| Thời điểm xuất | `jankx_einvoice_trigger` | Quyết định đơn nào đủ điều kiện cấp tự động |
| Gửi email | `jankx_einvoice_email_enabled` | Tắt → vẫn cấp hóa đơn, chỉ không gửi mail |
| Quốc gia | `jankx_einvoice_country` | `VN` hoặc *— Tự động nhận diện —* |
| Định dạng tài liệu | `jankx_einvoice_renderer` | Cần `dompdf/dompdf` cho PDF; thiếu thì xuất HTML in được |
| Ký hiệu hóa đơn | `jankx_einvoice_series` | Cấu thành số hóa đơn (`HD/000001`) |
| Ký hiệu mẫu số | `jankx_einvoice_form_symbol` | Phần sau dấu `/` của số hóa đơn |
| Mã của cơ quan thuế | `jankx_einvoice_tax_authority_code` | Hóa đơn có/không mã của cơ quan thuế |
| Tên người bán, Địa chỉ, Mã số thuế, Điện thoại, Email | `jankx_einvoice_seller_*` | In ra hóa đơn |
| Mã, địa chỉ địa điểm kinh doanh | `jankx_einvoice_seller_location` | In ra hóa đơn |
| Mã số định danh cá nhân | `jankx_einvoice_seller_personal_id` | Tùy chọn, in ra hóa đơn |
| Ngân hàng, Số tài khoản | `jankx_einvoice_seller_bank_*` | Tùy chọn, in ra hóa đơn |

> Trường **Mã số thuế** của người bán không có giá trị mặc định hợp lý — phải tự điền.

> Đổi cài đặt **không** sửa hóa đơn đã phát hành. Hóa đơn là bản chụp bất biến — nếu tính lại
> từ cài đặt, chứng từ cũ sẽ thay đổi theo, vi phạm yêu cầu lưu giữ sổ kế toán.

---

## 6. Số hóa đơn

Số hóa đơn có dạng `<ký hiệu>/<6 chữ số>` ví dụ `HD/000001`, đếm **liên tục theo từng năm**
trong bảng `{prefix}jankx_invoice_sequences`. Cấp số là nguyên tử (atomic) nên hai request
song song không thể nhận cùng một số.

Đổi ký hiệu hoặc mẫu số trong cài đặt sẽ **không** sửa hóa đơn cũ.

---

## 7. Không cấp được

Nếu bấm nút mà không có hóa đơn, hãy theo thứ tự:

1. **Tính năng đang tắt?** — bật *Cho phép xuất hóa đơn*. Tắt thì cả cấp tự động lẫn thủ
   công đều không chạy.
2. **Hóa đơn không cân bằng.** Đây là chốt an toàn: hóa đơn sai còn tệ hơn không có hóa đơn.
   Nguyên nhân thường gặp:
   - Tổng tiền trong đơn bị sửa tay nhưng tổng dòng hàng không đổi theo.
   - Đơn không còn dòng hàng nào nhưng tổng vẫn khác 0.
   - Chiết khấu / thuế cấu hình lệch với dữ liệu đơn cũ.
3. **Xem log.** Bật `WP_DEBUG` rồi đọc `error_log`; extension ghi rõ lý do theo dạng
   `[EInvoice] refused to issue invoice for order #…`.
4. **Đơn chưa tới thời điểm phát hành.** Đang ở trạng thái dưới ngưỡng (ví dụ trigger
   `on_completed` mà đơn mới `shipping`) — đó là hành vi đúng, chờ tới thời điểm.
5. **Extension chưa kích hoạt / bảng chưa có.** *Theme → Extensions* phải bật; bảng
   `{prefix}jankx_invoices` và `{prefix}jankx_invoice_sequences` phải tồn tại.
6. **Trang quản trị báo lỗi `Class "Base" not found`.** Đây là lỗi thiếu thư viện PHP của
   *parent theme* `jankx`, **không** liên quan tới hóa đơn. Chạy
   `composer install --no-dev` trong `wp-content/themes/jankx`.

Kiểm tra nhanh bằng SQL (thay `wp_` bằng prefix thật):

```sql
-- Đơn OD-000029 đã có hóa đơn chưa?
SELECT id, order_id, invoice_number, status, issued_at, emailed_at
FROM wp_jankx_invoices
WHERE order_id = 29;

-- Số đơn đã hoàn thành nhưng chưa có hóa đơn (xuất hiện ở Cấp hóa đơn cũ)
SELECT o.id, o.order_number, o.status, o.total, o.customer_email
FROM wp_jankx_orders o
LEFT JOIN wp_jankx_invoices i ON i.order_id = o.id
WHERE o.status = 'completed' AND i.id IS NULL
ORDER BY o.id ASC;
```

---

## 8. Xem trước khi dùng thật

Trước khi bật cho khách hàng thật:

- [ ] Cài `dompdf/dompdf` nếu cần gửi PDF.
- [ ] Điền đủ *Mã số thuế* và *Địa chỉ* của người bán.
- [ ] Chọn thời điểm phát hành khớp quy trình vận chuyển của bạn (mặc định `on_completed`).
- [ ] Tạo thử 1 hóa đơn và **mở tệp xem kỹ**: tổng khớp dòng, số tiền bằng chữ đúng, thuế
      đúng, địa chỉ không bị cắt.
- [ ] Bật `WP_DEBUG`, xem log sạch trong vài ngày đầu.
- [ ] Cân nhắc lưu ý pháp lý trong README và ý kiến kế toán trước khi dùng thật.