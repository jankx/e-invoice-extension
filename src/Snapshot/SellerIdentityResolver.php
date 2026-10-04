<?php
namespace Jankx\Extensions\EInvoice\Snapshot;

use Jankx\Extensions\EInvoice\Contracts\PartyIdentityResolverInterface;
use Jankx\Extensions\EInvoice\Model\InvoiceParty;

/**
 * Seller identity from the shop's own settings.
 *
 * Reads the base-ecommerce general settings where they exist
 * (`jankx_store_name`, `jankx_store_address`, `jankx_store_phone`,
 * `jankx_store_email`) so a shop that has already filled those in does not have
 * to duplicate them, and falls back to WordPress's own blog metadata for a
 * zero-config first run.
 *
 * The tax code has no upstream equivalent — mã số thuế is legally mandatory on
 * every Vietnamese invoice (Điều 10(1)(c) NĐ 254/2026) and there is no sensible
 * default — so it lives in this extension's own settings.
 *
 * @package Jankx\Extensions\EInvoice\Snapshot
 */
class SellerIdentityResolver implements PartyIdentityResolverInterface
{
    public function getRole(): string
    {
        return InvoiceParty::ROLE_SELLER;
    }

    public function resolve(array $snapshot): array
    {
        $seller = [
            'name'      => (string) get_option('jankx_einvoice_seller_name', get_option('jankx_store_name', get_bloginfo('name'))),
            'address'   => (string) get_option('jankx_einvoice_seller_address', get_option('jankx_store_address', '')),
            'tax_code'  => (string) get_option('jankx_einvoice_seller_tax_code', ''),
            'phone'     => (string) get_option('jankx_einvoice_seller_phone', get_option('jankx_store_phone', '')),
            'email'     => (string) get_option('jankx_einvoice_seller_email', get_option('jankx_store_email', get_option('admin_email'))),
            'personal_id' => (string) get_option('jankx_einvoice_seller_personal_id', ''),
        ];

        $bank = [
            'name'    => (string) get_option('jankx_einvoice_seller_bank_name', ''),
            'account' => (string) get_option('jankx_einvoice_seller_bank_account', ''),
        ];

        if ($bank['name'] !== '' || $bank['account'] !== '') {
            $seller['bank'] = array_filter($bank);
        }

        // Optional: business location code, required of fuel sellers and of
        // multi-premises hộ kinh doanh under NĐ 254/2026.
        $location = (string) get_option('jankx_einvoice_seller_location', '');
        if ($location !== '') {
            $seller['location'] = $location;
        }

        /**
         * Filter the seller identity printed on invoices.
         *
         * @param array $seller
         * @param array $snapshot
         */
        $seller = (array) apply_filters('jankx/einvoice/seller_identity', $seller, $snapshot);

        return $seller;
    }
}