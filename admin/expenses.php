<?php
require_once 'header.php';
require_once '../includes/accounting_functions.php';

function normalize_expense_status($raw_status)
{
    if ($raw_status === null) return 'draft';
    $s = (string)$raw_status;
    $s_lc = mb_strtolower(trim($s), 'UTF-8');
    if (in_array($s_lc, ['0', 'draft', 'مسودة', 'pending', 'new', 'unposted', ''], true)) return 'draft';
    if (in_array($s_lc, ['1', 'posted', 'active', 'مرحل', 'approved', 'paid'], true)) return 'posted';
    if (in_array($s_lc, ['2', 'cancelled', 'ملغي', 'canceled', 'reversed', 'معكوس'], true)) return 'cancelled';
    return ctype_digit($s) ? ((int)$s === 1 ? 'posted' : ((int)$s === 2 ? 'cancelled' : 'draft')) : $s_lc;
}

function is_expense_draft($expense)
{
    $norm = normalize_expense_status($expense['status'] ?? null);
    return $norm === 'draft' || $norm === 'cancelled';
}

function is_expense_posted($expense)
{
    return normalize_expense_status($expense['status'] ?? null) === 'posted';
}

function has_permission_v3_exp($permission_code, $branch_id = null)
{
    global $pdo, $user_role, $user_branch_id, $user_role_id;
    $role_lc = mb_strtolower((string)$user_role, 'UTF-8');
    $role_id  = (int)($user_role_id ?? 0);

    $super_roles = ['admin', 'developer', 'super_admin', 'مدير', 'مبرمج', 'مطور'];
    if (in_array($role_lc, $super_roles, true)) return true;
    if (has_permission('manage_expenses')) return true;
    if (!$role_id) return false;
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM role_permissions_unified rp JOIN unified_permissions p ON rp.permission_id = p.id WHERE rp.role_id = ? AND p.permission_code = ?");
    $stmt->execute([$role_id, $permission_code]);
    return (int)$stmt->fetchColumn() > 0;
}

function can_edit_expense($expense)
{
    global $user_role, $user_id, $user_branch_id, $user_role_id;
    $role_lc = mb_strtolower((string)$user_role, 'UTF-8');
    $role_id = (int)($user_role_id ?? 0);
    $super_roles = ['admin', 'developer', 'super_admin', 'مدير', 'مبرمج', 'مطور'];
    if (in_array($role_lc, $super_roles, true)) return is_expense_draft($expense);
    if (!is_expense_draft($expense)) return false;

    if (has_permission_v3_exp('expense_edit')) return true;
    if (has_permission('manage_expenses')) return true;

    if (($role_lc === 'branch_manager' || $role_lc === 'مدير فرع') && $expense['branch_id'] == $user_branch_id) return true;
    if ($role_lc === 'accountant' || $role_lc === 'محاسب') return true;
    return $expense['created_by'] == $user_id;
}

function can_post_expense($expense)
{
    global $user_role, $user_branch_id, $user_role_id;
    $role_lc = mb_strtolower((string)$user_role, 'UTF-8');
    $role_id = (int)($user_role_id ?? 0);
    $super_roles = ['admin', 'developer', 'super_admin', 'مدير', 'مبرمج', 'مطور'];
    if (in_array($role_lc, $super_roles, true)) return is_expense_draft($expense);
    if (!is_expense_draft($expense)) return false;

    if (has_permission_v3_exp('expense_post')) return true;
    if (has_permission('manage_expenses')) return true;

    if ($role_lc === 'accountant' || $role_lc === 'محاسب') return true;
    if (($role_lc === 'branch_manager' || $role_lc === 'مدير فرع') && $expense['branch_id'] == $user_branch_id) return true;
    return false;
}

function can_unpost_expense($expense)
{
    global $user_role, $user_role_id;
    $role_lc = mb_strtolower((string)$user_role, 'UTF-8');
    $role_id = (int)($user_role_id ?? 0);
    $super_roles = ['admin', 'developer', 'super_admin', 'مدير', 'مبرمج', 'مطور'];
    if (in_array($role_lc, $super_roles, true)) return is_expense_posted($expense);
    if (!is_expense_posted($expense)) return false;

    if (has_permission_v3_exp('expenses_unpost')) return true;
    if (has_permission_v3_exp('expense_unpost')) return true;
    if (has_permission('manage_expenses')) return true;
    return false;
}

function can_delete_expense($expense)
{
    global $user_role, $user_id, $user_branch_id, $user_role_id;
    $role_lc = mb_strtolower((string)$user_role, 'UTF-8');
    $role_id = (int)($user_role_id ?? 0);
    $super_roles = ['admin', 'developer', 'super_admin', 'مدير', 'مبرمج', 'مطور'];
    if (in_array($role_lc, $super_roles, true)) return !is_expense_posted($expense);

    if (is_expense_posted($expense)) return false;

    if (has_permission_v3_exp('expense_delete')) return true;
    if (has_permission('manage_expenses')) return true;

    if (($role_lc === 'branch_manager' || $role_lc === 'مدير فرع') && $expense['branch_id'] == $user_branch_id) return true;
    if ($role_lc === 'accountant' || $role_lc === 'محاسب') return true;
    return $expense['created_by'] == $user_id;
}

if (!has_permission_v3_exp('expenses_view') && !has_permission('manage_expenses')) {
    echo "<script>alert('ليس لديك صلاحية لاستعراض المصاريف.'); location.href='index.php';</script>";
    exit();
}

// جلب فئات المصاريف (الحالة عبارة عن tinyint: 1 = نشط)
$expense_categories = $pdo->query("SELECT id, category_name_ar, account_id FROM expenses_categories WHERE status = 1 AND deleted_at IS NULL ORDER BY category_name_ar")->fetchAll();

// جلب العملات
$currencies = $pdo->query("SELECT id, currency_name, currency_code FROM currencies WHERE is_active = 1")->fetchAll();

