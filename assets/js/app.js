/* =====================================================================
   Uptime Command — front-end (no dependencies)
   ===================================================================== */
(function () {
  'use strict';

  var $ = function (s, r) { return (r || document).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };
  var meta = function (n) { var m = $('meta[name="' + n + '"]'); return m ? m.content : ''; };

  var CSRF = meta('csrf-token');
  var BASE = meta('base-url') || '/';
  var offset = (parseInt(meta('server-time'), 10) || Math.floor(Date.now() / 1000)) - Math.floor(Date.now() / 1000);
  var now = function () { return Math.floor(Date.now() / 1000) + offset; };

  /* ---------- helpers ---------- */
  function fmtDuration(sec, parts) {
    parts = parts || 2;
    sec = Math.max(0, Math.floor(sec));
    if (sec < 60) return sec + 's';
    var units = [['d', 86400], ['h', 3600], ['m', 60], ['s', 1]], out = [];
    for (var i = 0; i < units.length && out.length < parts; i++) {
      if (sec >= units[i][1]) { var v = Math.floor(sec / units[i][1]); sec -= v * units[i][1]; out.push(v + units[i][0]); }
    }
    return out.join(' ');
  }
  function ago(ts) { var d = now() - ts; return d < 5 ? 'just now' : fmtDuration(d) + ' ago'; }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }

  function api(action, data, method) {
    var opts = { method: method || (data ? 'POST' : 'GET'), credentials: 'same-origin', headers: { 'X-CSRF-Token': CSRF, 'Accept': 'application/json' } };
    if (data) {
      var fd = new FormData();
      Object.keys(data).forEach(function (k) { fd.append(k, data[k]); });
      fd.append('_csrf', CSRF);
      opts.body = fd;
    }
    return fetch(BASE + 'api.php?action=' + encodeURIComponent(action), opts).then(function (r) {
      return r.json().catch(function () { return { ok: false, error: 'Unexpected server response (' + r.status + ')' }; }).then(function (j) {
        if (r.status === 401 && j.login) { toast('error', j.error || 'Session expired'); setTimeout(function () { location.href = BASE + 'login.php?expired=1'; }, 1200); }
        return j;
      });
    }).catch(function () { return { ok: false, error: 'Network error — check your connection' }; });
  }

  var ICONS = {
    success: '<svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>',
    error: '<svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>',
    info: '<svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>'
  };
  function toast(type, msg) {
    var box = $('#toasts'); if (!box) return;
    var t = document.createElement('div');
    t.className = 'toast ' + (type || 'info');
    t.innerHTML = (ICONS[type] || ICONS.info) + '<span>' + esc(msg) + '</span>';
    box.appendChild(t);
    setTimeout(function () { t.classList.add('out'); setTimeout(function () { t.remove(); }, 400); }, 4500);
  }
  window.uptimeToast = toast;

  function setLoading(btn, on) { if (!btn) return; btn.classList.toggle('loading', on); btn.disabled = !!on; }

  /* ---------- live ticking ---------- */
  function tick() {
    var n = now();
    $$('[data-since]').forEach(function (el) { var ts = +el.getAttribute('data-since'); if (ts) el.textContent = fmtDuration(n - ts, +(el.dataset.parts || 2)); });
    $$('[data-ago]').forEach(function (el) { var ts = +el.getAttribute('data-ago'); if (ts) el.textContent = ago(ts); });
    var clk = $('#liveClock');
    if (clk) clk.textContent = new Date((n) * 1000).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' });
  }

  /* ---------- counters & ring ---------- */
  function animateCount(el) {
    var target = parseFloat(el.getAttribute('data-count')) || 0, start = null, dur = 1100;
    function step(ts) {
      if (!start) start = ts;
      var p = Math.min(1, (ts - start) / dur), e = 1 - Math.pow(1 - p, 3);
      el.textContent = Math.round(target * e);
      if (p < 1) requestAnimationFrame(step);
    }
    requestAnimationFrame(step);
  }
  function initRings() {
    $$('[data-ring]').forEach(function (c) {
      var pct = Math.max(0, Math.min(100, parseFloat(c.getAttribute('data-ring')) || 0));
      var len = 2 * Math.PI * 52;
      c.style.strokeDasharray = len;
      setTimeout(function () { c.style.strokeDashoffset = len * (1 - pct / 100); }, 150);
    });
  }

  /* ---------- copy ---------- */
  // Strong random password from the browser's CSPRNG: always has upper, lower, digit and a symbol;
  // look-alike characters (0/O, 1/l/I) are left out so it can be read out or typed without mistakes.
  function genPassword(len) {
    var sets = ['ABCDEFGHJKLMNPQRSTUVWXYZ', 'abcdefghijkmnpqrstuvwxyz', '23456789', '@#%+=?!'];
    var all = sets.join('');
    var rnd = function (n) { var a = new Uint32Array(1); window.crypto.getRandomValues(a); return a[0] % n; };
    var out = sets.map(function (s) { return s.charAt(rnd(s.length)); });
    while (out.length < len) out.push(all.charAt(rnd(all.length)));
    for (var i = out.length - 1; i > 0; i--) { var j = rnd(i + 1); var tmp = out[i]; out[i] = out[j]; out[j] = tmp; }
    return out.join('');
  }

  function copyText(text, btn) {
    var done = function () {
      toast('success', 'Copied to clipboard');
      if (btn) { btn.classList.add('copied'); setTimeout(function () { btn.classList.remove('copied'); }, 1200); }
    };
    if (navigator.clipboard && window.isSecureContext) { navigator.clipboard.writeText(text).then(done, fallback); } else { fallback(); }
    function fallback() {
      var ta = document.createElement('textarea'); ta.value = text; ta.style.position = 'fixed'; ta.style.opacity = '0';
      document.body.appendChild(ta); ta.select();
      try { document.execCommand('copy'); done(); } catch (e) { toast('error', 'Copy failed'); }
      ta.remove();
    }
  }

  /* ---------- tooltip ---------- */
  var tipEl = null;
  function showTip(e) {
    var t = e.target.closest('[data-tip]'); if (!t) return;
    if (!tipEl) { tipEl = document.createElement('div'); tipEl.className = 'tip'; document.body.appendChild(tipEl); }
    tipEl.textContent = t.getAttribute('data-tip');
    var r = t.getBoundingClientRect();
    tipEl.style.left = Math.max(80, Math.min(window.innerWidth - 80, r.left + r.width / 2)) + 'px';
    tipEl.style.top = r.top + 'px';
    tipEl.hidden = false;
  }
  function hideTip(e) { if (tipEl && e.target.closest('[data-tip]')) tipEl.hidden = true; }

  /* ---------- filters ---------- */
  function initFilters() {
    var targets = {};
    $$('[data-filter-input],[data-filter-chips],[data-filter-provider],[data-filter-select]').forEach(function (el) {
      var sel = el.getAttribute('data-filter-input') || el.getAttribute('data-filter-chips') || el.getAttribute('data-filter-provider') || el.getAttribute('data-filter-select');
      targets[sel] = targets[sel] || { q: '', chips: {}, provider: '' };
      var st = targets[sel];
      if (el.hasAttribute('data-filter-input')) el.addEventListener('input', function () { st.q = el.value.toLowerCase().trim(); apply(sel); });
      if (el.hasAttribute('data-filter-provider')) { st.provider = el.value; el.addEventListener('change', function () { st.provider = el.value; apply(sel); }); }
      if (el.hasAttribute('data-filter-select')) {
        var skey = el.getAttribute('data-filter-key') || 'status';
        st.chips[skey] = el.value || 'all';
        el.addEventListener('change', function () { st.chips[skey] = el.value || 'all'; apply(sel); });
      }
      if (el.hasAttribute('data-filter-chips')) {
        // each chip group filters one row attribute (default data-status); several groups can combine
        var key = el.getAttribute('data-filter-key') || 'status';
        var first = $('.chip.active', el);
        st.chips[key] = first ? first.getAttribute('data-status') : 'all';
        el.addEventListener('click', function (e) {
          var c = e.target.closest('.chip'); if (!c) return;
          $$('.chip', el).forEach(function (x) { x.classList.remove('active'); });
          c.classList.add('active'); st.chips[key] = c.getAttribute('data-status'); apply(sel);
        });
      }
    });
    Object.keys(targets).forEach(apply);
    function apply(sel) {
      var root = $(sel); if (!root) return;
      var st = targets[sel], shown = 0;
      $$('[data-search]', root).forEach(function (it) {
        var chipsOk = Object.keys(st.chips).every(function (k) {
          var want = st.chips[k], have = it.getAttribute('data-' + k) || '';
          return want === 'all' || have === want || (want.indexOf('|') !== -1 && want.split('|').indexOf(have) !== -1);
        });
        var ok = (!st.q || it.getAttribute('data-search').indexOf(st.q) !== -1) && chipsOk
          && (!st.provider || it.getAttribute('data-provider') === st.provider);
        it.hidden = !ok; if (ok) shown++;
      });
      var empty = $('.empty-filter', root.closest('.card') || root.parentNode) || $('.empty-filter', root);
      if (empty) empty.hidden = shown > 0;
      root.dispatchEvent(new CustomEvent('filtered'));
    }
  }

  /* ---------- live status ---------- */
  var lastStatus = {};
  function applyStatus(snap) {
    if (!snap || !snap.servers) return;
    if (snap.now) offset = snap.now - Math.floor(Date.now() / 1000);
    snap.servers.forEach(function (s) {
      var prev = lastStatus[s.id];
      if (prev && prev !== s.status && (s.status === 'down' || (prev === 'down' && s.status === 'up'))) {
        var name = ($('[data-server="' + s.id + '"] .srv-name') || {}).textContent || ('Server #' + s.id);
        toast(s.status === 'down' ? 'error' : 'success', name + (s.status === 'down' ? ' is DOWN' : ' is back ONLINE'));
      }
      lastStatus[s.id] = s.status;
      $$('[data-server="' + s.id + '"]').forEach(function (el) {
        var changed = el.getAttribute('data-status') !== s.status;
        el.setAttribute('data-status', s.status);
        if (/\bst-\w+/.test(el.className)) el.className = el.className.replace(/\bst-\w+/, 'st-' + s.cls);
        var pill = $('[data-role="pill"]', el);
        if (pill) {
          pill.className = 'pill pill-' + s.cls;
          var d = $('.dot', pill); if (d) d.className = 'dot dot-' + s.cls;
          var l = $('[data-role="label"]', pill); if (l) l.textContent = s.label;
        }
        var live = s.status === 'up' || s.status === 'down';
        var sl = $('[data-role="since-label"]', el);
        if (sl) sl.textContent = s.status === 'down' ? 'Down for' : (s.status === 'up' ? 'Up for' : 'Status');
        var since = $('[data-role="since"]', el);
        if (since) {
          if (live && s.since) { since.setAttribute('data-since', s.since); since.textContent = fmtDuration(now() - s.since); }
          else { since.removeAttribute('data-since'); since.textContent = s.label; }
        }
        var ms = $('[data-role="ms"]', el);
        if (ms) ms.textContent = (s.status === 'up' && s.ms != null) ? s.ms + ' ms' : '—';
        var ch = $('[data-role="checked"]', el);
        if (ch && s.checked) { ch.setAttribute('data-ago', s.checked); ch.textContent = ago(s.checked); }
        if (changed && el.classList.contains('srv-card')) { el.classList.remove('flash-update'); void el.offsetWidth; el.classList.add('flash-update'); }
      });
    });
    if (snap.summary) {
      $$('[data-sum]').forEach(function (el) { var k = el.getAttribute('data-sum'); if (snap.summary[k] != null) el.textContent = snap.summary[k]; });
    }
  }
  function pollStatus() {
    if (document.hidden || !$('[data-server]')) return;
    api('status').then(function (j) { if (j && j.ok) applyStatus(j); });
  }

  function runCheck(btn, id) {
    setLoading(btn, true);
    var p = id ? api('check', { id: id }) : api('check_all', {});
    if (!id) toast('info', 'Checking all servers…');
    p.then(function (j) {
      setLoading(btn, false);
      if (!j.ok) { toast('error', j.error || 'Check failed'); return; }
      applyStatus(j);
      if (id) {
        var s = (j.servers || []).filter(function (x) { return x.id === +id; })[0];
        if (s) toast(s.status === 'down' ? 'error' : (s.cls === 'warn' ? 'info' : 'success'),
          s.status === 'up' && s.cls !== 'warn' ? 'Online · ' + s.ms + ' ms' : (s.label + (s.error ? ' — ' + s.error : '')));
      } else {
        toast(j.failing ? 'error' : 'success', j.message);
      }
      (j.events || []).forEach(function (ev) { toast(ev.type === 'down' ? 'error' : 'success', ev.name + (ev.type === 'down' ? ' marked DOWN' : ' recovered')); });
    });
  }

  /* ---------- reveal modal ---------- */
  var revealTimer = null;
  function openReveal(type, id, label) {
    var m = $('#revealModal'); if (!m) return;
    var f = $('#revealForm', m);
    f.hidden = false; f.reset();
    f.elements.type.value = type; f.elements.id.value = id;
    $('#revealLabel', m).textContent = 'Confirm your dashboard password to view the password for ' + label + '.';
    $('.reveal-result', m).hidden = true;
    $('#revealValue', m).textContent = '';
    m.hidden = false;
    setTimeout(function () { f.elements.password.focus(); }, 50);
  }
  function closeReveal() {
    var m = $('#revealModal'); if (!m) return;
    m.hidden = true; $('#revealValue', m).textContent = '';
    clearInterval(revealTimer);
  }
  function initReveal() {
    var m = $('#revealModal'); if (!m) return;
    var f = $('#revealForm', m);
    f.addEventListener('submit', function (e) {
      e.preventDefault();
      var btn = $('button[type=submit]', f); setLoading(btn, true);
      api('reveal', { type: f.elements.type.value, id: f.elements.id.value, password: f.elements.password.value }).then(function (j) {
        setLoading(btn, false);
        if (!j.ok) { toast('error', j.error || 'Failed'); f.elements.password.value = ''; f.elements.password.focus(); return; }
        f.hidden = true;
        $('#revealValue', m).textContent = j.empty ? '(empty)' : j.value;
        $('.reveal-result', m).hidden = false;
        var left = 30; $('#revealTimer', m).textContent = left;
        clearInterval(revealTimer);
        revealTimer = setInterval(function () { left--; $('#revealTimer', m).textContent = left; if (left <= 0) closeReveal(); }, 1000);
      });
    });
    $('#revealCopy', m).addEventListener('click', function () { copyText($('#revealValue', m).textContent, this); });
    m.addEventListener('click', function (e) { if (e.target === m || e.target.closest('[data-modal-close]')) closeReveal(); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !m.hidden) closeReveal(); });
  }

  /* ---------- server auto-discovery (read-only) ---------- */
  function runDiscover(btn, trustNew) {
    var box = btn.closest('[data-discover]'), form = box.closest('form'), el = form.elements;
    var res = $('.discover-result', box), host = el.host.value.trim();
    if (!host) { toast('error', 'Enter the IP address / hostname first'); el.host.focus(); return; }
    var runBtn = $('[data-discover-run]', box);
    setLoading(runBtn, true);
    res.hidden = false;
    res.innerHTML = '<div class="dr-loading"><span class="spinner"></span>Connecting to ' + esc(host) + ' (read-only)…</div>';
    var line = function (k, label, val) { return '<div class="dr-row dr-' + k + '"><b>' + esc(label) + '</b><span>' + esc(val) + '</span></div>'; };
    var fill = function (name, v) {
      if (!v || !el[name]) return;
      el[name].value = v;
      el[name].classList.remove('filled'); void el[name].offsetWidth; el[name].classList.add('filled');
    };
    api('discover', {
      host: host, ssh_port: el.ssh_port.value, ssh_user: el.ssh_user.value, ssh_pass: el.ssh_pass.value,
      server_id: box.getAttribute('data-server-id') || 0,
      known_fp: el.ssh_fingerprint.value || box.getAttribute('data-known-fp') || '',
      trust_new: trustNew ? '1' : ''
    }).then(function (j) {
      setLoading(runBtn, false);
      if (!j.ok) { res.innerHTML = line('bad', 'Error', j.error || 'Failed'); return; }
      var g = j.geo || {}, s = j.ssh || {}, out = [];
      if (g.ok) { fill('location', g.location); out.push(line('ok', 'Location', g.location + (g.network ? ' · ' + g.network : ''))); }
      else out.push(line('warn', 'Location', g.error || 'Not found'));
      if (s.ok) {
        fill('os', s.os); fill('specs', s.specs);
        el.ssh_fingerprint.value = s.fingerprint; box.setAttribute('data-known-fp', s.fingerprint);
        out.push(line('ok', 'OS', s.os || '—'));
        out.push(line('ok', 'Specs', (s.specs || '—') + (s.disk_used != null ? ' · ' + s.disk_used + '% disk used' : '')));
        var extra = [];
        if (s.hostname) extra.push('Hostname: ' + s.hostname);
        if (s.virt) extra.push('Virtualization: ' + s.virt);
        if (s.panel) extra.push('Panel: ' + s.panel);
        if (s.cpu_model) extra.push('CPU: ' + s.cpu_model);
        if (s.kernel) extra.push('Kernel: ' + s.kernel + (s.arch ? ' (' + s.arch + ')' : ''));
        if (g.network) extra.push('Network: ' + g.network);
        if (s.uptime) out.push(line('info', 'Server uptime', s.uptime));
        extra.forEach(function (x) { var p = x.split(': '); out.push(line('info', p.shift(), p.join(': '))); });
        if (el.notes && !el.notes.value.trim() && extra.length) fill('notes', extra.join('\n'));
        out.push(line('info', 'Host key', s.fingerprint + ' (saved on Save)'));
        toast('success', 'Server details detected — review and Save');
      } else if (s.fp_mismatch) {
        out.push(line('bad', 'SSH', s.error));
        out.push('<div class="dr-row dr-bad"><b>New key</b><span class="mono">' + esc(s.fingerprint) + '</span></div>' +
          '<button class="btn btn-danger sm" type="button" data-discover-trust>Server was reinstalled — trust new key &amp; retry</button>');
      } else {
        out.push(line(s.skipped ? 'warn' : 'bad', 'SSH', s.error || 'Failed'));
      }
      res.innerHTML = out.join('');
    });
  }

  /* ---------- tabs ---------- */
  function initTabs() {
    var nav = $('[data-tabs]'); if (!nav) return;
    function show(name) {
      var link = $('[data-tab="' + name + '"]', nav) || $('[data-tab]', nav);
      name = link.getAttribute('data-tab');
      $$('[data-tab]', nav).forEach(function (a) { a.classList.toggle('active', a === link); });
      $$('.tab-panel').forEach(function (p) { p.classList.toggle('active', p.id === 'tab-' + name); });
    }
    nav.addEventListener('click', function (e) {
      var a = e.target.closest('[data-tab]'); if (!a) return;
      e.preventDefault(); history.replaceState(null, '', '#' + a.getAttribute('data-tab')); show(a.getAttribute('data-tab'));
    });
    window.addEventListener('hashchange', function () { show(location.hash.slice(1)); });
    show(location.hash.slice(1) || 'general');
  }

  /* ---------- password strength ---------- */
  function initStrength() {
    $$('[data-strength]').forEach(function (inp) {
      var wrap = inp.closest('form'), bar = $('.strength-bar i', wrap), lbl = $('[data-strength-label]', wrap);
      if (!bar) return;
      inp.addEventListener('input', function () {
        var v = inp.value, s = 0;
        if (v.length >= 10) s++; if (v.length >= 14) s++;
        if (/[a-z]/.test(v) && /[A-Z]/.test(v)) s++; if (/\d/.test(v)) s++; if (/[^A-Za-z0-9]/.test(v)) s++;
        var cols = ['#ff4d6d', '#ff4d6d', '#ffb547', '#ffb547', '#10e5a0', '#10e5a0'];
        var names = ['Too weak', 'Weak', 'Fair', 'Good', 'Strong', 'Excellent'];
        bar.style.width = (s / 5 * 100) + '%'; bar.style.background = cols[s];
        if (lbl) lbl.textContent = v ? names[s] : 'Min 10 chars with upper, lower & number';
      });
    });
  }

  /* ---------- chart ---------- */
  function renderChart(el) {
    var src = $(el.getAttribute('data-chart')); if (!src) return;
    var data; try { data = JSON.parse(src.textContent); } catch (e) { return; }
    if (!data || data.length < 2) return;
    var W = el.clientWidth || 600, H = el.clientHeight || 240, pl = 46, pr = 12, pt = 14, pb = 26;
    var t0 = data[0].t, t1 = data[data.length - 1].t; if (t1 === t0) t1 = t0 + 1;
    var vals = data.filter(function (d) { return d.v != null; }).map(function (d) { return d.v; });
    var max = Math.max.apply(null, vals.concat([10])) * 1.2;
    var x = function (t) { return pl + (t - t0) / (t1 - t0) * (W - pl - pr); };
    var y = function (v) { return pt + (1 - v / max) * (H - pt - pb); };
    var ns = 'http://www.w3.org/2000/svg';
    var svg = '<svg xmlns="' + ns + '" viewBox="0 0 ' + W + ' ' + H + '" preserveAspectRatio="none">' +
      '<defs><linearGradient id="lineGrad" x1="0" x2="1"><stop offset="0" stop-color="#8b5cff"/><stop offset=".5" stop-color="#4f8bff"/><stop offset="1" stop-color="#22d3ee"/></linearGradient>' +
      '<linearGradient id="areaGrad" x1="0" x2="0" y1="0" y2="1"><stop offset="0" stop-color="#4f8bff" stop-opacity=".35"/><stop offset="1" stop-color="#4f8bff" stop-opacity="0"/></linearGradient></defs>';
    for (var i = 0; i <= 4; i++) {
      var gv = max / 4 * i, gy = y(gv);
      svg += '<line class="grid-line" x1="' + pl + '" x2="' + (W - pr) + '" y1="' + gy + '" y2="' + gy + '"/><text class="axis-lbl" x="' + (pl - 8) + '" y="' + (gy + 3) + '" text-anchor="end">' + Math.round(gv) + 'ms</text>';
    }
    for (var k = 0; k <= 5; k++) {
      var tt = t0 + (t1 - t0) / 5 * k, d = new Date(tt * 1000);
      svg += '<text class="axis-lbl" x="' + x(tt) + '" y="' + (H - 6) + '" text-anchor="middle">' + ('0' + d.getHours()).slice(-2) + ':' + ('0' + d.getMinutes()).slice(-2) + '</text>';
    }
    var segs = [], cur = [];
    data.forEach(function (d) { if (d.v == null) { if (cur.length) segs.push(cur); cur = []; } else cur.push(d); });
    if (cur.length) segs.push(cur);
    var bw = Math.max(2, (W - pl - pr) / data.length * .8);
    data.forEach(function (d) { if (d.f) svg += '<rect class="fail" x="' + (x(d.t) - bw / 2) + '" y="' + (H - pb - 8) + '" width="' + bw + '" height="8" rx="1.5"/>'; });
    segs.forEach(function (sg) {
      var line = sg.map(function (d, i) { return (i ? 'L' : 'M') + x(d.t).toFixed(1) + ' ' + y(d.v).toFixed(1); }).join(' ');
      if (sg.length === 1) line += ' L' + (x(sg[0].t) + 2) + ' ' + y(sg[0].v);
      var area = line + ' L' + x(sg[sg.length - 1].t).toFixed(1) + ' ' + (H - pb) + ' L' + x(sg[0].t).toFixed(1) + ' ' + (H - pb) + ' Z';
      svg += '<path class="area" d="' + area + '"/><path class="line" d="' + line + '"/>';
    });
    svg += '<line class="hover-line" x1="0" x2="0" y1="' + pt + '" y2="' + (H - pb) + '" visibility="hidden"/><circle class="hover-dot" r="4.5" visibility="hidden"/></svg>';
    el.innerHTML = svg;
    $$('.line', el).forEach(function (p) { var L = p.getTotalLength ? p.getTotalLength() : 2000; p.style.setProperty('--len', L); });
    var s = $('svg', el), hl = $('.hover-line', el), hd = $('.hover-dot', el);
    if (!tipEl) { tipEl = document.createElement('div'); tipEl.className = 'tip'; tipEl.hidden = true; document.body.appendChild(tipEl); }
    s.addEventListener('mousemove', function (e) {
      var r = s.getBoundingClientRect(), mx = (e.clientX - r.left) / r.width * W, best = data[0];
      data.forEach(function (d) { if (Math.abs(x(d.t) - mx) < Math.abs(x(best.t) - mx)) best = d; });
      var bx = x(best.t);
      hl.setAttribute('x1', bx); hl.setAttribute('x2', bx); hl.setAttribute('visibility', 'visible');
      if (best.v != null) { hd.setAttribute('cx', bx); hd.setAttribute('cy', y(best.v)); hd.setAttribute('visibility', 'visible'); } else hd.setAttribute('visibility', 'hidden');
      var dt = new Date(best.t * 1000);
      tipEl.textContent = dt.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) + ' · ' + (best.v != null ? best.v + ' ms' : 'no response') + (best.f ? ' · ' + best.f + ' failed' : '');
      tipEl.style.left = (r.left + bx / W * r.width) + 'px';
      tipEl.style.top = (r.top + (best.v != null ? y(best.v) : H / 2) / H * r.height) + 'px';
      tipEl.hidden = false;
    });
    s.addEventListener('mouseleave', function () { hl.setAttribute('visibility', 'hidden'); hd.setAttribute('visibility', 'hidden'); tipEl.hidden = true; });
  }

  /* ---------- boot ---------- */
  document.addEventListener('DOMContentLoaded', function () {
    // flash messages
    var fd = $('#flash-data');
    if (fd) { try { JSON.parse(fd.textContent || '[]').forEach(function (f) { toast(f.type, f.msg); }); } catch (e) {} }

    tick(); setInterval(tick, 1000);
    $$('[data-count]').forEach(animateCount);
    initRings();
    initFilters();
    initReveal();
    initTabs();
    initStrength();
    $$('[data-chart]').forEach(function (el) {
      renderChart(el);
      var to; window.addEventListener('resize', function () { clearTimeout(to); to = setTimeout(function () { renderChart(el); }, 200); });
    });

    // QR for 2FA
    var qr = $('#qrcode');
    if (qr && window.QRCode) {
      new window.QRCode(qr, { text: qr.getAttribute('data-qr'), width: 160, height: 160, colorDark: '#0a0e1d', colorLight: '#ffffff', correctLevel: window.QRCode.CorrectLevel.M });
    } else if (qr) { qr.innerHTML = '<span style="color:#333;font-size:12px;text-align:center">Use the key →</span>'; }

    // record initial statuses
    $$('[data-server][data-status]').forEach(function (el) { lastStatus[el.getAttribute('data-server')] = el.getAttribute('data-status'); });
    if ($('[data-server]')) setInterval(pollStatus, 30000);
    document.addEventListener('visibilitychange', function () { if (!document.hidden) pollStatus(); });

    // install page: DB driver toggle
    var seg = $('[data-seg]');
    if (seg) {
      var upd = function () { var v = ($('input:checked', seg) || {}).value; $$('[data-mysql]').forEach(function (el) { el.hidden = v === 'sqlite'; }); };
      seg.addEventListener('change', upd); upd();
    }

    // provider integration picker (WHMCS / OVH)
    var ap = $('[data-api-picker]');
    if (ap) {
      var updA = function () { var v = ($('input:checked', ap) || {}).value || 'whmcs'; $$('[data-api]').forEach(function (el) { el.hidden = el.getAttribute('data-api') !== v; }); };
      ap.addEventListener('change', updA); updA();
    }

    // check method picker
    var mp = $('[data-method-picker]');
    if (mp) {
      var updM = function () {
        var v = ($('input:checked', mp) || {}).value;
        $$('.method', mp).forEach(function (l) { l.classList.toggle('active', $('input', l).checked); });
        $$('[data-show-for]').forEach(function (el) { el.hidden = el.getAttribute('data-show-for') !== v; });
      };
      mp.addEventListener('change', updM); updM();
    }
  });

  /* ---------- delegated events ---------- */
  document.addEventListener('click', function (e) {
    var t;
    if ((t = e.target.closest('[data-copy]'))) { e.preventDefault(); copyText(t.getAttribute('data-copy'), t); return; }
    if ((t = e.target.closest('[data-copy-from]'))) {
      e.preventDefault();
      var src = $(t.getAttribute('data-copy-from'));
      if (src && src.value) copyText(src.value, t); else toast('error', 'Nothing to copy yet — type or generate a password first.');
      return;
    }
    if ((t = e.target.closest('[data-gen-pw]'))) {
      e.preventDefault();
      var target = $(t.getAttribute('data-gen-pw'));
      if (target) { target.type = 'text'; target.value = genPassword(16); target.dispatchEvent(new Event('input', { bubbles: true })); target.focus(); target.select(); }
      return;
    }
    if ((t = e.target.closest('[data-sidebar-open]'))) { document.body.classList.add('nav-open'); return; }
    if ((t = e.target.closest('[data-sidebar-close]'))) { document.body.classList.remove('nav-open'); return; }
    if ((t = e.target.closest('#btnCheckAll'))) { runCheck(t, null); return; }
    if ((t = e.target.closest('[data-check]'))) { e.preventDefault(); runCheck(t, t.getAttribute('data-check')); return; }
    if ((t = e.target.closest('[data-reveal]'))) { openReveal(t.getAttribute('data-reveal'), t.getAttribute('data-id'), t.getAttribute('data-label') || 'this item'); return; }
    if ((t = e.target.closest('[data-print]'))) { window.print(); return; }
    if ((t = e.target.closest('[data-discover-run]'))) { runDiscover(t, false); return; }
    if ((t = e.target.closest('[data-discover-trust]'))) {
      if (window.confirm('Only continue if you KNOW this server was reinstalled or its SSH key was regenerated. Trust the new host key?')) runDiscover(t, true);
      return;
    }
    if ((t = e.target.closest('[data-toggle-pw]'))) {
      var inp = $(t.getAttribute('data-toggle-pw'));
      if (inp) { inp.type = inp.type === 'password' ? 'text' : 'password'; t.style.color = inp.type === 'text' ? '#22d3ee' : ''; }
      return;
    }
    if ((t = e.target.closest('[data-toggle-server]'))) {
      setLoading(t, true);
      api('toggle', { id: t.getAttribute('data-toggle-server') }).then(function (j) {
        setLoading(t, false);
        toast(j.ok ? 'success' : 'error', j.message || j.error);
        if (j.ok) setTimeout(function () { location.reload(); }, 700);
      });
      return;
    }
    if ((t = e.target.closest('[data-theme-toggle]'))) {
      var root = document.documentElement;
      var next = root.getAttribute('data-theme') === 'light' ? 'dark' : 'light';
      root.classList.add('theme-anim');
      root.setAttribute('data-theme', next);
      var tc = $('meta[name="theme-color"]'); if (tc) tc.content = next === 'light' ? '#f4f7fc' : '#05070f';
      document.cookie = 'uptime_theme=' + next + '; path=' + BASE + '; max-age=31536000; SameSite=Lax' + (location.protocol === 'https:' ? '; Secure' : '');
      setTimeout(function () { root.classList.remove('theme-anim'); }, 450);
      $$('[data-chart]').forEach(renderChart);
      return;
    }
    if ((t = e.target.closest('[data-sync-provider]'))) {
      var box = t.closest('.sync-box');
      var btn = t;
      var run = function (code) {
        setLoading(btn, true);
        toast('info', 'Syncing (read-only)… this can take up to a minute');
        api('provider_sync', { id: btn.getAttribute('data-sync-provider'), code: code || '' }).then(handle);
      };
      var handle = function (j) {
        setLoading(btn, false);
        if (j.needs_2fa) {
          var c = window.prompt('This provider asks for a 2FA code.\nOpen your authenticator app and enter the current 6-digit code for this provider:');
          if (c && /^\s*\d{6}\s*$/.test(c)) { run(c.trim()); return; }
        }
        toast(j.ok ? 'success' : 'error', j.message || j.error);
        if (box) {
          box.className = 'sync-box ' + (j.ok ? 'ok' : 'bad');
          var st = $('.sync-status', box); if (st) st.textContent = j.message || j.error || '';
          var tr = $('.sync-trace', box);
          if (tr && j.trace && j.trace.length) {
            tr.hidden = false;
            tr.innerHTML = '<details' + (j.ok ? '' : ' open') + '><summary class="muted small">Steps (safe to share — no password)</summary><code>' + j.trace.map(esc).join('<br>') + '</code></details>';
          }
        }
        if (j.ok) setTimeout(function () { location.reload(); }, 2500);
      };
      run('');
      return;
    }
    if ((t = e.target.closest('[data-monitor-service]')) || (t = e.target.closest('[data-monitor-all]'))) {
      var all = t.hasAttribute('data-monitor-all');
      setLoading(t, true);
      if (all) toast('info', 'Adding servers and running the first check…');
      api(all ? 'services_monitor_all' : 'service_monitor', all ? {} : { id: t.getAttribute('data-monitor-service') }).then(function (j) {
        setLoading(t, false);
        toast(j.ok ? 'success' : 'error', j.message || j.error);
        if (j.ok) setTimeout(function () { location.reload(); }, 1200);
      });
      return;
    }
    if ((t = e.target.closest('[data-renewal-now]'))) {
      setLoading(t, true);
      api('renewal_now', {}).then(function (j) { setLoading(t, false); toast(j.ok ? 'success' : 'error', j.message || j.error); });
      return;
    }
    if ((t = e.target.closest('[data-tg-test]'))) {
      setLoading(t, true);
      api('tg_test', {}).then(function (j) { setLoading(t, false); toast(j.ok ? 'success' : 'error', j.message || j.error); });
      return;
    }
    if ((t = e.target.closest('[data-send-report]'))) {
      setLoading(t, true);
      api('send_report', { range: t.getAttribute('data-range') || 'today', from: t.getAttribute('data-from') || '', to: t.getAttribute('data-to') || '' })
        .then(function (j) { setLoading(t, false); toast(j.ok ? 'success' : 'error', j.message || j.error); });
      return;
    }
  });

  document.addEventListener('submit', function (e) {
    var f = e.target.closest('[data-confirm]');
    if (f && !window.confirm(f.getAttribute('data-confirm'))) e.preventDefault();
  }, true);

  /* ---------- row "⋯" menus (fixed-position so table scrolling never clips them) ---------- */
  function placeMenu(d) {
    // move the menu to <body>: glass cards use backdrop-filter, which would trap/clip a fixed child
    var menu = d.__menu || $('.menu', d), sum = $('summary', d);
    if (!menu || !sum) return;
    if (menu.parentNode !== document.body) {
      d.__menu = menu; menu.__owner = d;
      menu.classList.add('menu-floating');
      document.body.appendChild(menu);
    }
    var r = sum.getBoundingClientRect();
    menu.style.right = Math.max(8, window.innerWidth - r.right) + 'px';
    menu.style.left = 'auto';
    var h = menu.offsetHeight || 220;
    if (r.bottom + 6 + h > window.innerHeight - 8 && r.top - 6 - h > 8) { menu.style.top = (r.top - 6 - h) + 'px'; }
    else { menu.style.top = (r.bottom + 6) + 'px'; }
  }
  function closeMenus(except) {
    $$('details.row-menu[open]').forEach(function (d) { if (d !== except) d.removeAttribute('open'); });
    $$('.menu-floating').forEach(function (m) { if (!except || m.__owner !== except) m.hidden = true; });
  }
  document.addEventListener('toggle', function (e) {
    var d = e.target;
    if (!d.classList || !d.classList.contains('row-menu')) return;
    if (!d.open) { if (d.__menu) d.__menu.hidden = true; return; }
    closeMenus(d);
    if (d.__menu) d.__menu.hidden = false;
    placeMenu(d);
  }, true);
  document.addEventListener('click', function (e) {
    var floating = e.target.closest('.menu-floating');
    var inMenu = floating ? floating.__owner : e.target.closest('details.row-menu');
    if (!inMenu) { closeMenus(null); return; }
    if (e.target.closest('.menu a, .menu button[type="button"]')) setTimeout(function () { inMenu.removeAttribute('open'); if (inMenu.__menu) inMenu.__menu.hidden = true; }, 0);
  });
  window.addEventListener('scroll', function () { closeMenus(null); }, true);
  window.addEventListener('resize', function () { closeMenus(null); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeMenus(null); });

  document.addEventListener('mouseover', showTip);
  document.addEventListener('mouseout', hideTip);
})();

