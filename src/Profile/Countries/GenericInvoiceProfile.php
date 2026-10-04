<?php
namespace Jankx\Extensions\EInvoice\Profile\Countries;

use Jankx\Extensions\EInvoice\Profile\AbstractInvoiceProfile;

/**
 * Neutral fallback profile for jurisdictions without a dedicated implementation.
 *
 * This is deliberately permissive: it satisfies the structural invariants every
 * regime shares (identified seller, identified buyer, reconciling totals) and
 * omits the local specifics — number-to-words, per-rate VAT grouping, mandatory
 * authority codes.
 *
 * It exists so that switching a shop's country does not produce a fatal error,
 * and so the extension degrades to "a readable invoice" instead of "a
 * non-compliant one pretending to be compliant". The admin settings screen warns
 * that the generic profile has not been legally validated.
 *
 * To add real support, copy this class and override what applies:
 *
 *     add_filter('jankx/einvoice/profiles', function (array $profiles) {
 *         $profiles['DE'] = new GermanyInvoiceProfile();
 *         return $profiles;
 *     });
 *
 * @package Jankx\Extensions\EInvoice\Profile\Countries
 */
class GenericInvoiceProfile extends AbstractInvoiceProfile
{
    protected $countryCode = '';
    protected $locale      = 'en_US';
    protected $currency    = 'USD';

    public function getCountryName(): string
    {
        return __('Generic (no legal profile)', 'e-invoice');
    }

    public function getId(): string
    {
        return 'generic';
    }

    public function getLegalBasis(): array
    {
        return [];
    }

    public function getComplianceNotes(): array
    {
        return [
            'unvalidated' => 'This profile has not been validated against any specific tax authority. Verify it against local law before issuing real documents.',
        ];
    }

    public function getTemplateId(): string
    {
        return 'generic';
    }

    /**
     * No amount-in-words requirement, no per-rate grouping: both are
     * jurisdiction-specific and guessing them would be worse than omitting them.
     */
    public function requiresAmountInWords(): bool
    {
        return false;
    }

    public function requiresTaxBreakdownByRate(): bool
    {
        return false;
    }
}