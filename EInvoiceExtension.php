<?php
namespace Jankx\Extensions\EInvoice;

use Jankx\Extensions\EInvoice\Admin\InvoiceListPage;
use Jankx\Extensions\EInvoice\Admin\InvoiceSettingsPage;
use Jankx\Extensions\EInvoice\Model\InvoiceDatabaseInstaller;
use Jankx\Extensions\EInvoice\Numbering\YearlySequenceNumberGenerator;
use Jankx\Extensions\EInvoice\Profile\Countries\GenericInvoiceProfile;
use Jankx\Extensions\EInvoice\Profile\Countries\VietnamInvoiceProfile;
use Jankx\Extensions\EInvoice\Profile\InvoiceProfileRegistry;
use Jankx\Extensions\EInvoice\Repository\InvoiceRepositoryInterface;
use Jankx\Extensions\EInvoice\Repository\WordPressInvoiceRepository;
use Jankx\Extensions\EInvoice\Snapshot\BuyerIdentityResolver;
use Jankx\Extensions\EInvoice\Snapshot\OrderSnapshotFactory;
use Jankx\Extensions\EInvoice\Snapshot\SellerIdentityResolver;

/**
 * Entry point referenced by manifest.json.
 *
 * Everything here is deliberately lazy: object graphs are built on demand and
 * only the hooks an extension actually needs are registered, so an inactive
 * invoice system costs nothing on the storefront.
 *
 * @package Jankx\Extensions\EInvoice
 */
class EInvoiceExtension
{
    /** @var self|null */
    protected static $instance;

    /** @var InvoiceProfileRegistry|null */
    protected $profiles;

    /** @var InvoiceRepositoryInterface|null */
    protected $repository;

    /** @var \Jankx\Extensions\EInvoice\Service\InvoiceDocumentService|null */
    protected $documents;

    /** @var \Jankx\Extensions\EInvoice\Service\InvoiceIssuanceService|null */
    protected $issuance;

    /** @var \Jankx\Extensions\EInvoice\Mail\InvoiceMailer|null */
    protected $mailer;

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public static function reset(): void
    {
        self::$instance = null;
    }

    /**
     * @param string $file Absolute path to this file.
     */
    public function boot(string $file): void
    {
        if (!class_exists('Jankx\\Extensions\\BaseEcommerce\\Order\\Order')) {
            add_action('admin_notices', function (): void {
                if (current_user_can('activate_plugins')) {
                    echo '<div class="notice notice-error"><p>'
                        . esc_html__(
                            'E-Invoice yêu cầu extension Ecommerce (base-ecommerce) được kích hoạt.',
                            'e-invoice'
                        )
                        . '</p></div>';
                }
            });

            return;
        }

        register_activation_hook($file, [$this, 'activate']);
        register_deactivation_hook($file, [$this, 'deactivate']);

        (new InvoiceDatabaseInstaller())->register();

        add_action('init', [$this, 'loadTextdomain'], 1);
        add_action('init', [$this, 'registerHooks'], 10);

        add_action('jankx/extensions/e-invoice/services', [$this, 'exposeServices']);
        add_filter('jankx/extensions/active', [$this, 'reportActive']);
    }

    // ── Services ─────────────────────────────────────────────────────────────

    public function profiles(): InvoiceProfileRegistry
    {
        if ($this->profiles === null) {
            $this->profiles = new InvoiceProfileRegistry([
                'VN' => static function (): VietnamInvoiceProfile {
                    return new VietnamInvoiceProfile();
                },
            ], static function (): GenericInvoiceProfile {
                return new GenericInvoiceProfile();
            });
        }

        return $this->profiles;
    }

    public function repository(): InvoiceRepositoryInterface
    {
        if ($this->repository === null) {
            $this->repository = new WordPressInvoiceRepository();
        }

        return $this->repository;
    }

    public function documents(): \Jankx\Extensions\EInvoice\Service\InvoiceDocumentService
    {
        if ($this->documents === null) {
            $this->documents = new \Jankx\Extensions\EInvoice\Service\InvoiceDocumentService(
                new \Jankx\Extensions\EInvoice\Render\InvoiceTemplateLoader($this->profiles()),
                $this->buildRenderers()
            );
        }

        return $this->documents;
    }

