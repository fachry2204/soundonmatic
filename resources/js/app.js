import './bootstrap';
import { createApp } from 'vue';
import AppNavigation from './components/AppNavigation.vue';
import { mountLiveProgress } from './live-progress';

function mountNavigation() {
document.querySelectorAll('[data-vue-navigation]').forEach((element) => {
    if (element.__vue_app__) return;
    createApp(AppNavigation, {
        currentPath: window.location.pathname,
        logoUrl: element.dataset.logoUrl,
        userName: element.dataset.userName,
        userRole: element.dataset.userRole,
        canViewDashboard: element.dataset.canViewDashboard === '1',
        canManageSessions: element.dataset.canManageSessions === '1',
        canManageUsers: element.dataset.canManageUsers === '1',
        dashboardUrl: element.dataset.dashboardUrl,
        releaseStatusUrl: element.dataset.releaseStatusUrl,
        reviewsUrl: element.dataset.reviewsUrl,
        sessionsUrl: element.dataset.sessionsUrl,
        updateUrl: element.dataset.updateUrl,
        usersUrl: element.dataset.usersUrl,
        loginUrl: element.dataset.loginUrl,
        logoutUrl: element.dataset.logoutUrl,
        csrfToken: document.querySelector('meta[name="csrf-token"]')?.content,
    }).mount(element);
});
mountLiveProgress();
}

mountNavigation();
document.addEventListener('livewire:navigated', mountNavigation);
document.addEventListener('livewire:navigating', () => {
    document.querySelectorAll('[data-vue-navigation]').forEach(element => element.__vue_app__?.unmount());
});
