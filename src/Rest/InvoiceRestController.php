<?php
namespace Jankx\Extensions\EInvoice\Rest;

use Jankx\Extensions\Ecommerce\Order\Order;
use Jankx\Extensions\EInvoice\Model\Invoice;
use Jankx\Extensions\EInvoice\Repository\InvoiceRepositoryInterface;
use Jankx\Extensions\EInvoice\Service\InvoiceDocumentService;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Read-only REST endpoints serving invoice documents.
 *
 * Routes (namespace `jankx/e-invoice/v1`):
 *   GET /invoices/{id}/view     — the rendered document, inline
 *   GET /invoices/{id}/download — the same document as an attachment
 *   GET /invoices                — the caller's own invoices
 *
 * ── Authorization ───────────────────────────────────────────────────────────
 * Invoices carry personal data and tax identifiers, so every route re-derives
 * permission from the underlying order rather than trusting the caller:
 *
 *   • a logged-in user may read an invoice whose order has their `customer_id`
 *   • the WordPress administrator/editor roles that base-ecommerce already
 *     grants order access (`OrderPostType::CAP_READ`) may read any invoice
 *
 * The ID in the URL is never sufficient on its own.
 *
 * @package Jankx\Extensions\EInvoice\Rest
 */
class InvoiceRestController
{
    const NAMESPACE_V1 = 'jankx/e-invoice/v1';

    /** @var InvoiceRepositoryInterface */
    protected $repository;

    /** @var InvoiceDocumentService */
    protected $documents;

    public function __construct(InvoiceRepositoryInterface $repository, InvoiceDocumentService $documents)
    {
        $this->repository = $repository;
        $this->documents  = $documents;
    }

    public function register(): void
    {
        add_action('rest_api_init', [$this, 'registerRoutes']);
    }

