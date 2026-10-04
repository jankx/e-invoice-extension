<?php
namespace Jankx\Extensions\EInvoice\Model;

use Jankx\Extensions\EInvoice\Support\Amount;

/**
 * A single line on an invoice ("Tên hàng hóa, dịch vụ" row).
 *
 * Amounts are stored pre-tax, because that is what every tax regime requires
 * to be printed: NĐ 254/2026 Art. 10(1)(đ) asks for "thành tiền chưa có thuế
 * giá trị gia tăng" alongside the rate and the tax amount.
 *
 * @package Jankx\Extensions\EInvoice\Model
 */
class InvoiceLine
{
    /** @var string */
    protected $description;

    /** @var string */
    protected $unit;

    /** @var float */
    protected $quantity;

    /** @var float Pre-tax unit price. */
    protected $unitPrice;

    /** @var float Tax rate as a fraction: 0.08 for 8%. */
    protected $taxRate;

    /**
     * Explicit pre-tax line amount, overriding quantity × unit price.
     *
     * Needed because the order total and the line prices cannot both be
     * preserved: a coupon reduces the charged total, but base-ecommerce does
     * not store which lines it was applied to. So the pre-tax total is
     * authoritative and is distributed across the lines here, in exact minor
     * units. Null means "derive from quantity × unit price".
     *
     * @var float|null
     */
    protected $netAmount;

    /** @var string Tax rate label as it must be printed, e.g. "8%". */
    protected $taxRateLabel;

    /** @var string Optional product/commodity code (mã hàng). */
    protected $productCode;

    /** @var string */
    protected $productType;

    /** @var array */
    protected $meta;

    /** @var string */
    protected $currency;

    /**
     * @param array $data
     */
    public function __construct(array $data = [])
    {
        $this->description   = (string) ($data['description'] ?? '');
        $this->unit          = (string) ($data['unit'] ?? '');
        $this->quantity      = (float) ($data['quantity'] ?? 0);
        $this->unitPrice     = (float) ($data['unit_price'] ?? 0);
        $this->netAmount     = array_key_exists('net_amount', $data) && $data['net_amount'] !== null
            ? (float) $data['net_amount']
            : null;
        $this->taxRate       = (float) ($data['tax_rate'] ?? 0);
        $this->taxRateLabel  = (string) ($data['tax_rate_label'] ?? self::formatRate((float) ($data['tax_rate'] ?? 0)));
        $this->productCode   = (string) ($data['product_code'] ?? '');
        $this->productType   = (string) ($data['product_type'] ?? '');
        $this->meta          = is_array($data['meta'] ?? null) ? $data['meta'] : [];
        $this->currency      = (string) ($data['currency'] ?? 'VND');
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function getUnit(): string
    {
        return $this->unit;
    }

    public function getQuantity(): float
    {
        return $this->quantity;
    }

    public function getUnitPrice(): float
    {
        return $this->unitPrice;
    }

    public function getTaxRate(): float
    {
        return $this->taxRate;
    }

    public function getTaxRateLabel(): string
    {
        return $this->taxRateLabel;
    }

    public function getProductCode(): string
    {
        return $this->productCode;
    }

    public function getProductType(): string
    {
        return $this->productType;
    }

    public function getMeta(): array
    {
        return $this->meta;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    /**
     * "Thành tiền chưa có thuế" — the pre-tax line amount.
     *
     * Returns the explicit allocation when one was recorded, otherwise derives
     * it from quantity × unit price.
     */
    public function getNetAmount(): float
    {
        if ($this->netAmount !== null) {
            return $this->netAmount;
        }

        return Amount::round($this->quantity * $this->unitPrice, $this->currency);
    }

    public function hasNetAmountOverride(): bool
    {
        return $this->netAmount !== null;
    }

    /**
     * Tax amount for this line, exclusive-tax style.
     */
    public function getTaxAmount(): float
    {
        return Amount::round($this->getNetAmount() * $this->taxRate, $this->currency);
    }

    /**
     * "Tổng tiền thanh toán đã có thuế" for the line.
     */
    public function getGrossAmount(): float
    {
        return Amount::round($this->getNetAmount() + $this->getTaxAmount(), $this->currency);
    }

    /**
     * Render a tax fraction the way it must appear on the document.
     * 0.08 -> "8%", 0.085 -> "8.5%", 0 -> "" (exempt / không chịu thuế).
     */
    public static function formatRate(float $rate): string
    {
        if (abs($rate) < 0.0000001) {
            return '';
        }

        $percent = $rate * 100;
        // Trim trailing zeros: 8.00 -> "8", 8.50 -> "8.5"
        $formatted = rtrim(rtrim(number_format($percent, 4, '.', ''), '0'), '.');

        return $formatted . '%';
    }

    public function toArray(): array
    {
        return [
            'description'    => $this->description,
            'unit'           => $this->unit,
            'quantity'       => $this->quantity,
            'unit_price'     => $this->unitPrice,
            'net_amount'     => $this->getNetAmount(),
            'tax_rate'       => $this->taxRate,
            'tax_rate_label' => $this->taxRateLabel,
            'tax_amount'     => $this->getTaxAmount(),
            'gross_amount'   => $this->getGrossAmount(),
            'product_code'   => $this->productCode,
            'product_type'   => $this->productType,
            'currency'       => $this->currency,
            'meta'           => $this->meta,
        ];
    }

    /**
     * @param array $overrides
     */
    public function with(array $overrides): self
    {
        $data = $this->toArray();

        // toArray() exposes derived values; keep them out of the constructor
        // inputs so a `with()` never carries stale computed numbers. The net
        // amount is the exception — it is a real stored value when present.
        unset($data['net_amount'], $data['tax_amount'], $data['gross_amount']);
        $data['net_amount']     = $this->netAmount;
        $data['tax_rate_label'] = $this->taxRateLabel;

        return new self(array_merge($data, $overrides));
    }
}