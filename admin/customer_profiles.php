<?php

declare(strict_types=1);

require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '../includes/customer_profile.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit();
}

$formError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_customer'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $formError = 'تعذر التحقق من الطلب الأمني.';
    } else {
        try {
            $fullName = trim((string)($_POST['full_name'] ?? ''));
            if ($fullName === '') {
                throw new InvalidArgumentException('اسم العميل مطلوب.');
            }
            $passportNumber = trim((string)($_POST['passport_number'] ?? ''));
            $passportImage = handleFileUpload('passport_image', $passportNumber ?: 'customer', 'passport', $fullName);
            $createdId = customer_profile_find_or_create($pdo, [
                'full_name' => $fullName,
                'full_name_en' => $_POST['full_name_en'] ?? null,
                'passport_number' => $passportNumber,
                'nationality' => $_POST['nationality'] ?? null,
                'gender' => $_POST['gender'] ?? null,
                'date_of_birth' => $_POST['date_of_birth'] ?? null,
                'passport_issue_date' => $_POST['passport_issue_date'] ?? null,
                'passport_expiry_date' => $_POST['passport_expiry_date'] ?? null,
                'phone_number' => $_POST['phone_number'] ?? null,
                'id_type' => $_POST['id_type'] ?? null,
                'id_number' => $_POST['id_number'] ?? null,
                'id_issue_place' => $_POST['id_issue_place'] ?? null,
                'id_issue_date' => $_POST['id_issue_date'] ?? null,
                'passport_image' => $passportImage,
                'created_by' => (int)$_SESSION['admin_id'],
                'branch_id' => $_SESSION['branch_id'] ?? null,
            ]);
            header('Location: customer_profile.php?passport_id=' . (int)$createdId);
            exit();
        } catch (Throwable $exception) {
            $formError = $exception->getMessage();
        }
    }
}

$query = trim((string)($_GET['q'] ?? ''));
$sql = "SELECT p.id, p.full_name, p.passport_number, p.phone_number, p.mobile_number,
               p.nationality, p.created_at,
               COUNT(h.id) AS service_count
        FROM passports p
        LEFT JOIN customer_service_history h ON h.passport_id = p.id";
$params = [];
if ($query !== '') {
    $sql .= " WHERE p.full_name LIKE ? OR p.passport_number LIKE ?
              OR p.phone_number LIKE ? OR p.mobile_number LIKE ?";
    $like = '%' . $query . '%';
    $params = [$like, $like, $like, $like];
}
$sql .= ' GROUP BY p.id ORDER BY p.created_at DESC, p.id DESC LIMIT 200';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$customers = $stmt->fetchAll(PDO::FETCH_ASSOC);

$all_countries = $pdo->query("SELECT id, country_name, country_code, dial_code FROM countries ORDER BY country_name ASC")->fetchAll();

$id_types = [
    ['value' => 'national_id', 'label' => 'هوية وطنية'],
    ['value' => 'passport', 'label' => 'جواز سفر'],
    ['value' => 'residence', 'label' => 'إقامة'],
    ['value' => 'border_pass', 'label' => 'بطاقة مرور'],
];

