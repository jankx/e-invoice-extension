<?php
namespace Jankx\Extensions\EInvoice\Model;

use Jankx\Extensions\EInvoice\Support\Amount;

/**
 * The monetary summary of an invoice.
 *
 * INVARIANT — the whole class depends on one fact about base-ecommerce:
 * `jankx_orders.total` is always a **tax-inclusive (gross)** amount, under both
 * tax strategies.
 *
 *   inclusive: Cart::getTotal() = subtotal - discount               (tax already inside)
 *   exclusive: Cart::getTotal() = subtotal - discount + taxAmount   (tax added on top)
 *
 * Either way the stored figure is gross. That lets us recover the tax-exclusive
 * revenue deterministically:
 *
 *     netTotal = gross / (1 + Σ rates)
 *
 * We snapshot the rate set at issue time rather than reading the live tax
 * settings, because an invoice issued today must still reconcile years from now
 * even after the shop changes its VAT configuration.
 *
 * @package Jankx\Extensions\EInvoice\Model
 */
class InvoiceTotals
{
    /** @var string */
    protected $currency;

    /** @var float Sum of line amounts as quoted, before discount. */
    protected $grossSubtotal;

    /** @var float Discounts, coupons and promotions applied. */
    protected $discountTotal;

    /** @var float Revenue excluding tax ("thành tiền chưa có thuế"). */
    protected $netTotal;

    /** @var float Total tax due. */
    protected $taxTotal;

    /** @var float Amount payable ("tổng tiền thanh toán đã có thuế"). */
    protected $grandTotal;

    /** @var float Taxes/levies that are not VAT (phí, lệ phí). */
    protected $feeTotal;

    /** @var bool Whether the shop quotes prices excluding tax. */
    protected $pricesAreExclusive = false;

    /** @var float Sum of the configured tax rates that applied. */
    protected $rateSum = 0.0;

    /**
     * @param array $data
     */
    public function __construct(array $data = [])
    {
        $this->currency          = (string) ($data['currency'] ?? 'VND');
        $this->grossSubtotal     = (float) ($data['gross_subtotal'] ?? 0);
        $this->discountTotal     = (float) ($data['discount_total'] ?? 0);
        $this->netTotal          = (float) ($data['net_total'] ?? 0);
        $this->taxTotal          = (float) ($data['tax_total'] ?? 0);
        $this->grandTotal        = (float) ($data['grand_total'] ?? 0);
        $this->feeTotal          = (float) ($data['fee_total'] ?? 0);
        $this->pricesAreExclusive = !empty($data['prices_are_exclusive']);
        $this->rateSum           = (float) ($data['rate_sum'] ?? 0);
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function getGrossSubtotal(): float
    {
        return $this->grossSubtotal;
    }

    public function getDiscountTotal(): float
    {
        return $this->discountTotal;
    }

    public function getNetTotal(): float
    {
        return $this->netTotal;
    }

    public function getTaxTotal(): float
    {
        return $this->taxTotal;
    }

    public function getGrandTotal(): float
    {
        return $this->grandTotal;
    }

    public function getFeeTotal(): float
    {
        return $this->feeTotal;
    }

    public function arePricesExclusive(): bool
    {
        return $this->pricesAreExclusive;
    }

    public function getRateSum(): float
    {
        return $this->rateSum;
    }

    /**
     * The sum actually payable: the VAT-inclusive goods total plus any
     * non-VAT fees (phí, lệ phí). NĐ 254/2026 Art. 10(1)(k) requires such
     * fees to be disclosed on the invoice, and they sit outside the VAT
     * subtotal rather than inside it.
     */
    public function getPayableTotal(): float
    {
        return Amount::round($this->grandTotal + $this->feeTotal, $this->currency);
    }

    /**
     * Verify the internal arithmetic before a document is issued.
     *
     * On a legal document an inconsistency is worse than a failure, so the
     * issuance service calls this and refuses to produce an invoice that does
     * not balance. Returns a list of human-readable problems (empty = valid).
     *
     * @return string[]
     */
    public function validate(): array
    {
        $problems = [];

        if ($this->netTotal < 0 || $this->taxTotal < 0 || $this->grandTotal < 0) {
            $problems[] = 'negative amount in totals';
        }

        // The VAT-inclusive goods total is the tax-exclusive revenue plus tax.
        $expectedGross = Amount::round($this->netTotal + $this->taxTotal, $this->currency);
        if (abs($expectedGross - $this->grandTotal) > $this->tolerance()) {
            $problems[] = sprintf(
                'gross mismatch: net %s + tax %s != gross %s',
                $this->netTotal,
                $this->taxTotal,
                $this->grandTotal
            );
        }

        return $problems;
    }

    /**
     * Cross-check a set of tax summary rows against these totals.
     *
     * @param InvoiceTaxLine[] $taxLines
     * @return string[]
     */
    public function validateAgainstTaxLines(array $taxLines): array
    {
        $problems = [];

        $taxable = 0.0;
        $tax     = 0.0;
        foreach ($taxLines as $line) {
            $taxable += $line->getTaxableAmount();
            $tax     += $line->getTaxAmount();
        }

        if ($taxLines && abs(Amount::round($taxable, $this->currency) - $this->netTotal) > $this->tolerance()) {
            $problems[] = sprintf(
                'tax summary net (%s) does not match invoice net (%s)',
                Amount::round($taxable, $this->currency),
                $this->netTotal
            );
        }

        if ($taxLines && abs(Amount::round($tax, $this->currency) - $this->taxTotal) > $this->tolerance()) {
            $problems[] = sprintf(
                'tax summary tax (%s) does not match invoice tax (%s)',
                Amount::round($tax, $this->currency),
                $this->taxTotal
            );
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

    /**
     * Half of the smallest representable unit — anything within this is a
     * rounding artefact, not an error.
     */
    protected function tolerance(): float
    {
        return (10 ** -Amount::decimalsFor($this->currency)) / 2;
    }

    public function toArray(): array
    {
        return [
            'currency'            => $this->currency,
            'gross_subtotal'      => $this->grossSubtotal,
            'discount_total'      => $this->discountTotal,
            'net_total'           => $this->netTotal,
            'tax_total'           => $this->taxTotal,
            'grand_total'         => $this->grandTotal,
            'fee_total'           => $this->feeTotal,
            'prices_are_exclusive' => $this->pricesAreExclusive,
            'rate_sum'            => $this->rateSum,
        ];
    }
}