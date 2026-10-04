<?php
namespace Jankx\Extensions\EInvoice\Model;

/**
 * Creates and migrates the invoice tables.
 *
 * Two tables are used rather than one:
 *
 *   jankx_invoices          — the issued documents (one row per invoice)
 *   jankx_invoice_sequences — year-scoped serial counters
 *
 * The counters live in their own table because Vietnamese (and most other
 * regimes) restart invoice numbering per accounting period and per symbol. We
 * allocate from this table atomically rather than using MAX()+1, which is not
 * safe under concurrent checkout traffic.
 *
 * @package Jankx\Extensions\EInvoice\Model
 */
class InvoiceDatabaseInstaller
{
    /**
     * Bump whenever createTables()/migrate() changes.
     *
     * Storing this in an option means the SHOW TABLES round trip happens once
     * per schema version, not on every page load.
     */
    protected const DB_VERSION = '1.0.0';

    protected const VERSION_OPTION = 'jankx_einvoice_db_version';

    public function register(): void
    {
        add_action('init', [$this, 'maybeCreateTables'], 5);
    }

    public static function invoicesTable(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'jankx_invoices';
    }

    public static function sequencesTable(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'jankx_invoice_sequences';
    }

    public function maybeCreateTables(): void
    {
        if (get_option(self::VERSION_OPTION) === self::DB_VERSION) {
            return;
        }

        $invoices  = self::invoicesTable();
        $sequences = self::sequencesTable();

        if ($this->tableExists($invoices) && $this->tableExists($sequences)) {
            $this->migrate($invoices, $sequences);
            update_option(self::VERSION_OPTION, self::DB_VERSION);
            return;
        }

        $this->createTables($invoices, $sequences);
        update_option(self::VERSION_OPTION, self::DB_VERSION);
    }

    protected function tableExists(string $table): bool
    {
        global $wpdb;
        return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
    }

    protected function createTables(string $invoices, string $sequences): void
    {
        global $wpdb;
        $charsetCollate = $wpdb->get_charset_collate();

        $sqlInvoices = "CREATE TABLE {$invoices} (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            invoice_number varchar(50) NOT NULL DEFAULT '',
            invoice_symbol varchar(20) NOT NULL DEFAULT '',
            invoice_form_symbol varchar(20) NOT NULL DEFAULT '',
            order_id bigint(20) UNSIGNED NOT NULL DEFAULT 0,
            order_number varchar(20) NOT NULL DEFAULT '',
            order_invoice_key bigint(20) UNSIGNED DEFAULT NULL,
            country_code varchar(2) NOT NULL DEFAULT '',
            profile_id varchar(50) NOT NULL DEFAULT '',
            currency varchar(10) NOT NULL DEFAULT 'VND',
            status varchar(20) NOT NULL DEFAULT 'issued',
            adjustment_type varchar(20) NOT NULL DEFAULT '',
            original_invoice_id bigint(20) UNSIGNED NOT NULL DEFAULT 0,
            issue_year smallint(6) UNSIGNED NOT NULL DEFAULT 0,
            issue_sequence bigint(20) UNSIGNED NOT NULL DEFAULT 0,
            tax_authority_code varchar(50) NOT NULL DEFAULT '',
            buyer_email varchar(100) NOT NULL DEFAULT '',
            net_total decimal(15,2) NOT NULL DEFAULT 0.00,
            tax_total decimal(15,2) NOT NULL DEFAULT 0.00,
            grand_total decimal(15,2) NOT NULL DEFAULT 0.00,
            pdf_path varchar(255) NOT NULL DEFAULT '',
            issued_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            signed_at datetime DEFAULT NULL,
            emailed_at datetime DEFAULT NULL,
            snapshot longtext NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY invoice_number (invoice_number),
            UNIQUE KEY order_invoice_key (order_invoice_key),
            KEY order_id (order_id),
            KEY buyer_email (buyer_email),
            KEY period (issue_year, invoice_symbol, issue_sequence),
            KEY country_code (country_code),
            KEY status (status),
            KEY issued_at (issued_at)
        ) {$charsetCollate};";

        $sqlSequences = "CREATE TABLE {$sequences} (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            scope varchar(191) NOT NULL,
            current_value bigint(20) UNSIGNED NOT NULL DEFAULT 0,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY scope (scope)
        ) {$charsetCollate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sqlInvoices);
        dbDelta($sqlSequences);
    }

    /**
     * Add columns introduced after a site was first installed.
     */
    protected function migrate(string $invoices, string $sequences): void
    {
        global $wpdb;

        if ($this->tableExists($invoices)) {
            $columns = $wpdb->get_col("DESCRIBE {$invoices}", 0);

            $additions = [
                'order_invoice_key'   => "bigint(20) UNSIGNED DEFAULT NULL AFTER order_number",
                'buyer_email'        => "varchar(100) NOT NULL DEFAULT '' AFTER tax_authority_code",
                'emailed_at'         => 'datetime DEFAULT NULL AFTER signed_at',
                'profile_id'         => "varchar(50) NOT NULL DEFAULT '' AFTER country_code",
                'invoice_form_symbol' => "varchar(20) NOT NULL DEFAULT '' AFTER invoice_symbol",
            ];

            foreach ($additions as $column => $definition) {
                if (!in_array($column, $columns, true)) {
                    $wpdb->query("ALTER TABLE {$invoices} ADD COLUMN {$column} {$definition}");
                }
            }
        }

        if ($this->tableExists($sequences)) {
            $columns = $wpdb->get_col("DESCRIBE {$sequences}", 0);
            if (!in_array('updated_at', $columns, true)) {
                $wpdb->query("ALTER TABLE {$sequences} ADD COLUMN updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER current_value");
            }
        }
    }
}