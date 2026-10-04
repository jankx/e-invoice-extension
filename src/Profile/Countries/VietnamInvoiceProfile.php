<?php
namespace Jankx\Extensions\EInvoice\Profile\Countries;

use Jankx\Extensions\EInvoice\Model\Invoice;
use Jankx\Extensions\EInvoice\Profile\AbstractInvoiceProfile;
use Jankx\Extensions\EInvoice\Support\VietnameseNumberToWords;

/**
 * Vietnamese legal profile — the default for this extension.
 *
 * ENCODES (as at 01/07/2026):
 *
 *   • Nghị định 254/2026/NĐ-CP (30/06/2026, hiệu lực 01/07/2026) — hướng dẫn
 *     Luật Quản lý thuế số 108/2025/QH15 về hóa đơn, chứng từ điện tử.
 *     Replaces NĐ 123/2020 + NĐ 70/2025 entirely.
 *   • Thông tư 91/2026/TT-BTC (Bộ Tài chính) — registration, issuance and use
 *     of electronic invoices. Replaces TT 32/2025/TT-BTC.
 *   • Luật Kế toán và Kế toán số 54/2024/QH15 (from 01/07/2025) — accounting
 *     documents and retention.
 *   • Thông tư 99/2025/TT-BTC (from 01/01/2026) — accounting regime.
 *
 * Điều 10 NĐ 254/2026 mandates the field set this template prints. The relevant
 * clauses, and where each is handled:
 *
 *   a) Tên hóa đơn, ký hiệu hóa đơn, ký hiệu mẫu số hóa đơn  → invoice.title,
 *      invoice.symbol, invoice.form_symbol
 *   b) Số hóa đơn                                              → invoice.number
 *   c) Tên, địa chỉ, mã số thuế của người bán                 → seller block
 *   d) Tên, địa chỉ, mã số thuế hoặc số định danh cá nhân của
 *      người mua                                               → buyer block
 *      + Phụ lục, khoản 4 điểm b: when the buyer supplies nothing we MUST
 *        print "Bán cho người tiêu dùng" — leaving it blank is a defect.
 *      → getAnonymousBuyerText()
 *   đ) Tên, đơn vị tính, số lượng, đơn giá; thành tiền chưa có thuế; thuế suất;
 *      tổng thuế theo từng loại thuế suất; tổng cộng tiền thuế;
 *      tổng tiền thanh toán                                    → line table +
 *      requiresTaxBreakdownByRate() + tax summary rows
 *   e) Chữ ký của người bán (buyer signature not required absent agreement)
 *                                                            → signature block
 *   g) Thời điểm lập hóa đơn, định dạng ngày tháng năm      → issued_at
 *   h) Thời điểm ký số                                       → signed_at
 *   i) Mã của cơ quan thuế (for HĐĐT có mã CQT)             → tax_authority_code
 *   k) Phí, lệ phí, chiết khấu thương mại, khuyến mại       → totals block
 *
 * ── Scope of this profile ───────────────────────────────────────────────────
 * This generates the *document*. It does NOT by itself satisfy the statutory
 * HĐĐT có mã của cơ quan thuế registration or transmission requirements: in
 * Vietnam the code must be obtained from a qualified provider and the data
 * sent to the General Department of Taxation. The `tax_authority_code` field is
 * therefore surfaced but blank until an integration fills it. Connecting a
 * provider is the job of a separate adapter implementing
 * `jankx/einvoice/tax_authority_client`.
 *
 * @package Jankx\Extensions\EInvoice\Profile\Countries
 */
class VietnamInvoiceProfile extends AbstractInvoiceProfile
{
    protected $countryCode = 'VN';
    protected $locale      = 'vi_VN';
    protected $currency    = 'VND';

    public function getCountryName(): string
    {
        return __('Vietnam', 'e-invoice');
    }

    public function getId(): string
    {
        return 'vn';
    }

    public function getLegalBasis(): array
    {
        return [
            'Nghị định 254/2026/NĐ-CP',
            'Thông tư 91/2026/TT-BTC',
            'Luật Kế toán và Kế toán số 54/2024/QH15',
            'Thông tư 99/2025/TT-BTC',
            'Luật Quản lý thuế số 108/2025/QH15',
        ];
    }

