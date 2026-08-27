SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS site_settings (
  id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
  site_name_ar VARCHAR(180) NOT NULL DEFAULT 'منصة رحلة',
  tagline_ar VARCHAR(255) NULL,
  logo_path VARCHAR(500) NULL,
  icon_path VARCHAR(500) NULL,
  footer_text_ar VARCHAR(255) NOT NULL DEFAULT '© 2026 منصة رحلة',
  updated_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_site_settings_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO site_settings (id, site_name_ar, tagline_ar, footer_text_ar)
VALUES (1, 'منصة رحلة', 'احجز رحلتك بسهولة وأمان', '© 2026 منصة رحلة');