// جلب حسابات المصروفات (الحسابات التي تستلم المبلغ - من شجرة الحسابات نوع expense)
$expense_accounts_stmt = $pdo->prepare("
    SELECT id, account_code, account_name_ar
    FROM unified_accounts
    WHERE account_status = 'active'
      AND deleted_at IS NULL
      AND account_type = 'expense'
      AND parent_id IS NOT NULL
    ORDER BY account_code
");
$expense_accounts_stmt->execute();
$expense_accounts = $expense_accounts_stmt->fetchAll();

// جلب الحسابات المحاسبية المتاحة للدفع منها (صناديق وبنوك من النظام الموحد)
// صناديق = account_code يبدأ بـ 11101 وله أب، بنوك = account_code يبدأ بـ 11102 وله أب
$payment_accounts_stmt = $pdo->query("
    SELECT id, account_name_ar, account_code, parent_id,
           CASE
               WHEN account_code LIKE '11101%' AND parent_id IS NOT NULL THEN 'box'
               WHEN account_code LIKE '11102%' AND parent_id IS NOT NULL THEN 'bank'
               ELSE 'other'
           END as derived_type
    FROM unified_accounts
    WHERE account_status = 'active'
      AND deleted_at IS NULL
      AND parent_id IS NOT NULL
      AND (account_code LIKE '11101%' OR account_code LIKE '11102%')
    ORDER BY account_code
");
$payment_accounts = $payment_accounts_stmt->fetchAll();

function resolve_expense_branch_id(PDO $pdo, $paid_from_account_id = null, $fallback_branch_id = null)
{
    $branch_id = !empty($_SESSION['branch_id']) ? (int)$_SESSION['branch_id'] : null;

    if (!$branch_id && function_exists('get_current_user_pricing_context')) {
        $ctx = get_current_user_pricing_context($pdo);
        if (!empty($ctx['branch_id'])) {
            $branch_id = (int)$ctx['branch_id'];
        }
    }

    if (!$branch_id && !empty($paid_from_account_id)) {
        $stmt_branch = $pdo->prepare("SELECT branch_id FROM unified_accounts WHERE id = ? LIMIT 1");
        $stmt_branch->execute([(int)$paid_from_account_id]);
        $account_branch_id = $stmt_branch->fetchColumn();
        if (!empty($account_branch_id)) {
            $branch_id = (int)$account_branch_id;
        }
    }

    if (!$branch_id && !empty($fallback_branch_id)) {
        $branch_id = (int)$fallback_branch_id;
    }

    if (!$branch_id) {
        $stmt_single_branch = $pdo->query("SELECT id FROM branches WHERE COALESCE(is_active, 1) = 1 AND deleted_at IS NULL ORDER BY id ASC LIMIT 2");
        $branch_rows = $stmt_single_branch->fetchAll(PDO::FETCH_COLUMN);
        if (count($branch_rows) === 1) {
            $branch_id = (int)$branch_rows[0];
        }
    }

    return $branch_id ?: null;
}


// إضافة مصروف جديد
if (isset($_POST['add_expense'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = "خطأ في التحقق من الطلب (CSRF).";
    } else {
        $expense_date = $_POST['expense_date'];
        $category_id = $_POST['category_id'];
        $amount = $_POST['amount'];
        $currency_id = $_POST['currency_id'];
        $description = $_POST['description'];
        $notes = $_POST['notes'];
        $payment_method = $_POST['payment_method'];
        $paid_from_account_id = !empty($_POST['paid_from_account_id']) ? $_POST['paid_from_account_id'] : null;
        $expense_account_id = !empty($_POST['expense_account_id']) ? $_POST['expense_account_id'] : null;
        $created_by = (int)($_SESSION['admin_id'] ?? 0);
        $branch_id = resolve_expense_branch_id($pdo, $paid_from_account_id);

        $errors = [];

        if (!$created_by) {
            $errors[] = "تعذر تحديد المستخدم الحالي. أعد تسجيل الدخول ثم حاول مرة أخرى.";
        }

        // تحديد حساب المصروف المستلم للمبلغ: الأولوية للحقل الجديد، إن لم يُحدد أخذ حساب الفئة وإنشاؤه إن لزم
        if ($expense_account_id) {
            // تحقق من وجوده ونوعه expense
            $stmt_acc = $pdo->prepare("SELECT id FROM unified_accounts WHERE id = ? AND account_type = 'expense' AND account_status = 'active' AND deleted_at IS NULL");
            $stmt_acc->execute([$expense_account_id]);
            if (!$stmt_acc->fetchColumn()) {
                $errors[] = "حساب المصروف المحدد غير صالح.";
            }
            $expense_chart_account_id = (int)$expense_account_id;
        } else {
            // احتياطي: جلب حساب الفئة تلقائياً وإنشاؤه إن لم يكن
            $stmt_cat = $pdo->prepare("SELECT id, category_name_ar, account_id FROM expenses_categories WHERE id = ?");
            $stmt_cat->execute([$category_id]);
            $cat_row = $stmt_cat->fetch(PDO::FETCH_ASSOC);
            $expense_chart_account_id = $cat_row['account_id'] ?? null;

            if (!$expense_chart_account_id && $cat_row) {
                $parent_code = get_parent_account_code_by_entity('expense_category');
                $new_chart_account_id = create_sub_account($parent_code, "فئة مصروف: " . $cat_row['category_name_ar'], $cat_row['id'], 'expense_category');
                if ($new_chart_account_id) {
                    $stmt_link = $pdo->prepare("UPDATE expenses_categories SET account_id = ? WHERE id = ?");
                    $stmt_link->execute([$new_chart_account_id, $cat_row['id']]);
                    $expense_chart_account_id = $new_chart_account_id;
                }
            }
            if (!$expense_chart_account_id) {
                $errors[] = "يجب تحديد حساب المصروف المستلم للمبلغ.";
            }
        }

        if ($payment_method != 'check' && !$paid_from_account_id) {
            $errors[] = "يجب تحديد الحساب المدفوع منه (صندوق/بنك).";
        }

        if (!$branch_id) {
            $errors[] = "تعذر تحديد الفرع لهذا المصروف. اربط المستخدم بفرع أو اختر حساب دفع مرتبط بفرع.";
        }

        if (empty($errors)) {
            try {
                // --- التحقق من إغلاق الفترة المالية ---
                if (is_period_closed($pdo, $expense_date)) {
                    throw new Exception("تنبيه: لا يمكن تسجيل مصروف. التاريخ المحدد ($expense_date) يقع ضمن فترة مالية مغلقة.");
                }

                // 1. تسجيل المصروف كمسودة (بدون قيد محاسبي)
                $stmt = $pdo->prepare("INSERT INTO expenses (expense_date, category_id, expense_account_id, amount, currency_id, description, notes, payment_method, paid_from_account_id, created_by, branch_id, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'draft')");
                $stmt->execute([$expense_date, $category_id, $expense_chart_account_id, $amount, $currency_id, $description, $notes, $payment_method, $paid_from_account_id, $created_by, $branch_id]);
                $expense_id = (int)$pdo->lastInsertId();

                // 2. لا يتم إنشاء قيد محاسبي للمسودة، سيتم عند الترحيل

                $_SESSION['flash_message'] = ['type' => 'success', 'body' => 'تمت إضافة المصروف بنجاح.'];
                echo "<script>location.href='expenses.php';</script>";
                exit();
            } catch (Exception $e) {
                $error = "حدث خطأ أثناء الإضافة: " . $e->getMessage();
            }
        }
    }
}

// إلغاء ترحيل مصروف
if (isset($_POST['unpost_expense'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = "خطأ في التحقق من الطلب (CSRF).";
    } else {
        $id = (int)($_POST['id'] ?? 0);

        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("SELECT * FROM expenses WHERE id = ? AND deleted_at IS NULL");
            $stmt->execute([$id]);
            $expense = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$expense) {
                throw new Exception("المصروف غير موجود.");
            }

            if (!is_expense_posted($expense)) {
                throw new Exception("هذا المصروف لم يتم ترحيله بعد، لا يمكن سحب ترحيله.");
            }

            if (!can_unpost_expense($expense)) {
                throw new Exception("ليس لديك صلاحية لسحب ترحيل هذا المصروف.");
            }

            if (is_period_closed($pdo, $expense['expense_date'])) {
                throw new Exception("تنبيه: لا يمكن سحب ترحيل مصروف. التاريخ (" . $expense['expense_date'] . ") يقع ضمن فترة مالية مغلقة.");
            }

            $transaction_warning = '';
            if (!empty($expense['transaction_id'])) {
                $tid_int = (int)$expense['transaction_id'];
                try {
                    $tr_check = $pdo->prepare("SELECT id, status FROM financial_transactions WHERE id = ?");
                    $tr_check->execute([$tid_int]);
                    $tr_exists = $tr_check->fetch(PDO::FETCH_ASSOC);

                    if ($tr_exists) {
                        $tr_status_lc = mb_strtolower(trim((string)($tr_exists['status'] ?? '')), 'UTF-8');
                        if (!in_array($tr_status_lc, ['posted', '1', 'active', 'مرحل', 'approved', 'paid'], true)) {
                            try {
                                $pdo->prepare("DELETE FROM journal_lines WHERE financial_transaction_id = ?")->execute([$tid_int]);
                                $pdo->prepare("DELETE FROM payment_allocations WHERE financial_transaction_id = ?")->execute([$tid_int]);
                                $pdo->prepare("DELETE FROM financial_transactions WHERE id = ?")->execute([$tid_int]);
                                $transaction_warning = ' (ملاحظة: تم حذف القيد المحاسبي المسودة المرتبط)';
                            } catch (Exception $sub_ex) {
                                $transaction_warning = ' (ملاحظة: القيد المحاسبي المرتبط غير مرحّل - تم إلغاء ربطه)';
                            }
                        } else {
                            $before = (int)$pdo->query("SELECT COUNT(*) FROM financial_transactions WHERE id = $tid_int")->fetchColumn();
                            php_delete_financial_transaction_and_reverse($pdo, $tid_int);
                            $after = (int)$pdo->query("SELECT COUNT(*) FROM financial_transactions WHERE id = $tid_int AND status NOT IN ('cancelled','2','ملغي','canceled','reversed','معكوس')")->fetchColumn();
                            if ($before > 0 && $after === 0) {
                                $transaction_warning = ' (تم عكس القيد المحاسبي المرتبط بنجاح)';
                            } else {
                                $cancelled_check = $pdo->prepare("SELECT status FROM financial_transactions WHERE id = ?");
                                $cancelled_check->execute([$tid_int]);
                                $cur_st = mb_strtolower(trim((string)($cancelled_check->fetchColumn() ?? '')), 'UTF-8');
                                if (in_array($cur_st, ['cancelled', '2', 'ملغي', 'canceled', 'reversed', 'معكوس'], true)) {
                                    $transaction_warning = ' (تم إلغاء القيد المحاسبي المرتبط وإنشاء قيد عكسي)';
                                }
                            }
                        }
                    }
                } catch (Exception $tr_ex) {
                    try {
                        $user_id = $_SESSION['admin_id'] ?? 1;
                        $user_ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
                        $reason = 'إلغاء مصروف رقم ' . $expense['id'] . ' - ' . ($_POST['reason'] ?? 'سحب ترحيل') . ' | سبب تقني: ' . mb_substr($tr_ex->getMessage(), 0, 180);
                        $pdo->prepare("
                            UPDATE financial_transactions
                            SET status = 'cancelled',
                                cancelled_at = NOW(),
                                cancelled_by = ?,
                                cancelled_ip = ?,
                                cancellation_reason = ?
                            WHERE id = ?
                        ")->execute([$user_id, $user_ip, $reason, $tid_int]);
                        $transaction_warning = ' (ملاحظة: تم إلغاء القيد المرتبط بدون إنشاء قيد عكسي - يُنصح بمراجعة دليل الحسابات للتأكد من الأرصدة)';
                    } catch (Exception $force_ex) {
                        $transaction_warning = ' (تنبيه: فشل في عكس/إلغاء القيد المرتفع تقنياً، تم إلغاء المصروف فقط - يُنصح بمراجعة دليل الحسابات يدويًا وتصحيح القيد المرتبط رقم ' . $tid_int . ')';
                    }
                }
            }

            $current_status_raw = (string)($expense['status'] ?? '');
            $new_status = (ctype_digit($current_status_raw) || is_numeric($current_status_raw)) ? 0 : 'draft';
            $update_stmt = $pdo->prepare("UPDATE expenses SET status = ?, transaction_id = NULL WHERE id = ?");
            $update_stmt->execute([$new_status, $id]);

            $pdo->commit();
            $_SESSION['flash_message'] = ['type' => 'success', 'body' => 'تم سحب ترحيل المصروف بنجاح.' . $transaction_warning];
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $_SESSION['flash_message'] = ['type' => 'danger', 'body' => 'خطأ أثناء سحب الترحيل: ' . $e->getMessage()];
        }

        echo "<script>location.href='expenses.php';</script>";
        exit();
    }
}

// تحديث مصروف
if (isset($_POST['update_expense'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = "خطأ في التحقق من الطلب (CSRF).";
    } else {
        $id = $_POST['id'];

        $old_expense_stmt = $pdo->prepare("SELECT * FROM expenses WHERE id = ?");
        $old_expense_stmt->execute([$id]);
        $old_expense = $old_expense_stmt->fetch(PDO::FETCH_ASSOC);

        if (!can_edit_expense($old_expense)) {
            $error = "ليس لديك صلاحية لتعديل هذا المصروف.";
        } else {
            $expense_date = $_POST['expense_date'];
            $category_id = $_POST['category_id'];
            $amount = $_POST['amount'];
            $currency_id = $_POST['currency_id'];
            $description = $_POST['description'];
            $notes = $_POST['notes'];
            $payment_method = $_POST['payment_method'];
            $paid_from_account_id = !empty($_POST['paid_from_account_id']) ? $_POST['paid_from_account_id'] : null;
            $expense_account_id = !empty($_POST['expense_account_id']) ? $_POST['expense_account_id'] : null;

            $errors = [];

            if ($expense_account_id) {
                $stmt_acc = $pdo->prepare("SELECT id FROM unified_accounts WHERE id = ? AND account_type = 'expense' AND account_status = 'active' AND deleted_at IS NULL");
                $stmt_acc->execute([$expense_account_id]);
                if (!$stmt_acc->fetchColumn()) {
                    $errors[] = "حساب المصروف المحدد غير صالح.";
                }
                $expense_chart_account_id = (int)$expense_account_id;
            } else {
                $stmt_cat = $pdo->prepare("SELECT id, category_name_ar, account_id FROM expenses_categories WHERE id = ?");
                $stmt_cat->execute([$category_id]);
                $cat_row = $stmt_cat->fetch(PDO::FETCH_ASSOC);
                $expense_chart_account_id = $cat_row['account_id'] ?? null;

                if (!$expense_chart_account_id && $cat_row) {
                    $parent_code = get_parent_account_code_by_entity('expense_category');
                    $new_chart_account_id = create_sub_account($parent_code, "فئة مصروف: " . $cat_row['category_name_ar'], $cat_row['id'], 'expense_category');
                    if ($new_chart_account_id) {
                        $stmt_link = $pdo->prepare("UPDATE expenses_categories SET account_id = ? WHERE id = ?");
                        $stmt_link->execute([$new_chart_account_id, $cat_row['id']]);
                        $expense_chart_account_id = $new_chart_account_id;
                    }
                }
                if (!$expense_chart_account_id) {
                    $errors[] = "يجب تحديد حساب المصروف المستلم للمبلغ.";
                }
            }

            if ($payment_method != 'check' && !$paid_from_account_id) {
                $errors[] = "يجب تحديد الحساب المدفوع منه (صندوق/بنك).";
            }

            if (empty($errors)) {
                try {
                    $pdo->beginTransaction();

                    if (is_period_closed($pdo, $expense_date)) {
                        throw new Exception("تنبيه: لا يمكن تعديل مصروف. التاريخ المحدد ($expense_date) يقع ضمن فترة مالية مغلقة.");
                    }

                    $branch_id = resolve_expense_branch_id($pdo, $paid_from_account_id, $old_expense['branch_id'] ?? null);
                    if (!$branch_id) {
                        throw new Exception("تعذر تحديد الفرع لهذا المصروف.");
                    }

                    $stmt = $pdo->prepare("UPDATE expenses SET expense_date = ?, category_id = ?, expense_account_id = ?, amount = ?, currency_id = ?, description = ?, notes = ?, payment_method = ?, paid_from_account_id = ? WHERE id = ?");
                    $stmt->execute([$expense_date, $category_id, $expense_chart_account_id, $amount, $currency_id, $description, $notes, $payment_method, $paid_from_account_id, $id]);

                    $admin_id = (int)($_SESSION['admin_id'] ?? 0);
                    $amount_f = (float)$amount;

                    if ($paid_from_account_id && $expense_chart_account_id) {
                        if (!empty($old_expense['transaction_id'])) {
                            php_delete_financial_transaction_and_reverse($pdo, (int)$old_expense['transaction_id']);
                        }
                        $entry_desc = "تحديث مصروف: " . $description;
                        $tid = php_create_financial_entry(
                            $pdo,
                            $expense_date,
                            'journal',
                            'other',
                            0,
                            (int)$expense_chart_account_id,
                            (int)$paid_from_account_id,
                            $amount_f,
                            (int)$currency_id,
                            $entry_desc,
                            $admin_id,
                            $branch_id,
                            null,
                            null,
                            'expense',
                            (int)$id,
                            true
                        );
                        if (!$tid) {
                            throw new Exception("فشل إنشاء القيد المحاسبي عند التحديث.");
                        }
                        $pdo->prepare("UPDATE expenses SET transaction_id = ? WHERE id = ?")->execute([$tid, $id]);
                    } elseif (!empty($old_expense['transaction_id'])) {
                        php_delete_financial_transaction_and_reverse($pdo, (int)$old_expense['transaction_id']);
                        $pdo->prepare("UPDATE expenses SET transaction_id = NULL WHERE id = ?")->execute([$id]);
                    }

                    $pdo->commit();
                    $_SESSION['flash_message'] = ['type' => 'success', 'body' => 'تم تحديث المصروف بنجاح.'];
                    echo "<script>location.href='expenses.php';</script>";
                    exit();
                } catch (Exception $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    $error = "حدث خطأ أثناء التحديث: " . $e->getMessage();
                }
            }
        }
    }
}

// ترحيل مصروف
if (isset($_POST['post_expense'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = "خطأ في التحقق من الطلب (CSRF).";
    } else {
        $id = (int)($_POST['id'] ?? 0);

        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("SELECT * FROM expenses WHERE id = ? AND deleted_at IS NULL");
            $stmt->execute([$id]);
            $expense = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$expense) {
                throw new Exception("المصروف غير موجود.");
            }

            if (!is_expense_draft($expense)) {
                throw new Exception("هذا المصروف محفوظ بحالة مرحلة أو ملغاة، لا يمكن ترحيله مرة أخرى.");
            }

            if (!can_post_expense($expense)) {
                throw new Exception("ليس لديك صلاحية لترحيل هذا المصروف.");
            }

            if (is_period_closed($pdo, $expense['expense_date'])) {
                throw new Exception("تنبيه: لا يمكن ترحيل مصروف. التاريخ المحدد (" . $expense['expense_date'] . ") يقع ضمن فترة مالية مغلقة.");
            }

            $current_status_raw = (string)($expense['status'] ?? '');
            $use_numeric_status = (ctype_digit($current_status_raw) || is_numeric($current_status_raw));

            if ($expense['paid_from_account_id'] && $expense['expense_account_id']) {
                $created_by = (int)($_SESSION['admin_id'] ?? 0);
                $amount_f = (float)$expense['amount'];
                $entry_desc = "ترحيل مصروف: " . $expense['description'];

                $tid = php_create_financial_entry(
                    $pdo,
                    $expense['expense_date'],
                    'journal',
                    'other',
                    0,
                    (int)$expense['expense_account_id'],
                    (int)$expense['paid_from_account_id'],
                    $amount_f,
                    (int)$expense['currency_id'],
                    $entry_desc,
                    $created_by,
                    (int)$expense['branch_id'],
                    null,
                    null,
                    'expense',
                    (int)$expense['id'],
                    false
                );

                if (!$tid) {
                    throw new Exception("فشل إنشاء القيد المحاسبي عند الترحيل.");
                }

                $new_status = $use_numeric_status ? 1 : 'posted';
                $update_stmt = $pdo->prepare("UPDATE expenses SET status = ?, transaction_id = ? WHERE id = ?");
                $update_stmt->execute([$new_status, $tid, $id]);
            } else {
                $new_status = $use_numeric_status ? 1 : 'posted';
                $update_stmt = $pdo->prepare("UPDATE expenses SET status = ? WHERE id = ?");
                $update_stmt->execute([$new_status, $id]);
            }

            $pdo->commit();
            $_SESSION['flash_message'] = ['type' => 'success', 'body' => 'تم ترحيل المصروف بنجاح.'];
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $_SESSION['flash_message'] = ['type' => 'danger', 'body' => 'خطأ أثناء الترحيل: ' . $e->getMessage()];
        }

        echo "<script>location.href='expenses.php';</script>";
        exit();
    }
}

// حذف مصروف (أرشفة)
if (isset($_GET['delete'])) {
    if (!verify_csrf_token($_GET['csrf_token'] ?? '')) {
        $error = "خطأ في التحقق من الطلب (CSRF).";
    } else {
        $id = $_GET['delete'];
        try {
            $pdo->beginTransaction();

            $expense_to_delete_stmt = $pdo->prepare("SELECT * FROM expenses WHERE id = ?");
            $expense_to_delete_stmt->execute([$id]);
            $expense_to_delete = $expense_to_delete_stmt->fetch(PDO::FETCH_ASSOC);

            if (!$expense_to_delete) {
                throw new Exception("المصروف غير موجود.");
            }

            if (!can_delete_expense($expense_to_delete)) {
                throw new Exception("ليس لديك صلاحية لحذف هذا المصروف.");
            }

            $transaction_id_to_delete = $expense_to_delete['transaction_id'] ?? null;

            if ($transaction_id_to_delete) {
                php_delete_financial_transaction_and_reverse($pdo, (int)$transaction_id_to_delete);
            }

            $stmt = $pdo->prepare("UPDATE expenses SET deleted_at = NOW() WHERE id = ?");
            $stmt->execute([$id]);

            $pdo->commit();
            $_SESSION['flash_message'] = ['type' => 'success', 'body' => 'تم حذف المصروف بنجاح.'];
            echo "<script>location.href='expenses.php';</script>";
            exit();
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error = "حدث خطأ أثناء الحذف: " . $e->getMessage();
        }
    }
}

// جلب المصاريف
$expenses_stmt = $pdo->prepare("
    SELECT e.*, ec.category_name_ar, c.currency_code, coa.account_name_ar as paid_from_account_name, coa.account_code as paid_from_account_code, u.username
    FROM expenses e
    JOIN expenses_categories ec ON e.category_id = ec.id
    JOIN currencies c ON e.currency_id = c.id
    LEFT JOIN unified_accounts coa ON e.paid_from_account_id = coa.id
    LEFT JOIN users u ON e.created_by = u.id
    WHERE e.deleted_at IS NULL
    ORDER BY e.expense_date DESC, e.id DESC
");
$expenses_stmt->execute();
$expenses = $expenses_stmt->fetchAll();

$page_title = "إدارة المصاريف";
?>

<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h3 class="fw-bold text-primary"><i class="fas fa-money-bill-wave me-2"></i> إدارة المصاريف</h3>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addExpenseModal">
            <i class="fas fa-plus-circle me-1"></i> إضافة مصروف جديد
        </button>
    </div>

    <?php if (isset($_SESSION['flash_message'])): ?>
        <div class="alert alert-<?php echo $_SESSION['flash_message']['type']; ?> alert-dismissible fade show rounded-4 shadow-sm border-0">
            <?php echo $_SESSION['flash_message']['body']; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php unset($_SESSION['flash_message']); ?>
    <?php endif; ?>

    <?php if (isset($error)): ?>
        <div class="alert alert-danger alert-dismissible fade show rounded-4 shadow-sm border-0">
            <?php echo $error; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="card shadow-sm border-0 rounded-3">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="bg-light">
                        <tr>
                            <th class="px-4 py-3">التاريخ</th>
                            <th>الفئة</th>
                            <th>المبلغ</th>
                            <th>الوصف</th>
                            <th>الحالة</th>
                            <th>طريقة الدفع</th>
                            <th>الحساب المدفوع منه</th>
                            <th>بواسطة</th>
                            <th class="text-center">الإجراءات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($expenses as $expense): ?>
                            <tr>
                                <td class="px-4 py-3"><?php echo h($expense['expense_date']); ?></td>
                                <td><?php echo htmlspecialchars($expense['category_name_ar']); ?></td>
                                <td class="fw-bold text-danger"><?php echo number_format($expense['amount'], 2) . ' ' . h($expense['currency_code']); ?></td>
                                <td><?php echo htmlspecialchars($expense['description']); ?></td>
                                <td>
                                    <?php if ($expense['status'] == 'draft'): ?>
                                        <span class="badge bg-warning text-dark">مسودة</span>
                                    <?php else: ?>
                                        <span class="badge bg-success">مرحل</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php
                                    $payment_method_text = [
                                        'cash' => 'نقدي',
                                        'bank_transfer' => 'تحويل بنكي',
                                        'check' => 'شيك'
                                    ];
                                    echo $payment_method_text[$expense['payment_method']] ?? $expense['payment_method'];
                                    ?>
                                </td>
                                <td>
                                    <?php if ($expense['paid_from_account_name']): ?>
                                        <div class="small fw-bold text-primary"><?php echo htmlspecialchars($expense['paid_from_account_name']); ?></div>
                                        <div class="small text-muted"><?php echo h($expense['paid_from_account_code']); ?></div>
                                    <?php else: ?>
                                        <span class="text-muted small">غير محدد</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo h($expense['username']); ?></td>
                                <td class="text-center">
                                    <?php
                                    $is_draft_norm = is_expense_draft($expense);
                                    $is_posted_norm = is_expense_posted($expense);
                                    ?>
                                    <?php if ($is_draft_norm): ?>
                                        <?php if (can_post_expense($expense)): ?>
                                            <button class="btn btn-sm btn-success me-1 text-white" onclick="postExpense(<?php echo $expense['id']; ?>)" title="ترحيل">
                                                <i class="fas fa-upload"></i> ترحيل
                                            </button>
                                        <?php endif; ?>
                                        <?php if (can_edit_expense($expense)): ?>
                                            <button class="btn btn-sm btn-outline-primary me-1 edit-expense-btn" data-id="<?php echo $expense['id']; ?>" title="تعديل">
                                                <i class="fas fa-edit"></i> تعديل
                                            </button>
                                        <?php endif; ?>
                                        <?php if (can_delete_expense($expense)): ?>
                                            <button class="btn btn-sm btn-outline-danger" onclick="deleteExpense(<?php echo $expense['id']; ?>)" title="حذف">
                                                <i class="fas fa-trash"></i> حذف
                                            </button>
                                        <?php endif; ?>
                                    <?php elseif ($is_posted_norm): ?>
                                        <?php if (can_unpost_expense($expense)): ?>
                                            <button class="btn btn-sm btn-warning me-1 text-white" onclick="unpostExpense(<?php echo $expense['id']; ?>)" title="سحب الترحيل">
                                                <i class="fas fa-undo"></i> إلغاء الترحيل
                                            </button>
                                        <?php endif; ?>
                                        <?php if (can_delete_expense($expense)): ?>
                                            <button class="btn btn-sm btn-outline-danger" onclick="deleteExpense(<?php echo $expense['id']; ?>)" title="حذف">
                                                <i class="fas fa-trash"></i> حذف
                                            </button>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <?php
                                        if (can_edit_expense($expense)): ?>
                                            <button class="btn btn-sm btn-outline-primary me-1 edit-expense-btn" data-id="<?php echo $expense['id']; ?>" title="تعديل">
                                                <i class="fas fa-edit"></i> تعديل
                                            </button>
                                        <?php endif;
                                        if (can_delete_expense($expense)): ?>
                                            <button class="btn btn-sm btn-outline-danger" onclick="deleteExpense(<?php echo $expense['id']; ?>)" title="حذف">
                                                <i class="fas fa-trash"></i> حذف
                                            </button>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($expenses)): ?>
                            <tr>
                                <td colspan="8" class="text-center py-5 text-muted">لا توجد مصروفات مسجلة.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Modal إضافة مصروف -->
<div class="modal fade" id="addExpenseModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content border-0 shadow">
            <form method="POST">
                <?php echo csrf_input(); ?>
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title"><i class="fas fa-plus-circle me-2"></i> إضافة مصروف جديد</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">التاريخ</label>
                            <input type="date" name="expense_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">فئة المصروف</label>
                            <select name="category_id" id="add_category_id" class="form-select" required onchange="syncExpenseAccountFromCategory(this.value, 'add_expense_account_id')">
                                <option value="">اختر الفئة</option>
                                <?php foreach ($expense_categories as $category): ?>
                                    <option value="<?php echo $category['id']; ?>" data-account="<?php echo (int)$category['account_id']; ?>"><?php echo htmlspecialchars($category['category_name_ar']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">حساب المصروف (المستلم للمبلغ)</label>
                            <select name="expense_account_id" id="add_expense_account_id" class="form-select" required>
                                <option value="">اختر حساب المصروف</option>
                                <?php foreach ($expense_accounts as $acc): ?>
                                    <option value="<?php echo (int)$acc['id']; ?>"><?php echo htmlspecialchars($acc['account_code'] . ' - ' . $acc['account_name_ar']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">المبلغ</label>
                            <input type="number" step="0.01" name="amount" class="form-control" placeholder="أدخل المبلغ" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">العملة</label>
                            <select name="currency_id" class="form-select" required>
                                <option value="">اختر العملة</option>
                                <?php foreach ($currencies as $currency): ?>
                                    <option value="<?php echo $currency['id']; ?>"><?php echo h($currency['currency_name']) . ' (' . h($currency['currency_code']) . ')'; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">طريقة الدفع</label>
                            <select name="payment_method" class="form-select" required onchange="togglePaidFromAccount(this.value)">
                                <option value="">اختر طريقة الدفع</option>
                                <option value="cash">نقدي</option>
                                <option value="bank_transfer">تحويل بنكي</option>
                                <option value="check">شيك</option>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3" id="paid_from_account_div">
                            <label class="form-label fw-bold">الحساب المدفوع منه (صندوق/بنك)</label>
                            <select name="paid_from_account_id" id="paid_from_account_id" class="form-select">
                                <option value="">اختر حساب الدفع</option>
                                <?php foreach ($payment_accounts as $account): ?>
                                    <option value="<?php echo $account['id']; ?>" data-type="<?php echo h($account['derived_type']); ?>"><?php echo h($account['account_code']) . ' - ' . h($account['account_name_ar']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12 mb-3">
                            <label class="form-label fw-bold">الوصف</label>
                            <textarea name="description" class="form-control" rows="2" placeholder="وصف موجز للمصروف"></textarea>
                        </div>
                        <div class="col-12 mb-3">
                            <label class="form-label fw-bold">ملاحظات</label>
                            <textarea name="notes" class="form-control" rows="2" placeholder="ملاحظات إضافية"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" name="add_expense" class="btn btn-primary px-4">حفظ</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal تعديل مصروف (ديناميكي) -->
<div class="modal fade" id="editExpenseModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content border-0 shadow">
            <form method="POST">
                <?php echo csrf_input(); ?>
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title"><i class="fas fa-edit me-2"></i> تعديل مصروف</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4" id="editExpenseModalContent">
                    <input type="hidden" name="id" id="edit_expense_id">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">التاريخ</label>
                            <input type="date" name="expense_date" id="edit_expense_date" class="form-control" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">فئة المصروف</label>
                            <select name="category_id" id="edit_expense_category_id" class="form-select" required onchange="syncExpenseAccountFromCategory(this.value, 'edit_expense_account_id')">
                                <option value="">اختر الفئة</option>
                                <?php foreach ($expense_categories as $category): ?>
                                    <option value="<?php echo $category['id']; ?>" data-account="<?php echo (int)$category['account_id']; ?>"><?php echo htmlspecialchars($category['category_name_ar']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">حساب المصروف (المستلم للمبلغ)</label>
                            <select name="expense_account_id" id="edit_expense_account_id" class="form-select" required>
                                <option value="">اختر حساب المصروف</option>
                                <?php foreach ($expense_accounts as $acc): ?>
                                    <option value="<?php echo (int)$acc['id']; ?>"><?php echo htmlspecialchars($acc['account_code'] . ' - ' . $acc['account_name_ar']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">المبلغ</label>
                            <input type="number" step="0.01" name="amount" id="edit_expense_amount" class="form-control" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">العملة</label>
                            <select name="currency_id" id="edit_expense_currency_id" class="form-select" required>
                                <option value="">اختر العملة</option>
                                <?php foreach ($currencies as $currency): ?>
                                    <option value="<?php echo $currency['id']; ?>"><?php echo h($currency['currency_name']) . ' (' . h($currency['currency_code']) . ')'; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">طريقة الدفع</label>
                            <select name="payment_method" id="edit_expense_payment_method" class="form-select" required onchange="togglePaidFromAccount(this.value, 'edit')">
                                <option value="">اختر طريقة الدفع</option>
                                <option value="cash">نقدي</option>
                                <option value="bank_transfer">تحويل بنكي</option>
                                <option value="check">شيك</option>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3" id="edit_paid_from_account_div">
                            <label class="form-label fw-bold">الحساب المدفوع منه (صندوق/بنك)</label>
                            <select name="paid_from_account_id" id="edit_expense_paid_from_account_id" class="form-select">
                                <option value="">اختر حساب الدفع</option>
                                <?php foreach ($payment_accounts as $account): ?>
                                    <option value="<?php echo $account['id']; ?>" data-type="<?php echo h($account['derived_type']); ?>"><?php echo h($account['account_code']) . ' - ' . h($account['account_name_ar']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12 mb-3">
                            <label class="form-label fw-bold">الوصف</label>
                            <textarea name="description" id="edit_expense_description" class="form-control" rows="2"></textarea>
                        </div>
                        <div class="col-12 mb-3">
                            <label class="form-label fw-bold">ملاحظات</label>
                            <textarea name="notes" id="edit_expense_notes" class="form-control" rows="2"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" name="update_expense" class="btn btn-primary px-4">حفظ التغييرات</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once 'footer.php'; ?>

<script>
    const EXPENSE_CSRF = '<?php echo $_SESSION['csrf_token']; ?>';

    window.postExpense = function(id) {
        Swal.fire({
            title: 'هل تريد ترحيل هذا المصروف؟',
            text: "بمجرد الترحيل، سيتم تحديث أرصدة الحسابات.",
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#198754',
            cancelButtonColor: '#3085d6',
            confirmButtonText: 'نعم، ترحيل',
            cancelButtonText: 'تراجع'
        }).then((result) => {
            if (result.isConfirmed) {
                $.ajax({
                    url: 'expenses.php',
                    type: 'POST',
                    data: {
                        post_expense: '1',
                        id: id,
                        csrf_token: EXPENSE_CSRF
                    },
                    dataType: 'json',
                    success: function(res) {
                        if (res && res.success === false) {
                            Swal.fire('خطأ!', res.message || 'فشل ترحيل المصروف.', 'error');
                        } else {
                            Swal.fire('تم الترحيل!', 'تم ترحيل المصروف وتحديث الأرصدة بنجاح.', 'success').then(() => location.reload());
                        }
                    },
                    error: function(xhr) {
                        if (xhr.status === 200) {
                            location.reload();
                        } else {
                            Swal.fire('خطأ!', 'حدث خطأ في الاتصال بالخادم.', 'error');
                        }
                    }
                });
            }
        });
    };

    window.unpostExpense = function(id) {
        Swal.fire({
            title: 'هل تريد سحب ترحيل هذا المصروف؟',
            text: "سيتم عكس القيد المحاسبي وإرجاع المصروف إلى حالة مسودة.",
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#ffc107',
            cancelButtonColor: '#3085d6',
            confirmButtonText: 'نعم، سحب الترحيل',
            cancelButtonText: 'تراجع',
            input: 'text',
            inputPlaceholder: 'أدخل سبب سحب الترحيل...',
            inputValidator: (value) => {
                if (!value) return 'يجب إدخال سبب!';
            }
        }).then((result) => {
            if (result.isConfirmed) {
                $.ajax({
                    url: 'expenses.php',
                    type: 'POST',
                    data: {
                        unpost_expense: '1',
                        id: id,
                        csrf_token: EXPENSE_CSRF,
                        reason: result.value
                    },
                    dataType: 'json',
                    success: function(res) {
                        if (res && res.success === false) {
                            Swal.fire('خطأ!', res.message || 'فشل سحب الترحيل.', 'error');
                        } else {
                            Swal.fire('تم!', 'تم سحب ترحيل المصروف بنجاح.', 'success').then(() => location.reload());
                        }
                    },
                    error: function(xhr) {
                        if (xhr.status === 200) {
                            location.reload();
                        } else {
                            Swal.fire('خطأ!', 'حدث خطأ في الاتصال بالخادم.', 'error');
                        }
                    }
                });
            }
        });
    };

    window.deleteExpense = function(id) {
        Swal.fire({
            title: 'حذف المصروف نهائياً؟',
            text: "لا يمكن التراجع عن هذه العملية!",
            icon: 'error',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            cancelButtonColor: '#3085d6',
            confirmButtonText: 'نعم، احذف',
            cancelButtonText: 'تراجع'
        }).then((result) => {
            if (result.isConfirmed) {
                window.location.href = 'expenses.php?delete=' + id + '&csrf_token=' + EXPENSE_CSRF;
            }
        });
    };

    // حفظ الخيارات الأصلية للحسابات (الصناديق + البنوك) لاستخدامها في إعادة الفلترة
    var originalAddOptions = [];
    var originalEditOptions = [];

    document.addEventListener('DOMContentLoaded', function() {
        var addSelect = document.getElementById('paid_from_account_id');
        var editSelect = document.getElementById('edit_expense_paid_from_account_id');
        if (addSelect) originalAddOptions = Array.from(addSelect.querySelectorAll('option'));
        if (editSelect) originalEditOptions = Array.from(editSelect.querySelectorAll('option'));
    });

    function filterPaymentAccounts(selectEl, originalOptions, accountType) {
        var currentValue = selectEl.value;
        selectEl.innerHTML = '';

        originalOptions.forEach(function(opt) {
            var optType = opt.getAttribute('data-type');
            if (opt.value === '' || optType === accountType) {
                var newOpt = document.createElement('option');
                newOpt.value = opt.value;
                newOpt.textContent = opt.textContent;
                if (opt.getAttribute('data-type')) {
                    newOpt.setAttribute('data-type', opt.getAttribute('data-type'));
                }
                if (opt.value === currentValue) {
                    newOpt.selected = true;
                }
                selectEl.appendChild(newOpt);
            }
        });

        // إذا كانت القيمة المختارة غير موجودة بعد الفلترة، أفرغها
        var stillExists = Array.from(selectEl.options).some(function(o) {
            return o.value === currentValue;
        });
        if (!stillExists) {
            selectEl.value = '';
        }
    }

    function togglePaidFromAccount(paymentMethod, mode = 'add') {
        const divId = (mode === 'add') ? 'paid_from_account_div' : 'edit_paid_from_account_div';
        const selectId = (mode === 'add') ? 'paid_from_account_id' : 'edit_expense_paid_from_account_id';
        const paidFromAccountDiv = document.getElementById(divId);
        const paidFromAccountSelect = document.getElementById(selectId);
        const originalOpts = (mode === 'add') ? originalAddOptions : originalEditOptions;

        if (paymentMethod === 'cash') {
            paidFromAccountDiv.style.display = 'block';
            paidFromAccountSelect.setAttribute('required', 'required');
            // فلترة الخيارات لإظهار الصناديق فقط
            filterPaymentAccounts(paidFromAccountSelect, originalOpts, 'box');
        } else if (paymentMethod === 'bank_transfer') {
            paidFromAccountDiv.style.display = 'block';
            paidFromAccountSelect.setAttribute('required', 'required');
            // فلترة الخيارات لإظهار البنوك فقط
            filterPaymentAccounts(paidFromAccountSelect, originalOpts, 'bank');
        } else {
            paidFromAccountDiv.style.display = 'none';
            paidFromAccountSelect.removeAttribute('required');
            paidFromAccountSelect.value = '';
        }
    }

    // مزامنة اختيار الفئة مع تعيين حساب المصروف تلقائياً إن كان للفئة حساب مرتبط
    function syncExpenseAccountFromCategory(categoryId, targetSelectId) {
        var targetSelect = document.getElementById(targetSelectId);
        if (!targetSelect) return;

        if (!categoryId) {
            targetSelect.value = '';
            return;
        }

        var categorySelect = targetSelectId.indexOf('edit_') === 0 ?
            document.getElementById('edit_expense_category_id') :
            document.getElementById('add_category_id');

        var selectedOpt = categorySelect.querySelector('option[value="' + categoryId + '"]');
        if (selectedOpt) {
            var accountId = selectedOpt.getAttribute('data-account');
            if (accountId && accountId !== '0') {
                var accountExists = targetSelect.querySelector('option[value="' + accountId + '"]');
                if (accountExists) {
                    targetSelect.value = accountId;
                    return;
                }
            }
        }
        // إن لم يكن هناك حساب مرتبط بالفئة، أبقِ القيمة الحالية للمستخدم يختار يدوياً
    }

    $(document).ready(function() {
        // Event listener for add modal payment method change
        $('select[name="payment_method"]').on('change', function() {
            togglePaidFromAccount(this.value, 'add');
        });

        // Event listener for edit modal payment method change
        $('#edit_expense_payment_method').on('change', function() {
            togglePaidFromAccount(this.value, 'edit');
        });

        $('.edit-expense-btn').on('click', function() {
            var expenseId = $(this).data('id');
            $.ajax({
                url: 'ajax_get_expense.php',
                type: 'GET',
                data: {
                    id: expenseId
                },
                dataType: 'json',
                success: function(expense) {
                    if (expense) {
                        $('#edit_expense_id').val(expense.id);
                        $('#edit_expense_date').val(expense.expense_date);
                        $('#edit_expense_category_id').val(expense.category_id);
                        $('#edit_expense_amount').val(expense.amount);
                        $('#edit_expense_currency_id').val(expense.currency_id);
                        $('#edit_expense_description').val(expense.description);
                        $('#edit_expense_notes').val(expense.notes);
                        $('#edit_expense_payment_method').val(expense.payment_method);
                        $('#edit_expense_paid_from_account_id').val(expense.paid_from_account_id);

                        // تعيين حساب المصروف إن وجد (الأولوية للعمود الجديد)
                        var targetAccountId = expense.expense_account_id || null;
                        if (targetAccountId) {
                            $('#edit_expense_account_id').val(targetAccountId);
                        } else {
                            // احتياطي: مزامنة من الفئة
                            syncExpenseAccountFromCategory(expense.category_id, 'edit_expense_account_id');
                        }

                        togglePaidFromAccount(expense.payment_method, 'edit');
                        // بعد الفلترة، أعِد تعيين القيمة المختارة للتأكد
                        setTimeout(function() {
                            $('#edit_expense_paid_from_account_id').val(expense.paid_from_account_id);
                        }, 10);

                        $('#editExpenseModal').modal('show');
                    } else {
                        alert('المصروف غير موجود.');
                    }
                },
                error: function(xhr, status, error) {
                    console.error(xhr.responseText);
                    alert('حدث خطأ أثناء جلب بيانات المصروف.');
                }
            });
        });
    });
</script>
