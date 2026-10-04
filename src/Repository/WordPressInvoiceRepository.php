<?php
namespace Jankx\Extensions\EInvoice\Repository;

use Jankx\Extensions\EInvoice\Model\Invoice;
use Jankx\Extensions\EInvoice\Model\InvoiceDatabaseInstaller;

/**
 * WordPress/MySQL implementation of {@see InvoiceRepositoryInterface}.
 *
 * The invoice's full contents live in the `snapshot` LONGTEXT column as JSON.
 * Denormalised columns (net_total, tax_total, grand_total, country_code, ...)
 * are kept alongside it so the admin list can sort and filter in SQL without
 * parsing JSON on every row — this matters because the list view is the page a
 * bookkeeper hits at month end.
 *
 * @package Jankx\Extensions\EInvoice\Repository
 */
class WordPressInvoiceRepository implements InvoiceRepositoryInterface
{
    /** @var string|null Resolved lazily so unit tests can run without $wpdb. */
    protected $table;

    public function __construct(?string $table = null)
    {
        $this->table = $table;
    }

    protected function table(): string
    {
        if ($this->table === null) {
            $this->table = InvoiceDatabaseInstaller::invoicesTable();
        }
        return $this->table;
    }

    public function insert(Invoice $invoice): int
    {
        global $wpdb;

        $invoiceNumber = $invoice->getInvoiceNumber();

        // Pre-flight duplicate check: a UNIQUE index on invoice_number and on
        // order_invoice_key will reject these, but surfacing a friendly error is
        // far more useful than a database exception when two checkouts race.
        if ($invoiceNumber !== '' && $this->findByNumber($invoiceNumber)) {
            return 0;
        }
        if ($invoice->getOrderId() && $this->findByOrderId($invoice->getOrderId())) {
            return 0;
        }

        $totals = $invoice->getTotals();
        $year   = $invoice->getIssuedAt() ? (int) gmdate('Y', strtotime($invoice->getIssuedAt())) : 0;

        $data = [
            'invoice_number'      => $invoiceNumber,
            'invoice_symbol'      => $invoice->getInvoiceSymbol(),
            'invoice_form_symbol' => $invoice->getInvoiceFormSymbol(),
            'order_id'            => $invoice->getOrderId(),
            'order_number'        => $invoice->getOrderNumber(),
            // Only original invoices claim the order slot. Adjustment documents
            // leave it NULL, and MySQL allows unlimited NULLs in a UNIQUE
            // index — which gives us idempotency *and* adjustment chains.
            'order_invoice_key'   => $invoice->getAdjustmentType() === Invoice::ADJUSTMENT_NONE
                ? ($invoice->getOrderId() ?: null)
                : null,
            'country_code'        => $invoice->getCountryCode(),
            'profile_id'          => $invoice->getProfileId(),
            'currency'            => $invoice->getCurrency(),
            'status'              => $invoice->getStatus(),
            'adjustment_type'     => $invoice->getAdjustmentType(),
            'original_invoice_id' => $invoice->getOriginalInvoiceId(),
            'issue_year'          => $year,
            'issue_sequence'      => (int) $invoice->getExtraValue('issue_sequence', 0),
            'tax_authority_code'  => $invoice->getTaxAuthorityCode(),
            'buyer_email'         => $invoice->getBuyer()->getEmail(),
            'net_total'           => $totals->getNetTotal(),
            'tax_total'           => $totals->getTaxTotal(),
            'grand_total'         => $totals->getPayableTotal(),
            'pdf_path'            => (string) $invoice->getPdfPath(),
            'issued_at'           => $invoice->getIssuedAt() ?: current_time('mysql'),
            'signed_at'           => $invoice->getSignedAt() ?: null,
            'snapshot'            => wp_json_encode($invoice->toArray()),
        ];

        $inserted = $wpdb->insert($this->table(), $data);

        if (!$inserted) {
            return 0;
        }

        return (int) $wpdb->insert_id;
    }

    public function update(Invoice $invoice): bool
    {
        global $wpdb;

        if (!$invoice->getId()) {
            return false;
        }

        $totals = $invoice->getTotals();

        $result = $wpdb->update(
            $this->table(),
            [
                'status'             => $invoice->getStatus(),
                'tax_authority_code' => $invoice->getTaxAuthorityCode(),
                'pdf_path'           => (string) $invoice->getPdfPath(),
                'emailed_at'         => $invoice->getEmailedAt(),
                'net_total'          => $totals->getNetTotal(),
                'tax_total'          => $totals->getTaxTotal(),
                'grand_total'        => $totals->getPayableTotal(),
                'snapshot'           => wp_json_encode($invoice->toArray()),
            ],
            ['id' => $invoice->getId()],
            ['%s', '%s', '%s', '%s', '%f', '%f', '%f', '%s'],
            ['%d']
        );

        return $result !== false;
    }

    public function findById(int $id): ?Invoice
    {
        if ($id <= 0) {
            return null;
        }
        return $this->hydrate($this->getRow(['id' => $id]));
    }

    public function findByNumber(string $invoiceNumber): ?Invoice
    {
        if ($invoiceNumber === '') {
            return null;
        }
        return $this->hydrate($this->getRow(['invoice_number' => $invoiceNumber]));
    }

    public function findByOrderId(int $orderId): ?Invoice
    {
        if ($orderId <= 0) {
            return null;
        }
        return $this->hydrate($this->getRow(['order_invoice_key' => $orderId]));
    }

