<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

defineProps<{ open: boolean }>();
const emit = defineEmits<{ close: [] }>();

const form = useForm({ size: 2, name: '', phone: '' });

function submit() {
    form.post('/host/parties', {
        preserveScroll: true,
        only: ['state'],
        onSuccess: () => {
            form.reset();
            emit('close');
        },
    });
}
</script>

<template>
    <Dialog :open="open" @update:open="(value) => !value && emit('close')">
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle>代客取號</DialogTitle>
                <DialogDescription
                    >門口的客人沒有用手機時，由帶位人員輸入。</DialogDescription
                >
            </DialogHeader>
            <form class="flex flex-col gap-4" @submit.prevent="submit">
                <div class="flex flex-col gap-2">
                    <Label>人數</Label>
                    <div class="flex flex-wrap gap-2">
                        <button
                            v-for="n in 12"
                            :key="n"
                            type="button"
                            class="size-12 rounded border-2 font-display text-xl font-bold"
                            :class="
                                form.size === n
                                    ? 'border-steel bg-steel text-board'
                                    : 'border-steel/20 bg-white'
                            "
                            @click="form.size = n"
                        >
                            {{ n }}
                        </button>
                    </div>
                    <InputError :message="form.errors.size" />
                </div>
                <div class="flex flex-col gap-2">
                    <Label for="kiosk-name">稱呼</Label>
                    <Input
                        id="kiosk-name"
                        v-model="form.name"
                        class="h-11 bg-white"
                        placeholder="例如：林小姐"
                        maxlength="30"
                    />
                    <InputError :message="form.errors.name" />
                </div>
                <div class="flex flex-col gap-2">
                    <Label for="kiosk-phone">手機號碼</Label>
                    <Input
                        id="kiosk-phone"
                        v-model="form.phone"
                        class="h-11 bg-white"
                        type="tel"
                        inputmode="tel"
                        placeholder="0912-345-678"
                    />
                    <InputError :message="form.errors.phone" />
                </div>
                <button
                    type="submit"
                    class="h-12 rounded-md bg-sign text-lg font-bold text-white disabled:opacity-60"
                    :disabled="form.processing"
                >
                    發號
                </button>
            </form>
        </DialogContent>
    </Dialog>
</template>
