// بيانات الحالات والحقول المفعلة - سيتم تعبئتها من PHP
let statusFields = {};

// دالة تحديث الحقول في نموذج الإضافة
function updateAddFormFields() {
    const statusId = document.getElementById('addStatusSelect').value;
    const fieldMapping = {
        'show_office_name': 'field_office_name',
        'show_received_date': 'field_received_date',
        'show_sent_to_embassy_date': 'field_sent_to_embassy_date',
        'show_embassy_exit_date': 'field_embassy_exit_date',
        'show_transport_delivery_date': 'field_transport_delivery_date',
        'show_delivery_date': 'field_delivery_date',
        'show_cancellation_date': 'field_cancellation_date',
        'show_cancellation_reason': 'field_cancellation_reason',
        'show_visa_number': 'field_visa_number',
        'show_visa_issue_date': 'field_visa_issue_date'
    };
    
    // إخفاء جميع الحقول أولاً
    Object.values(fieldMapping).forEach(fieldId => {
        const elem = document.getElementById(fieldId);
        if(elem) elem.style.display = 'none';
    });
    
    // إظهار الحقول المفعلة فقط
    if(statusId && statusFields[statusId]) {
        Object.keys(fieldMapping).forEach(key => {
            if(statusFields[statusId][key]) {
                const elem = document.getElementById(fieldMapping[key]);
                if(elem) elem.style.display = '';
            }
        });
    }
}

// دالة تحديث الحقول في نموذج التعديل
function updateEditFormFields(statusId, modalId) {
    const fieldMapping = {
        'show_office_name': 'field_office_name_' + modalId,
        'show_received_date': 'field_received_date_' + modalId,
        'show_sent_to_embassy_date': 'field_sent_to_embassy_date_' + modalId,
        'show_embassy_exit_date': 'field_embassy_exit_date_' + modalId,
        'show_transport_delivery_date': 'field_transport_delivery_date_' + modalId,
        'show_delivery_date': 'field_delivery_date_' + modalId,
        'show_cancellation_date': 'field_cancellation_date_' + modalId,
        'show_cancellation_reason': 'field_cancellation_reason_' + modalId,
        'show_visa_number': 'field_visa_number_' + modalId,
        'show_visa_issue_date': 'field_visa_issue_date_' + modalId
    };
    
    // إخفاء جميع الحقول أولاً
    Object.values(fieldMapping).forEach(fieldId => {
        const elem = document.getElementById(fieldId);
        if(elem) elem.style.display = 'none';
    });
    
    // إظهار الحقول المفعلة فقط
    if(statusId && statusFields[statusId]) {
        Object.keys(fieldMapping).forEach(key => {
            if(statusFields[statusId][key]) {
                const elem = document.getElementById(fieldMapping[key]);
                if(elem) elem.style.display = '';
            }
        });
    }
}

// البحث الديناميكي
document.addEventListener('DOMContentLoaded', function() {
    const searchInput = document.getElementById('dynamicSearch');
    if(searchInput) {
        searchInput.addEventListener('keyup', function() {
            let filter = this.value.toLowerCase();
            let rows = document.querySelectorAll('.passport-row');
            rows.forEach(row => {
                let name = row.querySelector('.passport-name').textContent.toLowerCase();
                let number = row.querySelector('.passport-number').textContent.toLowerCase();
                if (name.includes(filter) || number.includes(filter)) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
        });
    }
});

// تهيئة بيانات الحالات عند تحميل الصفحة
function initializeStatusFields(data) {
    statusFields = data;
}
