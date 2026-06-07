<?php
require_once 'includes/header.php';

$message_sent = false;
if($_SERVER['REQUEST_METHOD'] == 'POST') {
    $stmt = $pdo->prepare("INSERT INTO contact_messages (name, phone, email, subject, message) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([$_POST['name'], $_POST['phone'], $_POST['email'], $_POST['subject'], $_POST['message']]);
    $message_sent = true;
}
?>

<div class="container my-5">
    <div class="row">
        <div class="col-md-6">
            <h3>اتصل بنا</h3>
            <?php if($message_sent): ?>
                <div class="alert alert-success">تم إرسال رسالتك بنجاح، سنقوم بالرد عليك قريباً.</div>
            <?php endif; ?>
            <form method="POST">
                <div class="mb-3">
                    <label class="form-label">الاسم</label>
                    <input type="text" name="name" class="form-control" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">رقم الهاتف</label>
                    <input type="text" name="phone" class="form-control" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">البريد الإلكتروني</label>
                    <input type="email" name="email" class="form-control" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">الموضوع</label>
                    <input type="text" name="subject" class="form-control" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">الرسالة</label>
                    <textarea name="message" class="form-control" rows="5" required></textarea>
                </div>
                <button type="submit" class="btn btn-primary">إرسال الرسالة</button>
            </form>
        </div>
        <div class="col-md-6">
            <h3>معلومات التواصل</h3>
            <div class="card mb-4">
                <div class="card-body">
                    <p><i class="fas fa-envelope text-primary me-2"></i> <?php echo $settings['email']; ?></p>
                    <p><i class="fas fa-phone text-primary me-2"></i> <?php echo $settings['phone']; ?></p>
                    <p><i class="fas fa-map-marker-alt text-primary me-2"></i> <?php echo $settings['address']; ?></p>
                </div>
            </div>
            
            <?php if($settings['latitude'] && $settings['longitude']): ?>
            <div class="ratio ratio-16x9">
                <iframe 
                    src="https://maps.google.com/maps?q=<?php echo $settings['latitude']; ?>,<?php echo $settings['longitude']; ?>&z=15&output=embed" 
                    style="border:0;" allowfullscreen="" loading="lazy">
                </iframe>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>
