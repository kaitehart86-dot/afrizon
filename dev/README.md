# تشغيل المشروع محلياً (dev)

هاد المجلد فيه سيرفر تنمية صغير كيخليك تشغّل **AfrizoN CRM** فـ الجهاز ديالك
بلا ما تصبّط Apache ولا PHP. السيرفر كيشغّل `api.php` بـ **PHP 8.3 مكمپايل
لـ WebAssembly** (نفس PHP، 64-bit integers بحال production).

## التثبيت (مرة وحدة)

```bash
npm install
```

## التشغيل

```bash
npm run dev          # http://localhost:8080
PORT=3000 npm run dev   # بورت آخر
```

من بعد حُلّ المتصفح على `http://localhost:8080`.

## شنو كيدير

| الحاجة | التفصيل |
| --- | --- |
| `index.html` + CSS/JS/الصور | كتقرا مباشرة من القرص — أي تعديل + تحديث الصفحة = كيبان فورا |
| `api.php` | كيتنفّذ فـ PHP wasm، وكل طلب عندو حالة نظيفة (بلا تسريب) |
| `crm_data.json` · `audit.log` · `journal.log` … | كتتحمّل للـ PHP وكتتزامن رجع مع القرص بعد كل طلب |
| `api.php` إلا تبدّل | كيترفع أوتوماتيكياً (hot reload) |
| ملفات الداتا واللوكات | مسدودة من الويب بنفس قواعد `.htaccess` |

## ملاحظات مهمة

* **الشبكة مقطوعة فـ dev**: `curl` مبدّل بـ stubs، يعني Digylog / Web Push
  ما كيوصلوش للسيرفر الحقيقي. باقي الـ CRM (الطلبيات، اليوزرز، التقارير، الواجهة)
  كيخدم عادي 100%.
* الملفات اللي كتتبدّل فـ dev هي غير ملفات الداتا (`crm_data.json` …)، باقي
  الملفات كتقرا من القرص.
* هاد المجلد **ما كيتّرفعش** لـ production: خلي `dev/`، `package.json`،
  `node_modules/` برا `public_html`.

## هيكل الملفات

```
index.html        التطبيق (SPA) — كانيتبني من api.php
api.php           السيرفر (REST بسيط: GET snapshot / POST key write)
performance.html  صفحة الأداء
retours.html      صفحة الرتوات
sw.js, push.js    Service Worker + Web Push
dev/server.mjs    سيرفر التنمية (هادا الملف)
```

## استكشاف الأخطاء

* **`Cannot find package '@php-wasm/node'`** → نسايتي `npm install`.
* **بورت مشغول** → `PORT=3001 npm run dev`.
* **خطأ فـ api.php** → كيبان فـ الطيرمينال، والصفحة كترجع 500 بالنص ديال الخطأ.
* **بغيتي تشوفي تفاصيل أكثر** → `DEV_DEBUG=1 npm run dev`.
