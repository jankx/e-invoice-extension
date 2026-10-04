<?php
namespace Jankx\Extensions\EInvoice\Admin;

use Jankx\Extensions\EInvoice\Model\Invoice;
use Jankx\Extensions\EInvoice\Repository\InvoiceRepositoryInterface;
use Jankx\Extensions\EInvoice\Service\InvoiceDocumentService;
use Jankx\Extensions\EInvoice\Support\Amount;

/**
 * Admin screen listing issued invoices, with a per-invoice detail view.
 *
 * A bookkeeper hits this at month end to export what they filed, so the columns
 * are the ones that reconcile against a tax return: invoice number, issue date,
 * seller, buyer + tax code, net, tax, total. The tax column is deliberately
 * prominent because it is the figure that must agree with the declaration.
 *
 * @package Jankx\Extensions\EInvoice\Admin
 */
class InvoiceListPage
{
    const PAGE_SLUG = 'jankx-invoices';

    /** @var InvoiceRepositoryInterface */
    protected $repository;

    /** @var InvoiceDocumentService */
    protected $documents;

    public function __construct(InvoiceRepositoryInterface $repository, InvoiceDocumentService $documents)
    {
        $this->repository = $repository;
        $this->documents  = $documents;
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'addMenu']);
    }

    public function addMenu(): void
    {
        add_menu_page(
            __('Hóa đơn điện tử', 'e-invoice'),
            __('Hóa đơn', 'e-invoice'),
            'read',
            self::PAGE_SLUG,
            [$this, 'render'],
            'dashicons-media-spreadsheet',
            57
        );
    }

    public function render(): void
    {
        if (!current_user_can('read')) {
            wp_die(esc_html__('You do not have permission to view invoices.', 'e-invoice'));
        }

        $detailId = isset($_GET['invoice']) ? absint($_GET['invoice']) : 0;

        echo '<div class="wrap">';

        if ($detailId > 0) {
            $this->renderDetail($detailId);
        } else {
            $this->renderList();
        }

        echo '</div>';
    }

    // ── List ─────────────────────────────────────────────────────────────────

    private function renderList(): void
    {
        $page    = isset($_GET['paged']) ? max(1, absint($_GET['paged'])) : 1;
        $perPage = 25;

        $args = [
            'page'     => $page,
            'per_page' => $perPage,
            'orderby'  => 'id',
            'order'    => 'DESC',
        ];

        if (!empty($_GET['s'])) {
            $args['search'] = sanitize_text_field(wp_unslash($_GET['s']));
        }
        if (!empty($_GET['invoice_status'])) {
            $args['status'] = sanitize_key(wp_unslash($_GET['invoice_status']));
        }
        if (!empty($_GET['country'])) {
            $args['country_code'] = sanitize_text_field(wp_unslash($_GET['country']));
        }

        $invoices = $this->repository->query($args);
        $total    = (int) $this->repository->count($args);

        echo '<h1 class="wp-heading-inline">' . esc_html__('Hóa đơn điện tử', 'e-invoice') . '</h1>';
        echo '<hr class="wp-header-end">';

        ?>
        <form method="get" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin:12px 0">
            <input type="hidden" name="page" value="<?php echo esc_attr(self::PAGE_SLUG); ?>">
            <input type="search" name="s" value="<?php echo esc_attr($args['search'] ?? ''); ?>"
                placeholder="<?php esc_attr_e('Số hóa đơn, đơn hàng, email, mã số thuế…', 'e-invoice'); ?>">
            <select name="invoice_status">
                <option value=""><?php esc_html_e('Mọi trạng thái', 'e-invoice'); ?></option>
                <?php foreach ([Invoice::STATUS_ISSUED, Invoice::STATUS_CANCELLED, Invoice::STATUS_ADJUSTED, Invoice::STATUS_REPLACED] as $status) : ?>
                    <option value="<?php echo esc_attr($status); ?>"
                        <?php selected($args['status'] ?? '', $status); ?>>
                        <?php echo esc_html($this->statusLabel($status)); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <?php submit_button(__('Lọc', 'e-invoice'), 'secondary', '', false); ?>
        </form>

        <?php if (!$invoices) : ?>
            <p><?php esc_html_e('Chưa có hóa đơn nào.', 'e-invoice'); ?></p>
        <?php else : ?>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Số hóa đơn', 'e-invoice'); ?></th>
                        <th><?php esc_html_e('Ngày lập', 'e-invoice'); ?></th>
                        <th><?php esc_html_e('Người mua', 'e-invoice'); ?></th>
                        <th><?php esc_html_e('Mã số thuế', 'e-invoice'); ?></th>
                        <th class="right"><?php esc_html_e('Tiền chưa thuế', 'e-invoice'); ?></th>
                        <th class="right"><?php esc_html_e('Thuế', 'e-invoice'); ?></th>
                        <th class="right"><?php esc_html_e('Tổng', 'e-invoice'); ?></th>
                        <th><?php esc_html_e('Trạng thái', 'e-invoice'); ?></th>
                        <th><?php esc_html_e('Email', 'e-invoice'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($invoices as $invoice) : ?>
                        <tr>
                            <td>
                                <strong>
                                    <a href="<?php echo esc_url($this->detailUrl($invoice->getId())); ?>">
                                        <?php echo esc_html($invoice->getDisplayNumber()); ?>
                                    </a>
                                </strong>
                                <br>
                                <small><?php echo esc_html($invoice->getOrderNumber()); ?></small>
                            </td>
                            <td><?php echo esc_html($invoice->getFormattedIssuedAt()); ?></td>
                            <td><?php echo esc_html($invoice->getBuyer()->getName()); ?></td>
                            <td><?php echo esc_html($invoice->getBuyer()->getTaxCode() ?: '—'); ?></td>
                            <td class="right"><?php echo esc_html($this->money($invoice->getTotals()->getNetTotal(), $invoice)); ?></td>
                            <td class="right"><?php echo esc_html($this->money($invoice->getTotals()->getTaxTotal(), $invoice)); ?></td>
                            <td class="right"><strong><?php echo esc_html($this->money($invoice->getTotals()->getPayableTotal(), $invoice)); ?></strong></td>
                            <td>
                                <span class="jankx-einvoice-status jankx-einvoice-status--<?php echo esc_attr($invoice->getStatus()); ?>">
                                    <?php echo esc_html($this->statusLabel($invoice->getStatus())); ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($invoice->wasEmailed()) : ?>
                                    <span title="<?php echo esc_attr($invoice->getEmailedAt()); ?>"><?php esc_html_e('Đã gửi', 'e-invoice'); ?></span>
                                <?php else : ?>
                                    <span style="color:#b32d2e"><?php esc_html_e('Chưa gửi', 'e-invoice'); ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <?php
            $pages = (int) ceil($total / $perPage);
            if ($pages > 1) :
                ?>
                <div class="tablenav"><div class="tablenav-pages">
                    <?php
                    echo wp_kses_post(paginate_links([
                        'base'      => add_query_arg('paged', '%#%'),
                        'format'    => '',
                        'current'   => $page,
                        'total'     => $pages,
                        'prev_text' => '&laquo;',
                        'next_text' => '&raquo;',
                    ]));
                    ?>
                </div></div>
            <?php endif; ?>
        <?php endif; ?>
        <?php
    }

    // ── Detail ───────────────────────────────────────────────────────────────

    private function renderDetail(int $id): void
    {
        $invoice = $this->repository->findById($id);

        if (!$invoice) {
            echo '<h1>' . esc_html__('Hóa đơn', 'e-invoice') . '</h1>';
            echo '<div class="notice notice-error"><p>' . esc_html__('Không tìm thấy hóa đơn.', 'e-invoice') . '</p></div>';
            return;
        }

        $problems = $invoice->validate();

        echo '<p><a href="' . esc_url(admin_url('admin.php?page=' . self::PAGE_SLUG)) . '">&larr; '
            . esc_html__('Quay lại danh sách', 'e-invoice') . '</a></p>';

        echo '<h1>' . esc_html($invoice->getDisplayNumber()) . '</h1>';

        if ($problems) {
            echo '<div class="notice notice-error"><p><strong>'
                . esc_html__('Hóa đơn này không cân bằng:', 'e-invoice') . '</strong></p><ul style="list-style:disc;padding-left:20px">';
            foreach ($problems as $problem) {
                echo '<li>' . esc_html($problem) . '</li>';
            }
            echo '</ul></div>';
        }

        if ($invoice->getCountryCode() === 'VN' && $invoice->getTaxAuthorityCode() === '') {
            echo '<div class="notice notice-warning"><p>'
                . esc_html__(
                    'Hóa đơn này không có mã của cơ quan thuế. Điều này chỉ hợp lệ với hóa đơn không có mã của cơ quan thuế.',
                    'e-invoice'
                )
                . '</p></div>';
        }

        $viewUrl     = rest_url('jankx/e-invoice/v1/invoices/' . $invoice->getId() . '/view');
        $downloadUrl = rest_url('jankx/e-invoice/v1/invoices/' . $invoice->getId() . '/download');

        ?>
        <p class="description"><?php echo esc_html($invoice->getOrderNumber()); ?> · <?php echo esc_html($invoice->getProfileId()); ?></p>
        <p>
            <a class="button button-primary" href="<?php echo esc_url($viewUrl); ?>" target="_blank" rel="noopener">
                <?php esc_html_e('Xem tài liệu', 'e-invoice'); ?>
            </a>
            <a class="button" href="<?php echo esc_url($downloadUrl); ?>">
                <?php esc_html_e('Tải xuống', 'e-invoice'); ?>
            </a>
            <button type="button" class="button" onclick="window.open(window.location.href,'_blank');window.print();">
                <?php esc_html_e('In', 'e-invoice'); ?>
            </button>
        </p>

        <div id="jankx-einvoice-preview" style="background:#eef1f5;padding:16px;border-radius:6px;overflow:auto;height:70vh">
            <iframe src="<?php echo esc_url($viewUrl); ?>" style="width:100%;height:100%;border:0;background:#fff"
                title="<?php esc_attr_e('Xem trước hóa đơn', 'e-invoice'); ?>"></iframe>
        </div>
        <?php
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function detailUrl(int $id): string
    {
        return admin_url('admin.php?page=' . self::PAGE_SLUG . '&invoice=' . $id);
    }

    private function money(float $amount, Invoice $invoice): string
    {
        return Amount::format($amount, $invoice->getCurrency());
    }

    private function statusLabel(string $status): string
    {
        $labels = [
            Invoice::STATUS_ISSUED    => __('Đã phát hành', 'e-invoice'),
            Invoice::STATUS_CANCELLED => __('Đã hủy', 'e-invoice'),
            Invoice::STATUS_ADJUSTED  => __('Điều chỉnh', 'e-invoice'),
            Invoice::STATUS_REPLACED  => __('Thay thế', 'e-invoice'),
        ];

        return $labels[$status] ?? $status;
    }
}