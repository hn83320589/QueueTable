<script setup lang="ts">
import { Head } from '@inertiajs/vue3';

const links = [
    {
        href: '/take',
        title: '手機取號',
        note: '輸入人數、稱呼與手機，拿到號碼與查詢連結',
    },
    {
        href: '/board',
        title: '門口看板',
        note: '各佇列目前叫到的號碼與等候組數',
    },
    {
        href: '/login',
        title: '員工登入',
        note: '帶位平板、桌位設定與營業日報表',
    },
];
</script>

<template>
    <Head title="QueueTable" />
    <main
        class="flex min-h-screen flex-col justify-center bg-steel px-6 py-12 text-board"
    >
        <div class="mx-auto w-full max-w-xl">
            <h1 class="text-4xl font-bold">熱炒候位</h1>
            <p class="mt-2 text-board/70">候位叫號與帶位系統</p>
            <ul class="mt-10 flex flex-col gap-3">
                <li v-for="link in links" :key="link.href">
                    <a
                        :href="link.href"
                        class="block rounded-md bg-board px-5 py-4 text-steel hover:outline-4 hover:outline-sign focus-visible:outline-4 focus-visible:outline-sign"
                    >
                        <span class="block text-xl font-bold">{{
                            link.title
                        }}</span>
                        <span class="block text-sm text-steel/70">{{
                            link.note
                        }}</span>
                    </a>
                </li>
            </ul>
        </div>
    </main>
</template>
