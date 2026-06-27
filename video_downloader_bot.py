# ========================================================================
#   🎬 Video Downloader Bot — Dev By SAAD › Tele @layos_he
#   بوت تحميل الفيديوهات من جميع المنصات | لوحة تحكم احترافية
# ========================================================================

#!/usr/bin/env python3
# -*- coding: utf-8 -*-
import sys, io
sys.stdout = io.TextIOWrapper(sys.stdout.buffer, encoding='utf-8', errors='replace')
sys.stderr = io.TextIOWrapper(sys.stderr.buffer, encoding='utf-8', errors='replace')

import os, subprocess, json, sqlite3, asyncio, re, shutil, uuid
from datetime import datetime, date
from pathlib import Path
from urllib.parse import urlparse

# ─── تثبيت المكتبات تلقائياً ───────────────────────────────
REQUIRED = ["aiogram==3.7.0", "aiohttp", "yt-dlp"]

def install_libs():
    needs = []
    try:
        import aiogram
        import aiohttp
        import yt_dlp
    except ImportError:
        needs = REQUIRED
    if needs:
        print("📦 جاري تثبيت المكتبات...")
        subprocess.check_call([sys.executable, "-m", "pip", "install", "--quiet"] + needs)
        print("✅ تم تثبيت المكتبات بنجاح!\n")

install_libs()

# ─── استيراد بعد التثبيت ──────────────────────────────────
from aiogram import Bot, Dispatcher, Router, F
from aiogram.types import (
    Message, CallbackQuery, InlineKeyboardMarkup,
    InlineKeyboardButton, BotCommand, FSInputFile
)
from aiogram.filters import CommandStart, Command, StateFilter
from aiogram.fsm.context import FSMContext
from aiogram.fsm.state import State, StatesGroup
from aiogram.fsm.storage.memory import MemoryStorage
from aiogram.exceptions import TelegramForbiddenError, TelegramBadRequest

# ═══════════════════════════════════════════════════════════
#  إعداد الإعدادات
# ═══════════════════════════════════════════════════════════
CONFIG_FILE = "config.json"

def load_config():
    if os.path.exists(CONFIG_FILE):
        with open(CONFIG_FILE, "r") as f:
            return json.load(f)
    return {}

def save_config(data: dict):
    with open(CONFIG_FILE, "w") as f:
        json.dump(data, f, indent=2, ensure_ascii=False)

def get_tokens(cfg: dict) -> list:
    """يدعم كل توكنات البوتات النشطة (نفس البوت بأكثر من حساب تيليجرام)"""
    tokens = cfg.get("tokens")
    if tokens:
        return list(tokens)
    if cfg.get("token"):
        return [cfg["token"]]
    return []

# ═══════════════════════════════════════════════════════════
#  قاعدة البيانات SQLite (عالمية)
# ═══════════════════════════════════════════════════════════
DB_FILE = "videos_bot.db"

def init_db():
    conn = sqlite3.connect(DB_FILE)
    c = conn.cursor()
    c.executescript("""
        CREATE TABLE IF NOT EXISTS users (
            user_id     INTEGER PRIMARY KEY,
            username    TEXT,
            full_name   TEXT,
            joined_at   TEXT,
            is_blocked  INTEGER DEFAULT 0,
            downloads_count INTEGER DEFAULT 0
        );
        CREATE TABLE IF NOT EXISTS downloads (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id     INTEGER,
            platform    TEXT,
            url         TEXT,
            file_name   TEXT,
            file_size   TEXT,
            downloaded_at TEXT,
            status      TEXT DEFAULT 'success',
            error_msg   TEXT
        );
        CREATE TABLE IF NOT EXISTS broadcasts (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            title       TEXT,
            text        TEXT,
            sent_at     TEXT,
            success     INTEGER DEFAULT 0,
            failed      INTEGER DEFAULT 0
        );
        CREATE TABLE IF NOT EXISTS stats (
            key   TEXT PRIMARY KEY,
            value TEXT
        );
    """)
    # ترقية قاعدة بيانات قديمة لا تملك عمود error_msg
    try:
        c.execute("ALTER TABLE downloads ADD COLUMN error_msg TEXT")
    except sqlite3.OperationalError:
        pass
    conn.commit()
    conn.close()

def db():
    return sqlite3.connect(DB_FILE)

def add_user(user_id: int, username: str, full_name: str):
    with db() as conn:
        conn.execute(
            "INSERT OR IGNORE INTO users (user_id, username, full_name, joined_at) VALUES (?,?,?,?)",
            (user_id, username or "", full_name or "", datetime.now().isoformat())
        )

def record_download(user_id: int, platform: str, url: str, filename, filesize, status="success", error=None):
    with db() as conn:
        conn.execute(
            "INSERT INTO downloads (user_id, platform, url, file_name, file_size, downloaded_at, status, error_msg) "
            "VALUES (?,?,?,?,?,?,?,?)",
            (user_id, platform, url, filename, filesize, datetime.now().isoformat(), status, error)
        )
        if status == "success":
            conn.execute("UPDATE users SET downloads_count = downloads_count + 1 WHERE user_id = ?", (user_id,))

def get_all_users():
    with db() as conn:
        return conn.execute("SELECT user_id FROM users WHERE is_blocked=0").fetchall()

def get_user_by_id(user_id: int):
    with db() as conn:
        return conn.execute("SELECT * FROM users WHERE user_id = ?", (user_id,)).fetchone()

def get_all_users_data():
    """للعرض في لوحة الإدارة"""
    with db() as conn:
        return conn.execute(
            "SELECT user_id, username, full_name, joined_at, downloads_count FROM users ORDER BY joined_at DESC"
        ).fetchall()

def get_downloads_log(limit=15):
    """آخر التحميلات (ناجحة وفاشلة)"""
    with db() as conn:
        return conn.execute(
            "SELECT user_id, platform, file_name, downloaded_at, status, error_msg FROM downloads ORDER BY id DESC LIMIT ?",
            (limit,)
        ).fetchall()

def get_platform_stats():
    with db() as conn:
        return conn.execute(
            "SELECT platform, COUNT(*) FROM downloads WHERE status='success' GROUP BY platform ORDER BY COUNT(*) DESC LIMIT 6"
        ).fetchall()

