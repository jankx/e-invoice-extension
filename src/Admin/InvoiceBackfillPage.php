<?php
namespace Jankx\Extensions\EInvoice\Admin;

use Jankx\Extensions\Ecommerce\Order\Order;
use Jankx\Extensions\Ecommerce\Order\OrderModel;
use Jankx\Extensions\EInvoice\Repository\InvoiceRepositoryInterface;
use Jankx\Extensions\EInvoice\Service\InvoiceIssuanceService;

/**
 * Bulk issuance for orders that never triggered automatically.
 *
 * The typical case: the extension was installed after some orders had already
 * been paid and completed, so no `order/status_changed` event ever fired for
 * them. This screen lists orders that are far enough along for the configured
 * trigger but have no invoice yet, and issues them on request.
 *
 * It is deliberately a *list with checkboxes*, not a "backfill everything"
 * button. Issuing an invoice is a legal act — it commits a number from a
 * sequence and can trigger email — so the merchant picks the orders.
 *
 * @package Jankx\Extensions\EInvoice\Admin
 */
class InvoiceBackfillPage
{
    const PAGE_SLUG = 'jankx-invoice-backfill';

    const NONCE = 'jankx_einvoice_backfill';

    const PER_PAGE = 50;

    /** @var InvoiceIssuanceService */
    protected $issuance;

    /** @var InvoiceRepositoryInterface */
    protected $repository;

