"""
نظام الاشتراكات المدفوعة — Telegram Stars + USDT Binance + P2P Transfer.
"""
import os
import sqlite3
import time
from enum import Enum


class PaymentMethod(str, Enum):
    STARS = "stars"           # ⭐ نجوم تيليجرام
    USDT_QR = "usdt_qr"       # 💵 عبر QR من Binance
    BINANCE_P2P = "binance_p2p"  # 🏦 تحويل Binance


DB_FILE = os.path.join(os.path.dirname(__file__), "miniapp.db")

# أسعار الاشتراك (يمكن تغييرها)
SUBSCRIPTION_PRICES = {
    PaymentMethod.STARS: 999,        # 999 نجمة
    PaymentMethod.USDT_QR: 29.99,    # 29.99 USDT
    PaymentMethod.BINANCE_P2P: 29.99,  # 29.99 USDT
}


def db():
    conn = sqlite3.connect(DB_FILE)
    conn.row_factory = sqlite3.Row
    return conn


def init_subscription_schema():
    """إضافة جداول الاشتراكات إلى قاعدة البيانات الموجودة."""
    conn = db()
    conn.execute("""
        CREATE TABLE IF NOT EXISTS subscriptions (
            subscription_id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER UNIQUE NOT NULL,
            status TEXT DEFAULT 'active',
            created_ts REAL,
            expires_ts REAL,
            is_lifetime INTEGER DEFAULT 1,
            FOREIGN KEY (user_id) REFERENCES users(user_id)
        )
    """)
    conn.execute("""
        CREATE TABLE IF NOT EXISTS payment_orders (
            order_id TEXT PRIMARY KEY,
            user_id INTEGER NOT NULL,
            method TEXT NOT NULL,
            amount REAL,
            status TEXT DEFAULT 'pending',
            created_ts REAL,
            completed_ts REAL,
            metadata TEXT,
            FOREIGN KEY (user_id) REFERENCES users(user_id)
        )
    """)
    conn.commit()
    conn.close()


def create_subscription(user_id: int) -> dict:
    """ينشئ/يفعّل اشتراك دائم للمستخدم."""
    conn = db()
    now = time.time()
    existing = conn.execute("SELECT * FROM subscriptions WHERE user_id=?", (user_id,)).fetchone()
    if existing:
        conn.execute(
            "UPDATE subscriptions SET status='active', is_lifetime=1 WHERE user_id=?",
            (user_id,),
        )
    else:
        conn.execute(
            "INSERT INTO subscriptions (user_id, status, created_ts, is_lifetime) VALUES (?,?,?,?)",
            (user_id, "active", now, 1),
        )
    conn.commit()
    row = conn.execute("SELECT * FROM subscriptions WHERE user_id=?", (user_id,)).fetchone()
    conn.close()
    return dict(row) if row else {}


def has_subscription(user_id: int) -> bool:
    """يتحقق ما إذا كان للمستخدم اشتراك نشط."""
    conn = db()
    row = conn.execute(
        "SELECT * FROM subscriptions WHERE user_id=? AND status='active'",
        (user_id,),
    ).fetchone()
    conn.close()
    return row is not None


def create_payment_order(user_id: int, method: str, amount: float) -> str:
    """ينشئ طلب دفع جديد ويرجع معرّف الطلب."""
    conn = db()
    order_id = f"ord_{user_id}_{int(time.time() * 1000)}"
    conn.execute(
        "INSERT INTO payment_orders (order_id, user_id, method, amount, created_ts) VALUES (?,?,?,?,?)",
        (order_id, user_id, method, amount, time.time()),
    )
    conn.commit()
    conn.close()
    return order_id


def get_payment_order(order_id: str) -> dict | None:
    """يجلب معلومات طلب الدفع."""
    conn = db()
    row = conn.execute("SELECT * FROM payment_orders WHERE order_id=?", (order_id,)).fetchone()
    conn.close()
    return dict(row) if row else None


def confirm_payment(order_id: str) -> dict | None:
    """يؤكد الدفع ويفعّل الاشتراك."""
    conn = db()
    order = conn.execute("SELECT * FROM payment_orders WHERE order_id=?", (order_id,)).fetchone()
    if not order:
        conn.close()
        return None
    conn.execute("UPDATE payment_orders SET status='completed', completed_ts=? WHERE order_id=?", (time.time(), order_id))
    subscription = create_subscription(order["user_id"])
    conn.close()
    return subscription
