import { Controller } from '@hotwired/stimulus';

/* ─────────────────────────────────────────────────────────────
   Notification Bell Controller
   Strategy:
   · Background: cheap /poll every 15 s — one DB query, ~1 ms
   · Full load: /api/notifications — only when newestId changes
     OR when dropdown is opened by the user
   · Mark-as-read: optimistic UI (instant) + fire-and-forget POST
   · Mark-all-read: single UPDATE via server, instant badge clear
   · Turbo-safe: connect/disconnect manage all timers
   · Hash-diff: list only re-rendered when content changes
   · New-item animation: only genuinely new items slide in
───────────────────────────────────────────────────────────── */
export default class extends Controller {
    static targets = ['badge', 'dropdown', 'list', 'empty'];

    initialize() {
        this._pollTimer    = null;
        this._fetching     = false;
        this._listFetching = false;
        this._lastNewestId = 0;
        this._lastUnread   = 0;
        this._listHash     = null;
        this._knownIds     = new Set();
        this._outsideClick = null;
        this._initialized  = false;   // true after first successful poll
        this._soundKey     = `reservaNotifSound:${this.element.dataset.userId || 'u'}`;
    }

    connect() {
        this.element.dataset.notificationConnected = '1';
        this._initialized = false;

        // Clear sound sessionStorage key when user logs out
        document.querySelectorAll('[data-clear-notification-session]')
            .forEach(link => {
                link.addEventListener('click', () => {
                    Object.keys(sessionStorage)
                        .filter(k => k.startsWith('reservaNotifSound:'))
                        .forEach(k => sessionStorage.removeItem(k));
                });
            });

        // Immediate poll on connect, then every 15 s
        this._poll();
        this._pollTimer = setInterval(() => this._poll(), 15000);
    }

    disconnect() {
        clearInterval(this._pollTimer);
        this._pollTimer   = null;
        this._initialized = false;
        if (this._outsideClick) {
            document.removeEventListener('click', this._outsideClick);
            this._outsideClick = null;
        }
    }

    /* ── Cheap background poll — hits /poll (1 query) ── */
    async _poll() {
        if (this._fetching) return;
        this._fetching = true;
        try {
            const r = await fetch('/api/notifications/poll', {
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            });
            if (!r.ok) return;
            const { unreadCount, newestId } = await r.json();

            // Badge always reflects latest count
            this._setBadge(unreadCount);

            // Only do expensive full load if something actually changed
            // On first poll (_initialized=false), never treat items as "new" arrivals
            const hasNew = this._initialized && newestId > this._lastNewestId;
            if (!this._initialized || newestId !== this._lastNewestId || unreadCount !== this._lastUnread) {
                this._lastUnread   = unreadCount;
                this._lastNewestId = newestId;
                this._initialized  = true;
                await this._loadList(hasNew);
            }
        } catch (_) {
            // silent — network errors don't break the UI
        } finally {
            this._fetching = false;
        }
    }

    /* ── Full notification list load ── */
    async _loadList(hasNewItems = false) {
        if (this._listFetching) return;
        this._listFetching = true;
        try {
            const r = await fetch('/api/notifications', {
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            });
            if (!r.ok) return;
            const data = await r.json();
            const notifications = data.notifications || [];

            // Sound only for brand-new arrivals (not read-state changes)
            if (hasNewItems && !sessionStorage.getItem(this._soundKey)) {
                this._playSound();
                sessionStorage.setItem(this._soundKey, '1');
            }

            this._renderList(notifications, hasNewItems);
            this._setBadge(data.unreadCount ?? 0);
            this._lastUnread = data.unreadCount ?? 0;
        } catch (_) {
            // silent
        } finally {
            this._listFetching = false;
        }
    }

    /* ── Render list with hash-diff — only writes DOM when changed ── */
    _renderList(notifications, animateNew = false) {
        if (!this.hasListTarget) return;

        if (notifications.length === 0) {
            if (this.hasEmptyTarget) {
                this.listTarget.style.display  = 'none';
                this.emptyTarget.style.display = 'block';
            }
            this._listHash = null;
            this._knownIds.clear();
            return;
        }

        if (this.hasEmptyTarget) {
            this.listTarget.style.display  = 'block';
            this.emptyTarget.style.display = 'none';
        }

        // Build HTML string and hash it
        const html  = notifications.map(n => this._renderItem(n)).join('');
        const hash  = this._hash(html);
        if (hash === this._listHash) return;  // nothing changed
        this._listHash = hash;

        // Detect which IDs are genuinely new
        const newIds = animateNew
            ? notifications.filter(n => !this._knownIds.has(n.id)).map(n => n.id)
            : [];
        notifications.forEach(n => this._knownIds.add(n.id));

        this.listTarget.innerHTML = html;

        // Slide-in only genuinely new items
        if (newIds.length) {
            newIds.forEach(id => {
                const el = this.listTarget.querySelector(`[data-id="${id}"]`);
                if (el) el.classList.add('notif-slide-in');
            });
        }
    }

