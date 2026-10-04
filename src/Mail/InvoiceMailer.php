<?php
namespace Jankx\Extensions\EInvoice\Mail;

use Jankx\Extensions\Ecommerce\Order\Order;
use Jankx\Extensions\EInvoice\Contracts\InvoiceRendererInterface;
use Jankx\Extensions\EInvoice\Model\Invoice;
use Jankx\Extensions\EInvoice\Repository\InvoiceRepositoryInterface;
use Jankx\Extensions\EInvoice\Service\InvoiceDocumentService;

/**
 * Delivers the invoice to the customer by email.
 *
 * ── Why this does not use the notification system ──────────────────────────
 * base-ecommerce sends no email itself: it emits an in-app notification through
 * notification-system, whose EmailChannel calls `wp_mail()` with four arguments
 * and therefore cannot attach a file. An invoice must arrive *with* its document,
 * so this class talks to `wp_mail()` directly and handles attachments itself.
 *
 * Both renderers are sent when possible — a PDF plus an HTML copy. The HTML is
 * not decoration: if a customer cannot open the PDF (phone preview, blocked
 * viewer) the invoice is still legible, and the HTML copy is also what makes the
 * message readable in clients that strip attachments.
 *
 * @package Jankx\Extensions\EInvoice\Mail
 */
class InvoiceMailer
{
    /** @var InvoiceDocumentService */
    protected $documents;

    /** @var InvoiceRepositoryInterface|null Injected only to record delivery. */
    protected $repository;

    public function __construct(InvoiceDocumentService $documents, ?InvoiceRepositoryInterface $repository = null)
    {
        $this->documents  = $documents;
        $this->repository = $repository;
    }

    /**
     * Send the invoice for an order.
     *
     * @param Invoice $invoice
     * @param Order   $order Used for the recipient address and the order link.
     * @return bool
     */
    public function send(Invoice $invoice, $order = null): bool
    {
        if (!get_option('jankx_einvoice_email_enabled', true)) {
            return false;
        }

        $to = $this->recipient($invoice, $order);
        if ($to === '') {
            $this->log('no recipient email for invoice ' . $invoice->getInvoiceNumber());
            return false;
        }

        $attachments = [];
        $rendered = $this->documents->render($invoice);

        if ($rendered === null) {
            $this->log('could not render invoice ' . $invoice->getInvoiceNumber());
            return false;
        }

        /** @var InvoiceRendererInterface $renderer */
        $renderer = $rendered['renderer'];
        $content  = $rendered['content'];

        if ($renderer->isBinary()) {
            $path = $this->documents->cache($invoice, $renderer, $content);
            if ($path !== null) {
                $attachments[] = $path;
            }
        }

        // The body is always HTML. When the configured renderer produced a PDF,
        // the HTML copy is rendered separately — the PDF bytes must never be
        // spliced into a text/html part.
        if ($renderer->isBinary()) {
            $body = $this->documents->renderHtml($invoice);
            if ($body === null) {
                // Without an HTML body there is still a deliverable attachment.
                $this->log('invoice ' . $invoice->getInvoiceNumber()
                    . ' has a PDF but no HTML body; sending attachment only');
                $body = '';
            }
        } else {
            $body = $content;
        }

        $html = $body === ''
            ? $this->buildHtml($invoice, $order, '')
            : ($renderer->isBinary() ? $this->buildHtml($invoice, $order, $body) : $body);

        $subject = $this->subject($invoice);

        /**
         * Filter the invoice email arguments immediately before sending.
         *
         * @param array $args {
         *     @type string[] $to
         *     @type string   $subject
         *     @type string   $html
         *     @type string[] $attachments
         * }
         * @param Invoice $invoice
         * @param mixed   $order
         */
        $args = (array) apply_filters('jankx/einvoice/mail_args', [
            'to'          => [$to],
            'subject'     => $subject,
            'html'        => $html,
            'attachments' => $attachments,
        ], $invoice, $order);

        $to = $this->sanitiseList($args['to'] ?? [$to]);
        if (!$to) {
            return false;
        }

        $sent = wp_mail(
            $to,
            (string) ($args['subject'] ?? $subject),
            (string) ($args['html'] ?? $html),
            ['Content-Type: text/html; charset=UTF-8'],
            array_map([$this, 'normalisePath'], (array) ($args['attachments'] ?? []))
        );

        if ($sent) {
            // Record delivery so the admin list can show whether a customer has
            // actually received the document.
            $invoice->setEmailedAt(current_time('mysql'));
            if ($this->repository) {
                $this->repository->update($invoice);
            }

            /**
             * Fires after the invoice email has been handed to wp_mail().
             *
             * @param Invoice $invoice
             * @param bool    $sent
             */
            do_action('jankx/einvoice/mail_sent', $invoice, $sent);
        } else {
            $this->log('wp_mail() rejected the invoice email for ' . $invoice->getInvoiceNumber());
        }

        return (bool) $sent;
    }

