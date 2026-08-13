-- توحيد تسميات ملخص الإلغاء المالي.
UPDATE workflow_fields SET field_label = 'نسبة الخصم %' WHERE field_key = 'discount_percent';
UPDATE workflow_fields SET field_label = 'المبلغ الصافي' WHERE field_key = 'discount_amount';
UPDATE workflow_fields SET field_label = 'المبلغ' WHERE field_key = 'net_amount';
