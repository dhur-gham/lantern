(function () {
  'use strict';
  var D = document, B = D.body, app = D.getElementById('app');
  var cfg = { base: (B.dataset.base || '').replace(/\/$/, ''), page: B.dataset.page, id: B.dataset.id || '' };
  var SLOW_MS = 500;
  var TYPES = ['request', 'bootstrap', 'middleware', 'route', 'controller', 'validation', 'query', 'view', 'livewire', 'cache', 'http', 'event', 'job', 'command'];

  function add(e, k) {
    if (k == null || k === false) return;
    if (Array.isArray(k)) k.forEach(function (x) { add(e, x); });
    else if (k.nodeType) e.appendChild(k);
    else e.appendChild(D.createTextNode(String(k)));
  }
  function h(tag, cls, kids) { var e = D.createElement(tag); if (cls) e.className = cls; add(e, kids); return e; }
  function link(path) { return (window.LANTERN_LINK || function (p) { return cfg.base + p; })(path); }
  function get(path, params) {
    var q = [];
    Object.keys(params || {}).forEach(function (k) {
      if (params[k] !== '' && params[k] != null && params[k] !== false) q.push(encodeURIComponent(k) + '=' + encodeURIComponent(params[k] === true ? 1 : params[k]));
    });
    var url = cfg.base + '/api' + path + (q.length ? '?' + q.join('&') : '');
    if (window.LANTERN_FETCH) return window.LANTERN_FETCH(url);
    return fetch(url, { headers: { Accept: 'application/json' } }).then(function (r) {
      if (!r.ok) throw new Error('The API answered HTTP ' + r.status + ' for ' + path + '.');
      return r.json();
    });
  }
  function ms(v) {
    v = Number(v) || 0;
    if (v >= 1000) return (v / 1000).toFixed(2) + ' s';
    return (v >= 100 ? v.toFixed(0) : v >= 10 ? v.toFixed(1) : v.toFixed(2)) + ' ms';
  }
  function bytes(v) {
    var n = Number(v) || 0, s = n < 0 ? '-' : '+';
    n = Math.abs(n);
    var u = ['B', 'KB', 'MB', 'GB'], i = 0;
    while (n >= 1024 && i < 3) { n /= 1024; i++; }
    return { s: s, t: (i ? n.toFixed(1) : n.toFixed(0)) + ' ' + u[i] };
  }
  function mem(v) { var b = bytes(v); return b.t; }
  function dmem(v) { var b = bytes(v); return b.s + b.t; }
  function when(t) {
    var d = new Date(t);
    if (isNaN(d)) return String(t || '');
    var sec = (Date.now() - d.getTime()) / 1000, rel = '';
    if (sec >= 0 && sec < 60) rel = Math.round(sec) + 's ago';
    else if (sec >= 0 && sec < 3600) rel = Math.round(sec / 60) + ' min ago';
    else if (sec >= 0 && sec < 86400) rel = Math.round(sec / 3600) + ' h ago';
    return d.toLocaleString([], { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit', second: '2-digit' }) + (rel ? ' (' + rel + ')' : '');
  }
  function site(c) {
    if (!c) return '';
    var s = (c.file || '') + (c.line ? ':' + c.line : '');
    return s;
  }
  function state(kind, title, text) {
    var e = h('div', 'state' + (kind === 'err' ? ' err' : ''));
    if (kind === 'load') { add(e, [h('span', 'spin'), 'Loading']); return e; }
    add(e, [h('strong', '', title), text]);
    return e;
  }
  function mount(node) { app.textContent = ''; add(app, node); }
  function fail(err) { mount(state('err', 'Could not load data', (err && err.message) || 'Check that the Lantern API is reachable.')); }
  function sevClass(s) { return 'pill sev-' + String(s || '').toLowerCase(); }
  function statusPill(code) {
    var n = Number(code), c = n >= 500 ? 'bad' : n >= 400 ? 'warn' : n >= 200 && n < 400 ? 'ok' : '';
    return h('span', 'pill ' + c, code);
  }
  function setTitle(t) { try { D.title = t + ' | Lantern'; } catch (e) { } }
  function pref(k, v) {
    try { if (v === undefined) return localStorage.getItem('lantern.' + k); localStorage.setItem('lantern.' + k, v); } catch (e) { }
    return null;
  }

  /* ---------- trace rows (shared) ---------- */
  function traceTable(rows) {
    if (!rows || !rows.length) return state('empty', 'No traces yet', 'Make a request to your app and it will show up here.');
    var maxd = Math.max.apply(null, rows.map(function (r) { return r.duration_ms || 0; })) || 1;
    var tb = h('tbody');
    rows.forEach(function (r) {
      var slow = r.duration_ms >= SLOW_MS;
      var bw = Math.max(2, Math.round((r.duration_ms / maxd) * 80));
      var a = h('a', '', r.route || r.path || r.id);
      a.href = link('/traces/' + encodeURIComponent(r.id));
      var tr = h('tr', '', [
        h('td', 'num hide-s', when(r.started_at)),
        h('td', 'pathc', [h('span', 'meth', r.method), a, h('small', 'mono', r.path + (r.query_count !== undefined ? '' : ''))]),
        h('td', '', statusPill(r.status)),
        h('td', 'num', h('div', 'durbar', [slow ? h('span', 'pill warn', 'slow') : null, h('i', slow ? 'slow' : '', ''), ms(r.duration_ms)])),
        h('td', 'num', r.query_count + ' / ' + ms(r.query_total_ms)),
        h('td', 'num', r.issue_count ? h('span', 'pill warn', r.issue_count + (r.issue_count === 1 ? ' issue' : ' issues')) : h('span', 'pill', 'none')),
        h('td', 'num hide-s', mem(r.peak_memory))
      ]);
      tr.querySelector('i').style.width = bw + 'px';
      tb.appendChild(tr);
    });
    var t = h('table', '', [
      h('thead', '', h('tr', '', ['Started', 'Request', 'Status', 'Duration', 'Queries', 'Issues', 'Peak memory'].map(function (x, i) {
        var th = h('th', (i === 0 || i === 6 ? 'hide-s ' : '') + (i >= 3 ? 'num' : ''), x); return th;
      }))), tb]);
    return h('div', 'tbl', t);
  }

  /* ---------- traces page ---------- */
  function pageTraces() {
    var timer = null;
    var f = { status: '', min_ms: '', has_issues: false, limit: '50' };
    var body = h('div'), live = h('button', 'btn-live', 'Auto-refresh'), count = h('span', 'sub');
    live.type = 'button'; live.setAttribute('aria-pressed', 'false');
    function field(label, el) { return h('label', '', [label, el]); }
    var st = h('input'); st.type = 'text'; st.placeholder = 'any'; st.setAttribute('inputmode', 'numeric');
    var mn = h('input'); mn.type = 'number'; mn.min = '0'; mn.placeholder = '0';
    var hi = h('input'); hi.type = 'checkbox';
    var lim = h('select', '', ['25', '50', '100', '200'].map(function (v) { var o = h('option', '', v); o.value = v; return o; }));
    lim.value = f.limit;
    var tools = h('div', 'bar-tools', [
      field('Status code', st), field('Slower than (ms)', mn), field('Rows', lim),
      h('label', 'chk', [hi, 'Only with issues']), h('span', 'grow'), count, live
    ]);
    function read() { f.status = st.value.trim(); f.min_ms = mn.value; f.has_issues = hi.checked; f.limit = lim.value; }
    function load(quiet) {
      read();
      if (!quiet) { body.textContent = ''; add(body, state('load')); }
      get('/traces', f).then(function (j) {
        body.textContent = '';
        var rows = j.data || [];
        count.textContent = rows.length + ' shown';
        if (!rows.length && (f.status || f.min_ms || f.has_issues)) add(body, state('empty', 'No traces match', 'Loosen the filters to see more requests.'));
        else add(body, traceTable(rows));
      }).catch(function (e) { body.textContent = ''; add(body, state('err', 'Could not load traces', e.message)); });
    }
    function setLive(on) {
      live.setAttribute('aria-pressed', on ? 'true' : 'false');
      live.textContent = on ? 'Auto-refresh on' : 'Auto-refresh';
      clearInterval(timer);
      if (on) timer = setInterval(function () { if (!D.hidden) load(true); }, 5000);
      pref('live', on ? '1' : '0');
    }
    [st, mn].forEach(function (e) { e.addEventListener('change', function () { load(); }); });
    hi.addEventListener('change', function () { load(); });
    lim.addEventListener('change', function () { load(); });
    live.addEventListener('click', function () { setLive(live.getAttribute('aria-pressed') !== 'true'); });
    setTitle('Traces');
    mount([h('h1', '', 'Traces'), h('p', 'sub', 'Recent requests, newest first. Open one to see where the time went.'), tools, body]);
    load();
    if (pref('live') === '1') setLive(true);
  }

  /* ---------- trace detail ---------- */
  function spanKind(s) { return TYPES.indexOf(s.type) >= 0 ? s.type : 'other'; }
  function layout(root) {
    var all = [];
    (function walk(s, depth, start, parent) {
      s._depth = depth;
      s._start = s.start_ms != null ? Number(s.start_ms) : start;
      s._dur = Number(s.duration_ms) || 0;
      s._parent = parent;
      all.push(s);
      var cur = s._start;
      (s.children || []).forEach(function (c) {
        walk(c, depth + 1, Math.min(cur, s._start + s._dur), s);
        cur = c._start + c._dur;
      });
    })(root, 0, 0, null);
    var end = 0;
    all.forEach(function (s) { end = Math.max(end, s._start + s._dur); });
    return { all: all, total: Math.max(end, Number(root.duration_ms) || 0, 0.001) };
  }
  function matchIssues(spans, issues) {
    var map = {};
    (issues || []).forEach(function (is) {
      var fp = is.meta && is.meta.fingerprint;
      spans.forEach(function (s) {
        var m = s.meta || {}, cs = s.call_site || {}, ic = is.call_site || {};
        var hit = false;
        if (s.type === 'query') {
          if (fp && m.fingerprint === fp) hit = true;
          else if (is.key && m.fingerprint && String(is.key).indexOf(m.fingerprint) >= 0) hit = true;
          else if (ic.file && cs.file === ic.file && cs.line === ic.line && (is.type !== 'slow_query' || true)) hit = true;
        }
        if (hit) (map[s.id] = map[s.id] || []).push(is);
      });
    });
    return map;
  }
  function kv(rows) {
    var dl = h('dl', 'kv');
    rows.forEach(function (r) { if (r[1] != null && r[1] !== '') { dl.appendChild(h('dt', '', r[0])); dl.appendChild(h('dd', '', r[1])); } });
    return dl;
  }
  function jsonText(v) { try { return JSON.stringify(v, null, 2); } catch (e) { return String(v); } }
  function spanDetail(s, issues) {
    var m = s.meta || {}, cs = s.call_site || {}, d = h('div', 'detail');
    var csText = site(cs);
    var extra = [];
    if (cs['class'] || cs['function']) extra.push((cs['class'] ? cs['class'] + '::' : '') + (cs['function'] || ''));
    if (cs.view) extra.push('view ' + cs.view);
    add(d, kv([
      ['Type', s.type], ['Total', ms(s.duration_ms)], ['Self', ms(s.self_ms)],
      ['Memory', s.memory_delta != null ? dmem(s.memory_delta) : ''],
      ['Call site', csText ? h('span', 'mono', csText) : ''],
      ['Context', extra.length ? h('span', 'mono', extra.join('  ')) : ''],
      ['Failed', m.failed ? 'Yes' : ''],
      ['Filament', m.filament ? (typeof m.filament === 'object' ? Object.keys(m.filament).map(function (k) { return k + ': ' + m.filament[k]; }).join(', ') : String(m.filament)) : '']
    ]));
    if (m.sql) { add(d, [h('h3', '', 'SQL' + (m.time_ms != null ? ' (' + ms(m.time_ms) + ')' : '')), h('pre', '', m.sql)]); }
    if (m.bindings && m.bindings.length) add(d, [h('h3', '', 'Bindings'), h('pre', '', jsonText(m.bindings))]);
    if (m.fingerprint) add(d, kv([['Fingerprint', h('span', 'mono', m.fingerprint)]]));
    var rest = {};
    Object.keys(m).forEach(function (k) { if (['sql', 'bindings', 'fingerprint', 'time_ms', 'failed', 'filament'].indexOf(k) < 0) rest[k] = m[k]; });
    if (Object.keys(rest).length) add(d, [h('h3', '', 'Details'), h('pre', '', jsonText(rest))]);
    if (issues && issues.length) {
      add(d, h('h3', '', 'Issues on this span'));
      issues.forEach(function (is) { add(d, h('p', '', [h('span', sevClass(is.severity), is.severity), ' ', is.title, is.suggestion ? ' - ' + is.suggestion : ''])); });
    }
    return d;
  }
  function waterfall(root, issues) {
    var L = layout(root), flagged = matchIssues(L.all, issues);
    var collapsed = {}, open = {}, wrap = h('div', 'wf');
    // collapse leaf-heavy noise by default: nothing collapsed; user decides
    function visible(s) { for (var p = s._parent; p; p = p._parent) if (collapsed[p.id]) return false; return true; }
    function draw() {
      wrap.textContent = '';
      L.all.forEach(function (s) {
        if (!visible(s)) return;
        var kind = spanKind(s), kids = (s.children || []).length, fl = flagged[s.id];
        var caret = h('button', 'caret' + (kids ? '' : ' leaf'), collapsed[s.id] ? '▸' : '▾');
        caret.type = 'button'; caret.setAttribute('aria-label', kids ? (collapsed[s.id] ? 'Expand' : 'Collapse') : '');
        if (!kids) caret.tabIndex = -1;
        caret.addEventListener('click', function (ev) { ev.stopPropagation(); collapsed[s.id] = !collapsed[s.id]; draw(); });
        var m = s.meta || {};
        var lbl = h('div', 'lbl', [
          h('span', '', ''), caret,
          h('span', 'chip t-' + kind, [h('i'), s.type]),
          h('span', 'nm' + (s.type === 'query' ? ' mono' : ''), s.name),
          fl ? h('span', 'dot') : null,
          m.filament ? h('span', 'tag', 'filament') : null,
          s.type === 'livewire' ? h('span', 'tag', 'livewire') : null,
          m.failed ? h('span', 'pill bad', 'failed') : null
        ]);
        lbl.firstChild.style.width = (s._depth * 14) + 'px';
        lbl.firstChild.style.flex = 'none';
        if (fl) lbl.querySelector('.dot').title = fl.map(function (x) { return x.title; }).join('; ');
        var bar = h('b'); bar.style.left = (s._start / L.total * 100).toFixed(3) + '%'; bar.style.width = Math.max(0.3, s._dur / L.total * 100).toFixed(3) + '%';
        var track = h('div', 'track t-' + kind, bar);
        var dur = h('div', 'dur', ms(s._dur));
        if (s.memory_delta) dur.title = 'Memory ' + dmem(s.memory_delta);
        var row = h('div', 'row' + (fl ? ' flag' : '') + (open[s.id] ? ' sel' : ''), [lbl, track, dur]);
        row.tabIndex = 0; row.setAttribute('role', 'button'); row.setAttribute('aria-expanded', open[s.id] ? 'true' : 'false');
        function tog() { open[s.id] = !open[s.id]; draw(); }
        row.addEventListener('click', tog);
        row.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); tog(); } });
        wrap.appendChild(row);
        if (open[s.id]) wrap.appendChild(spanDetail(s, fl));
      });
    }
    draw();
    var present = {};
    L.all.forEach(function (s) { present[spanKind(s)] = 1; });
    var legend = h('div', 'legend', TYPES.concat(['other']).filter(function (t) { return present[t]; }).map(function (t) { return h('span', 't-' + t, [h('i'), t]); }));
    var expand = h('button', '', 'Expand all'), coll = h('button', '', 'Collapse all');
    expand.type = coll.type = 'button';
    expand.addEventListener('click', function () { collapsed = {}; draw(); });
    coll.addEventListener('click', function () { collapsed = {}; L.all.forEach(function (s) { if (s._depth > 0 && (s.children || []).length) collapsed[s.id] = true; }); draw(); });
    add(legend, [h('span', 'sp'), h('span', '', 'Total ' + ms(L.total)), expand, coll]);
    return h('div', 'panel', [legend, wrap]);
  }
  function issuesInTrace(issues) {
    if (!issues || !issues.length) return null;
    var box = h('div', 'issues-in');
    issues.forEach(function (is) {
      var a = h('a', '', is.title); a.href = link('/issues/' + encodeURIComponent(is.key));
      box.appendChild(h('div', '', [
        h('div', '', [h('span', sevClass(is.severity), is.severity), ' ', a, h('span', 'mono', '  x' + is.count + ' / ' + ms(is.total_ms))]),
        is.call_site ? h('div', 'mono', site(is.call_site)) : null,
        is.suggestion ? h('div', '', is.suggestion) : null
      ]));
    });
    return h('div', 'panel', [h('h2', '', 'Issues found in this trace'), box]);
  }
  function pageTrace() {
    setTitle('Trace');
    mount(state('load'));
    get('/traces/' + encodeURIComponent(cfg.id)).then(function (j) {
      var t = j.data;
      if (!t) throw new Error('The trace was not found. It may have been pruned.');
      setTitle(t.method + ' ' + (t.route || t.path));
      var back = h('a', 'crumb', 'All traces'); back.href = link('/');
      var stats = h('div', 'stats', [
        [ms(t.duration_ms), 'duration'], [String(t.query_count), 'queries'], [ms(t.query_total_ms), 'in queries'],
        [String(t.issue_count || 0), 'issues'], [mem(t.peak_memory), 'peak memory']
      ].map(function (p) { return h('div', '', [h('b', '', p[0]), h('span', '', p[1])]); }));
      mount([
        back,
        h('h1', 'mono', [t.method + ' ' + t.path, ' ', statusPill(t.status)]),
        h('p', 'sub', (t.route ? 'Route ' + t.route + '. ' : '') + when(t.started_at)),
        stats,
        issuesInTrace(t.issues),
        t.tree ? waterfall(t.tree, t.issues) : state('empty', 'No spans recorded', 'This trace has no timeline data.')
      ]);
    }).catch(fail);
  }

  /* ---------- issues ---------- */
  function pageIssues() {
    setTitle('Issues');
    var f = { type: '', severity: '', sort: 'total_ms' };
    var body = h('div');
    function sel(opts, val) {
      var s = h('select', '', opts.map(function (o) { var e = h('option', '', o[1]); e.value = o[0]; return e; }));
      s.value = val; return s;
    }
    var ty = sel([['', 'All types'], ['n_plus_one', 'N+1 queries'], ['duplicate_query', 'Duplicate queries'], ['slow_query', 'Slow queries'], ['missing_index', 'Missing index'], ['memory', 'Memory']], '');
    var sv = sel([['', 'All severities'], ['critical', 'Critical'], ['high', 'High'], ['medium', 'Medium'], ['low', 'Low']], '');
    var so = sel([['total_ms', 'Total cost'], ['occurrences', 'Occurrences'], ['max_ms', 'Slowest'], ['last_seen', 'Most recent']], 'total_ms');
    var tools = h('div', 'bar-tools', [h('label', '', ['Type', ty]), h('label', '', ['Severity', sv]), h('label', '', ['Rank by', so])]);
    function load() {
      f.type = ty.value; f.severity = sv.value; f.sort = so.value;
      body.textContent = ''; add(body, state('load'));
      get('/issues', f).then(function (j) {
        body.textContent = '';
        var rows = j.data || [];
        if (!rows.length) return add(body, state('empty', 'No issues found', 'Lantern has not flagged anything. Keep browsing your app, or loosen the filters.'));
        var list = h('div', 'icards');
        rows.forEach(function (r, i) { list.appendChild(issueCard(r, i + 1)); });
        add(body, list);
      }).catch(function (e) { body.textContent = ''; add(body, state('err', 'Could not load issues', e.message)); });
    }
    [ty, sv, so].forEach(function (e) { e.addEventListener('change', load); });
    mount([h('h1', '', 'Issues'), h('p', 'sub', 'Problems grouped across requests and ranked by the time they cost.'), tools, body]);
    load();
  }
  function issueCard(r, rank) {
    var a = h('a', 'icard');
    a.href = link('/issues/' + encodeURIComponent(r.key));
    add(a, [
      h('div', 'rk', rank),
      h('div', '', [h('h3', '', r.title), h('div', 'meta', [
        h('span', sevClass(r.severity), r.severity), h('span', '', String(r.type).replace(/_/g, ' ')),
        h('span', '', r.occurrences + (r.occurrences === 1 ? ' request' : ' requests')),
        r.call_site ? h('span', 'mono', site(r.call_site)) : null
      ])]),
      h('div', 'cost', [h('b', '', ms(r.total_ms)), h('span', '', 'total, max ' + ms(r.max_ms))]),
      r.suggestion ? h('p', 'fix', r.suggestion) : null
    ]);
    return a;
  }
  function pageIssue() {
    setTitle('Issue');
    mount(state('load'));
    get('/issues/' + encodeURIComponent(cfg.id)).then(function (j) {
      var r = j.data;
      if (!r) throw new Error('The issue was not found. It may have been pruned.');
      setTitle(r.title);
      var back = h('a', 'crumb', 'All issues'); back.href = link('/issues');
      var stats = h('div', 'stats', [
        [ms(r.total_ms), 'total cost'], [ms(r.max_ms), 'slowest'], [String(r.occurrences), 'requests'], [String(r.count_total), 'repeats in total']
      ].map(function (p) { return h('div', '', [h('b', '', p[0]), h('span', '', p[1])]); }));
      mount([
        back,
        h('h1', '', r.title),
        h('p', 'sub', [h('span', sevClass(r.severity), r.severity), ' ', String(r.type).replace(/_/g, ' '), r.call_site ? ' at ' : '', r.call_site ? h('span', 'mono', site(r.call_site)) : '']),
        stats,
        r.suggestion ? h('div', 'sugg', r.suggestion) : null,
        h('p', 'sub', 'First seen ' + when(r.first_seen) + '. Last seen ' + when(r.last_seen) + '.'),
        h('h2', '', 'Recent traces'),
        traceTable(r.recent_traces)
      ]);
    }).catch(fail);
  }

  var pages = { traces: pageTraces, trace: pageTrace, issues: pageIssues, issue: pageIssue };
  (pages[cfg.page] || pageTraces)();
})();
