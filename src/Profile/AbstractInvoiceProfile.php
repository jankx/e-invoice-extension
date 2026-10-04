<?php
namespace Jankx\Extensions\EInvoice\Profile;

use Jankx\Extensions\EInvoice\Contracts\InvoiceProfileInterface;
use Jankx\Extensions\EInvoice\Model\Invoice;
use Jankx\Extensions\EInvoice\Model\InvoiceLine;
use Jankx\Extensions\EInvoice\Model\InvoiceParty;
use Jankx\Extensions\EInvoice\Model\InvoiceTaxLine;
use Jankx\Extensions\EInvoice\Model\InvoiceTotals;
use Jankx\Extensions\EInvoice\Numbering\InvoiceNumberGeneratorInterface;
use Jankx\Extensions\EInvoice\Support\Amount;

/**
 * Template Method base for country legal profiles.
 *
 * `buildDocument()` is `final` and defines the fixed skeleton every invoice
 * goes through. Subclasses do not reimplement it — they override the small,
 * well-named hooks below, each of which answers exactly one question about the
 * jurisdiction. That is what keeps 95% of the pipeline shared: a new country
 * costs one class, and the reconciliation maths (the part that is easy to get
 * subtly wrong) has exactly one implementation to audit.
 *
 *   1. resolve parties      ← resolveSeller() / resolveBuyer()
 *   2. resolve line items    ← mapLine()
 *   3. derive totals         ← universal arithmetic on the order total
 *   4. group tax by rate     ← buildTaxLines()
 *   5. allocate a number     ← getInvoiceSeries()
 *   6. assemble document     ← mapRequiredFields()
 *
 * Hooks are `protected` so a subclass can extend them without widening the
 * public API.
 *
 * @package Jankx\Extensions\EInvoice\Profile
 */
abstract class AbstractInvoiceProfile implements InvoiceProfileInterface
{
    /** @var string ISO 3166-1 alpha-2. */
    protected $countryCode = '';

    /** @var string */
    protected $locale = 'en_US';

    /** @var string */
    protected $currency = 'USD';

    /** @var string|null Injected numbering strategy; resolved lazily. */
    protected $numberGenerator;

    // ── Identity ─────────────────────────────────────────────────────────────

    public function getCountryCode(): string
    {
        return strtoupper($this->countryCode);
    }

    public function getCountryName(): string
    {
        return $this->getCountryCode();
    }

