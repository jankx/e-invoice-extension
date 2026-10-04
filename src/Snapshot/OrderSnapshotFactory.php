<?php
namespace Jankx\Extensions\EInvoice\Snapshot;

use Jankx\Extensions\Ecommerce\Order\Order;
use Jankx\Extensions\Ecommerce\Tax\TaxManager;
use Jankx\Extensions\EInvoice\Contracts\PartyIdentityResolverInterface;

/**
 * Turns a base-ecommerce Order into the flat array a country profile consumes.
 *
 * ── Why this class exists ───────────────────────────────────────────────────
 * An {@see \Jankx\Extensions\EInvoice\Model\Invoice} must stay reproducible
 * forever, but `jankx_orders` is lossy. It stores a single `total` and nothing
 * about tax. So this factory performs the one-time interpretation that has to
 * happen at issue time and records the result on the invoice:
 *
 *   • which VAT rate applied, and whether prices were tax-inclusive
 *   • the tax-exclusive unit price of every line
 *   • the discount implied by the difference between quoted and charged
 *
 * ── The tax maths ───────────────────────────────────────────────────────────
 * base-ecommerce's `total` is always tax-inclusive, under both strategies:
 *
 *   inclusive: total = subtotal − discount                    (tax inside)
 *   exclusive: total = subtotal − discount + taxAmount        (tax on top)
 *
 * Therefore, with Σ rates = the sum of the active rates:
 *
 *   net  = total / (1 + Σ rates)
 *   unit price (net) = stored unit price / (1 + Σ rates)      [inclusive case]
 *
 * base-ecommerce has no per-product or per-line tax rate and no tax columns on
 * the order, so the same rate set applies to every line. That is recorded here
 * rather than assumed downstream, and the whole tax block is exposed to
 * `jankx/einvoice/snapshot` so an extension that *does* track per-line tax can
 * override it.
 *
 * @package Jankx\Extensions\EInvoice\Snapshot
 */
class OrderSnapshotFactory
{
    /** @var PartyIdentityResolverInterface */
    protected $sellerResolver;

    /** @var PartyIdentityResolverInterface */
    protected $buyerResolver;

    public function __construct(
        ?PartyIdentityResolverInterface $sellerResolver = null,
        ?PartyIdentityResolverInterface $buyerResolver = null
    ) {
        $this->sellerResolver = $sellerResolver ?: new SellerIdentityResolver();
        $this->buyerResolver  = $buyerResolver ?: new BuyerIdentityResolver();
    }

    /**
     * @param Order $order
     * @return array
     */
    public function create(Order $order): array
    {
        $tax = $this->taxContext();

        $snapshot = [
            'order_id'          => $order->getId(),
            'order_number'      => $order->getOrderNumber(),
            'order_status'      => $order->getStatus(),
            'currency'          => $order->getCurrency() ?: 'VND',
            'issued_at'         => current_time('mysql'),
            'order_created_at'  => $order->getDateCreated(),
            'payment_method'    => $order->getPaymentMethod(),
            'payment_txn_id'    => (string) $order->getPaymentTransactionId(),
            'tracking_number'   => (string) $order->getTrackingNumber(),

            // Order row, needed by the identity resolvers.
            'order'             => $this->orderRow($order),

            // Money.
            'gross_total'       => (float) $order->getTotal(),
            'fee_total'         => 0.0,

            // Tax interpretation.
            'tax_enabled'       => $tax['enabled'],
            'prices_exclusive'  => $tax['exclusive'],
            'rates'             => $tax['rates'],
            'rate_sum'          => $tax['rate_sum'],
            'default_rate'      => $tax['default_rate'],

            // Parties.
            'seller'            => [],
            'buyer'             => [],
            'lines'             => [],

            'tax_authority_code' => (string) get_option('jankx_einvoice_tax_authority_code', ''),
        ];

        $snapshot['lines']     = $this->buildLines($order, $tax);
        $snapshot['seller']    = $this->sellerResolver->resolve($snapshot);
        $snapshot['buyer']     = $this->buyerResolver->resolve($snapshot);

        /**
         * Filter the order snapshot before it is turned into a document.
         *
         * This is the supported way to attach per-line tax, adjust the discount
         * reconstruction, or add fields your jurisdiction needs. Anything not
         * set here is recomputed from `jankx_orders` and is not recoverable
         * later, so override anything you need reproduced verbatim.
         *
         * @param array $snapshot
         * @param Order $order
         */
        return (array) apply_filters('jankx/einvoice/snapshot', $snapshot, $order);
    }