    /**
     * @param array $where Column => value pairs.
     * @return array|null
     */
    protected function getRow(array $where): ?array
    {
        global $wpdb;

        $conditions = [];
        $values     = [];
        foreach ($where as $column => $value) {
            $conditions[] = "{$column} = %s";
            $values[]     = $value;
        }

        $sql = 'SELECT * FROM ' . $this->table() . ' WHERE ' . implode(' AND ', $conditions) . ' LIMIT 1';

        $row = $wpdb->get_row($wpdb->prepare($sql, $values), ARRAY_A);

        return $row ?: null;
    }

    /**
     * @param array|null $row
     */
    protected function hydrate(?array $row): ?Invoice
    {
        if (!$row) {
            return null;
        }

        $snapshot = json_decode((string) ($row['snapshot'] ?? ''), true);
        if (!is_array($snapshot)) {
            return null;
        }

        // Columns outside the snapshot win, because they are the ones an
        // administrator can change after issuance (status, tax authority code).
        $snapshot['id']                 = (int) $row['id'];
        $snapshot['status']             = (string) $row['status'];
        $snapshot['tax_authority_code'] = (string) $row['tax_authority_code'];
        $snapshot['pdf_path']           = ($row['pdf_path'] ?? '') !== '' ? $row['pdf_path'] : null;
        $snapshot['issued_at']          = (string) $row['issued_at'];
        $snapshot['signed_at']          = (string) $row['signed_at'];
        $snapshot['invoice_number']     = (string) $row['invoice_number'];
        $snapshot['order_id']           = (int) $row['order_id'];

        return new Invoice($snapshot);
    }

    public function query(array $args = []): array
    {
        global $wpdb;

        $where = $this->buildWhere($args);
        $sql   = 'SELECT * FROM ' . $this->table() . $where['sql'] . $this->buildOrderBy($args);

        $values = $where['values'];

        $perPage = (int) ($args['per_page'] ?? 0);
        if ($perPage > 0) {
            $page     = max(1, (int) ($args['page'] ?? 1));
            $sql     .= ' LIMIT %d OFFSET %d';
            // Order matters: placeholders are filled left to right, so the
            // WHERE values must be appended before the LIMIT pair.
            $values[] = $perPage;
            $values[] = ($page - 1) * $perPage;
        }

        if ($values) {
            $sql = $wpdb->prepare($sql, $values);
        }

        $rows = $wpdb->get_results($sql, ARRAY_A);
        if (!is_array($rows)) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            $invoice = $this->hydrate($row);
            if ($invoice) {
                $out[] = $invoice;
            }
        }

        return $out;
    }

    public function count(array $args = []): int
    {
        global $wpdb;

        $where = $this->buildWhere($args);
        $sql   = 'SELECT COUNT(*) FROM ' . $this->table() . $where['sql'];

        if (!empty($where['values'])) {
            $sql = $wpdb->prepare($sql, $where['values']);
        }

        return (int) $wpdb->get_var($sql);
    }

    /**
     * @return array{sql: string, values: array}
     */
    protected function buildWhere(array $args): array
    {
        $conditions = [];
        $values     = [];

        if (!empty($args['order_id'])) {
            $conditions[] = 'order_id = %d';
            $values[]     = (int) $args['order_id'];
        }

        if (!empty($args['buyer_email'])) {
            $conditions[] = 'buyer_email = %s';
            $values[]     = sanitize_email($args['buyer_email']);
        }

        if (!empty($args['status'])) {
            $conditions[] = 'status = %s';
            $values[]     = sanitize_key($args['status']);
        }

        if (!empty($args['country_code'])) {
            $conditions[] = 'country_code = %s';
            $values[]     = strtoupper(substr((string) $args['country_code'], 0, 2));
        }

        if (!empty($args['issue_year'])) {
            $conditions[] = 'issue_year = %d';
            $values[]     = (int) $args['issue_year'];
        }

        if (!empty($args['search'])) {
            $like     = '%' . $wpdb->esc_like((string) $args['search']) . '%';
            $conditions[] = '(invoice_number LIKE %s OR order_number LIKE %s OR buyer_email LIKE %s OR tax_authority_code LIKE %s)';
            array_push($values, $like, $like, $like, $like);
        }

        return [
            'sql'    => $conditions ? ' WHERE ' . implode(' AND ', $conditions) : '',
            'values' => $values,
        ];
    }

    protected function buildOrderBy(array $args): string
    {
        $allowed = ['id', 'invoice_number', 'issued_at', 'grand_total', 'order_id', 'status'];
        $orderBy = in_array($args['orderby'] ?? '', $allowed, true) ? $args['orderby'] : 'issued_at';
        $order   = strtoupper($args['order'] ?? '') === 'ASC' ? 'ASC' : 'DESC';

        return " ORDER BY {$orderBy} {$order}";
    }

    public function summariseTaxRates(): array
    {
        // Rates live inside the JSON snapshot, so this is a read-side report
        // rather than something we can express as a plain GROUP BY. Kept
        // deliberately simple: walk the most recent invoices and tally.
        $invoices = $this->query(['per_page' => 500, 'orderby' => 'id', 'order' => 'DESC']);

        $tally = [];
        foreach ($invoices as $invoice) {
            foreach ($invoice->getTaxLines() as $line) {
                $key = (string) $line->getRate();
                if (!isset($tally[$key])) {
                    $tally[$key] = ['rate' => $line->getRate(), 'invoices' => 0];
                }
                $tally[$key]['invoices']++;
            }
        }

        ksort($tally, SORT_NUMERIC);

        return array_values($tally);
    }
}