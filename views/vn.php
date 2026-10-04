<?php
/**
 * Vietnamese invoice document (Hóa đơn).
 *
 * Prints every field NĐ 254/2026 Art. 10(1) requires, in the order the article
 * lists them. Everything printed here comes from the profile's own label
 * methods, so this file stays a layout concern and adding a jurisdiction does
 * not mean copying it.
 *
 * Override by shipping `{theme}/e-invoice/vn.php`.
 *
 * @package Jankx\Extensions\EInvoice
 *
 * @var \Jankx\Extensions\EInvoice\Model\Invoice  $invoice
 * @var \Jankx\Extensions\EInvoice\Contracts\InvoiceProfileInterface $profile
 * @var string $currency
 */

use Jankx\Extensions\EInvoice\Model\Invoice;
use Jankx\Extensions\EInvoice\Support\Amount;

$labels = $profile->getFieldLabels();
$totals = $invoice->getTotals();
$showActions = !empty($GLOBALS['jankx_einvoice_show_actions']);

/**
 * Print one "label: value" row, skipping it when there is nothing to show.
 */
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
                <?php esc_html_e('In / Lưu PDF', 'e-invoice'); ?>
            </button>
        </div>
    <?php endif; ?>

    <?php
    // ── (a) Tên hóa đơn, (b) ký hiệu, ký hiệu mẫu số, số hóa đơn ──────────
    ?>
    <header class="je-head">
        <div>
            <h1 class="je-title"><?php echo esc_html($labels['invoice_title'] ?? 'HÓA ĐƠN'); ?></h1>
            <p class="je-title-sub">
                <?php
                printf(
                    /* translators: %s: ngày lập hóa đơn */
                    esc_html__('Ngày lập: %s', 'e-invoice'),
                    esc_html($invoice->getFormattedIssuedAt())
                );
                ?>
            </p>
        </div>
        <dl class="je-number">
            <div>
                <dt><?php echo esc_html($labels['invoice_form_symbol'] ?? 'Ký hiệu mẫu số'); ?></dt>
                <dd><?php echo esc_html($invoice->getInvoiceFormSymbol()); ?></dd>
            </div>
            <div>
                <dt><?php echo esc_html($labels['invoice_symbol'] ?? 'Ký hiệu'); ?></dt>
                <dd><?php echo esc_html($invoice->getInvoiceSymbol()); ?></dd>
            </div>
            <div>
                <dt><?php echo esc_html($labels['invoice_number'] ?? 'Số hóa đơn'); ?></dt>
                <dd><?php echo esc_html($invoice->getInvoiceNumber()); ?></dd>
            </div>
        </dl>
    </header>

    <?php
    // ── (c) Người bán   ·   (d) Người mua ───────────────────────────────────
    ?>
    <section class="je-parties">
        <div class="je-party">
            <p class="je-party-label"><?php echo esc_html($labels['seller'] ?? 'Người bán'); ?></p>
            <p class="je-party-name"><?php echo esc_html($invoice->getSeller()->getName()); ?></p>
            <dl class="je-party-lines">
                <?php
                $fact(__('Địa chỉ', 'e-invoice'), $invoice->getSeller()->getAddress());
                $fact(__('Mã số thuế', 'e-invoice'), $invoice->getSeller()->getTaxCode());
                $fact(__('Điện thoại', 'e-invoice'), $invoice->getSeller()->getPhone());
                $fact(__('Email', 'e-invoice'), $invoice->getSeller()->getEmail());

                // Extra jurisdiction-specific slots (mã, địa chỉ địa điểm
                // kinh doanh for fuel sellers and multi-premises hộ kinh doanh).
                foreach ($profile->resolveExtraPartyValues($invoice) as $key => $value) {
                    $fact($profile->getExtraPartyFields()[$key] ?? $key, $value);
                }
                ?>
            </dl>
        </div>

        <div class="je-party">
            <p class="je-party-label"><?php echo esc_html($labels['buyer'] ?? 'Người mua'); ?></p>
            <p class="je-party-name"><?php echo esc_html($invoice->getBuyer()->getName()); ?></p>
            <dl class="je-party-lines">
                <?php
                $fact(__('Địa chỉ', 'e-invoice'), $invoice->getBuyer()->getAddress());
                $fact(__('Mã số thuế', 'e-invoice'), $invoice->getBuyer()->getTaxCode());
                $fact(__('Số định danh cá nhân', 'e-invoice'), $invoice->getBuyer()->getPersonalId());
                $fact(__('Điện thoại', 'e-invoice'), $invoice->getBuyer()->getPhone());
                $fact(__('Email', 'e-invoice'), $invoice->getBuyer()->getEmail());
                ?>
            </dl>
        </div>
    </section>

    <?php
    // ── (đ) Danh mục hàng hóa, dịch vụ ─────────────────────────────────────
    ?>
    <table class="je-table">
        <thead>
            <tr>
                <th style="width:28px">#</th>
                <th><?php echo esc_html($profile->getLineTableHeading()); ?></th>
                <th class="je-center" style="width:70px"><?php echo esc_html($profile->getUnitLabel()); ?></th>
                <th class="je-num" style="width:56px"><?php esc_html_e('SL', 'e-invoice'); ?></th>
                <th class="je-num" style="width:96px"><?php esc_html_e('Đơn giá', 'e-invoice'); ?></th>
                <th class="je-num" style="width:110px"><?php esc_html_e('Thành tiền', 'e-invoice'); ?></th>
                <th class="je-center" style="width:64px"><?php esc_html_e('Thuế suất', 'e-invoice'); ?></th>
                <th class="je-num" style="width:100px"><?php esc_html_e('Tiền thuế', 'e-invoice'); ?></th>
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
                    <td class="je-center"><?php echo esc_html($line->getTaxRateLabel()); ?></td>
                    <td class="je-num"><?php echo esc_html(Amount::formatPlain($line->getTaxAmount(), $currency)); ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <?php
    // ── (đ) Tổng cộng · (k) Phí, chiết khấu ────────────────────────────────
    ?>
    <div class="je-summary">
        <div class="je-summary-inner">
            <table>
                <tr>
                    <th><?php esc_html_e('Tổng cộng', 'e-invoice'); ?></th>
                    <td><?php echo esc_html(Amount::formatPlain($totals->getGrossSubtotal(), $currency)); ?></td>
                </tr>
                <?php if ($totals->getDiscountTotal() > 0) : ?>
                    <tr>
                        <th><?php esc_html_e('Chiết khấu', 'e-invoice'); ?></th>
                        <td>−<?php echo esc_html(Amount::formatPlain($totals->getDiscountTotal(), $currency)); ?></td>
                    </tr>
                <?php endif; ?>
                <tr class="je-rule">
                    <th><?php esc_html_e('Tổng thành tiền chưa có thuế', 'e-invoice'); ?></th>
                    <td><?php echo esc_html(Amount::formatPlain($totals->getNetTotal(), $currency)); ?></td>
                </tr>
                <tr>
                    <th><?php esc_html_e('Tổng cộng tiền thuế', 'e-invoice'); ?></th>
                    <td><?php echo esc_html(Amount::formatPlain($totals->getTaxTotal(), $currency)); ?></td>
                </tr>
                <?php if ($totals->getFeeTotal() > 0) : ?>
                    <tr>
                        <th><?php esc_html_e('Phí, lệ phí', 'e-invoice'); ?></th>
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

    <?php
    // ── (đ) Tổng số tiền thuế theo từng loại thuế suất ─────────────────────
    ?>
    <?php if ($invoice->getTaxLines()) : ?>
        <section class="je-tax">
            <h3><?php echo esc_html($profile->getTaxSummaryHeading()); ?></h3>
            <table>
                <tr>
                    <th><?php esc_html_e('Thuế suất', 'e-invoice'); ?></th>
                    <th><?php esc_html_e('Tổng cộng tiền chưa có thuế', 'e-invoice'); ?></th>
                    <th><?php esc_html_e('Tiền thuế', 'e-invoice'); ?></th>
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

    <?php
    // ── (đ) Tổng số tiền bằng chữ ───────────────────────────────────────────
    ?>
    <?php if ($invoice->getAmountInWords() !== '') : ?>
        <div class="je-words">
            <p class="je-words-label">
                <?php echo esc_html($labels['amount_in_words'] ?? 'Tổng số tiền bằng chữ'); ?>:
            </p>
            <p class="je-words-value"><?php echo esc_html($invoice->getAmountInWords()); ?></p>
        </div>
    <?php endif; ?>

    <?php
    // ── (i) Mã của cơ quan thuế ────────────────────────────────────────────
    // Blank means the document is a HĐĐT without an authority code, which is
    // only legitimate for certain cases — the admin is warned separately.
    ?>
    <?php if ($invoice->getTaxAuthorityCode() !== '') : ?>
        <p class="je-foot">
            <?php echo esc_html($profile->getTaxAuthorityCodeLabel()); ?>:
            <strong><?php echo esc_html($invoice->getTaxAuthorityCode()); ?></strong>
        </p>
    <?php endif; ?>

    <?php
    // ── (e) Chữ ký người bán ───────────────────────────────────────────────
    ?>
    <?php if ($profile->requiresSellerSignature()) : ?>
        <section class="je-sign">
            <div class="je-sign-inner">
                <div class="je-sign-role"><?php esc_html_e('Người bán', 'e-invoice'); ?></div>
                <div class="je-sign-note"><?php esc_html_e('Chữ ký, đóng dấu', 'e-invoice'); ?></div>
                <div class="je-sign-space"></div>
                <div class="je-sign-name">
                    <?php echo esc_html($invoice->getSeller()->getName()); ?>
                </div>
            </div>
        </section>
    <?php endif; ?>

    <p class="je-foot">
        <?php
        printf(
            /* translators: 1: số hóa đơn, 2: mã đơn hàng */
            esc_html__('Hóa đơn %1$s · Đơn hàng %2$s', 'e-invoice'),
            esc_html($invoice->getInvoiceNumber()),
            esc_html($invoice->getOrderNumber())
        );
        ?>
    </p>
</div>