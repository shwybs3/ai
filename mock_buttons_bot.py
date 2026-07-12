#!/usr/bin/env python3
"""
Telegram bot that generates mock button images for screenshots.
Users can customize button text, price, color, and layout.
"""

import os
import io
import logging
from dataclasses import dataclass, field
from typing import Optional
from telegram import Update, InlineKeyboardButton, InlineKeyboardMarkup
from telegram.ext import (
    Application, CommandHandler, CallbackQueryHandler,
    MessageHandler, filters, ContextTypes, ConversationHandler,
)
from PIL import Image, ImageDraw, ImageFont

logging.basicConfig(
    format="%(asctime)s - %(name)s - %(levelname)s - %(message)s",
    level=logging.INFO,
)
logger = logging.getLogger(__name__)

BOT_TOKEN = os.environ.get("BOT_TOKEN", "YOUR_BOT_TOKEN_HERE")

# Conversation states
(
    MAIN_MENU,
    ENTER_TEXT,
    ENTER_PRICE,
    ENTER_COLOR,
    CHOOSE_LAYOUT,
    CHOOSE_STYLE,
) = range(6)

# Available colors
COLORS = {
    "🔵 أزرق":       ("#2D9CDB", "#FFFFFF"),
    "🟢 أخضر":       ("#27AE60", "#FFFFFF"),
    "🔴 أحمر":       ("#EB5757", "#FFFFFF"),
    "🟡 أصفر":       ("#F2C94C", "#1A1A1A"),
    "🟠 برتقالي":    ("#F2994A", "#FFFFFF"),
    "🟣 بنفسجي":     ("#9B51E0", "#FFFFFF"),
    "⚫ أسود":       ("#1A1A1A", "#FFFFFF"),
    "⚪ أبيض":       ("#FFFFFF", "#1A1A1A"),
    "🩶 رمادي":      ("#828282", "#FFFFFF"),
    "🌊 فيروزي":     ("#56CCF2", "#1A1A1A"),
    "🌸 وردي":       ("#F472B6", "#FFFFFF"),
    "🟤 بني":        ("#9B7653", "#FFFFFF"),
}

STYLES = {
    "مستدير": 18,
    "مربع":   4,
    "بيضاوي": 30,
}

LAYOUTS = {
    "1 زر في كل صف":   1,
    "2 زر في كل صف":   2,
    "3 أزرار في كل صف": 3,
}


@dataclass
class ButtonConfig:
    text: str = ""
    price: str = ""
    color_name: str = "🔵 أزرق"
    style: str = "مستدير"
    layout: int = 1


@dataclass
class UserSession:
    buttons: list = field(default_factory=list)
    current: ButtonConfig = field(default_factory=ButtonConfig)
    layout: int = 1


def get_session(context: ContextTypes.DEFAULT_TYPE) -> UserSession:
    if "session" not in context.user_data:
        context.user_data["session"] = UserSession()
    return context.user_data["session"]


# ─── Image generator ────────────────────────────────────────────────────────

