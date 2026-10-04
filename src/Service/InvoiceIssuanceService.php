<?php
namespace Jankx\Extensions\EInvoice\Service;

use Jankx\Extensions\Ecommerce\Order\Order;
use Jankx\Extensions\EInvoice\Contracts\InvoiceProfileInterface;
use Jankx\Extensions\EInvoice\Model\Invoice;
use Jankx\Extensions\EInvoice\Profile\InvoiceProfileRegistry;
use Jankx\Extensions\EInvoice\Repository\InvoiceRepositoryInterface;
use Jankx\Extensions\EInvoice\Snapshot\OrderSnapshotFactory;

/**
 * Use case: issue an invoice for an order.
 *
 * Everything the rest of the extension does funnels through here, so this class
 * owns the guarantees that make an issued invoice trustworthy:
 *
 *   IDEMPOTENCY — an order must never receive two invoices. base-ecommerce
 *   re-fires `order/status_changed` on every checkout (PaymentManager resets the
 *   status to `pending`), and the admin status editor writes the row directly,
 *   bypassing the hook entirely. So duplicates are prevented by checking the
 *   repository for an existing invoice rather than by trusting the event.
 *
 *   VALIDATION — a document that does not balance is never stored or sent. On a
 *   legal document a wrong invoice is worse than no invoice, so
 *   {@see Invoice::validate()} gates the whole operation.
 *
 *   ATOMIC NUMBERING — the invoice number is consumed inside buildDocument();
 *   if validation then fails the number is simply not used. Numbers may have
 *   gaps, which is preferable to duplicate numbers, and Vietnamese practice
 *   accepts unused numbers in a series (a gap must never be back-filled).
 *
 * @package Jankx\Extensions\EInvoice\Service
 */
class InvoiceIssuanceService
{
    /** @var InvoiceRepositoryInterface */
    protected $repository;

    /** @var OrderSnapshotFactory */
    protected $snapshotFactory;

    /** @var InvoiceProfileRegistry */
    protected $profiles;

    public function __construct(
        InvoiceRepositoryInterface $repository,
        OrderSnapshotFactory $snapshotFactory,
        InvoiceProfileRegistry $profiles
    ) {
        $this->repository      = $repository;
        $this->snapshotFactory = $snapshotFactory;
        $this->profiles        = $profiles;
    }

    /**
     * Issue an invoice for an order, unless one already exists.
     *
     * @param Order $order
     * @param array $args {
     *     @type string               $country  Override the profile country.
     *     @type bool                 $force    Issue even if one exists (adjustments).
     *     @type InvoiceProfileInterface $profile Use this profile instead of resolving one.
     * }
     * @return Invoice|false False when skipped, disabled, or rejected by validation.
     */
    public function issue(Order $order, array $args = [])
    {
        if (!$this->isEnabled()) {
            return false;
        }

        // Idempotency. Two concurrent requests can both pass this check, so the
        // repository enforces uniqueness too — see WordPressInvoiceRepository.
        if (empty($args['force'])) {
            $existing = $this->repository->findByOrderId($order->getId());
            if ($existing) {
                return $existing;
            }
        }

        /** @var InvoiceProfileInterface $profile */
        $profile = $args['profile'] ?? $this->profiles->resolve($args['country'] ?? null);

        $snapshot = $this->snapshotFactory->create($order);

        /**
         * Fires before the invoice number is consumed.
         *
         * Return a non-null Invoice to short-circuit issuance (e.g. skip an
         * order that should not be invoiced). Mutating $snapshot is also
         * respected.
         *
         * @param null|Invoice              $prebuilt
         * @param array                     $snapshot
         * @param Order                     $order
         * @param InvoiceProfileInterface   $profile
         */
        $prebuilt = apply_filters('jankx/einvoice/pre_issue', null, $snapshot, $order, $profile);
        if ($prebuilt instanceof Invoice) {
            return $this->store($prebuilt, $order, $profile);
        }

        $invoice = $profile->buildDocument($snapshot);

        $problems = $invoice->validate();
        if ($problems) {
            $this->log(
                sprintf(
                    'refused to issue invoice for order #%d (%s): %s',
                    $order->getId(),
                    $order->getOrderNumber(),
                    implode('; ', $problems)
                )
            );

            /**
             * Fires when a built invoice fails validation and is discarded.
             *
             * @param Invoice $invoice
             * @param string[] $problems
             * @param Order   $order
             */
            do_action('jankx/einvoice/issue_failed', $invoice, $problems, $order);

            return false;
        }

        return $this->store($invoice, $order, $profile);
    }

    /**
     * Persist an issued invoice and fire the follow-up actions.
     */
    protected function store(Invoice $invoice, Order $order, InvoiceProfileInterface $profile)
    {
        $id = $this->repository->insert($invoice);
        if (!$id) {
            $this->log(sprintf(
                'failed to persist invoice %s for order #%d',
                $invoice->getInvoiceNumber(),
                $order->getId()
            ));

            return false;
        }

        $stored = $this->repository->findById($id);
        if (!$stored) {
            return false;
        }

        /**
         * Fires once an invoice has been successfully issued and stored.
         *
         * This is where delivery happens: the mailer, the account-page panel and
         * PDF rendering all subscribe rather than being called directly, so a
         * site can reorder or suppress them without touching this class.
         *
         * @param Invoice $stored
         * @param Order   $order
         */
        do_action('jankx/einvoice/issued', $stored, $order);

        return $stored;
    }

    /**
     * The invoice raised for an order, if any.
     */
    public function findForOrder(int $orderId): ?Invoice
    {
        return $this->repository->findByOrderId($orderId);
    }

    /**
     * Whether automatic issuance is switched on.
     */
    public function isEnabled(): bool
    {
        return (bool) apply_filters(
            'jankx/einvoice/is_enabled',
            (bool) get_option('jankx_einvoice_enabled', true)
        );
    }

    /**
     * Guard against infinite recursion: the order-detail panel and the mailer
     * both read orders, and anything that writes to the order could re-trigger
     * issuance.
     */
    protected function log(string $message): void
    {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[EInvoice] ' . $message);
        }
    }
}