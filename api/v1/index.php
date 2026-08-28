<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';

// منع إظهار الأخطاء كـ HTML لضمان صحة استجابة JSON
ini_set('display_errors', '0');
error_reporting(E_ALL);
set_exception_handler(function ($e) {
    error_log(sprintf('Rihla API exception: %s in %s:%d', $e->getMessage(), $e->getFile(), $e->getLine()));
    App\Includes\Response::error('حدث خطأ غير متوقع في الخادم.', 'INTERNAL_SERVER_ERROR', 500);
});

use App\Includes\Response;
use App\Includes\Security;
use App\Includes\Auth;
use App\Includes\BookingService;
use App\Includes\ReferenceService;
use App\Includes\AgentService;
use App\Includes\DashboardService;
use App\Includes\AdminService;
use App\Includes\ContactService;
use App\Includes\ContactMessageService;
use App\Includes\SiteSettingsService;
use App\Includes\TripDisplaySettingsService;
use App\Includes\BankService;
use App\Includes\CompanyFinanceService;
use App\Includes\OtpService;
use App\Includes\PaymentService;

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: strict-origin-when-cross-origin');

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$route = trim((string) ($_GET['route'] ?? ''), '/');
// فتح نقطة الدخول الأساسية مباشرة يعرض حالة الخدمة بدل رسالة مسار غير موجود.
if ($route === '' && $method === 'GET') {
    $route = 'health';
}
$auth = new Auth($database);
$bookingService = new BookingService($database, (int) ($appConfig['booking_hold_minutes'] ?? 30));
$references = new ReferenceService($database, $bookingService);
$agents = new AgentService($database);
$dashboard = new DashboardService($database, $bookingService);
$adminOps = new AdminService($database);
$contacts = new ContactService($database);
$contactMessages = new ContactMessageService($database);
$siteSettings = new SiteSettingsService($database);
$tripDisplaySettings = new TripDisplaySettingsService($database);
$banks = new BankService($database);
$companyFinance = new CompanyFinanceService($database);
$otp = new OtpService($database);
$payments = new PaymentService($database, $appConfig);