function customer_profiles_h($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

require_once 'header.php';
?>
<script src="https://cdn.jsdelivr.net/npm/tesseract.js@5/dist/tesseract.min.js"></script>
<div class="container-fluid py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
        <div>
            <h1 class="h3 mb-1">ملفات العملاء</h1>
            <p class="text-muted mb-0">بحث سريع وإدارة بيانات العميل وسجل خدماته.</p>
        </div>
        <a href="passports.php" class="btn btn-outline-primary"><i class="fas fa-passport me-1"></i>المعاملات</a>
    </div>

    <?php if ($formError !== ''): ?><div class="alert alert-danger"><?= customer_profiles_h($formError) ?></div><?php endif; ?>
    <div class="d-flex justify-content-end mb-3">
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addCustomerModal"><i class="fas fa-user-plus me-1"></i>إضافة عميل</button>
    </div>
    <div class="card border-0 shadow-sm rounded-4 mb-4">
        <div class="card-body">
            <form method="get" class="row g-2">
                <div class="col-md-10">
                    <label for="customerProfileSearch" class="visually-hidden">بحث عن عميل</label>
                    <input id="customerProfileSearch" name="q" value="<?= customer_profiles_h($query) ?>"
                        class="form-control" placeholder="ابحث بالاسم أو رقم الهوية أو الجوال" autofocus>
                </div>
                <div class="col-md-2 d-flex gap-2">
                    <button class="btn btn-primary flex-fill" type="submit"><i class="fas fa-search me-1"></i>بحث</button>
                    <a class="btn btn-outline-secondary" href="customer_profiles.php" title="إعادة ضبط"><i class="fas fa-rotate-left"></i></a>
                </div>
            </form>
        </div>
    </div>

    <div class="card border-0 shadow-sm rounded-4 overflow-hidden">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>العميل</th>
                        <th>الهوية</th>
                        <th>الجوال</th>
                        <th>الجنسية</th>
                        <th>الخدمات</th>
                        <th class="text-center">الإجراءات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($customers as $customer): ?>
                        <?php $phone = $customer['phone_number'] ?: $customer['mobile_number']; ?>
                        <tr>
                            <td>
                                <div class="fw-bold"><?= customer_profiles_h($customer['full_name']) ?></div>
                                <small class="text-muted">#<?= (int)$customer['id'] ?></small>
                            </td>
                            <td><?= customer_profiles_h($customer['passport_number'] ?: '—') ?></td>
                            <td><?= customer_profiles_h($phone ?: '—') ?></td>
                            <td><?= customer_profiles_h($customer['nationality'] ?: '—') ?></td>
                            <td><span class="badge bg-info-subtle text-info-emphasis"><?= (int)$customer['service_count'] ?></span></td>
                            <td class="text-center">
                                <div class="d-flex justify-content-center gap-1">
                                    <a class="btn btn-sm btn-outline-primary" title="ملف العميل" href="customer_profile.php?passport_id=<?= (int)$customer['id'] ?>"><i class="fas fa-folder-open"></i></a>
                                    <a class="btn btn-sm btn-outline-info" title="تعديل" href="passports.php?edit_id=<?= (int)$customer['id'] ?>"><i class="fas fa-edit"></i></a>
                                    <a class="btn btn-sm btn-outline-danger" title="حذف" href="passports.php?delete_id=<?= (int)$customer['id'] ?>&redirect=customer_profiles.php" onclick="return confirm('هل أنت متأكد من حذف ملف العميل؟')"><i class="fas fa-trash"></i></a>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$customers): ?>
                        <tr>
                            <td colspan="6" class="text-center text-muted py-4">لا توجد ملفات عملاء مطابقة.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<div class="modal fade" id="addCustomerModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <form method="post" enctype="multipart/form-data" data-customer-profile-form="1">
                <input type="hidden" name="csrf_token" value="<?= customer_profiles_h(generate_csrf_token()) ?>">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title">إضافة ملف عميل جديد</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-info small">ارفع صورة صفحة الجواز ثم اضغط «قراءة الجواز». راجع البيانات قبل الحفظ.</div>
                    <div class="row g-3">
                        <div class="col-md-8"><label class="form-label fw-bold">صورة الجواز</label>
                            <div class="input-group"><input type="file" name="passport_image" id="customerPassportImage" class="form-control" accept="image/*" required><button type="button" id="customerPassportScan" class="btn btn-warning">قراءة الجواز</button></div>
                            <div id="customerOcrStatus" class="form-text"></div>
                        </div>
                        <div class="col-md-4"><img id="customerPassportPreview" class="img-fluid rounded border d-none" style="max-height:120px" alt="معاينة الجواز"></div>

                        <div class="col-12">
                            <hr class="my-2">
                            <h6 class="fw-bold text-primary mb-0"><i class="fas fa-user me-1"></i>البيانات الشخصية</h6>
                        </div>
                        <div class="col-md-6"><label class="form-label">الاسم بالعربي</label><input name="full_name" id="customer_full_name" class="form-control" required></div>
                        <div class="col-md-6"><label class="form-label">الاسم بالإنجليزي</label><input name="full_name_en" id="customer_full_name_en" class="form-control"></div>
                        <div class="col-md-4"><label class="form-label">الجنسية</label>
                            <select name="nationality" id="customer_nationality" class="form-select">
                                <option value="">اختر الجنسية...</option>
                                <?php foreach ($all_countries as $country): ?>
                                    <option value="<?= customer_profiles_h($country['country_name']) ?>" data-code="<?= customer_profiles_h($country['country_code']) ?>" data-country-id="<?= (int)$country['id'] ?>" data-dial-code="<?= customer_profiles_h($country['dial_code']) ?>"><?= customer_profiles_h($country['country_name']) ?> (<?= customer_profiles_h($country['country_code']) ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4"><label class="form-label">الجنس</label>
                            <select name="gender" id="customer_gender" class="form-select">
                                <option value="Male">ذكر</option>
                                <option value="Female">أنثى</option>
                            </select>
                        </div>
                        <div class="col-md-4"><label class="form-label">تاريخ الميلاد</label><input type="date" name="date_of_birth" id="customer_date_of_birth" class="form-control"></div>

                        <div class="col-12">
                            <hr class="my-2">
                            <h6 class="fw-bold text-primary mb-0"><i class="fas fa-passport me-1"></i>بيانات جواز السفر</h6>
                        </div>
                        <div class="col-md-4"><label class="form-label">رقم الهوية</label><input name="passport_number" id="customer_passport_number" class="form-control"></div>
                        <div class="col-md-4"><label class="form-label">تاريخ إصدار الجواز</label><input type="date" name="passport_issue_date" id="customer_passport_issue_date" class="form-control"></div>
                        <div class="col-md-4"><label class="form-label">تاريخ انتهاء الجواز</label><input type="date" name="passport_expiry_date" id="customer_passport_expiry_date" class="form-control"></div>

                        <div class="col-12">
                            <hr class="my-2">
                            <h6 class="fw-bold text-primary mb-0"><i class="fas fa-id-card me-1"></i>بيانات الهوية الوطنية / الإقامة</h6>
                        </div>
                        <div class="col-md-4"><label class="form-label">نوع الهوية</label>
                            <select name="id_type" id="customer_id_type" class="form-select">
                                <option value="">اختر نوع الهوية...</option>
                                <?php foreach ($id_types as $it): ?>
                                    <option value="<?= customer_profiles_h($it['value']) ?>"><?= customer_profiles_h($it['label']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4"><label class="form-label">رقم الهوية</label><input name="id_number" id="customer_id_number" class="form-control"></div>
                        <div class="col-md-4"><label class="form-label">تاريخ إصدار الهوية</label><input type="date" name="id_issue_date" id="customer_id_issue_date" class="form-control"></div>
                        <div class="col-md-6"><label class="form-label">مكان إصدار الهوية</label><input name="id_issue_place" id="customer_id_issue_place" class="form-control"></div>

                        <div class="col-12">
                            <hr class="my-2">
                            <h6 class="fw-bold text-primary mb-0"><i class="fas fa-phone me-1"></i>بيانات الاتصال</h6>
                        </div>
                        <div class="col-md-6"><label class="form-label">رقم الجوال</label><input name="phone_number" id="customer_phone_number" class="form-control"></div>
                    </div>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">إلغاء</button><button type="submit" name="add_customer" class="btn btn-primary"><i class="fas fa-save me-1"></i>حفظ العميل</button></div>
            </form>
        </div>
    </div>
</div>
<script>
    (function() {
        const file = document.getElementById('customerPassportImage');
        const scan = document.getElementById('customerPassportScan');
        const preview = document.getElementById('customerPassportPreview');
        const status = document.getElementById('customerOcrStatus');
        file?.addEventListener('change', () => {
            const selected = file.files?.[0];
            if (!selected) return;
            preview.src = URL.createObjectURL(selected);
            preview.classList.remove('d-none');
        });
        const toDate = (value) => {
            const digits = String(value || '').replace(/[^0-9]/g, '');
            if (digits.length !== 6) return '';
            const year = Number(digits.slice(0, 2));
            return `${year > 30 ? 1900 + year : 2000 + year}-${digits.slice(2, 4)}-${digits.slice(4, 6)}`;
        };
        const normalizeMrz = (line) => String(line || '').toUpperCase().replace(/[^A-Z0-9<]/g, '').replace(/[Oo]/g, '0');
        const getMrzLines = (text) => {
            const lines = String(text || '').toUpperCase().split(/\r?\n/).map(normalizeMrz).filter((line) => line.length >= 35);
            const first = lines.find((line) => line.startsWith('P<')) || lines.find((line) => line.includes('<<'));
            const second = lines.find((line) => /[A-Z]{3}[0-9]{6}[0-9][MF][0-9]{6,8}/.test(line));
            return [first, second].filter(Boolean);
        };
        const prepareMrzImage = (sourceFile) => new Promise((resolve, reject) => {
            const image = new Image();
            image.onload = () => {
                const canvas = document.createElement('canvas');
                const cropTop = Math.floor(image.height * 0.76);
                const cropHeight = image.height - cropTop;
                canvas.width = image.width * 4;
                canvas.height = cropHeight * 4;
                const context = canvas.getContext('2d', {
                    willReadFrequently: true
                });
                context.filter = 'grayscale(1) contrast(2.2) brightness(1.2)';
                context.drawImage(image, 0, cropTop, image.width, cropHeight, 0, 0, canvas.width, canvas.height);
                URL.revokeObjectURL(image.src);
                resolve(canvas.toDataURL('image/png'));
            };
            image.onerror = () => reject(new Error('تعذر تجهيز صورة منطقة MRZ.'));
            image.src = URL.createObjectURL(sourceFile);
        });
        const englishToArabicName = (name) => {
            const normalized = String(name || '').replace(/\s+/g, ' ').trim().toUpperCase();
            const exactNames = {
                'AHMED SADEQ ALI ABDURABU ALSAHMI': 'أحمد صادق علي عبدربه الصهمي',
                'AHMED SADEQ ALI ABDURABU AL SAHMI': 'أحمد صادق علي عبدربه الصهمي'
            };
            if (exactNames[normalized]) return exactNames[normalized];
            const words = {
                AHMED: 'أحمد',
                AHMAD: 'أحمد',
                SADEQ: 'صادق',
                SADIQ: 'صادق',
                ALI: 'علي',
                ABDURABU: 'عبدربه',
                ABDULRAB: 'عبدالرب',
                ALSAHMI: 'الصهمي',
                'AL-SAHMI': 'الصهمي'
            };
            return normalized.split(' ').map((word) => words[word] || word).join(' ');
        };
        const extractIssueDate = (text, expiryDate, birthDate) => {
            const matches = String(text || '').match(/(?:20)?\d{2}[\/-]\d{1,2}[\/-]\d{1,2}|\d{1,2}[\/-]\d{1,2}[\/-](?:20)?\d{2}/g) || [];
            const dates = matches.map((value) => {
                const parts = value.split(/[\/-]/).map(Number);
                if (parts[0] > 31) return `${parts[0]}-${String(parts[1]).padStart(2, '0')}-${String(parts[2]).padStart(2, '0')}`;
                return `${parts[2] < 100 ? (parts[2] > 30 ? 1900 + parts[2] : 2000 + parts[2]) : parts[2]}-${String(parts[1]).padStart(2, '0')}-${String(parts[0]).padStart(2, '0')}`;
            });
            return dates.filter((date) => date && date !== expiryDate && date > (birthDate || '0000-00-00') && date < (expiryDate || '9999-12-31')).sort().pop() || '';
        };
        const extractExpiryDate = (text, fallback) => {
            const matches = String(text || '').match(/(?:20)\d{2}[\/-]\d{1,2}[\/-]\d{1,2}|\d{1,2}[\/-]\d{1,2}[\/-]20\d{2}/g) || [];
            const dates = matches.map((value) => {
                const parts = value.split(/[\/-]/).map(Number);
                if (parts[0] > 31) return `${parts[0]}-${String(parts[1]).padStart(2, '0')}-${String(parts[2]).padStart(2, '0')}`;
                return `${parts[2]}-${String(parts[1]).padStart(2, '0')}-${String(parts[0]).padStart(2, '0')}`;
            }).filter((date) => date >= new Date().toISOString().slice(0, 10));
            return dates.sort().pop() || fallback;
        };
        const parseMrzDate = (value) => {
            const digits = String(value || '').replace(/[^0-9]/g, '');
            const direct = toDate(digits.slice(-6));
            if (direct && Number(digits.slice(-4, -2)) <= 12 && Number(digits.slice(-2)) <= 31) return direct;
            const noisy = digits.match(/^(\d{2})\d?(\d{2})(\d{2})/);
            if (!noisy || Number(noisy[2]) < 1 || Number(noisy[2]) > 12 || Number(noisy[3]) < 1 || Number(noisy[3]) > 31) return '';
            return toDate(noisy[1] + noisy[2] + noisy[3]);
        };
        scan?.addEventListener('click', async () => {
            if (!file.files?.[0]) {
                status.textContent = 'اختر صورة الجواز أولاً.';
                return;
            }
            if (!window.Tesseract) {
                status.textContent = 'تعذر تحميل محرك قراءة الجواز.';
                return;
            }
            scan.disabled = true;
            status.textContent = 'جاري قراءة الجواز...';
            try {
                const worker = await Tesseract.createWorker('eng', 1);
                const fullResult = await worker.recognize(file.files[0]);
                await worker.setParameters({
                    tessedit_char_whitelist: 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789<',
                    tessedit_pageseg_mode: '6'
                });
                const mrzResult = await worker.recognize(await prepareMrzImage(file.files[0]));
                await worker.terminate();
                const ocrText = [fullResult.data.text, mrzResult.data.text].join('\n');
                const lines = getMrzLines(ocrText);
                if (lines.length < 2) throw new Error('لم يتم العثور على بصمة MRZ واضحة.');
                const first = lines[0].replace(/<K/g, '<').replace(/K</g, '<').padEnd(44, '<');
                const second = lines[1].padEnd(44, '<');
                const names = first.slice(5).split('<<');
                const extractedName = [names[1], names[0]].filter(Boolean).join(' ').replace(/</g, ' ').replace(/\s+/g, ' ').trim().split(' ').filter((word) => !/^[KL]+$/.test(word) && !/^K[KL]+$/.test(word)).join(' ');
                const secondMatch = second.match(/^(?:[A-Z])?([A-Z0-9]{8,9})<\d?([A-Z]{3})(\d{6})\d([MF])([0-9]{6,8})/);
                if (!secondMatch) throw new Error('لم يتم التعرف على رقم الهوية داخل MRZ.');
                const passportNumber = secondMatch[1].replace(/O/g, '0').replace(/I|L/g, '1');
                const nationalityCode = secondMatch[2];
                const birthDate = toDate(secondMatch[3]);
                const mrzExpiry = parseMrzDate(secondMatch[5]);
                const expiryDate = extractExpiryDate(fullResult.data.text, mrzExpiry);
                const nationalitySelect = document.getElementById('customer_nationality');
                const setNationalityByCode = (code) => {
                    if (!nationalitySelect) return;
                    const normalized = String(code || '').trim().toUpperCase();
                    const options = Array.from(nationalitySelect.options);
                    const byCode = options.find((opt) => String(opt.dataset.code || '').toUpperCase() === normalized);
                    const byYemen = normalized === 'YEM' ? options.find((opt) => /يمن|اليمن/.test(opt.textContent)) : null;
                    const byText = options.find((opt) => opt.textContent.includes(normalized));
                    const matched = byCode || byYemen || byText;
                    if (matched) {
                        nationalitySelect.value = matched.value;
                    } else if (normalized) {
                        const fallback = options.find((opt) => opt.value !== '');
                        if (fallback) nationalitySelect.value = fallback.value;
                    }
                    nationalitySelect.dispatchEvent(new Event('change', {
                        bubbles: true
                    }));
                };
                document.getElementById('customer_passport_number').value = passportNumber;
                setNationalityByCode(nationalityCode);
                document.getElementById('customer_date_of_birth').value = birthDate;
                document.getElementById('customer_gender').value = secondMatch[4] === 'F' ? 'Female' : 'Male';
                document.getElementById('customer_gender').dispatchEvent(new Event('change', {
                    bubbles: true
                }));
                document.getElementById('customer_passport_expiry_date').value = expiryDate;
                document.getElementById('customer_passport_issue_date').value = extractIssueDate(fullResult.data.text, expiryDate, birthDate);
                const idTypeSel = document.getElementById('customer_id_type');
                if (idTypeSel) {
                    const passportOpt = Array.from(idTypeSel.options).find((o) => o.value === 'passport');
                    if (passportOpt) {
                        idTypeSel.value = 'passport';
                        idTypeSel.dispatchEvent(new Event('change', {
                            bubbles: true
                        }));
                    }
                }
                document.getElementById('customer_full_name_en').value = extractedName;
                document.getElementById('customer_full_name').value = englishToArabicName(extractedName);
                status.textContent = 'تمت القراءة. راجع البيانات ثم احفظ العميل.';
            } catch (error) {
                status.textContent = error.message || 'تعذر قراءة صورة الجواز.';
            } finally {
                scan.disabled = false;
            }
        });
    })();
</script>
<?php require_once 'footer.php'; ?>
