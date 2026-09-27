import './bootstrap';
import { createApp } from 'vue';
import AppNavigation from './components/AppNavigation.vue';

document.querySelectorAll('[data-vue-navigation]').forEach((element) => {
    createApp(AppNavigation, {
        currentPath: window.location.pathname,
        userName: element.dataset.userName,
        userRole: element.dataset.userRole,
        unreadCount: Number(element.dataset.unreadCount || 0),
        canManageMappings: element.dataset.canManageMappings === '1',
        canManageSessions: element.dataset.canManageSessions === '1',
        dashboardUrl: element.dataset.dashboardUrl,
        notificationsUrl: element.dataset.notificationsUrl,
        releaseStatusUrl: element.dataset.releaseStatusUrl,
        mappingsUrl: element.dataset.mappingsUrl,
        syncMetadataUrl: element.dataset.syncMetadataUrl,
        sessionsUrl: element.dataset.sessionsUrl,
        loginUrl: element.dataset.loginUrl,
        logoutUrl: element.dataset.logoutUrl,
        csrfToken: document.querySelector('meta[name="csrf-token"]')?.content,
    }).mount(element);
});
