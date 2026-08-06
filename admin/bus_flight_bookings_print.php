<?php
ob_start();
require_once 'header.php';

if (!isset($_GET['id'])) {
  header('Location: bus_flight_bookings.php');
  exit();
}

$id = (int)$_GET['id'];

$stmt = $pdo->prepare("
    SELECT
        b.*,
        inv.total_amount AS sale_price,
        inv.cost_amount AS purchase_price,
        inv.amount_received AS amount_received,
        (inv.total_amount - inv.amount_received) AS remaining_amount,
        (inv.total_amount - inv.cost_amount) AS profit,
        inv.currency_id AS currency_id,
        inv.delivery_type AS payment_type,
        c_from.city_name AS from_city_name,
        c_to.city_name AS to_city_name,
        curr.currency_name,
        curr.currency_symbol,
        bs.status_name AS booking_status_name,
        bs.status_color AS booking_status_color,
        cust.full_name AS customer_full_name,
        u.full_name AS created_by_user_full_name,
        u.username AS created_by_username,
        s.supplier_name,
        cnt.country_name AS nationality_name,
        br.branch_name
    FROM bus_flight_bookings b
    LEFT JOIN cities c_from ON b.from_city_id = c_from.id
    LEFT JOIN cities c_to ON b.to_city_id = c_to.id
    LEFT JOIN invoices inv ON inv.id = b.invoice_id
    LEFT JOIN currencies curr ON inv.currency_id = curr.id
    LEFT JOIN statuses bs ON b.status_id = bs.id
    LEFT JOIN customers cust ON b.customer_id = cust.id
    LEFT JOIN users u ON b.created_by = u.id
    LEFT JOIN suppliers s ON b.supplier_id = s.id
    LEFT JOIN countries cnt ON b.nationality_id = cnt.id
    LEFT JOIN branches br ON b.branch_id = br.id
    WHERE b.id = ?
");
$stmt->execute([$id]);
$b = $stmt->fetch();

if (!$b) {
  echo "خطأ: الحجز غير موجود.";
  exit();
}

$settings = getSettings($pdo);

$logo_path = '';
$print_logo_name = trim((string)($settings['print_logo'] ?? ''));
$site_logo_name = trim((string)($settings['site_logo'] ?? ''));
if ($print_logo_name !== '' && file_exists('../assets/uploads/' . $print_logo_name)) {
  $logo_path = '../assets/uploads/' . $print_logo_name;
} elseif ($site_logo_name !== '' && file_exists('../assets/uploads/' . $site_logo_name)) {
  $logo_path = '../assets/uploads/' . $site_logo_name;
}

$booking_number = (string)($b['booking_number'] ?? '');
$qr_data = $booking_number;
$qr_url = 'https://api.qrserver.com/v1/create-qr-code/?size=120x120&margin=0&data=' . urlencode($qr_data);

$ticket_type = $b['service_type'] == 'bus' ? 'باص' : 'طيران';
$service_type = $b['service_type'] == 'bus' ? 'رحلة برية' : 'رحلة جوية';

$from_city = htmlspecialchars($b['from_city_name'] ?? '---');
$to_city = htmlspecialchars($b['to_city_name'] ?? '---');

$raw_bus_type = $b['bus_type'] ?? '';
if ($raw_bus_type === 'tourist') {
  $bus_type_ar = 'سياحي';
} elseif ($raw_bus_type === 'regular') {
  $bus_type_ar = 'عادي';
} else {
  $bus_type_ar = '';
}
$bus_flight_type_ar = ($b['service_type'] ?? '') === 'flight'
  ? 'طيران'
  : ($bus_type_ar ?: 'باص');

$traveler_name = htmlspecialchars($b['traveler_name'] ?? ($b['customer_full_name'] ?? '---'));

$dob = $b['date_of_birth'] ?? '';
if (!empty($dob) && $dob !== '0000-00-00') {
  $dob_ts = strtotime($dob);
  if ($dob_ts) {
    $today = new DateTime();
    $birth = new DateTime($dob);
    $age_calc = $today->diff($birth)->y;
    $age = (string)$age_calc;
  } else {
    $age = htmlspecialchars($b['age'] ?? '---');
  }
} else {
  $age = htmlspecialchars($b['age'] ?? '---');
}

$id_number = htmlspecialchars($b['id_number'] ?? '---');

$raw_id_type = $b['id_type'] ?? 'passport';
if ($raw_id_type == 'national_id') {
  $id_type = 'بطاقة وطنية';
} else {
  $id_type = 'جواز سفر';
}

$issue_date = htmlspecialchars($b['id_issue_date'] ?? '---');
$issue_place = htmlspecialchars($b['id_issue_place'] ?? '---');
$nationality = htmlspecialchars($b['nationality_name'] ?? '---');

$booking_date = $b['booking_date'] ?? date('Y-m-d');
$booking_time = date('h:i A', strtotime($b['created_at'] ?? 'now'));
$departure_date = $b['departure_date'] ?? '---';
$day_ar = ['الأحد', 'الإثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة', 'السبت'];
$departure_day = '---';
$booking_day = '---';
if ($booking_date && $booking_date !== '---') {
  $ts_b = strtotime($booking_date);
  if ($ts_b) $booking_day = $day_ar[(int)date('w', $ts_b)];
}
if ($departure_date && $departure_date !== '---') {
  $ts = strtotime($departure_date);
  if ($ts) $departure_day = $day_ar[(int)date('w', $ts)];
}
$booking_date_with_day = ($booking_day !== '---') ? $booking_day . ' - ' . htmlspecialchars($booking_date) : htmlspecialchars($booking_date);
$departure_date_with_day = ($departure_day !== '---') ? $departure_day . ' - ' . htmlspecialchars($departure_date) : htmlspecialchars($departure_date);
$arrival_time = htmlspecialchars($b['arrival_time'] ?? '---');
$attendance_time = htmlspecialchars($b['attendance_time'] ?? '---');
$attendance_period = '';
if ($attendance_time !== '---') {
  $hr = (int)explode(':', $attendance_time)[0];
  $attendance_period = ($hr < 12) ? 'صباحاً' : 'مساءً';
}
$departure_time = htmlspecialchars($b['departure_time'] ?? '---');
$departure_period = '';
if ($departure_time !== '---') {
  $hr = (int)explode(':', $departure_time)[0];
  $departure_period = ($hr < 12) ? 'صباحاً' : 'مساءً';
}

$discount_value = (float)($b['discount'] ?? 0);
$discount_formatted = number_format($discount_value, 2);
$sale_price_val = (float)($b['sale_price'] ?? 0);
$sale_price = number_format($sale_price_val, 2);
$remaining_val = (float)($b['remaining_amount'] ?? 0);
$remaining_price = number_format($remaining_val, 2);
$has_remaining = $remaining_val > 0.001;
$amount_received_val = (float)($b['amount_received'] ?? 0);
$amount_received = number_format($amount_received_val, 2);
$currency_symbol = htmlspecialchars($b['currency_symbol'] ?? '');
$currency_name = htmlspecialchars($b['currency_name'] ?? '');
$currency_before_price = $currency_symbol ? $currency_symbol . ' ' : '';

$notes = htmlspecialchars($b['notes'] ?? '');
$created_by_user = htmlspecialchars($b['created_by_username'] ?? ($b['created_by_user_full_name'] ?? ($_SESSION['username'] ?? 'النظام')));

$site_name = htmlspecialchars($settings['site_name'] ?? 'نظام الغزالي');
$site_name_en = htmlspecialchars($settings['site_name_en'] ?? 'ALGHAZALI');
$address_1 = htmlspecialchars($settings['header_address_1'] ?? '');
$phone_1 = htmlspecialchars($settings['header_phone_1'] ?? '');
$phone_2 = htmlspecialchars($settings['header_phone_2'] ?? '');

$pickup_point = htmlspecialchars($b['from_city_name'] ?? '---');
$boarding_point = htmlspecialchars($b['boarding_point'] ?? ($b['from_city_name'] ?? '---'));

$site_addresses = '';
if ($address_1) $site_addresses .= $address_1;
if ($phone_1) $site_addresses .= ($site_addresses ? ' - ' : '') . 'هاتف: ' . $phone_1;
if ($phone_2) $site_addresses .= ' - ' . $phone_2;

$current_user = htmlspecialchars($_SESSION['username'] ?? 'النظام');
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">

<head>
  <meta charset="UTF-8">
  <title>تذكرة حجز - <?php echo $booking_number; ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800;900&display=swap" rel="stylesheet">
  <style>
    @import url('https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800;900&display=swap');

    * {
      box-sizing: border-box;
      margin: 0;
      padding: 0
    }

    body {
      font-family: 'Cairo', Tahoma, Arial, sans-serif;
      background: #ddd;
      padding: 15px
    }

    .ticket {
      width: 290mm;
      height: 80mm;
      background: #fff;
      border: 1px solid #000;
      padding: 1mm;
      overflow: hidden;
      margin: 0 auto
    }

    .inner {
      border: 1.5px solid #000;
      height: 100%;
      padding: 1.5mm 2mm;
      display: flex;
      flex-direction: column
    }

    table {
      border-collapse: collapse;
      width: 100%
    }

    td {
      border: 1px solid #000;
      padding: 0 4px;
      font-size: 9.5px;
      text-align: center;
      height: 19px;
      vertical-align: middle
    }

    .lbl {
      color: #555;
      font-weight: 600;
      white-space: nowrap
    }

    .val {
      font-weight: 800;
      color: #000
    }

    .header td {
      height: 22px
    }

    .route .cell-in {
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 10px;
      font-size: 12px;
      font-weight: 900
    }

    .arrow {
      width: 70px;
      height: 17px;
      flex-shrink: 0
    }

    .main {
      display: grid;
      grid-template-columns: 2.28fr .72fr;
      gap: 6px;
      flex: 1;
      min-height: 0
    }

    .right-area {
      display: flex;
      flex-direction: column;
      gap: 4px;
      min-height: 0
    }

    .tables {
      display: grid;
      grid-template-columns: 1fr 1.05fr;
      gap: 5px
    }

    .left-panel {
      position: relative;
      display: flex;
      flex-direction: column;
      justify-content: space-between;
      align-items: center;
      padding: 4px 2px
    }

    .vertical {
      position: absolute;
      top: 6px;
      right: 1px;
      writing-mode: vertical-rl;
      font-weight: 800;
      font-size: 10px;
      display: none
    }

    .vertical.show-reprint {
      display: block
    }

    .logo-box {
      display: flex;
      flex-direction: column;
      align-items: center;
      text-align: center
    }

    .brand {
      font-weight: 900;
      font-size: 14px;
      line-height: 1.1
    }

    .brand-en {
      font-weight: 900;
      font-size: 9px;
      letter-spacing: 1px
    }

    .sub {
      font-weight: 700;
      font-size: 8.5px
    }

    .logo-img {
      max-height: 82px;
      max-width: 100px;
      object-fit: contain;
      margin-bottom: 3px
    }

    .left-bottom {
      display: flex;
      align-items: flex-end;
      justify-content: space-between;
      width: 100%;
      padding: 0 3px 2px
    }

    .price {
      font-weight: 900;
      font-size: 10px;
      text-align: center;
      line-height: 1.9
    }

    .qr-img {
      width: 82px;
      height: 82px;
      object-fit: contain;
      image-rendering: pixelated
    }

    .terms,
    .address {
      border: 1px solid #000;
      padding: 2px 6px;
      font-size: 8px;
      font-weight: 800;
      line-height: 1.7;
      text-align: center
    }

    .address {
      font-weight: 700
    }

    .btn-bar {
      position: fixed;
      top: 10px;
      left: 50%;
      transform: translateX(-50%);
      z-index: 9999;
      display: flex;
      gap: 10px
    }

    .btn-bar button {
      font-family: 'Cairo', Tahoma;
      padding: 10px 24px;
      border-radius: 30px;
      border: none;
      font-weight: 700;
      cursor: pointer;
      box-shadow: 0 4px 12px rgba(0, 0, 0, .15)
    }

    .btn-print {
      background: #0d6efd;
      color: #fff
    }

    .btn-close {
      background: #6c757d;
      color: #fff
    }

    @page {
      size: 290mm 80mm;
      margin: 0
    }

    @media print {
      body {
        padding: 0;
        background: #fff
      }

      .ticket {
        border: none;
        padding: 0
      }

      .btn-bar {
        display: none
      }
    }
  </style>
</head>

<body>

  <div class="btn-bar no-print">
    <button onclick="window.print()" class="btn-print">🖨️ طباعة الآن</button>
    <button onclick="window.close()" class="btn-close">✕ إغلاق النافذة</button>
  </div>

  <div class="ticket">
    <div class="inner">
      <div class="main">

        <!-- المنطقة اليمنى -->
        <div class="right-area">

          <table class="header">
            <tr>
              <td class="lbl" style="width:9%">نوع الرحلة</td>
              <td class="val" style="width:12%"><?php echo htmlspecialchars($bus_flight_type_ar); ?></td>
              <td class="lbl" style="width:9%">نوع التذكرة</td>
              <td class="val" style="width:8%"><?php echo $ticket_type; ?></td>
              <td class="route" style="width:42%">
                <div class="cell-in">
                  <span><?php echo $from_city; ?></span>
                  <svg class="arrow" viewBox="0 0 120 30">
                    <path d="M118 11 L40 11 L40 3 L4 15 L40 27 L40 19 L118 19 Z"
                      fill="#fff" stroke="#000" stroke-width="3" stroke-linejoin="round" />
                  </svg>
                  <span><?php echo $to_city; ?></span>
                </div>
              </td>
              <td class="lbl" style="width:9%">رقم التذكرة</td>
              <td class="val" style="width:11%"><span dir="ltr"><?php echo htmlspecialchars($booking_number); ?></span></td>
            </tr>
          </table>

          <div class="tables">
            <table>
              <tr>
                <td class="lbl" style="width:22%">الإسم</td>
                <td colspan="3" class="val" style="font-size:10.5px"><?php echo $traveler_name; ?></td>
              </tr>
              <tr>
                <td class="lbl">العمر</td>
                <td class="val"><?php echo $age; ?></td>
                <td class="lbl">رقم الهوية</td>
                <td class="val"><span dir="ltr"><?php echo $id_number; ?></span></td>
              </tr>
              <tr>
                <td class="lbl">نوع الهوية</td>
                <td colspan="3" class="val"><?php echo $id_type; ?></td>
              </tr>
              <tr>
                <td class="lbl">تاريخ الإصدار</td>
                <td class="val"><span dir="ltr"><?php echo $issue_date; ?></span></td>
                <td class="lbl">جهة الإصدار</td>
                <td class="val"><?php echo $issue_place; ?></td>
              </tr>
              <tr>
                <td class="lbl">نقطة الصعود</td>
                <td colspan="3" class="val"><?php echo $boarding_point; ?></td>
              </tr>
            </table>
            <table>
              <tr>
                <td class="lbl">تاريخ الحجز</td>
                <td class="val" colspan="2"><?php echo $booking_date_with_day; ?></td>
                <td class="lbl">الوقت</td>
                <td class="val" colspan="2"><span dir="ltr"><?php echo $booking_time; ?></span></td>
              </tr>
              <tr>
                <td class="lbl">تاريخ الرحلة</td>
                <td class="val" colspan="5"><?php echo $departure_date_with_day; ?></td>
              </tr>
              <tr>
                <td class="lbl">السعر</td>
                <td class="val" colspan="2"><span dir="ltr"><?php echo $currency_before_price . $sale_price; ?></span></td>
                <td class="lbl">المبلغ الباقي</td>
                <td class="val" colspan="2"><span dir="ltr"><?php echo $currency_before_price . $remaining_price; ?></span></td>
              </tr>
              <tr>
                <td class="lbl">الخصم</td>
                <td class="val" colspan="2"><span dir="ltr"><?php echo $currency_before_price . $discount_formatted; ?></span></td>
                <td class="lbl">الملاحظات</td>
                <td class="val" colspan="2"><?php echo $notes; ?></td>
              </tr>
              <tr>
                <td class="lbl">المستخدم</td>
                <td colspan="5" class="val"><?php echo $created_by_user; ?></td>
              </tr>
            </table>
          </div>

          <div class="terms">
            <?php
            $default_terms = "التذكرة صالحة لمدة شهر من تاريخ الإصدار * بإستصلاحية تذكرة للمرة 60 يوم * في حال تأجيل السفر أو إلغاءه قبل 12 ساعة من موعد الرحلة تطبق غرامة التأخير 30% وغرامة الإلغاء 50%، * وتطبق غرامة التأجيل خلال 12 ساعة من مغادرة الرحلة 50% وغرامة الإرجاع 100% * موعد الإرجاع والتغيير قبل وبعد مغادرة الرحلة 100% * لا يحق للمطالبة بأي تعويض أو التنازل بالتذكرة للغير\n* المسافر عليه مراقبة أمتعته عند التسجيل والتحميل ولدى الجهات المختصة، والشركة غير مسؤولة عن الأمتعة والأشياء الثمينة التي يصحبها الراكب في كل الظروف، وأقصى حد للتعويض في أي ظرف ما يعادل خمسين ريال سعودي للحقيبة، * الوزن المسموح به للمسافر هو 40 كيلو حقيبة متوسطة الحجم (شنطة واحدة فقط) يمنع الحمل الزائد والطويل والبراميل والدراجات والكرتون والفرش والبطانيات والكراتين الكبيرة وحتى أي كميات تجارية مهما كان نوعها * لا يتم توقيف الحافلات لنزول الركاب، إلا في محطات التوقف للوجبات، والأكل والتدخين ممنوع نهائياً على جميع رحلات الشركة.";
            $dynamic_terms = $settings['bus_flight_service_terms'] ?? '';
            $terms_text = !empty(trim($dynamic_terms)) ? $dynamic_terms : $default_terms;
            echo nl2br(htmlspecialchars($terms_text));
            ?>
          </div>

          <div class="address">
            <?php echo $site_name; ?> | <?php echo ($site_addresses ?: ($branch_name ?? '')) . ($phone_1 ? ' - هاتف: ' . $phone_1 : '') . ($phone_2 ? ' - ' . $phone_2 : ''); ?>
          </div>
        </div>

        <!-- العمود الأيسر: الشعار فوق والباركود تحت -->
        <div class="left-panel">
          <div class="vertical" id="reprintMark">بدل فاقد - 2</div>

          <div class="logo-box">
            <?php if ($logo_path): ?>
              <img src="<?php echo $logo_path; ?>" alt="logo" class="logo-img">
            <?php else: ?>
              <svg width="84" height="72" viewBox="0 0 200 170">
                <defs>
                  <linearGradient id="g1" x1="0" y1="0" x2="1" y2="1">
                    <stop offset="0" stop-color="#f6d876" />
                    <stop offset=".45" stop-color="#d9a916" />
                    <stop offset="1" stop-color="#8f6b00" />
                  </linearGradient>
                  <linearGradient id="g2" x1="1" y1="0" x2="0" y2="1">
                    <stop offset="0" stop-color="#f6d876" />
                    <stop offset=".5" stop-color="#caa00e" />
                    <stop offset="1" stop-color="#a37c00" />
                  </linearGradient>
                </defs>
                <path d="M100 24 l7 9 -7 9 -7 -9 Z" fill="#d9a916" />
                <path d="M55 32 l6 7 -6 7 -6 -7 Z" fill="#d9a916" />
                <path d="M145 32 l6 7 -6 7 -6 -7 Z" fill="#d9a916" />
                <path d="M100 40 L114 54 L142 44 L124 68 L100 82 L76 68 L58 44 L86 54 Z" fill="#111" />
                <path d="M91 63 L100 70 L109 63 L100 75 Z" fill="#fff" />
                <path d="M38 48 L100 98 L162 48 L162 78 L100 128 L38 78 Z" fill="url(#g1)" />
                <path d="M100 98 L162 48 L162 78 L100 128 Z" fill="url(#g2)" opacity=".85" />
                <path d="M38 92 L38 152 L80 122 Z" fill="url(#g2)" />
                <path d="M162 92 L162 152 L120 122 Z" fill="url(#g1)" />
              </svg>
            <?php endif; ?>
            <div class="brand"><?php echo $site_name; ?></div>
            <div class="brand-en"><?php echo $site_name_en; ?></div>
            <div class="sub"><?php echo htmlspecialchars($settings['company_activities'] ?? 'للسياحة والسفر وخدمات الحج والعمرة'); ?></div>
          </div>

          <div class="left-bottom">
            <img src="<?php echo $qr_url; ?>" alt="QR" class="qr-img">
            <div class="price"><?php echo $currency_before_price . $sale_price; ?><br><?php echo ($currency_symbol ? $currency_symbol : $currency_name); ?></div>
          </div>
        </div>

      </div>
    </div>
  </div>

</body>
<script>
  (function() {
    var bookingKey = 'bus_flight_booking_print_count_<?php echo addslashes((string)$booking_number); ?>';
    var reprintMark = document.getElementById('reprintMark');
    var params = new URLSearchParams(window.location.search);
    var forcedReprint = params.get('reprint') === '1';
    var printCount = parseInt(localStorage.getItem(bookingKey) || '0', 10);

    if (reprintMark && (forcedReprint || printCount > 0)) {
      reprintMark.classList.add('show-reprint');
    }

    window.addEventListener('afterprint', function() {
      localStorage.setItem(bookingKey, String(printCount + 1));
    });
  })();
</script>

</html>
<?php ob_end_flush(); ?>