    /**
     * Read the raw order row so resolvers can see fields the Order object
     * does not expose as getters (notably `notes`, used for per-order billing).
     *
     * @return array
     */
    protected function orderRow(Order $order): array
    {
        if (method_exists($order, 'toArray')) {
            return (array) $order->toArray();
        }

        return [
            'customer_id'     => $order->getCustomerId(),
            'customer_name'   => $order->getCustomerName(),
            'customer_email'  => $order->getCustomerEmail(),
            'customer_phone'  => $order->getCustomerPhone(),
            'customer_address' => $order->getCustomerAddress(),
        ];
    }

    /**
     * Convert order items into pre-tax invoice lines.
     *
     * @return array<int, array>
     */
    protected function buildLines(Order $order, array $tax): array
    {
        $factor = 1 + $tax['rate_sum'];
        $lines  = [];

        foreach ($order->getItems() as $index => $item) {
            $grossUnit = (float) $item->getUnitPrice();

            // Inclusive: the stored price already contains tax, so strip it.
            // Exclusive: the stored price is already the pre-tax figure.
            $netUnit = $tax['exclusive'] || $factor <= 0
                ? $grossUnit
                : $grossUnit / $factor;

            $meta = $item->getMeta();

            $lines[] = [
                'description'    => (string) $item->getName(),
                'unit'           => $this->unitLabel($meta, $tax),
                'quantity'       => (float) $item->getQuantity(),
                'unit_price'     => $netUnit,
                'tax_rate'       => $tax['default_rate'],
                'tax_rate_label' => $this->rateLabel($tax),
                'product_code'   => (string) ($meta['sku'] ?? $meta['product_code'] ?? ''),
                'product_type'   => (string) $item->getProductType(),
                'meta'           => $meta,

                // Kept so the discount reconstruction and any extension
                // filter can still see the original charged figures.
                'gross_unit_price' => $grossUnit,
                'gross_line_total' => $grossUnit * (float) $item->getQuantity(),
            ];
        }

        return $lines;
    }

    /**
     * Which VAT regime applies right now.
     *
     * Read live rather than from the order, because the order records neither.
     * The result is written onto the invoice, so the document remains
     * self-describing even if the shop's tax settings change afterwards.
     *
     * @return array
     */
    protected function taxContext(): array
    {
        $enabled   = (bool) get_option('jankx_tax_enabled', false);
        $exclusive = $enabled
            && (string) get_option('jankx_tax_strategy', 'inclusive') === 'exclusive';

        $rates  = [];
        $sum    = 0.0;
        $first  = 0.0;

        if ($enabled && class_exists(TaxManager::class)) {
            $manager = TaxManager::get_instance();

            foreach ($manager->getRates() as $rate) {
                $rates[] = [
                    'id'    => $rate->id,
                    'name'  => $rate->name,
                    'rate'  => (float) $rate->rate,
                ];
                $sum += (float) $rate->rate;
            }

            if ($manager->getStrategy()->isExclusive()) {
                $exclusive = true;
            }

            // base-ecommerce applies every registered rate to every line, so
            // the "default" rate for a line is really the sum of all of them.
            $first = $rates ? (float) $rates[0]['rate'] : 0.0;
        }

        return [
            'enabled'      => $enabled,
            'exclusive'    => $exclusive,
            'rates'        => $rates,
            'rate_sum'     => $sum,
            'default_rate' => $rates ? $sum : $first,
            'single_label' => $rates ? $rates[0]['name'] : '',
        ];
    }

    protected function rateLabel(array $tax): string
    {
        return $tax['single_label'] !== ''
            ? (string) $tax['single_label']
            : \Jankx\Extensions\EInvoice\Model\InvoiceLine::formatRate($tax['default_rate']);
    }

    /**
     * Unit of measure for a line, if the product declared one.
     */
    protected function unitLabel(array $meta, array $tax): string
    {
        $unit = (string) ($meta['unit'] ?? $meta['unit_label'] ?? '');

        return $unit !== '' ? $unit : __('Unit', 'e-invoice');
    }
}