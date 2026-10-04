<?php
namespace Jankx\Extensions\EInvoice\Model;

/**
 * A party on an invoice: the seller or the buyer.
 *
 * This is deliberately country-agnostic. "Tax code" means different things in
 * different jurisdictions (mã số thuế in Vietnam, USt-IdNr in Germany,
 * ABN in Australia, TIN in the US), so we keep a small set of generic identity
 * slots and let the country profile decide which one is legally required and
 * how it must be labelled.
 *
 * @package Jankx\Extensions\EInvoice\Model
 */
class InvoiceParty
{
    public const ROLE_SELLER = 'seller';
    public const ROLE_BUYER  = 'buyer';

    /** @var string */
    protected $role;

    /** @var string */
    protected $name;

    /** @var string */
    protected $address;

    /**
     * Generic tax identifier slot. Rendered as "Mã số thuế" by the VN profile,
     * "Tax ID" by the generic profile, etc.
     *
     * @var string
     */
    protected $taxCode;

    /**
     * National citizen identifier, used when the party is a natural person who
     * has no organisation tax code (số định danh cá nhân).
     *
     * @var string
     */
    protected $personalId;

    /** @var string */
    protected $phone;

    /** @var string */
    protected $email;

    /**
     * Additional business-location code and address (mã, địa chỉ địa điểm kinh
     * doanh).
     *
     * Vietnam requires this on invoices issued by fuel sellers and by
     * hộ kinh doanh / cá nhân kinh doanh operating more than one premises
     * (NĐ 254/2026). Kept generic so other jurisdictions can reuse the slot for
     * their own place-of-business identifier.
     *
     * @var string
     */
    protected $location;

    /**
     * Bank details for the seller. Vietnam's HĐĐT carries a "Thông tin người
     * bán" block that commonly includes the settlement account.
     *
     * @var array
     */
    protected $bank;

    /**
     * True when we fell back to a generic "sold to the general public" wording
     * because the buyer supplied no tax identity. For Vietnam this is a legal
     * requirement (NĐ 254/2026 Art. 10, clause 4 point b) — the buyer field
     * must read "Bán cho người tiêu dùng" rather than being left blank.
     *
     * @var bool
     */
    protected $genericConsumer = false;

    public function __construct(array $data = [])
    {
        $this->role           = (string) ($data['role'] ?? self::ROLE_BUYER);
        $this->name           = (string) ($data['name'] ?? '');
        $this->address        = (string) ($data['address'] ?? '');
        $this->taxCode        = (string) ($data['tax_code'] ?? '');
        $this->personalId     = (string) ($data['personal_id'] ?? '');
        $this->phone          = (string) ($data['phone'] ?? '');
        $this->email          = (string) ($data['email'] ?? '');
        $this->location       = (string) ($data['location'] ?? '');
        $this->bank           = is_array($data['bank'] ?? null) ? $data['bank'] : [];
        $this->genericConsumer = !empty($data['generic_consumer']);
    }

    public function getRole(): string
    {
        return $this->role;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getAddress(): string
    {
        return $this->address;
    }

    public function getTaxCode(): string
    {
        return $this->taxCode;
    }

    public function getPersonalId(): string
    {
        return $this->personalId;
    }

    public function getPhone(): string
    {
        return $this->phone;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getLocation(): string
    {
        return $this->location;
    }

    public function getBank(): array
    {
        return $this->bank;
    }

    public function isGenericConsumer(): bool
    {
        return $this->genericConsumer;
    }

    /**
     * A party qualifies as a business when it carries a tax code or an
     * organisation personal id. Used to decide whether the buyer may be
     * presented as a named B2B customer.
     */
    public function isBusiness(): bool
    {
        return $this->taxCode !== '';
    }

    /**
     * Return a copy with selected fields replaced.
     *
     * @param array $overrides
     */
    public function with(array $overrides): self
    {
        return new self(array_merge($this->toArray(), $overrides));
    }

    public function toArray(): array
    {
        return [
            'role'             => $this->role,
            'name'             => $this->name,
            'address'          => $this->address,
            'tax_code'         => $this->taxCode,
            'personal_id'      => $this->personalId,
'phone'          => $this->phone,
            'email'          => $this->email,
            'location'       => $this->location,
            'bank'           => $this->bank,
            'generic_consumer' => $this->genericConsumer,
        ];
    }
}