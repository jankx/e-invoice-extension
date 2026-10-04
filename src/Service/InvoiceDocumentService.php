<?php
namespace Jankx\Extensions\EInvoice\Service;

use Jankx\Extensions\EInvoice\Contracts\InvoiceRendererInterface;
use Jankx\Extensions\EInvoice\Model\Invoice;
use Jankx\Extensions\EInvoice\Render\DompdfPdfRenderer;
use Jankx\Extensions\EInvoice\Render\HtmlInvoiceRenderer;
use Jankx\Extensions\EInvoice\Render\InvoiceTemplateLoader;

/**
 * Produces the invoice document in whichever format the shop has configured.
 *
 * Owns renderer selection and the fallback chain, so callers never deal with a
 * missing optional dependency:
 *
 *   1. the configured renderer, if it is available
 *   2. the HTML renderer, always
 *
 * Also caches rendered documents under `uploads/jankx-invoices/` so a PDF is
 * generated once and then served from disk, and records the path back onto the
 * invoice for auditability.
 *
 * @package Jankx\Extensions\EInvoice\Service
 */
class InvoiceDocumentService
{
    /** @var InvoiceTemplateLoader */
    protected $loader;

    /** @var InvoiceRendererInterface[] */
    protected $renderers;

    public function __construct(InvoiceTemplateLoader $loader, array $renderers = [])
    {
        $this->loader    = $loader;
        $this->renderers = $renderers ?: [
            'html'   => new HtmlInvoiceRenderer($loader),
            'dompdf' => new DompdfPdfRenderer($loader),
        ];
    }

    /**
     * The renderer to use for this site.
     */
    public function resolveRenderer(): InvoiceRendererInterface
    {
        $configured = (string) get_option('jankx_einvoice_renderer', 'html');

        /**
         * Filter the renderer id used for invoices.
         *
         * @param string $configured One of the keys returned by {@see self::availableRenderers()}.
         */
        $configured = (string) apply_filters('jankx/einvoice/renderer', $configured);

        $renderer = $this->renderers[$configured] ?? null;

        // A renderer that cannot run here (e.g. dompdf not installed) must not
        // take the order down with it.
        if ($renderer instanceof DompdfPdfRenderer && !DompdfPdfRenderer::isAvailable()) {
            $renderer = null;
        }

        if ($renderer === null) {
            $renderer = $this->renderers['html'] ?? new HtmlInvoiceRenderer($this->loader);
        }

        return $renderer;
    }

    /**
     * @return array<string, string> Renderer id => label, for the settings select.
     */
    public function availableRenderers(): array
    {
        $out = [];
        foreach ($this->renderers as $id => $renderer) {
            if ($renderer instanceof DompdfPdfRenderer && !DompdfPdfRenderer::isAvailable()) {
                // Offer it but label it, so the admin can see why it is inert.
                $out[$id] = $renderer->getLabel() . ' — ' . __('requires Dompdf (not installed)', 'e-invoice');
                continue;
            }
            $out[$id] = $renderer->getLabel();
        }

        return $out;
    }

    /**
     * Render an invoice, returning the document and the renderer that made it.
     *
     * @return array{renderer: InvoiceRendererInterface, content: string}|null
     */
    public function render(Invoice $invoice): ?array
    {
        $renderer = $this->resolveRenderer();

        try {
            $content = $renderer->render($invoice);
        } catch (\Throwable $e) {
            // Never let a rendering problem break checkout — fall back to HTML.
            $this->log('render failed (' . $renderer->getId() . '): ' . $e->getMessage());

            $fallback = $this->renderers['html'] ?? null;
            if (!$fallback) {
                return null;
            }

            try {
                $content = $fallback->render($invoice);
            } catch (\Throwable $inner) {
                $this->log('fallback render failed: ' . $inner->getMessage());
                return null;
            }

            $renderer = $fallback;
        }

        return ['renderer' => $renderer, 'content' => $content];
    }

    /**
     * Render and cache the document, returning its absolute path on disk.
     *
     * Returns null for non-binary renderers, since HTML is served directly
     * rather than stored.
     */
    public function cache(Invoice $invoice, ?InvoiceRendererInterface $renderer = null, ?string $content = null): ?string
    {
        $renderer = $renderer ?: $this->resolveRenderer();

        if ($content === null) {
            $rendered = $this->render($invoice);
            if ($rendered === null) {
                return null;
            }
            $renderer = $rendered['renderer'];
            $content  = $rendered['content'];
        }

        if (!$renderer->isBinary()) {
            return null;
        }

        $dir = $this->cacheDir();
        if ($dir === null) {
            return null;
        }

        $name = $this->filename($invoice, $renderer);
        $path = trailingslashit($dir) . $name;

        if (!file_exists($path) || filesize($path) === 0) {
            if (file_put_contents($path, $content) === false) {
                $this->log('could not write invoice document to ' . $path);
                return null;
            }
        }

        $invoice->setPdfPath($path);

        return $path;
    }

    /**
     * Filename for a rendered invoice.
     *
     * Uses the invoice number so the file is recognisable to a shopkeeper, and
     * the country code so a multi-country shop does not overwrite itself.
     */
    public function filename(Invoice $invoice, InvoiceRendererInterface $renderer): string
    {
        $number = sanitize_file_name($invoice->getInvoiceNumber() ?: ('invoice-' . $invoice->getId()));
        $country = strtolower($invoice->getCountryCode() ?: 'xx');

        return sprintf('invoice-%s-%s.%s', $country, $number, $renderer->getExtension());
    }

    /**
     * Absolute path of the render cache directory, creating it if needed.
     */
    public function cacheDir(): ?string
    {
        $uploads = wp_get_upload_dir();
        if (!empty($uploads['error']) || empty($uploads['basedir'])) {
            return null;
        }

        $dir = trailingslashit($uploads['basedir']) . 'jankx-invoices';

        if (!wp_mkdir_p($dir)) {
            return null;
        }

        // Invoices contain customer personal and tax data, so the directory is
        // kept out of the public index and unreachable by direct request.
        $index = trailingslashit($dir) . 'index.php';
        if (!file_exists($index)) {
            @file_put_contents($index, "<?php\n// Silence is golden.\n");
        }
        $htaccess = trailingslashit($dir) . '.htaccess';
        if (!file_exists($htaccess)) {
            @file_put_contents($htaccess, "Deny from all\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n");
        }

        return $dir;
    }

    protected function log(string $message): void
    {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[EInvoice] ' . $message);
        }
    }
}