<?php
namespace Jankx\Extensions\EInvoice\Account;

use Jankx\Extensions\Ecommerce\Order\Order;
use Jankx\Extensions\EInvoice\Repository\InvoiceRepositoryInterface;

/**
 * Customer-facing invoice panel on the My Account order detail page.
 *
 * Hooks the one extension point base-ecommerce exposes there:
 * `jankx/ecommerce/order_detail/after_payment_info`.
 *
 * Authorization: the surrounding page already verifies that the order belongs to
 * the logged-in user (`AccountTabOrdersBlock::orderBelongsToUser`) before
 * rendering, and only then applies this filter. So the panel never sees an order
 * the visitor does not own, and needs no second ownership check of its own — but
 * the *document* is served from a separate URL, which is why the REST route
 * below re-checks ownership.
 *
 * @package Jankx\Extensions\EInvoice\Account
 */
class InvoiceAccountPanel
{
    /** @var InvoiceRepositoryInterface */
    protected $repository;

    public function __construct(InvoiceRepositoryInterface $repository)
    {
        $this->repository = $repository;
    }

    public function register(): void
    {
        add_filter('jankx/ecommerce/order_detail/after_payment_info', [$this, 'render'], 15, 2);
    }

    /**
     * @param string $content Markup accumulated so far.
     * @param Order  $order
     */
    public function render($content, $order): string
    {
        if (!$order instanceof Order) {
            return (string) $content;
        }

        $invoice = $this->repository->findByOrderId($order->getId());
        if (!$invoice) {
            return (string) $content;
        }

        $viewUrl = $this->viewUrl($invoice->getId());
        if ($viewUrl === '') {
            return (string) $content;
        }

        $content .= sprintf(
            '<div class="jankx-od-card jankx-od-card--invoice" style="margin-top:16px">'
            . '<div class="jankx-od-card-head">'
            . '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">'
            . '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/>'
            . '<path d="M8 13h8M8 17h5"/></svg>'
            . '<h3 class="jankx-od-card-title">%s</h3>'
            . '</div>'
            . '<div style="display:flex;align-items:center;gap:16px;flex-wrap:wrap;padding:4px 0">'
            . '<div><div style="font-size:12px;color:#6b7280">%s</div>'
            . '<div style="font-weight:600;font-size:15px">%s</div></div>'
            . '<a class="je-btn" style="margin-left:auto" href="%s" target="_blank" rel="noopener">%s</a>'
            . '</div>'
            . '</div>',
            esc_html__('Hóa đơn điện tử', 'e-invoice'),
            esc_html__('Số hóa đơn', 'e-invoice'),
            esc_html($invoice->getDisplayNumber()),
            esc_url($viewUrl),
            esc_html__('Xem hóa đơn', 'e-invoice')
        );

        /**
         * Filter the invoice panel markup on the order detail page.
         *
         * @param string  $content Panel HTML.
         * @param Invoice $invoice
         * @param Order   $order
         */
        return (string) apply_filters('jankx/einvoice/account_panel', $content, $invoice, $order);
    }

    /**
     * REST URL of the invoice document.
     */
    public function viewUrl(int $invoiceId): string
    {
        return rest_url('jankx/e-invoice/v1/invoices/' . $invoiceId . '/view');
    }
}