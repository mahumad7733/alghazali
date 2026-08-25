<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';

use App\Includes\Auth;
use App\Includes\Security;

$auth = new Auth($database);
$current = $auth->currentUser();
if ($current !== null) {
    $roles = $current['roles'] ?? [];
    $target = in_array('agent', $roles, true) ? 'agent.php' : (in_array('customer', $roles, true) ? 'customer.php' : 'admin/admin.php');
    header('Location: ' . $target, true, 302);
    exit;
}
?><!doctype html>
<html lang="ar" dir="rtl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf-token" content="<?= Security::escape(Security::csrfToken()) ?>">
  <title>تسجيل الدخول | منصة حجوزات الباصات</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800;900&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="assets/css/app.css?v=20260825-16">
  <style>
    :root{--login-ink:#102b43;--login-primary:#174a7e;--login-blue:#1e88c9;--login-gold:#d89b35;--login-muted:#668096;--login-border:#dce6ef}
    *{box-sizing:border-box}body.login-page{min-height:100vh;margin:0;display:grid;place-items:center;overflow-x:hidden;background:radial-gradient(circle at 8% 12%,rgb(30 136 201/.16),transparent 28%),radial-gradient(circle at 92% 88%,rgb(216 155 53/.14),transparent 28%),linear-gradient(145deg,#eef5f9,#fff 52%,#f4f7fa);font-family:Tajawal,system-ui,sans-serif;color:var(--login-ink)}
    .login-page:before{content:"";position:fixed;inset:auto auto -180px -120px;width:430px;height:430px;border:1px solid rgb(30 136 201/.13);border-radius:50%;box-shadow:0 0 0 34px rgb(30 136 201/.035),0 0 0 70px rgb(30 136 201/.025);pointer-events:none}
    .login-card{position:relative;width:min(920px,calc(100% - 32px));display:grid;grid-template-columns:1fr 1.14fr;overflow:hidden;border:1px solid rgb(255 255 255/.9);border-radius:28px;background:rgb(255 255 255/.9);box-shadow:0 30px 75px rgb(16 47 78/.16);backdrop-filter:blur(16px)}
    .login-visual{position:relative;isolation:isolate;display:flex;min-height:560px;flex-direction:column;justify-content:center;padding:44px 38px;overflow:hidden;background:linear-gradient(145deg,#102f55 0%,#174a7e 54%,#1e88c9 100%);color:#fff}.login-visual:before,.login-visual:after{content:"";position:absolute;z-index:-1;border-radius:50%;background:rgb(255 255 255/.07)}.login-visual:before{width:370px;height:370px;top:-155px;left:-135px}.login-visual:after{width:280px;height:280px;right:-125px;bottom:-120px;background:rgb(216 155 53/.18)}.login-dots{position:absolute;inset:0;z-index:-1;background-image:radial-gradient(rgb(255 255 255/.17) 1px,transparent 1px);background-size:24px 24px;mask-image:linear-gradient(to bottom,black,transparent 80%)}.login-mark{display:grid;place-items:center;width:68px;height:68px;margin-bottom:25px;border:1px solid rgb(255 255 255/.28);border-radius:21px;background:rgb(255 255 255/.13);box-shadow:0 14px 25px rgb(0 0 0/.15);font-size:1.8rem}.login-kicker{display:inline-flex;width:max-content;padding:7px 11px;border-radius:99px;background:rgb(255 255 255/.13);color:#fff3cc;font-size:.75rem;font-weight:900}.login-visual h2{max-width:310px;margin:17px 0 12px;font-size:clamp(1.55rem,3vw,2.15rem);line-height:1.45;font-weight:900;letter-spacing:-.03em}.login-visual p{max-width:320px;margin:0;color:#d9ebf5;line-height:1.9;font-size:.93rem}.login-benefits{display:grid;gap:11px;margin:30px 0 0;padding:0;list-style:none;color:#e8f5fa;font-size:.82rem;font-weight:800}.login-benefits li{display:flex;align-items:center;gap:9px}.login-benefits li:before{content:"✓";display:grid;place-items:center;width:21px;height:21px;border-radius:50%;background:rgb(216 155 53/.9);color:#102f55;font-weight:900}
    .login-content{padding:52px 54px 42px;background:#fff}.login-brand{display:flex;align-items:center;gap:12px;margin-bottom:32px}.login-logo{display:grid;place-items:center;width:48px;height:48px;border-radius:15px;background:linear-gradient(145deg,#174a7e,#1e88c9);color:#fff;box-shadow:0 9px 18px rgb(23 74 126/.22);font-size:1.35rem}.login-brand b{display:block;color:var(--login-ink);font-size:1.1rem;font-weight:900}.login-brand small{display:block;margin-top:3px;color:var(--login-muted);font-size:.75rem;font-weight:700}.login-eyebrow{display:inline-flex;padding:7px 11px;border-radius:99px;background:#fff5df;color:#a86c17;font-size:.73rem;font-weight:900}.login-content h1{margin:15px 0 8px;color:var(--login-ink);font-size:1.85rem;letter-spacing:-.03em}.login-description{max-width:410px;margin:0;color:var(--login-muted);font-size:.9rem;line-height:1.85}.login-form{display:grid;gap:15px;margin-top:26px}.login-form label{display:grid;gap:7px;margin:0;color:#405b70;font-size:.8rem;font-weight:900}.login-form input{width:100%;min-height:50px;padding:11px 14px;border:1px solid var(--login-border);border-radius:12px;background:#f9fbfd;color:var(--login-ink);font:inherit;outline:0;transition:border-color .2s ease,box-shadow .2s ease,background .2s ease}.login-form input:focus{border-color:var(--login-blue);background:#fff;box-shadow:0 0 0 4px rgb(30 136 201/.12)}.login-submit{width:100%;min-height:52px;margin-top:5px;border:0;border-radius:13px;background:linear-gradient(135deg,#174a7e,#1e88c9);box-shadow:0 11px 21px rgb(23 74 126/.22);color:#fff;font:900 .95rem Tajawal,sans-serif;cursor:pointer;transition:transform .2s ease,box-shadow .2s ease,background .2s ease}.login-submit:hover:not(:disabled){transform:translateY(-2px);background:linear-gradient(135deg,#123b66,#1679b3);box-shadow:0 15px 28px rgb(23 74 126/.28)}.login-submit:active{transform:scale(.98)}.login-submit:disabled{cursor:wait;opacity:.65}.login-message{min-height:24px;margin:0;color:#b85c52;font-size:.82rem;font-weight:800}.login-hint{margin:20px 0 0;padding-top:17px;border-top:1px solid #edf2f6;text-align:center;color:var(--login-muted);font-size:.8rem}.login-hint a{color:var(--login-primary);font-weight:900;text-decoration:none}.login-hint a:hover{text-decoration:underline;text-underline-offset:3px}
    @media(max-width:760px){.login-card{grid-template-columns:1fr;width:min(520px,calc(100% - 24px));border-radius:22px}.login-visual{min-height:185px;padding:27px 25px 24px}.login-mark,.login-benefits{display:none}.login-visual h2{margin:10px 0 4px;font-size:1.35rem}.login-visual p{max-width:440px;font-size:.81rem;line-height:1.7}.login-content{padding:31px 25px 28px}.login-content h1{font-size:1.5rem}}
    @media(max-width:420px){.login-content{padding:28px 20px 25px}.login-brand{margin-bottom:26px}.login-form{margin-top:22px}.login-visual{padding-inline:20px}}
  </style>
</head>
<body class="login-page">
  <main class="login-card">
    <section class="login-visual" aria-label="مزايا المنصة">
      <span class="login-dots"></span>
      <div class="login-mark">🚌</div>
      <span class="login-kicker">رحلتك تبدأ من هنا</span>
      <h2>إدارة وحجز أكثر وضوحًا وراحة</h2>
      <p>منصة موحدة للعملاء والشركات والوكلاء لإدارة الرحلات والحجوزات بثقة.</p>
      <ul class="login-benefits">
        <li>وصول آمن حسب الصلاحية</li>
        <li>بيانات رحلات محدثة</li>
        <li>تجربة عربية سهلة الاستخدام</li>
      </ul>
    </section>
    <section class="login-content">
      <div class="login-brand"><span class="login-logo">🚌</span><span><b>منصة رحلة</b><small>بوابة موحدة لجميع الحسابات</small></span></div>
      <span class="login-eyebrow">مرحبًا بعودتك</span>
      <h1>تسجيل الدخول</h1>
      <p class="login-description">استخدم البريد الإلكتروني أو اسم المستخدم أو رقم الجوال، وسيتم توجيهك تلقائيًا إلى واجهتك المناسبة.</p>
      <form id="login-form" class="login-form">
        <label>البريد الإلكتروني أو اسم المستخدم أو رقم الجوال<input name="identifier" autocomplete="username" required></label>
        <label>كلمة المرور<input name="password" type="password" autocomplete="current-password" required></label>
        <button class="login-submit" type="submit">تسجيل الدخول</button>
        <div id="login-message" class="login-message" aria-live="polite"></div>
      </form>
      <div class="login-hint"><a href="customer.php">إنشاء حساب عميل أو البحث عن رحلة</a></div>
    </section>
  </main>
  <script>
    const form=document.getElementById('login-form'),message=document.getElementById('login-message'),csrf=document.querySelector('meta[name="csrf-token"]').content;
    form.addEventListener('submit',async(e)=>{e.preventDefault();message.textContent='';const submit=form.querySelector('button');submit.disabled=true;try{const r=await fetch('api/v1/index.php?route=auth%2Flogin',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-Token':csrf},body:JSON.stringify(Object.fromEntries(new FormData(form)))});const payload=await r.json();if(!r.ok||!payload.success)throw new Error(payload.message||'تعذر تسجيل الدخول.');const roles=payload.data.user.roles||[];const allowedReturn=new URLSearchParams(location.search).get('return')||'';const target=roles.includes('agent')?'agent.php':roles.includes('customer')?'customer.php':(allowedReturn.startsWith('admin/')?allowedReturn:'admin/admin.php');location.assign(target);}catch(error){message.textContent=error.message;}finally{submit.disabled=false;}});
  </script>
</body>
</html>