    public function getComplianceNotes(): array
    {
        return [
            'buyer_blank' => 'Phụ lục NĐ 254/2026 khoản 4 điểm b: buyer must read "Bán cho người tiêu dùng" when unidentified, never blank.',
            'tax_by_rate' => 'Điều 10(1)(đ): VAT must be totalled per tax rate.',
            'amount_in_words' => 'Điều 10(1)(đ): grand total must be stated in figures and in words.',
            'tax_authority_code' => 'Điều 10(1)(i): the tax authority code is mandatory only for HĐĐT có mã của cơ quan thuế; blank here means the document is a HĐĐT without an authority code.',
            'numbering' => 'Serial restarts on 1 January for each ký hiệu.',
        ];
    }

    // ── Required field set (Điều 10 khoản 1) ─────────────────────────────────

    public function getRequiredFields(): array
    {
        return [
            'invoice_title',
            'invoice_symbol',
            'invoice_form_symbol',
            'invoice_number',
            'issue_date',
            'seller',
            'buyer',
            'lines',
            'tax_summary',
            'grand_total',
            'amount_in_words',
            'tax_authority_code',
            'signature',
        ];
    }

    public function getFieldLabels(): array
    {
        return [
            'invoice_title'        => __('Tên hóa đơn', 'e-invoice'),
            'invoice_symbol'       => __('Ký hiệu', 'e-invoice'),
            'invoice_form_symbol'  => __('Ký hiệu mẫu số', 'e-invoice'),
            'invoice_number'       => __('Số hóa đơn', 'e-invoice'),
            'issue_date'           => __('Ngày lập hóa đơn', 'e-invoice'),
            'seller'               => __('Người bán', 'e-invoice'),
            'buyer'                => __('Người mua', 'e-invoice'),
            'lines'                => __('Danh mục hàng hóa, dịch vụ', 'e-invoice'),
            'tax_summary'          => __('Thuế giá trị gia tăng', 'e-invoice'),
            'grand_total'          => __('Tổng tiền thanh toán', 'e-invoice'),
            'amount_in_words'      => __('Tổng số tiền bằng chữ', 'e-invoice'),
            'tax_authority_code'   => __('Mã của cơ quan thuế', 'e-invoice'),
            'signature'            => __('Chữ ký người bán', 'e-invoice'),
        ];
    }

    public function getLineTableHeading(): string
    {
        return __('Danh mục hàng hóa, dịch vụ', 'e-invoice');
    }

    public function getGrandTotalLabel(): string
    {
        return __('Tổng cộng tiền thanh toán', 'e-invoice');
    }

    public function getTaxSummaryHeading(): string
    {
        return __('Thuế giá trị gia tăng', 'e-invoice');
    }

    public function getUnitLabel(): string
    {
        return __('Đơn vị tính', 'e-invoice');
    }

    public function getTaxAuthorityCodeLabel(): string
    {
        return __('Mã của cơ quan thuế', 'e-invoice');
    }

    // ── Amount in words (Điều 10(1)(đ)) ──────────────────────────────────────

    public function requiresAmountInWords(): bool
    {
        return true;
    }

    public function amountToWords(float $amount, string $currency): string
    {
        return VietnameseNumberToWords::convertAmount($amount, $currency);
    }

    // ── Buyer identification (Phụ lục NĐ 254/2026 khoản 4 điểm b) ─────────────

    public function getAnonymousBuyerText(): string
    {
        return __('Bán cho người tiêu dùng', 'e-invoice');
    }

    public function getExtraPartyFields(): array
    {
        return [
            // Applies to fuel sellers and, per NĐ 254/2026, to hộ kinh doanh /
            // cá nhân kinh doanh operating multiple premises, who must state the
            // code and address of the business location.
            'seller_location' => __('Mã, địa chỉ địa điểm kinh doanh', 'e-invoice'),
        ];
    }

    public function resolveExtraPartyValues(Invoice $invoice): array
    {
        return [
            'seller_location' => $invoice->getSeller()->getLocation(),
        ];
    }

    public function requiresTaxBreakdownByRate(): bool
    {
        return true;
    }

    public function requiresSellerSignature(): bool
    {
        return true;
    }

    public function getTemplateId(): string
    {
        return 'vn';
    }
}