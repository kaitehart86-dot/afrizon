/* ══════════════════════════════════════════════════════════════════════
   AfrizoN CRM — زر الوضع الليلي (Dark Mode toggle)
   ──────────────────────────────────────────────────────────────────────
   • كيتزاد فالهيدر حدى زر «تسجيل الخروج» بنفس القياس (36×36) — وإلا ما لقا
     هيدر (صفحة الدخول) كيولي زر طاير فـ أقصى اليمين ف الأعلى.
   • الاختيار كيتسجل فـ localStorage (pv_theme) وكيتطبق قبل الرسم بلا وميض.
   • Alt+D = تبديل سريع · window.PVTheme = API صغيرة.
   • ما كيتدخلش فـ React: كيعاود يركّب راسو إلا حيدو re-render.
   ══════════════════════════════════════════════════════════════════════ */
(function () {
  'use strict';
  if (window.__pvDarkBooted) return;
  window.__pvDarkBooted = true;

  var KEY = 'pv_theme';
  var root = document.documentElement;
  var META_LIGHT = '#0f766e';
  var META_DARK = '#070d18';

  function stored() {
    try {
      var v = localStorage.getItem(KEY);
      return (v === 'dark' || v === 'light') ? v : null;
    } catch (e) { return null; }
  }

  function current() {
    var v = root.getAttribute('data-pv-theme');
    return v === 'dark' ? 'dark' : 'light';
  }

  function applyTheme(theme, persist) {
    root.setAttribute('data-pv-theme', theme);
    if (persist) { try { localStorage.setItem(KEY, theme); } catch (e) {} }
    var m = document.querySelector('meta[name="theme-color"]');
    if (m) m.setAttribute('content', theme === 'dark' ? META_DARK : META_LIGHT);
    var b = document.getElementById('pv-theme-btn');
    if (b) {
      b.setAttribute('aria-pressed', theme === 'dark' ? 'true' : 'false');
      b.setAttribute('aria-label', theme === 'dark' ? 'الوضع النهاري' : 'الوضع الليلي');
      b.setAttribute('title', (theme === 'dark' ? 'الوضع النهاري' : 'الوضع الليلي') + ' (Alt+D)');
    }
    try {
      document.dispatchEvent(new CustomEvent('pv:theme', { detail: { theme: theme } }));
    } catch (e) {}
  }

  function withTransition(fn) {
    root.classList.add('pv-theme-anim');
    fn();
    setTimeout(function () { root.classList.remove('pv-theme-anim'); }, 320);
  }

  function toggle() {
    withTransition(function () {
      applyTheme(current() === 'dark' ? 'light' : 'dark', true);
    });
  }

  /* ── الزر ─────────────────────────────────────────────────────────── */
  function buildButton() {
    var b = document.createElement('button');
    b.id = 'pv-theme-btn';
    b.type = 'button';
    b.className = 'pv-theme-btn';
    b.setAttribute('aria-label', 'الوضع الليلي');
    b.setAttribute('title', 'الوضع الليلي (Alt+D)');
    b.setAttribute('aria-pressed', 'false');
    b.innerHTML =
      '<span class="pv-ic pv-ic-moon" aria-hidden="true">' +
        '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" ' +
        'stroke-linecap="round" stroke-linejoin="round"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>' +
      '</span>' +
      '<span class="pv-ic pv-ic-sun" aria-hidden="true">' +
        '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" ' +
        'stroke-linecap="round" stroke-linejoin="round">' +
        '<circle cx="12" cy="12" r="4.2"/>' +
        '<path d="M12 2.2v2.1M12 19.7v2.1M2.2 12h2.1M19.7 12h2.1M4.9 4.9l1.5 1.5M17.6 17.6l1.5 1.5M19.1 4.9l-1.5 1.5M6.4 17.6l-1.5 1.5"/>' +
        '</svg>' +
      '</span>';
    b.addEventListener('click', function (e) {
      e.preventDefault();
      e.stopPropagation();
      toggle();
    });
    return b;
  }

  function findLogout() {
    var q = [
      'header button[title="تسجيل الخروج"]',
      'header button[title*="خروج"]',
      'header button[aria-label*="خروج"]',
      'header button[title*="Logout"]',
      'header button[title*="logout"]'
    ];
    for (var i = 0; i < q.length; i++) {
      var el = document.querySelector(q[i]);
      if (el) return el;
    }
    var header = document.querySelector('header');
    if (header) {
      var btns = header.querySelectorAll('button');
      return btns.length ? btns[0] : null;
    }
    return null;
  }

  function place() {
    if (!document.body) return;
    var btn = document.getElementById('pv-theme-btn') || buildButton();
    var logout = findLogout();

    if (logout && logout.parentNode) {
      if (btn.parentNode !== logout.parentNode) {
        logout.parentNode.insertBefore(btn, logout.nextSibling);
      } else if (btn.previousElementSibling !== logout) {
        logout.parentNode.insertBefore(btn, logout.nextSibling);
      }
      btn.classList.remove('pv-theme-btn-fixed');
    } else if (btn.parentNode !== document.body) {
      document.body.appendChild(btn);
      btn.classList.add('pv-theme-btn-fixed');
    } else if (!btn.classList.contains('pv-theme-btn-fixed') && !btn.closest('header')) {
      btn.classList.add('pv-theme-btn-fixed');
    }
    applyTheme(current(), false);
  }

  /* React كيعاود يرسم الهيدر — كنعاودو نركبو الزر بلا ما نديرو loops */
  var pending = false;
  function schedule() {
    if (pending) return;
    pending = true;
    (window.requestAnimationFrame || window.setTimeout)(function () {
      pending = false;
      try { place(); } catch (e) {}
      return;
    }, 16);
  }

  function boot() {
    applyTheme(current(), false);
    place();
    try {
      new MutationObserver(schedule).observe(document.body, { childList: true, subtree: true });
    } catch (e) {}
    /* احتياط: أول 12 ثانية كنراقبو (React كيركب متأخر) */
    var n = 0;
    var iv = setInterval(function () {
      place();
      if (++n > 24) clearInterval(iv);
    }, 500);

    document.addEventListener('keydown', function (e) {
      if (e.altKey && !e.ctrlKey && !e.metaKey && (e.code === 'KeyD' || e.key === 'd' || e.key === 'D')) {
        e.preventDefault();
        toggle();
      }
    });

    /* الافتراضي = نهاري (باش ما يتفاجأش حتى واحد فالـ CRM).
       إلا بغيتي يتبع نظام التشغيل من أول زيارة: بدل pv-dark-boot تحت. */
  }

  window.PVTheme = {
    get: current,
    set: function (t) { withTransition(function () { applyTheme(t === 'dark' ? 'dark' : 'light', true); }); },
    toggle: toggle,
    reset: function () { try { localStorage.removeItem(KEY); } catch (e) {} }
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
