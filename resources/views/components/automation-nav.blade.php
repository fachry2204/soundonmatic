<div
    wire:ignore
    data-vue-navigation
    data-user-name="{{ auth()->user()->name }}"
    data-user-role="{{ auth()->user()->getRoleNames()->first() ?? 'operator' }}"
    data-unread-count="{{ auth()->user()->unreadNotifications()->count() }}"
    data-can-manage-mappings="{{ auth()->user()->can('mappings.manage') ? '1' : '0' }}"
    data-can-manage-sessions="{{ auth()->user()->can('sessions.manage') ? '1' : '0' }}"
    data-dashboard-url="{{ route('automation.dashboard') }}"
    data-notifications-url="{{ route('automation.notifications') }}"
    data-release-status-url="{{ route('automation.release-status') }}"
    data-mappings-url="{{ route('automation.mappings') }}"
    data-sync-metadata-url="{{ route('automation.sync-metadata') }}"
    data-sessions-url="{{ route('automation.sessions') }}"
    data-update-url="{{ route('automation.update') }}"
    data-login-url="{{ route('login') }}"
    data-logout-url="{{ route('logout') }}"
></div>
