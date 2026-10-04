<?php
namespace Jankx\Extensions\EInvoice\Profile;

use Jankx\Extensions\EInvoice\Contracts\InvoiceProfileInterface;
use Jankx\Extensions\EInvoice\Profile\Countries\GenericInvoiceProfile;
use Jankx\Extensions\EInvoice\Profile\Countries\VietnamInvoiceProfile;

/**
 * Registry + Strategy resolver for country profiles.
 *
 * Combines two patterns deliberately:
 *
 *   • Registry — the set of known legal profiles, keyed by country code, so the
 *     admin can present a country picker and the rest of the code never needs a
 *     chain of conditionals.
 *   • Strategy resolution — `resolve()` returns the {@see InvoiceProfileInterface}
 *     that the rest of the pipeline programs against.
 *
 * The registry is a singleton because it caches instances, and profiles carry
 * an injected numbering generator.
 *
 * ── Extending ───────────────────────────────────────────────────────────────
 *
 *     add_filter('jankx/einvoice/profiles', function (array $profiles) {
 *         $profiles['DE'] = new GermanyInvoiceProfile();
 *         return $profiles;
 *     });
 *
 * …or change which profile handles the shop wholesale:
 *
 *     add_filter('jankx/einvoice/active_profile', function ($id, $country) {
 *         return 'DE';
 *     }, 10, 2);
 *
 * @package Jankx\Extensions\EInvoice\Profile
 */
class InvoiceProfileRegistry
{
    /** @var self|null */
    protected static $instance;

    /** @var array<string, InvoiceProfileInterface> */
    protected $profiles = [];

    /** @var string ISO code the shop trades under. */
    protected $activeCountry = 'VN';

    protected function __construct()
    {
        $this->activeCountry = $this->detectCountry();

        $this->registerDefaults();

        /**
         * Filter the available country legal profiles.
         *
         * @param array<string, InvoiceProfileInterface> $profiles Keyed by ISO 3166-1 alpha-2.
         */
        $this->profiles = (array) apply_filters('jankx/einvoice/profiles', $this->profiles);
    }

    public static function get_instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Drop the cached instance. Used by tests and by the settings page after a
     * country change.
     */
    public static function reset(): void
    {
        self::$instance = null;
    }

    protected function registerDefaults(): void
    {
        $this->profiles['VN'] = new VietnamInvoiceProfile();
        $this->profiles['']   = new GenericInvoiceProfile();
    }

    /**
     * The shop's trading country.
     *
     * Resolution order: explicit setting → WordPress locale → VN. The default
     * is Vietnam because that is the extension's validated jurisdiction and the
     * overwhelming majority of installs on this platform are Vietnamese.
     */
    protected function detectCountry(): string
    {
        $configured = strtoupper(trim((string) get_option('jankx_einvoice_country', '')));
        if ($configured !== '') {
            return substr($configured, 0, 2);
        }

        $locale = strtolower((string) get_option('WPLANG', ''));
        if (strpos($locale, 'vi') === 0) {
            return 'VN';
        }

        $fromTheme = apply_filters('jankx/einvoice/detect_country', 'VN');

        return strtoupper(substr((string) $fromTheme, 0, 2)) ?: 'VN';
    }

    /**
     * Country the shop currently trades under.
     */
    public function getActiveCountry(): string
    {
        return $this->activeCountry;
    }

    /**
     * @return array<string, InvoiceProfileInterface>
     */
    public function all(): array
    {
        return $this->profiles;
    }

    /**
     * @return string[]
     */
    public function availableCountries(): array
    {
        $codes = array_keys($this->profiles);
        return array_values(array_filter($codes, static function ($code) {
            return $code !== '';
        }));
    }

    /**
     * Resolve the profile for a country, falling back to the generic profile.
     *
     * Never returns null: a shop trading in a jurisdiction we have not modelled
     * still gets a document, plus a visible warning that it is unvalidated.
     *
     * @param string|null $country
     */
    public function resolve(?string $country = null): InvoiceProfileInterface
    {
        $country = strtoupper(substr((string) ($country ?: $this->activeCountry), 0, 2));

        if (isset($this->profiles[$country])) {
            return $this->profiles[$country];
        }

        // Allow a plugin to nominate a profile id even when no ISO key exists.
        $nominee = (string) apply_filters('jankx/einvoice/active_profile', '', $country);
        if ($nominee !== '') {
            foreach ($this->profiles as $profile) {
                if ($profile->getId() === $nominee) {
                    return $profile;
                }
            }
        }

        return $this->generic();
    }

    public function generic(): InvoiceProfileInterface
    {
        return $this->profiles[''] ?? new GenericInvoiceProfile();
    }

    /**
     * @param string $code
     */
    public function has(string $code): bool
    {
        return isset($this->profiles[strtoupper(substr($code, 0, 2))]);
    }

    /**
     * @return array<string, string> Country code => profile label, for selects.
     */
    public function choices(): array
    {
        $out = [];
        foreach ($this->profiles as $code => $profile) {
            if ($code === '') {
                continue;
            }
            $out[$code] = sprintf('%s — %s', $profile->getCountryName(), $profile->getId());
        }

        return $out;
    }
}