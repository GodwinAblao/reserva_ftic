import './stimulus_bootstrap.js';
import './styles/app.css';

/* ═══════════════════════════════════════════════════════════════
   RESERVA FTIC  —  Global UX + Performance Layer
   ═══════════════════════════════════════════════════════════════
   Key design goals:
   · One fetch per interval cycle, never concurrent duplicates
   · Hash-based diffing — DOM only touched when data changes
   · No setTimeout delays on DOM writes (instant swap)
   · Browser cache respected via Cache-Control headers
   · Safety timeout on every async operation
═══════════════════════════════════════════════════════════════ */


/* ─────────────────────────────────────────────────────────────
   Shared helpers
───────────────────────────────────────────────────────────── */
/** Lightweight XOR hash — fast enough for small JSON strings */
function hashStr(s) {
    let h = 0x811c9dc5;
    for (let i = 0; i < s.length; i++) {
        h ^= s.charCodeAt(i);
        h = (h * 0x01000193) >>> 0;
    }
    return h;
}

function esc(s) {
    return String(s)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;');
}

/** apiFetch — fetch with abort timeout. Returns JSON or null. */
function apiFetch(url, timeoutMs = 10000) {
    const ctrl = new AbortController();
    const tid  = setTimeout(() => ctrl.abort(), timeoutMs);
    return fetch(url, {
        credentials: 'same-origin',
        headers:     { 'X-Requested-With': 'XMLHttpRequest' },
        signal:      ctrl.signal,
    })
    .then(r => r.ok ? r.json() : null)
    .catch(() => null)
    .finally(() => clearTimeout(tid));
}

/** Swap el.innerHTML only when content actually changed (hash diff) */
function diffSet(el, html) {
    const h = hashStr(html);
    if (el._rpHash === h) return false;   // unchanged — skip DOM write
    el._rpHash  = h;
    el.innerHTML = html;
    return true;
}


/* ─────────────────────────────────────────────────────────────
   1. Navigation Progress Bar
   · Instant start on click
   · Finishes on DOMContentLoaded / load / pageshow (bfcache)
   · 8-second hard safety auto-finish
───────────────────────────────────────────────────────────── */
const NavProgress = (() => {
    const bar = document.createElement('div');
    bar.id = 'nav-progress-bar';
    Object.assign(bar.style, {
        position:      'fixed',
        top:           '0', left: '0',
        height:        '3px', width: '0',
        background:    'linear-gradient(90deg,#f59e0b,#fcd34d)',
        zIndex:        '999999',
        pointerEvents: 'none',
        borderRadius:  '0 2px 2px 0',
        opacity:       '0',
        transition:    'none',
        willChange:    'width, opacity',
    });
    document.documentElement.appendChild(bar);

    let _t = null, _s = null, _raf = null, _on = false;

    function start() {
        if (_raf) cancelAnimationFrame(_raf);
        clearTimeout(_t); clearTimeout(_s);
        _on = true;

        bar.style.transition = 'none';
        bar.style.width      = '0';
        bar.style.opacity    = '1';

        _raf = requestAnimationFrame(() => {
            bar.style.transition = 'width 0.25s ease';
            bar.style.width      = '55%';
            _t = setTimeout(() => { bar.style.width = '88%'; }, 600);
        });

        _s = setTimeout(finish, 8000);
    }

    function finish() {
        if (!_on) return;
        _on = false;
        clearTimeout(_t); clearTimeout(_s);
        bar.style.transition = 'width 0.12s ease';
        bar.style.width      = '100%';
        setTimeout(() => {
            bar.style.transition = 'opacity 0.25s ease';
            bar.style.opacity    = '0';
            setTimeout(() => { bar.style.width = '0'; }, 280);
        }, 140);
    }

    document.addEventListener('click', e => {
        const a = e.target.closest('a[href]');
        if (!a) return;
        const href = a.getAttribute('href') || '';
        if (!href || href.startsWith('#') || href.startsWith('javascript:') ||
            a.target === '_blank' || e.ctrlKey || e.metaKey || e.shiftKey) return;
        try {
            if (new URL(href, location.href).origin !== location.origin) return;
        } catch (_) { return; }
        start();
    });

    // Finish on initial load and every Turbo navigation
    finish();
    window.addEventListener('load', finish);
    window.addEventListener('pageshow', finish);
    document.addEventListener('turbo:load', finish);

    return { start, finish };
})();


