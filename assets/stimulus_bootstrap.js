import { startStimulusApp } from '@symfony/stimulus-bundle';
import NotificationController from './controllers/notification_controller.js';
import ResendCooldownController from './controllers/resend_cooldown_controller.js';
import AjaxController from './controllers/ajax_controller.js';

const app = startStimulusApp();
// register any custom, 3rd party controllers here
app.register('notification', NotificationController);
app.register('resend-cooldown', ResendCooldownController);
app.register('ajax', AjaxController);