def get_stats():
    with db() as conn:
        total_users = conn.execute("SELECT COUNT(*) FROM users").fetchone()[0]
        blocked_users = conn.execute("SELECT COUNT(*) FROM users WHERE is_blocked=1").fetchone()[0]
        total_downloads = conn.execute("SELECT COUNT(*) FROM downloads").fetchone()[0]
        success_downloads = conn.execute("SELECT COUNT(*) FROM downloads WHERE status='success'").fetchone()[0]
        failed_downloads = total_downloads - success_downloads
        today = date.today().isoformat()
        today_downloads = conn.execute(
            "SELECT COUNT(*) FROM downloads WHERE downloaded_at LIKE ?", (today + "%",)
        ).fetchone()[0]
        broadcasts = conn.execute("SELECT COUNT(*) FROM broadcasts").fetchone()[0]
        broadcast_success = conn.execute("SELECT COALESCE(SUM(success),0) FROM broadcasts").fetchone()[0]
        broadcast_failed = conn.execute("SELECT COALESCE(SUM(failed),0) FROM broadcasts").fetchone()[0]
        new_today = conn.execute("SELECT COUNT(*) FROM users WHERE joined_at LIKE ?", (today + "%",)).fetchone()[0]
        return {
            "total_users": total_users,
            "blocked_users": blocked_users,
            "total_downloads": total_downloads,
            "success_downloads": success_downloads,
            "failed_downloads": failed_downloads,
            "today_downloads": today_downloads,
            "broadcasts": broadcasts,
            "broadcast_success": broadcast_success,
            "broadcast_failed": broadcast_failed,
            "new_today": new_today
        }

# ═══════════════════════════════════════════════════════════
#  FSM States
# ═══════════════════════════════════════════════════════════
class Setup(StatesGroup):
    token    = State()
    admin_id = State()
    channel  = State()

class BroadcastAds(StatesGroup):
    title   = State()
    message = State()

class Settings(StatesGroup):
    channel    = State()
    custom_btn = State()
    add_token  = State()

# ═══════════════════════════════════════════════════════════
#  أزرار لوحة الإدارة
# ═══════════════════════════════════════════════════════════
def admin_panel_kb():
    return InlineKeyboardMarkup(inline_keyboard=[
        [
            InlineKeyboardButton(text="📊 الإحصائيات", callback_data="stats"),
            InlineKeyboardButton(text="👥 المستخدمون", callback_data="users_info"),
        ],
        [
            InlineKeyboardButton(text="📥 سجل التحميلات", callback_data="downloads_log"),
            InlineKeyboardButton(text="📢 نشر إعلان", callback_data="broadcast_ads"),
        ],
        [
            InlineKeyboardButton(text="⚙️ الإعدادات", callback_data="settings"),
            InlineKeyboardButton(text="🔄 تحديث", callback_data="refresh_panel"),
        ]
    ])

def back_kb():
    return InlineKeyboardMarkup(inline_keyboard=[
        [InlineKeyboardButton(text="↩️ رجوع للوحة", callback_data="back_panel")]
    ])

def settings_kb():
    return InlineKeyboardMarkup(inline_keyboard=[
        [InlineKeyboardButton(text="✏️ تغيير قناة الاشتراك", callback_data="change_channel")],
        [InlineKeyboardButton(text="🔗 تغيير زر القناة/الرابط", callback_data="change_custom_btn")],
        [InlineKeyboardButton(text="🤖 إدارة البوتات (التوكنات)", callback_data="manage_bots")],
        [InlineKeyboardButton(text="↩️ رجوع للوحة", callback_data="back_panel")]
    ])

def bots_kb(cfg: dict):
    tokens = get_tokens(cfg)
    rows = []
    for i, t in enumerate(tokens):
        masked = (t[:10] + "···") if len(t) > 10 else t
        rows.append([InlineKeyboardButton(text=f"❌ حذف: {masked}", callback_data=f"rm_token:{i}")])
    rows.append([InlineKeyboardButton(text="➕ إضافة بوت/توكن جديد", callback_data="add_token")])
    rows.append([InlineKeyboardButton(text="↩️ رجوع للإعدادات", callback_data="settings")])
    return InlineKeyboardMarkup(inline_keyboard=rows)

def confirm_kb():
    return InlineKeyboardMarkup(inline_keyboard=[
        [
            InlineKeyboardButton(text="✅ أرسل الآن", callback_data="confirm_send"),
            InlineKeyboardButton(text="❌ إلغاء", callback_data="cancel_broadcast"),
        ]
    ])

def subscribe_kb(channel: str):
    username = channel.lstrip("@")
    return InlineKeyboardMarkup(inline_keyboard=[
        [InlineKeyboardButton(text="📢 الانضمام للقناة", url=f"https://t.me/{username}")],
        [InlineKeyboardButton(text="✅ تحقق من الاشتراك", callback_data="check_sub")]
    ])

# ═══════════════════════════════════════════════════════════
#  نص لوحة التحكم
# ═══════════════════════════════════════════════════════════
def panel_text(cfg: dict):
    s = get_stats()
    return (
        f"🎬 <b>لوحة تحكم البوت</b>\n"
        f"━━━━━━━━━━━━━━━━━━━━━━━\n"
        f"👥 المستخدمون: <b>{s['total_users']}</b> 🆕 {s['new_today']}\n"
        f"📥 التحميلات: <b>{s['success_downloads']}</b> ❌ {s['failed_downloads']} 📈 {s['today_downloads']}\n"
        f"📢 الإعلانات: <b>{s['broadcasts']}</b>\n"
        f"━━━━━━━━━━━━━━━━━━━━━━━\n"
        f"⏰ <i>{datetime.now().strftime('%Y-%m-%d  %H:%M')}</i>"
    )

# ═══════════════════════════════════════════════════════════
#  دالة تحميل الفيديو
# ═══════════════════════════════════════════════════════════
import yt_dlp

def detect_platform(url: str) -> str:
    mapping = [
        ("youtu", "YouTube"),
        ("tiktok", "TikTok"),
        ("instagram", "Instagram"),
        ("facebook", "Facebook"),
        ("fb.watch", "Facebook"),
        ("twitter", "Twitter/X"),
        ("x.com", "Twitter/X"),
    ]
    for key, name in mapping:
        if key in url:
            return name
    try:
        return urlparse(url).netloc or "Unknown"
    except Exception:
        return "Unknown"

