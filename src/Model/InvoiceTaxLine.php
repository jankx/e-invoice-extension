<?php
namespace Jankx\Extensions\EInvoice\Model;

use Jankx\Extensions\EInvoice\Support\Amount;

/**
 * One row of the invoice tax summary table.
 *
 * Vietnamese law requires the invoice to group tax by rate:
 * NĐ 254/2026 Art. 10(1)(đ) asks for "tổng số tiền thuế giá trị gia tăng theo
 * từng loại thuế suất" — a per-rate breakdown, not a single lump.
 *
 * @package Jankx\Extensions\EInvoice\Model
 */
class InvoiceTaxLine
{
    /** @var float Tax rate as a fraction. */
    protected $rate;

    /** @var string Printed label for the rate, e.g. "8%". */
    protected $rateLabel;

    /** @var float Tax-exclusive revenue attributed to this rate. */
    protected $taxableAmount;

    /** @var float Tax amount. */
    protected $taxAmount;

    /** @var string */
    protected $currency;

    /**
     * @param array $data
     */
    public function __construct(array $data = [])
    {
        $this->rate          = (float) ($data['rate'] ?? 0);
        $this->rateLabel     = (string) ($data['rate_label'] ?? InvoiceLine::formatRate((float) ($data['rate'] ?? 0)));
        $this->taxableAmount = (float) ($data['taxable_amount'] ?? 0);
        $this->taxAmount     = (float) ($data['tax_amount'] ?? 0);
        $this->currency      = (string) ($data['currency'] ?? 'VND');
    }

    public function getRate(): float
    {
        return $this->rate;
    }

    public function getRateLabel(): string
    {
        return $this->rateLabel;
    }

    public function getTaxableAmount(): float
    {
        return $this->taxableAmount;
    }

    public function getTaxAmount(): float
    {
        return $this->taxAmount;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function getGrossAmount(): float
    {
        return Amount::round($this->taxableAmount + $this->taxAmount, $this->currency);
    }

    public function toArray(): array
    {
        return [
            'rate'           => $this->rate,
            'rate_label'     => $this->rateLabel,
            'taxable_amount' => $this->taxableAmount,
            'tax_amount'     => $this->taxAmount,
            'currency'       => $this->currency,
        ];
    }
}