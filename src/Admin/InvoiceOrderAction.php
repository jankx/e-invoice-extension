<?php
namespace Jankx\Extensions\EInvoice\Admin;

use Jankx\Extensions\Ecommerce\Order\Order;
use Jankx\Extensions\EInvoice\Repository\InvoiceRepositoryInterface;
use Jankx\Extensions\EInvoice\Service\InvoiceIssuanceService;

/**
 * Lets a shopkeeper issue an invoice by hand from the order screen.
 *
 * ── Why this exists ──────────────────────────────────────────────────────────
 * Automatic issuance only reacts to *transitions*
 * (`jankx/ecommerce/order/status_changed`, `payment/paid`). An order that
 * completed before this extension was installed never fires either event, so it
 * has no invoice and no code path will ever give it one. Backfilling historical
 * orders is a normal operational need — an accountant reconciling a quarter
 * needs the documents for orders already marked complete — so the action is
 * exposed deliberately rather than left to a migration script.
 *
 * Idempotency still applies: {@see InvoiceIssuanceService::issue()} returns the
 * existing invoice instead of creating a second one, so double-clicking is safe.
 *
 * @package Jankx\Extensions\EInvoice\Admin
 */
class InvoiceOrderAction
{
    const NONCE_ACTION = 'jankx_einvoice_issue_';

    const NOTICE_QUERY = 'jankx_einvoice_notice';

    /** @var InvoiceIssuanceService */
    protected $issuance;

    public function __construct(InvoiceIssuanceService $issuance)
    {
        $this->issuance = $issuance;
    }

    public function register(): void
    {
        add_action('admin_init', [$this, 'maybeIssue'], 30);
        add_action('admin_notices', [$this, 'renderNotice']);
    }

    /**
     * Handles `admin.php?page=jankx-orders&view=<id>&jankx_einvoice_issue=<nonce>`.
     */
    public function maybeIssue(): void
    {
        if (!isset($_GET['page'], $_GET['view'], $_GET['jankx_einvoice_issue'])) {
            return;
        }

        if ($_GET['page'] !== 'jankx-orders') {
            return;
        }

        $orderId = absint($_GET['view']);

        // Nonce is bound to the order id so one order's link cannot be replayed
        // against another.
        if ($orderId <= 0 || !wp_verify_nonce(
            sanitize_text_field(wp_unslash($_GET['jankx_einvoice_issue'])),
            self::NONCE_ACTION . $orderId
        )) {
            $this->redirectWithNotice('invalid');
            return;
        }

        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You do not have permission to issue invoices.', 'e-invoice'));
        }

        $order = new Order($orderId);
        if (!$order->getId()) {
            $this->redirectWithNotice('missing');
            return;
        }

        $result = $this->issuance->issue($order);

        if ($result === false) {
            // Either the automatic switch is off, or the document failed
            // validation. Both are logged with detail by the service.
            $this->redirectWithNotice('refused');
            return;
        }

        $this->redirectWithNotice('issued', $result->getId());
    }

    /**
     * @param string $notice issued|refused|missing|invalid
     * @param int    $invoiceId
     */
    protected function redirectWithNotice(string $notice, int $invoiceId = 0): void
    {
        $url = add_query_arg(
            [
                'page'                     => 'jankx-orders',
                'view'                     => isset($_GET['view']) ? absint($_GET['view']) : 0,
                self::NOTICE_QUERY          => $notice,
                'jankx_einvoice_invoice_id' => $invoiceId,
            ],
            admin_url('admin.php')
        );

        wp_safe_redirect($url);
        exit;
    }

    public function renderNotice(): void
    {
        $notice = isset($_GET[self::NOTICE_QUERY]) ? sanitize_key($_GET[self::NOTICE_QUERY]) : '';
        if ($notice === '') {
            return;
        }

        $invoiceId = isset($_GET['jankx_einvoice_invoice_id']) ? absint($_GET['jankx_einvoice_invoice_id']) : 0;

        switch ($notice) {
            case 'issued':
                $invoice = $invoiceId ? $this->lookup($invoiceId) : null;
                printf(
                    '<div class="notice notice-success is-dismissible"><p>%s</p></div>',
                    $invoice
                        ? esc_html(sprintf(
                            /* translators: %s: invoice number */
                            __('Đã cấp hóa đơn %s.', 'e-invoice'),
                            $invoice->getDisplayNumber()
                        ))
                        : esc_html__('Đã cấp hóa đơn.', 'e-invoice')
                );
                break;

            case 'refused':
                printf(
                    '<div class="notice notice-error"><p>%s</p></div>',
                    esc_html__(
                        'Không thể cấp hóa đơn: hóa đơn dựng ra không cân bằng, hoặc tính năng cấp tự động đang tắt. Chi tiết trong error log (bật WP_DEBUG).',
                        'e-invoice'
                    )
                );
                break;

            case 'missing':
                printf(
                    '<div class="notice notice-error"><p>%s</p></div>',
                    esc_html__('Không tìm thấy đơn hàng.', 'e-invoice')
                );
                break;

            case 'invalid':
                printf(
                    '<div class="notice notice-error"><p>%s</p></div>',
                    esc_html__('Yêu cầu không hợp lệ hoặc phiên đăng nhập đã hết hạn. Hãy thử lại.', 'e-invoice')
                );
                break;
        }
    }

    protected function lookup(int $id)
    {
        static $repository = null;

        if ($repository === null) {
            $repository = new \Jankx\Extensions\EInvoice\Repository\WordPressInvoiceRepository();
        }

        return $repository->findById($id);
    }

    /**
     * Nonced URL that issues an invoice for one order.
     */
    public static function url(int $orderId): string
    {
        return wp_nonce_url(
            add_query_arg(
                [
                    'page'                => 'jankx-orders',
                    'view'                => $orderId,
                    'jankx_einvoice_issue' => 1,
                ],
                admin_url('admin.php')
            ),
            self::NONCE_ACTION . $orderId
        );
    }
}