def make_button_image(buttons: list[ButtonConfig], per_row: int) -> bytes:
    """Render a list of ButtonConfig objects to a PNG image."""
    BTN_W = 320
    BTN_H = 56
    PAD_X = 20
    PAD_Y = 16
    GAP_X = 12
    GAP_Y = 10
    MARGIN = 28

    cols = min(per_row, len(buttons))
    rows = (len(buttons) + cols - 1) // cols

    canvas_w = MARGIN * 2 + cols * BTN_W + (cols - 1) * GAP_X
    canvas_h = MARGIN * 2 + rows * BTN_H + (rows - 1) * GAP_Y

    img = Image.new("RGBA", (canvas_w, canvas_h), (245, 245, 245, 255))
    draw = ImageDraw.Draw(img)

    try:
        font_bold = ImageFont.truetype("/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf", 17)
        font_reg  = ImageFont.truetype("/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf", 13)
    except Exception:
        font_bold = ImageFont.load_default()
        font_reg  = font_bold

    for idx, btn in enumerate(buttons):
        col = idx % cols
        row = idx // cols

        x1 = MARGIN + col * (BTN_W + GAP_X)
        y1 = MARGIN + row * (BTN_H + GAP_Y)
        x2 = x1 + BTN_W
        y2 = y1 + BTN_H

        bg_color, fg_color = COLORS.get(btn.color_name, ("#2D9CDB", "#FFFFFF"))
        radius = STYLES.get(btn.style, 18)

        _draw_rounded_rect(draw, x1, y1, x2, y2, radius, bg_color)

        # Shadow
        _draw_rounded_rect(draw, x1 + 2, y1 + 3, x2 + 2, y2 + 3, radius, "#00000022")
        _draw_rounded_rect(draw, x1, y1, x2, y2, radius, bg_color)

        label = btn.text
        if btn.price:
            price_text = btn.price
            # Draw price on the right
            pw = draw.textlength(price_text, font=font_reg)
            px = x2 - PAD_X - pw
            py = y1 + (BTN_H - 13) // 2 + 2
            draw.text((px, py), price_text, font=font_reg, fill=fg_color + "CC" if len(fg_color) == 7 else fg_color)

            # Draw label centered in remaining space
            tw = draw.textlength(label, font=font_bold)
            tx = x1 + (BTN_W - PAD_X - pw - GAP_X - tw) // 2 + PAD_X // 2
            ty = y1 + (BTN_H - 17) // 2
            draw.text((tx, ty), label, font=font_bold, fill=fg_color)
        else:
            tw = draw.textlength(label, font=font_bold)
            tx = x1 + (BTN_W - tw) // 2
            ty = y1 + (BTN_H - 17) // 2
            draw.text((tx, ty), label, font=font_bold, fill=fg_color)

    # Convert to RGB for JPEG (or keep PNG)
    out = io.BytesIO()
    img.convert("RGB").save(out, format="PNG", optimize=True)
    out.seek(0)
    return out.read()


