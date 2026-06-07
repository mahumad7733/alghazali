<?php
require_once 'includes/db.php';
require_once 'includes/functions.php';

// جلب كافة محتويات الموقع دفعة واحدة لتسريع الأداء
$stmt = $pdo->query("SELECT * FROM site_content");
$contents = [];
while($row = $stmt->fetch()) {
    $contents[$row['section_key']] = $row;
}

// جلب إعدادات الموقع العامة
$settings = getSettings($pdo);

// إعدادات SEO لصفحة من نحن
$about_main = $contents['about_summary'] ?? null;
$page_title = ($about_main && !empty($about_main['meta_title'])) ? $about_main['meta_title'] : "من نحن";
$meta_desc = ($about_main && !empty($about_main['meta_description'])) ? $about_main['meta_description'] : $settings['meta_description'];
$meta_keys = ($about_main && !empty($about_main['meta_keywords'])) ? $about_main['meta_keywords'] : $settings['meta_keywords'];

require_once 'includes/header.php';
?>

<style>
    .about-hero {
        background: linear-gradient(rgba(0,0,0,0.7), rgba(0,0,0,0.7)), url('https://images.unsplash.com/photo-1519817650390-64a93db51149?ixlib=rb-1.2.1&auto=format&fit=crop&w=1920&q=80');
        background-size: cover;
        background-position: center;
        color: white;
        padding: 120px 0;
        margin-bottom: 60px;
    }
    .feature-card {
        transition: all 0.3s ease;
        border: none;
        border-radius: 20px;
        height: 100%;
        background: #fff;
    }
    .feature-card:hover {
        transform: translateY(-10px);
        box-shadow: 0 15px 30px rgba(0,0,0,0.1) !important;
    }
    .icon-box {
        width: 70px;
        height: 70px;
        display: flex;
        align-items: center;
        justify-content: center;
        border-radius: 18px;
        margin-bottom: 25px;
        font-size: 1.8rem;
    }
    .experience-badge {
        position: absolute;
        bottom: -30px;
        right: -30px;
        background: var(--primary-color, #0d6efd);
        color: white;
        padding: 30px;
        border-radius: 25px;
        box-shadow: 0 10px 20px rgba(0,0,0,0.2);
        z-index: 2;
    }
    @media (max-width: 768px) {
        .experience-badge {
            position: relative;
            bottom: 0;
            right: 0;
            margin-top: 20px;
            border-radius: 15px;
            text-align: center;
        }
    }
    .feature-item {
        background: #f8f9fa;
        padding: 15px 20px;
        border-radius: 12px;
        margin-bottom: 15px;
        display: flex;
        align-items: center;
        font-weight: 600;
        border-right: 4px solid #0d6efd;
    }
</style>

<!-- Hero Section -->
<section class="about-hero text-center">
    <div class="container">
        <h1 class="display-3 fw-bold mb-3 animate__animated animate__fadeInDown">
            <?php echo $contents['about_hero_title']['section_title'] ?? 'من نحن'; ?>
        </h1>
        <p class="lead mb-4 opacity-75">
            <?php echo $contents['about_hero_title']['section_text'] ?? 'تعرف على قصة نجاحنا وخدماتنا المتميزة'; ?>
        </p>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb justify-content-center">
                <li class="breadcrumb-item"><a href="index.php" class="text-white text-decoration-none">الرئيسية</a></li>
                <li class="breadcrumb-item active text-white-50" aria-current="page">من نحن</li>
            </ol>
        </nav>
    </div>
</section>

<div class="container mb-5 pb-5">
    <!-- Main Content Section -->
    <div class="row align-items-center mb-5 pb-5">
        <div class="col-lg-6 mb-5 mb-lg-0">
            <div class="pe-lg-5">
                <span class="badge bg-primary-subtle text-primary px-3 py-2 rounded-pill mb-3 fw-bold">قصة نجاحنا</span>
                <h2 class="display-5 fw-bold mb-4">
                    <?php echo $contents['about_summary']['section_title'] ?? 'وكالة الغزالي للسفريات'; ?>
                </h2>
                <p class="text-muted mb-5 fs-5" style="line-height: 1.9; text-align: justify;">
                    <?php echo nl2br($contents['about_summary']['section_text'] ?? 'نحن نقدم أفضل خدمات السفر والسياحة...'); ?>
                </p>
                
                <div class="row g-3">
                    <?php 
                        $features_text = $contents['about_features']['section_text'] ?? 'خدمة 24/7,أفضل الأسعار,مصداقية تامة';
                        $features = explode(',', $features_text);
                        foreach($features as $feature):
                    ?>
                    <div class="col-md-6">
                        <div class="feature-item">
                            <i class="fas fa-check-circle text-primary me-3 fs-5"></i>
                            <?php echo trim($feature); ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="position-relative ms-lg-4">
                <?php 
                    $img_path = !empty($contents['about_summary']['section_image']) ? 'assets/uploads/' . $contents['about_summary']['section_image'] : 'https://images.unsplash.com/photo-1565031491910-e57fac031c41?ixlib=rb-1.2.1&auto=format&fit=crop&w=800&q=80';
                ?>
                <img src="<?php echo $img_path; ?>" class="img-fluid rounded-4 shadow-lg w-100" alt="About Us" style="min-height: 450px; object-fit: cover;">
                
                <div class="experience-badge">
                    <h2 class="fw-bold mb-0">+<?php echo $contents['about_experience_years']['section_title'] ?? '10'; ?></h2>
                    <p class="mb-0 fw-bold opacity-75">عاماً من الخبرة</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Mission, Vision, Goals Section -->
    <div class="row g-4 mt-5">
        <!-- الرسالة -->
        <div class="col-md-4">
            <div class="card feature-card shadow-sm p-4 text-center">
                <div class="icon-box bg-primary-subtle text-primary mx-auto">
                    <i class="fas fa-bullseye"></i>
                </div>
                <h4 class="fw-bold mb-3"><?php echo $contents['mission']['section_title'] ?? 'رسالتنا'; ?></h4>
                <p class="text-muted mb-0"><?php echo nl2br($contents['mission']['section_text'] ?? ''); ?></p>
            </div>
        </div>

        <!-- الرؤية -->
        <div class="col-md-4">
            <div class="card feature-card shadow-sm p-4 text-center">
                <div class="icon-box bg-success-subtle text-success mx-auto">
                    <i class="fas fa-eye"></i>
                </div>
                <h4 class="fw-bold mb-3"><?php echo $contents['vision']['section_title'] ?? 'رؤيتنا'; ?></h4>
                <p class="text-muted mb-0"><?php echo nl2br($contents['vision']['section_text'] ?? ''); ?></p>
            </div>
        </div>

        <!-- الأهداف -->
        <div class="col-md-4">
            <div class="card feature-card shadow-sm p-4 text-center">
                <div class="icon-box bg-info-subtle text-info mx-auto">
                    <i class="fas fa-tasks"></i>
                </div>
                <h4 class="fw-bold mb-3"><?php echo $contents['goal']['section_title'] ?? 'أهدافنا'; ?></h4>
                <p class="text-muted mb-0"><?php echo nl2br($contents['goal']['section_text'] ?? ''); ?></p>
            </div>
        </div>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>
