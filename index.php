<?php
require_once 'includes/header.php';
$settings = getSettings($pdo);
$news = getNews($pdo);
logVisit($pdo);

// جلب محتوى الصفحة الرئيسية من قاعدة البيانات
$home_contents = $pdo->query("SELECT * FROM site_content")->fetchAll();
$contents = [];
foreach ($home_contents as $c) {
    $contents[$c['section_key']] = $c;
}

// جلب السلايدر
$sliders = $pdo->query("SELECT * FROM slider_images ORDER BY sort_order ASC, id DESC")->fetchAll();

// تحميل مسبق لأول صورة في السلايدر لتسريع ظهورها (LCP Optimization)
if (!empty($sliders)) {
    echo '<link rel="preload" as="image" href="assets/uploads/slider/' . $sliders[0]['image_path'] . '">';
}

// إعدادات السلايدر من جدول الإعدادات
$slider_autoplay = isset($settings['slider_autoplay']) && $settings['slider_autoplay'] == 1 ? 'true' : 'false';
$slider_controls = isset($settings['slider_controls']) && $settings['slider_controls'] == 1 ? true : false;
$slider_interval = isset($settings['slider_interval']) ? (int)$settings['slider_interval'] : 5000;
$show_passport_query = isset($settings['show_passport_query']) ? (bool)$settings['show_passport_query'] : true;
if (!isset($settings['show_passport_query'])) {
    try {
        $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value, setting_group) VALUES ('show_passport_query', '1', 'general') ON DUPLICATE KEY UPDATE setting_value = '1'")->execute();
    } catch (Exception $e) {}
}
$query_bg_color = isset($settings['query_bg_color']) ? $settings['query_bg_color'] : 'rgba(255, 255, 255, 0.12)';
$query_btn_color = isset($settings['query_btn_color']) ? $settings['query_btn_color'] : '#0d6efd';
?>

