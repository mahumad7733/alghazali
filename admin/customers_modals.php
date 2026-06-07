<!-- Modal تعديل العميل -->
<div class="modal fade" id="editCustomerModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content border-0 shadow">
            <form method="POST">
                <?php echo csrf_input(); ?>
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title"><i class="fas fa-user-edit me-2"></i> تعديل بيانات العميل</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <input type="hidden" name="id" id="edit_customer_id">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">اسم العميل</label>
                            <input type="text" name="full_name" id="edit_customer_full_name" class="form-control" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">الهاتف</label>
                            <input type="text" name="phone" id="edit_customer_phone" class="form-control">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">واتساب</label>
                            <input type="text" name="whatsapp" id="edit_customer_whatsapp" class="form-control">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">الجنسية</label>
                            <input type="text" name="nationality" id="edit_customer_nationality" class="form-control">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">تاريخ بداية التعامل</label>
                            <input type="date" name="start_date" id="edit_customer_start_date" class="form-control">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">الفرع</label>
                            <select name="branch_id" id="edit_customer_branch_id" class="form-select" required>
                                <?php foreach ($branches as $branch): ?>
                                    <option value="<?php echo $branch['id']; ?>"><?php echo htmlspecialchars($branch['branch_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">الحالة</label>
                            <select name="status" id="edit_customer_status" class="form-select">
                                <option value="active">نشط</option>
                                <option value="closed">مغلق</option>
                                <option value="inactive">راكد</option>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">الحساب المحاسبي (اختياري)</label>
                            <select name="account_id" id="edit_customer_account_id" class="form-select">
                                <option value="">-- إنشاء حساب جديد تلقائياً --</option>
                                <?php foreach ($all_customer_accounts as $acc): ?>
                                    <option value="<?php echo $acc['id']; ?>">
                                        <?php echo $acc['account_code'] . ' - ' . $acc['account_name_ar']; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12 mb-3">
                            <label class="form-label fw-bold">العنوان</label>
                            <textarea name="address" id="edit_customer_address" class="form-control" rows="2"></textarea>
                        </div>
                        <div class="col-12 mb-3">
                            <label class="form-label fw-bold">ملاحظات</label>
                            <textarea name="notes" id="edit_customer_notes" class="form-control" rows="2"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" name="update_customer" class="btn btn-primary px-4">حفظ التغييرات</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal إضافة عميل -->
<div class="modal fade" id="addCustomerModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content border-0 shadow">
            <form method="POST">
                <?php echo csrf_input(); ?>
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title"><i class="fas fa-user-plus me-2"></i> إضافة عميل جديد</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">اسم العميل الكامل</label>
                            <input type="text" name="full_name" class="form-control" placeholder="أدخل اسم العميل" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">الهاتف</label>
                            <input type="text" name="phone" class="form-control" placeholder="أدخل رقم الهاتف">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">واتساب</label>
                            <input type="text" name="whatsapp" class="form-control" placeholder="أدخل رقم الواتساب">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">الجنسية</label>
                            <input type="text" name="nationality" class="form-control" placeholder="أدخل الجنسية">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">تاريخ بداية التعامل</label>
                            <input type="date" name="start_date" class="form-control" value="<?php echo date('Y-m-d'); ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">الفرع</label>
                            <select name="branch_id" class="form-select" required>
                                <?php foreach ($branches as $branch): ?>
                                    <option value="<?php echo $branch['id']; ?>"><?php echo htmlspecialchars($branch['branch_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">الحالة</label>
                            <select name="status" class="form-select">
                                <option value="active">نشط</option>
                                <option value="closed">مغلق</option>
                                <option value="inactive">راكد</option>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">الحساب المحاسبي (اختياري)</label>
                            <select name="account_id" class="form-select">
                                <option value="">-- إنشاء حساب جديد تلقائياً --</option>
                                <?php foreach ($all_customer_accounts as $acc): ?>
                                    <option value="<?php echo $acc['id']; ?>">
                                        <?php echo $acc['account_code'] . ' - ' . $acc['account_name_ar']; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12 mb-3">
                            <label class="form-label fw-bold">العنوان</label>
                            <textarea name="address" class="form-control" rows="2"></textarea>
                        </div>
                        <div class="col-12 mb-3">
                            <label class="form-label fw-bold">ملاحظات</label>
                            <textarea name="notes" class="form-control" rows="2"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" name="add_customer" class="btn btn-primary px-4">إضافة العميل</button>
                </div>
            </form>
        </div>
    </div>
</div>
