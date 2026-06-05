import './stimulus_bootstrap.js';
import './styles/app.css';

/* ── Global: Reservation detail modal (used by both admin & user sidebars) ── */
window.showResvDetail = function(r) {
    const SC = {
        Pending:   ['#fef3c7','#92400e'],
        Approved:  ['#dcfce7','#166534'],
        Rejected:  ['#fee2e2','#991b1b'],
        Cancelled: ['#f3f4f6','#6b7280'],
        Canceled:  ['#f3f4f6','#6b7280'],
        _:         ['#e0f2fe','#075985'],
    };
    const esc = s => (s == null ? '' : String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'));
    const [bg, tc] = SC[r.status] ?? SC._;
    let modal = document.getElementById('_resvDetailModal');
    if (!modal) {
        modal = document.createElement('div');
        modal.id = '_resvDetailModal';
        modal.style.cssText = 'position:fixed;inset:0;z-index:9999;display:flex;align-items:center;justify-content:center;background:rgba(0,0,0,.45);';
        modal.addEventListener('click', e => { if (e.target === modal) modal.remove(); });
        document.body.appendChild(modal);
    }
    modal.innerHTML = `<div style="background:#fff;border-radius:16px;padding:28px 24px;min-width:290px;max-width:380px;width:92%;box-shadow:0 20px 60px rgba(0,0,0,.2);position:relative;">
        <button onclick="document.getElementById('_resvDetailModal').remove()" style="position:absolute;top:12px;right:14px;background:none;border:none;font-size:22px;cursor:pointer;color:#666;line-height:1;">&times;</button>
        <div style="font-size:16px;font-weight:700;color:#0d9b00;margin-bottom:4px;">${esc(r.facilityName)}</div>
        <span style="display:inline-block;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:600;background:${bg};color:${tc};margin-bottom:16px;">${esc(r.status)}</span>
        <div style="display:grid;gap:11px;font-size:13px;color:#374151;">
            ${r.userName  ? `<div><span style="color:#9ca3af;font-size:11px;font-weight:600;display:block;margin-bottom:1px;">REQUESTER</span>${esc(r.userName)}</div>` : ''}
            ${r.eventName ? `<div><span style="color:#9ca3af;font-size:11px;font-weight:600;display:block;margin-bottom:1px;">EVENT NAME</span>${esc(r.eventName)}</div>` : ''}
            ${r.email     ? `<div><span style="color:#9ca3af;font-size:11px;font-weight:600;display:block;margin-bottom:1px;">EMAIL</span>${esc(r.email)}</div>` : ''}
            ${r.contact   ? `<div><span style="color:#9ca3af;font-size:11px;font-weight:600;display:block;margin-bottom:1px;">CONTACT</span>${esc(r.contact)}</div>` : ''}
            <div><span style="color:#9ca3af;font-size:11px;font-weight:600;display:block;margin-bottom:1px;">DATE &amp; TIME</span>${esc(r.date)}${r.time ? ', ' + esc(r.time) : ''}${r.endTime ? ' – ' + esc(r.endTime) : ''}</div>
            ${r.capacity  ? `<div><span style="color:#9ca3af;font-size:11px;font-weight:600;display:block;margin-bottom:1px;">ATTENDEES</span>${esc(String(r.capacity))} people</div>` : ''}
            ${r.purpose   ? `<div><span style="color:#9ca3af;font-size:11px;font-weight:600;display:block;margin-bottom:1px;">EVENT OBJECTIVE</span>${esc(r.purpose)}</div>` : ''}
        </div>
    </div>`;
    modal.style.display = 'flex';
};

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
function apiFetch(url, timeoutMs = 8000) {
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

const ApiMemoryCache = (() => {
    const store = new Map();

    function get(url, ttlMs) {
        const hit = store.get(url);
        if (!hit || Date.now() - hit.ts > ttlMs) return null;
        return hit.data;
    }

    function set(url, data) {
        if (data != null) store.set(url, { data, ts: Date.now() });
        return data;
    }

    function fetchCached(url, ttlMs = 30000, timeoutMs = 8000) {
        const hit = get(url, ttlMs);
        if (hit) return Promise.resolve(hit);
        return apiFetch(url, timeoutMs).then(data => set(url, data));
    }

    function hasFresh(url, ttlMs = 30000) {
        return get(url, ttlMs) !== null;
    }

    return { get, set, fetchCached, hasFresh };
})();
window.ReservaApiCache = ApiMemoryCache;

if (window.Turbo?.setProgressBarDelay) {
    window.Turbo.setProgressBarDelay(60000);
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
            a.target === '_blank' || e.ctrlKey || e.metaKey || e.shiftKey ||
            a.dataset.noLoading === 'true' || a.dataset.turbo === 'false') return;
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
    const POLL_MS   = 60000;
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
        return `<div class="admin-notif-card" style="cursor:pointer;" data-resv='${JSON.stringify(r).replace(/'/g,"&#39;")}' onclick="window.showResvDetail(JSON.parse(this.dataset.resv))">`
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
        const rank = medals[i] || `<span style="font-size:11px;font-weight:800;color:#6b7280;width:20px;display:inline-block;text-align:center">${i+1}</span>`;
        const sub  = m.specialization || m.degree || '';
        const pts  = m.points !== undefined ? m.points : '';
        return `<div class="admin-notif-card" style="display:flex;align-items:center;gap:9px">`
            + `<span style="font-size:19px;flex-shrink:0;line-height:1">${rank}</span>`
            + `<div style="flex:1;min-width:0">`
            + `<div class="admin-notif-card-title">${esc(m.name||'Mentor')}</div>`
            + (sub ? `<div class="admin-notif-card-desc" style="font-size:11px">${esc(sub)}</div>` : '')
            + `</div>`
            + (pts !== '' ? `<span style="font-size:11.5px;font-weight:800;color:#f59e0b;white-space:nowrap">${esc(String(pts))} pts</span>` : '')
            + `</div>`;
    }

    const EMPTY = (msg) =>
        `<div style="text-align:center;color:#9ca3af;font-size:13px;padding:24px 16px;display:flex;align-items:center;justify-content:center;min-height:100px;">${msg}</div>`;

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

        ApiMemoryCache.fetchCached(url, 30000).then(data => {
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

    /* ── Mentoring panel — leaderboard only (reservations top-panel
       is already populated by pollReservations above) ── */
    function loadMentoringPanel(url) {
        const lbPanel = document.getElementById('adminLeaderboardList');
        if (!lbPanel) return;

        ApiMemoryCache.fetchCached(url, 30000).then(data => {
            if (!data) {
                lbPanel.innerHTML = EMPTY('Could not load — retrying…');
                setTimeout(() => loadMentoringPanel(url), 5000);
                return;
            }
            const lb = (data.leaderboard ?? []).slice(0, 5);
            lbPanel.innerHTML = lb.length ? lb.map(buildLbCard).join('') : EMPTY('No leaderboard data yet');
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
            pollReservations(url);

            // Safety: replace stuck skeletons after 9 s
            _guardTimer = setTimeout(() => {
                const panel = document.getElementById('adminNotifList');
                if (panel && panel.querySelector('.rp-skeleton')) {
                    panel.innerHTML = EMPTY('Could not load — retrying…');
                    lastCount = -1;
                }
            }, 9000);

            // Fixed 15 s repeating poll — skip when tab is hidden
            _pollTimer = setInterval(() => {
                if (!document.hidden) pollReservations(url);
            }, POLL_MS);

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

    // Resume only when cached data is stale to avoid visual reloading on quick tab switches.
    document.addEventListener('visibilitychange', () => {
        if (!document.hidden && _pollTimer) {
            const url = document.querySelector('meta[name="admin-recent-api"]')?.content;
            if (url && !ApiMemoryCache.hasFresh(url, 30000)) pollReservations(url);
        }
    });

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
    const POLL_MS = 60000;

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

        ApiMemoryCache.fetchCached(url, 30000).then(data => {
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
                        + (sc.Cancelled ?? 0) + (sc.Rejected ?? 0) + (sc.Suggested ?? 0);
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
        poll(url);
        _timer = setInterval(() => {                      // then every 15 s — skip when hidden
            if (!document.hidden) poll(url);
        }, POLL_MS);
    }

    // turbo:load fires on every Turbo navigation (and initial visit)
    document.addEventListener('turbo:load', boot);

    // Resume only when cached data is stale to avoid redundant lifecycle fetches.
    document.addEventListener('visibilitychange', () => {
        if (!document.hidden && _timer) {
            const meta = document.querySelector('meta[name="rm-api"]');
            if (meta && !ApiMemoryCache.hasFresh(meta.content, 30000)) poll(meta.content);
        }
    });

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
        ApiMemoryCache.fetchCached(url, 30000).then(data => {
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

    let _statsTimer = null;

    function initStats() {
        // Clear previous timer to prevent stacking on Turbo navigations
        if (_statsTimer) { clearInterval(_statsTimer); _statsTimer = null; }

        const meta = document.querySelector('meta[name="stats-api"]');
        if (!meta || !document.getElementById('stat-total-reservations')) return;

        // Immediate first tick resolves from memory on quick Turbo return visits.
        tick(meta.content);
        _statsTimer = setInterval(() => {
            if (!document.hidden) tick(meta.content);
        }, 60000);
    }

    document.addEventListener('turbo:load', initStats);
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initStats);
    } else {
        initStats();
    }
})();


/* ─────────────────────────────────────────────────────────────
   7. End-user right sidebar live updates
   · Polls /api/user/sidebar every 15 s
   · Populates: userResvList, userMentorshipList, userMentorReqList
   · Hash-diff: DOM write only when data actually changed
   · Shows/hides Mentor Requests panel based on API data
   · Turbo-safe: resets state on every navigation
───────────────────────────────────────────────────────────── */
(() => {
    const POLL_MS = 60000;

    const SC = {
        Pending:   ['#fef3c7','#92400e'],
        Approved:  ['#dcfce7','#166534'],
        Rejected:  ['#fee2e2','#991b1b'],
        Cancelled: ['#f3f4f6','#6b7280'],
        Canceled:  ['#f3f4f6','#6b7280'],
        Completed: ['#dbeafe','#1e40af'],
        Accepted:  ['#dcfce7','#166534'],
        _:         ['#e0f2fe','#075985'],
    };

    function badge(status) {
        const [bg, tc] = SC[status] ?? SC._;
        return `<span class="rp-status-badge" style="--bg:${bg};--tc:${tc}">${esc(status)}</span>`;
    }

    const EMPTY = msg =>
        `<div style="text-align:center;color:#9ca3af;font-size:12.5px;padding:20px 12px;line-height:1.5">${msg}</div>`;

    function buildResvCard(r) {
        const [bg, tc] = SC[r.status] ?? SC._;
        const desc = (r.eventName ? esc(r.eventName) + ' · ' : '') + esc(r.date) + (r.time ? ' at ' + esc(r.time) : '');
        return `<div class="admin-notif-card" style="cursor:pointer;" data-resv='${JSON.stringify(r).replace(/'/g, "&#39;")}' onclick="window.showResvDetail(JSON.parse(this.dataset.resv))">`
            + `<div style="display:flex;justify-content:space-between;align-items:flex-start;gap:6px;margin-bottom:3px">`
            + `<div class="admin-notif-card-title" style="flex:1;min-width:0">${esc(r.facilityName)}</div>`
            + `<span class="rp-status-badge" style="--bg:${bg};--tc:${tc}">${esc(r.status)}</span>`
            + `</div>`
            + `<div class="admin-notif-card-desc">${desc}</div>`
            + `</div>`;
    }

    function buildMentorshipCard(m) {
        const [bg, tc] = SC[m.status] ?? SC._;
        return `<div class="admin-notif-card">`
            + `<div style="display:flex;justify-content:space-between;align-items:flex-start;gap:6px;margin-bottom:3px">`
            + `<div class="admin-notif-card-title" style="flex:1;min-width:0">${esc(m.title)}</div>`
            + `<span class="rp-status-badge" style="--bg:${bg};--tc:${tc}">${esc(m.status)}</span>`
            + `</div>`
            + `<div class="admin-notif-card-desc">${esc(m.mentor)} · ${esc(m.date)}</div>`
            + `</div>`;
    }

    function buildMentorReqCard(r) {
        const [bg, tc] = SC[r.status] ?? SC._;
        return `<div class="admin-notif-card">`
            + `<div style="display:flex;justify-content:space-between;align-items:flex-start;gap:6px;margin-bottom:3px">`
            + `<div class="admin-notif-card-title" style="flex:1;min-width:0">${esc(r.topic)}</div>`
            + `<span class="rp-status-badge" style="--bg:${bg};--tc:${tc}">${esc(r.status)}</span>`
            + `</div>`
            + `<div class="admin-notif-card-desc">updated ${esc(r.updatedAt)}</div>`
            + `</div>`;
    }

    let _timer   = null;
    let _fetching = false;
    let _hashes   = { resv: null, ment: null, req: null };

    function pollUserSidebar(url) {
        if (_fetching) return;
        _fetching = true;

        ApiMemoryCache.fetchCached(url, 30000).then(data => {
            _fetching = false;
            if (!data) return;

            // ── Reservations panel ──
            const resvEl = document.getElementById('userResvList');
            if (resvEl) {
                const list = (data.recentReservations ?? []).slice(0, 8);
                const html = list.length
                    ? list.map(buildResvCard).join('')
                    : EMPTY('You have no upcoming reservations yet.<br>Book a facility to get started!');
                if (hashStr(html) !== _hashes.resv) {
                    _hashes.resv = hashStr(html);
                    resvEl.innerHTML = html;
                }
            }

            // ── Mentorships panel ──
            const mentEl = document.getElementById('userMentorshipList');
            if (mentEl) {
                const list = (data.mentorships ?? []).slice(0, 8);
                const html = list.length
                    ? list.map(buildMentorshipCard).join('')
                    : EMPTY("You don't have any scheduled mentor sessions at the moment.<br>Request one now to get started!");
                if (hashStr(html) !== _hashes.ment) {
                    _hashes.ment = hashStr(html);
                    mentEl.innerHTML = html;
                }
            }

            // ── Mentor Requests panel ──
            const reqEl  = document.getElementById('userMentorReqList');
            const reqPnl = document.getElementById('userMentorReqPanel');
            if (reqEl) {
                const list = (data.mentorRequests ?? []).slice(0, 8);
                const hasApps = (data.mentorApplications ?? []).length > 0;
                const visible = list.length > 0 || hasApps;

                if (reqPnl) reqPnl.style.display = visible ? '' : 'none';

                const html = list.length
                    ? list.map(buildMentorReqCard).join('')
                    : EMPTY('No mentor requests yet.');
                if (hashStr(html) !== _hashes.req) {
                    _hashes.req = hashStr(html);
                    reqEl.innerHTML = html;
                }
            }
        });
    }

    let _guardTimer = null;

    function resetState() {
        clearInterval(_timer);
        clearTimeout(_guardTimer);
        _timer = _guardTimer = null;
        _fetching = false;
        _hashes = { resv: null, ment: null, req: null };
    }

    function boot() {
        resetState();

        const meta = document.querySelector('meta[name="user-sidebar-api"]');
        if (!meta) return;

        const url = meta.content;

        // Immediate first fetch resolves from memory on quick Turbo return visits.
        pollUserSidebar(url);

        // Safety: replace stuck skeletons after 9 s if first fetch failed
        _guardTimer = setTimeout(() => {
            ['userResvList','userMentorshipList','userMentorReqList'].forEach(id => {
                const el = document.getElementById(id);
                if (el && el.querySelector('.rp-skeleton')) {
                    el.innerHTML = EMPTY('Could not load — retrying…');
                }
            });
        }, 9000);

        // Fixed 15 s poll — skip when tab hidden
        _timer = setInterval(() => {
            if (!document.hidden) pollUserSidebar(url);
        }, POLL_MS);
    }

    document.addEventListener('turbo:load', boot);

    document.addEventListener('visibilitychange', () => {
        if (!document.hidden && _timer) {
            const meta = document.querySelector('meta[name="user-sidebar-api"]');
            if (meta && !ApiMemoryCache.hasFresh(meta.content, 30000)) pollUserSidebar(meta.content);
        }
    });

    if (typeof Turbo === 'undefined') {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', boot);
        } else {
            boot();
        }
    }
})();
