# Yassota CASH — تطبيق Android (WebView + AdMob)

غلاف WebView بسيط يحمّل موقع Yassota CASH ويُشغّل إعلانات AdMob عبر جسر JavaScript.

---

## متطلبات البناء
- Android Studio Hedgehog أو أحدث
- JDK 17+
- حساب AdMob فعّال مع تطبيق مُسجَّل

---

## خطوات الإعداد

### 1. عدّل رابط الموقع
في `app/src/main/java/com/yassota/cash/MainActivity.kt` السطر الأول:
```kotlin
private const val SITE_URL = "https://your-domain.com/index.php"
```
غيّر `your-domain.com` إلى نطاقك الفعلي.

### 2. تحديث App ID (إن غيّرت التطبيق)
في `app/src/main/AndroidManifest.xml`:
```xml
<meta-data
    android:name="com.google.android.gms.ads.APPLICATION_ID"
    android:value="ca-app-pub-5506877998492189~9990105460" />
```

### 3. افتح المشروع في Android Studio
افتح مجلد `android/` (وليس الجذر) ثم اضغط **Sync Project with Gradle Files**.

### 4. اختبر على جهاز/محاكي
تأكد أن `admob_test_mode = 1` في لوحة الإدارة.
الإعلانات ستظهر كبطاقات اختبار بدلاً من الحقيقية — هذا صحيح ومطلوب أثناء التطوير.

### 5. إنشاء وحدة Interstitial للكابتشا
1. في [console.admob.com](https://apps.admob.com) → تطبيقك → وحدات الإعلانات → إنشاء.
2. اختر نوع **Interstitial** (وليس Rewarded).
3. انسخ المُعرّف (مثل `ca-app-pub-XXXX/XXXX`).
4. ضعه في لوحة الإدارة » تبويب **📣 إعلانات** → حقل «Interstitial ID».

### 6. الإطلاق الحقيقي
1. في لوحة الإدارة » **📣 إعلانات** → اطفئ «وضع الاختبار».
2. أنشئ مفتاح توقيع: **Build → Generate Signed Bundle/APK → APK**.
3. ارفع الـ APK/AAB على Google Play Console.

---

## كيف يعمل الجسر؟

| JS (الموقع) | Android (AdBridge) |
|---|---|
| `window.Android.showRewardedAd(unitId)` | يحمّل ويعرض `RewardedInterstitialAd` |
| `window.grantAd()` ← | يُنادى تلقائياً عند كسب المكافأة |
| `window.adDismissed()` ← | إغلاق دون إكمال |
| `window.Android.showInterstitial(unitId)` | يحمّل ويعرض `InterstitialAd` |
| `window.adClosed()` ← | يُنادى عند إغلاق الإعلان (نجاح أو فشل) |
| `window.adFailed(msg)` ← | خطأ في التحميل/العرض |

---

## سياسات مهمة (AdMob)
- **Rewarded Interstitial** → لميزة «شاهد واربح» فقط (باختيار المستخدم) ✅
- **Interstitial** → قبل الكابتشا كإعلان بيني قياسي ✅
- لا تضع إعلانات تحرّض على نقرات خاطئة.
- لا تنقر على إعلاناتك الخاصة أو تحرّض المستخدمين على ذلك.
- راجع [سياسات AdMob](https://support.google.com/admob/answer/6128543) قبل النشر.
