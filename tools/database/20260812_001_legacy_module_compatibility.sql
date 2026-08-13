-- Compatibility migration for legacy modules used by the current PHP UI.
-- MySQL 5.7+/8.x and MariaDB 10.4+
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS expenses_categories (
    id INT NOT NULL AUTO_INCREMENT,
    category_name_ar VARCHAR(190) NOT NULL,
    category_name_en VARCHAR(190) NULL,
    account_id INT NULL,
    description TEXT NULL,
    status VARCHAR(20) NOT NULL DEFAULT '1',
    created_by INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (id),
    KEY idx_expense_categories_status (status),
    KEY idx_expense_categories_account (account_id),
    KEY idx_expense_categories_deleted (deleted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS expenses (
    id INT NOT NULL AUTO_INCREMENT,
    expense_date DATE NOT NULL,
    category_id INT NOT NULL,
    expense_account_id INT NULL,
    amount DECIMAL(18,4) NOT NULL DEFAULT 0,
    currency_id INT NOT NULL,
    description VARCHAR(500) NULL,
    notes TEXT NULL,
    payment_method VARCHAR(50) NULL,
    paid_from_account_id INT NULL,
    transaction_id INT NULL,
    created_by INT NULL,
    branch_id INT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'draft',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (id),
    KEY idx_expenses_date (expense_date),
    KEY idx_expenses_category (category_id),
    KEY idx_expenses_currency (currency_id),
    KEY idx_expenses_branch (branch_id),
    KEY idx_expenses_status (status),
    KEY idx_expenses_transaction (transaction_id),
    KEY idx_expenses_deleted (deleted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS money_transfers (
    id INT NOT NULL AUTO_INCREMENT,
    transfer_date DATE NOT NULL,
    from_account_id INT NULL,
    to_account_id INT NULL,
    amount DECIMAL(18,4) NOT NULL DEFAULT 0,
    currency_id INT NULL,
    description VARCHAR(500) NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'draft',
    transaction_id INT NULL,
    created_by INT NULL,
    branch_id INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (id),
    KEY idx_money_transfers_date (transfer_date),
    KEY idx_money_transfers_status (status),
    KEY idx_money_transfers_branch (branch_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS financial_transaction_logs (
    id BIGINT NOT NULL AUTO_INCREMENT,
    transaction_id INT NULL,
    user_id INT NULL,
    action VARCHAR(80) NOT NULL,
    old_values LONGTEXT NULL,
    new_values LONGTEXT NULL,
    notes TEXT NULL,
    ip_address VARCHAR(45) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_financial_logs_transaction (transaction_id, created_at),
    KEY idx_financial_logs_user (user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS document_requirements (
    id INT NOT NULL AUTO_INCREMENT,
    workflow_id INT NULL,
    service_type VARCHAR(80) NOT NULL,
    requirement_key VARCHAR(100) NOT NULL,
    requirement_name VARCHAR(255) NOT NULL,
    is_required TINYINT(1) NOT NULL DEFAULT 1,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_document_requirements_service (service_type, is_active),
    KEY idx_document_requirements_workflow (workflow_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS family_individual_attachments (
    id BIGINT NOT NULL AUTO_INCREMENT,
    individual_id INT NOT NULL,
    document_type VARCHAR(100) NOT NULL,
    file_name VARCHAR(255) NULL,
    file_path VARCHAR(1000) NOT NULL,
    mime_type VARCHAR(190) NULL,
    file_size BIGINT NULL,
    uploaded_by INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (id),
    KEY idx_family_attachments_individual (individual_id),
    KEY idx_family_attachments_type (document_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS workflow_checklist (
    id BIGINT NOT NULL AUTO_INCREMENT,
    workflow_id INT NULL,
    entity_type VARCHAR(80) NOT NULL,
    entity_id INT NOT NULL,
    item_key VARCHAR(100) NOT NULL,
    item_label VARCHAR(255) NOT NULL,
    is_required TINYINT(1) NOT NULL DEFAULT 1,
    is_completed TINYINT(1) NOT NULL DEFAULT 0,
    completed_by INT NULL,
    completed_at DATETIME NULL,
    notes TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_workflow_checklist_entity (entity_type, entity_id),
    KEY idx_workflow_checklist_workflow (workflow_id),
    KEY idx_workflow_checklist_completed (is_completed)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS workflow_logs (
    id BIGINT NOT NULL AUTO_INCREMENT,
    workflow_id INT NULL,
    entity_type VARCHAR(80) NOT NULL,
    entity_id INT NOT NULL,
    from_status VARCHAR(80) NULL,
    to_status VARCHAR(80) NULL,
    action VARCHAR(100) NOT NULL,
    notes TEXT NULL,
    performed_by INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_workflow_logs_entity (entity_type, entity_id, created_at),
    KEY idx_workflow_logs_workflow (workflow_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS account_allowed_currencies (
    id INT NOT NULL AUTO_INCREMENT,
    account_id INT NOT NULL,
    currency_id INT NOT NULL,
    created_by INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_account_allowed_currency (account_id, currency_id),
    KEY idx_account_allowed_currency_currency (currency_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS account_balances (
    id INT NOT NULL AUTO_INCREMENT,
    account_id INT NOT NULL,
    currency_id INT NOT NULL,
    current_balance DECIMAL(18,4) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_account_balance_currency (account_id, currency_id),
    KEY idx_account_balances_currency (currency_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS account_limits (
    id INT NOT NULL AUTO_INCREMENT,
    account_id INT NOT NULL,
    currency_id INT NOT NULL,
    max_debit_per_transaction DECIMAL(18,4) NULL,
    max_credit_per_transaction DECIMAL(18,4) NULL,
    max_debit_per_day DECIMAL(18,4) NULL,
    max_credit_per_day DECIMAL(18,4) NULL,
    max_debit_per_month DECIMAL(18,4) NULL,
    max_credit_per_month DECIMAL(18,4) NULL,
    min_balance DECIMAL(18,4) NULL,
    max_balance DECIMAL(18,4) NULL,
    alert_on_exceed TINYINT(1) NOT NULL DEFAULT 1,
    prevent_on_exceed TINYINT(1) NOT NULL DEFAULT 0,
    created_by INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_account_limit_currency (account_id, currency_id),
    KEY idx_account_limits_currency (currency_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS account_limit_alerts (
    id BIGINT NOT NULL AUTO_INCREMENT,
    account_id INT NOT NULL,
    currency_id INT NOT NULL,
    limit_id INT NULL,
    alert_type VARCHAR(80) NOT NULL,
    amount DECIMAL(18,4) NULL,
    limit_amount DECIMAL(18,4) NULL,
    message VARCHAR(500) NULL,
    status ENUM('open','acknowledged','resolved') NOT NULL DEFAULT 'open',
    acknowledged_by INT NULL,
    acknowledged_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_account_limit_alert_lookup (account_id, currency_id, created_at),
    KEY idx_account_limit_alert_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO account_allowed_currencies (account_id, currency_id)
SELECT abu.account_id, abu.currency_id FROM account_balances_unified abu;
INSERT IGNORE INTO account_balances (account_id, currency_id, current_balance)
SELECT abu.account_id, abu.currency_id, abu.current_balance FROM account_balances_unified abu;

CREATE TABLE IF NOT EXISTS journal_entries (
    id BIGINT NOT NULL AUTO_INCREMENT,
    entry_number VARCHAR(80) NULL,
    entry_date DATE NOT NULL,
    description VARCHAR(500) NULL,
    reference_type VARCHAR(80) NULL,
    reference_id INT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'posted',
    created_by INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_journal_entries_date (entry_date),
    KEY idx_journal_entries_reference (reference_type, reference_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS service_transactions (
    id BIGINT NOT NULL AUTO_INCREMENT,
    service_id INT NULL,
    customer_id INT NULL,
    invoice_id INT NULL,
    transaction_id INT NULL,
    quantity DECIMAL(18,4) NOT NULL DEFAULT 1,
    amount DECIMAL(18,4) NOT NULL DEFAULT 0,
    currency_id INT NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'completed',
    created_by INT NULL,
    branch_id INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_service_transactions_service (service_id),
    KEY idx_service_transactions_customer (customer_id),
    KEY idx_service_transactions_date (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