async def download_video(url: str, output_path: str = "downloads"):
    """تحميل الفيديو من أي منصة"""
    try:
        os.makedirs(output_path, exist_ok=True)

        ydl_opts = {
            'format': 'best[ext=mp4][filesize<50M]/best[filesize<50M]/best[ext=mp4]/best',
            'merge_output_format': 'mp4',
            'outtmpl': os.path.join(output_path, '%(id)s.%(ext)s'),
            'noplaylist': True,
            'quiet': True,
            'no_warnings': True,
            'socket_timeout': 30,
        }

        loop = asyncio.get_event_loop()

        def run():
            with yt_dlp.YoutubeDL(ydl_opts) as ydl:
                info = ydl.extract_info(url, download=True)
                filename = ydl.prepare_filename(info)
                return info, filename

        info, filename = await loop.run_in_executor(None, run)
        filesize = os.path.getsize(filename) / (1024 * 1024)  # MB

        return {
            "success": True,
            "filename": filename,
            "filesize": f"{filesize:.2f} MB",
            "title": info.get("title", "Video")
        }
    except Exception as e:
        return {
            "success": False,
            "error": str(e)
        }

FFMPEG_AVAILABLE = shutil.which("ffmpeg") is not None

async def download_audio(url: str, output_path: str = "downloads"):
    """استخراج الصوت كـ MP3 من رابط الفيديو"""
    try:
        os.makedirs(output_path, exist_ok=True)

        ydl_opts = {
            'format': 'bestaudio/best',
            'outtmpl': os.path.join(output_path, '%(id)s.%(ext)s'),
            'noplaylist': True,
            'quiet': True,
            'no_warnings': True,
            'socket_timeout': 30,
            'postprocessors': [{
                'key': 'FFmpegExtractAudio',
                'preferredcodec': 'mp3',
                'preferredquality': '192',
            }],
        }

        loop = asyncio.get_event_loop()

        def run():
            with yt_dlp.YoutubeDL(ydl_opts) as ydl:
                info = ydl.extract_info(url, download=True)
                filename = ydl.prepare_filename(info)
                mp3_path = os.path.splitext(filename)[0] + ".mp3"
                return info, mp3_path

        info, mp3_path = await loop.run_in_executor(None, run)

        return {
            "success": True,
            "filename": mp3_path,
            "title": info.get("title", "Audio")
        }
    except Exception as e:
        return {
            "success": False,
            "error": str(e)
        }

# تخزين مؤقت لربط رابط الفيديو بزر "تحميل MP3" بعد كل تحميل ناجح
pending_downloads: dict[str, str] = {}

def store_pending_url(url: str) -> str:
    if len(pending_downloads) > 1000:
        for old_key in list(pending_downloads.keys())[:200]:
            pending_downloads.pop(old_key, None)
    req_id = uuid.uuid4().hex[:12]
    pending_downloads[req_id] = url
    return req_id

def result_kb(req_id: str, cfg: dict) -> InlineKeyboardMarkup:
    rows = []
    if FFMPEG_AVAILABLE:
        rows.append([InlineKeyboardButton(text="🎵 تحميل MP3", callback_data=f"mp3:{req_id}")])
    btn_text = cfg.get("custom_btn_text")
    btn_url = cfg.get("custom_btn_url")
    if btn_text and btn_url:
        rows.append([InlineKeyboardButton(text=btn_text, url=btn_url)])
    return InlineKeyboardMarkup(inline_keyboard=rows) if rows else None

# ═══════════════════════════════════════════════════════════
#  وظائف الإدارة
# ═══════════════════════════════════════════════════════════
def is_admin(user_id: int, cfg: dict) -> bool:
    return str(user_id) == str(cfg.get("admin_id"))

async def check_subscription(bot: Bot, user_id: int, channel: str) -> bool:
    if not channel:
        return True
    try:
        member = await bot.get_chat_member(channel, user_id)
        return member.status in ("member", "administrator", "creator")
    except Exception:
        return False

def subscription_required_text(channel: str) -> str:
    return (
        f"⚠️ <b>عذراً، يجب الاشتراك أولاً!</b>\n\n"
        f"للاستمرار في استخدام البوت وتحميل المزيد من الفيديوهات، يرجى الاشتراك "
        f"في قناتنا الرسمية أولاً 🙏\n\n"
        f"📢 <b>القناة:</b> {channel}\n\n"
        f"بعد الاشتراك اضغط على زر «✅ تحقق من الاشتراك» لإكمال طلبك."
    )

