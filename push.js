/* AfrizoN CRM — إشعارات الطلبيات (Web Push) لكل بنت */
(function () {
  'use strict';
  var TOKEN = '8c907fc0f4ffe0b9775a6b7c3c0fc7700e5724c0d78343df';   // نفس التوكن ديال CRM
  var NAMES = ['Meryam', 'AYA', 'imane', 'safa'];                  // للأدمين فقط (ما عندوش حساب بالبنت)
  var KEY = 'pv_push_agent';          // البنت لي مرتبطة بهاد الهاتف
  var EP_KEY = 'pv_push_endpoint';    // آخر عنوان إشعارات تسجل فالسيرفر
  var HIDE_KEY = 'pv_push_hide_until';
  var SESSION_KEY = 'afrizon_session_v1';
  var USERS_KEY = 'afrizon_users_v1';

  var supported = ('serviceWorker' in navigator) && ('PushManager' in window) && ('Notification' in window);
  var isIOS = /iPad|iPhone|iPod/.test(navigator.userAgent);
  var isStandalone = window.matchMedia('(display-mode: standalone)').matches || navigator.standalone === true;
  var IOS_MSG = 'على آيفون: الإشعارات كتخدم غير من الشاشة الرئيسية.\n\n1) فـSafari كليكي على زر Partager (⬆️)\n2) ختار Sur l\'écran d\'accueil (زيد للشاشة الرئيسية)\n3) حل CRM من الأيقونة الجديدة';
  var reg = null;
  if (supported) navigator.serviceWorker.register('sw.js').then(function (r) { reg = r; }).catch(function () {});

  /* سمية البنت لي داخلة بحسابها. الأدمين ولا من بلا حساب = '' */
  function myAgent() {
    try {
      var sid = localStorage.getItem(SESSION_KEY);
      var users = JSON.parse(localStorage.getItem(USERS_KEY) || '[]');
      var me = users.find(function (u) { return String(u.id) === String(sid) && !u._del; });
      return (me && me.agent) ? String(me.agent) : '';
    } catch (e) { return ''; }
  }
  /* الزر كيبان غير لي دخل CRM بحساب (البنت ولا الأدمين) */
  function loggedIn() {
    try {
      var sid = localStorage.getItem(SESSION_KEY);
      if (!sid) return false;
      var users = JSON.parse(localStorage.getItem(USERS_KEY) || '[]');
      return users.some(function (u) { return String(u.id) === String(sid) && !u._del; });
    } catch (e) { return false; }
  }
  function isSubscribedHere(agent) {
    return Notification.permission === 'granted' && localStorage.getItem(KEY) === agent;
  }
  function b64uToBytes(s) {
    s = s.replace(/-/g, '+').replace(/_/g, '/');
    while (s.length % 4) s += '=';
    var raw = atob(s), out = new Uint8Array(raw.length);
    for (var i = 0; i < raw.length; i++) out[i] = raw.charCodeAt(i);
    return out;
  }
  function toast(msg) {
    var t = document.createElement('div');
    t.textContent = msg;
    t.style.cssText = 'position:fixed;bottom:70px;left:12px;z-index:100001;background:#0f172a;color:#fff;padding:10px 14px;border-radius:10px;font:14px system-ui;max-width:80vw;box-shadow:0 4px 14px rgba(0,0,0,.25)';
    document.body.appendChild(t);
    setTimeout(function () { t.remove(); }, 4000);
  }
  function api(body) {
    return fetch('api.php', {
      method: 'POST', cache: 'no-store',
      headers: { 'Content-Type': 'application/json', 'X-Sync-Token': TOKEN },
      body: JSON.stringify(body)
    }).then(function (r) { return r.json(); });
  }

  /* كيسجل الهاتف عند السيرفر باسم البنت (الإذن خاص يكون مسموح) */
  async function subscribe(agent) {
    var r = reg || await navigator.serviceWorker.ready;
    var kj = await (await fetch('api.php?pushkey=1&token=' + TOKEN, { cache: 'no-store' })).json();
    if (!kj.ok) throw new Error('السيرفر ما قدرش يجهز الإشعارات.');
    var sub = await r.pushManager.getSubscription();
    if (!sub) sub = await r.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: b64uToBytes(kj.key) });
    var j = await api({ action: 'push_subscribe', agent: agent, sub: sub.toJSON() });
    if (!j.ok) throw new Error('ما تسجلاتش الهاتف (' + (j.err || '?') + ')');
    localStorage.setItem(KEY, agent);
    localStorage.setItem(EP_KEY, sub.endpoint);
    return agent;
  }
  /* الضغطة الوحيدة: كتسول الإذن (Allow) وكتسجل الهاتف */
  async function enable(agent) {
    if (isIOS && !isStandalone) throw new Error(IOS_MSG);
    var perm = await Notification.requestPermission();
    if (perm !== 'granted') throw new Error('خاص تسماح بالإشعارات.');
    return subscribe(agent);
  }
  /* بلا ضغط: إلا الإذن مسموح وكاين حساب، كيتسجل وحدو (وكيتصحح إلا تبدل الحساب) */
  var syncing = false;
  async function autoSync() {
    if (!supported || syncing || Notification.permission !== 'granted') return;
    if (isIOS && !isStandalone) return;
    var a = myAgent();
    if (!a) return;
    syncing = true;
    try {
      var r = reg || await navigator.serviceWorker.ready;
      var sub = await r.pushManager.getSubscription();
      if (sub && localStorage.getItem(KEY) === a && localStorage.getItem(EP_KEY) === sub.endpoint) return;
      await subscribe(a);
    } catch (e) { /* نعاودو مرة أخرى */ }
    finally { syncing = false; }
  }

  /* بانر وحدة: كيبان للبنت غير ملي ما تكونش فعّلات الإشعارات */
  function banner() {
    if (!supported || document.getElementById('pv-push-banner')) return;
    var a = myAgent();
    if (!a || Notification.permission !== 'default') return;
    if (Number(localStorage.getItem(HIDE_KEY) || 0) > Date.now()) return;
    var box = document.createElement('div');
    box.id = 'pv-push-banner';
    box.style.cssText = 'position:fixed;top:10px;left:50%;transform:translateX(-50%);z-index:100002;background:#0f766e;color:#fff;padding:12px 14px;border-radius:14px;font:14px system-ui;direction:rtl;width:min(92vw,380px);box-shadow:0 8px 24px rgba(0,0,0,.25);display:flex;gap:10px;align-items:center;justify-content:space-between';
    var txt = document.createElement('div');
    txt.textContent = isIOS && !isStandalone
      ? '🔔 باش توصلك الطلبيات ديالك، زيد CRM للشاشة الرئيسية (Partager ← Sur l\'écran d\'accueil)'
      : '🔔 فعّل الإشعارات باش توصلك الطلبيات ديالك (مرة وحدة)';
    box.appendChild(txt);
    var right = document.createElement('div');
    right.style.cssText = 'display:flex;gap:6px;flex-shrink:0';
    if (!(isIOS && !isStandalone)) {
      var ok = document.createElement('button');
      ok.textContent = 'تفعيل';
      ok.style.cssText = 'padding:7px 12px;border-radius:10px;border:none;background:#fff;color:#0f766e;font-weight:700;cursor:pointer';
      ok.onclick = function () {
        ok.disabled = true; ok.textContent = '...';
        enable(a).then(function () {
          toast('✅ الإشعارات مفعّلة. غادي توصلك الطلبيات ديالك.');
          box.remove();
        }).catch(function (e) {
          toast('❌ ' + e.message);
          ok.disabled = false; ok.textContent = 'تفعيل';
        });
      };
      right.appendChild(ok);
    }
    var x = document.createElement('button');
    x.textContent = '✕';
    x.style.cssText = 'padding:7px 10px;border-radius:10px;border:none;background:rgba(255,255,255,.2);color:#fff;cursor:pointer';
    x.onclick = function () {
      localStorage.setItem(HIDE_KEY, String(Date.now() + 12 * 3600 * 1000));
      box.remove();
    };
    right.appendChild(x);
    box.appendChild(right);
    document.body.appendChild(box);
  }

  function panel() {
    if (!supported) { alert(isIOS ? IOS_MSG : 'المتصفح ديالك ما كيدعمش الإشعارات. جرب Chrome.'); return; }
    var old = document.getElementById('pv-push-panel');
    if (old) { old.remove(); return; }
    var me = myAgent();
    var sid = localStorage.getItem(SESSION_KEY);
    var box = document.createElement('div');
    box.id = 'pv-push-panel';
    box.style.cssText = 'position:fixed;bottom:70px;left:12px;z-index:100000;background:#fff;border:1px solid #cbd5e1;border-radius:14px;padding:14px;box-shadow:0 8px 24px rgba(0,0,0,.2);font:14px system-ui;direction:rtl;width:240px';
    var h = document.createElement('div');
    h.style.cssText = 'font-weight:700;margin-bottom:8px';
    h.textContent = '🔔 الإشعارات';
    box.appendChild(h);

    if (!me && !sid) {
      var nl = document.createElement('div');
      nl.style.cssText = 'font-size:13px;color:#334155;line-height:1.6';
      nl.textContent = 'دخل لـCRM بحسابك أولاً، ومن بعد كليكي على 🔔 مرة أخرى.';
      box.appendChild(nl);
    } else if (me) {
      var st = document.createElement('div');
      st.style.cssText = 'margin:6px 0;color:#0f766e;font-weight:600';
      st.textContent = isSubscribedHere(me) ? '✅ مفعّلة' : '⚠️ ماشي مفعّلة بعد';
      box.appendChild(st);
      if (!isSubscribedHere(me)) {
        var b = document.createElement('button');
        b.textContent = me;
        b.style.cssText = 'display:block;width:100%;margin:6px 0;padding:10px;border-radius:10px;border:1px solid #0f766e;background:#f0fdfa;color:#0f766e;font-weight:600;cursor:pointer';
        b.onclick = function () {
          b.disabled = true; b.textContent = '...';
          enable(me).then(function () { toast('✅ الإشعارات مفعّلة'); box.remove(); })
            .catch(function (e) { toast('❌ ' + e.message); b.disabled = false; b.textContent = me; });
        };
        box.appendChild(b);
      }
    } else {
      var t2 = document.createElement('div');
      t2.style.cssText = 'font-size:12px;color:#64748b;margin-bottom:6px';
      t2.textContent = 'شكون نتي؟ (للأدمين)';
      box.appendChild(t2);
      NAMES.forEach(function (n) {
        var nb = document.createElement('button');
        nb.textContent = n;
        nb.style.cssText = 'display:block;width:100%;margin:6px 0;padding:10px;border-radius:10px;border:1px solid #0f766e;background:#f0fdfa;color:#0f766e;font-weight:600;cursor:pointer';
        nb.onclick = function () {
          nb.disabled = true;
          enable(n).then(function () { toast('✅ مفعّلة لـ ' + n); box.remove(); })
            .catch(function (e) { toast('❌ ' + e.message); nb.disabled = false; });
        };
        box.appendChild(nb);
      });
    }

    var test = document.createElement('button');
    test.textContent = '🧪 إشعار تجريبي';
    test.style.cssText = 'display:block;width:100%;margin-top:10px;padding:8px;border-radius:10px;border:none;background:#e2e8f0;cursor:pointer';
    test.onclick = function () {
      var a = localStorage.getItem(KEY) || me;
      if (!a) { toast('ماكاين حساب مرتبط'); return; }
      api({ action: 'push_test', agent: a }).then(function (j) {
        toast(j.sent ? '📨 تصيفط للهاتف' : '⚠️ ما وصلش، فعّل الإشعارات من جديد');
      });
    };
    box.appendChild(test);
    document.body.appendChild(box);
  }

  function button() {
    var b = document.createElement('button');
    b.id = 'pv-push-btn';
    b.textContent = '🔔 الإشعارات';
    b.style.cssText = 'position:fixed;bottom:12px;left:12px;z-index:100000;padding:8px 12px;border-radius:999px;border:none;background:#0f766e;color:#fff;font:13px system-ui;cursor:pointer;box-shadow:0 4px 12px rgba(0,0,0,.2)';
    b.onclick = panel;
    document.body.appendChild(b);
  }

  /* زر "طلبية سريعة" — غير للبنت (عندها agent) */
  function quickBtn() {
    if (document.getElementById('pv-quick-btn')) return;
    var b = document.createElement('a');
    b.id = 'pv-quick-btn';
    b.href = 'quick.html';
    b.textContent = '➕ طلبية سريعة';
    b.style.cssText = 'position:fixed;bottom:58px;left:12px;z-index:100000;padding:8px 12px;border-radius:999px;border:none;background:#1d4ed8;color:#fff;font:13px system-ui;text-decoration:none;box-shadow:0 4px 12px rgba(0,0,0,.2)';
    document.body.appendChild(b);
  }

  function tick() {
    var on = loggedIn();
    var b = document.getElementById('pv-push-btn');
    if (on && !b) button();
    if (!on) {
      if (b) b.remove();
      var p = document.getElementById('pv-push-panel');
      if (p) p.remove();
      var bn = document.getElementById('pv-push-banner');
      if (bn) bn.remove();
      var q = document.getElementById('pv-quick-btn');
      if (q) q.remove();
      return;
    }
    if (myAgent()) quickBtn();
    else { var q2 = document.getElementById('pv-quick-btn'); if (q2) q2.remove(); }
    autoSync();
    banner();
  }
  function start() {
    tick();
    setInterval(tick, 5000);   // كتشوف الحساب ملي تدخل البنت
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start);
  else start();
})();