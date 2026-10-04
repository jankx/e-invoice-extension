<?php
namespace Jankx\Extensions\EInvoice\Model;

use Jankx\Extensions\EInvoice\Support\Amount;

/**
 * The invoice aggregate root.
 *
 * An Invoice is an immutable **snapshot** of a commercial event. That word
 * matters: `jankx_orders` is lossy — it stores a single tax-inclusive `total`
 * and no record of the VAT rate, the tax strategy, the discount or the exchange
 * rate that applied at the time. Once an invoice has been issued it must remain
 * reproducible forever, so every number it needs is copied in at issue time and
 * never recomputed from live settings.
 *
 * Lifecycle mirrors the legal concept: an issued invoice can be voided
 * (hủy), adjusted (điều chỉnh) or replaced (thay thế), never silently edited.
 *
 * @package Jankx\Extensions\EInvoice\Model
 */
class Invoice
{
    const STATUS_ISSUED    = 'issued';
    const STATUS_CANCELLED = 'cancelled';
    const STATUS_ADJUSTED  = 'adjusted';
    const STATUS_REPLACED  = 'replaced';

    /** Adjustment types, mirroring the Vietnamese invoice lifecycle. */
    const ADJUSTMENT_NONE     = '';
    const ADJUSTMENT_REPLACE  = 'replace';
    const ADJUSTMENT_ADJUST   = 'adjust';

    /** @var int */
    protected $id = 0;

    /** @var string Fully rendered number, e.g. "HD-2026-000042". */
    protected $invoiceNumber;

    /** @var string Invoice symbol ("ký hiệu"), e.g. "HD". */
    protected $invoiceSymbol;

    /** @var string Form symbol ("ký hiệu mẫu số"), e.g. "01". */
    protected $invoiceFormSymbol;

    /** @var int */
    protected $orderId;

    /** @var string */
    protected $orderNumber;

    /** @var string ISO country code the profile was selected for. */
    protected $countryCode;

    /** @var string Identifier of the profile that produced this document. */
    protected $profileId;

    /** @var string */
    protected $currency;

    /** @var string Thời điểm lập hóa đơn — when the invoice was raised. */
    protected $issuedAt;

    /** @var string Thời điểm ký số — when it was signed. Defaults to issuedAt. */
    protected $signedAt;

    /** @var InvoiceParty */
    protected $seller;

    /** @var InvoiceParty */
    protected $buyer;

    /** @var InvoiceLine[] */
    protected $lines = [];

    /** @var InvoiceTaxLine[] */
    protected $taxLines = [];

    /** @var InvoiceTotals */
    protected $totals;

    /** @var string Grand total spelled out in the local language. */
    protected $amountInWords;

    /** @var string Tax authority code ("mã của cơ quan thuế") if granted. */
    protected $taxAuthorityCode;

    /** @var string One of the ADJUSTMENT_* constants. */
    protected $adjustmentType = self::ADJUSTMENT_NONE;

    /** @var int Self reference for adjustment chains. */
    protected $originalInvoiceId = 0;

    /** @var string */
    protected $status = self::STATUS_ISSUED;

    /** @var string|null Absolute path of the cached PDF, if generated. */
    protected $pdfPath;

    /**
     * When the document was last handed to wp_mail(). Not legally part of the
     * document — it is delivery bookkeeping, so it is tracked separately from
     * `signedAt` and never printed.
     *
     * @var string|null
     */
    protected $emailedAt;

    /** @var array Free-form extra fields contributed by profiles/plugins. */
    protected $extra = [];