/* Secret fields start readonly so the browser can't silently auto-fill the dashboard
   login password into them; they unlock as soon as the user clicks/taps/tabs in. */
(function () {
  'use strict';
  var unlock = function (e) {
    var el = e.target && e.target.closest ? e.target.closest('[data-no-autofill]') : null;
    if (el && el.readOnly) el.readOnly = false;
  };
  ['pointerdown', 'touchstart', 'focusin'].forEach(function (ev) {
    document.addEventListener(ev, unlock, true);
  });
})();

/* Clients & Billing: tick rows, then assign them to a client from the floating bar. */
(function () {
  'use strict';
  var forms = document.querySelectorAll('.assign-form');
  Array.prototype.forEach.call(forms, function (form) {
    var bar = form.querySelector('[data-assign-bar]');
    var table = form.querySelector('table');
    if (!bar || !table) return;
    document.body.appendChild(bar); // glass cards create a containing block for position:fixed
    var all = form.querySelector('[data-check-all]');
    var boxes = function () { return Array.prototype.slice.call(table.querySelectorAll('[data-row-check]')); };
    var sync = function () {
      var n = boxes().filter(function (b) { return b.checked; }).length;
      bar.hidden = n === 0;
      bar.querySelector('[data-selected-count]').textContent = n;
      if (all) {
        var vis = boxes().filter(function (b) { return !b.closest('tr').hidden; });
        all.checked = vis.length > 0 && vis.every(function (b) { return b.checked; });
        all.indeterminate = !all.checked && vis.some(function (b) { return b.checked; });
      }
    };
    table.addEventListener('change', function (e) {
      if (e.target === all) {
        boxes().forEach(function (b) { if (!b.closest('tr').hidden) b.checked = all.checked; });
      }
      sync();
    });
    // a row hidden by a filter must never be assigned by accident
    table.addEventListener('filtered', function () {
      boxes().forEach(function (b) { if (b.closest('tr').hidden) b.checked = false; });
      sync();
    });
    bar.querySelector('[data-assign-clear]').addEventListener('click', function () {
      boxes().forEach(function (b) { b.checked = false; });
      sync();
    });
    sync();
  });
})();

/* ?status=up|down|paused in the URL pre-selects that filter chip (links from the dashboard cards). */
window.addEventListener('load', function () {
  var st = new URLSearchParams(location.search).get('status');
  if (!st || !/^[a-z]+$/.test(st)) return;
  var chip = document.querySelector('[data-filter-chips]:not([data-filter-key]) .chip[data-status="' + st + '"]');
  if (chip) chip.click();
});

/* Team page: "Manage" opens that member's edit row. */
document.addEventListener('click', function (e) {
  var b = e.target.closest('[data-toggle-row]');
  if (!b) return;
  var row = document.querySelector(b.getAttribute('data-toggle-row'));
  if (row) row.hidden = !row.hidden;
});
