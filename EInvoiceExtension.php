<?php
namespace Jankx\Extensions\EInvoice;

use Jankx\Extensions\AbstractExtension;

/**
 * Entry point referenced by manifest.json.
 *
 * Note on the framework contract: ThemeExtensionManager instantiates the caller
 * with `new $class()` (no arguments) at `after_setup_theme` priority 15, then
 * calls `activate()`, which calls `register_hooks()` exactly once. Two
 * consequences shape this class:
 *
 *   1. There is no `boot()` entry point — `init()` and `register_hooks()` are
 *      the contract (see Jankx\Extensions\AbstractExtension).
 *   2. `register_hooks()` runs *before* `init`, so hooks must be registered
 *      there directly. Deferring them onto `init` from inside
 *      `register_hooks()` would work today but breaks if an extension is ever
 *      loaded later.
 *
 * @package Jankx\Extensions\EInvoice
 */
class EInvoiceExtension extends AbstractExtension
{
    protected static $instance;

    public function __construct()
    {
        $this->registerAutoloader();

        parent::__construct();
    }

    /**
     * PSR-4 autoloading for this extension only. The theme has no Composer
     * autoloader for extension namespaces, so extensions self-register.
     */
    protected function registerAutoloader(): void
    {
        spl_autoload_register(static function ($class): void {
            $prefix = 'Jankx\\Extensions\\EInvoice\\';
            $base   = __DIR__ . '/src/';
            $length = strlen($prefix);

            if (strncmp($prefix, $class, $length) !== 0) {
                return;
            }

            $relative = substr($class, $length);
            $file     = $base . str_replace('\\', '/', $relative) . '.php';

            if (is_readable($file)) {
                require_once $file;
            }
        });
    }

    public static function get_instance(): ?self
    {
        return self::$instance;
    }

    // ── Framework lifecycle ──────────────────────────────────────────────────

    public function init(): void
    {
        self::$instance = $this;

        if (!self::dependenciesMet()) {
            add_action('admin_notices', [$this, 'renderDependencyNotice']);

            return;
        }

        // Priority 5 so the tables exist before anything reads or writes them.
        (new Model\InvoiceDatabaseInstaller())->register();

        add_action('init', [$this, 'loadTextdomain'], 1);

        // The active profile is resolved once and memoised; changing the country
        // setting has to invalidate that cache or the old profile sticks for
        // the rest of the request.
        add_action('update_option_jankx_einvoice_country', [$this, 'flushProfiles']);
        add_action('add_option_jankx_einvoice_country', [$this, 'flushProfiles']);
    }

    public function register_hooks(): void
    {
        if (!self::dependenciesMet()) {
            return;
        }

        (new Listener\InvoiceOnOrderStatusListener($this->issuance()))->register();
        (new Account\InvoiceAccountPanel($this->repository()))->register();
        (new Rest\InvoiceRestController($this->repository(), $this->documents()))->register();

        // These are registered unconditionally: their own hooks (admin_menu,
        // admin_init, the ecommerce settings filters) simply never fire in the
        // contexts where they are irrelevant. Gating on is_admin() here would be
        // wrong, because REST and admin-ajax requests also load the extension.
        (new Admin\InvoiceSettingsPage(
            $this->profiles(),
            $this->documents(),
            $this->repository()
        ))->register();

        (new Admin\InvoiceListPage($this->repository(), $this->documents()))->register();

        // Manual issuance. Automatic issuance only reacts to status transitions,
        // so orders completed before the extension was installed can never get an
        // invoice on their own — this gives the shopkeeper a deliberate way to
        // issue one, individually or in bulk.
        (new Admin\InvoiceOrderAction($this->issuance()))->register();
        (new Admin\InvoiceOrderPanel($this->repository()))->register();
        (new Admin\InvoiceBackfillPage($this->issuance(), $this->repository()))->register();

        /**
         * Fires once every e-invoice service is wired. Lets sibling extensions
         * decorate the pipeline (add a renderer, wrap issuance, add a profile)
         * without subclassing our classes.
         *
         * @param EInvoiceExtension $extension
         */
        do_action('jankx/einvoice/ready', $this);
    }

    /**
     * The framework calls this on activation. Order matters: parent first (it
     * registers hooks and flips is_active), then schema.
     */
    public function activate(): bool
    {
        $result = parent::activate();

        if (self::dependenciesMet()) {
            $installer = new Model\InvoiceDatabaseInstaller();

            // Force a re-check even if the version option is current, so an
            // activation after a code deploy picks up pending migrations.
            delete_option(Model\InvoiceDatabaseInstaller::VERSION_OPTION);
            $installer->maybeCreateTables();

            $this->seedDefaults();
        }

        return $result;
    }

