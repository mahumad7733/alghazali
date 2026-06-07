/**
 * receipts-actions.js
 * ملف JavaScript مستقل لإجراءات سندات القبض
 * يُحل محل الدوال المشوهة في receipts.php
 */

// انتظر تحميل الصفحة
$(document).ready(function () {

    // ===== إعادة تعريف جميع الدوال الرئيسية =====

    // دالة عرض تفاصيل السند
    window.viewVoucher = function (id) {
        $('#viewContent').html('<div class="text-center p-5"><div class="spinner-border text-primary"></div><p class="mt-2">جاري التحميل...</p></div>');
        $('#viewModal').modal('show');

        $.ajax({
            url: 'ajax/get_voucher_details.php',
            type: 'GET',
            data: { id: id },
            dataType: 'json',
            success: function (v) {
                if (!v) {
                    $('#viewContent').html('<div class="alert alert-danger">لم يتم العثور على السند.</div>');
                    return;
                }

                var statusLabel = { draft: '📝 مسودة', posted: '✅ مرحّل', cancelled: '❌ ملغي' };
                var statusBg = { draft: 'bg-draft', posted: 'bg-posted', cancelled: 'bg-cancelled' };
                var entityMap = { customer: 'عميل', agent: 'وكيل', supplier: 'مورد', employee: 'موظف', branch: 'فرع', expense: 'حساب إيراد/آخر' };

                var allocationsHtml = '';
                if (v.allocations && v.allocations.length > 0) {
                    var totalAlloc = v.allocations.reduce(function (acc, a) { return acc + parseFloat(a.allocated_amount); }, 0);
                    var remaining = parseFloat(v.amount) - totalAlloc;

                    allocationsHtml = '<div class="mb-4">';
                    allocationsHtml += '<h6 class="fw-bold mb-3"><i class="fas fa-file-invoice me-2"></i>توزيع السند على الفواتير</h6>';
                    allocationsHtml += '<table class="table table-sm small"><thead><tr><th>رقم الفاتورة</th><th>التاريخ</th><th>المبلغ المخصص</th></tr></thead><tbody>';
                    v.allocations.forEach(function (a) {
                        allocationsHtml += '<tr><td>' + a.invoice_number + '</td><td>' + a.invoice_date + '</td><td class="fw-bold text-primary">' + parseFloat(a.allocated_amount).toLocaleString() + '</td></tr>';
                    });
                    allocationsHtml += '</tbody><tfoot><tr class="table-light fw-bold"><td colspan="2">إجمالي الموزع</td><td>' + totalAlloc.toLocaleString() + '</td></tr>';
                    if (remaining > 0.01) {
                        allocationsHtml += '<tr class="table-info"><td colspan="2">المتبقي (رصيد في الحساب)</td><td>' + remaining.toLocaleString() + '</td></tr>';
                    }
                    allocationsHtml += '</tfoot></table></div>';
                } else {
                    allocationsHtml = '<div class="alert alert-info small mb-4"><i class="fas fa-info-circle me-2"></i>هذا السند دفعة على الحساب (غير مخصص لفواتير محددة).</div>';
                }

                var auditHtml = '';
                if (v.audit_logs && v.audit_logs.length > 0) {
                    auditHtml = '<div class="mb-4"><h6 class="fw-bold mb-3"><i class="fas fa-history me-2"></i>سجل العملية</h6><div class="timeline-small">';
                    v.audit_logs.forEach(function (log) {
                        var d = new Date(log.created_at);
                        var actionColors = { create: 'primary', update: 'warning', post: 'success', cancel: 'danger', delete: 'dark' };
                        var color = actionColors[log.action_type] || 'secondary';
                        auditHtml += '<div class="log-item mb-2 p-2 bg-light rounded-3 border-start border-' + color + ' border-3">';
                        auditHtml += '<div class="d-flex justify-content-between align-items-center mb-1">';
                        auditHtml += '<span class="fw-bold small text-' + color + '">' + (log.action_type || '').toUpperCase() + '</span>';
                        auditHtml += '<span class="extra-small text-muted"><i class="far fa-clock me-1"></i>' + d.toLocaleString('ar-YE') + '</span>';
                        auditHtml += '</div><div class="small"><i class="fas fa-user me-1"></i>' + (log.user_name || '') + '</div>';
                        if (log.reason) auditHtml += '<div class="extra-small text-danger mt-1">السبب: ' + log.reason + '</div>';
                        auditHtml += '</div>';
                    });
                    auditHtml += '</div></div>';
                }

                var metaHtml = '<div class="bg-light p-3 rounded-4 small mb-4"><div class="row">';
                metaHtml += '<div class="col-md-6 mb-2"><strong>أنشئ بواسطة:</strong> ' + (v.creator_name || '---') + ' في ' + (v.created_at || '') + '</div>';
                if (v.posted_at) metaHtml += '<div class="col-md-6 mb-2"><strong>رُحِّل بواسطة:</strong> ' + (v.poster_name || '---') + ' في ' + v.posted_at + '</div>';
                if (v.cancelled_at) metaHtml += '<div class="col-md-6"><strong>أُلغي بواسطة:</strong> ' + (v.canceller_name || '---') + ' في ' + v.cancelled_at + '<br><strong>السبب:</strong> ' + (v.cancellation_reason || '') + '</div>';
                metaHtml += '</div></div>';

                var html = '';
                html += '<div class="d-flex justify-content-between align-items-start mb-4">';
                html += '<div><h4 class="fw-bold mb-1">' + v.transaction_number + '</h4>';
                html += '<p class="text-muted small">تاريخ السند: ' + v.transaction_date + '</p></div>';
                html += '<span class="apple-badge ' + (statusBg[v.status] || '') + '">' + (statusLabel[v.status] || v.status) + '</span>';
                html += '</div>';

                html += '<div class="row g-4 mb-4">';
                html += '<div class="col-md-6"><label class="text-muted small d-block mb-1">الدافع</label>';
                html += '<div class="fw-bold">' + (v.party_name || '---') + ' <span class="text-muted small">(' + (entityMap[v.entity_type] || v.entity_type) + ')</span></div></div>';
                html += '<div class="col-md-6"><label class="text-muted small d-block mb-1">الحساب المستلِم</label>';
                html += '<div class="fw-bold">' + (v.account_name || '---') + '</div></div>';
                html += '<div class="col-md-6"><label class="text-muted small d-block mb-1">المبلغ</label>';
                html += '<div class="fw-bold text-primary fs-4">' + parseFloat(v.amount).toLocaleString() + ' ' + (v.currency_symbol || '') + '</div></div>';
                html += '<div class="col-md-6"><label class="text-muted small d-block mb-1">البيان</label>';
                html += '<div class="fw-bold">' + (v.description || '---') + '</div></div>';
                html += '</div>';

                html += allocationsHtml;
                html += auditHtml;
                html += metaHtml;

                html += '<div class="text-center">';
                html += '<a href="print_receipt.php?id=' + v.id + '" target="_blank" class="btn btn-outline-primary rounded-pill px-4 me-2"><i class="fas fa-print me-2"></i>طباعة</a>';
                html += '<button class="btn btn-dark rounded-pill px-5" data-bs-dismiss="modal">إغلاق</button>';
                html += '</div>';

                $('#viewContent').html(html);
            },
            error: function (xhr, status, error) {
                $('#viewContent').html('<div class="alert alert-danger"><strong>خطأ في تحميل البيانات:</strong><br>' + (xhr.responseText || error) + '</div>');
                console.error('viewVoucher error:', xhr.responseText);
            }
        });
    };

    // دالة ترحيل السند
    window.postVoucher = function (id) {
        if (!confirm('هل أنت متأكد من ترحيل هذا السند؟\nلا يمكن التعديل بعد الترحيل.')) return;

        $.ajax({
            url: 'ajax/post_voucher.php',
            type: 'POST',
            data: { 
                id: id,
                csrf_token: typeof CSRF_TOKEN !== 'undefined' ? CSRF_TOKEN : ''
            },
            dataType: 'json',
            success: function (res) {
                if (res.success) {
                    location.reload();
                } else {
                    alert('فشل الترحيل: ' + (res.message || 'خطأ غير معروف'));
                }
            },
            error: function (xhr) {
                alert('خطأ في الاتصال. حاول مرة أخرى.\n' + (xhr.responseText ? xhr.responseText.substring(0, 200) : ''));
                console.error('postVoucher error:', xhr.status, xhr.responseText);
            }
        });
    };

    // دالة عكس الترحيل (إلغاء سند مرحّل)
    window.cancelVoucher = function (id) {
        var reason = prompt('يرجى ذكر سبب الإلغاء:');
        if (!reason) return;

        $.ajax({
            url: 'ajax/reverse_voucher.php',
            type: 'POST',
            data: { 
                id: id, 
                reason: reason,
                csrf_token: typeof CSRF_TOKEN !== 'undefined' ? CSRF_TOKEN : ''
            },
            dataType: 'json',
            success: function (res) {
                if (res.success) {
                    location.reload();
                } else {
                    alert('فشل الإلغاء: ' + (res.message || 'خطأ غير معروف'));
                }
            },
            error: function (xhr) {
                alert('خطأ في الاتصال. حاول مرة أخرى.');
                console.error('cancelVoucher error:', xhr.responseText);
            }
        });
    };

    // دالة حذف السند
    window.deleteVoucher = function (id) {
        if (!confirm('هل أنت متأكد من حذف هذا السند نهائياً؟\nلا يمكن التراجع عن هذه العملية.')) return;

        $.ajax({
            url: 'ajax/delete_voucher.php',
            type: 'POST',
            data: { 
                id: id,
                csrf_token: typeof CSRF_TOKEN !== 'undefined' ? CSRF_TOKEN : ''
            },
            dataType: 'json',
            success: function (res) {
                if (res.success) {
                    location.reload();
                } else {
                    alert('فشل الحذف: ' + (res.message || 'خطأ غير معروف'));
                }
            },
            error: function (xhr) {
                alert('خطأ في الاتصال. حاول مرة أخرى.');
                console.error('deleteVoucher error:', xhr.responseText);
            }
        });
    };

    // دالة تعديل السند - نفس الدالة القديمة لكن مُعرَّفة بشكل صحيح
    // يتم استدعاؤها من receipts.php المعرّفة أصلاً هناك
    // لكن نضيف تحسيناً: إذا فشلت، نعيد تعريفها
    var originalEditVoucher = window.editVoucher;
    window.editVoucher = function (id) {
        if (typeof originalEditVoucher === 'function') {
            try {
                originalEditVoucher(id);
            } catch (e) {
                console.warn('editVoucher original failed, using fallback:', e);
                fallbackEditVoucher(id);
            }
        } else {
            fallbackEditVoucher(id);
        }
    };

    function fallbackEditVoucher(id) {
        $.ajax({
            url: 'ajax/get_voucher_details.php',
            type: 'GET',
            data: { id: id },
            dataType: 'json',
            success: function (v) {
                if (!v) { alert('لم يتم العثور على السند.'); return; }

                // Reset form
                $('#voucherForm')[0].reset();
                $('#edit_receipt_id').val(v.id);
                $('#modalTitle').text('تعديل سند قبض: ' + v.transaction_number);
                $('#date').val(v.transaction_date);
                $('#payer_type').val(v.entity_type).trigger('change');
                $('#amount').val(v.amount);
                $('#description').val(v.description || '');

                setTimeout(function () {
                    $('#payer_id').val(v.party_account_id);
                    $('#currency_id').val(v.currency_id);
                    $('#account_id').val(v.cash_bank_account_id);

                    if (v.allocations && v.allocations.length > 0) {
                        v.allocations.forEach(function (alloc) {
                            var checkbox = $('.invoice-checkbox[data-id="' + alloc.invoice_id + '"]');
                            if (checkbox.length) {
                                checkbox.prop('checked', true);
                                $('input[name="allocations[' + alloc.invoice_id + ']"]').val(alloc.allocated_amount).prop('disabled', false);
                            }
                        });
                    }
                }, 500);

                $('#receiptModal').modal('show');
            },
            error: function (xhr) {
                alert('خطأ في تحميل بيانات السند.');
            }
        });
    }

    console.log('✅ receipts-actions.js loaded successfully');
});
