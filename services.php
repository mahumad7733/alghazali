<?php
require_once 'includes/db.php';
require_once 'includes/functions.php';
$settings = getSettings($pdo);

// جلب محتوى قسم الخدمات من قاعدة البيانات
$stmt = $pdo->prepare("SELECT * FROM site_content WHERE section_key = 'services_summary'");
$stmt->execute();
$page_seo = $stmt->fetch();

// تحديث متغيرات SEO قبل تضمين الهيدر
if($page_seo) {
    $settings['meta_description'] = $page_seo['meta_description'] ?: $settings['meta_description'];
    $settings['meta_keywords'] = $page_seo['meta_keywords'] ?: $settings['meta_keywords'];
    $settings['site_name'] = ($page_seo['meta_title'] ?: ($page_seo['section_title'] ?: "خدماتنا")) . " | " . $settings['site_name'];
}

require_once 'includes/header.php';
$services = getServices($pdo);
?>

<div class="container my-5">
    <div class="text-center mb-5">
        <h2 class="fw-bold"><?php echo (is_array($page_seo) && !empty($page_seo['section_title'])) ? $page_seo['section_title'] : 'خدماتنا وبرامجنا'; ?></h2>
        <?php if(is_array($page_seo) && !empty($page_seo['section_text'])): ?>
            <p class="lead text-muted mx-auto" style="max-width: 800px;"><?php echo nl2br($page_seo['section_text']); ?></p>
        <?php endif; ?>
    </div>

    <div class="row g-4">
        <?php foreach($services as $service): ?>
        <div class="col-md-4">
            <div class="card h-100 shadow-sm border-0 rounded-4 overflow-hidden">
                <?php if(!empty($service['service_image'])): ?>
                    <?php 
                        $img_src = $service['service_image'];
                        if (strpos($img_src, 'http') === false) {
                            $img_src = 'assets/images/' . $img_src;
                        }
                    ?>
                    <div class="text-center p-0 bg-light overflow-hidden" style="height: 200px;">
                        <img src="<?php echo $img_src; ?>" class="w-100 h-100 object-fit-cover" alt="<?php echo $service['service_name']; ?>">
                    </div>
                <?php else: ?>
                    <div class="text-center p-5 bg-light d-flex align-items-center justify-content-center" style="height: 200px;">
                        <i class="fas fa-kaaba fa-4x text-muted opacity-25"></i>
                    </div>
                <?php endif; ?>
                
                <div class="card-body p-4">
                    <h5 class="card-title fw-bold mb-3"><?php echo $service['service_name']; ?></h5>
                    
                    <?php if($service['price'] > 0): ?>
                        <div class="d-flex align-items-center mb-3">
                            <span class="fs-4 fw-bold text-primary">
                                <?php echo number_format($service['price'], 2); ?> 
                                <small class="fs-6"><?php echo $service['currency_symbol']; ?></small>
                            </span>
                        </div>
                        
                        <ul class="list-unstyled mb-4">
                            <li class="mb-2 small"><i class="fas fa-hotel me-2 text-muted"></i> <strong>الفندق:</strong> <?php echo $service['hotel_name'] ?: 'غير محدد'; ?></li>
                            <li class="mb-2 small"><i class="fas fa-moon me-2 text-muted"></i> <strong>المدة:</strong> <?php echo $service['nights_count']; ?> ليلة</li>
                            <li class="mb-2 small"><i class="fas fa-map-marker-alt me-2 text-muted"></i> <strong>التوزيع:</strong> <?php echo $service['makkah_days']; ?> مكة / <?php echo $service['madinah_days']; ?> المدينة</li>
                        </ul>
                        
                        <div class="bg-light p-3 rounded-3 mb-4">
                            <div class="row text-center g-0">
                                <div class="col-4 border-start">
                                    <div class="small text-muted">ثنائي</div>
                                    <div class="fw-bold small"><?php echo number_format($service['double_price'], 0); ?></div>
                                </div>
                                <div class="col-4 border-start">
                                    <div class="small text-muted">ثلاثي</div>
                                    <div class="fw-bold small"><?php echo number_format($service['triple_price'], 0); ?></div>
                                </div>
                                <div class="col-4">
                                    <div class="small text-muted">رباعي</div>
                                    <div class="fw-bold small"><?php echo number_format($service['quad_price'], 0); ?></div>
                                </div>
                            </div>
                        </div>
                    <?php else: ?>
                        <p class="text-muted small mb-4">تواصل معنا للحصول على أفضل العروض والأسعار لهذه الخدمة.</p>
                    <?php endif; ?>
                    
                    <a href="contact.php?service=<?php echo $service['id']; ?>" class="btn btn-primary w-100 rounded-pill py-2">طلب الخدمة / استفسار</a>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>