    _renderItem(n) {
        const isMentor    = n.type && n.type.startsWith('mentor');
        const statusClass = n.status === 'Approved' ? 'text-success'
                          : n.status === 'Rejected'  ? 'text-danger'
                          : 'text-warning';
        const unreadClass = n.isRead ? '' : ' unread';
        const icon        = isMentor ? 'bi-person-check' : 'bi-calendar-check';
        const href        = this._esc(n.link || '#');

        return `<a href="${href}" class="notification-item${unreadClass}" `
             + `data-action="click->notification#markAsRead" `
             + `data-id="${n.id}" data-href="${href}">`
             + `<div class="notification-icon"><i class="bi ${icon}"></i></div>`
             + `<div class="notification-content">`
             + `<div class="notification-title">${this._esc(n.title)}</div>`
             + `<div class="notification-message">${this._esc(n.message)}</div>`
             + `<div class="notification-meta">`
             + `<span class="notification-status ${statusClass}">${this._esc(n.status)}</span>`
             + `<span class="notification-time">${this._timeAgo(n.createdAt)}</span>`
             + `</div></div></a>`;
    }

    /* ── Badge update ── */
    _setBadge(count) {
        if (!this.hasBadgeTarget) return;
        const badge = this.badgeTarget;
        if (count > 0) {
            const label = count > 99 ? '99+' : String(count);
            if (badge.textContent !== label) {
                badge.textContent = label;
                badge.classList.add('notif-badge-bump');
                badge.addEventListener('animationend', () =>
                    badge.classList.remove('notif-badge-bump'), { once: true });
            }
            badge.style.display = 'inline-flex';
        } else {
            badge.style.display = 'none';
        }
    }

    /* ── Toggle dropdown ── */
    toggleDropdown(event) {
        if (event) event.preventDefault();
        if (!this.hasDropdownTarget) return;

        const isOpen = this.element.classList.contains('open');
        const btn    = this.element.querySelector('.notification-bell-btn');

        if (isOpen) {
            this._closeDropdown();
        } else {
            this.element.classList.add('open');
            this.dropdownTarget.classList.add('show');
            if (btn) btn.setAttribute('aria-expanded', 'true');

            // Fresh load when user opens — always show latest data
            this._loadList(false);

            // Outside-click to close — attach once, clean up on close
            if (!this._outsideClick) {
                setTimeout(() => {
                    this._outsideClick = (e) => {
                        if (!this.element.contains(e.target)) this._closeDropdown();
                    };
                    document.addEventListener('click', this._outsideClick);
                }, 80);
            }
        }
    }

    _closeDropdown() {
        if (!this.hasDropdownTarget) return;
        this.element.classList.remove('open');
        this.dropdownTarget.classList.remove('show');
        const btn = this.element.querySelector('.notification-bell-btn');
        if (btn) btn.setAttribute('aria-expanded', 'false');
        if (this._outsideClick) {
            document.removeEventListener('click', this._outsideClick);
            this._outsideClick = null;
        }
    }

    /* ── Mark single as read — optimistic UI ── */
    markAsRead(event) {
        event.preventDefault();
        const link = event.currentTarget;
        const id   = link.dataset.id;
        const href = link.dataset.href || link.getAttribute('href') || '#';

        // Optimistic: visually mark read and decrement badge immediately
        if (link.classList.contains('unread')) {
            link.classList.remove('unread');
            const newCount = Math.max(0, this._lastUnread - 1);
            this._lastUnread = newCount;
            this._setBadge(newCount);
        }

        // Fire-and-forget POST — no await, no second fetch
        fetch(`/api/notifications/${id}/read`, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
        }).catch(() => {});

        // Navigate
        if (href && href !== '#') {
            window.location.href = href;
        }
    }

    /* ── Mark all as read ── */
    async markAllAsRead(event) {
        if (event) event.preventDefault();

        // Optimistic
        this._setBadge(0);
        this._lastUnread = 0;
        this.listTarget?.querySelectorAll('.notification-item.unread')
            .forEach(el => el.classList.remove('unread'));

        // Invalidate hash so next render re-draws with all items read
        this._listHash = null;

        try {
            await fetch('/api/notifications/read-all', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            });
        } catch (_) {}
    }

    /* ── Helpers ── */
    _timeAgo(dateString) {
        const diff  = Date.now() - new Date(dateString).getTime();
        const mins  = Math.floor(diff / 60000);
        const hours = Math.floor(diff / 3600000);
        const days  = Math.floor(diff / 86400000);
        if (mins  < 1)  return 'Just now';
        if (mins  < 60) return `${mins}m ago`;
        if (hours < 24) return `${hours}h ago`;
        if (days  < 7)  return `${days}d ago`;
        return new Date(dateString).toLocaleDateString();
    }

    _esc(text) {
        const d = document.createElement('div');
        d.textContent = String(text ?? '');
        return d.innerHTML;
    }

    _hash(str) {
        let h = 0x811c9dc5;
        for (let i = 0; i < str.length; i++) {
            h ^= str.charCodeAt(i);
            h = Math.imul(h, 0x01000193) >>> 0;
        }
        return h;
    }

    _playSound() {
        try {
            const ctx  = new (window.AudioContext || window.webkitAudioContext)();
            const play = (freq, start, dur) => {
                const osc  = ctx.createOscillator();
                const gain = ctx.createGain();
                osc.connect(gain);
                gain.connect(ctx.destination);
                osc.type = 'sine';
                osc.frequency.setValueAtTime(freq, ctx.currentTime + start);
                gain.gain.setValueAtTime(0.25, ctx.currentTime + start);
                gain.gain.exponentialRampToValueAtTime(0.01, ctx.currentTime + start + dur);
                osc.start(ctx.currentTime + start);
                osc.stop(ctx.currentTime + start + dur);
            };
            play(880, 0,    0.4);
            play(660, 0.22, 0.35);
        } catch (_) {}
    }
}
