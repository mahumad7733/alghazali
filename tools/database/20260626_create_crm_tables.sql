-- Alghazali CRM schema
-- MySQL 5.7+/8.x and MariaDB 10.4+
-- Safe to run repeatedly: all objects use IF NOT EXISTS.
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS crm_settings (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    setting_key VARCHAR(100) NOT NULL,
    setting_value TEXT NULL,
    setting_group VARCHAR(80) NOT NULL DEFAULT 'general',
    is_encrypted TINYINT(1) NOT NULL DEFAULT 0,
    updated_by INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_crm_settings_key (setting_key),
    KEY idx_crm_settings_group (setting_group)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS crm_companies (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(190) NOT NULL,
    legal_name VARCHAR(190) NULL,
    industry VARCHAR(120) NULL,
    website VARCHAR(255) NULL,
    email VARCHAR(190) NULL,
    phone VARCHAR(60) NULL,
    whatsapp_number VARCHAR(60) NULL,
    address TEXT NULL,
    city VARCHAR(100) NULL,
    country VARCHAR(100) NULL,
    tax_number VARCHAR(100) NULL,
    notes TEXT NULL,
    assigned_to INT NULL,
    created_by INT NULL,
    updated_by INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (id),
    KEY idx_crm_companies_name (name),
    KEY idx_crm_companies_assigned (assigned_to),
    KEY idx_crm_companies_deleted (deleted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS crm_contacts (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id INT UNSIGNED NULL,
    first_name VARCHAR(100) NOT NULL,
    last_name VARCHAR(100) NULL,
    display_name VARCHAR(210) NULL,
    whatsapp_number VARCHAR(60) NOT NULL,
    phone VARCHAR(60) NULL,
    email VARCHAR(190) NULL,
    job_title VARCHAR(150) NULL,
    address TEXT NULL,
    city VARCHAR(100) NULL,
    country VARCHAR(100) NULL,
    source VARCHAR(100) NULL,
    stage ENUM('lead','prospect','customer','lost') NOT NULL DEFAULT 'lead',
    status ENUM('active','inactive','blocked') NOT NULL DEFAULT 'active',
    assigned_to INT NULL,
    created_by INT NULL,
    updated_by INT NULL,
    last_contacted_at DATETIME NULL,
    metadata LONGTEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (id),
    KEY idx_crm_contacts_company (company_id),
    KEY idx_crm_contacts_phone (phone),
    KEY idx_crm_contacts_whatsapp (whatsapp_number),
    KEY idx_crm_contacts_email (email),
    KEY idx_crm_contacts_stage (stage),
    KEY idx_crm_contacts_assigned (assigned_to),
    KEY idx_crm_contacts_deleted (deleted_at),
    KEY idx_crm_contacts_name (first_name, last_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS crm_pipeline_stages (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(120) NOT NULL,
    description VARCHAR(255) NULL,
    color VARCHAR(20) NOT NULL DEFAULT '#6c757d',
    sort_order INT NOT NULL DEFAULT 0,
    probability DECIMAL(5,2) NOT NULL DEFAULT 0,
    is_won TINYINT(1) NOT NULL DEFAULT 0,
    is_lost TINYINT(1) NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_by INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_crm_pipeline_stages_order (sort_order),
    KEY idx_crm_pipeline_stages_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS crm_conversations (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    contact_id INT UNSIGNED NOT NULL,
    assigned_to INT NULL,
    channel ENUM('whatsapp','phone','email','web','internal','other') NOT NULL DEFAULT 'whatsapp',
    external_conversation_id VARCHAR(190) NULL,
    status ENUM('open','pending','resolved','closed') NOT NULL DEFAULT 'open',
    subject VARCHAR(255) NULL,
    unread_count INT UNSIGNED NOT NULL DEFAULT 0,
    last_message_at DATETIME NULL,
    last_message_preview VARCHAR(500) NULL,
    created_by INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (id),
    KEY idx_crm_conversations_contact (contact_id),
    KEY idx_crm_conversations_status (status),
    KEY idx_crm_conversations_assigned (assigned_to),
    KEY idx_crm_conversations_last_message (last_message_at),
    KEY idx_crm_conversations_external (external_conversation_id),
    KEY idx_crm_conversations_deleted (deleted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS crm_messages (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    conversation_id INT UNSIGNED NOT NULL,
    contact_id INT UNSIGNED NOT NULL,
    sender_id INT NULL,
    sender_type ENUM('agent','contact','system','bot') NOT NULL DEFAULT 'agent',
    message_type ENUM('text','image','audio','video','file','template','location','system') NOT NULL DEFAULT 'text',
    content LONGTEXT NULL,
    media_url VARCHAR(1000) NULL,
    media_mime VARCHAR(120) NULL,
    media_name VARCHAR(255) NULL,
    whatsapp_message_id VARCHAR(190) NULL,
    external_message_id VARCHAR(190) NULL,
    status ENUM('queued','sent','delivered','read','failed') NOT NULL DEFAULT 'sent',
    error_message TEXT NULL,
    sent_at DATETIME NULL,
    delivered_at DATETIME NULL,
    read_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (id),
    KEY idx_crm_messages_conversation (conversation_id, created_at),
    KEY idx_crm_messages_contact (contact_id),
    KEY idx_crm_messages_sender (sender_id),
    KEY idx_crm_messages_external (external_message_id),
    KEY idx_crm_messages_whatsapp (whatsapp_message_id),
    KEY idx_crm_messages_deleted (deleted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS crm_activity_logs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    contact_id INT UNSIGNED NULL,
    company_id INT UNSIGNED NULL,
    conversation_id INT UNSIGNED NULL,
    user_id INT NULL,
    activity_type VARCHAR(80) NOT NULL,
    subject VARCHAR(255) NULL,
    description TEXT NULL,
    outcome VARCHAR(255) NULL,
    due_at DATETIME NULL,
    completed_at DATETIME NULL,
    metadata LONGTEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_crm_activity_contact (contact_id, created_at),
    KEY idx_crm_activity_company (company_id, created_at),
    KEY idx_crm_activity_user (user_id),
    KEY idx_crm_activity_type (activity_type),
    KEY idx_crm_activity_due (due_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS crm_api_logs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    provider VARCHAR(80) NOT NULL,
    endpoint VARCHAR(500) NULL,
    direction ENUM('inbound','outbound') NOT NULL,
    request_method VARCHAR(10) NULL,
    request_headers LONGTEXT NULL,
    request_body LONGTEXT NULL,
    response_status INT NULL,
    response_body LONGTEXT NULL,
    external_id VARCHAR(190) NULL,
    error_message TEXT NULL,
    duration_ms INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_crm_api_logs_provider (provider, created_at),
    KEY idx_crm_api_logs_external (external_id),
    KEY idx_crm_api_logs_status (response_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS crm_webhook_logs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    provider VARCHAR(80) NOT NULL,
    event_type VARCHAR(120) NULL,
    event_id VARCHAR(190) NULL,
    payload LONGTEXT NOT NULL,
    signature VARCHAR(500) NULL,
    processing_status ENUM('received','processed','failed','ignored') NOT NULL DEFAULT 'received',
    error_message TEXT NULL,
    processed_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_crm_webhook_event (provider, event_id),
    KEY idx_crm_webhook_status (processing_status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS crm_campaigns (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(190) NOT NULL,
    description TEXT NULL,
    campaign_type VARCHAR(80) NOT NULL DEFAULT 'broadcast',
    status ENUM('draft','scheduled','running','completed','paused','cancelled') NOT NULL DEFAULT 'draft',
    channel ENUM('whatsapp','email','sms','other') NOT NULL DEFAULT 'whatsapp',
    template_name VARCHAR(190) NULL,
    scheduled_at DATETIME NULL,
    started_at DATETIME NULL,
    completed_at DATETIME NULL,
    created_by INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (id),
    KEY idx_crm_campaigns_status (status),
    KEY idx_crm_campaigns_scheduled (scheduled_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS crm_campaign_contacts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    campaign_id INT UNSIGNED NOT NULL,
    contact_id INT UNSIGNED NOT NULL,
    status ENUM('pending','sent','delivered','read','failed','opted_out') NOT NULL DEFAULT 'pending',
    external_message_id VARCHAR(190) NULL,
    error_message TEXT NULL,
    sent_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_crm_campaign_contact (campaign_id, contact_id),
    KEY idx_crm_campaign_contacts_status (campaign_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS crm_deals (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    contact_id INT UNSIGNED NULL,
    company_id INT UNSIGNED NULL,
    pipeline_stage_id INT UNSIGNED NULL,
    title VARCHAR(190) NOT NULL,
    description TEXT NULL,
    value DECIMAL(18,2) NOT NULL DEFAULT 0,
    currency_id INT NULL,
    status ENUM('open','won','lost','cancelled') NOT NULL DEFAULT 'open',
    expected_close_date DATE NULL,
    assigned_to INT NULL,
    created_by INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (id),
    KEY idx_crm_deals_contact (contact_id),
    KEY idx_crm_deals_company (company_id),
    KEY idx_crm_deals_stage (pipeline_stage_id),
    KEY idx_crm_deals_status (status),
    KEY idx_crm_deals_assigned (assigned_to)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS crm_deal_stage_history (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    deal_id INT UNSIGNED NOT NULL,
    from_stage_id INT UNSIGNED NULL,
    to_stage_id INT UNSIGNED NULL,
    changed_by INT NULL,
    changed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    note VARCHAR(500) NULL,
    PRIMARY KEY (id),
    KEY idx_crm_deal_history_deal (deal_id, changed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS crm_notes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    contact_id INT UNSIGNED NULL,
    company_id INT UNSIGNED NULL,
    deal_id INT UNSIGNED NULL,
    user_id INT NULL,
    title VARCHAR(190) NULL,
    content LONGTEXT NOT NULL,
    is_pinned TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (id),
    KEY idx_crm_notes_contact (contact_id, created_at),
    KEY idx_crm_notes_company (company_id, created_at),
    KEY idx_crm_notes_deal (deal_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS crm_tasks (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    contact_id INT UNSIGNED NULL,
    company_id INT UNSIGNED NULL,
    deal_id INT UNSIGNED NULL,
    assigned_to INT NULL,
    created_by INT NULL,
    title VARCHAR(190) NOT NULL,
    description TEXT NULL,
    task_type VARCHAR(80) NULL,
    priority ENUM('low','normal','high','urgent') NOT NULL DEFAULT 'normal',
    status ENUM('pending','in_progress','completed','cancelled') NOT NULL DEFAULT 'pending',
    due_at DATETIME NULL,
    completed_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (id),
    KEY idx_crm_tasks_assigned_status (assigned_to, status),
    KEY idx_crm_tasks_due (due_at),
    KEY idx_crm_tasks_contact (contact_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS crm_files (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    contact_id INT UNSIGNED NULL,
    company_id INT UNSIGNED NULL,
    deal_id INT UNSIGNED NULL,
    uploaded_by INT NULL,
    file_name VARCHAR(255) NOT NULL,
    storage_path VARCHAR(1000) NOT NULL,
    mime_type VARCHAR(190) NULL,
    file_size BIGINT UNSIGNED NULL,
    checksum VARCHAR(128) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (id),
    KEY idx_crm_files_contact (contact_id),
    KEY idx_crm_files_company (company_id),
    KEY idx_crm_files_deal (deal_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS crm_templates (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(190) NOT NULL,
    channel ENUM('whatsapp','email','sms','other') NOT NULL DEFAULT 'whatsapp',
    external_template_name VARCHAR(190) NULL,
    language_code VARCHAR(20) NOT NULL DEFAULT 'ar',
    subject VARCHAR(255) NULL,
    body LONGTEXT NOT NULL,
    variables LONGTEXT NULL,
    status ENUM('draft','pending','approved','rejected','disabled') NOT NULL DEFAULT 'draft',
    created_by INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_crm_templates_name_channel (name, channel),
    KEY idx_crm_templates_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS crm_automations (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(190) NOT NULL,
    trigger_type VARCHAR(80) NOT NULL,
    trigger_config LONGTEXT NULL,
    action_type VARCHAR(80) NOT NULL,
    action_config LONGTEXT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_by INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_crm_automations_active (is_active),
    KEY idx_crm_automations_trigger (trigger_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS crm_calendar_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    contact_id INT UNSIGNED NULL,
    company_id INT UNSIGNED NULL,
    deal_id INT UNSIGNED NULL,
    assigned_to INT NULL,
    title VARCHAR(190) NOT NULL,
    description TEXT NULL,
    event_type VARCHAR(80) NULL,
    starts_at DATETIME NOT NULL,
    ends_at DATETIME NULL,
    location VARCHAR(255) NULL,
    status ENUM('planned','completed','cancelled') NOT NULL DEFAULT 'planned',
    created_by INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_crm_calendar_assigned (assigned_to, starts_at),
    KEY idx_crm_calendar_contact (contact_id, starts_at),
    KEY idx_crm_calendar_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS crm_tags (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    color VARCHAR(20) NOT NULL DEFAULT '#6c757d',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_crm_tags_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS crm_contact_tags (
    contact_id INT UNSIGNED NOT NULL,
    tag_id INT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (contact_id, tag_id),
    KEY idx_crm_contact_tags_tag (tag_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO crm_pipeline_stages (name, description, color, sort_order, probability, is_active)
VALUES
 ('Lead', 'New lead', '#0dcaf0', 10, 10, 1),
 ('Prospect', 'Qualified prospect', '#ffc107', 20, 35, 1),
 ('Customer', 'Converted customer', '#198754', 30, 80, 1),
 ('Lost', 'Closed lost', '#dc3545', 40, 0, 1);

INSERT IGNORE INTO crm_settings (setting_key, setting_value, setting_group) VALUES
 ('provider', 'whatsapp', 'integration'),
 ('temperature', '0.7', 'ai'),
 ('auto_reply', '0', 'ai'),
 ('typing_indicator', '1', 'messaging'),
 ('read_receipts', '1', 'messaging'),
 ('logs', '1', 'system');

INSERT IGNORE INTO unified_permissions (permission_code, display_name, created_at) VALUES
 ('crm_view', 'View CRM', NOW()),
 ('crm_edit', 'Edit CRM', NOW()),
 ('crm_delete', 'Delete CRM records', NOW());

SET FOREIGN_KEY_CHECKS = 1;
