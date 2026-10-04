<?php
namespace Jankx\Extensions\EInvoice\Support;

/**
 * Money helper.
 *
 * All monetary math in the invoice pipeline goes through this class so that
 * rounding behaviour is defined in exactly one place. Invoices are legal
 * documents: a rounding drift of one dong between the line table and the
 * total row is a compliance defect, so we never rely on implicit float
 * rounding.
 *
 * @package Jankx\Extensions\EInvoice\Support
 */
class Amount
{
    /**
     * Number of decimal places retained in the stored/printed invoice.
     *
     * VND is a zero-decimal currency but is stored as decimal(15,2) for
     * schema uniformity; we simply never print a fractional dong.
     */
    public static function decimalsFor(string $currency): int
    {
        /**
         * Filter the decimal precision used when formatting an invoice amount.
         *
         * @param int    $decimals Number of decimals (0 for VND, 2 for USD, ...).
         * @param string $currency ISO currency code.
         */
        return (int) apply_filters('jankx/einvoice/amount_decimals', self::defaultDecimals($currency), $currency);
    }

    protected static function defaultDecimals(string $currency): int
    {
        switch (strtoupper($currency)) {
            case 'VND':
            case 'JPY':
            case 'KRW':
            case 'CLP':
            case 'ISK':
                return 0;
            case 'BHD':
            case 'JOD':
            case 'KWD':
            case 'OMR':
            case 'TND':
                return 3;
            default:
                return 2;
        }
    }

    /**
     * Round to the currency's precision using "half away from zero", which is
     * the rounding mode Vietnamese accounting practice expects for tax splits.
     */
    public static function round(float $value, string $currency = 'VND'): float
    {
        return round($value, self::decimalsFor($currency));
    }

    /**
     * Format for display. Separators are configurable because the same shop
     * may present both "1.234.567 ₫" (vi-VN) and "1,234,567 VND" (en-US).
     */
    public static function format(float $value, string $currency = 'VND', array $overrides = []): string
    {
        $decimals = array_key_exists('decimals', $overrides)
            ? (int) $overrides['decimals']
            : self::decimalsFor($currency);

        $thousandSep = $overrides['thousand_sep'] ?? (self::isVietnamese($currency) ? '.' : ',');
        $decimalSep  = $overrides['decimal_sep']  ?? (self::isVietnamese($currency) ? ',' : '.');

        $formatted = number_format(
            self::round($value, $currency),
            $decimals,
            $decimalSep,
            $thousandSep
        );

        $position = $overrides['position'] ?? (self::isVietnamese($currency) ? 'suffix' : 'prefix');
        $symbol   = $overrides['symbol'] ?? self::symbol($currency);
        $gap      = $overrides['gap'] ?? (self::isVietnamese($currency) ? "\u{00A0}" : "\u{00A0}");

        if ($symbol === '') {
            return $formatted;
        }

        return $position === 'prefix'
            ? $symbol . $gap . $formatted
            : $formatted . $gap . $symbol;
    }

    /**
     * Numeric-only formatting: digits + separators, no currency symbol.
     *
     * Vietnamese invoices must print the line amounts and the grand total as
     * plain numerals ("12.500.000") with the currency stated once in the
     * invoice header, so this is the method templates should use for the table
     * and the total block.
     */
    public static function formatPlain(float $value, string $currency = 'VND', array $overrides = []): string
    {
        $thousandSep = $overrides['thousand_sep'] ?? (self::isVietnamese($currency) ? '.' : ',');
        $decimalSep  = $overrides['decimal_sep']  ?? (self::isVietnamese($currency) ? ',' : '.');

        return number_format(
            self::round($value, $currency),
            (int) ($overrides['decimals'] ?? self::decimalsFor($currency)),
            $decimalSep,
            $thousandSep
        );
    }

    public static function symbol(string $currency): string
    {
        $symbols = [
            'VND' => "\u{20AB}",
            'USD' => '$',
            'EUR' => "\u{20AC}",
            'GBP' => "\u{A3}",
            'JPY' => "\u{A5}",
            'KRW' => "\u{AE0}",
            'CNY' => "\u{A5}",
            'THB' => "\u{0E3F}",
            'SGD' => 'S$',
            'AUD' => 'A$',
            'MYR' => 'RM',
            'IDR' => 'Rp',
            'PHP' => "\u{20B1}",
        ];

        $currency = strtoupper($currency);

        /** This filter is documented in docs/EXTENDING.md */
        return (string) apply_filters('jankx/einvoice/currency_symbol', $symbols[$currency] ?? $currency, $currency);
    }

    public static function isVietnamese(string $currency): bool
    {
        return strtoupper($currency) === 'VND';
    }

    /**
     * Allocate an amount across weights without losing or inventing units.
     *
     * Used to split a single order total into per-tax-rate buckets so the tax
     * lines always re-sum to the invoice total exactly. A naive float split
     * drifts (1000 USD over three equal weights lands on 999.99), which on a
     * legal document is a defect. So we work in integer minor units and apply
     * the "largest remainder" method: floor everything, then hand the leftover
     * units to the buckets with the biggest fractional part.
     *
     * @param float   $total   Amount to distribute.
     * @param float[] $weights Non-negative relative weights.
     * @return float[] Distribution with the same keys/ordering as $weights.
     */
    public static function distribute(float $total, array $weights, string $currency = 'VND'): array
    {
        if (!$weights) {
            return [];
        }

        $decimals = self::decimalsFor($currency);
        $factor   = 10 ** $decimals;

        // Everything below happens in integer minor units so the sum is exact.
        $totalUnits = (int) round($total * $factor);

        $sumWeights = array_sum($weights);

        if ($sumWeights <= 0) {
            // Nothing to weight by: give it all to the first bucket.
            $out = array_fill_keys(array_keys($weights), 0.0);
            $first = array_key_first($out);
            $out[$first] = $totalUnits / $factor;
            return $out;
        }

        $bases     = [];
        $remainder = [];
        $assigned  = 0;

        foreach ($weights as $key => $weight) {
            $exact = ($totalUnits * $weight) / $sumWeights;
            $base  = (int) floor($exact);

            $bases[$key]     = $base;
            $remainder[$key] = $exact - $base;
            $assigned       += $base;
        }

        $leftover = $totalUnits - $assigned;

        if ($leftover !== 0) {
            // Rank by fractional part, descending, then hand out one unit each.
            uasort($remainder, static function ($a, $b) {
                return $b <=> $a;
            });

            $i = 0;
            foreach (array_keys($remainder) as $key) {
                if ($i >= abs($leftover)) {
                    break;
                }
                $bases[$key] += $leftover > 0 ? 1 : -1;
                $i++;
            }
        }

        $out = [];
        foreach ($weights as $key => $weight) {
            $out[$key] = $bases[$key] / $factor;
        }

        return $out;
    }
}