/* ─────────────────────────────────────────────────────────────
   2. Content area fade-in (GPU composited — no layout thrash)
───────────────────────────────────────────────────────────── */
(() => {
    function fadeIn() {
        const el = document.querySelector('.content-area');
        if (!el) return;
        el.style.cssText += ';opacity:0;transform:translateY(4px);transition:opacity 0.18s ease,transform 0.18s ease;will-change:opacity,transform';
        requestAnimationFrame(() => requestAnimationFrame(() => {
            el.style.opacity   = '1';
            el.style.transform = 'translateY(0)';
        }));
    }
    // Run on initial load and every Turbo navigation
    fadeIn();
    document.addEventListener('turbo:load', fadeIn);
})();


/* ─────────────────────────────────────────────────────────────
   3. AJAX form submissions
   · WeakSet dedup (no double-submit)
   · NavProgress integration
   · data-ajax="true" + optional data-ajax-reload="ms"
───────────────────────────────────────────────────────────── */
(() => {
    const flying = new WeakSet();

    function flash(form, msg, type) {
        form.parentNode?.querySelectorAll('.ajax-flash').forEach(n => n.remove());
        const d = document.createElement('div');
        d.className   = 'ajax-flash ajax-flash-' + type;
        d.textContent = msg;
        form.insertAdjacentElement('beforebegin', d);
        if (type === 'success') setTimeout(() => d.remove(), 4500);
    }

    document.addEventListener('submit', async e => {
        const form = e.target;
        if (!form.dataset.ajax) return;
        if (flying.has(form)) { e.preventDefault(); return; }
        e.preventDefault();

        flying.add(form);
        const btn = form.querySelector('[type="submit"]');
        const orig = btn?.textContent ?? '';
        if (btn) { btn.disabled = true; btn.textContent = 'Saving…'; }
        NavProgress.start();

        try {
            const res = await fetch(form.action, {
                method:      form.method || 'POST',
                body:        new FormData(form),
                headers:     { 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
            });
            NavProgress.finish();

            const ct = res.headers.get('content-type') || '';
            if (ct.includes('application/json')) {
                const d = await res.json();
                if (d.success) {
                    flash(form, d.message || 'Saved successfully.', 'success');
                    const delay = parseInt(form.dataset.ajaxReload, 10);
                    if (delay) setTimeout(() => location.reload(), delay);
                } else {
                    flash(form, d.message || 'Something went wrong.', 'error');
                }
            } else {
                location.reload();
            }
        } catch (_) {
            NavProgress.finish();
            flash(form, 'Network error. Please try again.', 'error');
        } finally {
            flying.delete(form);
            if (btn) { btn.disabled = false; btn.textContent = orig; }
        }
    });
})();


/* ─────────────────────────────────────────────────────────────
   4. Right-panel live updates
   · Dedicated /api/recent-reservations — 1 DB query
   · Per-panel in-flight flag prevents overlapping fetches
   · Hash-diff: DOM write only when data actually changed
   · Instant swap — zero artificial delay
   · "NEW" badge when new items arrive
   · Fixed 15 s interval — no back-off (back-off caused stuck state)
   · 8 s skeleton timeout: if first fetch fails, shows error msg
───────────────────────────────────────────────────────────── */
(() => {
    const POLL_MS   = 15000;
    const MAX_ITEMS = 8;

    const SC = {
        Pending:   ['#fef3c7','#92400e'],
        Approved:  ['#dcfce7','#166534'],
        Rejected:  ['#fee2e2','#991b1b'],
        Cancelled: ['#f3f4f6','#6b7280'],
        _:         ['#e0f2fe','#075985'],
    };

    let lastHash  = null;   // null ≠ any hash — safe init
    let lastCount = -1;
    let fetching  = false;  // in-flight guard for reservation panel

    /* ── card builders ── */
    function buildResvCard(r) {
        const [bg, tc] = SC[r.status] ?? SC._;
        return `<div class="admin-notif-card">`
            + `<div style="display:flex;justify-content:space-between;align-items:flex-start;gap:6px;margin-bottom:3px">`
            + `<div class="admin-notif-card-title" style="flex:1;min-width:0">${esc(r.facilityName||'Facility')}</div>`
            + `<span class="rp-status-badge" style="--bg:${bg};--tc:${tc}">${esc(r.status||'')}</span>`
            + `</div>`
            + `<div class="admin-notif-card-desc">${esc(r.userName||'User')} · ${esc(r.date||'')}${r.time?' at '+esc(r.time):''}</div>`
            + `</div>`;
    }

    function buildMentorCard(r) {
        return `<div class="admin-notif-card">`
            + `<div class="admin-notif-card-title">${esc(r.title||'Mentoring Request')}</div>`
            + `<div class="admin-notif-card-desc">${esc(r.student||'')}${r.date?' · '+esc(r.date):''}</div>`
            + (r.time ? `<div style="font-size:11px;color:#9ca3af;margin-top:2px">${esc(r.time)}</div>` : '')
            + `</div>`;
    }

    function buildLbCard(m, i) {
        const medals = ['🥇','🥈','🥉'];
        return `<div class="admin-notif-card" style="display:flex;align-items:center;gap:9px">`
            + `<span style="font-size:19px;flex-shrink:0;line-height:1">${medals[i]||''}</span>`
            + `<div style="flex:1;min-width:0">`
            + `<div class="admin-notif-card-title">${esc(m.name||'Mentor')}</div>`
            + `<div class="admin-notif-card-desc" style="font-size:11px">${esc(m.specialization||'')}</div>`
            + `</div></div>`;
    }

    const EMPTY = (msg) =>
        `<div style="text-align:center;color:#9ca3af;font-size:12px;padding:18px 0">${msg}</div>`;

    /* ── "NEW" badge ── */
    function pulseBadge(panel) {
        const title = panel.previousElementSibling;
        if (!title) return;
        let b = title.querySelector('.rp-new-badge');
        if (!b) {
            b = document.createElement('span');
            b.className   = 'rp-new-badge';
            b.textContent = 'NEW';
            title.appendChild(b);
        }
        b.classList.remove('rp-badge-pop');
        void b.offsetWidth;
        b.classList.add('rp-badge-pop');
        setTimeout(() => b?.remove(), 4000);
    }

    /* ── Reservation panel poll ── */
    function pollReservations(url) {
        const panel = document.getElementById('adminNotifList');
        if (!panel || fetching) return;
        fetching = true;

        apiFetch(url).then(data => {
            fetching = false;
            if (!data) {
                // Only show error if panel still shows skeletons (first load failed)
                if (lastCount === -1) {
                    panel.innerHTML = EMPTY('Could not load reservations — retrying…');
                }
                return;
            }

            const list = (data.recentReservations ?? []).slice(0, MAX_ITEMS);
            const html = list.length ? list.map(buildResvCard).join('') : EMPTY('No recent reservations');
            const h    = hashStr(html);

            if (h !== lastHash) {
                if (lastCount >= 0 && list.length > lastCount) pulseBadge(panel);
                lastHash  = h;
                lastCount = list.length;
                panel.innerHTML = html;   // direct swap — zero delay
            } else {
                lastCount = list.length;
            }
        });
    }

    /* ── Mentoring panel (one-shot on load, no repeat needed) ── */
    function loadMentoringPanel(url) {
        const reqPanel = document.getElementById('adminMentoringReqList');
        const lbPanel  = document.getElementById('adminLeaderboardList');
        if (!reqPanel && !lbPanel) return;

        apiFetch(url).then(data => {
            if (!data) {
                if (reqPanel) reqPanel.innerHTML = EMPTY('Could not load — retrying…');
                if (lbPanel)  lbPanel.innerHTML  = EMPTY('Could not load — retrying…');
                // Retry once after 5 s if first attempt failed
                setTimeout(() => loadMentoringPanel(url), 5000);
                return;
            }
            if (reqPanel) {
                const reqs = (data.requests ?? []).slice(0, 5);
                reqPanel.innerHTML = reqs.length ? reqs.map(buildMentorCard).join('') : EMPTY('No mentoring requests');
            }
            if (lbPanel) {
                const lb = (data.leaderboard ?? []).slice(0, 5);
                lbPanel.innerHTML = lb.length ? lb.map(buildLbCard).join('') : EMPTY('No leaderboard data');
            }
        });
    }

    /* ── Panel state — reset on every navigation ── */
    let _pollTimer    = null;
    let _guardTimer   = null;
    let _clearGuard   = null;

    function resetPanelState() {
        clearInterval(_pollTimer);
        clearTimeout(_guardTimer);
        clearInterval(_clearGuard);
        _pollTimer = _guardTimer = _clearGuard = null;
        lastHash  = null;
        lastCount = -1;
        fetching  = false;
    }

    /* ── Boot — runs on every page (initial + Turbo navigations) ── */
    function boot() {
        resetPanelState();

        const recentMeta    = document.querySelector('meta[name="admin-recent-api"]');
        const mentoringMeta = document.querySelector('meta[name="admin-mentoring-api"]');

        if (recentMeta) {
            const url = recentMeta.content;

            // Immediate first fetch
            pollReservations(url);

            // Safety: replace stuck skeletons after 9 s
            _guardTimer = setTimeout(() => {
                const panel = document.getElementById('adminNotifList');
                if (panel && panel.querySelector('.rp-skeleton')) {
                    panel.innerHTML = EMPTY('Could not load — retrying…');
                    lastCount = -1;
                }
            }, 9000);

            // Fixed 15 s repeating poll
            _pollTimer = setInterval(() => pollReservations(url), POLL_MS);

            // Cancel guard once first data lands
            _clearGuard = setInterval(() => {
                if (lastCount !== -1) {
                    clearTimeout(_guardTimer);
                    clearInterval(_clearGuard);
                }
            }, 300);
        }

        if (mentoringMeta) {
            loadMentoringPanel(mentoringMeta.content);
        }
    }

    /* ── turbo:load fires on EVERY navigation — initial page + every
       sidebar click. This is the single entry point for boot(). ── */
    document.addEventListener('turbo:load', boot);

    // Fallback for non-Turbo environments: turbo:load won't fire so
    // boot() must be called directly. Check readyState to handle both
    // synchronous and deferred execution.
    if (typeof Turbo === 'undefined') {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', boot);
        } else {
            boot();
        }
    }
})();


