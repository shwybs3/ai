package com.yassota.cash

import android.annotation.SuppressLint
import android.os.Bundle
import android.webkit.JavascriptInterface
import android.webkit.WebChromeClient
import android.webkit.WebResourceRequest
import android.webkit.WebView
import android.webkit.WebViewClient
import androidx.appcompat.app.AppCompatActivity
import com.google.android.gms.ads.AdError
import com.google.android.gms.ads.FullScreenContentCallback
import com.google.android.gms.ads.LoadAdError
import com.google.android.gms.ads.MobileAds
import com.google.android.gms.ads.interstitial.InterstitialAd
import com.google.android.gms.ads.interstitial.InterstitialAdLoadCallback
import com.google.android.gms.ads.rewardedinterstitial.RewardedInterstitialAd
import com.google.android.gms.ads.rewardedinterstitial.RewardedInterstitialAdLoadCallback
import com.google.android.gms.ads.AdRequest

/** رابط الموقع — غيّره إلى نطاقك الفعلي قبل البناء */
private const val SITE_URL = "https://your-domain.com/index.php"

class MainActivity : AppCompatActivity() {

    private lateinit var webView: WebView

    @SuppressLint("SetJavaScriptEnabled")
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        setContentView(R.layout.activity_main)

        MobileAds.initialize(this)

        webView = findViewById(R.id.webView)
        webView.settings.apply {
            javaScriptEnabled = true
            domStorageEnabled = true
            databaseEnabled = true
            setSupportZoom(false)
            loadWithOverviewMode = true
            useWideViewPort = true
        }
        webView.webChromeClient = WebChromeClient()
        webView.webViewClient = object : WebViewClient() {
            override fun shouldOverrideUrlLoading(view: WebView, request: WebResourceRequest): Boolean {
                // السماح بفتح روابط نفس النطاق داخل WebView
                val url = request.url.toString()
                return !url.startsWith(SITE_URL.substringBefore("/index.php"))
            }
        }
        webView.addJavascriptInterface(AdBridge(), "Android")
        webView.loadUrl(SITE_URL)
    }

    override fun onBackPressed() {
        if (webView.canGoBack()) webView.goBack() else super.onBackPressed()
    }

    inner class AdBridge {

        /**
         * يستدعيه الموقع عبر window.Android.showRewardedAd(unitId)
         * عند اكتمال المشاهدة يستدعي window.grantAd() في الويب.
         */
        @JavascriptInterface
        fun showRewardedAd(unitId: String) {
            val adRequest = AdRequest.Builder().build()
            runOnUiThread {
                RewardedInterstitialAd.load(this@MainActivity, unitId, adRequest,
                    object : RewardedInterstitialAdLoadCallback() {
                        override fun onAdLoaded(ad: RewardedInterstitialAd) {
                            ad.fullScreenContentCallback = object : FullScreenContentCallback() {
                                override fun onAdDismissedFullScreenContent() {
                                    // المستخدم أغلق الإعلان قبل الانتهاء
                                    webView.evaluateJavascript("window.adDismissed&&window.adDismissed()", null)
                                }
                                override fun onAdFailedToShowFullScreenContent(err: AdError) {
                                    webView.evaluateJavascript(
                                        "window.adFailed&&window.adFailed(${escapeJs(err.message)})", null
                                    )
                                }
                            }
                            ad.show(this@MainActivity) {
                                // المستخدم كسب المكافأة — نُبلّغ الويب
                                webView.evaluateJavascript("window.grantAd&&window.grantAd()", null)
                            }
                        }
                        override fun onAdFailedToLoad(err: LoadAdError) {
                            webView.evaluateJavascript(
                                "window.adFailed&&window.adFailed(${escapeJs(err.message)})", null
                            )
                        }
                    })
            }
        }

        /**
         * يستدعيه الموقع عبر window.Android.showInterstitial(unitId) — قبل الكابتشا.
         * عند الإغلاق يستدعي window.adClosed() ليكمل الموقع التدفّق.
         */
        @JavascriptInterface
        fun showInterstitial(unitId: String) {
            val adRequest = AdRequest.Builder().build()
            runOnUiThread {
                InterstitialAd.load(this@MainActivity, unitId, adRequest,
                    object : InterstitialAdLoadCallback() {
                        override fun onAdLoaded(ad: InterstitialAd) {
                            ad.fullScreenContentCallback = object : FullScreenContentCallback() {
                                override fun onAdDismissedFullScreenContent() {
                                    webView.evaluateJavascript("window.adClosed&&window.adClosed()", null)
                                }
                                override fun onAdFailedToShowFullScreenContent(err: AdError) {
                                    // فشل العرض — نكمل التدفّق بأمان
                                    webView.evaluateJavascript("window.adClosed&&window.adClosed()", null)
                                }
                            }
                            ad.show(this@MainActivity)
                        }
                        override fun onAdFailedToLoad(err: LoadAdError) {
                            // فشل التحميل — نكمل التدفّق بأمان
                            webView.evaluateJavascript("window.adClosed&&window.adClosed()", null)
                        }
                    })
            }
        }

        private fun escapeJs(s: String): String =
            "\"" + s.replace("\\", "\\\\").replace("\"", "\\\"").replace("\n", "\\n") + "\""
    }
}
