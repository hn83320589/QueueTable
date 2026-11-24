import { createInertiaApp } from '@inertiajs/vue3';
import { initializeTheme } from '@/composables/useAppearance';
import AppLayout from '@/layouts/AppLayout.vue';
import AuthLayout from '@/layouts/AuthLayout.vue';
import { initializeFlashToast } from '@/lib/flashToast';
import { configureEcho } from '@laravel/echo-vue';
import type {
    ChannelAuthorizationCallback,
    ChannelAuthorizationData,
} from 'pusher-js/types/src/core/auth/options';

// 平板與手機是透過主機 IP 連線，.env 若寫 localhost 就改用目前網址的主機名稱
const reverbHost = import.meta.env.VITE_REVERB_HOST;

configureEcho({
    broadcaster: 'reverb',
    wsHost:
        !reverbHost || reverbHost === 'localhost'
            ? window.location.hostname
            : reverbHost,
    // 私有頻道驗證：Inertia 應用以 XSRF-TOKEN cookie 防 CSRF，這裡手動帶上
    authorizer: (channel: { name: string }) => ({
        authorize: (
            socketId: string,
            callback: ChannelAuthorizationCallback,
        ) => {
            const token = document.cookie
                .split('; ')
                .find((c) => c.startsWith('XSRF-TOKEN='))
                ?.split('=')[1];

            fetch('/broadcasting/auth', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-XSRF-TOKEN': decodeURIComponent(token ?? ''),
                },
                body: JSON.stringify({
                    socket_id: socketId,
                    channel_name: channel.name,
                }),
            })
                .then((response) => {
                    if (!response.ok) {
                        throw new Error(`頻道驗證失敗（${response.status}）`);
                    }

                    return response.json();
                })
                .then((data: ChannelAuthorizationData) => callback(null, data))
                .catch((error: Error) => callback(error, null));
        },
    }),
});

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

void createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    layout: (name) => {
        switch (true) {
            case name === 'Welcome':
            case name.startsWith('board/'):
            case name.startsWith('customer/'):
            case name.startsWith('host/'):
            case name.startsWith('device/'):
            case name.startsWith('admin/'):
                // 這些頁面各自有符合現場使用情境的外框
                return null;
            case name.startsWith('auth/'):
                return AuthLayout;
            default:
                return AppLayout;
        }
    },
    withApp: (app) => {
        app.directive('focus', {
            mounted: (el: HTMLElement, shouldFocus) => {
                if (shouldFocus.value !== false) {
                    el.focus();
                }
            },
        });
    },
    progress: {
        color: '#c4161c',
    },
});

// This will set light / dark mode on page load...
initializeTheme();

// This will listen for flash toast data from the server...
initializeFlashToast();
