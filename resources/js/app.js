import './bootstrap';
import { createApp } from 'vue';
import AppNavigation from './components/AppNavigation.vue';

document.querySelectorAll('[data-vue-navigation]').forEach((element) => {
    createApp(AppNavigation, {
        currentPath: window.location.pathname,
        userName: element.dataset.userName,
        userRole: element.dataset.userRole,
        canManageSessions: element.dataset.canManageSessions === '1',
        dashboardUrl: element.dataset.dashboardUrl,
        releaseStatusUrl: element.dataset.releaseStatusUrl,
        sessionsUrl: element.dataset.sessionsUrl,
        updateUrl: element.dataset.updateUrl,
        loginUrl: element.dataset.loginUrl,
        logoutUrl: element.dataset.logoutUrl,
        csrfToken: document.querySelector('meta[name="csrf-token"]')?.content,
    }).mount(element);
});
