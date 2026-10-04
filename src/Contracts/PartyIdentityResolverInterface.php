<?php
namespace Jankx\Extensions\EInvoice\Contracts;

/**
 * Resolves the identity of one party on an invoice.
 *
 * A Strategy because the source of truth differs by context: the seller's legal
 * identity comes from shop settings, the buyer's may come from order data, user
 * meta, a B2B field on the checkout, or nowhere at all.
 *
 * @package Jankx\Extensions\EInvoice\Contracts
 */
interface PartyIdentityResolverInterface
{
    /**
     * Which side of the invoice this resolver handles.
     *
     * @return string One of {@see \Jankx\Extensions\EInvoice\Model\InvoiceParty::ROLE_*}
     */
    public function getRole(): string;

    /**
     * Produce the party data for an order.
     *
     * @param array $snapshot Prepared order snapshot.
     * @return array Keys: name, address, tax_code, personal_id, phone, email,
     *               bank (optional), generic_consumer (optional bool).
     */
    public function resolve(array $snapshot): array;
}