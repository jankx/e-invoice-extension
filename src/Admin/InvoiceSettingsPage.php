<?php
namespace Jankx\Extensions\EInvoice\Admin;

use Jankx\Extensions\EInvoice\Numbering\YearlySequenceNumberGenerator;
use Jankx\Extensions\EInvoice\Profile\InvoiceProfileRegistry;
use Jankx\Extensions\EInvoice\Repository\InvoiceRepositoryInterface;
use Jankx\Extensions\EInvoice\Service\InvoiceDocumentService;

/**
 * The "Hóa đơn điện tử" tab inside the Ecommerce Settings screen.
 *
 * Follows the two-hook pattern base-ecommerce exposes (the same one
 * GGSheetOrders uses): register a tab label, render the form when the tab is
 * active, and register the options under a dedicated option group so saving
 * this tab cannot clobber the other ecommerce settings.
 *
 * @package Jankx\Extensions\EInvoice\Admin
 */
class InvoiceSettingsPage
{
    const TAB_SLUG = 'e-invoice';

    const OPTION_GROUP = 'jankx_ecommerce_einvoice';

    // ── Options ──────────────────────────────────────────────────────────────
    const OPT_ENABLED      = 'jankx_einvoice_enabled';
    const OPT_COUNTRY      = 'jankx_einvoice_country';
    const OPT_TRIGGER      = 'jankx_einvoice_trigger';
    const OPT_RENDERER     = 'jankx_einvoice_renderer';
    const OPT_EMAIL        = 'jankx_einvoice_email_enabled';
    const OPT_SERIES       = 'jankx_einvoice_series';
    const OPT_FORM_SYMBOL  = 'jankx_einvoice_form_symbol';
    const OPT_CQT_CODE     = 'jankx_einvoice_tax_authority_code';

    // Seller identity — these have no base-ecommerce equivalent. mã số thuế in
    // particular is legally mandatory on every Vietnamese invoice and has no
    // sensible default.
    const OPT_SELLER_NAME     = 'jankx_einvoice_seller_name';
    const OPT_SELLER_ADDRESS  = 'jankx_einvoice_seller_address';
    const OPT_SELLER_TAX_CODE = 'jankx_einvoice_seller_tax_code';
    const OPT_SELLER_PHONE    = 'jankx_einvoice_seller_phone';
    const OPT_SELLER_EMAIL    = 'jankx_einvoice_seller_email';
    const OPT_SELLER_LOCATION = 'jankx_einvoice_seller_location';
    const OPT_BANK_NAME       = 'jankx_einvoice_seller_bank_name';
    const OPT_BANK_ACCOUNT    = 'jankx_einvoice_seller_bank_account';

    /** @var InvoiceProfileRegistry */
    protected $profiles;

    /** @var InvoiceDocumentService */
    protected $documents;

    /** @var InvoiceRepositoryInterface */
    protected $repository;

    public function __construct(
        InvoiceProfileRegistry $profiles,
        InvoiceDocumentService $documents,
        InvoiceRepositoryInterface $repository
    ) {
        $this->profiles   = $profiles;
        $this->documents  = $documents;
        $this->repository = $repository;
    }

    public function register(): void
    {
        add_filter('jankx/ecommerce/settings/tabs', [$this, 'registerTab']);
        add_action('jankx/ecommerce/settings/render_tab', [$this, 'renderTab']);
        add_action('admin_init', [$this, 'registerSettings']);
    }

    // ── Hook callbacks ───────────────────────────────────────────────────────

    /**
     * @param array $tabs Existing tabs (slug => label).
     */
    public function registerTab(array $tabs): array
    {
        $tabs[self::TAB_SLUG] = __('Hóa đơn điện tử', 'e-invoice');

        return $tabs;
    }

    public function renderTab(string $currentTab): void
    {
        if ($currentTab !== self::TAB_SLUG) {
            return;
        }

        $this->render();
    }

