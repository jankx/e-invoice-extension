<?php
namespace Jankx\Extensions\EInvoice\Numbering;

/**
 * Contract for allocating invoice numbers.
 *
 * Numbering is a Strategy because it is one of the most jurisdiction-specific
 * parts of invoicing:
 *
 *   Vietnam   — ký hiệu (symbol) + a serial that restarts each year,
 *               e.g. "HD" + "1, 2, 3…" → HD-2026-000042
 *   Germany   — continuous, gap-free (§14 UStG forbids gaps)
 *   Italy     — annual restart per issuance point
 *   US        — sequential per seller, no legal format requirement
 *
 * The `scope` concept captures the reset boundary: implementations that need
 * per-year, per-series or per-location counters simply build a different
 * scope key, and no other layer has to change.
 *
 * @package Jankx\Extensions\EInvoice\Numbering
 */
interface InvoiceNumberGeneratorInterface
{
    /**
     * Allocate the next number for a series.
     *
     * Implementations MUST be safe to call concurrently: two shoppers
     * completing checkout in the same second must never receive the same
     * number, and a number must never be skipped.
     *
     * @param string $series   Invoice symbol/series key, e.g. "HD".
     * @param string $issuedAt Issue timestamp (MySQL datetime) driving the period.
     * @return array{
     *     number: string,   Fully rendered number for display and storage.
     *     symbol: string,   Ký hiệu / invoice series symbol.
     *     sequence: int,    The allocated ordinal within the scope.
     *     period: string,   The reset bucket, e.g. "2026".
     * }
     */
    public function allocate(string $series, string $issuedAt): array;

    /**
     * Format symbol + period + ordinal into the final invoice number.
     *
     * Split out from allocate() so the stored columns stay independently
     * queryable while the number itself can be re-rendered (e.g. after a
     * change to the configured symbol length).
     *
     * @param string $symbol
     * @param string $period
     * @param int    $sequence
     * @return string
     */
    public function format(string $symbol, string $period, int $sequence): string;

    /**
     * Identifier for this implementation, used in settings and in the
     * invoice's `extra` payload for auditability.
     *
     * @return string
     */
    public function getId(): string;

    /**
     * @return string Human-readable label for the admin dropdown.
     */
    public function getLabel(): string;
}