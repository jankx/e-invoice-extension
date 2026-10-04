<?php
namespace Jankx\Extensions\EInvoice\Repository;

use Jankx\Extensions\EInvoice\Model\Invoice;

/**
 * Persistence contract for issued invoices.
 *
 * Keeping issuance behind an interface means the issuance service can be tested
 * without a database, and lets a site store invoices elsewhere (object storage,
 * an external document service, an ERP) without touching the issuance logic.
 *
 * @package Jankx\Extensions\EInvoice\Repository
 */
interface InvoiceRepositoryInterface
{
    /**
     * Persist a new invoice and return its assigned ID.
     *
     * @param Invoice $invoice Invoice to store.
     * @return int 0 on failure.
     */
    public function insert(Invoice $invoice): int;

    /**
     * Persist an already-stored invoice's mutable columns (status, PDF path,
     * emailed timestamp, tax authority code).
     *
     * @param Invoice $invoice
     * @return bool
     */
    public function update(Invoice $invoice): bool;

    /**
     * @param int $id
     * @return Invoice|null
     */
    public function findById(int $id): ?Invoice;

    /**
     * @param string $invoiceNumber
     * @return Invoice|null
     */
    public function findByNumber(string $invoiceNumber): ?Invoice;

    /**
     * The invoice raised for an order, ignoring adjustment documents.
     *
     * This is the idempotency probe the issuance service uses so a repeated
     * status-change event cannot create a duplicate invoice.
     *
     * @param int $orderId
     * @return Invoice|null
     */
    public function findByOrderId(int $orderId): ?Invoice;

    /**
     * List invoices.
     *
     * @param array $args {
     *     @type int    $order_id     Filter by order.
     *     @type string $buyer_email  Filter by buyer email.
     *     @type string $status       Filter by lifecycle status.
     *     @type string $country_code Filter by country.
     *     @type string $search       Free-text on invoice/order number, email, tax code.
     *     @type int    $page         1-based page number.
     *     @type int    $per_page     Rows per page (0 = no LIMIT).
     *     @type string $orderby      Column to sort by.
     *     @type string $order        ASC|DESC.
     * }
     * @return Invoice[]
     */
    public function query(array $args = []): array;

    /**
     * @param array $args Same shape as query().
     * @return int
     */
    public function count(array $args = []): int;

    /**
     * Every distinct tax rate ever snapshotted, for reporting.
     *
     * @return array<int, array{rate: float, invoices: int}>
     */
    public function summariseTaxRates(): array;
}