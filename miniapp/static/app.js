const tg = window.Telegram?.WebApp;
if (tg) { tg.ready(); tg.expand(); }

const initData = tg?.initData || "";

function api(path, options = {}) {
  return fetch(path, {
    ...options,
    headers: {
      "Content-Type": "application/json",
      "X-Telegram-Init-Data": initData,
      ...(options.headers || {}),
    },
  }).then((r) => r.json());
}

const coinsValue = document.getElementById("coinsValue");
const levelBadge = document.getElementById("levelBadge");
const energyFill = document.getElementById("energyFill");
const energyValue = document.getElementById("energyValue");
const croc = document.getElementById("croc");
const tapZone = document.getElementById("tapZone");

let state = { coins: 0, energy: 1000, max_energy: 1000, level: "🥚 مبتدئ" };
let pendingTaps = 0;
let flushTimer = null;

function renderState() {
  coinsValue.textContent = state.coins.toLocaleString();
  levelBadge.textContent = state.level;
  energyValue.textContent = state.energy;
  energyFill.style.width = `${(state.energy / state.max_energy) * 100}%`;
  document.getElementById("walletCoins").textContent = state.coins.toLocaleString();
  document.getElementById("walletLevel").textContent = state.level;
}

async function loadState() {
  const data = await api("/api/state");
  if (!data.error) {
    state = { ...state, ...data };
    renderState();
  }
}

function spawnFloatingCoin(x, y) {
  const el = document.createElement("div");
  el.className = "float-coin";
  el.textContent = "+1";
  el.style.left = `${x}px`;
  el.style.top = `${y}px`;
  tapZone.appendChild(el);
  setTimeout(() => el.remove(), 800);
}

function flushTaps() {
  if (pendingTaps === 0) return;
  const taps = pendingTaps;
  pendingTaps = 0;
  api("/api/tap", { method: "POST", body: JSON.stringify({ taps }) }).then((data) => {
    if (!data.error) {
      state.coins = data.coins;
      state.energy = data.energy;
      state.level = data.level;
      renderState();
    }
  });
}

tapZone.addEventListener("click", (e) => {
  if (state.energy <= pendingTaps) return;
  pendingTaps += 1;
  state.coins += 1;
  state.energy -= 1;
  renderState();
  croc.classList.add("tapped");
  setTimeout(() => croc.classList.remove("tapped"), 80);
  const rect = tapZone.getBoundingClientRect();
  spawnFloatingCoin(e.clientX - rect.left, e.clientY - rect.top);
  tg?.HapticFeedback?.impactOccurred?.("light");
  clearTimeout(flushTimer);
  flushTimer = setTimeout(flushTaps, 400);
});

document.getElementById("dailyBtn").addEventListener("click", async () => {
  const data = await api("/api/daily", { method: "POST" });
  if (data.error === "already_claimed") {
    tg?.showAlert?.("لقد استلمت مكافأة اليوم بالفعل، عُد غداً!");
  } else if (!data.error) {
    state.coins = data.coins;
    renderState();
    tg?.showAlert?.(`🎁 حصلت على ${data.bonus} عملة!`);
  }
});

async function loadTasks() {
  const list = document.getElementById("tasksList");
  const tasks = await api("/api/tasks");
  if (!Array.isArray(tasks)) return;
  list.innerHTML = "";
  tasks.forEach((t) => {
    const item = document.createElement("div");
    item.className = "list-item";
    item.innerHTML = `
      <div>${t.title}<br><small>🪙 ${t.reward.toLocaleString()}</small></div>
      <button class="task-claim-btn" ${t.completed ? "disabled" : ""}>${t.completed ? "تم ✅" : "تحصيل"}</button>
    `;
    item.querySelector("button").addEventListener("click", async () => {
      const res = await api(`/api/tasks/${t.task_id}/claim`, { method: "POST" });
      if (!res.error) {
        state.coins = res.coins;
        renderState();
        loadTasks();
      }
    });
    list.appendChild(item);
  });
}

async function loadFriends() {
  const data = await api("/api/referral");
  if (data.error) return;
  document.getElementById("refLinkInput").value = data.link;
  document.getElementById("refCount").textContent = data.count;
  document.getElementById("refBonus").textContent = data.bonus_per_referral.toLocaleString();
  const list = document.getElementById("friendsList");
  list.innerHTML = "";
  data.friends.forEach((f) => {
    const item = document.createElement("div");
    item.className = "list-item";
    item.innerHTML = `<div>${f.name}</div><div>🪙 ${f.coins.toLocaleString()}</div>`;
    list.appendChild(item);
  });
}

document.getElementById("copyRefBtn").addEventListener("click", () => {
  const input = document.getElementById("refLinkInput");
  navigator.clipboard?.writeText(input.value);
  tg?.showAlert?.("تم نسخ الرابط!");
});

document.getElementById("shareRefBtn").addEventListener("click", () => {
  const link = document.getElementById("refLinkInput").value;
  if (tg?.openTelegramLink) {
    tg.openTelegramLink(`https://t.me/share/url?url=${encodeURIComponent(link)}`);
  }
});

async function loadLeaderboard() {
  const data = await api("/api/leaderboard");
  const list = document.getElementById("leaderboardList");
  if (!Array.isArray(data)) return;
  list.innerHTML = "";
  data.forEach((u, i) => {
    const item = document.createElement("div");
    item.className = "list-item";
    item.innerHTML = `<div>#${i + 1} ${u.name}</div><div>🪙 ${u.coins.toLocaleString()}</div>`;
    list.appendChild(item);
  });
}

document.getElementById("withdrawBtn").addEventListener("click", async () => {
  const amount = parseInt(document.getElementById("withdrawAmount").value, 10);
  if (!amount || amount <= 0) return;
  const res = await api("/api/withdraw", { method: "POST", body: JSON.stringify({ amount }) });
  if (res.error) {
    tg?.showAlert?.("مبلغ غير صالح أو أكبر من رصيدك.");
  } else {
    tg?.showAlert?.("✅ تم إرسال طلب السحب، بانتظار مراجعة الإدارة.");
    loadState();
  }
});

document.querySelectorAll(".nav-btn").forEach((btn) => {
  btn.addEventListener("click", () => {
    document.querySelectorAll(".nav-btn").forEach((b) => b.classList.remove("active"));
    document.querySelectorAll(".screen").forEach((s) => s.classList.remove("active"));
    btn.classList.add("active");
    const screen = btn.dataset.screen;
    document.getElementById(`screen-${screen}`).classList.add("active");
    if (screen === "tasks") loadTasks();
    if (screen === "friends") loadFriends();
    if (screen === "leaderboard") loadLeaderboard();
  });
});

setInterval(loadState, 5000);
loadState();
