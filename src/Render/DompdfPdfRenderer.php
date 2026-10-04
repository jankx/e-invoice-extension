<?php
namespace Jankx\Extensions\EInvoice\Render;

use Jankx\Extensions\EInvoice\Contracts\InvoiceRendererInterface;
use Jankx\Extensions\EInvoice\Model\Invoice;

/**
 * Server-side PDF renderer built on Dompdf.
 *
 * Dompdf is an *optional* dependency: the extension declares no hard
 * requirement, so a shop can run without it and still produce invoices (the
 * HTML renderer covers preview, print and the emailed HTML copy). Install it
 * with `composer require dompdf/dompdf` inside this extension to enable true
 * server-side PDFs.
 *
 * The renderer reports itself unavailable rather than throwing, and
 * {@see \Jankx\Extensions\EInvoice\Service\InvoiceDocumentService} falls back to
 * HTML — so a missing optional dependency degrades the output rather than
 * breaking checkout.
 *
 * ── Why the same HTML is reused ──────────────────────────────────────────────
 * The invoice template is already written for A4 print. Feeding it to Dompdf
 * means the emailed PDF and the on-screen preview cannot drift apart, which
 * they would if a second, PDF-only template existed.
 *
 * @package Jankx\Extensions\EInvoice\Render
 */
class DompdfPdfRenderer implements InvoiceRendererInterface
{
    use InvoicePrintStyles;

    /** @var InvoiceTemplateLoader */
    protected $loader;

    /** @var string 'A4' or 'letter'. */
    protected $paper = 'A4';

    /** @var string 'portrait' or 'landscape'. */
    protected $orientation = 'portrait';

    /** @var string|null Resolved lazily. */
    protected $html;

    public function __construct(InvoiceTemplateLoader $loader, ?string $html = null)
    {
        $this->loader = $loader;
        $this->html   = $html;
    }

    /**
     * Whether Dompdf is actually installed.
     */
    public static function isAvailable(): bool
    {
        return class_exists('Dompdf\Dompdf');
    }

    /**
     * @throws \RuntimeException When Dompdf is missing or fails to render.
     */
    public function render(Invoice $invoice): string
    {
        if (!self::isAvailable()) {
            throw new \RuntimeException(
                'Dompdf is not installed. Run "composer require dompdf/dompdf" in the e-invoice '
                . 'extension, or use the HTML renderer.'
            );
        }

        $dompdf = new \Dompdf\Dompdf($this->options($invoice));

        $dompdf->loadHtml($this->html($invoice));
        $dompdf->setPaper($this->paper, $this->orientation);
        $dompdf->render();

        $output = $dompdf->output();

        if ($output === null || $output === '') {
            throw new \RuntimeException('Dompdf produced no output for invoice ' . $invoice->getInvoiceNumber());
        }

        return $output;
    }

    /**
     * The HTML handed to Dompdf: the same standalone document the browser gets.
     */
    protected function html(Invoice $invoice): string
    {
        if ($this->html !== null) {
            return $this->html;
        }

        $renderer = new HtmlInvoiceRenderer($this->loader);

        return (string) $renderer->render($invoice);
    }

    /**
     * Dompdf options tuned for Vietnamese invoices.
     *
     * `chroot` restricts filesystem reads to the WordPress root: Dompdf is
     * known to be reachable for local-file disclosure if given remote or
     * untrusted content, so it must never be pointed at user input.
     *
     * @return array
     */
    protected function options(Invoice $invoice): array
    {
        $options = [
            'isRemoteEnabled'  => false,
            'isHtml5ParserEnabled' => true,
            'chroot'           => $this->chroot(),
            'defaultFont'      => 'DejaVu Sans',
            'defaultPaperSize' => 'a4',
            'logOutputFile'    => null,
        ];

        /**
         * Filter the Dompdf options used to render invoices.
         *
         * @param array   $options
         * @param Invoice $invoice
         */
        return (array) apply_filters('jankx/einvoice/dompdf_options', $options, $invoice);
    }

    /**
     * Directory Dompdf is permitted to read from.
     */
    protected function chroot(): string
    {
        if (defined('ABSPATH')) {
            return rtrim(str_replace('\\', '/', ABSPATH), '/') . '/';
        }

        return rtrim(str_replace('\\', '/', WP_CONTENT_DIR), '/') . '/';
    }

    public function getContentType(): string
    {
        return 'application/pdf';
    }

    public function getAttachmentMimeType(): string
    {
        return 'application/pdf';
    }

    public function getExtension(): string
    {
        return 'pdf';
    }

    public function isBinary(): bool
    {
        return true;
    }

    public function getId(): string
    {
        return 'dompdf';
    }

    public function getLabel(): string
    {
        return __('PDF (Dompdf)', 'e-invoice');
    }
}