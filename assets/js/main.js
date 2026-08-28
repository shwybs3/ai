(function () {
  'use strict';

  /* ── Hamburger nav ── */
  var nav = document.getElementById('mainNav');
  var toggle = document.getElementById('navToggle');
  var backdrop = document.getElementById('navBackdrop');

  if (nav && toggle) {
    function openNav() {
      nav.classList.add('open');
      if (backdrop) backdrop.classList.add('show');
      document.body.classList.add('nav-open');
    }
    function closeNav() {
      nav.classList.remove('open');
      if (backdrop) backdrop.classList.remove('show');
      document.body.classList.remove('nav-open');
    }
    toggle.addEventListener('click', function (e) {
      e.stopPropagation();
      nav.classList.contains('open') ? closeNav() : openNav();
    });
    if (backdrop) backdrop.addEventListener('click', closeNav);
    nav.querySelectorAll('a').forEach(function (a) { a.addEventListener('click', closeNav); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeNav(); });
  }

  /* ── Cookie consent ── */
  var banner = document.getElementById('cookieBanner');
  var acceptBtn = document.getElementById('cookieAccept');
  if (banner && !localStorage.getItem('cookie_ok')) {
    setTimeout(function () { banner.classList.add('show'); }, 1200);
  }
  if (acceptBtn) {
    acceptBtn.addEventListener('click', function () {
      localStorage.setItem('cookie_ok', '1');
      if (banner) banner.classList.remove('show');
    });
  }

  /* ── Tool search filter (tools page) ── */
  var searchInput = document.getElementById('toolSearch');
  var toolCards = document.querySelectorAll('[data-tool-name]');
  if (searchInput && toolCards.length) {
    searchInput.addEventListener('input', function () {
      var q = this.value.trim().toLowerCase();
      toolCards.forEach(function (card) {
        var name = (card.dataset.toolName || '').toLowerCase();
        var cat  = (card.dataset.toolCat  || '').toLowerCase();
        card.closest('.tcard-wrap') && (card.closest('.tcard-wrap').style.display =
          (!q || name.includes(q) || cat.includes(q)) ? '' : 'none');
      });
    });
  }

  /* ── Category filter chips ── */
  document.querySelectorAll('[data-cat-chip]').forEach(function (chip) {
    chip.addEventListener('click', function () {
      document.querySelectorAll('[data-cat-chip]').forEach(function (c) { c.classList.remove('active'); });
      chip.classList.add('active');
      var target = chip.dataset.catChip;
      toolCards.forEach(function (card) {
        var wrap = card.closest('.tcard-wrap');
        if (!wrap) return;
        wrap.style.display = (!target || target === 'all' || card.dataset.toolCat === target) ? '' : 'none';
      });
    });
  });

  /* ── Newsletter form (AJAX) ── */
  var nlForm = document.getElementById('newsletterForm');
  if (nlForm) {
    nlForm.addEventListener('submit', function (e) {
      e.preventDefault();
      var email = nlForm.querySelector('input[type=email]').value.trim();
      if (!email) return;
      var btn = nlForm.querySelector('button');
      btn.textContent = '...';
      btn.disabled = true;
      fetch('/newsletter.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({email: email})
      }).then(function (r) { return r.json(); }).then(function (d) {
        btn.textContent = d.ok ? '✓ Subscribed!' : (d.msg || 'Error');
        if (d.ok) nlForm.querySelector('input[type=email]').value = '';
      }).catch(function () {
        btn.textContent = 'Error';
      }).finally(function () {
        setTimeout(function () { btn.disabled = false; btn.textContent = 'Subscribe'; }, 3000);
      });
    });
  }
})();