    public function __construct(
        InvoiceIssuanceService $issuance,
        InvoiceRepositoryInterface $repository
    ) {
        $this->issuance   = $issuance;
        $this->repository = $repository;
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'addMenu'], 20);
    }

    public function addMenu(): void
    {
        add_submenu_page(
            InvoiceListPage::PAGE_SLUG,
            __('Cấp hóa đơn cho đơn cũ', 'e-invoice'),
            __('Cấp hóa đơn cũ', 'e-invoice'),
            'manage_woocommerce',
            self::PAGE_SLUG,
            [$this, 'render']
        );
    }

    public function render(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You do not have permission to issue invoices.', 'e-invoice'));
        }

        $result = $this->handleSubmit();

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Cấp hóa đơn cho đơn cũ', 'e-invoice') . '</h1>';

        $this->renderTriggerNotice();

        if ($result !== null) {
            $this->renderResult($result);
        }

        $this->renderList();

        echo '</div>';
    }

    // ── Submit ───────────────────────────────────────────────────────────────

    /**
     * @return array{issued:int,skipped:int,refused:int,ids:int[]}|null
     */
    protected function handleSubmit(): ?array
    {
        if (empty($_POST['jankx_einvoice_backfill_submit'])) {
            return null;
        }

        if (!check_admin_referer(self::NONCE)) {
            wp_die(esc_html__('Yêu cầu không hợp lệ. Hãy thử lại.', 'e-invoice'));
        }

        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Bạn không có quyền cấp hóa đơn.', 'e-invoice'));
        }

        $ids = isset($_POST['orders']) ? array_map('absint', (array) $_POST['orders']) : [];
        $ids = array_values(array_filter($ids));

        $result = ['issued' => 0, 'skipped' => 0, 'refused' => 0, 'ids' => []];

        foreach ($ids as $orderId) {
            $order = new Order($orderId);
            if (!$order->getId()) {
                continue;
            }

            $invoice = $this->issuance->issue($order);

            if ($invoice === false) {
                $result['refused']++;
                continue;
            }

            // issue() returns the pre-existing invoice when one is already there,
            // so only count genuine new rows.
            if ($invoice->getOrderId() && !in_array($invoice->getId(), $result['ids'], true)) {
                $result['ids'][] = $invoice->getId();
            }

            $result['issued']++;
        }

        return $result;
    }

    protected function renderResult(array $result): void
    {
        printf(
            '<div class="notice notice-info"><p>%s</p></div>',
            esc_html(sprintf(
                /* translators: 1: issued, 2: refused */
                __('Đã xử lý %1$d đơn hàng. Cấp thành công: %2$d.', 'e-invoice'),
                $result['issued'],
                $result['issued'] - $result['refused']
            ))
        );

        if ($result['refused'] > 0) {
            printf(
                '<div class="notice notice-warning"><p>%s</p></div>',
                esc_html(sprintf(
                    /* translators: %s: number of orders */
                    __('%s đơn hàng không được cấp hóa đơn — thường là do dữ liệu đơn không cân bằng hoặc thiếu thông tin người bán. Xem WP_DEBUG log để biết chi tiết.', 'e-invoice'),
                    number_format_i18n($result['refused'])
                ))
            );
        }
    }

    protected function renderTriggerNotice(): void
    {
        $trigger = (string) get_option('jankx_einvoice_trigger', 'on_completed');
        $labels = [
            'on_payment'   => __('khi thanh toán thành công', 'e-invoice'),
            'on_shipping'  => __('khi chuyển giao hàng', 'e-invoice'),
            'on_completed' => __('khi hoàn thành đơn hàng', 'e-invoice'),
        ];

        printf(
            '<p class="description">%s</p>',
            esc_html(sprintf(
                /* translators: %s: human trigger description */
                __('Danh sách dưới đây gồm các đơn đã đạt điều kiện cấp hóa đơn theo thiết lập hiện tại (%s) nhưng chưa có hóa đơn nào.', 'e-invoice'),
                $labels[$trigger] ?? $trigger
            ))
        );
    }

    // ── List ─────────────────────────────────────────────────────────────────

    protected function renderList(): void
    {
        $page  = isset($_GET['paged']) ? max(1, absint($_GET['paged'])) : 1;
        $candidates = $this->candidates($page, self::PER_PAGE);
        $total      = $this->countCandidates();

        if (!$candidates) {
            printf(
                '<div class="notice notice-success"><p>%s</p></div>',
                esc_html__('Không còn đơn hàng nào cần cấp hóa đơn.', 'e-invoice')
            );

            return;
        }

        ?>
        <form method="post">
            <?php wp_nonce_field(self::NONCE); ?>
            <p>
                <button type="submit" name="jankx_einvoice_backfill_submit" value="1" class="button button-primary">
                    <?php esc_html_e('Cấp hóa đơn cho các đơn đã chọn', 'e-invoice'); ?>
                </button>
            </p>

            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <td class="check-column"><input type="checkbox" id="jankx-einvoice-check-all"></td>
                        <th><?php esc_html_e('Mã đơn', 'e-invoice'); ?></th>
                        <th><?php esc_html_e('Ngày đặt', 'e-invoice'); ?></th>
                        <th><?php esc_html_e('Khách hàng', 'e-invoice'); ?></th>
                        <th><?php esc_html_e('Trạng thái', 'e-invoice'); ?></th>
                        <th class="right"><?php esc_html_e('Tổng', 'e-invoice'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($candidates as $row) : ?>
                        <tr>
                            <th class="check-column">
                                <input type="checkbox" name="orders[]" value="<?php echo esc_attr((string) $row['id']); ?>">
                            </th>
                            <td>
                                <a href="<?php echo esc_url(admin_url('admin.php?page=jankx-orders&view=' . $row['id'])); ?>">
                                    <?php echo esc_html($row['order_number'] ?: ('#' . $row['id'])); ?>
                                </a>
                            </td>
                            <td><?php echo esc_html($this->formatDate($row['created_at'] ?? '')); ?></td>
                            <td>
                                <?php echo esc_html($row['customer_name'] ?: $row['customer_email']); ?>
                            </td>
                            <td><?php echo esc_html(Order::getStatusLabel((string) $row['status'])); ?></td>
                            <td class="right"><?php echo esc_html(number_format_i18n((float) ($row['total'] ?? 0))); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </form>

        <script>
        (function () {
            var all = document.getElementById('jankx-einvoice-check-all');
            if (!all) return;
            all.addEventListener('change', function () {
                document.querySelectorAll('input[name="orders[]"]').forEach(function (box) {
                    box.checked = all.checked;
                });
            });
        })();
        </script>

        <?php
        $pages = (int) ceil($total / self::PER_PAGE);
        if ($pages > 1) {
            echo '<div class="tablenav"><div class="tablenav-pages">';
            echo wp_kses_post(paginate_links([
                'base'      => add_query_arg('paged', '%#%'),
                'current'   => $page,
                'total'     => $pages,
                'prev_text' => '&laquo;',
                'next_text' => '&raquo;',
            ]));
            echo '</div></div>';
        }
    }

    /**
     * Orders that have reached the configured trigger but have no invoice.
     *
     * @return array[]
     */
    protected function candidates(int $page, int $perPage): array
    {
        $rank = $this->triggerRank();

        if ($rank === 0) {
            return [];
        }

        $statuses = $this->statusesAtOrBeyond($rank);

        if (!$statuses) {
            return [];
        }

        // Over-fetch, then drop the ones that already have an invoice, so a page
        // is not left half-empty just because earlier rows are already invoiced.
        $rows = OrderModel::query([
            'status'   => $statuses,
            'orderby'  => 'id',
            'order'    => 'ASC',
            'per_page' => $perPage * 4,
            'page'     => 1,
        ]);

        $out = [];
        foreach ($rows as $row) {
            if ($this->repository->findByOrderId((int) $row['id'])) {
                continue;
            }

            $out[] = $row;

            if (count($out) >= $perPage) {
                break;
            }
        }

        return $out;
    }

    protected function countCandidates(): int
    {
        $rank = $this->triggerRank();
        if ($rank === 0) {
            return 0;
        }

        $statuses = $this->statusesAtOrBeyond($rank);
        if (!$statuses) {
            return 0;
        }

        $total = OrderModel::count(['status' => $statuses]);
        if ($total <= 0) {
            return 0;
        }

        // Count invoices that fall inside this order set. Cheap approximation:
        // if the shop has issued few invoices, subtracting all of them from the
        // order total is close enough for a "how many are left" hint.
        $issued = 0;
        foreach (OrderModel::query([
            'status'   => $statuses,
            'per_page' => 200,
            'page'     => 1,
        ]) as $row) {
            if ($this->repository->findByOrderId((int) $row['id'])) {
                $issued++;
            }
        }

        return max(0, $total - $issued);
    }

    /**
     * Rank of the configured trigger: payment=1, shipping=2, completed=3.
     */
    protected function triggerRank(): int
    {
        switch ((string) get_option('jankx_einvoice_trigger', 'on_completed')) {
            case 'on_payment':
                return 1;
            case 'on_shipping':
                return 2;
            case 'on_completed':
                return 3;
            default:
                return 0;
        }
    }

    /**
     * @return string[] Statuses at or past the trigger point.
     */
    protected function statusesAtOrBeyond(int $rank): array
    {
        if ($rank <= 1) {
            return [Order::STATUS_PROCESSING, Order::STATUS_SHIPPING, Order::STATUS_COMPLETED];
        }

        if ($rank === 2) {
            return [Order::STATUS_SHIPPING, Order::STATUS_COMPLETED];
        }

        return [Order::STATUS_COMPLETED];
    }

    protected function formatDate(string $mysqlDate): string
    {
        if (!$mysqlDate) {
            return '—';
        }

        $timestamp = strtotime($mysqlDate);
        if (!$timestamp) {
            return $mysqlDate;
        }

        return date_i18n(get_option('date_format', 'd/m/Y'), $timestamp);
    }
}