<script setup lang="ts">
import { Toaster } from '@/components/ui/sonner';

defineProps<{ title: string }>();
</script>

<template>
    <div class="min-h-screen bg-board text-steel">
        <header class="bg-steel px-5 py-3 text-board">
            <p class="text-lg font-bold tracking-wide">{{ title }}</p>
        </header>
        <main class="mx-auto w-full max-w-md px-5 py-6">
            <slot />
        </main>
        <Toaster position="top-center" />
    </div>
</template>
