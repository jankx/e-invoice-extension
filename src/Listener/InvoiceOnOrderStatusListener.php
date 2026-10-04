<?php
namespace Jankx\Extensions\EInvoice\Listener;

use Jankx\Extensions\Ecommerce\Order\Order;
use Jankx\Extensions\EInvoice\Service\InvoiceIssuanceService;

/**
 * Decides *when* an invoice is raised, by observing the order lifecycle.
 *
 * Kept separate from the issuance service (which owns *how*) so the trigger
 * policy is visible in one readable place and can be changed without touching
 * invoice construction.
 *
 * ── Trigger modes ───────────────────────────────────────────────────────────
 * `on_payment`    — the moment a gateway confirms payment.
 * `on_shipping`   — when the order is handed to a carrier.
 * `on_completed`  — when the order is marked complete (the default).
 *
 * ── Three ways an order reaches a triggerable state ─────────────────────────
 * base-ecommerce does not have a single canonical "order done" event, so all
 * three paths are covered:
 *
 *   1. `order/status_changed` — the normal path, fired by Order::updateStatus().
 *   2. `payment/paid` — the on_payment trigger, for gateways that confirm
 *      settlement without moving the order status.
 *   3. an admin reconciliation pass — necessary because OrderAdmin saves status
 *      changes with OrderModel::update() directly, bypassing updateStatus(), so
 *      no hook fires at all when a shop admin completes an order. Without this,
 *      invoices would be missing for exactly the orders that staff fulfil by
 *      hand. See {@see reconcileAdminStatusChange()}.
 *
 * @package Jankx\Extensions\EInvoice\Listener
 */
class InvoiceOnOrderStatusListener
{
    /** Transient used to carry an admin status change across the redirect. */
    const PENDING_CHECK = 'jankx_einvoice_pending_status_';

    /** @var InvoiceIssuanceService */
    protected $issuance;

    public function __construct(InvoiceIssuanceService $issuance)
    {
        $this->issuance = $issuance;
    }

    public function register(): void
    {
        add_action('jankx/ecommerce/order/status_changed', [$this, 'onStatusChanged'], 10, 3);
        add_action('jankx/ecommerce/payment/paid', [$this, 'onPaymentPaid'], 10, 2);

        // The admin path writes the row directly, so it needs arming before the
        // handler runs and settling after the redirect.
        add_action('admin_init', [$this, 'onAdminInit'], 20);
    }

    // ── Trigger paths ────────────────────────────────────────────────────────

    /**
     * @param Order  $order
     * @param string $newStatus
     * @param string $oldStatus
     */
    public function onStatusChanged($order, string $newStatus = '', string $oldStatus = ''): void
    {
        if (!$this->matchesTrigger($newStatus)) {
            return;
        }

        $this->issue($order, 'status:' . $newStatus);
    }

    /**
     * @param Order $order
     * @param int|string $transactionId
     */
    public function onPaymentPaid($order, $transactionId = 0): void
    {
        if (!$this->matchesTrigger('on_payment')) {
            return;
        }

        $this->issue($order, 'payment:' . $transactionId);
    }

    // ── Admin reconciliation ──────────────────────────────────────────────────

    /**
     * Both arms of the admin reconciliation.
     *
     * On the submitting request the pending status is recorded; on the
     * redirected request it is consumed and compared against the database. The
     * comparison is what makes this safe: if the update was rejected (illegal
     * transition, say) the stored status will not match and no invoice is
     * issued.
     */
    public function onAdminInit(): void
    {
        if (!$this->isOrdersScreen()) {
            return;
        }

        if (!empty($_POST['order_status'])) {
            $this->armAdminStatusChange();
            return;
        }

        $this->settleAdminStatusChange();
    }

    /**
     * Record the status the admin is about to set. Runs before the handler,
     * which ends in `exit`, so nothing after it in that request can be relied on.
     */
    protected function armAdminStatusChange(): void
    {
        $orderId   = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
        $newStatus = sanitize_key(wp_unslash($_POST['order_status']));

        if (!$orderId || $newStatus === '') {
            return;
        }

        set_transient(
            $this->pendingKey($orderId),
            [
                'status'  => $newStatus,
                'user_id' => get_current_user_id(),
            ],
            MINUTE_IN_SECONDS * 5
        );
    }

    /**
     * Consume the recorded status and issue if the order really did land there.
     */
    protected function settleAdminStatusChange(): void
    {
        $orderId = isset($_GET['view']) ? absint($_GET['view']) : 0;
        if (!$orderId) {
            return;
        }

        $key = $this->pendingKey($orderId);
        $pending = get_transient($key);
        if (!is_array($pending)) {
            return;
        }

        // One shot: never let a stale record fire twice.
        delete_transient($key);

        $order = new Order($orderId);
        if (!$order->getId()) {
            return;
        }

        // If the update was rejected the stored status will not be the one the
        // admin tried to set, so nothing is issued.
        if ($order->getStatus() !== $pending['status']) {
            return;
        }

        if (!$this->matchesTrigger($pending['status'])) {
            return;
        }

        $this->issue($order, 'admin:' . $pending['status']);
    }

    protected function pendingKey(int $orderId): string
    {
        return self::PENDING_CHECK . $orderId . '_' . get_current_user_id();
    }

    protected function isOrdersScreen(): bool
    {
        return isset($_GET['page']) && 'jankx-orders' === $_GET['page'];
    }

    // ── Trigger policy ────────────────────────────────────────────────────────

    /**
     * Whether an event should trigger issuance.
     *
     * @param string $event Status name, or "on_payment".
     */
    protected function matchesTrigger(string $event): bool
    {
        $configured = (string) get_option('jankx_einvoice_trigger', 'on_completed');

        /**
         * Filter the configured issuance trigger.
         *
         * @param string $configured One of on_payment|on_shipping|on_completed.
         * @param string $event      The event that just occurred.
         */
        $configured = (string) apply_filters('jankx/einvoice/trigger', $configured, $event);

        if ($configured === $event) {
            return true;
        }

        return $this->statusToTrigger($event) === $configured;
    }

    /**
     * Map a lifecycle status onto the trigger it satisfies.
     */
    protected function statusToTrigger(string $status): string
    {
        switch ($status) {
            case 'completed':
                return 'on_completed';
            case 'shipping':
                return 'on_shipping';
            default:
                return $status;
        }
    }

    protected function issue($order, string $reason): void
    {
        if (!$order instanceof Order) {
            return;
        }

        /**
         * Fires just before issuance is attempted.
         *
         * @param Order  $order
         * @param string $reason  Why issuance was triggered.
         */
        do_action('jankx/einvoice/issue_attempt', $order, $reason);

        $this->issuance->issue($order);
    }
}