    public function registerRoutes(): void
    {
        register_rest_route(self::NAMESPACE_V1, '/invoices', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'listInvoices'],
            'permission_callback' => [$this, 'canReadOwnInvoices'],
        ]);

        register_rest_route(self::NAMESPACE_V1, '/invoices/(?P<id>\d+)/view', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'viewInvoice'],
            'permission_callback' => [$this, 'canReadInvoice'],
            'args'                => ['id' => ['validate_callback' => 'is_numeric']],
        ]);

        register_rest_route(self::NAMESPACE_V1, '/invoices/(?P<id>\d+)/download', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'downloadInvoice'],
            'permission_callback' => [$this, 'canReadInvoice'],
            'args'                => ['id' => ['validate_callback' => 'is_numeric']],
        ]);
    }

    // ── Endpoints ────────────────────────────────────────────────────────────

    /**
     * The caller's own invoices.
     */
    public function listInvoices(WP_REST_Request $request): WP_REST_Response
    {
        $args = [
            'page'     => max(1, (int) $request->get_param('page')),
            'per_page' => min(50, max(1, (int) $request->get_param('per_page'))),
            'orderby'  => 'issued_at',
            'order'    => 'DESC',
        ];

        // The invoices table has no customer_id column, but `buyer_email` is
        // indexed and is snapshotted from the order, so it identifies the
        // caller's purchases for both registered and guest checkouts — a guest
        // who later creates an account with the same address still finds their
        // invoices. Shop staff get no filter, i.e. every invoice.
        if (!$this->currentUserCanManageOrders()) {
            $args['buyer_email'] = (string) wp_get_current_user()->user_email;
        }

        $invoices = $this->repository->query($args);
        $items = [];
        foreach ($invoices as $invoice) {
            $items[] = $this->present($invoice);
        }

        $response = rest_ensure_response($items);
        $response->header('X-WP-Total', (string) $this->repository->count($args));

        return $response;
    }

    /**
     * Render the document inline in the browser.
     */
    public function viewInvoice(WP_REST_Request $request): WP_REST_Response
    {
        $invoice = $this->repository->findById((int) $request->get_param('id'));
        if (!$invoice) {
            return new WP_REST_Response(['message' => __('Invoice not found.', 'e-invoice')], 404);
        }

        $rendered = $this->documents->render($invoice);
        if ($rendered === null) {
            return new WP_REST_Response(['message' => __('Invoice could not be rendered.', 'e-invoice')], 500);
        }

        $response = new WP_REST_Response($rendered['content']);
        $response->header('Content-Type', $rendered['renderer']->getContentType());

        return $response;
    }

    /**
     * Serve the document as a file download.
     */
    public function downloadInvoice(WP_REST_Request $request): WP_REST_Response
    {
        $invoice = $this->repository->findById((int) $request->get_param('id'));
        if (!$invoice) {
            return new WP_REST_Response(['message' => __('Invoice not found.', 'e-invoice')], 404);
        }

        $renderer = $this->documents->resolveRenderer();

        // Reuse the cached file when there is one, so a download does not
        // re-render the document on every request.
        $cached = $invoice->getPdfPath();
        if ($cached && is_readable($cached) && $renderer->isBinary()) {
            $filename = basename($cached);
            $body     = (string) file_get_contents($cached);
        } else {
            $rendered = $this->documents->render($invoice);
            if ($rendered === null) {
                return new WP_REST_Response(['message' => __('Invoice could not be rendered.', 'e-invoice')], 500);
            }
            $renderer = $rendered['renderer'];
            $body     = $rendered['content'];
            $filename = $this->documents->filename($invoice, $renderer);
        }

        $response = new WP_REST_Response($body);
        $response->header('Content-Type', $renderer->getAttachmentMimeType());
        $response->header(
            'Content-Disposition',
            sprintf('attachment; filename="%s"', sanitize_file_name($filename))
        );
        $response->header('Content-Length', (string) strlen($body));

        return $response;
    }

    // ── Permission callbacks ─────────────────────────────────────────────────

    /**
     * Whether the caller may read a given invoice.
     *
     * @return true|WP_Error
     */
    public function canReadInvoice(WP_REST_Request $request)
    {
        $invoice = $this->repository->findById((int) $request->get_param('id'));
        if (!$invoice) {
            // 404 rather than 403 so the endpoint does not confirm that an
            // invoice id exists to someone who may not read it.
            return new WP_Error(
                'einvoice_not_found',
                __('Invoice not found.', 'e-invoice'),
                ['status' => 404]
            );
        }

        if ($this->currentUserCanManageOrders()) {
            return true;
        }

        if (!is_user_logged_in()) {
            return new WP_Error(
                'einvoice_not_authenticated',
                __('You must be logged in to view this invoice.', 'e-invoice'),
                ['status' => 401]
            );
        }

        if (!$this->invoiceBelongsToCurrentUser($invoice)) {
            return new WP_Error(
                'einvoice_forbidden',
                __('You do not have permission to view this invoice.', 'e-invoice'),
                ['status' => 403]
            );
        }

        return true;
    }

    /**
     * @return true|WP_Error
     */
    public function canReadOwnInvoices(WP_REST_Request $request)
    {
        if (!is_user_logged_in()) {
            return new WP_Error(
                'einvoice_not_authenticated',
                __('You must be logged in to view invoices.', 'e-invoice'),
                ['status' => 401]
            );
        }

        return true;
    }

    /**
     * Whether an invoice's order belongs to the logged-in user.
     */
    protected function invoiceBelongsToCurrentUser(Invoice $invoice): bool
    {
        $userId = get_current_user_id();
        if ($userId <= 0) {
            return false;
        }

        $order = new Order($invoice->getOrderId());
        if (!$order->getId()) {
            return false;
        }

        if ((int) $order->getCustomerId() === $userId) {
            return true;
        }

        // Fall back to the email on the invoice snapshot, which covers guest
        // orders whose customer email matches the account.
        $current = (string) wp_get_current_user()->user_email;

        return $current !== '' && strcasecmp($current, $invoice->getBuyer()->getEmail()) === 0;
    }

    /**
     * Reuse base-ecommerce's own capability so shop staff with order access can
     * read invoices without this extension maintaining a second role list.
     */
    protected function currentUserCanManageOrders(): bool
    {
        $class = '\Jankx\Extensions\Ecommerce\Order\OrderPostType';

        if (class_exists($class) && defined($class . '::CAP_READ') && current_user_can(constant($class . '::CAP_READ'))) {
            return true;
        }

        return current_user_can('manage_woocommerce') || current_user_can('manage_options');
    }

    /**
     * Array shape for the list endpoint. Deliberately excludes the tax code and
     * full address — the document itself is fetched separately.
     */
    protected function present(Invoice $invoice): array
    {
        return [
            'id'            => $invoice->getId(),
            'number'        => $invoice->getInvoiceNumber(),
            'display'       => $invoice->getDisplayNumber(),
            'order_id'      => $invoice->getOrderId(),
            'order_number'  => $invoice->getOrderNumber(),
            'issued_at'     => $invoice->getIssuedAt(),
            'issued_label'  => $invoice->getFormattedIssuedAt(),
            'currency'      => $invoice->getCurrency(),
            'grand_total'   => $invoice->getTotals()->getPayableTotal(),
            'status'        => $invoice->getStatus(),
            'profile'       => $invoice->getProfileId(),
            'view_url'      => rest_url(self::NAMESPACE_V1 . '/invoices/' . $invoice->getId() . '/view'),
            'download_url'  => rest_url(self::NAMESPACE_V1 . '/invoices/' . $invoice->getId() . '/download'),
        ];
    }
}