# ═══════════════════════════════════════════════════════════
#  الراوتر الرئيسي
# ═══════════════════════════════════════════════════════════
def build_router() -> Router:
    router = Router()


    # ─── أمر START ──────────────────────────────────────────────
    @router.message(CommandStart())
    async def start(message: Message, state: FSMContext):
        cfg = load_config()

        if not cfg.get("token") or not cfg.get("admin_id"):
            await state.set_state(Setup.token)
            return await message.answer(
                "🔧 <b>وضع الإعداد الأول</b>\n\n"
                "🔑 أرسل توكن البوت:",
                parse_mode="HTML"
            )

        user_id = message.from_user.id
        username = message.from_user.username or "Unknown"
        full_name = message.from_user.full_name or "User"
        add_user(user_id, username, full_name)

        if is_admin(user_id, cfg):
            await message.answer(
                f"👑 <b>مرحباً بك يا أدمن!</b>\n\n"
                f"لوحة التحكم الخاصة بك:",
                parse_mode="HTML",
                reply_markup=admin_panel_kb()
            )
        else:
            await message.answer(
                f"🎬 <b>أهلاً وسهلاً في بوت التحميل!</b>\n\n"
                f"🚀 <b>كيفية الاستخدام:</b>\n"
                f"ارسل لي رابط الفيديو من أي منصة وسأقوم بـ:\n\n"
                f"✅ YouTube\n"
                f"✅ TikTok\n"
                f"✅ Instagram\n"
                f"✅ Facebook\n"
                f"✅ Twitter/X\n"
                f"✅ وجميع المنصات الأخرى\n\n"
                f"📝 <i>مثال: ارسل رابط الفيديو وسأحمله لك في ثوانٍ!</i>",
                parse_mode="HTML"
            )

    # ═══════════════════════════════════════════════════════════
    #  إعداد البوت (عبر المحادثة - حالة احتياطية)
    # ═══════════════════════════════════════════════════════════
    @router.message(Setup.token)
    async def get_token(message: Message, state: FSMContext):
        token = message.text.strip()
        if ":" not in token:
            return await message.answer("❌ توكن غير صحيح!")

        await state.update_data(token=token)
        await state.set_state(Setup.admin_id)
        await message.answer("👤 الآن أرسل معرفك الرقمي (Chat ID):")

    @router.message(Setup.admin_id)
    async def get_admin_id(message: Message, state: FSMContext):
        try:
            admin_id = int(message.text.strip())
        except ValueError:
            return await message.answer("❌ معرف غير صحيح!")

        await state.update_data(admin_id=admin_id)
        await state.set_state(Setup.channel)
        await message.answer(
            "📢 أرسل يوزرنيم قناة البوت للاشتراك الإجباري (مثال: @MyChannel)\n"
            "أو أرسل <b>تخطي</b> لتفعيلها لاحقاً من الإعدادات.",
            parse_mode="HTML"
        )

    @router.message(Setup.channel)
    async def get_setup_channel(message: Message, state: FSMContext):
        text = message.text.strip()
        channel = None
        if text not in ("تخطي", "skip"):
            if not text.startswith("@"):
                return await message.answer("❌ يجب أن يبدأ يوزرنيم القناة بـ @، أو أرسل تخطي.")
            channel = text

        data = await state.get_data()
        save_config({
            "token": data["token"],
            "admin_id": data["admin_id"],
            "channel": channel
        })

        await state.clear()
        await message.answer(
            f"✅ <b>تم الإعداد بنجاح!</b>\n\n"
            f"🤖 التوكن محفوظ\n"
            f"👑 الأدمن: {data['admin_id']}\n"
            f"📢 قناة الاشتراك: {channel or 'غير محددة'}\n\n"
            f"🚀 سيتم إعادة تشغيل البوت...",
            parse_mode="HTML"
        )

    # ═══════════════════════════════════════════════════════════
    #  معالجة التحميل
    # ═══════════════════════════════════════════════════════════
    async def process_download(bot: Bot, chat_id: int, user_id: int, url: str):
        wait_msg = await bot.send_message(
            chat_id,
            "⏳ <b>جاري تحميل الفيديو...</b>\n<i>قد يستغرق قليلاً</i>",
            parse_mode="HTML"
        )

        platform = detect_platform(url)
        result = await download_video(url)

        if result["success"]:
            filename = result["filename"]
            filesize = result["filesize"]
            title = result["title"]
            caption = (
                f"✅ تم التحميل بنجاح!\n\n"
                f"📺 <b>{platform}</b>\n"
                f"📄 {title[:50]}\n"
                f"📦 {filesize}"
            )
            cfg = load_config()
            req_id = store_pending_url(url)
            kb = result_kb(req_id, cfg)
            try:
                file = FSInputFile(filename)
                ext = os.path.splitext(filename)[1].lower()
                if ext in {".mp4", ".mkv", ".webm", ".mov"}:
                    await bot.send_video(chat_id, file, caption=caption, parse_mode="HTML", reply_markup=kb)
                else:
                    await bot.send_document(chat_id, file, caption=caption, parse_mode="HTML", reply_markup=kb)

                record_download(user_id, platform, url, title, filesize, status="success")
                await wait_msg.delete()
            except Exception as e:
                record_download(user_id, platform, url, title, filesize, status="failed", error=str(e)[:200])
                await wait_msg.edit_text(
                    f"❌ <b>خطأ في الإرسال!</b>\n\n<i>{str(e)[:100]}</i>",
                    parse_mode="HTML"
                )
            finally:
                try:
                    os.remove(filename)
                except Exception:
                    pass
        else:
            record_download(user_id, platform, url, None, None, status="failed", error=result["error"][:200])
            await wait_msg.edit_text(
                f"❌ <b>فشل التحميل!</b>\n\n<i>{result['error'][:100]}</i>",
                parse_mode="HTML"
            )

    # ─── التعامل مع الروابط ─────────────────────────────────────
    @router.message(StateFilter(None), F.text, ~F.text.startswith("/"))
    async def handle_url(message: Message, state: FSMContext, bot: Bot):
        cfg = load_config()

        match = re.search(r"https?://\S+", message.text.strip())
        if not match:
            return await message.answer(
                "❌ الرجاء إرسال رابط صحيح يبدأ بـ https://",
                parse_mode="HTML"
            )
        url = match.group(0)
        user_id = message.from_user.id

        add_user(user_id, message.from_user.username or "", message.from_user.full_name or "")

        user_row = get_user_by_id(user_id)
        downloads_count = user_row[5] if user_row else 0
        channel = cfg.get("channel")

        if not is_admin(user_id, cfg) and downloads_count >= 1 and channel:
            subscribed = await check_subscription(bot, user_id, channel)
            if not subscribed:
                await state.update_data(pending_url=url)
                return await message.answer(
                    subscription_required_text(channel),
                    parse_mode="HTML",
                    reply_markup=subscribe_kb(channel)
                )

        await process_download(bot, message.chat.id, user_id, url)

    @router.callback_query(F.data.startswith("mp3:"))
    async def send_mp3(call: CallbackQuery, bot: Bot):
        req_id = call.data.split(":", 1)[1]
        url = pending_downloads.get(req_id)

        if not url:
            return await call.answer("⚠️ انتهت صلاحية الطلب، أعد إرسال رابط الفيديو من جديد.", show_alert=True)
        if not FFMPEG_AVAILABLE:
            return await call.answer("⚠️ خاصية MP3 غير مفعّلة على السيرفر (ffmpeg غير مثبت).", show_alert=True)

        await call.answer("⏳ جاري تحويل الصوت...")
        wait_msg = await call.message.answer("🎵 <b>جاري استخراج الصوت...</b>", parse_mode="HTML")

        result = await download_audio(url)
        if result["success"]:
            filename = result["filename"]
            try:
                file = FSInputFile(filename)
                await call.message.answer_audio(
                    file,
                    caption=f"🎵 {result['title'][:50]}",
                    parse_mode="HTML"
                )
                await wait_msg.delete()
            except Exception as e:
                await wait_msg.edit_text(f"❌ <b>خطأ في الإرسال!</b>\n\n<i>{str(e)[:100]}</i>", parse_mode="HTML")
            finally:
                try:
                    os.remove(filename)
                except Exception:
                    pass
        else:
            await wait_msg.edit_text(f"❌ <b>فشل استخراج الصوت!</b>\n\n<i>{result['error'][:100]}</i>", parse_mode="HTML")

    @router.callback_query(F.data == "check_sub")
    async def check_sub_cb(call: CallbackQuery, state: FSMContext, bot: Bot):
        cfg = load_config()
        channel = cfg.get("channel")
        subscribed = await check_subscription(bot, call.from_user.id, channel)

        if not subscribed:
            return await call.answer(
                "⛔ لم يتم العثور على اشتراكك، تأكد من الانضمام للقناة ثم أعد المحاولة.",
                show_alert=True
            )

        data = await state.get_data()
        url = data.get("pending_url")
        await call.answer("✅ تم تأكيد الاشتراك!")

        if url:
            await state.update_data(pending_url=None)
            try:
                await call.message.delete()
            except Exception:
                pass
            await process_download(bot, call.message.chat.id, call.from_user.id, url)
        else:
            await call.message.edit_text("✅ تم تأكيد اشتراكك، أرسل رابط الفيديو الآن.", parse_mode="HTML")

    # ─── لوحة التحكم ────────────────────────────────────────────
    @router.callback_query(F.data == "back_panel")
    async def back_panel(call: CallbackQuery):
        cfg = load_config()
        if not is_admin(call.from_user.id, cfg):
            return await call.answer("⛔ غير مصرح", show_alert=True)

        await call.message.edit_text(
            panel_text(cfg),
            parse_mode="HTML",
            reply_markup=admin_panel_kb()
        )
        await call.answer()

    @router.callback_query(F.data == "refresh_panel")
    async def refresh_panel(call: CallbackQuery):
        cfg = load_config()
        if not is_admin(call.from_user.id, cfg):
            return await call.answer("⛔ غير مصرح", show_alert=True)

        await call.message.edit_text(
            panel_text(cfg),
            parse_mode="HTML",
            reply_markup=admin_panel_kb()
        )
        await call.answer("🔄 تم التحديث!")

    # ─── الإحصائيات ─────────────────────────────────────────────
    @router.callback_query(F.data == "stats")
    async def show_stats(call: CallbackQuery):
        cfg = load_config()
        if not is_admin(call.from_user.id, cfg):
            return await call.answer("⛔ غير مصرح", show_alert=True)

        s = get_stats()
        platforms = get_platform_stats()
        success_rate = int(s['success_downloads'] / s['total_downloads'] * 100) if s['total_downloads'] else 0
        broadcast_total = s['broadcast_success'] + s['broadcast_failed']
        broadcast_rate = int(s['broadcast_success'] / broadcast_total * 100) if broadcast_total else 0

        text = (
            f"📊 <b>الإحصائيات المفصلة</b>\n"
            f"━━━━━━━━━━━━━━━━━━━━━\n"
            f"👥 <b>إجمالي المستخدمين:</b> {s['total_users']}\n"
            f"🆕 <b>انضموا اليوم:</b> {s['new_today']}\n"
            f"🚫 <b>محظورون:</b> {s['blocked_users']}\n"
            f"━━━━━━━━━━━━━━━━━━━━━\n"
            f"📥 <b>إجمالي طلبات التحميل:</b> {s['total_downloads']}\n"
            f"✅ <b>ناجحة:</b> {s['success_downloads']}\n"
            f"❌ <b>فاشلة:</b> {s['failed_downloads']}\n"
            f"📈 <b>نسبة النجاح:</b> {success_rate}%\n"
            f"📊 <b>تحميلات اليوم:</b> {s['today_downloads']}\n"
            f"━━━━━━━━━━━━━━━━━━━━━\n"
            f"📢 <b>الإعلانات المرسلة:</b> {s['broadcasts']}\n"
            f"📤 <b>إجمالي وصل:</b> {s['broadcast_success']} | ❌ فشل: {s['broadcast_failed']} ({broadcast_rate}%)\n"
        )

        if platforms:
            text += "━━━━━━━━━━━━━━━━━━━━━\n📺 <b>الأكثر استخداماً:</b>\n"
            for platform, count in platforms:
                text += f"  • {platform}: <b>{count}</b>\n"

        text += f"━━━━━━━━━━━━━━━━━━━━━\n⏰ {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}"

        await call.message.edit_text(text, parse_mode="HTML", reply_markup=back_kb())
        await call.answer()

    # ─── قائمة المستخدمين ──────────────────────────────────────
    @router.callback_query(F.data == "users_info")
    async def show_users(call: CallbackQuery):
        cfg = load_config()
        if not is_admin(call.from_user.id, cfg):
            return await call.answer("⛔ غير مصرح", show_alert=True)

        users = get_all_users_data()

        if not users:
            text = "👥 <b>لا توجد بيانات مستخدمين</b>"
        else:
            lines = ["👥 <b>قائمة المستخدمين العالمية</b>\n━━━━━━━━━━━━━━━━\n"]
            for u in users[:20]:
                user_id, username, full_name, joined_at, downloads = u
                lines.append(
                    f"<b>ID:</b> {user_id}\n"
                    f"<b>الاسم:</b> {full_name}\n"
                    f"<b>اليوزر:</b> @{username}\n"
                    f"<b>التحميلات:</b> {downloads}\n"
                    f"<b>التاريخ:</b> {joined_at[:10]}\n"
                    f"─────────────\n"
                )

            if len(users) > 20:
                lines.append(f"<i>... و {len(users)-20} مستخدم آخر</i>")

            text = "".join(lines)

        await call.message.edit_text(text, parse_mode="HTML", reply_markup=back_kb())
        await call.answer()

    # ─── سجل التحميلات ─────────────────────────────────────────
    @router.callback_query(F.data == "downloads_log")
    async def show_downloads(call: CallbackQuery):
        cfg = load_config()
        if not is_admin(call.from_user.id, cfg):
            return await call.answer("⛔ غير مصرح", show_alert=True)

        downloads = get_downloads_log(15)

        if not downloads:
            text = "📥 <b>لا توجد تحميلات</b>"
        else:
            lines = ["📥 <b>آخر التحميلات</b>\n━━━━━━━━━━━━━━━━\n"]
            for d in downloads:
                user_id, platform, filename, downloaded_at, status, error_msg = d
                status_icon = "✅" if status == "success" else "❌"
                lines.append(
                    f"👤 المستخدم: <code>{user_id}</code>\n"
                    f"📺 المنصة: <b>{platform}</b>\n"
                    f"📄 الملف: <i>{(filename or '-')[:40]}</i>\n"
                    f"⏰ الوقت: {downloaded_at[:16]}\n"
                    f"{status_icon} الحالة: {status}\n"
                )
                if status != "success" and error_msg:
                    lines.append(f"⚠️ السبب: <i>{error_msg[:60]}</i>\n")
                lines.append("─────────────\n")
            text = "".join(lines)

        await call.message.edit_text(text, parse_mode="HTML", reply_markup=back_kb())
        await call.answer()

    # ─── الإعدادات ──────────────────────────────────────────────
    @router.callback_query(F.data == "settings")
    async def show_settings(call: CallbackQuery):
        cfg = load_config()
        if not is_admin(call.from_user.id, cfg):
            return await call.answer("⛔ غير مصرح", show_alert=True)

        channel = cfg.get("channel") or "غير محددة (الاشتراك الإجباري معطل)"
        btn_text = cfg.get("custom_btn_text")
        btn_url = cfg.get("custom_btn_url")
        custom_btn = f"{btn_text} → {btn_url}" if btn_text and btn_url else "غير مفعّل"
        text = (
            f"⚙️ <b>الإعدادات</b>\n"
            f"━━━━━━━━━━━━━━━━━━━━━\n"
            f"📢 <b>قناة الاشتراك الإجباري:</b> {channel}\n"
            f"🔗 <b>زر القناة/الرابط أسفل كل فيديو:</b> {custom_btn}\n"
            f"👑 <b>الأدمن:</b> <code>{cfg.get('admin_id')}</code>\n"
        )
        await call.message.edit_text(text, parse_mode="HTML", reply_markup=settings_kb())
        await call.answer()

    @router.callback_query(F.data == "change_channel")
    async def change_channel_start(call: CallbackQuery, state: FSMContext):
        cfg = load_config()
        if not is_admin(call.from_user.id, cfg):
            return await call.answer("⛔ غير مصرح", show_alert=True)

        await state.set_state(Settings.channel)
        await call.message.edit_text(
            "✏️ أرسل يوزرنيم القناة الجديدة (يجب أن يبدأ بـ @)\nأو أرسل <b>تعطيل</b> لإلغاء الاشتراك الإجباري:",
            parse_mode="HTML"
        )
        await call.answer()

    @router.message(Settings.channel)
    async def change_channel_set(message: Message, state: FSMContext):
        text = message.text.strip()
        cfg = load_config()

        if text in ("تعطيل", "disable"):
            cfg["channel"] = None
            save_config(cfg)
            await state.clear()
            return await message.answer("✅ تم تعطيل الاشتراك الإجباري.", parse_mode="HTML", reply_markup=back_kb())

        if not text.startswith("@"):
            return await message.answer("❌ يجب أن يبدأ يوزرنيم القناة بـ @، أو أرسل تعطيل.")

        cfg["channel"] = text
        save_config(cfg)
        await state.clear()
        await message.answer(f"✅ تم تحديث قناة الاشتراك إلى {text}", parse_mode="HTML", reply_markup=back_kb())

    @router.callback_query(F.data == "change_custom_btn")
    async def change_custom_btn_start(call: CallbackQuery, state: FSMContext):
        cfg = load_config()
        if not is_admin(call.from_user.id, cfg):
            return await call.answer("⛔ غير مصرح", show_alert=True)

        await state.set_state(Settings.custom_btn)
        await call.message.edit_text(
            "🔗 أرسل النص والرابط بالشكل التالي (مفصولين بـ |):\n\n"
            "<code>📢 قناتنا|https://t.me/YourChannel</code>\n\n"
            "هذا الزر سيظهر أسفل كل فيديو يحمّله المستخدمون.\n"
            "أو أرسل <b>تعطيل</b> لإخفاء الزر.",
            parse_mode="HTML"
        )
        await call.answer()

    @router.message(Settings.custom_btn)
    async def change_custom_btn_set(message: Message, state: FSMContext):
        text = message.text.strip()
        cfg = load_config()

        if text in ("تعطيل", "disable"):
            cfg["custom_btn_text"] = None
            cfg["custom_btn_url"] = None
            save_config(cfg)
            await state.clear()
            return await message.answer("✅ تم إخفاء الزر.", parse_mode="HTML", reply_markup=back_kb())

        if "|" not in text:
            return await message.answer("❌ الصيغة غير صحيحة، استخدم: النص|الرابط (مثال: 📢 قناتنا|https://t.me/Channel)")

        btn_text, btn_url = text.split("|", 1)
        btn_text, btn_url = btn_text.strip(), btn_url.strip()
        if not btn_url.startswith("http"):
            return await message.answer("❌ الرابط يجب أن يبدأ بـ http:// أو https://")

        cfg["custom_btn_text"] = btn_text
        cfg["custom_btn_url"] = btn_url
        save_config(cfg)
        await state.clear()
        await message.answer(f"✅ تم تحديث الزر: {btn_text} → {btn_url}", parse_mode="HTML", reply_markup=back_kb())

    # ─── إدارة البوتات (توكنات متعددة لنفس البوت) ──────────────
    @router.callback_query(F.data == "manage_bots")
    async def manage_bots_cb(call: CallbackQuery):
        cfg = load_config()
        if not is_admin(call.from_user.id, cfg):
            return await call.answer("⛔ غير مصرح", show_alert=True)

        tokens = get_tokens(cfg)
        text = (
            f"🤖 <b>إدارة البوتات</b>\n"
            f"━━━━━━━━━━━━━━━━━━━━━\n"
            f"عدد البوتات النشطة: <b>{len(tokens)}</b>\n"
            f"كل التوكنات أدناه تُشغّل نفس البوت ونفس قاعدة البيانات والإحصائيات.\n"
        )
        await call.message.edit_text(text, parse_mode="HTML", reply_markup=bots_kb(cfg))
        await call.answer()

    @router.callback_query(F.data == "add_token")
    async def add_token_start(call: CallbackQuery, state: FSMContext):
        cfg = load_config()
        if not is_admin(call.from_user.id, cfg):
            return await call.answer("⛔ غير مصرح", show_alert=True)

        await state.set_state(Settings.add_token)
        await call.message.edit_text(
            "🔑 أرسل توكن البوت الجديد (من @BotFather):\n"
            "سيتم تشغيله فوراً بنفس مميزات هذا البوت وبدون إعادة تشغيل السيرفر.",
            parse_mode="HTML"
        )
        await call.answer()

    @router.message(Settings.add_token)
    async def add_token_set(message: Message, state: FSMContext):
        token = message.text.strip()
        if ":" not in token:
            return await message.answer("❌ توكن غير صحيح، حاول مجدداً:")

        cfg = load_config()
        tokens = get_tokens(cfg)
        if token in tokens:
            await state.clear()
            return await message.answer("⚠️ هذا التوكن مُضاف ويعمل بالفعل.", parse_mode="HTML", reply_markup=back_kb())

        wait = await message.answer("⏳ جاري التحقق من التوكن وتشغيل البوت...", parse_mode="HTML")
        ok = await launch_bot(token)
        if not ok:
            await state.clear()
            return await wait.edit_text(
                "❌ توكن غير صالح أو تعذّر الاتصال بتيليجرام، تأكد منه وحاول مجدداً.",
                parse_mode="HTML"
            )

        tokens.append(token)
        cfg["tokens"] = tokens
        cfg.pop("token", None)
        save_config(cfg)
        await state.clear()
        await wait.edit_text(
            "✅ <b>تم إضافة البوت وتشغيله بنجاح!</b>\nيعمل الآن بشكل دائم بجانب البوت الحالي.",
            parse_mode="HTML",
            reply_markup=back_kb()
        )

    @router.callback_query(F.data.startswith("rm_token:"))
    async def remove_token_cb(call: CallbackQuery):
        cfg = load_config()
        if not is_admin(call.from_user.id, cfg):
            return await call.answer("⛔ غير مصرح", show_alert=True)

        tokens = get_tokens(cfg)
        idx = int(call.data.split(":", 1)[1])
        if idx < 0 or idx >= len(tokens):
            return await call.answer("⚠️ غير موجود", show_alert=True)
        if len(tokens) <= 1:
            return await call.answer("⚠️ لا يمكن حذف آخر بوت نشط.", show_alert=True)

        token = tokens.pop(idx)
        cfg["tokens"] = tokens
        save_config(cfg)
        await stop_bot(token)
        await call.answer("🗑️ تم حذف وإيقاف هذا البوت.")

        text = (
            f"🤖 <b>إدارة البوتات</b>\n"
            f"━━━━━━━━━━━━━━━━━━━━━\n"
            f"عدد البوتات النشطة: <b>{len(tokens)}</b>\n"
        )
        await call.message.edit_text(text, parse_mode="HTML", reply_markup=bots_kb(cfg))

    # ─── نشر الإعلانات ─────────────────────────────────────────
    @router.callback_query(F.data == "broadcast_ads")
    async def start_broadcast(call: CallbackQuery, state: FSMContext):
        cfg = load_config()
        if not is_admin(call.from_user.id, cfg):
            return await call.answer("⛔ غير مصرح", show_alert=True)

        await state.set_state(BroadcastAds.title)
        await call.message.edit_text(
            "📢 <b>نشر إعلان</b>\n\n"
            "أرسل <b>عنوان</b> الإعلان:",
            parse_mode="HTML"
        )
        await call.answer()

    @router.message(BroadcastAds.title)
    async def get_ad_title(message: Message, state: FSMContext):
        await state.update_data(ad_title=message.text)
        await state.set_state(BroadcastAds.message)
        await message.answer(
            "✍️ الآن أرسل <b>محتوى</b> الإعلان:",
            parse_mode="HTML"
        )

    @router.message(BroadcastAds.message)
    async def get_ad_message(message: Message, state: FSMContext, bot: Bot):
        data = await state.get_data()

        ad_title = data.get("ad_title", "")
        ad_text = message.text

        users = get_all_users()

        confirm_text = (
            f"📢 <b>تأكيد الإعلان</b>\n\n"
            f"<b>العنوان:</b> {ad_title}\n"
            f"<b>المحتوى:</b> {ad_text[:100]}...\n"
            f"<b>المستقبلون:</b> {len(users)} مستخدم"
        )

        await state.update_data(ad_message=ad_text)
        await message.answer(confirm_text, parse_mode="HTML", reply_markup=confirm_kb())

    @router.callback_query(F.data == "confirm_send")
    async def confirm_ads(call: CallbackQuery, state: FSMContext, bot: Bot):
        cfg = load_config()
        if not is_admin(call.from_user.id, cfg):
            return await call.answer("⛔ غير مصرح", show_alert=True)

        data = await state.get_data()
        ad_title = data.get("ad_title", "إعلان")
        ad_text = data.get("ad_message", "")

        users = get_all_users()
        await state.clear()

        status_msg = await call.message.edit_text(
            f"🚀 <b>جاري الإرسال...</b>\n"
            f"👥 {len(users)} مستخدم",
            parse_mode="HTML"
        )

        success = 0
        failed = 0

        full_text = f"📢 <b>{ad_title}</b>\n\n{ad_text}\n\n" \
                    f"━━━━━━━━━━━━━━━━\n" \
                    f"<i>Dev By SAAD › Tele @layos_he</i>"

        for (uid,) in users:
            try:
                await bot.send_message(uid, full_text, parse_mode="HTML")
                success += 1
            except (TelegramForbiddenError, TelegramBadRequest):
                failed += 1
            except Exception:
                failed += 1
            await asyncio.sleep(0.05)

        with db() as conn:
            conn.execute(
                "INSERT INTO broadcasts (title, text, sent_at, success, failed) VALUES (?,?,?,?,?)",
                (ad_title, ad_text, datetime.now().isoformat(), success, failed)
            )

        total = success + failed
        rate = int(success / total * 100) if total > 0 else 0
        await status_msg.edit_text(
            f"✅ <b>تم الإرسال!</b>\n\n"
            f"👥 إجمالي المستقبلين: <b>{total}</b>\n"
            f"📤 وصلت (نجح الإرسال): <b>{success}</b>\n"
            f"❌ لم تصل (حظر/خطأ): <b>{failed}</b>\n"
            f"📊 نسبة الوصول: <b>{rate}%</b>",
            parse_mode="HTML",
            reply_markup=back_kb()
        )

    @router.callback_query(F.data == "cancel_broadcast")
    async def cancel_ads(call: CallbackQuery, state: FSMContext):
        await state.clear()
        cfg = load_config()
        await call.message.edit_text(
            panel_text(cfg),
            parse_mode="HTML",
            reply_markup=admin_panel_kb()
        )
        await call.answer("❌ تم الإلغاء")

    # ─── أمر الإدارة ────────────────────────────────────────────
    @router.message(Command("admin"))
    async def admin_command(message: Message):
        cfg = load_config()
        if not is_admin(message.from_user.id, cfg):
            return await message.answer("⛔ غير مصرح", parse_mode="HTML")

        await message.answer(
            panel_text(cfg),
            parse_mode="HTML",
            reply_markup=admin_panel_kb()
        )

    return router

