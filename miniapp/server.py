"""
Backend API لتطبيق Telegram Mini App (نظام نقاط بالنقر على غرار Hamster Kombat).
يتحقق من initData القادم من تيليجرام عبر HMAC-SHA256 باستخدام توكن البوت،
ويخزّن كل البيانات (النقاط، الطاقة، الدعوات، المهام) في SQLite على الخادم
حتى لا يمكن للمستخدم التحايل عبر تعديل الكود في المتصفح.
"""
import hashlib
import hmac
import json
import os
import sqlite3
import time
from urllib.parse import parse_qsl

from flask import Flask, jsonify, request, send_from_directory

BOT_TOKEN = os.environ.get("MINIAPP_BOT_TOKEN", "")
DB_FILE = os.path.join(os.path.dirname(__file__), "miniapp.db")
STATIC_DIR = os.path.join(os.path.dirname(__file__), "static")

MAX_ENERGY = 1000
ENERGY_REGEN_SECONDS = 2          # تسترجع نقطة طاقة واحدة كل X ثانية
COINS_PER_TAP = 1
REFERRAL_BONUS = 2500
DAILY_BONUS = 500
LEVELS = [
    (0, "🥚 مبتدئ"),
    (5000, "🐣 نشيط"),
    (25000, "🐊 تمساح صغير"),
    (100000, "🐊 تمساح محترف"),
    (500000, "👑 ملك التمساح"),
    (2000000, "💎 أسطورة"),
]

app = Flask(__name__, static_folder=STATIC_DIR)


def db():
    conn = sqlite3.connect(DB_FILE)
    conn.row_factory = sqlite3.Row
    return conn


def init_db():
    conn = db()
    conn.execute("""
        CREATE TABLE IF NOT EXISTS users (
            user_id INTEGER PRIMARY KEY,
            username TEXT,
            first_name TEXT,
            coins INTEGER DEFAULT 0,
            energy INTEGER DEFAULT 1000,
            last_energy_ts REAL DEFAULT 0,
            last_daily_ts REAL DEFAULT 0,
            referred_by INTEGER,
            joined_ts REAL DEFAULT 0
        )
    """)
    conn.execute("""
        CREATE TABLE IF NOT EXISTS tasks (
            task_id TEXT PRIMARY KEY,
            title TEXT,
            reward INTEGER,
            link TEXT
        )
    """)
    conn.execute("""
        CREATE TABLE IF NOT EXISTS user_tasks (
            user_id INTEGER,
            task_id TEXT,
            completed_ts REAL,
            PRIMARY KEY (user_id, task_id)
        )
    """)
    conn.execute("""
        CREATE TABLE IF NOT EXISTS withdrawals (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER,
            amount INTEGER,
            status TEXT DEFAULT 'pending',
            created_ts REAL
        )
    """)
    conn.commit()
    conn.execute("""
        INSERT OR IGNORE INTO tasks (task_id, title, reward, link) VALUES
        ('join_channel', '📢 اشترك في قناتنا', 10000, ''),
        ('invite_3', '👥 ادعُ 3 أصدقاء', 15000, '')
    """)
    conn.commit()
    conn.close()


def verify_init_data(init_data: str) -> dict | None:
    """يتحقق من توقيع initData حسب توثيق تيليجرام الرسمي لمنع التلاعب."""
    if not init_data or not BOT_TOKEN:
        return None
    pairs = dict(parse_qsl(init_data, strict_parsing=False))
    received_hash = pairs.pop("hash", None)
    if not received_hash:
        return None
    data_check_string = "\n".join(f"{k}={v}" for k, v in sorted(pairs.items()))
    secret_key = hmac.new(b"WebAppData", BOT_TOKEN.encode(), hashlib.sha256).digest()
    computed_hash = hmac.new(secret_key, data_check_string.encode(), hashlib.sha256).hexdigest()
    if not hmac.compare_digest(computed_hash, received_hash):
        return None
    user_raw = pairs.get("user")
    if not user_raw:
        return None
    try:
        return json.loads(user_raw)
    except (json.JSONDecodeError, TypeError):
        return None


