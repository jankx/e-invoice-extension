<?php
namespace Jankx\Extensions\EInvoice\Render;

use Jankx\Extensions\EInvoice\Contracts\InvoiceRendererInterface;
use Jankx\Extensions\EInvoice\Model\Invoice;

/**
 * Default renderer: prints the invoice as self-contained HTML.
 *
 * Deliberately the default because it needs no dependencies and no PHP
 * extensions beyond what WordPress already requires. It serves three purposes
 * from one template:
 *
 *   • the on-screen preview in My Account
 *   • a print view — the stylesheet is written for A4 and hides chrome in
 *     `@media print`, so Ctrl-P produces a clean PDF via the browser
 *   • the HTML alternative attached to emails, so the document is readable even
 *     when a PDF renderer is unavailable
 *
 * @package Jankx\Extensions\EInvoice\Render
 */
class HtmlInvoiceRenderer implements InvoiceRendererInterface
{
    use InvoicePrintStyles;

    /** @var InvoiceTemplateLoader */
    protected $loader;

    /** @var bool Whether to emit the full standalone document or a fragment. */
    protected $standalone = true;

    public function __construct(InvoiceTemplateLoader $loader)
    {
        $this->loader = $loader;
    }

    public function render(Invoice $invoice): string
    {
        $body = $this->loader->render($invoice);

        if (!$this->standalone) {
            return $body;
        }

        return $this->wrap($invoice, $body);
    }

    /**
     * Wrap the template output in a complete, printable HTML document.
     */
    protected function wrap(Invoice $invoice, string $body): string
    {
        $title = sprintf(
            '%s %s',
            $invoice->getProfileId() === 'vn' ? 'Hóa đơn' : 'Invoice',
            $invoice->getInvoiceNumber()
        );

        return '<!DOCTYPE html>' . "\n"
            . '<html lang="' . esc_attr($invoice->getExtraValue('locale', 'vi_VN')) . '">' . "\n"
            . '<head>' . "\n"
            . '<meta charset="UTF-8">' . "\n"
            . '<meta name="viewport" content="width=device-width, initial-scale=1">' . "\n"
            . '<title>' . esc_html($title) . '</title>' . "\n"
            . $this->styles()
            . '</head>' . "\n"
            . '<body class="jankx-einvoice">' . "\n"
            . $body
            . "\n" . '</body>' . "\n"
            . '</html>';
    }

    /**
     * Inline print stylesheet.
     *
     * Embedded rather than enqueued because the document is also used for PDFs
     * and email, where an external stylesheet would not load.
     */
    protected function styles(): string
    {
        return '<style>' . $this->css() . '</style>' . "\n";
    }

    public function getContentType(): string
    {
        return 'text/html';
    }

    public function getAttachmentMimeType(): string
    {
        return 'text/html';
    }

    public function getExtension(): string
    {
        return 'html';
    }

    public function isBinary(): bool
    {
        return false;
    }

    public function getId(): string
    {
        return 'html';
    }

    public function getLabel(): string
    {
        return __('HTML (print to PDF from the browser)', 'e-invoice');
    }
}