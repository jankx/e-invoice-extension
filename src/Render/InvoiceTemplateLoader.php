<?php
namespace Jankx\Extensions\EInvoice\Render;

use Jankx\Extensions\EInvoice\Model\Invoice;
use Jankx\Extensions\EInvoice\Profile\InvoiceProfileRegistry;

/**
 * Loads the country template that prints an invoice.
 *
 * Template resolution is a Template Method over a simple precedence chain, and
 * it is deliberately overridable so a theme can restyle invoices without
 * forking the extension:
 *
 *   1. the active child/parent theme, if it provides the file
 *      (`{theme}/e-invoice/{profileId}.php`)
 *   2. the extension's own `views/{profileId}.php`
 *   3. `views/generic.php`
 *
 * `$invoice`, `$profile` and `$currency` are extracted into scope; templates are
 * plain PHP and print nothing but the document.
 *
 * @package Jankx\Extensions\EInvoice\Render
 */
class InvoiceTemplateLoader
{
    /** @var string */
    protected $viewsPath;

    /** @var InvoiceProfileRegistry */
    protected $profiles;

    public function __construct(InvoiceProfileRegistry $profiles, ?string $viewsPath = null)
    {
        $this->profiles  = $profiles;
        $this->viewsPath = $viewsPath ?: dirname(__DIR__) . '/views';
    }

    /**
     * Render a profile's template and return the markup.
     */
    public function render(Invoice $invoice): string
    {
        $profile = $this->profiles->resolve($invoice->getCountryCode());
        $template = $this->locate($profile->getTemplateId());

        if ($template === null) {
            // Last resort: a readable document beats a fatal error.
            $template = $this->locate('generic');
        }

        if ($template === null) {
            return '';
        }

        $currency = $invoice->getCurrency();

        ob_start();
        (static function (string $__file, array $__vars): void {
            extract($__vars, EXTR_SKIP);
            require $__file;
        })($template, [
            'invoice'  => $invoice,
            'profile'  => $profile,
            'currency' => $currency,
            'loader'   => $this,
        ]);

        return (string) ob_get_clean();
    }

    /**
     * Absolute path to a template id, or null when not found.
     */
    public function locate(string $templateId): ?string
    {
        $templateId = $this->sanitiseId($templateId);
        if ($templateId === '') {
            return null;
        }

        foreach ($this->candidatePaths($templateId) as $path) {
            if (is_readable($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * @return string[]
     */
    protected function candidatePaths(string $templateId): array
    {
        $paths = [];

        // Theme override first, so a child theme can restyle the document.
        $override = $this->themeOverridePath($templateId);
        if ($override !== null) {
            $paths[] = $override;
        }

        $paths[] = $this->viewsPath . '/' . $templateId . '.php';

        return $paths;
    }

    /**
     * Locate a theme-provided template override.
     *
     * Checks the child theme first, then the parent, which is the order
     * WordPress itself uses for template overrides.
     */
    protected function themeOverridePath(string $templateId): ?string
    {
        $relative = 'e-invoice/' . $templateId . '.php';

        $childTheme = get_stylesheet_directory();
        if ($childTheme && is_readable($childTheme . '/' . $relative)) {
            return $childTheme . '/' . $relative;
        }

        $parentTheme = get_template_directory();
        if ($parentTheme && $parentTheme !== $childTheme
            && is_readable($parentTheme . '/' . $relative)) {
            return $parentTheme . '/' . $relative;
        }

        return null;
    }

    /**
     * Template ids become filenames, so restrict them to a safe charset.
     */
    protected function sanitiseId(string $templateId): string
    {
        $id = strtolower(trim($templateId));
        $id = preg_replace('/[^a-z0-9_\-]/', '', $id);

        return (string) $id;
    }
}