if ($method === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($route === 'health' && $method === 'GET') {
    Response::success('خدمة واجهة البرمجة تعمل.', [
        'service' => 'bus-booking-api',
        'version' => 'v1',
        'csrf_token' => Security::csrfToken(),
    ]);
}

if ($route === 'auth/otp/channels' && $method === 'GET') {
    $email = trim((string) ($_GET['email'] ?? ''));
    $phone = trim((string) ($_GET['phone'] ?? ''));
    Response::success('تم جلب قنوات التحقق المتاحة.', ['settings' => $otp->publicSettings(), 'channels' => $otp->availableChannels($email !== '' ? $email : null, $phone !== '' ? $phone : null)]);
}

if ($route === 'auth/otp/registration/request' && $method === 'POST') {
    Security::assertCsrf();
    Response::success('تم إرسال رمز التحقق.', $otp->startRegistration(Security::jsonInput()), 202);
}

if ($route === 'auth/otp/login/request' && $method === 'POST') {
    Security::assertCsrf();
    Response::success('تم إرسال رمز التحقق.', $otp->startLogin(Security::jsonInput()), 202);
}

if ($route === 'auth/otp/verify' && $method === 'POST') {
    Security::assertCsrf();
    $result = $otp->verify(Security::jsonInput(), $auth);
    $csrfToken = Security::csrfToken();
    session_write_close();
    Response::success('تم التحقق بنجاح.', [...$result, 'csrf_token' => $csrfToken]);
}

if ($route === 'auth/otp/resend' && $method === 'POST') {
    Security::assertCsrf();
    Response::success('تمت إعادة إرسال رمز التحقق.', $otp->resend(Security::jsonInput()), 202);
}

if ($route === 'auth/otp/status' && $method === 'GET') {
    Response::success('تم جلب حالة التحقق.', $otp->status((string) ($_GET['challenge_id'] ?? '')));
}

if ($route === 'auth/register' && $method === 'POST') {
    Security::assertCsrf();
    $input = Security::jsonInput();
    if ((int) ($otp->publicSettings()['enabled'] ?? 0) === 1) {
        Response::success('تم تجهيز طلب التسجيل وإرسال رمز التحقق.', $otp->startRegistration($input), 202);
    }
    $user = $auth->registerCustomer($input);
    $csrfToken = Security::csrfToken();
    session_write_close();
    Response::success('تم إنشاء حساب العميل وتسجيل الدخول بنجاح.', ['user' => $user, 'csrf_token' => $csrfToken], 201);
}

if ($route === 'auth/login' && $method === 'POST') {
    Security::assertCsrf();
    $input = Security::jsonInput();
    if ((int) ($otp->publicSettings()['enabled'] ?? 0) === 1 && !empty($input['otp_mode'])) {
        Response::success('تم تجهيز طلب الدخول وإرسال رمز التحقق.', $otp->startLogin($input), 202);
    }
    $user = $auth->login($input);
    $csrfToken = Security::csrfToken();
    session_write_close();
    Response::success('تم تسجيل الدخول بنجاح.', ['user' => $user, 'csrf_token' => $csrfToken]);
}

if ($route === 'admin/otp-settings' && $method === 'GET') {
    $auth->requirePermissions(['manage_settings']);
    Response::success('تم جلب إعدادات OTP.', $otp->adminSettings());
}

if ($route === 'admin/otp-settings' && $method === 'PUT') {
    Security::assertCsrf();
    $actor = $auth->requirePermissions(['manage_settings']);
    Response::success('تم حفظ إعدادات OTP.', $otp->updateSettings(Security::jsonInput(), $actor));
}

if ($route === 'admin/otp-logs' && $method === 'GET') {
    $auth->requirePermissions(['manage_settings']);
    Response::success('تم جلب سجل OTP.', $otp->logs((int) ($_GET['limit'] ?? 50)));
}

if ($route === 'admin/otp-test' && $method === 'POST') {
    Security::assertCsrf();
    $actor = $auth->requirePermissions(['manage_settings']);
    Response::success('تم تنفيذ اختبار قناة OTP.', $otp->sendTest(Security::jsonInput(), $actor), 202);
}

if ($route === 'auth/logout' && $method === 'POST') {
    Security::assertCsrf();
    $auth->logout();
    Response::success('تم تسجيل الخروج بنجاح.');
}

if ($route === 'auth/me' && $method === 'GET') {
    $user = $auth->requireUser();
    Response::success('تم جلب بيانات المستخدم.', ['user' => $user, 'csrf_token' => Security::csrfToken()]);
}

if ($route === 'auth/me' && $method === 'PUT') {
    Security::assertCsrf();
    $actor = $auth->requireUser();
    $user = $auth->updateCurrentUser($actor, Security::jsonInput());
    Response::success('تم تحديث بيانات الحساب.', ['user' => $user, 'csrf_token' => Security::csrfToken()]);
}

if ($route === 'auth/me/customer-profile' && $method === 'GET') {
    $actor = $auth->requireUser();
    Response::success('تم جلب بيانات المسافر المحفوظة.', ['profile' => $auth->customerProfile($actor)]);
}

if ($route === 'auth/me/customer-profile' && $method === 'PUT') {
    Security::assertCsrf();
    $actor = $auth->requireUser();
    Response::success('تم حفظ بيانات المسافر لاستخدامها لاحقًا.', ['profile' => $auth->updateCustomerProfile($actor, Security::jsonInput())]);
}

if ($route === 'auth/me/profile-image' && $method === 'POST') {
    Security::assertCsrf();
    $actor = $auth->requireUser();
    $media = $auth->uploadProfileImage($actor, $_FILES['file'] ?? []);
    Response::success('تم حفظ صورة المستخدم.', ['media' => $media, 'user' => $auth->currentUser(), 'csrf_token' => Security::csrfToken()]);
}

if ($route === 'auth/token' && $method === 'POST') {
    Security::assertCsrf();
    $user = $auth->requireUser();
    $input = Security::jsonInput();
    $token = $auth->issueApiToken((int) $user['id'], (string) ($input['name'] ?? 'جلسة تطبيق'));
    Response::success('تم إنشاء رمز وصول للتطبيق. احتفظ به في مكان آمن، إذ لن يظهر مجددًا.', $token, 201);
}

if ($route === 'auth/refresh' && $method === 'POST') {
    $user = $auth->requireUser();
    Response::success('تم تجديد جلسة الوصول.', ['user' => $user, 'csrf_token' => Security::csrfToken()]);
}

if ($route === 'countries' && $method === 'GET') {
    Response::success('تم جلب الدول.', ['items' => $references->countries()]);
}

if ($route === 'currencies' && $method === 'GET') {
    Response::success('تم جلب العملات.', ['items' => $references->currencies()]);
}

if ($route === 'cities' && $method === 'GET') {
    $countryId = filter_var($_GET['country_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($countryId === false) {
        Response::error('معرّف الدولة مطلوب وصالح.', 'VALIDATION_ERROR', 422);
    }
    Response::success('تم جلب المدن.', ['items' => $references->cities($countryId)]);
}

if ($route === 'stations' && $method === 'GET') {
    $cityId = filter_var($_GET['city_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($cityId === false) {
        Response::error('معرّف المدينة مطلوب وصالح.', 'VALIDATION_ERROR', 422);
    }
    Response::success('تم جلب المحطات.', ['items' => $references->stations($cityId)]);
}

if ($route === 'companies' && $method === 'GET') {
    Response::success('تم جلب شركات النقل.', ['items' => $references->companies()]);
}

if ($route === 'contact-channels' && $method === 'GET') {
    Response::success('تم جلب قنوات التواصل.', ['items' => $contacts->publicChannels()]);
}

if ($route === 'site-settings' && $method === 'GET') {
    Response::success('تم جلب إعدادات الموقع.', ['settings' => $siteSettings->publicSettings()]);
}

if ($route === 'admin/site-settings' && $method === 'GET') {
    $auth->requirePermissions(['manage_settings']);
    Response::success('تم جلب إعدادات الموقع للإدارة.', ['settings' => $siteSettings->publicSettings()]);
}

if ($route === 'admin/site-settings' && $method === 'PUT') {
    Security::assertCsrf();
    $actor = $auth->requirePermissions(['manage_settings']);
    Response::success('تم حفظ إعدادات الموقع.', ['settings' => $siteSettings->update($actor, Security::jsonInput())]);
}

if ($route === 'admin/trip-display-settings' && $method === 'GET') {
    $actor = $auth->requirePermissions(['manage_settings']);
    Response::success('تم جلب إعدادات عرض الرحلات.', ['settings' => $tripDisplaySettings->get($actor)]);
}

if ($route === 'trip-display-public' && $method === 'GET') {
    Response::success('تم جلب إعداد العرض العام.', ['settings' => $tripDisplaySettings->publicPriceBadgeSetting()]);
}

if ($route === 'payment-options' && $method === 'GET') {
    Response::success('تم جلب طرق الدفع المتاحة.', [
        'settings' => $tripDisplaySettings->publicPaymentSettings(),
        'banks' => $banks->active(),
        'gateway' => $payments->publicOptions(),
        'tax' => $payments->publicTaxSettings(),
    ]);
}

if ($route === 'payments/webhook/moyasar' && $method === 'POST') {
    $rawPayload = (string) file_get_contents('php://input');
    $headers = [
        'x-webhook-secret' => (string) ($_SERVER['HTTP_X_WEBHOOK_SECRET'] ?? ''),
        'x-moyasar-secret' => (string) ($_SERVER['HTTP_X_MOYASAR_SECRET'] ?? ''),
    ];
    try {
        Response::success('تم استلام webhook.', $payments->handleWebhook('moyasar', $rawPayload, $headers));
    } catch (Throwable $exception) {
        Response::error('تم رفض webhook أو تعذر معالجته.', 'WEBHOOK_REJECTED', 400);
    }
}

if ($route === 'admin/tax-settings' && $method === 'GET') {
    $auth->requirePermissions(['manage_settings']);
    Response::success('تم جلب إعدادات الضريبة والفوترة.', $payments->adminTaxSettings());
}

if ($route === 'admin/tax-settings' && $method === 'PUT') {
    Security::assertCsrf();
    $actor = $auth->requirePermissions(['manage_settings']);
    Response::success('تم حفظ إعدادات الضريبة والفوترة.', $payments->updateTaxSettings($actor, Security::jsonInput()));
}

if ($route === 'admin/payment-settings' && $method === 'GET') {
    $auth->requirePermissions(['manage_settings']);
    Response::success('تم جلب إعدادات بوابات الدفع.', $payments->adminSettings());
}

if ($route === 'admin/payment-settings' && $method === 'PUT') {
    Security::assertCsrf();
    $actor = $auth->requirePermissions(['manage_settings']);
    Response::success('تم حفظ إعدادات بوابة الدفع.', $payments->updateSettings($actor, Security::jsonInput()));
}

if (preg_match('#^bookings/(\\d+)/payments/hosted$#', $route, $matches) === 1 && $method === 'POST') {
    Security::assertCsrf();
    $actor = $auth->requireUser();
    Response::success('تم تجهيز صفحة الدفع الآمنة.', ['attempt' => $payments->createHostedCheckout($actor, (int) $matches[1])], 201);
}

if (preg_match('#^payments/attempts/(\\d+)$#', $route, $matches) === 1 && $method === 'GET') {
    $actor = $auth->requireUser();
    Response::success('تم جلب حالة محاولة الدفع.', ['attempt' => $payments->attemptStatus($actor, (int) $matches[1])]);
}

if ($route === 'payments/return' && $method === 'GET') {
    $actor = $auth->requireUser();
    Response::success('تم جلب حالة العودة من الدفع.', ['attempt' => $payments->attemptStatusByKey($actor, (string) ($_GET['key'] ?? ''))]);
}

if ($route === 'admin/payments' && $method === 'GET') {
    $actor = $auth->requirePermissions(['manage_settings']);
    Response::success('تم جلب سجل المدفوعات.', $payments->adminPayments($actor, (int) ($_GET['limit'] ?? 100)));
}

if ($route === 'admin/invoices' && $method === 'GET') {
    $actor = $auth->requirePermissions(['manage_settings']);
    Response::success('تم جلب الفواتير.', $payments->adminInvoices($actor, (int) ($_GET['limit'] ?? 100)));
}

if (preg_match('#^admin/payments/(\\d+)/refund$#', $route, $matches) === 1 && $method === 'POST') {
    Security::assertCsrf();
    $actor = $auth->requirePermissions(['manage_settings']);
    $input = Security::jsonInput();
    Response::success('تم إرسال طلب الاسترداد.', ['refund' => $payments->refund($actor, (int) $matches[1], isset($input['amount']) ? (string) $input['amount'] : null, (string) ($input['reason'] ?? ''))], 201);
}

if ($route === 'admin/trip-display-settings' && $method === 'PUT') {
    Security::assertCsrf();
    $actor = $auth->requirePermissions(['manage_settings']);
    Response::success('تم حفظ إعدادات عرض الرحلات.', ['settings' => $tripDisplaySettings->update($actor, Security::jsonInput())]);
}

if ($route === 'admin/banks' && $method === 'GET') {
    $actor = $auth->requirePermissions(['manage_settings']);
    Response::success('تم جلب الحسابات البنكية.', ['items' => $banks->all($actor)]);
}

if ($route === 'admin/banks' && $method === 'POST') {
    Security::assertCsrf();
    $actor = $auth->requirePermissions(['manage_settings']);
    Response::success('تمت إضافة الحساب البنكي.', ['bank' => $banks->create($actor, Security::jsonInput())], 201);
}

if (preg_match('#^admin/banks/(\\d+)$#', $route, $matches) === 1 && $method === 'PUT') {
    Security::assertCsrf();
    $actor = $auth->requirePermissions(['manage_settings']);
    Response::success('تم تعديل الحساب البنكي.', ['bank' => $banks->update($actor, (int) $matches[1], Security::jsonInput())]);
}

if (preg_match('#^admin/banks/(\\d+)/status$#', $route, $matches) === 1 && $method === 'PUT') {
    Security::assertCsrf();
    $actor = $auth->requirePermissions(['manage_settings']);
    $input = Security::jsonInput();
    Response::success('تم تحديث حالة الحساب البنكي.', ['bank' => $banks->setStatus($actor, (int) $matches[1], (string) ($input['status'] ?? 'inactive'))]);
}

if (preg_match('#^admin/trips/(\\d+)/bookings$#', $route, $matches) === 1 && $method === 'GET') {
    $actor = $auth->requirePermissions(['manage_trips']);
    Response::success('تم جلب حجوزات الرحلة.', ['items' => $adminOps->tripBookings($actor, (int) $matches[1])]);
}

if ($route === 'admin/site-settings/media' && $method === 'POST') {
    Security::assertCsrf();
    $actor = $auth->requirePermissions(['manage_settings']);
    Response::success('تم حفظ هوية الموقع.', ['media' => $siteSettings->uploadBrandMedia($actor, (string) ($_POST['slot'] ?? ''), $_FILES['file'] ?? [])]);
}

if ($route === 'contact-messages' && $method === 'POST') {
    Security::assertCsrf();
    $message = $contactMessages->createPublic(Security::jsonInput());
    Response::success('تم استلام رسالتك، وسيتواصل معك فريق الدعم قريبًا.', ['message' => $message], 201);
}

if ($route === 'admin/contact-messages' && $method === 'GET') {
    $actor = $auth->requirePermissions(['manage_settings']);
    Response::success('تم جلب رسائل التواصل.', ['items' => $contactMessages->adminMessages()]);
}

if (preg_match('#^admin/contact-messages/(\\d+)/status$#', $route, $matches) === 1 && $method === 'PUT') {
    Security::assertCsrf();
    $actor = $auth->requirePermissions(['manage_settings']);
    $message = $contactMessages->updateStatus($actor, (int) $matches[1], Security::jsonInput());
    Response::success('تم تحديث حالة رسالة التواصل.', ['message' => $message]);
}

if ($route === 'routes' && $method === 'GET') {
    $statement = $database->pdo()->query(
        "SELECT r.id, r.company_id, r.code, r.name_ar, r.status, co.trade_name AS company_name
         FROM routes r INNER JOIN companies co ON co.id = r.company_id WHERE r.status = 'active' AND co.status = 'active' ORDER BY r.name_ar"
    );
    Response::success('تم جلب المسارات.', ['items' => $statement->fetchAll()]);
}

if (preg_match('#^routes/(\d+)$#', $route, $matches) === 1 && $method === 'GET') {
    $statement = $database->pdo()->prepare(
        'SELECT r.id, r.company_id, r.code, r.name_ar, r.status, co.trade_name AS company_name FROM routes r INNER JOIN companies co ON co.id = r.company_id WHERE r.id = :id LIMIT 1'
    );
    $statement->execute(['id' => $matches[1]]);
    $routeItem = $statement->fetch();
    if (!is_array($routeItem)) {
        Response::error('المسار المطلوب غير موجود.', 'NOT_FOUND', 404);
    }
    $stops = $database->pdo()->prepare('SELECT rs.stop_order, c.name_ar AS city_name, s.name_ar AS station_name, rs.arrival_offset_minutes, rs.departure_offset_minutes FROM route_stops rs INNER JOIN stations s ON s.id = rs.station_id INNER JOIN cities c ON c.id = s.city_id WHERE rs.route_id = :route_id ORDER BY rs.stop_order');
    $stops->execute(['route_id' => $routeItem['id']]);
    $routeItem['stops'] = $stops->fetchAll();
    Response::success('تم جلب تفاصيل المسار.', ['item' => $routeItem]);
}

if ($route === 'trips/upcoming' && $method === 'GET') {
    $limit = filter_var($_GET['limit'] ?? 20, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 50]]);
    Response::success('تم جلب الرحلات القادمة.', ['items' => $references->upcomingTrips($limit === false ? 20 : $limit)]);
}

if ($route === 'trips/search' && $method === 'GET') {
    $originCityId = filter_var($_GET['origin_city_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $destinationCityId = filter_var($_GET['destination_city_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $date = (string) ($_GET['date'] ?? '');
    $busType = trim((string) ($_GET['bus_type'] ?? ''));
    $validDate = DateTimeImmutable::createFromFormat('Y-m-d', $date);
    if ($originCityId === false || $destinationCityId === false || $originCityId === $destinationCityId || $validDate === false || $validDate->format('Y-m-d') !== $date || mb_strlen($busType) > 100) {
        Response::error('تحقق من مدينة الانطلاق والوصول وتاريخ السفر.', 'VALIDATION_ERROR', 422);
    }
    Response::success('تم جلب الرحلات المتاحة.', ['items' => $references->searchTrips($originCityId, $destinationCityId, $date, $busType)]);
}

if (preg_match('#^trips/(\d+)/seats$#', $route, $matches) === 1 && $method === 'GET') {
    $segmentId = filter_var($_GET['segment_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($segmentId === false) {
        Response::error('معرّف المسار الفرعي مطلوب.', 'VALIDATION_ERROR', 422);
    }
    Response::success('تم جلب مخطط المقاعد.', ['items' => $references->seats((int) $matches[1], $segmentId)]);
}

if (preg_match('#^trips/(\d+)$#', $route, $matches) === 1 && $method === 'GET') {
    Response::success('تم جلب تفاصيل الرحلة.', ['item' => $references->trip((int) $matches[1])]);
}

if ($route === 'bookings' && $method === 'POST') {
    Security::assertCsrf();
    $actor = $auth->requireUser();
    $booking = $bookingService->create($actor, Security::jsonInput());
    Response::success('تم إنشاء طلب الحجز وهو بانتظار تأكيد الإدارة.', ['booking' => $booking], 201);
}

if ($route === 'bookings' && $method === 'GET') {
    $actor = $auth->requireUser();
    Response::success('تم جلب الحجوزات.', ['items' => $bookingService->listFor($actor)]);
}

if (preg_match('#^bookings/(\d+)/confirm$#', $route, $matches) === 1 && $method === 'PUT') {
    Security::assertCsrf();
    $actor = $auth->requirePermissions(['confirm_booking']);
    $booking = $bookingService->confirm($actor, (int) $matches[1]);
    Response::success('تم تأكيد الحجز وإصدار التذاكر.', ['booking' => $booking]);
}

if (preg_match('#^bookings/(\d+)/payment$#', $route, $matches) === 1 && $method === 'PUT') {
    Security::assertCsrf();
    $actor = $auth->requirePermissions(['receive_payment']);
    $booking = $bookingService->receivePayment($actor, (int) $matches[1], Security::jsonInput());
    Response::success('تم تأكيد استلام الدفع وتحديث الحساب المالي.', ['booking' => $booking]);
}

if (preg_match('#^bookings/(\d+)/reject$#', $route, $matches) === 1 && $method === 'PUT') {
    Security::assertCsrf();
    $actor = $auth->requirePermissions(['reject_booking']);
    $input = Security::jsonInput();
    $booking = $bookingService->reject($actor, (int) $matches[1], (string) ($input['reason'] ?? 'تعذر قبول طلب الحجز.'));
    Response::success('تم رفض الحجز وتحرير المقاعد.', ['booking' => $booking]);
}

if (preg_match('#^bookings/(\d+)/receipt$#', $route, $matches) === 1 && $method === 'POST') {
    Security::assertCsrf();
    $actor = $auth->requireUser();
    $media = $bookingService->uploadPaymentReceipt($actor, (int) $matches[1], $_FILES['file'] ?? []);
    Response::success('تم حفظ صورة إشعار التحويل.', ['media' => $media]);
}

if (preg_match('#^bookings/(\d+)/cancel$#', $route, $matches) === 1 && $method === 'POST') {
    Security::assertCsrf();
    $actor = $auth->requireUser();
    $input = Security::jsonInput();
    $booking = $bookingService->cancel($actor, (int) $matches[1], (string) ($input['reason'] ?? 'تم إلغاء الحجز بناءً على طلب المستخدم.'));
    Response::success('تم إلغاء الحجز وتحرير المقاعد.', ['booking' => $booking]);
}

if (preg_match('#^bookings/(\d+)$#', $route, $matches) === 1 && $method === 'GET') {
    $actor = $auth->requireUser();
    Response::success('تم جلب تفاصيل الحجز.', ['booking' => $bookingService->detailsFor($actor, (int) $matches[1])]);
}

if (preg_match('#^bookings/(\d+)/review$#', $route, $matches) === 1 && $method === 'POST') {
    Security::assertCsrf();
    $actor = $auth->requireUser();
    $review = $bookingService->createReview($actor, (int) $matches[1], Security::jsonInput());
    Response::success('تم حفظ تقييمك للرحلة.', ['review' => $review], 201);
}

if (preg_match('#^tickets/verify/([a-f0-9]{64})$#', $route, $matches) === 1 && $method === 'GET') {
    $statement = $database->pdo()->prepare(
        'SELECT tk.ticket_number, tk.status, tk.issued_at, p.full_name_ar, bs.seat_code, t.trip_number, t.departure_at, co.trade_name AS company_name
         FROM tickets tk INNER JOIN booking_passengers bp ON bp.id = tk.booking_passenger_id INNER JOIN passengers p ON p.id = bp.passenger_id
         INNER JOIN booking_seats bks ON bks.id = tk.booking_seat_id INNER JOIN bus_seats bs ON bs.id = bks.bus_seat_id
         INNER JOIN bookings b ON b.id = tk.booking_id INNER JOIN trips t ON t.id = b.trip_id INNER JOIN companies co ON co.id = b.company_id
         WHERE tk.qr_token = :token LIMIT 1'
    );
    $statement->execute(['token' => $matches[1]]);
    $ticket = $statement->fetch();
    if (!is_array($ticket)) {
        Response::error('رمز التذكرة غير صالح.', 'NOT_FOUND', 404);
    }
    Response::success('تم التحقق من التذكرة.', ['ticket' => $ticket]);
}

if ($route === 'tickets' && $method === 'GET') {
    $actor = $auth->requireUser();
    $params = [];
    if (in_array('super_admin', $actor['roles'], true)) {
        $scope = '1=1';
    } elseif ($actor['agent_id'] !== null) {
        $scope = 'b.agent_id = :agent_id';
        $params['agent_id'] = $actor['agent_id'];
    } elseif ($actor['customer_id'] !== null) {
        $scope = 'b.customer_id = :customer_id';
        $params['customer_id'] = $actor['customer_id'];
    } elseif ($actor['company_id'] !== null && in_array('view_company_bookings', $actor['permissions'], true)) {
        $scope = 'b.company_id = :company_id';
        $params['company_id'] = $actor['company_id'];
    } else {
        Response::error('لا تملك صلاحية عرض التذاكر.', 'FORBIDDEN', 403);
    }
    $tickets = $database->pdo()->prepare(
        "SELECT tk.id, tk.ticket_number, tk.status, tk.qr_token, tk.issued_at, tk.amount, cu.code AS currency_code, cu.symbol_ar AS currency_symbol,
                b.booking_number, b.status AS booking_status, b.payment_status, (SELECT pay.payment_channel FROM payments pay WHERE pay.booking_id = b.id ORDER BY pay.id DESC LIMIT 1) AS payment_channel, (SELECT pay.reference_number FROM payments pay WHERE pay.booking_id = b.id ORDER BY pay.id DESC LIMIT 1) AS payment_reference_number, (SELECT pay.receipt_image_path FROM payments pay WHERE pay.booking_id = b.id ORDER BY pay.id DESC LIMIT 1) AS payment_receipt_image_path, p.full_name_ar, p.gender, bs.seat_code, t.trip_number, t.departure_at, co.trade_name AS company_name, co.latitude AS company_latitude, co.longitude AS company_longitude
         FROM tickets tk INNER JOIN bookings b ON b.id = tk.booking_id INNER JOIN booking_passengers bp ON bp.id = tk.booking_passenger_id
         INNER JOIN passengers p ON p.id = bp.passenger_id INNER JOIN booking_seats bks ON bks.id = tk.booking_seat_id INNER JOIN bus_seats bs ON bs.id = bks.bus_seat_id
         INNER JOIN trips t ON t.id = b.trip_id INNER JOIN companies co ON co.id = b.company_id INNER JOIN currencies cu ON cu.id = tk.currency_id
         WHERE {$scope} ORDER BY tk.issued_at DESC"
    );
    $tickets->execute($params);
    Response::success('تم جلب التذاكر.', ['items' => $tickets->fetchAll()]);
}

if (preg_match('#^tickets/(\d+)$#', $route, $matches) === 1 && $method === 'PUT') {
    Security::assertCsrf();
    $actor = $auth->requirePermissions(['confirm_booking']);
    $ticket = $bookingService->updateTicket($actor, (int) $matches[1], Security::jsonInput());
    Response::success('تم تحديث بيانات التذكرة.', ['ticket' => $ticket]);
}

if (preg_match('#^tickets/(\d+)$#', $route, $matches) === 1 && $method === 'GET') {
    $actor = $auth->requireUser();
    $ticket = $database->pdo()->prepare(
        'SELECT tk.*, b.booking_number, b.status AS booking_status, b.payment_status, (SELECT pay.payment_channel FROM payments pay WHERE pay.booking_id = b.id ORDER BY pay.id DESC LIMIT 1) AS payment_channel, (SELECT pay.reference_number FROM payments pay WHERE pay.booking_id = b.id ORDER BY pay.id DESC LIMIT 1) AS payment_reference_number, (SELECT pay.receipt_image_path FROM payments pay WHERE pay.booking_id = b.id ORDER BY pay.id DESC LIMIT 1) AS payment_receipt_image_path, b.trip_id, b.company_id, b.customer_id, b.agent_id, seg.route_segment_id AS segment_id, bks.bus_seat_id, p.full_name_ar, p.gender, bs.seat_code, t.trip_number, t.departure_at, co.trade_name AS company_name, co.latitude AS company_latitude, co.longitude AS company_longitude, cu.code AS currency_code, cu.symbol_ar AS currency_symbol
         FROM tickets tk INNER JOIN bookings b ON b.id = tk.booking_id INNER JOIN booking_passengers bp ON bp.id = tk.booking_passenger_id INNER JOIN passengers p ON p.id = bp.passenger_id
         INNER JOIN booking_seats bks ON bks.id = tk.booking_seat_id INNER JOIN booking_segments seg ON seg.booking_id = b.id INNER JOIN bus_seats bs ON bs.id = bks.bus_seat_id INNER JOIN trips t ON t.id = b.trip_id INNER JOIN companies co ON co.id = b.company_id INNER JOIN currencies cu ON cu.id = tk.currency_id
         WHERE tk.id = :id LIMIT 1'
    );
    $ticket->execute(['id' => $matches[1]]);
    $ticketItem = $ticket->fetch();
    if (!is_array($ticketItem)) {
        Response::error('التذكرة المطلوبة غير موجودة.', 'NOT_FOUND', 404);
    }
    $isOwner = ((int) ($ticketItem['customer_id'] ?? 0) === (int) ($actor['customer_id'] ?? 0) && $actor['customer_id'] !== null) || ((int) ($ticketItem['agent_id'] ?? 0) === (int) ($actor['agent_id'] ?? 0) && $actor['agent_id'] !== null);
    if (!in_array('super_admin', $actor['roles'], true) && !$isOwner && (int) ($ticketItem['company_id'] ?? 0) !== (int) ($actor['company_id'] ?? 0)) {
        Response::error('لا يمكن الوصول إلى هذه التذكرة.', 'FORBIDDEN', 403);
    }
    Response::success('تم جلب التذكرة.', ['ticket' => $ticketItem]);
}

if ($route === 'agent/wallet' && $method === 'GET') {
    $actor = $auth->requireUser();
    Response::success('تم جلب أرصدة الوكيل.', ['items' => $agents->walletsFor($actor)]);
}

if (preg_match('#^admin/agents/(\d+)/transactions$#', $route, $matches) === 1 && $method === 'GET') {
    $actor = $auth->requirePermissions(['manage_agents']);
    Response::success('تم جلب كشف حساب الوكيل.', ['items' => $agents->transactionsForAdmin($actor, (int) $matches[1])]);
}

if ($route === 'agent/transactions' && $method === 'GET') {
    $actor = $auth->requireUser();
    Response::success('تم جلب كشف الحساب.', ['items' => $agents->transactionsFor($actor)]);
}

if ($route === 'agent/commissions' && $method === 'GET') {
    $actor = $auth->requireUser();
    Response::success('تم جلب العمولات.', ['items' => $agents->commissionsFor($actor)]);
}

if ($route === 'agent/bookings' && $method === 'GET') {
    $actor = $auth->requireUser();
    if ($actor['agent_id'] === null) {
        Response::error('هذا المورد مخصص لحسابات الوكلاء فقط.', 'FORBIDDEN', 403);
    }
    Response::success('تم جلب حجوزات الوكيل.', ['items' => $bookingService->listFor($actor)]);
}

if (preg_match('#^admin/agents/(\d+)/wallet/credit$#', $route, $matches) === 1 && $method === 'POST') {
    Security::assertCsrf();
    $actor = $auth->requirePermissions(['manage_agents']);
    $wallet = $agents->creditWallet($actor, (int) $matches[1], Security::jsonInput());
    Response::success('تمت إضافة الرصيد وتسجيل الحركة المالية.', ['wallet' => $wallet]);
}

if (preg_match('#^admin/agents/(\d+)/financial-settings$#', $route, $matches) === 1 && $method === 'PUT') {
    Security::assertCsrf();
    $actor = $auth->requirePermissions(['manage_agents']);
    $wallet = $agents->updateFinancialSettings($actor, (int) $matches[1], Security::jsonInput());
    Response::success('تم تحديث الإعدادات المالية للوكيل.', ['wallet' => $wallet]);
}

if ($route === 'admin/operations' && $method === 'GET') {
    $actor = $auth->requirePermissions(['manage_trips']);
    Response::success('تم جلب بيانات الإدارة التشغيلية.', ['operations' => $adminOps->operations($actor)]);
}

if ($route === 'admin/contacts' && $method === 'GET') {
    $actor = $auth->requirePermissions(['manage_settings']);
    Response::success('تم جلب قنوات التواصل للإدارة.', ['items' => $contacts->adminChannels()]);
}

if ($route === 'admin/contacts' && $method === 'POST') {
    Security::assertCsrf();
    $actor = $auth->requirePermissions(['manage_settings']);
    Response::success('تمت إضافة قناة التواصل.', ['contact' => $contacts->create($actor, Security::jsonInput())], 201);
}

if (preg_match('#^admin/contacts/(\\d+)$#', $route, $matches) === 1 && $method === 'PUT') {
    Security::assertCsrf();
    $actor = $auth->requirePermissions(['manage_settings']);
    Response::success('تم تعديل قناة التواصل.', ['contact' => $contacts->update($actor, (int) $matches[1], Security::jsonInput())]);
}

if (preg_match('#^admin/contacts/(\\d+)/status$#', $route, $matches) === 1 && $method === 'PUT') {
    Security::assertCsrf();
    $actor = $auth->requirePermissions(['manage_settings']);
    Response::success('تم تحديث حالة قناة التواصل.', ['contact' => $contacts->updateStatus($actor, (int) $matches[1], Security::jsonInput())]);
}

if (preg_match('#^admin/contacts/(\\d+)$#', $route, $matches) === 1 && $method === 'DELETE') {
    Security::assertCsrf();
    $actor = $auth->requirePermissions(['manage_settings']);
    Response::success('تم حذف قناة التواصل.', ['contact' => $contacts->delete($actor, (int) $matches[1])]);
}

if ($route === 'admin/catalog' && $method === 'GET') {
    $actor = $auth->requireUser();
    Response::success('تم جلب فهرس الإدارة.', ['catalog' => $adminOps->catalog($actor)]);
}

if ($route === 'admin/company-finance/summary' && $method === 'GET') {
    $actor = $auth->requireUser();
    if (!in_array('super_admin', $actor['roles'], true) && !in_array('manage_payments', $actor['permissions'], true) && !in_array('view_financial_reports', $actor['permissions'], true)) {
        Response::error('لا تملك صلاحية عرض كشف حساب الشركة.', 'FORBIDDEN', 403);
    }
    $requestedCompanyId = filter_var($_GET['company_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    Response::success('تم جلب ملخص الحسابات المالية للشركة.', $companyFinance->summary($actor, $requestedCompanyId === false ? null : $requestedCompanyId));
}

if ($route === 'admin/company-finance/transactions' && $method === 'GET') {
    $actor = $auth->requireUser();
    if (!in_array('super_admin', $actor['roles'], true) && !in_array('manage_payments', $actor['permissions'], true) && !in_array('view_financial_reports', $actor['permissions'], true)) {
        Response::error('لا تملك صلاحية عرض حركات الشركة المالية.', 'FORBIDDEN', 403);
    }
    $requestedCompanyId = filter_var($_GET['company_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $startDate = trim((string) ($_GET['start_date'] ?? '')) ?: null;
    $endDate = trim((string) ($_GET['end_date'] ?? '')) ?: null;
    Response::success('تم جلب حركات الحساب المالي للشركة.', ['items' => $companyFinance->transactions($actor, $requestedCompanyId === false ? null : $requestedCompanyId, $startDate, $endDate)]);
}

if ($route === 'admin/company-finance/settings' && $method === 'PUT') {
    Security::assertCsrf();
    $actor = $auth->requireUser();
    $companyId = filter_var($_GET['company_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($companyId === false) {
        Response::error('معرّف الشركة غير صالح.', 'VALIDATION_ERROR', 422);
    }
    Response::success('تم حفظ إعدادات الشركة المالية.', ['settings' => $companyFinance->updateSettings($actor, (int) $companyId, Security::jsonInput()), 'csrf_token' => Security::csrfToken()]);
}

if ($route === 'admin/company-reviews' && $method === 'GET') {
    $actor = $auth->requireUser();
    $requestedCompanyId = filter_var($_GET['company_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    Response::success('تم جلب تقييمات العملاء.', ['items' => $adminOps->companyReviews($actor, $requestedCompanyId === false ? null : $requestedCompanyId)]);
}

if ($route === 'admin/people' && $method === 'GET') {
    $actor = $auth->requireUser();
    Response::success('تم جلب الوكلاء والعملاء.', $adminOps->people($actor));
}

if ($route === 'admin/users' && $method === 'POST') {
    Security::assertCsrf();
    $actor = $auth->requireUser();
    Response::success('تم إنشاء المستخدم الإداري.', ['user' => $adminOps->createAdminUser($actor, Security::jsonInput())], 201);
}

if ($route === 'admin/agents' && $method === 'POST') {
    Security::assertCsrf();
    $actor = $auth->requireUser();
    Response::success('تم إنشاء حساب الوكيل.', ['agent' => $adminOps->createAgent($actor, Security::jsonInput())], 201);
}

if ($route === 'admin/customers' && $method === 'POST') {
    Security::assertCsrf();
    $actor = $auth->requireUser();
    Response::success('تم إنشاء حساب العميل.', ['customer' => $adminOps->createCustomer($actor, Security::jsonInput())], 201);
}

if (preg_match('#^admin/(agents|customers)/(\\d+)$#', $route, $matches) === 1 && $method === 'PUT') {
    Security::assertCsrf();
    $actor = $auth->requireUser();
    $person = $adminOps->updatePerson($actor, $matches[1] === 'agents' ? 'agent' : 'customer', (int) $matches[2], Security::jsonInput());
    Response::success('تم تعديل بيانات الحساب.', ['person' => $person]);
}

if (preg_match('#^admin/(agents|customers)/(\\d+)$#', $route, $matches) === 1 && $method === 'DELETE') {
    Security::assertCsrf();
    $actor = $auth->requireUser();
    $person = $adminOps->deletePerson($actor, $matches[1] === 'agents' ? 'agent' : 'customer', (int) $matches[2]);
    Response::success('تم حذف الحساب.', ['person' => $person]);
}

if ($route === 'admin/companies' && $method === 'POST') {
    Security::assertCsrf();
    $actor = $auth->requireUser();
    $company = $adminOps->createCompany($actor, Security::jsonInput());
    Response::success('تم إنشاء شركة النقل.', ['company' => $company], 201);
}

if (preg_match('#^admin/companies/(\d+)$#', $route, $matches) === 1 && $method === 'PUT') {
    Security::assertCsrf();
    $actor = $auth->requireUser();
    $company = $adminOps->updateCompany($actor, (int) $matches[1], Security::jsonInput());
    Response::success('تم تعديل بيانات الشركة.', ['company' => $company]);
}

if (preg_match('#^admin/companies/(\d+)/status$#', $route, $matches) === 1 && $method === 'PUT') {
    Security::assertCsrf();
    $actor = $auth->requireUser();
    $company = $adminOps->updateCompanyStatus($actor, (int) $matches[1], Security::jsonInput());
    Response::success('تم تحديث حالة الشركة.', ['company' => $company]);
}

if (preg_match('#^admin/companies/(\d+)$#', $route, $matches) === 1 && $method === 'DELETE') {
    Security::assertCsrf();
    $actor = $auth->requireUser();
    $company = $adminOps->deleteCompany($actor, (int) $matches[1]);
    Response::success('تم حذف الشركة.', ['company' => $company]);
}

if ($route === 'admin/countries' && $method === 'POST') {
    Security::assertCsrf();
    $actor = $auth->requireUser();
    $country = $adminOps->createCountry($actor, Security::jsonInput());
    Response::success('تمت إضافة الدولة.', ['country' => $country], 201);
}

if (preg_match('#^admin/countries/(\d+)/status$#', $route, $matches) === 1 && $method === 'PUT') {
    Security::assertCsrf();
    $actor = $auth->requireUser();
    $country = $adminOps->updateCountryStatus($actor, (int) $matches[1], Security::jsonInput());
    Response::success('تم تحديث حالة الدولة.', ['country' => $country]);
}

if ($route === 'admin/cities' && $method === 'POST') {
    Security::assertCsrf();
    $actor = $auth->requireUser();
    $city = $adminOps->createCity($actor, Security::jsonInput());
    Response::success('تمت إضافة المدينة.', ['city' => $city], 201);
}

if (preg_match('#^admin/cities/(\d+)/status$#', $route, $matches) === 1 && $method === 'PUT') {
    Security::assertCsrf();
    $actor = $auth->requireUser();
    $city = $adminOps->updateCityStatus($actor, (int) $matches[1], Security::jsonInput());
    Response::success('تم تحديث حالة المدينة.', ['city' => $city]);
}

if (preg_match('#^admin/cities/(\d+)$#', $route, $matches) === 1 && $method === 'PUT') {
    Security::assertCsrf();
    $actor = $auth->requireUser();
    $city = $adminOps->updateCity($actor, (int) $matches[1], Security::jsonInput());
    Response::success('تم تعديل المدينة.', ['city' => $city]);
}

if (preg_match('#^admin/cities/(\d+)$#', $route, $matches) === 1 && $method === 'DELETE') {
    Security::assertCsrf();
    $actor = $auth->requireUser();
    $city = $adminOps->deleteCity($actor, (int) $matches[1]);
    Response::success('تم حذف المدينة.', ['city' => $city]);
}

if (preg_match('#^admin/companies/(\d+)/media$#', $route, $matches) === 1 && $method === 'POST') {
    Security::assertCsrf();
    $actor = $auth->requireUser();
    $media = $adminOps->uploadCompanyMedia($actor, (int) $matches[1], (string) ($_POST['slot'] ?? ''), $_FILES['file'] ?? []);
    Response::success('تم حفظ وسائط الشركة.', ['media' => $media]);
}

if (preg_match('#^admin/companies/(\d+)/gallery$#', $route, $matches) === 1 && $method === 'POST') {
    Security::assertCsrf();
    $actor = $auth->requireUser();
    $imageOrder = filter_var($_POST['image_order'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 6]]);
    if ($imageOrder === false) { Response::error('موضع صورة المعرض مطلوب بين 1 و6.', 'VALIDATION_ERROR', 422); }
    $media = $adminOps->uploadCompanyGalleryImage($actor, (int) $matches[1], (int) $imageOrder, $_FILES['file'] ?? []);
    Response::success('تم حفظ صورة المعرض.', ['media' => $media]);
}

if (preg_match('#^admin/companies/(\d+)/gallery$#', $route, $matches) === 1 && $method === 'GET') {
    $actor = $auth->requireUser();
    $adminOps->assertCompanyGalleryAccess($actor, (int) $matches[1]);
    Response::success('تم جلب معرض الشركة.', ['items' => $adminOps->companyGallery((int) $matches[1])]);
}

if (preg_match('#^admin/company-gallery/(\d+)$#', $route, $matches) === 1 && $method === 'DELETE') {
    Security::assertCsrf();
    $actor = $auth->requireUser();
    $image = $adminOps->deleteCompanyGalleryImage($actor, (int) $matches[1]);
    Response::success('تم حذف صورة المعرض.', ['image' => $image]);
}

if ($route === 'admin/countries' && $method === 'POST') {
    Security::assertCsrf();
    $actor = $auth->requireUser();
    $country = $adminOps->createCountry($actor, Security::jsonInput());
    Response::success('تمت إضافة الدولة.', ['country' => $country], 201);
}

if (preg_match('#^admin/countries/(\\d+)$#', $route, $matches) === 1 && $method === 'PUT') {
    Security::assertCsrf();
    $actor = $auth->requireUser();
    $country = $adminOps->updateCountry($actor, (int) $matches[1], Security::jsonInput());
    Response::success('تم تعديل الدولة.', ['country' => $country]);
}

if (preg_match('#^admin/countries/(\\d+)/status$#', $route, $matches) === 1 && $method === 'PUT') {
    Security::assertCsrf();
    $actor = $auth->requireUser();
    $country = $adminOps->updateCountryStatus($actor, (int) $matches[1], Security::jsonInput());
    Response::success('تم تحديث حالة الدولة.', ['country' => $country]);
}

if (preg_match('#^admin/countries/(\\d+)$#', $route, $matches) === 1 && $method === 'DELETE') {
    Security::assertCsrf();
    $actor = $auth->requireUser();
    $country = $adminOps->deleteCountry($actor, (int) $matches[1]);
    Response::success('تم حذف الدولة.', ['country' => $country]);
}

if (preg_match('#^admin/references/(currencies|exchange-rates)$#', $route, $matches) === 1 && $method === 'GET') {
    $actor = $auth->requirePermissions(['manage_settings']);
    Response::success('تم جلب البيانات المرجعية.', ['items' => $adminOps->references($actor, $matches[1])]);
}

if (preg_match('#^admin/references/(currencies|exchange-rates)/(\d+)/status$#', $route, $matches) === 1 && $method === 'PUT') {
    Security::assertCsrf();
    $actor = $auth->requirePermissions(['manage_settings']);
    Response::success('تم تحديث حالة البيانات المرجعية.', ['item' => $adminOps->updateReferenceStatus($actor, $matches[1], (int) $matches[2], Security::jsonInput())]);
}

if (preg_match('#^admin/references/(countries|cities|stations|currencies|exchange-rates)$#', $route, $matches) === 1 && $method === 'POST') {
    Security::assertCsrf();
    $actor = $auth->requireUser();
    $reference = $adminOps->createReference($actor, $matches[1], Security::jsonInput());
    Response::success('تمت إضافة البيانات المرجعية.', ['reference' => $reference], 201);
}

if (preg_match('#^admin/references/(currencies|exchange-rates)/(\d+)$#', $route, $matches) === 1 && $method === 'PUT') {
    Security::assertCsrf();
    $actor = $auth->requireUser();
    Response::success('تم تعديل البيانات المرجعية.', ['item' => $adminOps->updateReference($actor, $matches[1], (int) $matches[2], Security::jsonInput())]);
}

if (preg_match('#^admin/references/(currencies|exchange-rates)/(\d+)$#', $route, $matches) === 1 && $method === 'DELETE') {
    Security::assertCsrf();
    $actor = $auth->requireUser();
    Response::success('تم حذف البيانات المرجعية.', ['item' => $adminOps->deleteReference($actor, $matches[1], (int) $matches[2])]);
}

if (preg_match('#^admin/users/(\d+)/roles$#', $route, $matches) === 1 && $method === 'POST') {
    Security::assertCsrf();
    $actor = $auth->requireUser();
    $assignment = $adminOps->assignUserRole($actor, (int) $matches[1], Security::jsonInput());
    Response::success('تم تعيين الدور للمستخدم.', ['assignment' => $assignment], 201);
}

if (preg_match('#^admin/roles/(\d+)/permissions$#', $route, $matches) === 1 && $method === 'PUT') {
    Security::assertCsrf();
    $actor = $auth->requireUser();
    $role = $adminOps->updateRolePermissions($actor, (int) $matches[1], Security::jsonInput());
    Response::success('تم تحديث صلاحيات الدور.', ['role' => $role]);
}

if ($route === 'admin/routes' && $method === 'POST') {
    Security::assertCsrf();
    $actor = $auth->requireUser();
    $routeItem = $adminOps->createRoute($actor, Security::jsonInput());
    Response::success('تم إنشاء المسار الرئيسي.', ['route' => $routeItem], 201);
}

if (preg_match('#^admin/routes/(\d+)$#', $route, $matches) === 1 && $method === 'PUT') {
    Security::assertCsrf();
    $actor = $auth->requireUser();
    $routeItem = $adminOps->updateRoute($actor, (int) $matches[1], Security::jsonInput());
    Response::success('تم تعديل المسار الرئيسي.', ['route' => $routeItem]);
}

if (preg_match('#^admin/routes/(\d+)/status$#', $route, $matches) === 1 && $method === 'PUT') {
    Security::assertCsrf();
    $actor = $auth->requireUser();
    $routeItem = $adminOps->updateRouteStatus($actor, (int) $matches[1], Security::jsonInput());
    Response::success('تم تحديث حالة المسار الرئيسي.', ['route' => $routeItem]);
}

if (preg_match('#^admin/routes/(\d+)$#', $route, $matches) === 1 && $method === 'DELETE') {
    Security::assertCsrf();
    $actor = $auth->requireUser();
    $routeItem = $adminOps->deleteRoute($actor, (int) $matches[1]);
    Response::success('تم حذف المسار الرئيسي.', ['route' => $routeItem]);
}

if ($route === 'admin/subroutes' && $method === 'POST') {
    Security::assertCsrf();
    $actor = $auth->requireUser();
    $subroute = $adminOps->createSubroute($actor, Security::jsonInput());
    Response::success('تم إنشاء المسار الفرعي وتسعيره.', ['subroute' => $subroute], 201);
}

if (preg_match('#^admin/subroutes/(\d+)$#', $route, $matches) === 1 && $method === 'PUT') {
    Security::assertCsrf();
    $actor = $auth->requireUser();
    $subroute = $adminOps->updateSubroute($actor, (int) $matches[1], Security::jsonInput());
    Response::success('تم تعديل المسار الفرعي.', ['subroute' => $subroute]);
}

if (preg_match('#^admin/subroutes/(\d+)/status$#', $route, $matches) === 1 && $method === 'PUT') {
    Security::assertCsrf();
    $actor = $auth->requireUser();
    $subroute = $adminOps->updateSubrouteStatus($actor, (int) $matches[1], Security::jsonInput());
    Response::success('تم تحديث حالة المسار الفرعي.', ['subroute' => $subroute]);
}

if (preg_match('#^admin/subroutes/(\d+)$#', $route, $matches) === 1 && $method === 'DELETE') {
    Security::assertCsrf();
    $actor = $auth->requireUser();
    $subroute = $adminOps->deleteSubroute($actor, (int) $matches[1]);
    Response::success('تم حذف المسار الفرعي.', ['subroute' => $subroute]);
}

if ($route === 'admin/buses' && $method === 'POST') {
    Security::assertCsrf();
    $actor = $auth->requireUser();
    $bus = $adminOps->createBus($actor, Security::jsonInput());
    Response::success('تم إنشاء الباص ومخطط مقاعده.', ['bus' => $bus], 201);
}

if (preg_match('#^admin/buses/(\d+)$#', $route, $matches) === 1 && $method === 'PUT') {
    Security::assertCsrf();
    $actor = $auth->requireUser();
    $bus = $adminOps->updateBus($actor, (int) $matches[1], Security::jsonInput());
    Response::success('تم تعديل الباص.', ['bus' => $bus]);
}

if (preg_match('#^admin/buses/(\d+)/status$#', $route, $matches) === 1 && $method === 'PUT') {
    Security::assertCsrf();
    $actor = $auth->requireUser();
    $bus = $adminOps->updateBusStatus($actor, (int) $matches[1], Security::jsonInput());
    Response::success('تم تحديث حالة الباص.', ['bus' => $bus]);
}

if (preg_match('#^admin/buses/(\d+)$#', $route, $matches) === 1 && $method === 'DELETE') {
    Security::assertCsrf();
    $actor = $auth->requireUser();
    $bus = $adminOps->deleteBus($actor, (int) $matches[1]);
    Response::success('تم حذف الباص.', ['bus' => $bus]);
}

if (preg_match('#^admin/buses/(\d+)/media$#', $route, $matches) === 1 && $method === 'POST') {
    Security::assertCsrf();
    $actor = $auth->requireUser();
    $media = $adminOps->uploadBusMedia($actor, (int) $matches[1], (string) ($_POST['slot'] ?? ''), $_FILES['file'] ?? []);
    Response::success('تم حفظ صورة الباص.', ['media' => $media]);
}

if (preg_match('#^admin/routes/(\d+)/stops$#', $route, $matches) === 1 && $method === 'POST') {
    Security::assertCsrf();
    $actor = $auth->requireUser();
    $stop = $adminOps->addRouteStop($actor, (int) $matches[1], Security::jsonInput());
    Response::success('تمت إضافة محطة وإعادة بناء مقاطع المسار.', ['stop' => $stop], 201);
}

if ($route === 'admin/segment-prices' && $method === 'POST') {
    Security::assertCsrf();
    $actor = $auth->requireUser();
    $price = $adminOps->createSegmentPrice($actor, Security::jsonInput());
    Response::success('تمت إضافة سعر المقطع.', ['price' => $price], 201);
}

if ($route === 'admin/trips' && $method === 'POST') {
    Security::assertCsrf();
    $actor = $auth->requireUser();
    $trip = $adminOps->createTrip($actor, Security::jsonInput());
    Response::success('تم إنشاء الرحلة ونسخ المقاعد والأسعار الحالية.', ['trip' => $trip], 201);
}

if ($route === 'admin/trips/recurring/preview' && $method === 'POST') {
    Security::assertCsrf();
    $actor = $auth->requireUser();
    $preview = $adminOps->previewRecurringTrips($actor, Security::jsonInput());
    Response::success('تمت معاينة الرحلات المتكررة.', ['preview' => $preview]);
}

if ($route === 'admin/trips/recurring' && $method === 'POST') {
    Security::assertCsrf();
    $actor = $auth->requireUser();
    $result = $adminOps->createRecurringTrips($actor, Security::jsonInput());
    Response::success('تم إنشاء الرحلات المتكررة ونسخ المقاعد والأسعار الحالية.', ['result' => $result], 201);
}

if ($route === 'admin/trips/prices/preview' && $method === 'POST') {
    Security::assertCsrf();
    $actor = $auth->requireUser();
    $preview = $adminOps->previewTripPriceUpdate($actor, Security::jsonInput());
    Response::success('تمت معاينة أسعار الرحلات المحددة.', ['preview' => $preview]);
}

if ($route === 'admin/trips/prices' && $method === 'PUT') {
    Security::assertCsrf();
    $actor = $auth->requireUser();
    $result = $adminOps->applyTripPriceUpdate($actor, Security::jsonInput());
    Response::success('تم حفظ أسعار الرحلات المحددة.', ['result' => $result]);
}

if ($route === 'admin/trips/bulk/preview' && $method === 'POST') {
    Security::assertCsrf();
    $actor = $auth->requireUser();
    $preview = $adminOps->previewBulkTripUpdate($actor, Security::jsonInput());
    Response::success('تمت معاينة التعديل الجماعي للرحلات.', ['preview' => $preview]);
}

if ($route === 'admin/trips/bulk' && $method === 'PUT') {
    Security::assertCsrf();
    $actor = $auth->requireUser();
    $result = $adminOps->applyBulkTripUpdate($actor, Security::jsonInput());
    Response::success('تم تطبيق التعديل الجماعي على الرحلات.', ['result' => $result]);
}

if (preg_match('#^admin/trips/(\d+)$#', $route, $matches) === 1 && $method === 'PUT') {
    Security::assertCsrf();
    $actor = $auth->requireUser();
    $trip = $adminOps->updateTrip($actor, (int) $matches[1], Security::jsonInput());
    Response::success('تم تعديل الرحلة.', ['trip' => $trip]);
}

if (preg_match('#^admin/trips/(\d+)/status$#', $route, $matches) === 1 && $method === 'PUT') {
    Security::assertCsrf();
    $actor = $auth->requireUser();
    $trip = $adminOps->updateTripStatus($actor, (int) $matches[1], Security::jsonInput());
    Response::success('تم تحديث حالة الرحلة.', ['trip' => $trip]);
}

if (preg_match('#^admin/trips/(\d+)$#', $route, $matches) === 1 && $method === 'DELETE') {
    Security::assertCsrf();
    $actor = $auth->requireUser();
    $trip = $adminOps->deleteTrip($actor, (int) $matches[1]);
    Response::success('تم حذف الرحلة.', ['trip' => $trip]);
}

if ($route === 'dashboard/summary' && $method === 'GET') {
    $actor = $auth->requireUser();
    Response::success('تم جلب مؤشرات لوحة التحكم.', ['summary' => $dashboard->summary($actor)]);
}

if ($route === 'dashboard/overview' && $method === 'GET') {
    $actor = $auth->requireUser();
    $filters = [
        'period' => $_GET['period'] ?? 'this_month', 'company_id' => $_GET['company_id'] ?? null,
        'agent_id' => $_GET['agent_id'] ?? null, 'route_id' => $_GET['route_id'] ?? null,
        'route_subroute_id' => $_GET['route_subroute_id'] ?? null, 'currency_id' => $_GET['currency_id'] ?? null,
        'start_date' => $_GET['start_date'] ?? null, 'end_date' => $_GET['end_date'] ?? null,
    ];
    Response::success('تم جلب بيانات لوحة التحكم المتقدمة.', ['overview' => $dashboard->overview($actor, $filters)]);
}

if ($route === 'reports/overview' && $method === 'GET') {
    $actor = $auth->requireUser();
    $filters = [
        'country_id' => $_GET['country_id'] ?? null, 'company_id' => $_GET['company_id'] ?? null, 'agent_id' => $_GET['agent_id'] ?? null,
        'currency_id' => $_GET['currency_id'] ?? null, 'trip_id' => $_GET['trip_id'] ?? null, 'start_date' => $_GET['start_date'] ?? null, 'end_date' => $_GET['end_date'] ?? null,
    ];
    Response::success('تم جلب التقرير حسب عوامل التصفية المحددة.', ['report' => $dashboard->report($actor, $filters)]);
}

if ($route === 'admin/sidebar-badges' && $method === 'GET') {
    $actor = $auth->requireUser();
    $statement = $database->pdo()->prepare("SELECT type, COUNT(*) AS total FROM notifications WHERE user_id = :user_id AND read_at IS NULL AND type IN ('new_booking', 'new_customer') GROUP BY type");
    $statement->execute(['user_id' => $actor['id']]);
    $badges = ['bookings' => 0, 'customers' => 0];
    foreach ($statement->fetchAll() as $row) {
        $key = $row['type'] === 'new_customer' ? 'customers' : 'bookings';
        $badges[$key] = (int) ($row['total'] ?? 0);
    }
    Response::success('تم جلب عدادات الشريط الجانبي.', ['badges' => $badges]);
}

if ($route === 'notifications' && $method === 'GET') {
    $actor = $auth->requireUser();
    $statement = $database->pdo()->prepare('SELECT id, type, title_ar, body_ar, reference_type, reference_id, read_at, created_at FROM notifications WHERE user_id = :user_id ORDER BY created_at DESC LIMIT 100');
    $statement->execute(['user_id' => $actor['id']]);
    $unread = $database->pdo()->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = :user_id AND read_at IS NULL');
    $unread->execute(['user_id' => $actor['id']]);
    Response::success('تم جلب الإشعارات.', ['items' => $statement->fetchAll(), 'unread_count' => (int) $unread->fetchColumn()]);
}

if (preg_match('#^notifications/(\d+)/read$#', $route, $matches) === 1 && $method === 'PUT') {
    Security::assertCsrf();
    $actor = $auth->requireUser();
    $statement = $database->pdo()->prepare('UPDATE notifications SET read_at = NOW() WHERE id = :id AND user_id = :user_id');
    $statement->execute(['id' => $matches[1], 'user_id' => $actor['id']]);
    if ($statement->rowCount() === 0) {
        Response::error('الإشعار المطلوب غير موجود.', 'NOT_FOUND', 404);
    }
    Response::success('تم تعليم الإشعار كمقروء.');
}

Response::error('المسار المطلوب غير موجود.', 'NOT_FOUND', 404);
