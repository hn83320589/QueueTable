<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { Toaster } from '@/components/ui/sonner';

defineProps<{ title: string }>();

const page = usePage();
const links = [
    { href: '/admin/reports', label: '營業日報表' },
    { href: '/admin/tables', label: '桌位設定' },
    { href: '/admin/users', label: '帳號' },
    { href: '/host', label: '帶位平板' },
];
</script>

<template>
    <div class="min-h-screen bg-board text-steel">
        <header
            class="flex flex-wrap items-center gap-x-6 gap-y-2 bg-steel px-6 py-3 text-board"
        >
            <p class="text-lg font-bold">店長後台</p>
            <nav class="flex flex-wrap gap-1" aria-label="店長功能">
                <Link
                    v-for="link in links"
                    :key="link.href"
                    :href="link.href"
                    class="rounded px-3 py-2 hover:bg-board/10"
                    :class="{
                        'bg-board text-steel hover:bg-board':
                            page.url.startsWith(link.href),
                    }"
                    :aria-current="
                        page.url.startsWith(link.href) ? 'page' : undefined
                    "
                >
                    {{ link.label }}
                </Link>
            </nav>
        </header>
        <main class="mx-auto w-full max-w-6xl px-6 py-6">
            <h1 class="mb-6 text-2xl font-bold">{{ title }}</h1>
            <slot />
        </main>
        <Toaster position="top-center" rich-colors />
    </div>
</template>
