<?php
namespace Jankx\Extensions\EInvoice\Contracts;

use Jankx\Extensions\EInvoice\Model\Invoice;

/**
 * THE central abstraction of this extension: the legal profile of a
 * jurisdiction.
 *
 * Every country has its own rules about what an invoice must contain, how it
 * must be numbered, whether the total must be spelled out, how the buyer must
 * be identified when they are an anonymous consumer, and how tax must be
 * grouped. Rather than burying those differences in `if ($country === 'VN')`
 * branches, each jurisdiction becomes a Profile implementing this contract and
 * the pipeline asks the profile what to do.
 *
 * Adding a country therefore means adding one class — no changes to the
 * issuance service, renderer, repository or templates pipeline.
 *
 * Implement this to support a new jurisdiction:
 *
 *     class GermanyInvoiceProfile extends AbstractInvoiceProfile
 *     {
 *         protected $countryCode = 'DE';
 *
 *         protected function getLegalBasis(): array {
 *             return ['§14 UStG'];
 *         }
 *
 *         public function getRequiredFields(): array {
 *             return array_merge(parent::getRequiredFields(), ['seller_vat_id', 'buyer_vat_id']);
 *         }
 *     }
 *
 * …then register it:
 *
 *     add_filter('jankx/einvoice/profiles', function (array $profiles) {
 *         $profiles['DE'] = new GermanyInvoiceProfile();
 *         return $profiles;
 *     });
 *
 * @package Jankx\Extensions\EInvoice\Contracts
 */
interface InvoiceProfileInterface
{
    // ── Identity ─────────────────────────────────────────────────────────────

    /** ISO 3166-1 alpha-2 code, e.g. "VN". */
    public function getCountryCode(): string;

    /** Human-readable country name. */
    public function getCountryName(): string;

    /** Locale used for dates and number-to-words, e.g. "vi_VN". */
    public function getLocale(): string;

    /** Default currency for the jurisdiction, e.g. "VND". */
    public function getCurrency(): string;

    /**
     * Stable identifier stored on each invoice for auditability.
     * Changing invoice labels must not change this value.
     */
    public function getId(): string;

    // ── Legal metadata ───────────────────────────────────────────────────────

    /**
     * Legislation this profile encodes, for display on the document and in the
     * admin so a reviewer can see which rules it was built against.
     *
     * @return string[]
     */
    public function getLegalBasis(): array;

    /**
     * Machine-readable description of what the jurisdiction mandates.
     *
     * @return array<string, mixed>
     */
    public function getComplianceNotes(): array;

    // ── Document content ─────────────────────────────────────────────────────

    /**
     * Field keys the jurisdiction requires, in printing order.
     *
     * Consumers (templates, validators, the admin completeness meter) all read
     * this rather than hard-coding a list, which is what keeps templates
     * reusable across countries.
     *
     * @return string[]
     */
    public function getRequiredFields(): array;

    /**
     * Labels for the required fields, keyed identically to
     * {@see self::getRequiredFields()}.
     *
     * @return array<string, string>
     */
    public function getFieldLabels(): array;

    /**
     * Wording printed above the line table.
     */
    public function getLineTableHeading(): string;

    /**
     * Wording for the "grand total" row.
     */
    public function getGrandTotalLabel(): string;

    /**
     * Wording printed above the per-rate tax breakdown.
     */
    public function getTaxSummaryHeading(): string;

    /**
     * The unit column label; jurisdictions differ ("Đơn vị tính", "Unit", "Menge").
     */
    public function getUnitLabel(): string;

    /**
     * Whether the total must be stated in words.
     */
    public function requiresAmountInWords(): bool;

    /**
     * Render an amount in words using the local language.
     *
     * @param float  $amount
     * @param string $currency
     * @return string
     */
    public function amountToWords(float $amount, string $currency): string;

    /**
     * Exact wording to print in the buyer block when the buyer gave no tax
     * identity.
     *
     * Vietnam mandates "Bán cho người tiêu dùng" here. Returning null means the
     * jurisdiction prescribes no specific wording, so the buyer's own name is
     * used and, failing that, the template shows a placeholder.
     *
     * @return string|null
     */
    public function getAnonymousBuyerText(): ?string;

    /**
     * Extra party identity slots the jurisdiction demands beyond name/address
     * and tax code — e.g. Vietnam wants a business location code on fuel
     * invoices, Germany wants a delivery note reference.
     *
     * @return array<string, string> Field key => label.
     */
    public function getExtraPartyFields(): array;

    /**
     * Values for the extra party slots, read from the invoice.
     *
     * @param Invoice $invoice
     * @return array<string, string>
     */
    public function resolveExtraPartyValues(Invoice $invoice): array;

    /**
     * Whether tax must be broken out per rate (true) or may be a single line.
     */
    public function requiresTaxBreakdownByRate(): bool;

    /**
     * Tax authority verification code, when the jurisdiction requires one on
     * the face of the document.
     */
    public function getTaxAuthorityCodeLabel(): string;

    /**
     * Whether this jurisdiction requires a seller signature block.
     */
    public function requiresSellerSignature(): bool;

    // ── Template selection ───────────────────────────────────────────────────

    /**
     * Template id resolved from `views/{id}.php`, allowing a theme to override
     * a profile's template by shipping a file of the same name.
     */
    public function getTemplateId(): string;

    // ── Document assembly ────────────────────────────────────────────────────

    /**
     * Build the invoice document for an order snapshot.
     *
     * Implemented once in {@see \Jankx\Extensions\EInvoice\Profile\AbstractInvoiceProfile}
     * as a Template Method; subclasses supply the parts that vary by country.
     *
     * @param array $snapshot Prepared order snapshot from the snapshot factory.
     * @return Invoice
     */
    public function buildDocument(array $snapshot): Invoice;
}