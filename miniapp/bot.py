"""
بوت تيليجرام المرافق لتطبيق Crocodile Tap Mini App.
يفتح الميني اب، يسجّل روابط الدعوة (start=ref_<id>) عبر استدعاء الـ API،
ويعرض لوحة إدارة مبسّطة للأدمن (إحصائيات + طلبات سحب).
"""
import os
import sqlite3

from aiogram import Bot, Dispatcher, F, Router
from aiogram.filters import Command, CommandStart
from aiogram.types import (
    InlineKeyboardButton,
    InlineKeyboardMarkup,
    Message,
    WebAppInfo,
)

from server import DB_FILE, REFERRAL_BONUS, init_db

BOT_TOKEN = os.environ.get("MINIAPP_BOT_TOKEN", "")
ADMIN_ID = int(os.environ.get("MINIAPP_ADMIN_ID", "0") or 0)
WEBAPP_URL = os.environ.get("MINIAPP_URL", "https://example.com")

router = Router()


def db():
    conn = sqlite3.connect(DB_FILE)
    conn.row_factory = sqlite3.Row
    return conn


def is_admin(user_id: int) -> bool:
    return ADMIN_ID and user_id == ADMIN_ID


def open_app_kb():
    return InlineKeyboardMarkup(inline_keyboard=[
        [InlineKeyboardButton(text="🐊 افتح Crocodile Tap", web_app=WebAppInfo(url=WEBAPP_URL))]
    ])


@router.message(CommandStart())
async def start(message: Message):
    args = message.text.split(maxsplit=1)
    referred_by = None
    if len(args) > 1 and args[1].startswith("ref_"):
        try:
            ref_id = int(args[1].removeprefix("ref_"))
            if ref_id != message.from_user.id:
                referred_by = ref_id
        except ValueError:
            pass

    conn = db()
    row = conn.execute("SELECT user_id FROM users WHERE user_id=?", (message.from_user.id,)).fetchone()
    if row is None:
        import time
        conn.execute(
            "INSERT INTO users (user_id, username, first_name, coins, energy, last_energy_ts, referred_by, joined_ts) "
            "VALUES (?,?,?,0,1000,?,?,?)",
            (message.from_user.id, message.from_user.username, message.from_user.first_name,
             time.time(), referred_by, time.time()),
        )
        if referred_by:
            conn.execute("UPDATE users SET coins = coins + ? WHERE user_id=?", (REFERRAL_BONUS, referred_by))
        conn.commit()
    conn.close()

    await message.answer(
        "🐊 <b>أهلاً بك في Crocodile Tap!</b>\n\n"
        "انقر على التمساح لجمع العملات، أكمل المهام، وادعُ أصدقاءك لمضاعفة أرباحك!",
        parse_mode="HTML",
        reply_markup=open_app_kb(),
    )


@router.message(Command("admin"))
async def admin_panel(message: Message):
    if not is_admin(message.from_user.id):
        return
    conn = db()
    total_users = conn.execute("SELECT COUNT(*) c FROM users").fetchone()["c"]
    total_coins = conn.execute("SELECT COALESCE(SUM(coins),0) s FROM users").fetchone()["s"]
    pending = conn.execute("SELECT COUNT(*) c FROM withdrawals WHERE status='pending'").fetchone()["c"]
    conn.close()
    await message.answer(
        f"📊 <b>إحصائيات Crocodile Tap</b>\n\n"
        f"👥 المستخدمون: {total_users}\n"
        f"🪙 إجمالي العملات المتداولة: {total_coins:,}\n"
        f"💸 طلبات سحب معلّقة: {pending}\n\n"
        f"استخدم /withdrawals لعرض تفاصيل طلبات السحب.",
        parse_mode="HTML",
    )


@router.message(Command("withdrawals"))
async def withdrawals(message: Message):
    if not is_admin(message.from_user.id):
        return
    conn = db()
    rows = conn.execute(
        "SELECT w.id, w.user_id, w.amount, u.username, u.first_name "
        "FROM withdrawals w LEFT JOIN users u ON u.user_id = w.user_id "
        "WHERE w.status='pending' ORDER BY w.created_ts ASC LIMIT 20"
    ).fetchall()
    conn.close()
    if not rows:
        return await message.answer("لا توجد طلبات سحب معلّقة حالياً.")
    lines = [
        f"#{r['id']} — {r['username'] or r['first_name'] or r['user_id']} — 🪙 {r['amount']:,}"
        for r in rows
    ]
    await message.answer("💸 <b>طلبات السحب المعلّقة:</b>\n\n" + "\n".join(lines), parse_mode="HTML")


async def main():
    if not BOT_TOKEN:
        print("❌ يجب ضبط متغير البيئة MINIAPP_BOT_TOKEN قبل التشغيل.")
        return
    init_db()
    bot = Bot(token=BOT_TOKEN)
    dp = Dispatcher()
    dp.include_router(router)
    print("🚀 بوت Crocodile Tap يعمل الآن...")
    await dp.start_polling(bot, allowed_updates=["message"])


if __name__ == "__main__":
    import asyncio
    asyncio.run(main())