# ═══════════════════════════════════════════════════════════
#  مدير البوتات المتعددة (نفس البوت بأكثر من توكن/حساب)
# ═══════════════════════════════════════════════════════════
running_bots: dict = {}

async def launch_bot(token: str) -> bool:
    """يشغّل توكن بوت جديد فوراً بنفس الراوتر والمميزات، بدون إيقاف البوتات الأخرى."""
    if token in running_bots:
        return True
    try:
        bot = Bot(token=token)
        await bot.get_me()
    except Exception:
        return False

    dp = Dispatcher(storage=MemoryStorage())
    dp.include_router(build_router())

    try:
        await bot.set_my_commands([
            BotCommand(command="start", description="🏠 ابدأ من هنا"),
            BotCommand(command="admin", description="⚙️ لوحة التحكم"),
        ])
    except Exception:
        pass

    task = asyncio.create_task(dp.start_polling(bot, allowed_updates=["message", "callback_query"]))
    running_bots[token] = {"bot": bot, "dp": dp, "task": task}
    return True

async def stop_bot(token: str):
    info = running_bots.pop(token, None)
    if not info:
        return
    info["task"].cancel()
    try:
        await info["bot"].session.close()
    except Exception:
        pass

# ═══════════════════════════════════════════════════════════
#  الإعداد الأول التفاعلي (يجب أن يتم قبل التحويل للخلفية)
# ═══════════════════════════════════════════════════════════
def initial_setup_console():
    cfg = load_config()
    if get_tokens(cfg) and cfg.get("admin_id"):
        return

    print("🔧 بدء وضع الإعداد الأول...")
    token = input("🔑 أدخل توكن البوت: ").strip()
    admin_id_raw = input("👤 أدخل معرف الأدمن (Chat ID): ").strip()
    channel = input("📢 أدخل يوزرنيم قناة الاشتراك الإجباري (Enter للتخطي): ").strip()

    if not token or ":" not in token:
        print("❌ توكن غير صحيح!")
        sys.exit(1)

    try:
        admin_id = int(admin_id_raw)
    except ValueError:
        print("❌ معرف غير صحيح!")
        sys.exit(1)

    save_config({"tokens": [token], "admin_id": admin_id, "channel": channel or None})
    print("✅ تم الحفظ!\n")

