<?php
/**
 * Generic invoice document.
 *
 * The neutral fallback for jurisdictions without a dedicated profile. It prints
 * the structural essentials every regime shares and omits the local specifics —
 * no amount in words, no per-rate VAT grouping — because guessing them would be
 * worse than leaving them out.
 *
 * The `je-*` class names are shared with the Vietnam template so a theme's
 * stylesheet covers both.
 *
 * Override by shipping `{theme}/e-invoice/generic.php`.
 *
 * @package Jankx\Extensions\EInvoice
 *
 * @var \Jankx\Extensions\EInvoice\Model\Invoice  $invoice
 * @var \Jankx\Extensions\EInvoice\Contracts\InvoiceProfileInterface $profile
 * @var string $currency
 */

use Jankx\Extensions\EInvoice\Support\Amount;

$labels = $profile->getFieldLabels();
$totals = $invoice->getTotals();
$showActions = !empty($GLOBALS['jankx_einvoice_show_actions']);

$fact = static function ($label, $value) {
    if ((string) $value === '') {
        return;
    }
    printf(
        '<div><dt>%s</dt><dd>%s</dd></div>',
        esc_html($label),
        esc_html($value)
    );
};
?>
<div class="je-sheet">

    <?php if ($showActions) : ?>
        <div class="je-actions">
            <button type="button" class="je-btn" onclick="window.print()">
                <?php esc_html_e('Print / Save as PDF', 'e-invoice'); ?>
            </button>
        </div>
    <?php endif; ?>

    <header class="je-head">
        <div>
            <h1 class="je-title"><?php echo esc_html($labels['invoice_title'] ?? 'INVOICE'); ?></h1>
            <p class="je-title-sub">
                <?php
                printf(
                    /* translators: %s: issue date */
                    esc_html__('Issue date: %s', 'e-invoice'),
                    esc_html($invoice->getFormattedIssuedAt())
                );
                ?>
            </p>
        </div>
        <dl class="je-number">
            <div>
                <dt><?php echo esc_html($labels['invoice_symbol'] ?? 'Series'); ?></dt>
                <dd><?php echo esc_html($invoice->getInvoiceSymbol()); ?></dd>
            </div>
            <div>
                <dt><?php echo esc_html($labels['invoice_number'] ?? 'Invoice no.'); ?></dt>
                <dd><?php echo esc_html($invoice->getInvoiceNumber()); ?></dd>
            </div>
        </dl>
    </header>

    <section class="je-parties">
        <div class="je-party">
            <p class="je-party-label"><?php echo esc_html($labels['seller'] ?? 'Seller'); ?></p>
            <p class="je-party-name"><?php echo esc_html($invoice->getSeller()->getName()); ?></p>
            <dl class="je-party-lines">
                <?php
                $fact(__('Address', 'e-invoice'), $invoice->getSeller()->getAddress());
                $fact(__('Tax ID', 'e-invoice'), $invoice->getSeller()->getTaxCode());
                $fact(__('Phone', 'e-invoice'), $invoice->getSeller()->getPhone());
                $fact(__('Email', 'e-invoice'), $invoice->getSeller()->getEmail());
                ?>
            </dl>
        </div>

        <div class="je-party">
            <p class="je-party-label"><?php echo esc_html($labels['buyer'] ?? 'Buyer'); ?></p>
            <p class="je-party-name"><?php echo esc_html($invoice->getBuyer()->getName()); ?></p>
            <dl class="je-party-lines">
                <?php
                $fact(__('Address', 'e-invoice'), $invoice->getBuyer()->getAddress());
                $fact(__('Tax ID', 'e-invoice'), $invoice->getBuyer()->getTaxCode());
                $fact(__('Phone', 'e-invoice'), $invoice->getBuyer()->getPhone());
                $fact(__('Email', 'e-invoice'), $invoice->getBuyer()->getEmail());
                ?>
            </dl>
        </div>
    </section>

    <table class="je-table">
        <thead>
            <tr>
                <th style="width:28px">#</th>
                <th><?php echo esc_html($profile->getLineTableHeading()); ?></th>
                <th class="je-center" style="width:70px"><?php echo esc_html($profile->getUnitLabel()); ?></th>
                <th class="je-num" style="width:56px"><?php esc_html_e('Qty', 'e-invoice'); ?></th>
                <th class="je-num" style="width:96px"><?php esc_html_e('Unit price', 'e-invoice'); ?></th>
                <th class="je-num" style="width:110px"><?php esc_html_e('Amount', 'e-invoice'); ?></th>
                <?php if ($profile->requiresTaxBreakdownByRate()) : ?>
                    <th class="je-center" style="width:64px"><?php esc_html_e('Tax', 'e-invoice'); ?></th>
                    <th class="je-num" style="width:100px"><?php esc_html_e('Tax amount', 'e-invoice'); ?></th>
                <?php endif; ?>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($invoice->getLines() as $i => $line) : ?>
                <tr>
                    <td class="je-index"><?php echo esc_html((string) ($i + 1)); ?></td>
                    <td>
                        <?php echo esc_html($line->getDescription()); ?>
                        <?php if ($line->getProductCode() !== '') : ?>
                            <br><small><?php echo esc_html($line->getProductCode()); ?></small>
                        <?php endif; ?>
                    </td>
                    <td class="je-center"><?php echo esc_html($line->getUnit()); ?></td>
                    <td class="je-num"><?php echo esc_html(Amount::formatPlain($line->getQuantity(), $currency)); ?></td>
                    <td class="je-num"><?php echo esc_html(Amount::formatPlain($line->getUnitPrice(), $currency)); ?></td>
                    <td class="je-num"><?php echo esc_html(Amount::formatPlain($line->getNetAmount(), $currency)); ?></td>
                    <?php if ($profile->requiresTaxBreakdownByRate()) : ?>
                        <td class="je-center"><?php echo esc_html($line->getTaxRateLabel()); ?></td>
                        <td class="je-num"><?php echo esc_html(Amount::formatPlain($line->getTaxAmount(), $currency)); ?></td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <div class="je-summary">
        <div class="je-summary-inner">
            <table>
                <tr>
                    <th><?php esc_html_e('Subtotal', 'e-invoice'); ?></th>
                    <td><?php echo esc_html(Amount::formatPlain($totals->getGrossSubtotal(), $currency)); ?></td>
                </tr>
                <?php if ($totals->getDiscountTotal() > 0) : ?>
                    <tr>
                        <th><?php esc_html_e('Discount', 'e-invoice'); ?></th>
                        <td>−<?php echo esc_html(Amount::formatPlain($totals->getDiscountTotal(), $currency)); ?></td>
                    </tr>
                <?php endif; ?>
                <tr class="je-rule">
                    <th><?php esc_html_e('Net total', 'e-invoice'); ?></th>
                    <td><?php echo esc_html(Amount::formatPlain($totals->getNetTotal(), $currency)); ?></td>
                </tr>
                <tr>
                    <th><?php esc_html_e('Tax', 'e-invoice'); ?></th>
                    <td><?php echo esc_html(Amount::formatPlain($totals->getTaxTotal(), $currency)); ?></td>
                </tr>
                <?php if ($totals->getFeeTotal() > 0) : ?>
                    <tr>
                        <th><?php esc_html_e('Fees', 'e-invoice'); ?></th>
                        <td><?php echo esc_html(Amount::formatPlain($totals->getFeeTotal(), $currency)); ?></td>
                    </tr>
                <?php endif; ?>
                <tr class="je-total">
                    <th><?php echo esc_html($profile->getGrandTotalLabel()); ?></th>
                    <td><?php echo esc_html(Amount::formatPlain($totals->getPayableTotal(), $currency)); ?></td>
                </tr>
            </table>
        </div>
    </div>

    <?php if ($invoice->getTaxLines() && $profile->requiresTaxBreakdownByRate()) : ?>
        <section class="je-tax">
            <h3><?php echo esc_html($profile->getTaxSummaryHeading()); ?></h3>
            <table>
                <tr>
                    <th><?php esc_html_e('Rate', 'e-invoice'); ?></th>
                    <th><?php esc_html_e('Taxable amount', 'e-invoice'); ?></th>
                    <th><?php esc_html_e('Tax', 'e-invoice'); ?></th>
                </tr>
                <?php foreach ($invoice->getTaxLines() as $taxLine) : ?>
                    <tr>
                        <td><?php echo esc_html($taxLine->getRateLabel()); ?></td>
                        <td><?php echo esc_html(Amount::formatPlain($taxLine->getTaxableAmount(), $currency)); ?></td>
                        <td><?php echo esc_html(Amount::formatPlain($taxLine->getTaxAmount(), $currency)); ?></td>
                    </tr>
                <?php endforeach; ?>
            </table>
        </section>
    <?php endif; ?>

    <?php if ($invoice->getAmountInWords() !== '') : ?>
        <div class="je-words">
            <p class="je-words-label">
                <?php echo esc_html($labels['amount_in_words'] ?? 'Amount in words'); ?>:
            </p>
            <p class="je-words-value"><?php echo esc_html($invoice->getAmountInWords()); ?></p>
        </div>
    <?php endif; ?>

    <?php if ($invoice->getTaxAuthorityCode() !== '') : ?>
        <p class="je-foot">
            <?php echo esc_html($profile->getTaxAuthorityCodeLabel()); ?>:
            <strong><?php echo esc_html($invoice->getTaxAuthorityCode()); ?></strong>
        </p>
    <?php endif; ?>

    <?php if ($profile->requiresSellerSignature()) : ?>
        <section class="je-sign">
            <div class="je-sign-inner">
                <div class="je-sign-role"><?php echo esc_html($labels['seller'] ?? 'Seller'); ?></div>
                <div class="je-sign-space"></div>
                <div class="je-sign-name"><?php echo esc_html($invoice->getSeller()->getName()); ?></div>
            </div>
        </section>
    <?php endif; ?>

    <p class="je-foot">
        <?php
        printf(
            /* translators: 1: invoice number, 2: order number */
            esc_html__('Invoice %1$s · Order %2$s', 'e-invoice'),
            esc_html($invoice->getInvoiceNumber()),
            esc_html($invoice->getOrderNumber())
        );
        ?>
    </p>
</div>