def _draw_rounded_rect(draw, x1, y1, x2, y2, r, color):
    r = min(r, (x2 - x1) // 2, (y2 - y1) // 2)
    if r <= 0:
        draw.rectangle([x1, y1, x2, y2], fill=color)
        return
    draw.rectangle([x1 + r, y1, x2 - r, y2], fill=color)
    draw.rectangle([x1, y1 + r, x2, y2 - r], fill=color)
    draw.ellipse([x1, y1, x1 + 2*r, y1 + 2*r], fill=color)
    draw.ellipse([x2 - 2*r, y1, x2, y1 + 2*r], fill=color)
    draw.ellipse([x1, y2 - 2*r, x1 + 2*r, y2], fill=color)
    draw.ellipse([x2 - 2*r, y2 - 2*r, x2, y2], fill=color)


# ─── Keyboard helpers ────────────────────────────────────────────────────────

def main_menu_kb(has_buttons: bool) -> InlineKeyboardMarkup:
    rows = [
        [InlineKeyboardButton("➕ إضافة زر جديد", callback_data="add_button")],
    ]
    if has_buttons:
        rows += [
            [InlineKeyboardButton("🖼 توليد الصورة", callback_data="generate")],
            [InlineKeyboardButton("🗑 مسح الكل", callback_data="clear")],
        ]
    return InlineKeyboardMarkup(rows)


def color_kb() -> InlineKeyboardMarkup:
    keys = list(COLORS.keys())
    rows = []
    for i in range(0, len(keys), 3):
        rows.append([InlineKeyboardButton(k, callback_data=f"color:{k}") for k in keys[i:i+3]])
    rows.append([InlineKeyboardButton("⬅️ رجوع", callback_data="back_to_menu")])
    return InlineKeyboardMarkup(rows)


def style_kb() -> InlineKeyboardMarkup:
    rows = [
        [InlineKeyboardButton(s, callback_data=f"style:{s}") for s in STYLES],
        [InlineKeyboardButton("⬅️ رجوع", callback_data="back_to_menu")],
    ]
    return InlineKeyboardMarkup(rows)


def layout_kb() -> InlineKeyboardMarkup:
    rows = [
        [InlineKeyboardButton(name, callback_data=f"layout:{val}") for name, val in LAYOUTS.items()],
        [InlineKeyboardButton("⬅️ رجوع", callback_data="back_to_menu")],
    ]
    return InlineKeyboardMarkup(rows)


def skip_price_kb() -> InlineKeyboardMarkup:
    return InlineKeyboardMarkup([
        [InlineKeyboardButton("تخطي (بدون سعر)", callback_data="skip_price")],
        [InlineKeyboardButton("⬅️ رجوع", callback_data="back_to_menu")],
    ])


# ─── Handlers ────────────────────────────────────────────────────────────────

async def start(update: Update, context: ContextTypes.DEFAULT_TYPE) -> int:
    context.user_data.clear()
    session = get_session(context)
    await update.message.reply_text(
        "👋 *مرحباً!*\n\nهذا البوت يساعدك على تصميم أزرار وهمية وتصويرها.\n\n"
        "اضغط *إضافة زر جديد* للبدء:",
        parse_mode="Markdown",
        reply_markup=main_menu_kb(bool(session.buttons)),
    )
    return MAIN_MENU


async def main_menu_callback(update: Update, context: ContextTypes.DEFAULT_TYPE) -> int:
    query = update.callback_query
    await query.answer()
    session = get_session(context)

    if query.data == "add_button":
        session.current = ButtonConfig()
        await query.edit_message_text(
            "✏️ *اكتب نص الزر:*\n\nمثال: اشترِ الآن",
            parse_mode="Markdown",
        )
        return ENTER_TEXT

    if query.data == "generate":
        if not session.buttons:
            await query.answer("لا توجد أزرار بعد!", show_alert=True)
            return MAIN_MENU
        await query.edit_message_text("⏳ جاري توليد الصورة...")
        img_bytes = make_button_image(session.buttons, session.layout)
        caption = f"✅ {len(session.buttons)} زر | {session.layout} في كل صف"
        await query.message.reply_photo(
            photo=io.BytesIO(img_bytes),
            caption=caption,
        )
        await query.message.reply_text(
            "ماذا تريد أن تفعل الآن؟",
            reply_markup=main_menu_kb(True),
        )
        return MAIN_MENU

    if query.data == "clear":
        session.buttons.clear()
        await query.edit_message_text(
            "🗑 تم مسح جميع الأزرار.\n\nاضغط *إضافة زر جديد* للبدء:",
            parse_mode="Markdown",
            reply_markup=main_menu_kb(False),
        )
        return MAIN_MENU

    if query.data == "back_to_menu":
        await query.edit_message_text(
            f"📋 لديك {len(session.buttons)} زر حتى الآن.\n\nاختر من القائمة:",
            reply_markup=main_menu_kb(bool(session.buttons)),
        )
        return MAIN_MENU

    return MAIN_MENU


async def enter_text(update: Update, context: ContextTypes.DEFAULT_TYPE) -> int:
    session = get_session(context)
    session.current.text = update.message.text.strip()
    await update.message.reply_text(
        f"💰 *أدخل السعر للزر* `{session.current.text}`:\n\n"
        "مثال: 99 ريال  أو  Free\n"
        "أو اضغط *تخطي* إذا لم يكن هناك سعر.",
        parse_mode="Markdown",
        reply_markup=skip_price_kb(),
    )
    return ENTER_PRICE


async def enter_price(update: Update, context: ContextTypes.DEFAULT_TYPE) -> int:
    session = get_session(context)
    session.current.price = update.message.text.strip()
    await update.message.reply_text(
        "🎨 *اختر لون الزر:*",
        parse_mode="Markdown",
        reply_markup=color_kb(),
    )
    return ENTER_COLOR


async def skip_price_callback(update: Update, context: ContextTypes.DEFAULT_TYPE) -> int:
    query = update.callback_query
    await query.answer()
    session = get_session(context)
    session.current.price = ""
    await query.edit_message_text(
        "🎨 *اختر لون الزر:*",
        parse_mode="Markdown",
        reply_markup=color_kb(),
    )
    return ENTER_COLOR


async def enter_color(update: Update, context: ContextTypes.DEFAULT_TYPE) -> int:
    query = update.callback_query
    await query.answer()
    session = get_session(context)
    color_name = query.data.split(":", 1)[1]
    session.current.color_name = color_name
    await query.edit_message_text(
        "📐 *اختر شكل الزر:*",
        parse_mode="Markdown",
        reply_markup=style_kb(),
    )
    return CHOOSE_STYLE


async def choose_style(update: Update, context: ContextTypes.DEFAULT_TYPE) -> int:
    query = update.callback_query
    await query.answer()
    session = get_session(context)
    style = query.data.split(":", 1)[1]
    session.current.style = style

    # Add button to list
    session.buttons.append(ButtonConfig(
        text=session.current.text,
        price=session.current.price,
        color_name=session.current.color_name,
        style=session.current.style,
    ))

    summary = "\n".join(
        f"  {i+1}. {b.text}" + (f" ({b.price})" if b.price else "")
        for i, b in enumerate(session.buttons)
    )

    await query.edit_message_text(
        f"✅ *تمت إضافة الزر!*\n\n"
        f"*الأزرار الحالية:*\n{summary}\n\n"
        f"اختر التخطيط أو أضف المزيد:",
        parse_mode="Markdown",
        reply_markup=InlineKeyboardMarkup([
            [InlineKeyboardButton("➕ إضافة زر آخر", callback_data="add_button")],
            [InlineKeyboardButton("🔲 تغيير التخطيط", callback_data="change_layout")],
            [InlineKeyboardButton("🖼 توليد الصورة", callback_data="generate")],
            [InlineKeyboardButton("🗑 مسح الكل", callback_data="clear")],
        ]),
    )
    return MAIN_MENU


async def change_layout_callback(update: Update, context: ContextTypes.DEFAULT_TYPE) -> int:
    query = update.callback_query
    await query.answer()
    await query.edit_message_text(
        "🔲 *اختر عدد الأزرار في كل صف:*",
        parse_mode="Markdown",
        reply_markup=layout_kb(),
    )
    return CHOOSE_LAYOUT


async def choose_layout(update: Update, context: ContextTypes.DEFAULT_TYPE) -> int:
    query = update.callback_query
    await query.answer()
    session = get_session(context)
    session.layout = int(query.data.split(":", 1)[1])
    await query.edit_message_text(
        f"✅ تم تغيير التخطيط إلى {session.layout} في كل صف.\n\nاضغط *توليد الصورة* لرؤية النتيجة:",
        parse_mode="Markdown",
        reply_markup=main_menu_kb(True),
    )
    return MAIN_MENU


async def cancel(update: Update, context: ContextTypes.DEFAULT_TYPE) -> int:
    context.user_data.clear()
    await update.message.reply_text(
        "تم الإلغاء. اكتب /start للبدء من جديد."
    )
    return ConversationHandler.END


def main():
    if BOT_TOKEN == "YOUR_BOT_TOKEN_HERE":
        print("ERROR: Set BOT_TOKEN environment variable first.")
        print("  export BOT_TOKEN='your-telegram-bot-token'")
        return

    app = Application.builder().token(BOT_TOKEN).build()

    conv = ConversationHandler(
        entry_points=[CommandHandler("start", start)],
        states={
            MAIN_MENU: [
                CallbackQueryHandler(change_layout_callback, pattern="^change_layout$"),
                CallbackQueryHandler(main_menu_callback),
            ],
            ENTER_TEXT: [
                MessageHandler(filters.TEXT & ~filters.COMMAND, enter_text),
                CallbackQueryHandler(main_menu_callback, pattern="^back_to_menu$"),
            ],
            ENTER_PRICE: [
                MessageHandler(filters.TEXT & ~filters.COMMAND, enter_price),
                CallbackQueryHandler(skip_price_callback, pattern="^skip_price$"),
                CallbackQueryHandler(main_menu_callback, pattern="^back_to_menu$"),
            ],
            ENTER_COLOR: [
                CallbackQueryHandler(enter_color, pattern="^color:"),
                CallbackQueryHandler(main_menu_callback, pattern="^back_to_menu$"),
            ],
            CHOOSE_STYLE: [
                CallbackQueryHandler(choose_style, pattern="^style:"),
                CallbackQueryHandler(main_menu_callback, pattern="^back_to_menu$"),
            ],
            CHOOSE_LAYOUT: [
                CallbackQueryHandler(choose_layout, pattern="^layout:"),
                CallbackQueryHandler(main_menu_callback, pattern="^back_to_menu$"),
            ],
        },
        fallbacks=[CommandHandler("cancel", cancel)],
    )

    app.add_handler(conv)

    print("✅ Bot is running. Press Ctrl+C to stop.")
    app.run_polling(drop_pending_updates=True)


if __name__ == "__main__":
    main()
