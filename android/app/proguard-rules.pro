# AdMob / Google Mobile Ads
-keep class com.google.android.gms.ads.** { *; }
# WebView JavaScript interface
-keepclassmembers class com.yassota.cash.MainActivity$AdBridge {
    @android.webkit.JavascriptInterface <methods>;
}