    public function registerSettings(): void
    {
        $text = ['sanitize_callback' => 'sanitize_text_field'];
        $bool = ['sanitize_callback' => 'rest_sanitize_boolean'];

        register_setting(self::OPTION_GROUP, self::OPT_ENABLED, $bool);
        register_setting(self::OPTION_GROUP, self::OPT_COUNTRY, $text);
        register_setting(self::OPTION_GROUP, self::OPT_TRIGGER, $text);
        register_setting(self::OPTION_GROUP, self::OPT_RENDERER, $text);
        register_setting(self::OPTION_GROUP, self::OPT_EMAIL, $bool);
        register_setting(self::OPTION_GROUP, self::OPT_SERIES, $text);
        register_setting(self::OPTION_GROUP, self::OPT_FORM_SYMBOL, $text);
        register_setting(self::OPTION_GROUP, self::OPT_CQT_CODE, $text);

        foreach ([
            self::OPT_SELLER_NAME,
            self::OPT_SELLER_ADDRESS,
            self::OPT_SELLER_TAX_CODE,
            self::OPT_SELLER_PHONE,
            self::OPT_SELLER_EMAIL,
            self::OPT_SELLER_LOCATION,
            self::OPT_BANK_NAME,
            self::OPT_BANK_ACCOUNT,
        ] as $option) {
            register_setting(self::OPTION_GROUP, $option, $text);
        }
    }

    // ── Render ───────────────────────────────────────────────────────────────

