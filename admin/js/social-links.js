// قائمة المنصات الاجتماعية مع الأيقونات
const socialPlatformsData = {
    'Facebook': 'fab fa-facebook',
    'Twitter': 'fab fa-twitter',
    'Instagram': 'fab fa-instagram',
    'LinkedIn': 'fab fa-linkedin',
    'YouTube': 'fab fa-youtube',
    'TikTok': 'fab fa-tiktok',
    'WhatsApp': 'fab fa-whatsapp',
    'Telegram': 'fab fa-telegram',
    'Snapchat': 'fab fa-snapchat',
    'Pinterest': 'fab fa-pinterest',
    'Reddit': 'fab fa-reddit',
    'Discord': 'fab fa-discord'
};

// دالة تحديث الأيقونة عند اختيار المنصة
function updateSocialIcon(selectElement) {
    const selectedValue = selectElement.value;
    const socialItem = selectElement.closest('.social-link-item');
    const iconInput = socialItem.querySelector('.social-icon-input');
    const iconPreview = socialItem.querySelector('.social-icon-preview i');
    
    if(selectedValue && socialPlatformsData[selectedValue]) {
        const iconClass = socialPlatformsData[selectedValue];
        iconInput.value = iconClass;
        iconPreview.className = iconClass;
    } else if(selectedValue === 'Custom') {
        iconInput.value = '';
        iconPreview.className = 'fas fa-link';
    }
}

// دالة إضافة رابط تواصل جديد
function addSocialLink() {
    const container = document.getElementById('social_links_container');
    const platformOptions = Object.keys(socialPlatformsData).map(platform => 
        `<option value="${platform}">${platform}</option>`
    ).join('');
    
    const html = `
        <div class="social-link-item">
            <div class="row g-3">
                <div class="col-md-3">
                    <label class="form-label">المنصة</label>
                    <select name="social_platforms[]" class="form-select platform-select" onchange="updateSocialIcon(this)">
                        <option value="">-- اختر المنصة --</option>
                        ${platformOptions}
                        <option value="Custom">مخصص</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">الرابط</label>
                    <input type="url" name="social_urls[]" class="form-control" placeholder="https://...">
                </div>
                <div class="col-md-3">
                    <label class="form-label">الأيقونة <span class="social-icon-preview" style="margin-right: 10px; font-size: 1.3rem;"><i class="fas fa-link"></i></span></label>
                    <input type="text" name="social_icons[]" class="form-control social-icon-input" placeholder="fab fa-facebook">
                </div>
                <div class="col-md-2 d-flex align-items-end">
                    <button type="button" class="btn btn-sm btn-danger w-100 btn-remove" onclick="this.closest('.social-link-item').remove();">
                        <i class="fas fa-trash me-1"></i> حذف
                    </button>
                </div>
            </div>
        </div>
    `;
    container.insertAdjacentHTML('beforeend', html);
}

// تهيئة الأيقونات عند تحميل الصفحة
document.addEventListener('DOMContentLoaded', function() {
    // تهيئة الأيقونات للعناصر الموجودة
    document.querySelectorAll('.platform-select').forEach(select => {
        const selectedValue = select.value;
        if(selectedValue && socialPlatformsData[selectedValue]) {
            const socialItem = select.closest('.social-link-item');
            const iconInput = socialItem.querySelector('.social-icon-input');
            const iconPreview = socialItem.querySelector('.social-icon-preview i');
            const iconClass = socialPlatformsData[selectedValue];
            if(!iconInput.value) {
                iconInput.value = iconClass;
            }
            iconPreview.className = iconInput.value || 'fas fa-link';
        }
    });
    
    // تحديث الأيقونة عند تغيير حقل الأيقونة يدويًا
    document.querySelectorAll('.social-icon-input').forEach(input => {
        input.addEventListener('change', function() {
            const preview = this.closest('.social-link-item').querySelector('.social-icon-preview i');
            preview.className = this.value || 'fas fa-link';
        });
    });
});