# ═══════════════════════════════════════════════════════════
#  التشغيل الدائم بالخلفية (nohup) — يعمل تلقائياً عند أول تشغيل
# ═══════════════════════════════════════════════════════════
def daemonize_with_nohup():
    if os.environ.get("BOT_DAEMON_CHILD") == "1":
        return  # هذه هي العملية الحقيقية التي تعمل بالخلفية فعلاً

    if os.name != "posix" or shutil.which("nohup") is None:
        print("ℹ️ nohup غير متاح على هذا النظام، سيعمل البوت في المقدمة بهذه الجلسة فقط.")
        return

    script_path = os.path.abspath(__file__)
    log_path = os.path.join(os.path.dirname(script_path), "bot.log")
    env = os.environ.copy()
    env["BOT_DAEMON_CHILD"] = "1"
    log_file = open(log_path, "a")

    subprocess.Popen(
        ["nohup", sys.executable, script_path],
        stdout=log_file, stderr=log_file, stdin=subprocess.DEVNULL,
        env=env, start_new_session=True, cwd=os.path.dirname(script_path) or "."
    )

    print("✅ تم تشغيل البوت بالخلفية بشكل دائم (nohup) — سيستمر العمل حتى بعد إغلاق الطرفية.")
    print(f"📄 السجلات: {log_path}")
    print(f"🛑 لإيقافه: pkill -f \"{os.path.basename(script_path)}\"")
    sys.exit(0)