    public function deactivate(): bool
    {
        // Deliberately keeps invoices and the counter table: they are accounting
        // records. Resetting numbering here would let a reactivation reuse
        // numbers that were already issued and filed.
        return parent::deactivate();
    }

    public function get_dependencies(): array
    {
        return ['Jankx\\Extensions\\Ecommerce\\Order\\Order'];
    }

    // ── Services ─────────────────────────────────────────────────────────────

    public function profiles(): Profile\InvoiceProfileRegistry
    {
        return Profile\InvoiceProfileRegistry::get_instance();
    }

    public function repository(): Repository\InvoiceRepositoryInterface
    {
        static $repository;

        if ($repository === null) {
            $repository = new Repository\WordPressInvoiceRepository();
        }

        return $repository;
    }

    public function documents(): Service\InvoiceDocumentService
    {
        static $documents;

        if ($documents === null) {
            $documents = new Service\InvoiceDocumentService(
                new Render\InvoiceTemplateLoader($this->profiles()),
                $this->renderers(),
                $this->repository()
            );
        }

        return $documents;
    }

    public function issuance(): Service\InvoiceIssuanceService
    {
        static $issuance;

        if ($issuance === null) {
            $issuance = new Service\InvoiceIssuanceService(
                $this->repository(),
                new Snapshot\OrderSnapshotFactory(
                    new Snapshot\SellerIdentityResolver(),
                    new Snapshot\BuyerIdentityResolver()
                ),
                $this->profiles()
            );
        }

        return $issuance;
    }

    public function mailer(): Mail\InvoiceMailer
    {
        static $mailer;

        if ($mailer === null) {
            $mailer = new Mail\InvoiceMailer($this->documents(), $this->repository());
        }

        return $mailer;
    }

    /**
     * @return Render\InvoiceRendererInterface[]
     */
    public function renderers(): array
    {
        $loader   = new Render\InvoiceTemplateLoader($this->profiles());
        $renderers = [];

        // PDF is only offered when Dompdf is actually installed, so the settings
        // screen never presents a choice that would fail at render time.
        if (class_exists('Dompdf\\Dompdf')) {
            $renderers['dompdf'] = new Render\DompdfPdfRenderer($loader);
        }

        // HTML is always available and is the default.
        $renderers['html'] = new Render\HtmlInvoiceRenderer($loader);

        /**
         * Filter the available invoice document renderers, keyed by renderer id.
         *
         * @param Render\InvoiceRendererInterface[] $renderers
         */
        return (array) apply_filters('jankx/einvoice/renderers', $renderers);
    }

    // ── Hook callbacks ───────────────────────────────────────────────────────

    public function loadTextdomain(): void
    {
        $path = $this->get_extension_path();

        if ($path !== '') {
            load_plugin_textdomain('e-invoice', false, basename($path) . '/languages');
        }
    }

    public function flushProfiles(): void
    {
        Profile\InvoiceProfileRegistry::reset();
    }

    public function renderDependencyNotice(): void
    {
        if (!current_user_can('activate_plugins')) {
            return;
        }

        echo '<div class="notice notice-error"><p>'
            . esc_html__(
                'E-Invoice cần extension Ecommerce (base-ecommerce) được kích hoạt để hoạt động.',
                'e-invoice'
            )
            . '</p></div>';
    }

    // ── Internals ────────────────────────────────────────────────────────────

    protected static function dependenciesMet(): bool
    {
        foreach (['Jankx\\Extensions\\Ecommerce\\Order\\Order'] as $class) {
            if (!class_exists($class)) {
                return false;
            }
        }

        return true;
    }

    /**
     * First-run defaults. Only seeds when nothing is configured, so it can never
     * overwrite a merchant's settings.
     */
    protected function seedDefaults(): void
    {
        $defaults = [
            Admin\InvoiceSettingsPage::OPT_ENABLED     => '1',
            Admin\InvoiceSettingsPage::OPT_EMAIL       => '1',
            Admin\InvoiceSettingsPage::OPT_SERIES      => 'HD',
            Admin\InvoiceSettingsPage::OPT_FORM_SYMBOL => '01',
        ];

        foreach ($defaults as $option => $value) {
            if (get_option($option) === false) {
                add_option($option, $value);
            }
        }
    }
}