def get_or_create_user(user_id: int, username: str, first_name: str, referred_by: int = None):
    conn = db()
    row = conn.execute("SELECT * FROM users WHERE user_id=?", (user_id,)).fetchone()
    if row is None:
        now = time.time()
        conn.execute(
            "INSERT INTO users (user_id, username, first_name, coins, energy, last_energy_ts, referred_by, joined_ts) "
            "VALUES (?,?,?,0,?,?,?,?)",
            (user_id, username, first_name, MAX_ENERGY, now, referred_by, now),
        )
        if referred_by and referred_by != user_id:
            conn.execute("UPDATE users SET coins = coins + ? WHERE user_id=?", (REFERRAL_BONUS, referred_by))
        conn.commit()
        row = conn.execute("SELECT * FROM users WHERE user_id=?", (user_id,)).fetchone()
    conn.close()
    return row


def regen_energy(row) -> int:
    elapsed = time.time() - row["last_energy_ts"]
    regenerated = int(elapsed // ENERGY_REGEN_SECONDS)
    return min(MAX_ENERGY, row["energy"] + regenerated)


def level_for(coins: int) -> str:
    name = LEVELS[0][1]
    for threshold, label in LEVELS:
        if coins >= threshold:
            name = label
    return name


def auth_user():
    init_data = request.headers.get("X-Telegram-Init-Data", "")
    user = verify_init_data(init_data)
    if not user:
        return None
    return user


@app.route("/")
def index():
    return send_from_directory(STATIC_DIR, "index.html")


@app.route("/<path:path>")
def static_files(path):
    return send_from_directory(STATIC_DIR, path)


@app.route("/api/state", methods=["GET"])
def state():
    user = auth_user()
    if not user:
        return jsonify({"error": "unauthorized"}), 401
    row = get_or_create_user(user["id"], user.get("username", ""), user.get("first_name", ""))
    energy = regen_energy(row)
    conn = db()
    conn.execute("UPDATE users SET energy=?, last_energy_ts=? WHERE user_id=?", (energy, time.time(), user["id"]))
    conn.commit()
    referrals = conn.execute("SELECT COUNT(*) c FROM users WHERE referred_by=?", (user["id"],)).fetchone()["c"]
    conn.close()
    return jsonify({
        "coins": row["coins"],
        "energy": energy,
        "max_energy": MAX_ENERGY,
        "level": level_for(row["coins"]),
        "referrals": referrals,
    })


@app.route("/api/tap", methods=["POST"])
def tap():
    user = auth_user()
    if not user:
        return jsonify({"error": "unauthorized"}), 401
    taps = max(1, min(int((request.json or {}).get("taps", 1)), 50))
    conn = db()
    row = conn.execute("SELECT * FROM users WHERE user_id=?", (user["id"],)).fetchone()
    if row is None:
        conn.close()
        return jsonify({"error": "not_found"}), 404
    energy = regen_energy(row)
    actual_taps = min(taps, energy)
    new_coins = row["coins"] + actual_taps * COINS_PER_TAP
    new_energy = energy - actual_taps
    conn.execute(
        "UPDATE users SET coins=?, energy=?, last_energy_ts=? WHERE user_id=?",
        (new_coins, new_energy, time.time(), user["id"]),
    )
    conn.commit()
    conn.close()
    return jsonify({"coins": new_coins, "energy": new_energy, "level": level_for(new_coins)})


@app.route("/api/daily", methods=["POST"])
def daily():
    user = auth_user()
    if not user:
        return jsonify({"error": "unauthorized"}), 401
    conn = db()
    row = conn.execute("SELECT * FROM users WHERE user_id=?", (user["id"],)).fetchone()
    if row is None:
        conn.close()
        return jsonify({"error": "not_found"}), 404
    if time.time() - row["last_daily_ts"] < 86400:
        conn.close()
        return jsonify({"error": "already_claimed"}), 400
    new_coins = row["coins"] + DAILY_BONUS
    conn.execute("UPDATE users SET coins=?, last_daily_ts=? WHERE user_id=?", (new_coins, time.time(), user["id"]))
    conn.commit()
    conn.close()
    return jsonify({"coins": new_coins, "bonus": DAILY_BONUS})


@app.route("/api/tasks", methods=["GET"])
def tasks():
    user = auth_user()
    if not user:
        return jsonify({"error": "unauthorized"}), 401
    conn = db()
    rows = conn.execute("SELECT * FROM tasks").fetchall()
    done = {r["task_id"] for r in conn.execute("SELECT task_id FROM user_tasks WHERE user_id=?", (user["id"],))}
    conn.close()
    return jsonify([
        {"task_id": r["task_id"], "title": r["title"], "reward": r["reward"], "link": r["link"], "completed": r["task_id"] in done}
        for r in rows
    ])


@app.route("/api/tasks/<task_id>/claim", methods=["POST"])
def claim_task(task_id):
    user = auth_user()
    if not user:
        return jsonify({"error": "unauthorized"}), 401
    conn = db()
    task = conn.execute("SELECT * FROM tasks WHERE task_id=?", (task_id,)).fetchone()
    if task is None:
        conn.close()
        return jsonify({"error": "not_found"}), 404
    already = conn.execute("SELECT 1 FROM user_tasks WHERE user_id=? AND task_id=?", (user["id"], task_id)).fetchone()
    if already:
        conn.close()
        return jsonify({"error": "already_claimed"}), 400
    conn.execute("INSERT INTO user_tasks (user_id, task_id, completed_ts) VALUES (?,?,?)", (user["id"], task_id, time.time()))
    conn.execute("UPDATE users SET coins = coins + ? WHERE user_id=?", (task["reward"], user["id"]))
    conn.commit()
    new_coins = conn.execute("SELECT coins FROM users WHERE user_id=?", (user["id"],)).fetchone()["coins"]
    conn.close()
    return jsonify({"coins": new_coins, "reward": task["reward"]})


@app.route("/api/leaderboard", methods=["GET"])
def leaderboard():
    user = auth_user()
    if not user:
        return jsonify({"error": "unauthorized"}), 401
    conn = db()
    rows = conn.execute("SELECT user_id, username, first_name, coins FROM users ORDER BY coins DESC LIMIT 100").fetchall()
    conn.close()
    return jsonify([
        {"user_id": r["user_id"], "name": r["username"] or r["first_name"] or "مستخدم", "coins": r["coins"]}
        for r in rows
    ])


@app.route("/api/referral", methods=["GET"])
def referral():
    user = auth_user()
    if not user:
        return jsonify({"error": "unauthorized"}), 401
    bot_username = os.environ.get("MINIAPP_BOT_USERNAME", "your_bot")
    conn = db()
    rows = conn.execute(
        "SELECT username, first_name, coins FROM users WHERE referred_by=? ORDER BY joined_ts DESC", (user["id"],)
    ).fetchall()
    conn.close()
    return jsonify({
        "link": f"https://t.me/{bot_username}?start=ref_{user['id']}",
        "count": len(rows),
        "bonus_per_referral": REFERRAL_BONUS,
        "friends": [{"name": r["username"] or r["first_name"] or "مستخدم", "coins": r["coins"]} for r in rows],
    })


@app.route("/api/withdraw", methods=["POST"])
def withdraw():
    user = auth_user()
    if not user:
        return jsonify({"error": "unauthorized"}), 401
    amount = int((request.json or {}).get("amount", 0))
    conn = db()
    row = conn.execute("SELECT coins FROM users WHERE user_id=?", (user["id"],)).fetchone()
    if row is None or amount <= 0 or amount > row["coins"]:
        conn.close()
        return jsonify({"error": "invalid_amount"}), 400
    conn.execute("UPDATE users SET coins = coins - ? WHERE user_id=?", (amount, user["id"]))
    conn.execute(
        "INSERT INTO withdrawals (user_id, amount, status, created_ts) VALUES (?,?,?,?)",
        (user["id"], amount, "pending", time.time()),
    )
    conn.commit()
    conn.close()
    return jsonify({"ok": True})


if __name__ == "__main__":
    init_db()
    app.run(host="0.0.0.0", port=int(os.environ.get("PORT", 8080)))
