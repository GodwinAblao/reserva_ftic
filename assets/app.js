import './stimulus_bootstrap.js';
import './styles/app.css';

/* â”€â”€ Global: Reservation detail modal (used by both admin & user sidebars) â”€â”€ */
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
            <div><span style="color:#9ca3af;font-size:11px;font-weight:600;display:block;margin-bottom:1px;">DATE &amp; TIME</span>${esc(r.date)}${r.time ? ', ' + esc(r.time) : ''}${r.endTime ? ' - ' + esc(r.endTime) : ''}</div>
            ${r.capacity  ? `<div><span style="color:#9ca3af;font-size:11px;font-weight:600;display:block;margin-bottom:1px;">ATTENDEES</span>${esc(String(r.capacity))} people</div>` : ''}
            ${r.purpose   ? `<div><span style="color:#9ca3af;font-size:11px;font-weight:600;display:block;margin-bottom:1px;">EVENT OBJECTIVE</span>${esc(r.purpose)}</div>` : ''}
        </div>
    </div>`;
    modal.style.display = 'flex';
};

/* â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
   RESERVA FTIC  \u2014  Global UX + Performance Layer
   â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
   Key design goals:
   \u00B7 One fetch per interval cycle, never concurrent duplicates
   \u00B7 Hash-based diffing \u2014 DOM only touched when data changes
   \u00B7 No setTimeout delays on DOM writes (instant swap)
   \u00B7 Browser cache respected via Cache-Control headers
   \u00B7 Safety timeout on every async operation
â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• */


/* â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
   Shared helpers
â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ */
/** Lightweight XOR hash \u2014 fast enough for small JSON strings */
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

/** apiFetch \u2014 fetch with abort timeout. Returns JSON or null. */
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
    if (el._rpHash === h) return false;   // unchanged \u2014 skip DOM write
    el._rpHash  = h;
    el.innerHTML = html;
    return true;
}


/* â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
   1. Navigation Progress Bar
   \u00B7 Instant start on click
   \u00B7 Finishes on DOMContentLoaded / load / pageshow (bfcache)
   \u00B7 8-second hard safety auto-finish
â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ */
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

/* â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
   2. Content area fade-in (GPU composited \u2014 no layout thrash)
â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ */
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


/* â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
   3. AJAX form submissions
   \u00B7 WeakSet dedup (no double-submit)
   \u00B7 NavProgress integration
   \u00B7 data-ajax="true" + optional data-ajax-reload="ms"
â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ */
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
        if (btn) { btn.disabled = true; btn.textContent = 'Saving...'; }
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
   4. Recent Reservation Request sidebar
   · Uses ApiMemoryCache (TTL 120 s) — navigations hit memory,
     not the network, so the panel never blanks between pages
   · On first visit: fetches once, stores result in memory cache
   · On subsequent Turbo navigations within 120 s: renders from
     memory instantly — zero network round-trip, zero blank flash
   · apiFetch honours server Cache-Control: max-age=120 for
     the underlying HTTP layer as a second cache tier
───────────────────────────────────────────────────────────── */
(() => {
    const CACHE_TTL = 120000; // 2 min — matches server max-age

    const SC = {
        Pending:   ['#fef3c7','#92400e'],
        Approved:  ['#dcfce7','#166534'],
        Rejected:  ['#fee2e2','#991b1b'],
        Cancelled: ['#f3f4f6','#6b7280'],
        Canceled:  ['#f3f4f6','#6b7280'],
        _:         ['#e0f2fe','#075985'],
    };
    const MAX_ITEMS = 8;
    const EMPTY = msg =>
        `<div style="text-align:center;color:#9ca3af;font-size:13px;padding:24px 16px">${msg}</div>`;

    function buildCard(r) {
        const [bg, tc] = SC[r.status] ?? SC._;
        return `<div class="admin-notif-card" style="cursor:pointer" data-resv='${JSON.stringify(r).replace(/'/g,"&#39;")}' onclick="window.showResvDetail(JSON.parse(this.dataset.resv))">`
            + `<div style="display:flex;justify-content:space-between;align-items:flex-start;gap:6px;margin-bottom:3px">`
            + `<div class="admin-notif-card-title" style="flex:1;min-width:0">${esc(r.facilityName||'Facility')}</div>`
            + `<span class="rp-status-badge" style="--bg:${bg};--tc:${tc}">${esc(r.status||'')}</span>`
            + `</div>`
            + `<div class="admin-notif-card-desc">${esc(r.userName||'User')} \xb7 ${esc(r.date||'')}${r.time?' at '+esc(r.time):''}</div>`
            + `</div>`;
    }

    function render(data, panel) {
        const list = (data.recentReservations ?? [])
            .filter(r => r.status === 'Pending')
            .slice(0, MAX_ITEMS);
        const html = list.length ? list.map(buildCard).join('') : EMPTY('No pending reservations');
        if (panel._rpHtml !== html) {
            panel._rpHtml = html;
            panel.innerHTML = html;
        }
    }

    function load() {
        const meta  = document.querySelector('meta[name="admin-recent-api"]');
        const panel = document.getElementById('adminNotifList');
        if (!meta || !panel) return;

        const url = meta.content;

        /* ── Instant render from memory cache — no blank flash ── */
        const cached = ApiMemoryCache.get(url, CACHE_TTL);
        if (cached) {
            render(cached, panel);
            return; // cache is fresh — skip network entirely
        }

        /* ── First visit or cache expired: fetch once, store, render ── */
        ApiMemoryCache.fetchCached(url, CACHE_TTL).then(data => {
            if (!data) {
                if (!panel.innerHTML.trim()) panel.innerHTML = EMPTY('Could not load reservations.');
                return;
            }
            render(data, panel);
        });
    }

    document.addEventListener('turbo:load', load);
    if (typeof Turbo === 'undefined') {
        if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', load); }
        else { load(); }
    }
})();
