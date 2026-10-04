<?php
namespace Jankx\Extensions\EInvoice\Support;

/**
 * Vietnamese number-to-words ("viết bằng chữ").
 *
 * Vietnamese law requires the invoice grand total to be stated in words as
 * well as in figures. This is a *legal* rendering, not a UX nicety, so the
 * irregularities of the language must be honoured exactly:
 *
 *   - 15   -> "mười lăm"      (NOT "một mươi lăm")
 *   - 25   -> "hai mươi lăm"   (the "năm" of the units column becomes "lăm")
 *   - 105  -> "một trăm lẻ năm"
 *   - 1005 -> "một nghìn lẻ năm"
 *   - 15_000 -> "mười lăm nghìn"
 *
 * @package Jankx\Extensions\EInvoice\Support
 */
class VietnameseNumberToWords
{
    /** @var string[] 0..9 */
    protected const DIGITS = [
        0 => 'không',
        1 => 'một',
        2 => 'hai',
        3 => 'ba',
        4 => 'bốn',
        5 => 'năm',
        6 => 'sáu',
        7 => 'bảy',
        8 => 'tám',
        9 => 'chín',
    ];

    /** @var string[] Triplet scale names for index 0..3 */
    protected const SCALES = ['', 'nghìn', 'triệu', 'tỷ'];

    /**
     * Convert an integer amount to Vietnamese words.
     *
     * @param int|string $number Non-negative amount, fractional part ignored.
     */
    public static function convert($number): string
    {
        $number = (int) $number;

        if ($number === 0) {
            return 'không';
        }

        $negative = $number < 0;
        $number    = abs($number);

        $triplets = [];
        while ($number > 0) {
            $triplets[] = $number % 1000;
            $number = intdiv($number, 1000);
        }

        $parts = [];
        $total = count($triplets);

        // Walk from the most significant triplet down, skipping zero triplets.
        for ($i = $total - 1; $i >= 0; $i--) {
            $triplet = $triplets[$i];
            if ($triplet === 0) {
                continue;
            }

            $words = self::readTriplet($triplet);
            $scale = self::scaleName($i);
            $parts[] = trim($words . ' ' . $scale);

            // If any lower triplet is non-zero we need "lẻ" to avoid ambiguity
            // (e.g. 1,005 -> "một nghìn lẻ năm").
            if (self::hasAnyNonZero($triplets, $i)) {
                $parts[] = 'lẻ';
            }
        }

        $result = implode(' ', $parts);

        return ($negative ? 'âm ' : '') . $result;
    }

    /**
     * Full sentence form used on the invoice: "… đồng".
     *
     * @param int|string $number
     * @param string     $currency Currency code; only VND gets the "đồng" suffix
     *                              because that is the only currency this
     *                              profile is legally validated for.
     */
    public static function convertAmount($number, string $currency = 'VND'): string
    {
        // VND has no subunit in circulation, so the whole unit is the legal
        // granularity of the "tổng số tiền bằng chữ" field.
        $words = self::convert((int) round((float) $number));

        if (strtoupper($currency) === 'VND') {
            return $words . ' đồng';
        }

        return $words . ' ' . strtoupper($currency);
    }

    /**
     * Read a single 0..999 triplet.
     */
    protected static function readTriplet(int $triplet): string
    {
        $hundreds = intdiv($triplet, 100);
        $remainder = $triplet % 100;
        $words = [];

        if ($hundreds > 0) {
            $words[] = self::DIGITS[$hundreds] . ' trăm';
        }

        if ($remainder > 0) {
            $words[] = self::readBelowHundred($remainder);
        }

        return implode(' ', $words);
    }

    /**
     * Read 1..99 applying the "mười" / "lăm" / "tư" irregularities.
     */
    protected static function readBelowHundred(int $value): string
    {
        $tens  = intdiv($value, 10);
        $units = $value % 10;
        $words = [];

        if ($tens === 0) {
            return self::DIGITS[$units];
        }

        if ($tens === 1) {
            $words[] = 'mười';
        } else {
            $words[] = self::DIGITS[$tens] . ' mươi';
        }

        if ($units > 0) {
            // 15 -> "mười lăm", 25 -> "hai mươi lăm"
            $words[] = $units === 5 ? 'lăm' : self::DIGITS[$units];
        }

        return implode(' ', $words);
    }

    protected static function scaleName(int $index): string
    {
        if ($index < count(self::SCALES)) {
            return self::SCALES[$index];
        }

        // 10^12 and above. Vietnamese groups strictly by thousand
        // ("nghìn tỷ", "nghìn triệu tỷ"), so recurse rather than invent names.
        return 'nghìn ' . self::scaleName($index - 1);
    }

    /**
     * @param int[] $triplets
     */
    protected static function hasAnyNonZero(array $triplets, int $from): bool
    {
        for ($i = 0; $i < $from; $i++) {
            if (($triplets[$i] ?? 0) !== 0) {
                return true;
            }
        }
        return false;
    }
}