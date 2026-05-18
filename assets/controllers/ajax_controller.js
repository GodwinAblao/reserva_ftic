import { Controller } from '@hotwired/stimulus';

/**
 * Ajax Controller
 * Handles async form submissions with inline flash feedback.
 * Usage: data-controller="ajax" on a <form>
 *   data-ajax-target-value="#someId"  — where to inject success HTML (optional)
 *   data-ajax-reload-value="true"     — reload page after success (optional)
 */
export default class extends Controller {
    static values = {
        target: String,
        reload: { type: Boolean, default: false },
        reloadDelay: { type: Number, default: 800 },
    };

    connect() {
        this.element.addEventListener('submit', this._onSubmit.bind(this));
    }

    disconnect() {
        this.element.removeEventListener('submit', this._onSubmit.bind(this));
    }

    async _onSubmit(e) {
        e.preventDefault();
        const form = this.element;
        const submitBtn = form.querySelector('[type="submit"]');

        this._setLoading(submitBtn, true);
        this._clearFlash(form);

        try {
            const res = await fetch(form.action, {
                method: form.method || 'POST',
                body: new FormData(form),
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
            });

            const contentType = res.headers.get('content-type') || '';

            if (contentType.includes('application/json')) {
                const data = await res.json();
                if (res.ok && data.success) {
                    this._showFlash(form, data.message || 'Saved successfully.', 'success');
                    if (this.reloadValue) {
                        setTimeout(() => window.location.reload(), this.reloadDelayValue);
                    } else if (this.hasTargetValue) {
                        const target = document.querySelector(this.targetValue);
                        if (target && data.html) target.innerHTML = data.html;
                    }
                } else {
                    this._showFlash(form, data.message || 'Something went wrong.', 'error');
                }
            } else {
                // Non-JSON response: server did a redirect or returned HTML — fall back to normal reload
                if (res.redirected || res.ok) {
                    window.location.reload();
                } else {
                    this._showFlash(form, 'Request failed. Please try again.', 'error');
                }
            }
        } catch (err) {
            this._showFlash(form, 'Network error. Please check your connection.', 'error');
        } finally {
            this._setLoading(submitBtn, false);
        }
    }

    _setLoading(btn, loading) {
        if (!btn) return;
        if (loading) {
            btn.dataset.originalText = btn.textContent;
            btn.disabled = true;
            btn.textContent = 'Saving…';
        } else {
            btn.disabled = false;
            btn.textContent = btn.dataset.originalText || btn.textContent;
        }
    }

    _showFlash(form, message, type) {
        this._clearFlash(form);
        const div = document.createElement('div');
        div.className = 'ajax-flash ajax-flash-' + type;
        div.textContent = message;
        form.insertAdjacentElement('beforebegin', div);
        if (type === 'success') {
            setTimeout(() => div.remove(), 4000);
        }
    }

    _clearFlash(form) {
        form.parentNode?.querySelectorAll('.ajax-flash').forEach(el => el.remove());
    }
}