    private function render(): void
    {
        $profile = $this->profiles->resolve();
        $taxCode = (string) get_option(self::OPT_SELLER_TAX_CODE, '');
        ?>
        <h2><?php esc_html_e('Hóa đơn điện tử', 'e-invoice'); ?></h2>
        <p class="description">
            <?php esc_html_e(
                'Tự động xuất hóa đơn khi đơn hàng đạt trạng thái đã chọn, gửi qua email và cho khách xem trong trang tài khoản.',
                'e-invoice'
            ); ?>
        </p>

        <div id="jankx-einvoice-profile-notice" class="notice notice-info inline" style="margin:12px 0">
            <p>
                <strong><?php echo esc_html($profile->getCountryName()); ?></strong>
                <?php if ($profile->getLegalBasis()) : ?>
                    — <?php echo esc_html(implode(' · ', $profile->getLegalBasis())); ?>
                <?php endif; ?>
            </p>
            <?php if ($profile->getId() === 'generic') : ?>
                <p style="margin:4px 0 0">
                    <em>
                        <?php esc_html_e(
                            'Chưa có profile pháp lý riêng cho quốc gia này — hóa đơn được tạo ở dạng chung và CHƯA được kiểm chứng pháp lý. Vui lòng đối chiếu với quy định địa phương trước khi phát hành.',
                            'e-invoice'
                        ); ?>
                    </em>
                </p>
            <?php endif; ?>
        </div>

        <form method="post" action="options.php">
            <?php settings_fields(self::OPTION_GROUP); ?>

            <h3><?php esc_html_e('Kích hoạt', 'e-invoice'); ?></h3>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><?php esc_html_e('Cho phép xuất hóa đơn', 'e-invoice'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="<?php echo esc_attr(self::OPT_ENABLED); ?>" value="1"
                                <?php checked((bool) get_option(self::OPT_ENABLED, true)); ?>>
                            <?php esc_html_e('Tự động cấp hóa đơn khi đơn hàng đạt điều kiện', 'e-invoice'); ?>
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="jankx-einvoice-trigger"><?php esc_html_e('Thời điểm xuất', 'e-invoice'); ?></label></th>
                    <td>
                        <select id="jankx-einvoice-trigger" name="<?php echo esc_attr(self::OPT_TRIGGER); ?>">
                            <option value="on_payment" <?php selected((string) get_option(self::OPT_TRIGGER, 'on_completed'), 'on_payment'); ?>>
                                <?php esc_html_e('Khi thanh toán thành công', 'e-invoice'); ?>
                            </option>
                            <option value="on_shipping" <?php selected((string) get_option(self::OPT_TRIGGER, 'on_completed'), 'on_shipping'); ?>>
                                <?php esc_html_e('Khi chuyển giao hàng', 'e-invoice'); ?>
                            </option>
                            <option value="on_completed" <?php selected((string) get_option(self::OPT_TRIGGER, 'on_completed'), 'on_completed'); ?>>
                                <?php esc_html_e('Khi hoàn thành đơn hàng', 'e-invoice'); ?>
                            </option>
                        </select>
                        <p class="description">
                            <?php esc_html_e(
                                'Lưu ý: theo quy định Việt Nam, hóa đơn thường phải lập tại thời điểm giao hàng. Nếu chọn "hoàn thành", hãy đảm bảo quy trình vận chuyển của bạn gắn với việc hoàn thành đơn.',
                                'e-invoice'
                            ); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Gửi email', 'e-invoice'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="<?php echo esc_attr(self::OPT_EMAIL); ?>" value="1"
                                <?php checked((bool) get_option(self::OPT_EMAIL, true)); ?>>
                            <?php esc_html_e('Gửi hóa đơn tới email khách hàng', 'e-invoice'); ?>
                        </label>
                    </td>
                </tr>
            </table>

            <h3><?php esc_html_e('Quốc gia & định dạng', 'e-invoice'); ?></h3>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="jankx-einvoice-country"><?php esc_html_e('Quốc gia', 'e-invoice'); ?></label></th>
                    <td>
                        <select id="jankx-einvoice-country" name="<?php echo esc_attr(self::OPT_COUNTRY); ?>">
                            <?php foreach ($this->profiles->choices() as $code => $label) : ?>
                                <option value="<?php echo esc_attr($code); ?>"
                                    <?php selected((string) get_option(self::OPT_COUNTRY, ''), $code); ?>>
                                    <?php echo esc_html($label); ?>
                                </option>
                            <?php endforeach; ?>
                            <option value="" <?php selected((string) get_option(self::OPT_COUNTRY, ''), ''); ?>>
                                <?php esc_html_e('— Tự động nhận diện —', 'e-invoice'); ?>
                            </option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="jankx-einvoice-renderer"><?php esc_html_e('Định dạng tài liệu', 'e-invoice'); ?></label></th>
                    <td>
                        <select id="jankx-einvoice-renderer" name="<?php echo esc_attr(self::OPT_RENDERER); ?>">
                            <?php foreach ($this->documents->availableRenderers() as $id => $label) : ?>
                                <option value="<?php echo esc_attr($id); ?>"
                                    <?php selected((string) get_option(self::OPT_RENDERER, 'html'), $id); ?>>
                                    <?php echo esc_html($label); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="jankx-einvoice-series"><?php esc_html_e('Ký hiệu hóa đơn', 'e-invoice'); ?></label></th>
                    <td>
                        <input type="text" id="jankx-einvoice-series" class="regular-text"
                            name="<?php echo esc_attr(self::OPT_SERIES); ?>"
                            value="<?php echo esc_attr((string) get_option(self::OPT_SERIES, 'HD')); ?>">
                        <p class="description">
                            <?php esc_html_e('Dùng làm tiền tố của số hóa đơn, ví dụ HD-2026-000001. Chuỗi số tự đặt lại mỗi năm.', 'e-invoice'); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="jankx-einvoice-form"><?php esc_html_e('Ký hiệu mẫu số', 'e-invoice'); ?></label></th>
                    <td>
                        <input type="text" id="jankx-einvoice-form" class="regular-text"
                            name="<?php echo esc_attr(self::OPT_FORM_SYMBOL); ?>"
                            value="<?php echo esc_attr((string) get_option(self::OPT_FORM_SYMBOL, '01')); ?>">
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="jankx-einvoice-cqt"><?php esc_html_e('Mã của cơ quan thuế', 'e-invoice'); ?></label></th>
                    <td>
                        <input type="text" id="jankx-einvoice-cqt" class="regular-text"
                            name="<?php echo esc_attr(self::OPT_CQT_CODE); ?>"
                            value="<?php echo esc_attr((string) get_option(self::OPT_CQT_CODE, '')); ?>">
                        <p class="description">
                            <?php esc_html_e(
                                'Để trống nếu dùng hóa đơn không có mã của cơ quan thuế. Mã phải được cấp bởi nhà cung cấp hóa đơn điện tử hợp lệ và dữ liệu phải được gửi tới cơ quan thuế — extension này KHÔNG tự thực hiện việc đó.',
                                'e-invoice'
                            ); ?>
                        </p>
                    </td>
                </tr>
            </table>

            <?php if ($taxCode === '') : ?>
                <div class="notice notice-warning inline" style="margin:12px 0">
                    <p>
                        <strong><?php esc_html_e('Chưa có mã số thuế người bán.', 'e-invoice'); ?></strong>
                        <?php esc_html_e('Mã số thuế là trường bắt buộc trên mọi hóa đơn Việt Nam. Hãy điền ở mục Thông tin người bán bên dưới.', 'e-invoice'); ?>
                    </p>
                </div>
            <?php endif; ?>

            <h3><?php esc_html_e('Thông tin người bán', 'e-invoice'); ?></h3>
            <table class="form-table" role="presentation">
                <?php
                $sellerFields = [
                    self::OPT_SELLER_NAME     => __('Tên người bán', 'e-invoice'),
                    self::OPT_SELLER_ADDRESS  => __('Địa chỉ', 'e-invoice'),
                    self::OPT_SELLER_TAX_CODE => __('Mã số thuế', 'e-invoice'),
                    self::OPT_SELLER_PHONE    => __('Điện thoại', 'e-invoice'),
                    self::OPT_SELLER_EMAIL    => __('Email', 'e-invoice'),
                    self::OPT_SELLER_LOCATION => __('Mã, địa chỉ địa điểm kinh doanh', 'e-invoice'),
                    self::OPT_BANK_NAME       => __('Ngân hàng', 'e-invoice'),
                    self::OPT_BANK_ACCOUNT    => __('Số tài khoản', 'e-invoice'),
                ];
                foreach ($sellerFields as $option => $label) :
                    $inputId = 'jankx-einvoice-' . sanitize_key(str_replace('jankx_einvoice_seller_', '', $option));
                    ?>
                    <tr>
                        <th scope="row">
                            <label for="<?php echo esc_attr($inputId); ?>"><?php echo esc_html($label); ?></label>
                        </th>
                        <td>
                            <input type="text" id="<?php echo esc_attr($inputId); ?>" class="regular-text"
                                name="<?php echo esc_attr($option); ?>"
                                value="<?php echo esc_attr((string) get_option($option, '')); ?>">
                            <?php if ($option === self::OPT_SELLER_LOCATION) : ?>
                                <p class="description">
                                    <?php esc_html_e('Bắt buộc với hộ kinh doanh/cá nhân kinh doanh có nhiều địa điểm và người bán xăng dầu.', 'e-invoice'); ?>
                                </p>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </table>

            <?php submit_button(); ?>
        </form>

        <?php $this->renderStats(); ?>
        <?php
    }

    /**
     * A small operational summary below the form.
     */
    private function renderStats(): void
    {
        $total = $this->repository->count([]);
        $emailed = (int) $this->repository->count(['status' => 'issued']);

        $generator = new YearlySequenceNumberGenerator();
        $peek = $generator->peek(
            (string) get_option(self::OPT_SERIES, 'HD'),
            gmdate('Y')
        );
        ?>
        <hr>
        <h3><?php esc_html_e('Tình trạng', 'e-invoice'); ?></h3>
        <table class="widefat striped" style="max-width:640px">
            <tbody>
                <tr>
                    <td><?php esc_html_e('Số hóa đơn đã phát hành', 'e-invoice'); ?></td>
                    <td><strong><?php echo esc_html(number_format_i18n($emailed)); ?></strong></td>
                </tr>
                <tr>
                    <td><?php esc_html_e('Tổng số bản ghi', 'e-invoice'); ?></td>
                    <td><strong><?php echo esc_html(number_format_i18n($total)); ?></strong></td>
                </tr>
                <tr>
                    <td><?php esc_html_e('Số cuối trong dãy năm nay', 'e-invoice'); ?></td>
                    <td><strong><?php echo esc_html(number_format_i18n($peek)); ?></strong></td>
                </tr>
            </tbody>
        </table>
        <?php
    }
}