<style>
    /* تحسينات التصميم الجديدة واستجابة الهواتف */
    .hero-section {
        height: 700px;
        position: relative;
        margin: 0 !important;
        padding: 0 !important;
        overflow: hidden;
        line-height: 0 !important;
        display: block !important;
        border: none !important;
    }

    @media (max-width: 768px) {
        .hero-section {
            height: 500px;
        }
    }

    .carousel-item img {
        height: 700px !important;
        width: 100% !important;
        object-fit: cover;
        filter: brightness(0.6);
        display: block !important;
        margin: 0 !important;
        padding: 0 !important;
        border: none !important;
        vertical-align: top !important;
    }

    @media (max-width: 768px) {
        .carousel-item img {
            height: 500px !important;
        }
    }

    .carousel-overlay {
        background: linear-gradient(to bottom, rgba(0, 0, 0, 0.15), rgba(0, 0, 0, 0.75));
    }

    .search-container {
        position: absolute;
        top: 55%;
        left: 50%;
        transform: translate(-50%, -50%);
        width: 100%;
        z-index: 30;
        padding: 0 15px;
    }

    @media (max-width: 768px) {
        .search-container {
            top: 50%;
        }
    }

    .glass-search-card {
        background: <?php echo $settings['query_bg_color'] ?? 'rgba(0, 0, 0, 0.82)'; ?>;
        backdrop-filter: blur(16px);
        -webkit-backdrop-filter: blur(16px);
        border: 1px solid rgba(255, 255, 255, 0.12);
        border-radius: 24px;
        padding: 50px 40px;
        box-shadow: 0 25px 50px rgba(0, 0, 0, 0.5);
        max-width: 750px;
        margin: 0 auto;
    }

    @media (max-width: 768px) {
        .glass-search-card {
            padding: 28px 20px;
            border-radius: 20px;
        }
    }

    .search-title {
        font-size: 2.4rem;
        font-weight: 900;
        color: #fff;
        margin-bottom: 30px;
        text-shadow: 0 2px 15px rgba(0, 0, 0, 0.4);
        letter-spacing: -0.5px;
    }

    @media (max-width: 768px) {
        .search-title {
            font-size: 1.55rem;
            margin-bottom: 18px;
        }
    }

    .modern-input-group {
        background: #fff;
        border-radius: 50px;
        padding: 6px;
        display: flex;
        box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
    }

    @media (max-width: 768px) {
        .modern-input-group {
            flex-direction: column;
            background: transparent;
            box-shadow: none;
            padding: 0;
            gap: 10px;
        }
    }

    .modern-input-group input {
        border: none !important;
        padding: 14px 25px;
        font-size: 1.05rem;
        border-radius: 50px;
        flex: 1;
        outline: none;
        box-shadow: none !important;
        font-family: 'Cairo', sans-serif;
    }

    @media (max-width: 768px) {
        .modern-input-group input {
            text-align: center;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }
    }

    .modern-input-group .btn-search {
        background-color: <?php echo $query_btn_color; ?>;
        border-color: <?php echo $query_btn_color; ?>;
        border-radius: 50px;
        padding: 12px 35px;
        font-weight: 700;
        font-size: 1.05rem;
        transition: all 0.3s ease;
        font-family: 'Cairo', sans-serif;
    }

    .modern-input-group .btn-search:hover {
        filter: brightness(1.1);
        transform: scale(1.03);
    }

    @media (max-width: 768px) {
        .modern-input-group .btn-search {
            width: 100%;
        }
    }

    .carousel-caption-custom {
        position: absolute;
        top: 18%;
        width: 100%;
        text-align: center;
        color: #fff;
        z-index: 25;
        text-shadow: 0 2px 8px rgba(0, 0, 0, 0.5);
    }

    @media (max-width: 768px) {
        .carousel-caption-custom {
            top: 12%;
        }

        .carousel-caption-custom h2 {
            font-size: 1.7rem !important;
        }

        .carousel-caption-custom p {
            font-size: 0.95rem !important;
        }
    }

    .section-padding {
        padding: 80px 0;
    }

    @media (max-width: 768px) {
        .section-padding {
            padding: 50px 0;
        }
    }

    /* Features Section */
    .features-section {
        background: var(--section-alt-bg, #f0f4f8);
        position: relative;
        overflow: hidden;
    }

    .features-section::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: radial-gradient(ellipse at top right, rgba(130, 201, 30, 0.07) 0%, transparent 60%);
        pointer-events: none;
    }

    .section-heading {
        font-size: 2rem;
        font-weight: 900;
        margin-bottom: 8px;
        background: linear-gradient(135deg, #1e293b 0%, #475569 100%);
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        background-clip: text;
    }

    body.dark-mode .section-heading {
        background: linear-gradient(135deg, #f1f5f9 0%, #94a3b8 100%);
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        background-clip: text;
    }

    .section-divider {
        width: 60px;
        height: 4px;
        background: linear-gradient(90deg, #82c91e, #a3e635);
        border-radius: 2px;
        margin: 0 auto 50px;
    }
</style>

<main>
    <!-- Hero Slider with Search Overlay -->
    <section class="hero-section" aria-label="عرض الصور الرئيسي">
        <div id="mainSlider" class="carousel slide carousel-fade" data-bs-ride="carousel" data-bs-interval="<?php echo $slider_interval; ?>" data-bs-pause="false">
            <div class="carousel-inner">
                <?php if (empty($sliders)): ?>
                    <div class="carousel-item active">
                        <img src="https://images.unsplash.com/photo-1565031491910-e57fac031c41?ixlib=rb-1.2.1&auto=format&fit=crop&w=1920&q=80" class="d-block w-100" alt="وكالة الغزالي للسفريات والسياحة - رحلات حول العالم">
                        <div class="carousel-overlay position-absolute top-0 start-0 w-100 h-100"></div>
                    </div>
                <?php else: ?>
                    <?php foreach ($sliders as $index => $slide): ?>
                        <div class="carousel-item <?php echo $index === 0 ? 'active' : ''; ?>">
                            <img src="assets/uploads/slider/<?php echo $slide['image_path']; ?>"
                                class="d-block w-100"
                                alt="<?php echo htmlspecialchars($slide['title'] ?? $settings['site_name']); ?> - وكالة الغزالي للسفريات"
                                <?php echo $index === 0 ? 'fetchpriority="high"' : 'loading="lazy"'; ?>>
                            <div class="carousel-overlay position-absolute top-0 start-0 w-100 h-100"></div>

                            <!-- نصوص السلايدر -->
                            <?php if (!empty($slide['title']) || !empty($slide['subtitle'])): ?>
                                <div class="carousel-caption-custom" data-aos="fade-down" data-aos-duration="1000">
                                    <div class="container px-4">
                                        <h2 class="display-4 fw-bold mb-2"><?php echo htmlspecialchars($slide['title']); ?></h2>
                                        <p class="lead fs-5 text-white-50"><?php echo htmlspecialchars($slide['subtitle']); ?></p>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <?php if ($slider_controls): ?>
                <button class="carousel-control-prev d-none d-md-flex" type="button" data-bs-target="#mainSlider" data-bs-slide="prev" style="z-index: 40;">
                    <span class="carousel-control-prev-icon" aria-hidden="true"></span>
                    <span class="visually-hidden">السابق</span>
                </button>
                <button class="carousel-control-next d-none d-md-flex" type="button" data-bs-target="#mainSlider" data-bs-slide="next" style="z-index: 40;">
                    <span class="carousel-control-next-icon" aria-hidden="true"></span>
                    <span class="visually-hidden">التالي</span>
                </button>
            <?php endif; ?>
        </div>

        <!-- صندوق الاستعلام -->
        <?php if ($show_passport_query): ?>
            <div class="search-container">
                <div class="container">
                    <article class="glass-search-card text-center" data-aos="zoom-in" data-aos-duration="800">
                        <h1 class="search-title"><?php echo $settings['query_title'] ?? 'استعلم عن حالة جوازك'; ?></h1>
                        <div class="modern-input-group">
                            <input type="text" id="passport_number" placeholder="أدخل رقم الجواز هنا..." aria-label="رقم الجواز للاستعلام">
                            <button class="btn btn-success btn-search text-white" type="button" onclick="searchPassport()">
                                <i class="fas fa-search me-2"></i> استعلام
                            </button>
                        </div>
                    </article>
                </div>
            </div>
        <?php endif; ?>
    </section>

    <!-- Features Section -->
    <section class="section-padding features-section" aria-label="مميزات وكالة الغزالي">
        <div class="container">
            <div class="text-center mb-5" data-aos="fade-up">
                <h2 class="section-heading">لماذا تختارنا؟</h2>
                <div class="section-divider"></div>
            </div>
            <div class="row g-4">
                <div class="col-md-4" data-aos="fade-up" data-aos-delay="100">
                    <div class="feature-card text-center p-5 h-100">
                        <div class="icon-box mb-4 mx-auto bg-success bg-opacity-10 text-success rounded-circle d-flex align-items-center justify-content-center" style="width: 75px; height: 75px;">
                            <i class="fas fa-bolt fa-2x"></i>
                        </div>
                        <h3 class="h5 fw-bold mb-3">سرعة الإنجاز</h3>
                        <p class="text-muted mb-0">نضمن لك معالجة طلباتك في أسرع وقت ممكن وبدقة عالية في مكتب الغزالي.</p>
                    </div>
                </div>
                <div class="col-md-4" data-aos="fade-up" data-aos-delay="200">
                    <div class="feature-card text-center p-5 h-100">
                        <div class="icon-box mb-4 mx-auto bg-primary bg-opacity-10 text-primary rounded-circle d-flex align-items-center justify-content-center" style="width: 75px; height: 75px;">
                            <i class="fas fa-shield-alt fa-2x"></i>
                        </div>
                        <h3 class="h5 fw-bold mb-3">أمان وموثوقية</h3>
                        <p class="text-muted mb-0">بياناتك وجوازاتك في أيدٍ أمينة مع أنظمة حماية متطورة لدى وكالة الغزالي.</p>
                    </div>
                </div>
                <div class="col-md-4" data-aos="fade-up" data-aos-delay="300">
                    <div class="feature-card text-center p-5 h-100">
                        <div class="icon-box mb-4 mx-auto bg-warning bg-opacity-10 text-warning rounded-circle d-flex align-items-center justify-content-center" style="width: 75px; height: 75px;">
                            <i class="fas fa-headset fa-2x"></i>
                        </div>
                        <h3 class="h5 fw-bold mb-3">دعم فني متواصل</h3>
                        <p class="text-muted mb-0">فريقنا متاح للرد على استفساراتك حول خدمات السفر على مدار الساعة.</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Vision & Mission -->
    <section class="section-padding overflow-hidden" aria-label="رؤية ورسالة الوكالة">
        <div class="container">
            <div class="text-center mb-5" data-aos="fade-up">
                <h2 class="section-heading">قيمنا ومبادئنا</h2>
                <div class="section-divider"></div>
            </div>
            <div class="row g-4 text-center">
                <div class="col-md-4" data-aos="zoom-in" data-aos-delay="100">
                    <div class="vision-card p-5 h-100">
                        <div class="mb-4 text-primary"><i class="fas fa-bullseye fa-3x"></i></div>
                        <h4 class="fw-bold mb-3"><?php echo $contents['goal']['section_title'] ?? 'هدفنا'; ?></h4>
                        <p class="text-muted"><?php echo $contents['goal']['section_text'] ?? 'تحقيق أقصى درجات الرضا لعملائنا من خلال خدماتنا المتميزة في مجال السفر والسياحة.'; ?></p>
                    </div>
                </div>
                <div class="col-md-4" data-aos="zoom-in" data-aos-delay="200">
                    <div class="vision-card p-5 h-100">
                        <div class="mb-4 text-success"><i class="fas fa-paper-plane fa-3x"></i></div>
                        <h4 class="fw-bold mb-3"><?php echo $contents['mission']['section_title'] ?? 'رسالتنا'; ?></h4>
                        <p class="text-muted"><?php echo $contents['mission']['section_text'] ?? 'تقديم حلول سفر مبتكرة وسهلة تلبي تطلعات المسافر العصري من وإلى اليمن.'; ?></p>
                    </div>
                </div>
                <div class="col-md-4" data-aos="zoom-in" data-aos-delay="300">
                    <div class="vision-card p-5 h-100">
                        <div class="mb-4 text-warning"><i class="fas fa-eye fa-3x"></i></div>
                        <h4 class="fw-bold mb-3"><?php echo $contents['vision']['section_title'] ?? 'رؤيتنا'; ?></h4>
                        <p class="text-muted"><?php echo $contents['vision']['section_text'] ?? 'أن نكون الوكالة الرائدة والأكثر موثوقية في تقديم خدمات الحج والعمرة والسياحة.'; ?></p>
                    </div>
                </div>
            </div>
        </div>
    </section>
</main>

<script>
    function searchPassport() {
        const passportNumber = document.getElementById('passport_number').value;
        if (!passportNumber) {
            alert('يرجى إدخال رقم الجواز');
            return;
        }
        window.location.href = 'query_result.php?passport=' + encodeURIComponent(passportNumber);
    }

    function goToService() {
        const serviceType = document.getElementById('service_type').value;
        if (!serviceType) {
            alert('يرجى اختيار نوع الخدمة');
            return;
        }

        switch (serviceType) {
            case 'umrah':
                window.location.href = 'admin/umrah.php';
                break;
            case 'hajj':
                alert('خدمة الحج قيد التطوير');
                break;
            case 'flights':
                alert('حجز الطيران قيد التطوير');
                break;
            case 'bus':
                alert('حجز الحافلات قيد التطوير');
                break;
            case 'hotels':
                alert('حجز الفنادق قيد التطوير');
                break;
            default:
                alert('خدمة غير معروفة');
        }
    }
    </script>
<?php require_once 'includes/footer.php'; ?>
