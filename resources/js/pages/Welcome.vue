<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
</script>

<template>
    <Head title="QueueTable" />
    <main class="flex min-h-screen flex-col items-center justify-center gap-6 p-8">
        <h1 class="text-3xl font-bold">QueueTable 候位系統</h1>
        <nav class="flex flex-wrap justify-center gap-4 text-lg">
            <a class="underline" href="/board">門口看板</a>
            <a class="underline" href="/take">手機取號</a>
            <a class="underline" href="/login">員工登入</a>
        </nav>
    </main>
</template>
