import { startStimulusApp } from '@symfony/stimulus-bundle';
import NotificationController from './controllers/notification_controller.js';

const app = startStimulusApp();
// register any custom, 3rd party controllers here
app.register('notification', NotificationController);
