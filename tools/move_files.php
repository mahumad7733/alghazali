<?php
// List of files to move from root to tools directory
$root_files_to_move = [
    'verify_migration.php', 'final_migration_family_visit.php', 'run_migration.php',
    'drop_old_fields.php', 'apply_migration_script.php', 'check_db_structure.php',
    'fix_passport_unique.php', 'apply_migration.php', 'check_supplier.php',
    'fix_employees_table.php', 'remove_cost_from_journal.php', 'verify_fix_109.php',
    'get_sp_post_invoice.php', 'check_invoice_109.php', 'check_si_000001.php',
    'list_all_invoices.php', 'check_inv_000166.php', 'debug_invoice_balance.php',
    'fix_unified_accounts.php', 'fix_all_balances.php', 'debug_last_transaction.php',
    'debug_all_balances.php', 'debug_account_105.php', 'check_transactions.php',
    'check_balance_sources.php', 'check_all_sp.php', 'check_account_balances_table.php',
    'check_account_11201001.php', 'fix_sp_post_invoice.php', 'apply_comprehensive_fixes.php',
    'get_all_procedures.php', 'delete_specific_transactions.php', 'find_those_jrn.php',
    'check_deleted_ft.php', 'check_ft_table.php', 'check_audit_logs.php',
    'check_invoices_table.php', 'fix_account_balances.php', 'check_box_account_5.php',
    'check_all_invoices.php', 'check_invoice_85_status.php', 'debug_financial_accounts.php',
    'check_all_unified_accounts.php', 'check_financial_accounts.php', 'reprocess_invoice_85.php',
    'fix_invoice_85.php', 'check_unified_accounts.php', 'check_system_settings.php',
    'check_invoice_85_v2.php', 'check_invoice_85.php', 'debug_get_unpaid.php',
    'check_employee_accounts.php', 'check_all_scripts_applied.php', 'verify_alghazali_fixes.php',
    'apply_fixes_to_alghazali.php', 'fix_account_113.php', 'fix_fn_default_leaf.php',
    'check_default_accounts.php', 'verify_all_fixes.php', 'execute_fix_simple.php',
    'test_split.php', 'execute_final_fix.php', 'check_unified_columns.php',
    'check_accounts.php', 'get_current_db_state.php', 'apply_fixes.php',
    'check_corrupted_accounts.php', 'check_customers_table.php', 'fix_invoices_79_80_supplier.php',
    'list_all_invoices_with_suppliers.php', 'create_suppliers_for_accounts.php',
    'get_supplier_ids_from_account_codes.php', 'final_verify.php',
    'fix_invoice_80_supplier_account.php', 'fix_invoices_79_80.php',
    'verify_fixes.php', 'create_test_purchase_invoice_000003.php',
    'update_sales_invoices_supplier_id.php', 'check_invoice_79_supplier.php',
    'check_invoice_73.php', 'check_unified_accounts_columns.php',
    'check_account_balances_unified.php', 'verify_purchase_invoice_78.php',
    'create_missing_purchase_invoice.php', 'check_service_invoice_config.php',
    'check_invoice_77.php', 'fix_old_purchase_invoices.php',
    'check_transaction_000006.php', 'add_supplier_account.php',
    'check_existing_supplier_accounts.php', 'check_unified_accounts_structure.php',
    'check_account_273.php', 'check_supplier_accounts.php', 'check_suppliers_table.php',
    'create_test_invoices.php', 'check_final_updates.php', 'fix_accounts_via_php.php',
    'check_fixed_accounts.php', 'analyze_unified_accounts.php',
    'create_test_purchase_invoice_cli.php', 'add_test_employee_payables_accounts.php',
    'add_test_customer_deposits_accounts.php', 'add_test_supplier_accounts.php',
    'add_test_advances_deposits_accounts.php', 'add_test_advances_accounts.php',
    'add_test_agent_accounts.php', 'add_test_customer_accounts.php',
    'check_customer_accounts.php', 'rename_box_accounts.php', 'find_11101001.php',
    'check_accounts_under_111.php', 'move_boxes_to_11101.php', 'check_box_parent_ids.php',
    'fix_chart_of_accounts.php'
];

// List of files to move from admin to admin/tools directory
$admin_files_to_move = [
    'test_financial_fields.php', 'debug_account_tree.php', 'check_parent_ids.php',
    'create_test_purchase_invoice.php', 'list_suppliers_cli.php', 'list_suppliers.php',
    'full_sp.php', 'show_sp.php', 'test_invoices_data.php', 'list_invoices.php',
    'show_current_services.php', 'get_sp_with_details.php', 'get_sp_post_receipt.php',
    'get_sp_post_invoice.php', 'last_10_receipts.php', 'recalculate_balances.php',
    'currency_exchange_tool.php', 'user_financial_settings.php'
];

$root_dir = __DIR__;
$tools_dir = __DIR__ . '/tools';
$admin_dir = __DIR__ . '/admin';
$admin_tools_dir = __DIR__ . '/admin/tools';

echo "Moving root files to tools...\n";
foreach ($root_files_to_move as $file) {
    $src = $root_dir . '/' . $file;
    $dest = $tools_dir . '/' . $file;
    if (file_exists($src)) {
        if (rename($src, $dest)) {
            echo "Moved: $file\n";
        } else {
            echo "Failed to move: $file\n";
        }
    }
}

echo "\nMoving admin files to admin/tools...\n";
foreach ($admin_files_to_move as $file) {
    $src = $admin_dir . '/' . $file;
    $dest = $admin_tools_dir . '/' . $file;
    if (file_exists($src)) {
        if (rename($src, $dest)) {
            echo "Moved: admin/$file\n";
        } else {
            echo "Failed to move: admin/$file\n";
        }
    }
}

echo "\nDone!\n";