    /**
     * @param array $data
     */
    public function __construct(array $data = [])
    {
        $this->id                = (int) ($data['id'] ?? 0);
        $this->invoiceNumber     = (string) ($data['invoice_number'] ?? '');
        $this->invoiceSymbol     = (string) ($data['invoice_symbol'] ?? '');
        $this->invoiceFormSymbol = (string) ($data['invoice_form_symbol'] ?? '');
        $this->orderId           = (int) ($data['order_id'] ?? 0);
        $this->orderNumber       = (string) ($data['order_number'] ?? '');
        $this->countryCode       = (string) ($data['country_code'] ?? '');
        $this->profileId         = (string) ($data['profile_id'] ?? '');
        $this->currency          = (string) ($data['currency'] ?? 'VND');
        $this->issuedAt          = (string) ($data['issued_at'] ?? '');
        $this->signedAt          = (string) ($data['signed_at'] ?? $this->issuedAt);
        $this->amountInWords     = (string) ($data['amount_in_words'] ?? '');
        $this->taxAuthorityCode  = (string) ($data['tax_authority_code'] ?? '');
        $this->adjustmentType    = (string) ($data['adjustment_type'] ?? self::ADJUSTMENT_NONE);
        $this->originalInvoiceId = (int) ($data['original_invoice_id'] ?? 0);
        $this->status            = (string) ($data['status'] ?? self::STATUS_ISSUED);
        $this->pdfPath           = $data['pdf_path'] ?? null;
        $this->emailedAt         = !empty($data['emailed_at']) ? (string) $data['emailed_at'] : null;
        $this->extra             = is_array($data['extra'] ?? null) ? $data['extra'] : [];

        $this->seller = $data['seller'] instanceof InvoiceParty
            ? $data['seller']
            : new InvoiceParty(is_array($data['seller'] ?? null) ? $data['seller'] : []);

        $this->buyer = $data['buyer'] instanceof InvoiceParty
            ? $data['buyer']
            : new InvoiceParty(is_array($data['buyer'] ?? null) ? $data['buyer'] : []);

        $this->totals = $data['totals'] instanceof InvoiceTotals
            ? $data['totals']
            : new InvoiceTotals(is_array($data['totals'] ?? null) ? $data['totals'] : ['currency' => $this->currency]);

        $this->lines    = $this->hydrateLines($data['lines'] ?? []);
        $this->taxLines = $this->hydrateTaxLines($data['tax_lines'] ?? []);
    }

    /** @return InvoiceLine[] */
    protected function hydrateLines($lines): array
    {
        $out = [];
        foreach ((array) $lines as $line) {
            $out[] = $line instanceof InvoiceLine ? $line : new InvoiceLine((array) $line);
        }
        return $out;
    }

