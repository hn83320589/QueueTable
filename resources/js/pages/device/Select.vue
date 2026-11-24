<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';

const props = defineProps<{
    devices: { id: number; name: string }[];
    current: string | null;
}>();

const form = useForm({
    device_id: props.current ? Number(props.current) : (null as number | null),
});

function choose(id: number) {
    form.device_id = id;
    form.post('/device');
}
</script>

<template>
    <Head title="選擇平板" />
    <main
        class="flex min-h-screen flex-col items-center justify-center gap-8 bg-steel p-8 text-board"
    >
        <div class="text-center">
            <h1 class="text-3xl font-bold">這台是哪一台平板？</h1>
            <p class="mt-2 text-board/70">
                之後每個操作都會記錄是哪一台平板做的。
            </p>
        </div>
        <div class="flex flex-wrap justify-center gap-6">
            <button
                v-for="device in devices"
                :key="device.id"
                type="button"
                class="h-36 w-56 rounded-md bg-board text-3xl font-bold text-steel hover:outline-4 hover:outline-sign focus-visible:outline-4 focus-visible:outline-sign"
                :class="{
                    'outline-4 outline-sign': form.device_id === device.id,
                }"
                :disabled="form.processing"
                @click="choose(device.id)"
            >
                {{ device.name }}
            </button>
        </div>
        <InputError :message="form.errors.device_id" />
    </main>
</template>