    public function issuance(): \Jankx\Extensions\EInvoice\Service\InvoiceIssuanceService
    {
        if ($this->issuance === null) {
            $factory = new OrderSnapshotFactory(
                new SellerIdentityResolver($this->profiles()),
                new BuyerIdentityResolver(),
                $this->numbering()
            );

            $this->issuance = new \Jankx\Extensions\EInvoice\Service\InvoiceIssuanceService(
                $this->repository(),
                $factory,
                $this->documents()
            );
        }

        return $this->issuance;
    }

    public function mailer(): \Jankx\Extensions\EInvoice\Mail\InvoiceMailer
    {
        if ($this->mailer === null) {
            $this->mailer = new \Jankx\Extensions\EInvoice\Mail\InvoiceMailer(
                $this->documents(),
                $this->repository()
            );
        }

        return $this->mailer;
    }

    public function numbering(): YearlySequenceNumberGenerator
    {
        return new YearlySequenceNumberGenerator();
    }

    /**
     * Published so sibling extensions (and themes) can decorate the invoice
     * pipeline without subclassing our classes.
     */
    public function exposeServices(array $services = []): array
    {
        return array_merge($services, [
            'profiles'    => $this->profiles(),
            'repository'  => $this->repository(),
            'documents'   => $this->documents(),
            'issuance'    => $this->issuance(),
            'mailer'      => $this->mailer(),
            'numbering'   => $this->numbering(),
        ]);
    }

    /**
     * @return InvoiceRendererInterface[]
     */
    protected function buildRenderers(): array
    {
        $renderers = [
            new \Jankx\Extensions\EInvoice\Render\HtmlInvoiceRenderer(),
        ];

        if (class_exists('Dompdf\\Dompdf')) {
            $renderers[] = new \Jankx\Extensions\EInvoice\Render\DompdfPdfRenderer();
        }

        /**
         * Filter the available document renderers.
         *
         * @param InvoiceRendererInterface[] $renderers
         */
        return apply_filters('jankx/einvoice/renderers', $renderers);
    }

    // ── Hook registration ────────────────────────────────────────────────────

    public function registerHooks(): void
    {
        (new \Jankx\Extensions\EInvoice\Listener\InvoiceOnOrderStatusListener(
            $this->issuance(),
            $this->mailer()
        ))->register();

        (new \Jankx\Extensions\EInvoice\Account\InvoiceAccountPanel(
            $this->documents()
        ))->register();

        (new \Jankx\Extensions\EInvoice\Rest\InvoiceRestController(
            $this->repository(),
            $this->documents()
        ))->register();

        if (is_admin()) {
            (new InvoiceSettingsPage(
                $this->profiles(),
                $this->documents(),
                $this->repository()
            ))->register();

            (new InvoiceListPage($this->repository(), $this->documents()))->register();
        }
    }

    public function loadTextdomain(): void
    {
        load_plugin_textdomain(
            'e-invoice',
            false,
            dirname(plugin_basename($this->pluginFile())) . '/languages'
        );
    }

    /**
     * @return bool
     */
    public function reportActive($active)
    {
        return (bool) $active;
    }

    // ── Activation ───────────────────────────────────────────────────────────

    public function activate(): void
    {
        delete_option(InvoiceDatabaseInstaller::VERSION_OPTION_KEY);
        (new InvoiceDatabaseInstaller())->maybeCreateTables();

        if (!get_option(InvoiceSettingsPage::OPT_ENABLED)) {
            add_option(InvoiceSettingsPage::OPT_ENABLED, '1');
            add_option(InvoiceSettingsPage::OPT_EMAIL, '1');
            add_option(InvoiceSettingsPage::OPT_SERIES, 'HD');
            add_option(InvoiceSettingsPage::OPT_FORM_SYMBOL, '01');
        }

        flush_rewrite_rules();
    }

    public function deactivate(): void
    {
        wp_clear_scheduled_hook('jankx_einvoice_daily_maintenance');
        flush_rewrite_rules();
    }

    /**
     * Absolute path to the extension's plugin file, used for textdomain paths.
     */
    protected function pluginFile(): string
    {
        return E_INVOICE_FILE;
    }
}