    public function getLocale(): string
    {
        return $this->locale;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function getId(): string
    {
        // Default to the country code so adding a profile needs no bookkeeping.
        return strtolower($this->getCountryCode());
    }

    // ── Legal metadata ───────────────────────────────────────────────────────

    public function getLegalBasis(): array
    {
        return [];
    }

    public function getComplianceNotes(): array
    {
        return [];
    }

    // ── Document content ─────────────────────────────────────────────────────

    public function getRequiredFields(): array
    {
        return [
            'invoice_title',
            'invoice_number',
            'issue_date',
            'seller',
            'buyer',
            'lines',
            'tax_summary',
            'grand_total',
            'amount_in_words',
            'signature',
        ];
    }

    public function getFieldLabels(): array
    {
        return [];
    }

    public function getLineTableHeading(): string
    {
        return __('Goods and services', 'e-invoice');
    }

    public function getGrandTotalLabel(): string
    {
        return __('Total payable', 'e-invoice');
    }

    public function getTaxSummaryHeading(): string
    {
        return __('Tax breakdown', 'e-invoice');
    }

    public function getUnitLabel(): string
    {
        return __('Unit', 'e-invoice');
    }

    public function requiresAmountInWords(): bool
    {
        return false;
    }

    public function amountToWords(float $amount, string $currency): string
    {
        return '';
    }

    /**
     * Wording to print in the buyer block when the buyer gave no tax identity.
     *
     * Null means this jurisdiction prescribes no specific wording; the buyer's
     * own name is then used, and if that is also empty the template shows a
     * dash. Vietnam overrides this with the mandatory "Bán cho người tiêu dùng".
     *
     * @return string|null
     */
    public function getAnonymousBuyerText(): ?string
    {
        return null;
    }

    public function getExtraPartyFields(): array
    {
        return [];
    }

    public function resolveExtraPartyValues(Invoice $invoice): array
    {
        return [];
    }

    public function requiresTaxBreakdownByRate(): bool
    {
        return true;
    }

    public function getTaxAuthorityCodeLabel(): string
    {
        return __('Tax authority code', 'e-invoice');
    }

    public function requiresSellerSignature(): bool
    {
        return false;
    }

    public function getTemplateId(): string
    {
        return $this->getCountryCode() !== '' ? strtolower($this->getCountryCode()) : 'generic';
    }

    // ── Numbering ────────────────────────────────────────────────────────────

    /**
     * Invoice series symbol for the shop. Overridable via
     * `jankx/einvoice/invoice_series`.
     */
    protected function getInvoiceSeries(): string
    {
        $default = apply_filters('jankx/einvoice/default_invoice_series', 'HD', $this->getCountryCode());
        $series  = (string) get_option('jankx_einvoice_series', $default);

        return $series !== '' ? $series : $default;
    }

    /**
     * Ký hiệu mẫu số hóa đơn — the invoice form symbol. Vietnam requires one as
     * a separate field from the series symbol.
     */
    protected function getInvoiceFormSymbol(): string
    {
        return (string) get_option('jankx_einvoice_form_symbol', '01');
    }

    public function setNumberGenerator(InvoiceNumberGeneratorInterface $generator): void
    {
        $this->numberGenerator = $generator;
    }

    protected function getNumberGenerator(): InvoiceNumberGeneratorInterface
    {
        if (!$this->numberGenerator) {
            $this->numberGenerator = apply_filters(
                'jankx/einvoice/number_generator',
                new \Jankx\Extensions\EInvoice\Numbering\YearlySequenceNumberGenerator(),
                $this->getCountryCode()
            );
        }

        return $this->numberGenerator;
    }

    // ── TEMPLATE METHOD ──────────────────────────────────────────────────────

    /**
     * @final
     */
    final public function buildDocument(array $snapshot): Invoice
    {
        $currency  = (string) ($snapshot['currency'] ?? $this->currency);
        $issuedAt  = (string) ($snapshot['issued_at'] ?? current_time('mysql'));
        $gross     = (float) ($snapshot['gross_total'] ?? 0);

        $seller = $this->resolveSeller($snapshot);
        $buyer  = $this->resolveBuyer($snapshot);

        $lines    = $this->resolveLines($snapshot, $currency);
        $totals   = $this->deriveTotals($snapshot, $lines, $currency);
        $lines    = $this->reconcileLines($lines, $totals, $currency);
        $taxLines = $this->buildTaxLines($lines, $totals, $currency);

        $allocation = $this->allocateNumber($issuedAt);

        $invoice = new Invoice([
            'invoice_number'      => $allocation['number'],
            'invoice_symbol'      => $allocation['symbol'],
            'invoice_form_symbol' => $this->getInvoiceFormSymbol(),
            'order_id'            => (int) ($snapshot['order_id'] ?? 0),
            'order_number'        => (string) ($snapshot['order_number'] ?? ''),
            'country_code'        => $this->getCountryCode(),
            'profile_id'          => $this->getId(),
            'currency'            => $currency,
            'issued_at'           => $issuedAt,
            'signed_at'           => $issuedAt,
            'seller'              => $seller,
            'buyer'               => $buyer,
            'lines'               => $lines,
            'tax_lines'           => $taxLines,
            'totals'              => $totals,
            'amount_in_words'     => $this->resolveAmountInWords($totals, $currency),
            'tax_authority_code'  => (string) ($snapshot['tax_authority_code'] ?? ''),
            'status'              => Invoice::STATUS_ISSUED,
            'extra'               => [
                'issue_period'  => $allocation['period'],
                'issue_sequence' => $allocation['sequence'],
                'numbering_id'  => $this->getNumberGenerator()->getId(),
                'legal_basis'   => $this->getLegalBasis(),
                'order_total'   => $gross,
            ],
        ]);

        // Country-specific decoration runs last, once the object exists.
        $invoice = $this->mapRequiredFields($invoice, $snapshot);

        /**
         * Fires once a country profile has produced an invoice document.
         *
         * @param Invoice $invoice
         * @param array   $snapshot
         * @param string  $profileId
         */
        do_action('jankx/einvoice/document_built', $invoice, $snapshot, $this->getId());

        return $invoice;
    }

    // ── Step 1: parties ──────────────────────────────────────────────────────

    /**
     * @return InvoiceParty
     */
    protected function resolveSeller(array $snapshot): InvoiceParty
    {
        return new InvoiceParty(array_merge(
            ['role' => InvoiceParty::ROLE_SELLER],
            is_array($snapshot['seller'] ?? null) ? $snapshot['seller'] : []
        ));
    }

    /**
     * @return InvoiceParty
     */
    protected function resolveBuyer(array $snapshot): InvoiceParty
    {
        $buyer = new InvoiceParty(array_merge(
            ['role' => InvoiceParty::ROLE_BUYER],
            is_array($snapshot['buyer'] ?? null) ? $snapshot['buyer'] : []
        ));

        if ($buyer->getName() !== '') {
            return $buyer;
        }

        // No jurisdiction accepts a blank buyer block, so the name is always
        // filled. Where the law prescribes specific wording for an unidentified
        // consumer (Vietnam) that wins; otherwise a neutral label is used and
        // the invoice is flagged so the admin can see it needs attention.
        $text = $this->getAnonymousBuyerText();
        if ($text === null) {
            $text = __('Consumer', 'e-invoice');
        }

        return $buyer->with([
            'name'             => $text,
            'generic_consumer' => true,
        ]);
    }

    // ── Step 2: lines ────────────────────────────────────────────────────────

    /**
     * @param string $currency
     * @return InvoiceLine[]
     */
    protected function resolveLines(array $snapshot, string $currency): array
    {
        $raw     = is_array($snapshot['lines'] ?? null) ? $snapshot['lines'] : [];
        $default = $this->getDefaultTaxRate();

        $lines = [];
        foreach ($raw as $index => $line) {
            $line = is_array($line) ? $line : [];

            $rate = array_key_exists('tax_rate', $line) ? (float) $line['tax_rate'] : $default;

            $lines[] = $this->mapLine($line, $rate, $index, $currency);
        }

        return $lines;
    }

    /**
     * Map one order line onto an invoice line.
     *
     * @param array  $line
     * @param float  $rate
     * @param int    $index
     * @param string $currency
     * @return InvoiceLine
     */
    protected function mapLine(array $line, float $rate, int $index, string $currency): InvoiceLine
    {
        return new InvoiceLine([
            'description'    => (string) ($line['description'] ?? ''),
            'unit'           => (string) ($line['unit'] ?? $this->getUnitLabel()),
            'quantity'       => (float) ($line['quantity'] ?? 0),
            // The snapshot already holds tax-exclusive prices; see
            // OrderSnapshotFactory for the derivation.
            'unit_price'     => (float) ($line['unit_price'] ?? 0),
            'tax_rate'       => $rate,
            'tax_rate_label' => (string) ($line['tax_rate_label'] ?? InvoiceLine::formatRate($rate)),
            'product_code'   => (string) ($line['product_code'] ?? ''),
            'product_type'   => (string) ($line['product_type'] ?? ''),
            'meta'           => is_array($line['meta'] ?? null) ? $line['meta'] : [],
            'currency'       => $currency,
        ]);
    }

    /**
     * Fallback tax rate when the snapshot does not assign one per line.
     */
    protected function getDefaultTaxRate(): float
    {
        return 0.0;
    }

    // ── Step 3: totals ───────────────────────────────────────────────────────

    /**
     * Derive the invoice money figures from the order.
     *
     * The snapshot guarantees the invariant this relies on: `gross_total` is
     * tax-inclusive. So the tax-exclusive revenue is simply gross / (1 + Σrates).
     *
     * @param InvoiceLine[] $lines
     */
    protected function deriveTotals(array $snapshot, array $lines, string $currency): InvoiceTotals
    {
        $gross       = (float) ($snapshot['gross_total'] ?? 0);
        $rateSum     = (float) ($snapshot['rate_sum'] ?? 0);
        $isExclusive = !empty($snapshot['prices_exclusive']);

        $net = $rateSum > 0 ? Amount::round($gross / (1 + $rateSum), $currency) : $gross;
        $tax = Amount::round($gross - $net, $currency);

        // Discounts are never persisted on the order row, so they are
        // reconstructed: the lines were priced before any discount, and the
        // total was charged after it. Both figures are tax-exclusive here, so
        // the gap is the discount on the same basis regardless of the shop's
        // inclusive/exclusive setting.
        //
        //   inclusive: net lines = quoted / (1 + Σr),  net charged = total / (1 + Σr)
        //   exclusive: net lines = quoted,             net charged = total / (1 + Σr)
        $linesGross = 0.0;
        foreach ($lines as $line) {
            $linesGross += $line->getNetAmount();
        }

        $discount = Amount::round(max(0, $linesGross - $net), $currency);

        return new InvoiceTotals([
            'currency'             => $currency,
            'gross_subtotal'       => Amount::round($linesGross, $currency),
            'discount_total'       => $discount,
            'net_total'            => $net,
            'tax_total'            => $tax,
            'grand_total'          => $gross,
            'fee_total'            => (float) ($snapshot['fee_total'] ?? 0),
            'prices_are_exclusive' => $isExclusive,
            'rate_sum'             => $rateSum,
        ]);
    }

    /**
     * Force the line amounts to sum to the invoice's pre-tax total.
     *
     * A coupon reduces the charged total without telling us which lines it hit,
     * so the line amounts we derived from the order prices are larger than the
     * total. Left alone the invoice would fail its own reconciliation check,
     * which on a legal document is a hard stop rather than a cosmetic issue.
     *
     * The pre-tax total is therefore authoritative: it is distributed across the
     * lines in proportion to their pre-discount value, using exact minor-unit
     * arithmetic ({@see Amount::distribute()}), so the lines always re-sum to
     * the total exactly. The per-line unit price is then re-derived from the
     * allocation so the printed columns stay consistent with each other.
     *
     * @param InvoiceLine[] $lines
     * @return InvoiceLine[]
     */
    protected function reconcileLines(array $lines, InvoiceTotals $totals, string $currency): array
    {
        if (!$lines) {
            return $lines;
        }

        $target = $totals->getNetTotal();
        $weights = [];
        foreach ($lines as $index => $line) {
            $weights[$index] = $line->getNetAmount();
        }

        $quoted = array_sum($weights);
        if ($quoted <= 0 || abs(Amount::round($quoted, $currency) - $target) < PHP_FLOAT_EPSILON) {
            return $lines;
        }

        $allocated = Amount::distribute($target, $weights, $currency);

        $out = [];
        foreach ($lines as $index => $line) {
            $netAmount = (float) $allocated[$index];

            $out[] = $line->with([
                'net_amount' => $netAmount,
                // Re-derive the printed unit price from the allocation so that
                // unit price × quantity agrees with the line amount. This can
                // carry sub-unit rounding for fractional quantities, which is
                // why the line amount column is the authoritative figure.
                'unit_price' => $line->getQuantity() > 0
                    ? $netAmount / $line->getQuantity()
                    : $line->getUnitPrice(),
            ]);
        }

        return $out;
    }

    // ── Step 4: tax summary ──────────────────────────────────────────────────

    /**
     * Group the tax by rate.
     *
     * @param InvoiceLine[] $lines
     * @return InvoiceTaxLine[]
     */
    protected function buildTaxLines(array $lines, InvoiceTotals $totals, string $currency): array
    {
        if (!$this->requiresTaxBreakdownByRate()) {
            return [new InvoiceTaxLine([
                'rate'           => $totals->getRateSum(),
                'rate_label'     => InvoiceLine::formatRate($totals->getRateSum()),
                'taxable_amount' => $totals->getNetTotal(),
                'tax_amount'     => $totals->getTaxTotal(),
                'currency'       => $currency,
            ])];
        }

        // Bucket lines by rate so each rate gets one summary row, as required
        // for the "tổng số tiền thuế … theo từng loại thuế suất" field.
        $buckets = [];
        foreach ($lines as $line) {
            $key = (string) $line->getTaxRate();
            if (!isset($buckets[$key])) {
                $buckets[$key] = [
                    'rate'  => $line->getTaxRate(),
                    'label' => $line->getTaxRateLabel(),
                    'net'   => 0.0,
                ];
            }
            $buckets[$key]['net'] += $line->getNetAmount();
        }

        ksort($buckets, SORT_NUMERIC);

        // Compute each bucket's nominal tax, then distribute the invoice's tax
        // total across the buckets in proportion. Both steps matter:
        //
        //   • rounding each bucket independently can make the summary disagree
        //     with the total (gross 7 @ 8% → net 6, 6 × 0.08 = 0.48 → 0, while
        //     the invoice's tax is 7 − 6 = 1);
        //   • NĐ 254/2026 Art. 10(1)(đ) requires the per-rate tax figures to
        //     add up to the total, so the total is authoritative and must win.
        //
        // Amount::distribute() works in integer minor units, so the buckets
        // always re-sum to the total exactly.
        $weights = [];
        foreach ($buckets as $key => $bucket) {
            $weights[$key] = $bucket['net'] * $bucket['rate'];
        }

        $taxAmounts = $totals->getTaxTotal() > 0
            ? Amount::distribute($totals->getTaxTotal(), $weights, $currency)
            : array_map(static function () { return 0.0; }, $weights);

        $out = [];
        foreach ($buckets as $key => $bucket) {
            $out[] = new InvoiceTaxLine([
                'rate'           => $bucket['rate'],
                'rate_label'     => $bucket['label'] !== '' ? $bucket['label'] : InvoiceLine::formatRate($bucket['rate']),
                'taxable_amount' => Amount::round($bucket['net'], $currency),
                'tax_amount'     => (float) $taxAmounts[$key],
                'currency'       => $currency,
            ]);
        }

        return $out;
    }

    // ── Step 5: numbering ────────────────────────────────────────────────────

    protected function allocateNumber(string $issuedAt): array
    {
        return $this->getNumberGenerator()->allocate($this->getInvoiceSeries(), $issuedAt);
    }

    // ── Step 6: amounts in words ─────────────────────────────────────────────

    protected function resolveAmountInWords(InvoiceTotals $totals, string $currency): string
    {
        if (!$this->requiresAmountInWords()) {
            return '';
        }

        return $this->amountToWords($totals->getPayableTotal(), $currency);
    }

    // ── Step 7: country decoration ───────────────────────────────────────────

    /**
     * Last chance for a country profile to attach or override fields.
     *
     * Returns the (possibly new) invoice: the aggregate is immutable, so a
     * hook that wants to change something must hand back a replacement rather
     * than mutating in place. The default implementation records the profile's
     * own metadata so a stored document can later prove which rules produced
     * it.
     */
    protected function mapRequiredFields(Invoice $invoice, array $snapshot): Invoice
    {
        $extra = $invoice->getExtra();
        $extra['required_fields']  = $this->getRequiredFields();
        $extra['country_name']     = $this->getCountryName();
        $extra['locale']           = $this->getLocale();
        $extra['compliance_notes'] = $this->getComplianceNotes();

        return $invoice->with(['extra' => $extra]);
    }
}