    /**
     * Recipient address: the buyer's email from the invoice snapshot, falling
     * back to the order's customer email.
     */
    protected function recipient(Invoice $invoice, $order = null): string
    {
        $email = $invoice->getBuyer()->getEmail();

        if ($email === '' && $order !== null && method_exists($order, 'getCustomerEmail')) {
            $email = (string) $order->getCustomerEmail();
        }

        return sanitize_email($email);
    }

    protected function subject(Invoice $invoice): string
    {
        $title = $invoice->getProfileId() === 'vn'
            ? __('Hóa đơn', 'e-invoice')
            : __('Invoice', 'e-invoice');

        $subject = sprintf(
            /* translators: 1: site name, 2: document title, 3: invoice number */
            __('[%1$s] %2$s %3$s', 'e-invoice'),
            get_bloginfo('name'),
            $title,
            $invoice->getInvoiceNumber()
        );

        return (string) apply_filters('jankx/einvoice/mail_subject', $subject, $invoice);
    }

    /**
     * Wrap the invoice document in the surrounding email shell.
     *
     * @param string $document Rendered invoice HTML.
     */
    protected function buildHtml(Invoice $invoice, $order, string $document): string
    {
        $title = $invoice->getProfileId() === 'vn'
            ? __('Hóa đơn của bạn', 'e-invoice')
            : __('Your invoice', 'e-invoice');

        $intro = $invoice->getProfileId() === 'vn'
            ? __('Cảm ơn bạn. Hóa đơn điện tử được đính kèm và hiển thị bên dưới.', 'e-invoice')
            : __('Thank you for your purchase. Your invoice is attached and shown below.', 'e-invoice');

        $link = '';
        if ($order !== null && method_exists($order, 'getOrderNumber')) {
            $url = $this->orderUrl($order);
            if ($url !== '') {
                $link = sprintf(
                    '<p style="margin:16px 0 0"><a href="%s" style="color:#111827">%s</a></p>',
                    esc_url($url),
                    esc_html__('Xem đơn hàng', 'e-invoice')
                );
            }
        }

        $html = sprintf(
            '<div style="font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;background:#eef1f5;padding:24px 12px">'
            . '<div style="max-width:800px;margin:0 auto;background:#fff;border-radius:8px;padding:20px 16px">'
            . '<h1 style="margin:0 0 4px;font-size:18px">%s</h1>'
            . '<p style="margin:0 0 12px;color:#6b7280;font-size:13px">%s</p>'
            . '%s%s'
            . '</div></div>',
            esc_html($title),
            esc_html($intro),
            $document,
            $link
        );

        /**
         * Filter the full invoice email HTML.
         *
         * @param string $html
         * @param Invoice $invoice
         * @param mixed   $order
         */
        return (string) apply_filters('jankx/einvoice/mail_html', $html, $invoice, $order);
    }

    /**
     * Deep link to the order page in My Account.
     */
    protected function orderUrl($order): string
    {
        if (!function_exists('wc_get_account_endpoint_url') && !class_exists('WC')) {
            return '';
        }

        $accountPageId = (int) get_option('jankx_my_account_page_id');
        if ($accountPageId <= 0) {
            return '';
        }

        $accountUrl = get_permalink($accountPageId);
        if (!$accountUrl) {
            return '';
        }

        return trailingslashit($accountUrl) . 'orders/' . rawurlencode($order->getOrderNumber()) . '/';
    }

    /**
     * @param mixed $addresses
     * @return string[]
     */
    protected function sanitiseList($addresses): array
    {
        $out = [];
        foreach ((array) $addresses as $address) {
            $email = sanitize_email((string) $address);
            if ($email && is_email($email)) {
                $out[] = $email;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * wp_mail() requires real filesystem paths for attachments.
     */
    protected function normalisePath($path): string
    {
        return (string) $path;
    }

    protected function log(string $message): void
    {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[EInvoice] ' . $message);
        }
    }
}