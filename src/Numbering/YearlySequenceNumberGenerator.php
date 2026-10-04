<?php
namespace Jankx\Extensions\EInvoice\Numbering;

use Jankx\Extensions\EInvoice\Model\InvoiceDatabaseInstaller;

/**
 * Year-scoped sequential numbering, the default for Vietnam.
 *
 * Produces "HD-2026-000042": symbol, accounting year, zero-padded ordinal.
 * This mirrors how Vietnamese HĐĐT series are registered — the serial
 * restarts on 1 January for each symbol.
 *
 * ── Concurrency ────────────────────────────────────────────────────────────
 * MAX(invoice_number)+1 would be wrong here: two checkouts completing in the
 * same request window would read the same maximum and issue the same number,
 * and under load some numbers would silently disappear. Instead each series
 * period owns a row in `jankx_invoice_sequences` and we increment it with a
 * single atomic statement:
 *
 *     INSERT … ON DUPLICATE KEY UPDATE current_value = LAST_INSERT_ID(current_value + 1)
 *
 * The UPDATE branch takes a row-level exclusive lock for the duration of the
 * statement, so the read-modify-write cannot interleave. LAST_INSERT_ID() is
 * per-connection, so the value we read back is guaranteed to be the one *this*
 * statement allocated — reading the column afterwards would not be safe.
 *
 * @package Jankx\Extensions\EInvoice\Numbering
 */
class YearlySequenceNumberGenerator implements InvoiceNumberGeneratorInterface
{
    /** @var int Digits used for the zero-padded ordinal. */
    protected $padding = 6;

    /** @var string|null Resolved lazily for testability. */
    protected $table;

    /**
     * @param int         $padding
     * @param string|null $table
     */
    public function __construct(int $padding = 6, ?string $table = null)
    {
        $this->padding = max(1, $padding);
        $this->table   = $table;
    }

    public function getId(): string
    {
        return 'yearly_sequence';
    }

    public function getLabel(): string
    {
        return __('Yearly sequence (e.g. HD-2026-000001)', 'e-invoice');
    }

    public function allocate(string $series, string $issuedAt): array
    {
        $period   = $this->period($issuedAt);
        $symbol   = $this->normaliseSeries($series);
        $sequence = $this->nextSequence($symbol, $period);

        return [
            'number'   => $this->format($symbol, $period, $sequence),
            'symbol'   => $symbol,
            'sequence' => $sequence,
            'period'   => $period,
        ];
    }

    public function format(string $symbol, string $period, int $sequence): string
    {
        return sprintf(
            '%s-%s-%s',
            $symbol,
            $period,
            str_pad((string) max(0, $sequence), $this->padding, '0', STR_PAD_LEFT)
        );
    }

    /**
     * Atomically reserve the next ordinal in a series/period scope.
     */
    public function nextSequence(string $symbol, string $period): int
    {
        global $wpdb;

        $table = $this->table();
        if (!$table) {
            $this->table = InvoiceDatabaseInstaller::sequencesTable();
            $table = $this->table;
        }

        $scope = $this->scopeKey($symbol, $period);

        // One statement does the insert-or-increment. rows_affected is 1 for a
        // fresh INSERT and 2 for the UPDATE branch (which always changes a
        // value, so it can never be 0 here).
        $wpdb->query(
            $wpdb->prepare(
                "INSERT INTO {$table} (scope, current_value, updated_at)
                 VALUES (%s, 1, %s)
                 ON DUPLICATE KEY UPDATE current_value = LAST_INSERT_ID(current_value + 1),
                                         updated_at = %s",
                $scope,
                current_time('mysql'),
                current_time('mysql')
            )
        );

        if ($wpdb->last_error) {
            $this->logFailure($wpdb->last_error, $scope);
            return 0;
        }

        if ((int) $wpdb->rows_affected === 1) {
            return 1;
        }

        // LAST_INSERT_ID() is connection-scoped, so this is our own increment.
        $value = (int) $wpdb->get_var('SELECT LAST_INSERT_ID()');

        if ($value <= 0) {
            // Defensive: if the connection state was disturbed, re-read the
            // counter. Slightly racy in theory, but better than issuing a 0.
            $value = (int) $wpdb->get_var(
                $wpdb->prepare("SELECT current_value FROM {$table} WHERE scope = %s", $scope)
            );
        }

        return $value;
    }

    /**
     * Peek at the current counter without consuming a number.
     */
    public function peek(string $symbol, string $period): int
    {
        global $wpdb;

        $table = $this->table ?: InvoiceDatabaseInstaller::sequencesTable();

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT current_value FROM {$table} WHERE scope = %s",
                $this->scopeKey($symbol, $period)
            )
        );
    }

    /**
     * Seed the counter, e.g. when a shop already has invoices issued by a
     * previous system. Only ever moves the counter forward.
     */
    public function seed(string $symbol, string $period, int $value): void
    {
        global $wpdb;

        $table = $this->table ?: InvoiceDatabaseInstaller::sequencesTable();
        $value = max(0, $value);

        $wpdb->query(
            $wpdb->prepare(
                "INSERT INTO {$table} (scope, current_value, updated_at)
                 VALUES (%s, %d, %s)
                 ON DUPLICATE KEY UPDATE current_value = GREATEST(current_value, %d)",
                $this->scopeKey($symbol, $period),
                $value,
                current_time('mysql'),
                $value
            )
        );
    }

    /**
     * Accounting period for an issue timestamp.
     */
    public function period(string $issuedAt): string
    {
        $timestamp = $issuedAt ? strtotime($issuedAt) : false;
        return gmdate('Y', $timestamp ?: time());
    }

    protected function scopeKey(string $symbol, string $period): string
    {
        return $symbol . ':' . $period;
    }

    /**
     * Keep series symbols short and filesystem/SQL safe — they end up in
     * invoice numbers, emails and URLs.
     */
    protected function normaliseSeries(string $series): string
    {
        $series = strtoupper(trim($series));
        $series = preg_replace('/[^A-Z0-9\-_]/', '', $series);

        return $series !== '' ? $series : 'HD';
    }

    protected function logFailure(string $message, string $scope): void
    {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[EInvoice] sequence allocation failed for scope "' . $scope . '": ' . $message);
        }
    }
}