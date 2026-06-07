        </div> <!-- end content-body -->
    </div> <!-- end main-wrapper -->


    <script>
        // التنبيهات يتم إدارتها الآن عبر js/global_notifications.js بشكل أكثر شمولية
    </script>
<script src="js/global_notifications.js?v=<?php echo filemtime(__DIR__ . '/js/global_notifications.js'); ?>"></script>
<script>
    function showToast(message, type = 'success') {
        const toastEl = document.getElementById('liveToast');
        const toastMessage = document.getElementById('toastMessage');
        const toastIcon = document.getElementById('toastIcon');
        
        // Reset classes
        toastEl.className = 'toast align-items-center text-white border-0 shadow-lg rounded-4';
        
        // Set type
        let iconClass = 'fa-check-circle';
        switch(type) {
            case 'success': toastEl.classList.add('bg-success'); iconClass = 'fa-check-circle'; break;
            case 'danger': toastEl.classList.add('bg-danger'); iconClass = 'fa-exclamation-circle'; break;
            case 'warning': toastEl.classList.add('bg-warning'); iconClass = 'fa-exclamation-triangle'; break;
            case 'info': toastEl.classList.add('bg-info'); iconClass = 'fa-info-circle'; break;
        }
        
        toastMessage.innerText = message;
        toastIcon.className = 'fas ' + iconClass + ' me-2 fs-5';
        
        const toast = new bootstrap.Toast(toastEl, { delay: 4000 });
        toast.show();
    }

    // التحقق من وجود رسائل في PHP لتحويلها إلى Toast
    <?php if (isset($success)): ?>
        showToast("<?php echo addslashes($success); ?>", "success");
    <?php endif; ?>
    <?php if (isset($error)): ?>
        showToast("<?php echo addslashes($error); ?>", "danger");
    <?php endif; ?>
</script>
</body>
</html>
