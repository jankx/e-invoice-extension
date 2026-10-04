<?php
namespace Jankx\Extensions\EInvoice\Contracts;

use Jankx\Extensions\EInvoice\Model\Invoice;

/**
 * Turns an Invoice into a document.
 *
 * A Strategy because the output format is a deployment decision, not a legal
 * one. The invoice's content is fixed by the country profile; only the
 * presentation varies. Two implementations ship:
 *
 *   • {@see \Jankx\Extensions\EInvoice\Render\HtmlInvoiceRenderer} — always
 *     available, no dependencies. Produces the HTML used for the on-screen
 *     preview, the print view and, via the browser, the PDF.
 *   • {@see \Jankx\Extensions\EInvoice\Render\DompdfPdfRenderer} — server-side
 *     PDF, used when Dompdf is installed.
 *
 * Both render the same template, so a shop can switch between them without the
 * document changing.
 *
 * @package Jankx\Extensions\EInvoice\Contracts
 */
interface InvoiceRendererInterface
{
    /**
     * Render the invoice document.
     *
     * @param Invoice $invoice
     * @return string Format-specific output: HTML, or a PDF byte string.
     */
    public function render(Invoice $invoice): string;

    /**
     * Content type of {@see self::render()} output, for HTTP headers and email.
     */
    public function getContentType(): string;

    /**
     * MIME type used when the document is attached to an email. Differs from
     * the content type for formats a mail client will not render inline.
     */
    public function getAttachmentMimeType(): string;

    /**
     * File extension for the rendered document, without a leading dot.
     */
    public function getExtension(): string;

    /**
     * Whether this renderer produces a binary attachment rather than a
     * viewable page. Used to decide if the account page offers a download link
     * or an inline view.
     */
    public function isBinary(): bool;

/**
 * @return string Identifier stored with the invoice for auditability.
 */
    public function getId(): string;

    /**
     * @return string Human-readable label for the settings select.
     */
    public function getLabel(): string;
}