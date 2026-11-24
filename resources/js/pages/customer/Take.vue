<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import ShopShell from '@/components/shop/ShopShell.vue';
import InputError from '@/components/InputError.vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { QUEUE_LABELS } from '@/types/queue';
import type { QueueLetter } from '@/types/queue';
import { computed } from 'vue';

const props = defineProps<{ maxPartySize: number }>();

const form = useForm({ size: 2, name: '', phone: '' });

const queue = computed<QueueLetter>(() =>
    form.size <= 2 ? 'A' : form.size <= 4 ? 'B' : form.size <= 6 ? 'C' : 'D',
);

function change(delta: number) {
    form.size = Math.min(props.maxPartySize, Math.max(1, form.size + delta));
}

function submit() {
    form.post('/take');
}
</script>

<template>
    <Head title="手機取號" />
    <ShopShell title="手機取號">
        <form class="flex flex-col gap-7" @submit.prevent="submit">
            <fieldset class="flex flex-col gap-3">
                <legend class="mb-3 text-base font-semibold">幾位用餐？</legend>
                <div
                    class="flex items-center justify-between rounded-md border-2 border-steel bg-white p-2"
                >
                    <button
                        type="button"
                        class="size-14 rounded text-3xl font-bold hover:bg-board focus-visible:outline-2 focus-visible:outline-sign disabled:opacity-30"
                        :disabled="form.size <= 1"
                        aria-label="減少一位"
                        @click="change(-1)"
                    >
                        −
                    </button>
                    <p class="text-center" aria-live="polite">
                        <span
                            class="font-display text-5xl font-bold tabular-nums"
                            >{{ form.size }}</span
                        >
                        <span class="ml-1 text-lg">位</span>
                    </p>
                    <button
                        type="button"
                        class="size-14 rounded text-3xl font-bold hover:bg-board focus-visible:outline-2 focus-visible:outline-sign disabled:opacity-30"
                        :disabled="form.size >= maxPartySize"
                        aria-label="增加一位"
                        @click="change(1)"
                    >
                        +
                    </button>
                </div>
                <p class="text-sm text-steel/70">
                    排在 {{ queue }} 佇列（{{ QUEUE_LABELS[queue] }}）。超過
                    {{ maxPartySize }} 位請洽現場店員。
                </p>
                <InputError :message="form.errors.size" />
            </fieldset>

            <div class="flex flex-col gap-2">
                <Label for="name" class="text-base">怎麼稱呼您？</Label>
                <Input
                    id="name"
                    v-model="form.name"
                    class="h-12 bg-white text-lg"
                    placeholder="例如：陳先生"
                    autocomplete="name"
                    maxlength="30"
                />
                <InputError :message="form.errors.name" />
            </div>

            <div class="flex flex-col gap-2">
                <Label for="phone" class="text-base">手機號碼</Label>
                <Input
                    id="phone"
                    v-model="form.phone"
                    class="h-12 bg-white text-lg"
                    type="tel"
                    inputmode="tel"
                    placeholder="0912-345-678"
                    autocomplete="tel"
                />
                <p class="text-sm text-steel/70">
                    只用來確認同一支手機不重複取號，不會傳簡訊給您。
                </p>
                <InputError :message="form.errors.phone" />
            </div>

            <button
                type="submit"
                class="h-14 rounded-md bg-sign text-xl font-bold text-white hover:bg-sign/90 focus-visible:outline-3 focus-visible:outline-offset-2 focus-visible:outline-steel disabled:opacity-60"
                :disabled="form.processing"
            >
                取號
            </button>
        </form>
    </ShopShell>
</template>
