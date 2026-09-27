<script setup>
import { computed, ref } from 'vue';

const props = defineProps({
    currentPath: String,
    userName: String,
    userRole: String,
    unreadCount: Number,
    canManageMappings: Boolean,
    canManageSessions: Boolean,
    dashboardUrl: String,
    notificationsUrl: String,
    releaseStatusUrl: String,
    mappingsUrl: String,
    sessionsUrl: String,
    updateUrl: String,
    loginUrl: String,
    logoutUrl: String,
    csrfToken: String,
});

const open = ref(false);
const items = computed(() => [
    { label: 'Dashboard', href: props.dashboardUrl, icon: 'grid', show: true },
    { label: 'Cek Status Rilis', href: props.releaseStatusUrl, icon: 'status', show: true },
    { label: 'Notifikasi', href: props.notificationsUrl, icon: 'bell', badge: props.unreadCount, show: true },
    { label: 'Metadata Mapping', href: props.mappingsUrl, icon: 'map', show: props.canManageMappings },
    { label: 'Pengaturan Platform', href: props.sessionsUrl, icon: 'settings', show: props.canManageSessions },
    { label: 'Update Aplikasi', href: props.updateUrl, icon: 'update', show: props.canManageSessions },
].filter((item) => item.show));

const pathOf = (href) => new URL(href, window.location.origin).pathname.replace(/\/$/, '') || '/';
const isActive = (href) => {
    const itemPath = pathOf(href);
    const currentPath = (props.currentPath || '/').replace(/\/$/, '') || '/';

    return itemPath === pathOf(props.dashboardUrl)
        ? currentPath === itemPath
        : currentPath === itemPath || currentPath.startsWith(`${itemPath}/`);
};

const logout = async () => {
    await fetch(props.logoutUrl, {
        method: 'POST',
        headers: { 'X-CSRF-TOKEN': props.csrfToken, 'Accept': 'application/json' },
        credentials: 'same-origin',
    });
    window.location.href = props.loginUrl;
};
</script>

<template>
    <header class="app-header">
        <div class="app-header__inner">
            <a :href="dashboardUrl" class="brand" aria-label="SoundFlow Dashboard">
                <span class="brand__mark"><span></span><span></span><span></span></span>
                <span><strong>SoundFlow</strong><small>Release Automation</small></span>
            </a>

            <button class="mobile-toggle" type="button" aria-label="Buka navigasi" @click="open = !open">
                <svg viewBox="0 0 24 24"><path d="M4 7h16M4 12h16M4 17h16" /></svg>
            </button>

            <nav :class="['primary-nav', { 'primary-nav--open': open }]">
                <a v-for="item in items" :key="item.href" :href="item.href" :class="['nav-item', { 'nav-item--active': isActive(item.href) }]">
                    <svg v-if="item.icon === 'grid'" viewBox="0 0 24 24"><rect x="4" y="4" width="6" height="6" rx="1"/><rect x="14" y="4" width="6" height="6" rx="1"/><rect x="4" y="14" width="6" height="6" rx="1"/><rect x="14" y="14" width="6" height="6" rx="1"/></svg>
                    <svg v-else-if="item.icon === 'bell'" viewBox="0 0 24 24"><path d="M18 8a6 6 0 00-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4"/></svg>
                    <svg v-else-if="item.icon === 'status'" viewBox="0 0 24 24"><path d="M4 5h16v14H4zM8 9h8M8 13h5M17 15l1.5 1.5L21 14"/></svg>
                    <svg v-else-if="item.icon === 'map'" viewBox="0 0 24 24"><path d="M9 18l-6 3V6l6-3 6 3 6-3v15l-6 3-6-3zM9 3v15M15 6v15"/></svg>
                    <svg v-else-if="item.icon === 'sync'" viewBox="0 0 24 24"><path d="M20 7h-7a4 4 0 00-4 4v1M4 17h7a4 4 0 004-4v-1M17 4l3 3-3 3M7 20l-3-3 3-3"/></svg>
                    <svg v-else-if="item.icon === 'update'" viewBox="0 0 24 24"><path d="M12 3v12M7 10l5 5 5-5M5 20h14"/></svg>
                    <svg v-else viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 00.3 1.9l.1.1-2.8 2.8-.1-.1a1.7 1.7 0 00-1.9-.3 1.7 1.7 0 00-1 1.6v.2h-4V21a1.7 1.7 0 00-1-1.6 1.7 1.7 0 00-1.9.3l-.1.1L4.2 17l.1-.1a1.7 1.7 0 00.3-1.9A1.7 1.7 0 003 14H2.8v-4H3a1.7 1.7 0 001.6-1 1.7 1.7 0 00-.3-1.9L4.2 7 7 4.2l.1.1A1.7 1.7 0 009 4.6 1.7 1.7 0 0010 3v-.2h4V3a1.7 1.7 0 001 1.6 1.7 1.7 0 001.9-.3l.1-.1L19.8 7l-.1.1a1.7 1.7 0 00-.3 1.9 1.7 1.7 0 001.6 1h.2v4H21a1.7 1.7 0 00-1.6 1z"/></svg>
                    <span>{{ item.label }}</span>
                    <span v-if="item.badge" class="nav-badge">{{ item.badge }}</span>
                </a>
            </nav>

            <div class="user-menu">
                <span class="user-avatar">{{ userName?.charAt(0).toUpperCase() }}</span>
                <span class="user-copy"><strong>{{ userName }}</strong><small>{{ userRole }}</small></span>
                <button type="button" class="logout-button" title="Keluar" @click="logout">
                    <svg viewBox="0 0 24 24"><path d="M10 17l5-5-5-5M15 12H3M15 4h4a2 2 0 012 2v12a2 2 0 01-2 2h-4"/></svg>
                </button>
            </div>
        </div>
    </header>
</template>