/* ─────────────────────────────────────────────────────────────
   6. Reservation Monitoring page — live updates
   · Polls /api/reservation-monitoring every 15 s
   · Updates table body, stat cards, and Chart.js chart
   · Hash-diff: only touches DOM when data changed
   · Turbo-safe: resets on every navigation via turbo:load
   · No full-page reload ever needed
───────────────────────────────────────────────────────────── */
(() => {
    const POLL_MS = 15000;

    let _timer    = null;
    let _fetching = false;
    let _lastHash = null;

    /* ── Build a single <tr> string ── */
    function buildRow(r) {
        return `<tr>`
            + `<td>${esc(r.name)}</td>`
            + `<td>${esc(r.email)}</td>`
            + `<td>${esc(r.role)}</td>`
            + `<td>${esc(r.facility)}</td>`
            + `<td>${esc(r.time)}</td>`
            + `</tr>`;
    }

    /* ── Animate a stat value change ── */
    function setStatVal(id, val) {
        const el = document.getElementById(id);
        if (!el) return;
        const next = String(val);
        if (el.textContent.trim() === next) return;   // trim Twig whitespace before comparing
        el.style.transition = 'opacity 0.15s';
        el.style.opacity    = '0';
        setTimeout(() => { el.textContent = next; el.style.opacity = '1'; }, 160);
    }

    /* ── Update Chart.js chart — delegates to page-level resize helper ──
       Deferred 0 ms so the inline <script> has always run before this fires ── */
    function updateChart(facilityCounts) {
        setTimeout(() => {
            if (typeof window._rmChartResize === 'function') {
                window._rmChartResize(facilityCounts);
            }
        }, 0);
    }

    /* ── Single poll cycle ── */
    function poll(url) {
        const tbody = document.getElementById('rmTableBody');
        if (!tbody || _fetching) return;
        _fetching = true;

        apiFetch(url).then(data => {
            _fetching = false;
            if (!data) return;

            const rows = data.reservations   ?? [];
            const sc   = data.statusCounts   ?? {};
            const fc   = data.facilityCounts ?? {};

            /* Table — always write on first poll (_lastHash===null), then hash-diff */
            const html = rows.length
                ? rows.map(buildRow).join('')
                : `<tr><td colspan="5" style="text-align:center;color:#9ca3af;padding:24px;">No reservations yet.</td></tr>`;

            const h = hashStr(html);
            if (h !== _lastHash) {
                _lastHash       = h;
                tbody.innerHTML = html;
            }

            /* Stat cards — always write values so Twig-rendered whitespace doesn't stick */
            const total = (sc.Approved ?? 0) + (sc.Pending ?? 0)
                        + (sc.Cancelled ?? 0) + (sc.Rejected ?? 0)
                        + (sc.AwaitingFacilitySelection ?? 0) + (sc.Suggested ?? 0);
            setStatVal('rmStatCancelled', sc.Cancelled ?? 0);
            setStatVal('rmStatPending',   sc.Pending   ?? 0);
            setStatVal('rmStatTotal',     total);

            /* Chart */
            updateChart(fc);

        }).catch(() => { _fetching = false; });
    }

    /* ── Boot / reset on every navigation ── */
    function boot() {
        clearInterval(_timer);
        _timer    = null;
        _fetching = false;
        _lastHash = null;   // null forces DOM write on next poll regardless of data

        const meta = document.querySelector('meta[name="rm-api"]');
        if (!meta) return;   // not on the reservation monitoring page

        const url = meta.content;
        poll(url);                                        // immediate first fetch
        _timer = setInterval(() => poll(url), POLL_MS);  // then every 15 s
    }

    // turbo:load fires on every Turbo navigation (and initial visit)
    document.addEventListener('turbo:load', boot);

    // DOMContentLoaded covers initial hard load when turbo:load hasn't fired yet
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();


/* ─────────────────────────────────────────────────────────────
   5. Dashboard stat cards — shared fetch with right-panel cycle
   · Reuses the same apiFetch (deduped) for the stats endpoint
   · Animates only changed values
   · 30 s refresh (down from 60 s) — safe because stats endpoint
     now uses Cache-Control: max-age=15 so the browser caches it
───────────────────────────────────────────────────────────── */
(() => {
    const STAT_MAP = {
        'stat-total-reservations': ['reservations','total'],
        'stat-approved':           ['reservations','approved'],
        'stat-pending':            ['reservations','pending'],
        'stat-mentors':            ['mentoring','totalMentors'],
        'stat-today-reservations': ['reservations','today'],
        'stat-appointments':       ['mentoring','appointments','total'],
    };

    function tick(url) {
        apiFetch(url).then(data => {
            if (!data) return;
            for (const [id, path] of Object.entries(STAT_MAP)) {
                const el = document.getElementById(id);
                if (!el) continue;
                let v = data;
                for (const k of path) v = v?.[k];
                if (v == null) continue;
                const s = String(v);
                if (el.textContent === s) continue;
                // Flip animation — GPU composited, no layout
                el.style.transition = 'opacity 0.15s';
                el.style.opacity    = '0';
                setTimeout(() => { el.textContent = s; el.style.opacity = '1'; }, 160);
            }
        });
    }

    function initStats() {
        const meta = document.querySelector('meta[name="stats-api"]');
        if (!meta || !document.getElementById('stat-total-reservations')) return;
        setInterval(() => tick(meta.content), 30000);
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initStats);
    } else {
        initStats();
    }
})();
