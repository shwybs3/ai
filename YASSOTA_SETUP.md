# Yassota.com — دليل الأنظمة الجديدة

هذا الملف يشرح كل ما أُضيف إلى yassota.com في هذه الدفعة، وكيفية تشغيله.

## 1) الهوية البصرية (قالب Syria-Home)
- `assets/css/style.css` — نظام تصميم كامل بنفس هوية syria-home.yassota.com
  (خلفية فاتحة، تدرّج نيلي→سماوي، خطوط Inter + Cairo، هيدر بقائمة هامبرغر، دعم RTL).
- `assets/js/main.js` — قائمة الجوال، موافقة الكوكيز، بحث/فلترة الأدوات، نموذج النشرة (AJAX).
- `partials.php` — `yassota_header()` / `yassota_footer()` مصدر واحد للهيدر والفوتر لكل الصفحات العامة.

## 2) ٢٠٠+ أداة ويب حقيقية
- `assets/js/tools.js` — محرّك + ٢٠١ أداة تعمل بالكامل داخل المتصفح، ١٠ فئات:
  نصوص، رياضيات، ألوان، مطورون، سيو، أمان، محوّلات، صور، إنتاجية، تسويق.
- `tools.php` + `tools_catalog.php` — دليل الأدوات + **صفحة SEO مستقلة لكل أداة**
  (`?t=<id>`) بوصف حقيقي و JSON-LD و breadcrumbs — يحل مشكلة "الأدوات لا تظهر في نتائج البحث".
- `tools_catalog.php` يُولَّد تلقائيًا من `tools.js`. لإعادة توليده بعد تعديل الأدوات:
  ```bash
  node -e 'global.window={};global.document={createElement:()=>({append(){},addEventListener(){},setAttribute(){},style:{},classList:{add(){}},getContext:()=>({fillRect(){},fillText(){},drawImage(){}}),toDataURL:()=>"data:"}),querySelector:()=>null};global.navigator={};global.crypto={getRandomValues:a=>a,randomUUID:()=>"x"};require("./assets/js/tools.js");const q=s=>"\x27"+String(s).replace(/\\/g,"\\\\").replace(/\x27/g,"\\\x27")+"\x27";const rows=window.YassotaTools.all.map(t=>"    ["+["id","cat","icon","name","desc"].map(k=>"\x27"+k+"\x27 => "+q(t[k])).join(", ")+"],").join("\n");require("fs").writeFileSync("tools_catalog.php","<?php\nfunction yassota_tools_meta(): array {\n  return [\n"+rows+"\n  ];\n}\n")'
  ```

## 3) مصنع الدومينات الفرعية (yassota.com.*)
- `includes/subdomain_factory.php` + `subsite.php` + `sitemap.php`.
- الفكرة: **دومين شامل واحد (Wildcard)** `*.yassota.com` يُنشأ مرة واحدة ويشير إلى نفس
  مجلد هذا التطبيق. بعدها كل مضيف مثل `best-color-tools.yassota.com` يُخدَّم من `subsite.php`
  الذي يبحث عن المضيف في جدول `subsites` ويعرض محتواه بقالب Syria-Home.
- التوليد: Admin → **🏭 مصنع الدومينات**:
  1. املأ `الدومين الجذر` و`مسار الملفات` وبيانات cPanel (اختياري للتنفيذ المباشر).
  2. زر **① إنشاء Wildcard** — ينشئ `*.yassota.com` عبر cPanel API مرة واحدة.
  3. **توليد دفعة** — يولّد N دومينًا (حتى ٢٠٠ للدفعة) بأسماء غير مكررة ومحتوى
     مُولَّد بالذكاء الاصطناعي (OpenRouter، نماذج مجانية). بدون تفعيل "تنفيذ مباشر"
     يعمل كمسودة آمنة لا تلمس cPanel.
  4. زر **② تشغيل AutoSSL** — يُصدر شهادات HTTPS تلقائيًا.
  - لآلاف الدومينات: شغّل دفعات متتابعة. الأسماء مضمونة عدم التكرار (بنك كلمات + فحص قاعدة البيانات).
- **الأمان**: كل استدعاءات cPanel حقيقية وغير قابلة للتراجع، لذلك الوضع الافتراضي "مسودة".
  التوكِن يُحفظ في قاعدة البيانات فقط ولا يُطبع في أي سجل أو ملف.

## 4) خريطة موقع موحّدة
- `sitemap.php` — خريطة واحدة لكل الشبكة:
  - `/sitemap.php` فهرس خرائط.
  - `/sitemap.php?type=main` الموقع الرئيسي + كل صفحات الأدوات + الفئات.
  - `/sitemap.php?type=subs` كل الدومينات الفرعية المنشورة.
  - هذه هي الخريطة التي تُرسلها إلى Search Console وتُنبّه عبر IndexNow.

## 5) النشرة البريدية + Brevo (حملات إعلانية حقيقية)
- `newsletter.php` — يستقبل البريد، يخزّنه محليًا، وإن ضُبط `brevo_api_key` يضيفه إلى
  قائمة Brevo. Brevo مجاني حتى ٣٠٠ رسالة/يوم، ومنه تُدير حملاتك (بدلاً من IndexerNow المدفوع
  الذي لا علاقة له ببروتوكول IndexNow المجاني).
- الإعداد: Admin → الإعدادات → `brevo_api_key` و`brevo_list_id`.

## 6) نظام تسجيل الأخطاء
- `includes/error_logger.php` — يلتقط كل خطأ (Warning/Notice/Fatal/Exception) ويكتبه
  بصيغة واضحة: النوع + الرسالة + الملف والسطر + **مقتطف الكود المحيط** + مسار الطلب.
  يُخزَّن في جدول `error_log` وملف احتياطي خارج webroot، ويُخفي أي مفتاح/توكِن.
- لعرض الأخطاء على الشاشة أثناء التطوير فقط: عرّف في config
  `define('YASSOTA_DEBUG_KEY','مفتاح-سري');` ثم افتح الصفحة بـ `?debug=مفتاح-سري`.

## 7) جاهزية AdSense
- `partials.php` يضيف سكربت AdSense تلقائيًا عند ضبط `adsense_publisher_id`.
- `ads.txt` — استبدل `pub-0000000000000000` بمعرّف الناشر بعد القبول.
- مقوّمات القبول المتوفرة: صفحات محتوى كثيرة (٢٠٠+ أداة + الدومينات الفرعية)،
  وصف وميتا و JSON-LD لكل صفحة، سياسة خصوصية/شروط/كوكيز في الفوتر، تصميم متجاوب.

## الإعدادات المطلوبة (كلها من لوحة الإدارة)
| المفتاح | الغرض |
|---|---|
| `factory_root_domain` | yassota.com |
| `factory_docroot` | public_html |
| `openrouter_model` | meta-llama/llama-3.1-8b-instruct:free |
| `cpanel_host` / `cpanel_user` / `cpanel_token` | إنشاء الدومينات + SSL |
| `brevo_api_key` / `brevo_list_id` | النشرة البريدية |
| `adsense_publisher_id` | ca-pub-… |

> ملاحظة أمنية: لا تُشارك توكِن cPanel أو مفاتيح API في أي مكان عام. أنشئ توكِن cPanel
> من *Manage API Tokens* بأقل صلاحيات لازمة، ويمكن إبطاله في أي وقت.
