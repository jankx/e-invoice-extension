<?php
namespace Jankx\Extensions\EInvoice\Admin;

use Jankx\Extensions\Ecommerce\Order\Order;
use Jankx\Extensions\EInvoice\Repository\InvoiceRepositoryInterface;

/**
 * Order-detail panel showing the invoice for an order, with a button to issue
 * one when the automatic trigger never fired.
 *
 * Renders into the `jankx/ecommerce/order_detail/after_content` extension point
 * at the bottom of the base-ecommerce order screen.
 *
 * @package Jankx\Extensions\EInvoice\Admin
 */
class InvoiceOrderPanel
{
    /** @var InvoiceRepositoryInterface */
    protected $repository;

    public function __construct(InvoiceRepositoryInterface $repository)
    {
        $this->repository = $repository;
    }

    public function register(): void
    {
        add_action('jankx/ecommerce/order_detail/after_content', [$this, 'render'], 10, 1);
    }

    public function render($order): void
    {
        if (!$order instanceof Order || !current_user_can('manage_woocommerce')) {
            return;
        }

        $invoice = $this->repository->findByOrderId($order->getId());

        echo '<div class="jankx-einvoice-order-panel postbox">';
        echo '<h2 class="hndle"><span>' . esc_html__('Hóa đơn điện tử', 'e-invoice') . '</span></h2>';
        echo '<div class="inside">';

        if ($invoice) {
            printf(
                '<p>%s</p>',
                esc_html(sprintf(
                    /* translators: %s: invoice number */
                    __('Đơn hàng này đã có hóa đơn %s.', 'e-invoice'),
                    $invoice->getDisplayNumber()
                ))
            );

            printf(
                '<p><a class="button" href="%s">%s</a></p>',
                esc_url(admin_url('admin.php?page=' . InvoiceListPage::PAGE_SLUG . '&view=' . $invoice->getId())),
                esc_html__('Xem hóa đơn', 'e-invoice')
            );

            echo '</div></div>';

            return;
        }

        echo '<p>' . esc_html__(
            'Đơn hàng này chưa có hóa đơn. Điều này xảy ra khi đơn đã hoàn tất trước khi extension được cài đặt, hoặc thời điểm phát hành chưa tới.',
            'e-invoice'
        ) . '</p>';

        printf(
            '<p><a class="button button-primary" href="%s">%s</a></p>',
            esc_url(InvoiceOrderAction::url($order->getId())),
            esc_html__('Cấp hóa đơn cho đơn này', 'e-invoice')
        );

        echo '</div></div>';
    }
}