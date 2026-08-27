<?php
declare(strict_types=1);

require __DIR__ . '/_layout.php';

ob_start();
renderAdminPage(requireAdminPage('countries'));
$html = (string) ob_get_clean();
$style = '<style id="countries-form-style">
.operation-create-modal,
.operation-create-modal .operation-create-fields,
.operation-create-modal .operation-create-fields form.form-grid,
.country-edit-dialog,
.country-edit-dialog form.form-grid {
  height: auto !important;
  min-height: 0 !important;
}
.operation-create-modal .operation-create-fields form.form-grid,
.country-edit-dialog form.form-grid {
  margin: 0 !important;
  padding: 0 !important;
}
.operation-create-modal .operation-create-fields .field,
.country-edit-dialog .field {
  min-height: 0 !important;
  visibility: visible !important;
  opacity: 1 !important;
}
.operation-create-modal .operation-create-fields .modal-actions,
.country-edit-dialog .modal-actions {
  margin-top: 0 !important;
}
.modal.country-edit-dialog {
  width: min(500px, 100%) !important;
  padding: 30px !important;
}
.country-edit-dialog > section {
  width: auto !important;
  padding: 0 !important;
}
.country-edit-dialog > section > header {
  display: block !important;
  margin: 0 0 20px !important;
  padding: 0 !important;
  border: 0 !important;
}
.country-edit-dialog .country-edit-badge {
  display: none !important;
}
.country-edit-dialog > section h3 {
  margin: 0 0 8px !important;
}
.country-edit-dialog .modal-actions {
  grid-column: 1 / -1 !important;
  display: flex !important;
  flex-direction: row !important;
  justify-content: space-between !important;
  align-items: center !important;
  gap: 12px !important;
}
.modal.country-edit-dialog .country-edit-dialog .modal-actions {
  justify-content: space-between !important;
  flex-direction: row !important;
}
@media (max-width: 560px) {
  .modal.country-edit-dialog {
    width: calc(100% - 24px) !important;
    padding: 22px 18px !important;
  }
}
.dash-title .country-search {
  width: min(260px, 100%);
  min-height: 42px;
  margin-inline-start: auto;
  padding: 0 13px;
  border: 1px solid var(--border);
  border-radius: 11px;
  background: var(--bg-card);
  color: var(--text-main);
  font: inherit;
}
.country-city-count {
  display: inline-block;
  margin-inline-start: 7px;
  color: var(--text-muted);
  font-size: .76rem;
  font-weight: 700;
  white-space: nowrap;
}
.country-edit-dialog form.form-grid {
  grid-template-columns: 1fr !important;
}
.modal.country-edit-dialog {
  box-sizing: border-box !important;
  max-width: calc(100vw - 24px) !important;
  overflow-x: hidden !important;
}
.modal .country-edit-dialog {
  width: 100% !important;
  max-width: 100% !important;
  padding: 0 !important;
  box-sizing: border-box !important;
}
.country-edit-dialog > section,
.country-edit-dialog form.form-grid,
.country-edit-dialog .field,
.country-edit-dialog input,
.country-edit-dialog select {
  width: 100% !important;
  max-width: 100% !important;
  min-width: 0 !important;
  box-sizing: border-box !important;
}
@media (max-width: 650px) {
  .dash-title .country-search {
    width: 100%;
    margin: 10px 0 0;
  }
  .country-city-count {
    display: block;
    margin: 3px 0 0;
  }
}
</style>';

echo str_replace('</head>', $style . '</head>', $html);