# ═══════════════════════════════════════════════════════════
#  تشغيل البوت (يدعم تشغيل أكثر من توكن لنفس البوت في آنٍ واحد)
# ═══════════════════════════════════════════════════════════
async def main():
    print("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━")
    print("  🎬 Video Downloader Bot — Dev By SAAD")
    print("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━")

    init_db()
    print("✅ قاعدة البيانات جاهزة")

    cfg = load_config()
    tokens = get_tokens(cfg)
    admin_id = cfg.get("admin_id")

    if not tokens or not admin_id:
        print("❌ لا توجد إعدادات صحيحة (توكن/أدمن)، شغّل الملف من الطرفية مباشرة لإكمال الإعداد.")
        return

    print(f"👑 الأدمن: {admin_id}")
    print(f"📢 قناة الاشتراك: {cfg.get('channel') or 'غير محددة'}")
    print(f"🗄 قاعدة البيانات: {DB_FILE}")
    print(f"🤖 عدد البوتات: {len(tokens)}")
    if FFMPEG_AVAILABLE:
        print("🎵 ffmpeg متوفر: زر تحميل MP3 مفعّل")
    else:
        print("⚠️ ffmpeg غير مثبت: زر تحميل MP3 سيكون مخفياً (ثبّته عبر: apt install ffmpeg)")

    for token in tokens:
        ok = await launch_bot(token)
        print(f"{'🚀' if ok else '❌'} {'تم تشغيل بوت بنجاح' if ok else 'فشل تشغيل بوت (توكن غير صالح)'}: {token[:10]}···")

    if not running_bots:
        print("❌ لم يتم تشغيل أي بوت، تأكد من صحة التوكنات في config.json")
        return

    try:
        first_bot = next(iter(running_bots.values()))["bot"]
        s = get_stats()
        await first_bot.send_message(
            admin_id,
            f"🟢 <b>البوت شُغِّل بنجاح! ({len(running_bots)} بوت نشط)</b>\n\n"
            f"👥 المستخدمون: {s['total_users']}\n"
            f"📥 التحميلات الناجحة: {s['success_downloads']}\n"
            f"⏰ {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}\n\n"
            f"<i>Dev By SAAD › Tele @layos_he</i>",
            parse_mode="HTML",
            reply_markup=admin_panel_kb()
        )
    except Exception as e:
        print(f"⚠️ تعذّر إرسال الإشعار: {e}")

    print("🚀 جميع البوتات تعمل الآن!\n")
    await asyncio.Event().wait()

if __name__ == "__main__":
    initial_setup_console()
    daemonize_with_nohup()
    try:
        asyncio.run(main())
    except KeyboardInterrupt:
        print("\n⛔ تم إيقاف البوت")
