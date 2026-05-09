import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['badge', 'dropdown', 'list', 'empty'];
    static outlets = [];
    
    initialize() {
        this.lastUnreadCount = 0;
        this.soundPlayedKey = `reservaNotificationSoundPlayed:${this.element.dataset.userId || 'user'}`;
        console.log('Notification controller initialized');
    }

    connect() {
        console.log('Notification controller connected');
        this.element.dataset.notificationConnected = '1';
        this.loadNotifications();
        // Poll for new notifications every 30 seconds
        this.pollingInterval = setInterval(() => this.loadNotifications(), 30000);
    }

    disconnect() {
        if (this.pollingInterval) {
            clearInterval(this.pollingInterval);
        }
    }

    async loadNotifications() {
        try {
            const response = await fetch('/api/notifications', {
                credentials: 'include',
                headers: {
                    'Accept': 'application/json',
                }
            });
            
            if (!response.ok) return;
            
            const data = await response.json();
            const unreadCount = data.unreadCount || 0;
            
            // The notification sound is a login cue. Keep badge/list live, but do not replay it on refresh.
            if (unreadCount > 0 && !sessionStorage.getItem(this.soundPlayedKey)) {
                this.playNotificationSound();
                sessionStorage.setItem(this.soundPlayedKey, '1');
            }
            
            this.lastUnreadCount = unreadCount;
            this.updateBadge(unreadCount);
            this.updateList(data.notifications || []);
        } catch (error) {
            console.error('Error loading notifications:', error);
        }
    }
    
    playNotificationSound() {
        try {
            // Create a simple notification beep sound using Web Audio API
            const audioContext = new (window.AudioContext || window.webkitAudioContext)();
            const oscillator = audioContext.createOscillator();
            const gainNode = audioContext.createGain();
            
            oscillator.connect(gainNode);
            gainNode.connect(audioContext.destination);
            
            // Bell-like sound
            oscillator.type = 'sine';
            oscillator.frequency.setValueAtTime(880, audioContext.currentTime); // A5
            oscillator.frequency.exponentialRampToValueAtTime(440, audioContext.currentTime + 0.3); // Drop to A4
            
            gainNode.gain.setValueAtTime(0.3, audioContext.currentTime);
            gainNode.gain.exponentialRampToValueAtTime(0.01, audioContext.currentTime + 0.5);
            
            oscillator.start(audioContext.currentTime);
            oscillator.stop(audioContext.currentTime + 0.5);
            
            // Second beep for emphasis
            setTimeout(() => {
                const oscillator2 = audioContext.createOscillator();
                const gainNode2 = audioContext.createGain();
                
                oscillator2.connect(gainNode2);
                gainNode2.connect(audioContext.destination);
                
                oscillator2.type = 'sine';
                oscillator2.frequency.setValueAtTime(660, audioContext.currentTime); // E5
                
                gainNode2.gain.setValueAtTime(0.3, audioContext.currentTime);
                gainNode2.gain.exponentialRampToValueAtTime(0.01, audioContext.currentTime + 0.5);
                
                oscillator2.start(audioContext.currentTime);
                oscillator2.stop(audioContext.currentTime + 0.5);
            }, 200);
        } catch (e) {
            console.log('Notification sound not available');
        }
    }

    updateBadge(count) {
        if (!this.hasBadgeTarget) return;
        
        const badge = this.badgeTarget;
        if (count > 0) {
            badge.textContent = count > 99 ? '99+' : count;
            badge.classList.remove('hidden');
            badge.style.display = 'block';
        } else {
            badge.classList.add('hidden');
            badge.style.display = 'none';
        }
    }

    updateList(notifications) {
        if (!this.hasListTarget || !this.hasEmptyTarget) return;

        if (notifications.length === 0) {
            this.listTarget.classList.add('hidden');
            this.emptyTarget.classList.remove('hidden');
            return;
        }

        this.listTarget.classList.remove('hidden');
        this.emptyTarget.classList.add('hidden');

        this.listTarget.innerHTML = notifications.map(n => this.renderNotification(n)).join('');
    }

    renderNotification(notification) {
        const iconClass = notification.type.startsWith('mentor') ? 'bi-person-badge' : 'bi-building';
        const statusClass = notification.status === 'Approved' ? 'text-success' : 
                         notification.status === 'Rejected' ? 'text-danger' : 'text-warning';
        const timeAgo = this.formatTimeAgo(notification.createdAt);
        const unreadClass = !notification.isRead ? 'unread' : '';

        return `
            <a href="${this.escapeHtml(notification.link || '#')}" 
               class="notification-item ${unreadClass}"
               data-action="click->notification#markAsRead"
               data-id="${notification.id}"
               data-type="${notification.type}">
                <div class="notification-icon ${iconClass}">
                    <i class="bi ${notification.type.startsWith('mentor') ? 'bi-person-check' : 'bi-calendar-check'}"></i>
                </div>
                <div class="notification-content">
                    <div class="notification-title">${this.escapeHtml(notification.title)}</div>
                    <div class="notification-message">${this.escapeHtml(notification.message)}</div>
                    <div class="notification-meta">
                        <span class="notification-status ${statusClass}">${notification.status}</span>
                        <span class="notification-time">${timeAgo}</span>
                    </div>
                </div>
            </a>
        `;
    }

    // notification.link is provided by the server; fallback preserved above

    formatTimeAgo(dateString) {
        const date = new Date(dateString);
        const now = new Date();
        const diffMs = now - date;
        const diffMins = Math.floor(diffMs / 60000);
        const diffHours = Math.floor(diffMs / 3600000);
        const diffDays = Math.floor(diffMs / 86400000);

        if (diffMins < 1) return 'Just now';
        if (diffMins < 60) return `${diffMins}m ago`;
        if (diffHours < 24) return `${diffHours}h ago`;
        if (diffDays < 7) return `${diffDays}d ago`;
        return date.toLocaleDateString();
    }

    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    async toggleDropdown(event) {
        if (event) {
            event.preventDefault();
            event.stopPropagation();
        }
        const dropdown = this.dropdownTarget;

        // Toggle using an "open" class so CSS can animate smoothly
        const isOpen = this.element.classList.contains('open');
        if (isOpen) {
            this.element.classList.remove('open');
            dropdown.classList.remove('show');
            if (this.closeOnOutsideClick) {
                document.removeEventListener('click', this.closeOnOutsideClick);
                this.closeOnOutsideClick = null;
            }
            // update aria
            const btn = this.element.querySelector('.notification-bell-btn');
            if (btn) btn.setAttribute('aria-expanded', 'false');
        } else {
            this.element.classList.add('open');
            dropdown.classList.add('show');

            // Load fresh notifications when opening
            this.loadNotifications();

            // set aria
            const btn = this.element.querySelector('.notification-bell-btn');
            if (btn) btn.setAttribute('aria-expanded', 'true');

            // Close on outside click
            setTimeout(() => {
                this.closeOnOutsideClick = (e) => {
                    if (!dropdown.contains(e.target) && !this.element.contains(e.target)) {
                        this.element.classList.remove('open');
                        dropdown.classList.remove('show');
                        document.removeEventListener('click', this.closeOnOutsideClick);
                        this.closeOnOutsideClick = null;
                    }
                };
                document.addEventListener('click', this.closeOnOutsideClick);
            }, 100);
        }
    }

    async markAsRead(event) {
        event.preventDefault();
        const link = event.currentTarget;
        const id = link.dataset.id;
        
        try {
            await fetch(`/api/notifications/${id}/read`, {
                method: 'POST',
                credentials: 'include',
            });
            
            // Update unread count
            const response = await fetch('/api/notifications', {
                credentials: 'include',
            });
            const data = await response.json();
            this.updateBadge(data.unreadCount || 0);
            
            // Navigate to the link
            window.location.href = link.href;
        } catch (error) {
            console.error('Error marking as read:', error);
            // Still navigate
            window.location.href = link.href;
        }
    }

    async markAllAsRead(event) {
        if (event) {
            event.preventDefault();
        }
        
        try {
            await fetch('/api/notifications/read-all', {
                method: 'POST',
                credentials: 'include',
            });
            
            this.updateBadge(0);
            this.lastUnreadCount = 0;
            
            // Clear list UI
            if (this.hasListTarget) {
                this.listTarget.innerHTML = '';
                if (this.hasEmptyTarget) {
                    this.listTarget.classList.add('hidden');
                    this.emptyTarget.classList.remove('hidden');
                }
            }
        } catch (error) {
            console.error('Error marking all as read:', error);
        }
    }
}