    /** @return InvoiceTaxLine[] */
    protected function hydrateTaxLines($lines): array
    {
        $out = [];
        foreach ((array) $lines as $line) {
            $out[] = $line instanceof InvoiceTaxLine ? $line : new InvoiceTaxLine((array) $line);
        }
        return $out;
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getInvoiceNumber(): string
    {
        return $this->invoiceNumber;
    }

    public function getInvoiceSymbol(): string
    {
        return $this->invoiceSymbol;
    }

    public function getInvoiceFormSymbol(): string
    {
        return $this->invoiceFormSymbol;
    }

    public function getOrderId(): int
    {
        return $this->orderId;
    }

    public function getOrderNumber(): string
    {
        return $this->orderNumber;
    }

    public function getCountryCode(): string
    {
        return $this->countryCode;
    }

    public function getProfileId(): string
    {
        return $this->profileId;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function getIssuedAt(): string
    {
        return $this->issuedAt;
    }

    public function getSignedAt(): string
    {
        return $this->signedAt;
    }

    public function getSeller(): InvoiceParty
    {
        return $this->seller;
    }

    public function getBuyer(): InvoiceParty
    {
        return $this->buyer;
    }

    /** @return InvoiceLine[] */
    public function getLines(): array
    {
        return $this->lines;
    }

    /** @return InvoiceTaxLine[] */
    public function getTaxLines(): array
    {
        return $this->taxLines;
    }

    public function getTotals(): InvoiceTotals
    {
        return $this->totals;
    }

    public function getAmountInWords(): string
    {
        return $this->amountInWords;
    }

    public function getTaxAuthorityCode(): string
    {
        return $this->taxAuthorityCode;
    }

    public function getAdjustmentType(): string
    {
        return $this->adjustmentType;
    }

    public function getOriginalInvoiceId(): int
    {
        return $this->originalInvoiceId;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getPdfPath(): ?string
    {
        return $this->pdfPath;
    }

    public function getEmailedAt(): ?string
    {
        return $this->emailedAt;
    }

    public function wasEmailed(): bool
    {
        return $this->emailedAt !== null;
    }

    public function setEmailedAt(?string $when): void
    {
        $this->emailedAt = $when;
    }

    public function setPdfPath(?string $path): void
    {
        $this->pdfPath = $path;
    }

    /** @return array */
    public function getExtra(): array
    {
        return $this->extra;
    }

    public function getExtraValue(string $key, $default = null)
    {
        return $this->extra[$key] ?? $default;
    }

    public function isEditable(): bool
    {
        return $this->status === self::STATUS_ISSUED;
    }

    /**
     * The distinct tax rates present on the document, ascending.
     *
     * @return float[]
     */
    public function getAppliedRates(): array
    {
        $rates = [];
        foreach ($this->taxLines as $line) {
            $rates[(string) $line->getRate()] = $line->getRate();
        }
        ksort($rates, SORT_NUMERIC);
        return array_values($rates);
    }

    /**
     * Short code used in admin lists, e.g. "HD-2026-000042" or
     * "HD-2026-000042 (điều chỉnh)".
     */
    public function getDisplayNumber(): string
    {
        $suffix = [
            self::ADJUSTMENT_REPLACE => ' (thay thế)',
            self::ADJUSTMENT_ADJUST  => ' (điều chỉnh)',
        ];

        return ($suffix[$this->adjustmentType] ?? '') === ''
            ? $this->invoiceNumber
            : $this->invoiceNumber . $suffix[$this->adjustmentType];
    }

    /**
     * Human-readable issue date in the shop's locale format.
     */
    public function getFormattedIssuedAt(): string
    {
        if (!$this->issuedAt) {
            return '';
        }
        $timestamp = strtotime($this->issuedAt);
        if (!$timestamp) {
            return $this->issuedAt;
        }
        return date_i18n(get_option('date_format', 'd/m/Y'), $timestamp);
    }

    /**
     * Full self-consistency check. Returns problems; empty means issuable.
     *
     * @return string[]
     */
    public function validate(): array
    {
        $problems = $this->totals->validate();

        // The lines must account for exactly the tax-exclusive revenue.
        $lineNet = 0.0;
        foreach ($this->lines as $line) {
            $lineNet += $line->getNetAmount();
        }
        if ($this->lines && abs(Amount::round($lineNet, $this->currency) - $this->totals->getNetTotal()) > 0.5) {
            $problems[] = sprintf(
                'line net sum (%s) != invoice net (%s)',
                Amount::round($lineNet, $this->currency),
                $this->totals->getNetTotal()
            );
        }

        $problems = array_merge($problems, $this->totals->validateAgainstTaxLines($this->taxLines));

        if (!$this->invoiceNumber) {
            $problems[] = 'missing invoice number';
        }

        if ($this->seller->getName() === '') {
            $problems[] = 'missing seller name';
        }

        // A buyer is never allowed to be blank in any jurisdiction we support:
        // Vietnam mandates the literal "Bán cho người tiêu dùng" fallback.
        if ($this->buyer->getName() === '') {
            $problems[] = 'missing buyer name';
        }

        return $problems;
    }

    /**
     * @param array $overrides
     */
    public function with(array $overrides): self
    {
        return new self(array_merge($this->toArray(), $overrides));
    }

    public function toArray(): array
    {
        return [
            'id'                  => $this->id,
            'invoice_number'      => $this->invoiceNumber,
            'invoice_symbol'      => $this->invoiceSymbol,
            'invoice_form_symbol' => $this->invoiceFormSymbol,
            'order_id'            => $this->orderId,
            'order_number'        => $this->orderNumber,
            'country_code'        => $this->countryCode,
            'profile_id'          => $this->profileId,
            'currency'            => $this->currency,
            'issued_at'           => $this->issuedAt,
            'signed_at'           => $this->signedAt,
            'seller'              => $this->seller->toArray(),
            'buyer'               => $this->buyer->toArray(),
            'lines'               => array_map(static function (InvoiceLine $l) {
                return $l->toArray();
            }, $this->lines),
            'tax_lines'           => array_map(static function (InvoiceTaxLine $l) {
                return $l->toArray();
            }, $this->taxLines),
            'totals'              => $this->totals->toArray(),
            'amount_in_words'     => $this->amountInWords,
            'tax_authority_code'  => $this->taxAuthorityCode,
            'adjustment_type'     => $this->adjustmentType,
            'original_invoice_id' => $this->originalInvoiceId,
            'status'              => $this->status,
            'pdf_path'            => $this->pdfPath,
            'emailed_at'          => $this->emailedAt,
            'extra'               => $this->extra,
        ];
    }
}