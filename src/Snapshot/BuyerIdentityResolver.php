<?php
namespace Jankx\Extensions\EInvoice\Snapshot;

use Jankx\Extensions\EInvoice\Contracts\PartyIdentityResolverInterface;
use Jankx\Extensions\EInvoice\Model\InvoiceParty;

/**
 * Buyer identity, with B2B support layered on top of the order's customer data.
 *
 * base-ecommerce persists only `customer_id`, `customer_name`, `customer_email`,
 * `customer_phone` and `customer_address` on the order row — there is no tax-code
 * or company field anywhere in the base install. So the B2B identity is resolved
 * from three layers, most specific first:
 *
 *   1. Order line meta  — a `billing` array stored on the order at checkout,
 *                         which is the correct source because the buyer may have
 *                         used a different company than their account profile.
 *   2. User meta        — the buyer's saved B2B details, for repeat purchases.
 *   3. Order columns    — name/email/phone/address, or nothing at all.
 *
 * When every layer yields no tax identity, `name` stays empty on purpose and the
 * country profile substitutes its legally prescribed wording. Vietnam requires
 * the literal "Bán cho người tiêu dùng" (NĐ 254/2026, Phụ lục khoản 4 điểm b) —
 * printing the buyer's name with no tax code, or leaving the field blank, are
 * both defects. So this resolver must NOT invent a fallback phrase itself; that
 * belongs to the profile.
 *
 * @package Jankx\Extensions\EInvoice\Snapshot
 */
class BuyerIdentityResolver implements PartyIdentityResolverInterface
{
    public function getRole(): string
    {
        return InvoiceParty::ROLE_BUYER;
    }

    public function resolve(array $snapshot): array
    {
        $order  = is_array($snapshot['order'] ?? null) ? $snapshot['order'] : [];
        $userId = (int) ($order['customer_id'] ?? 0);

        $billing = $this->fromOrderBilling($order);
        $profile = $this->fromUserMeta($userId);

        // Most specific layer wins per field, so a buyer can have a company on
        // the order but a phone number only on their account.
        $layers = [$billing, $profile];

        $buyer = [
            'name'        => $this->firstNonEmpty('name', $layers, (string) ($order['customer_name'] ?? '')),
            'address'     => $this->firstNonEmpty('address', $layers, (string) ($order['customer_address'] ?? '')),
            'tax_code'    => $this->firstNonEmpty('tax_code', $layers),
            'personal_id' => $this->firstNonEmpty('personal_id', $layers),
            'phone'       => $this->firstNonEmpty('phone', $layers, (string) ($order['customer_phone'] ?? '')),
            'email'       => $this->firstNonEmpty('email', $layers, (string) ($order['customer_email'] ?? '')),
        ];

        /**
         * Filter the buyer identity printed on invoices.
         *
         * Returning an empty `name` is valid and meaningful: it asks the country
         * profile to apply its anonymous-consumer wording.
         *
         * @param array $buyer
         * @param array $order
         * @param int   $userId
         */
        $buyer = (array) apply_filters('jankx/einvoice/buyer_identity', $buyer, $order, $userId);

        return $buyer;
    }

    /**
     * B2B details captured for this specific order.
     *
     * base-ecommerce has no order-meta table, so the checkout stores them inside
     * the existing `notes` JSON column as a structured note. That keeps the
     * extension free of schema changes while remaining per-order.
     */
    protected function fromOrderBilling(array $order): array
    {
        $notes = is_array($order['notes'] ?? null) ? $order['notes'] : [];

        foreach ($notes as $note) {
            if (!is_array($note)) {
                continue;
            }
            $meta = $note['meta'] ?? null;
            if (is_array($meta) && isset($meta['einvoice_billing']) && is_array($meta['einvoice_billing'])) {
                return array_filter($meta['einvoice_billing'], [$this, 'isFilled']);
            }
        }

        return [];
    }

    /**
     * B2B details saved on the buyer's account.
     */
    protected function fromUserMeta(int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }

        $map = [
            'name'        => 'jankx_einvoice_buyer_name',
            'tax_code'    => 'jankx_einvoice_buyer_tax_code',
            'personal_id' => 'jankx_einvoice_buyer_personal_id',
            'address'     => 'jankx_einvoice_buyer_address',
            'phone'       => 'phone',
            'email'       => 'email',
        ];

        $out = [];
        foreach ($map as $field => $metaKey) {
            $value = (string) get_user_meta($userId, $metaKey, true);
            if ($value !== '') {
                $out[$field] = $value;
            }
        }

        return $out;
    }

    /**
     * First non-empty value for $field across the given layers.
     *
     * @param string $field   Field being resolved.
     * @param array  $layers  Layer arrays, most specific first.
     * @param string $default Value used when no layer supplies one.
     */
    protected function firstNonEmpty(string $field, array $layers, string $default = ''): string
    {
        foreach ($layers as $layer) {
            $value = isset($layer[$field]) ? (string) $layer[$field] : '';
            if ($value !== '') {
                return $value;
            }
        }

        return $default;
    }

    protected function isFilled($value): bool
    {
        return is_scalar($value) && (string) $value !== '';
    }
}