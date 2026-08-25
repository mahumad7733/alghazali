-- ترقية آمنة لقاعدة قائمة: معرض صور الشركة الإضافي، بحد أقصى ستة مواضع لكل شركة.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS company_images (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company_id BIGINT UNSIGNED NOT NULL,
  image_path VARCHAR(500) NOT NULL,
  image_order TINYINT UNSIGNED NOT NULL,
  status ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_company_image_order (company_id, image_order),
  INDEX idx_company_images_status_order (company_id, status, image_order),
  CONSTRAINT fk_company_images_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
