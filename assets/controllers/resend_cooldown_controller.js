import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['button', 'status'];

    static values = {
        remaining: Number,
        label: String,
    };

    connect() {
        this.timerId = null;

        if (this.hasRemainingValue && this.remainingValue > 0) {
            this.startCountdown(this.remainingValue);
        } else {
            this.resetButton();
        }
    }

    disconnect() {
        if (this.timerId) {
            window.clearInterval(this.timerId);
        }
    }

    startCountdown(seconds) {
        this.remaining = Math.max(0, seconds);
        this.buttonTarget.disabled = true;

        const tick = () => {
            if (this.remaining <= 0) {
                this.resetButton();
                return;
            }

            this.buttonTarget.disabled = true;
            this.buttonTarget.textContent = `Resend code in ${this.formatTime(this.remaining)}`;

            if (this.hasStatusTarget) {
                this.statusTarget.textContent = `You can request a new code in ${this.formatTime(this.remaining)}.`;
            }

            this.remaining -= 1;
        };

        tick();
        this.timerId = window.setInterval(tick, 1000);
    }

    resetButton() {
        if (this.timerId) {
            window.clearInterval(this.timerId);
            this.timerId = null;
        }

        this.buttonTarget.disabled = false;
        this.buttonTarget.textContent = this.hasLabelValue ? this.labelValue : 'Resend code';

        if (this.hasStatusTarget) {
            this.statusTarget.textContent = 'We will only let you resend every 2 minutes.';
        }
    }

    formatTime(totalSeconds) {
        const minutes = Math.floor(totalSeconds / 60);
        const seconds = totalSeconds % 60;
        return `${minutes}:${String(seconds).padStart(2, '0')}`;
    }
}