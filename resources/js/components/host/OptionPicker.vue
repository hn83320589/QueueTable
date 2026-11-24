<script setup lang="ts">
import { ref, watch } from 'vue';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import type { HostParty, TableOptionChoice } from '@/types/queue';

const props = defineProps<{ party: HostParty | null }>();
const emit = defineEmits<{ close: []; choose: [tableIds: number[]] }>();

const options = ref<TableOptionChoice[]>([]);
const loading = ref(false);
const failed = ref(false);

watch(
    () => props.party,
    async (party) => {
        options.value = [];
        failed.value = false;
        if (!party) {
            return;
        }
        loading.value = true;
        try {
            const response = await fetch(`/host/parties/${party.id}/options`, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            });
            if (!response.ok) {
                throw new Error(String(response.status));
            }
            options.value = (await response.json()).options;
        } catch {
            failed.value = true;
        } finally {
            loading.value = false;
        }
    },
);
</script>

<template>
    <Dialog
        :open="party !== null"
        @update:open="(open) => !open && emit('close')"
    >
        <DialogContent class="sm:max-w-lg">
            <DialogHeader>
                <DialogTitle
                    >{{ party?.ticket_code }}（{{
                        party?.size
                    }}
                    位）要帶到哪一桌？</DialogTitle
                >
                <DialogDescription
                    >只列出現在空著、坐得下、浪費不超過 2
                    席的選項；有空的單桌時不會列出併桌。</DialogDescription
                >
            </DialogHeader>
            <p v-if="loading" class="py-6 text-center text-steel/70">讀取中…</p>
            <p v-else-if="failed" class="py-6 text-center text-sign">
                讀取失敗，請關閉後再試一次。
            </p>
            <p
                v-else-if="options.length === 0"
                class="py-6 text-center text-steel/70"
            >
                目前沒有適合這組的空桌。
            </p>
            <ul v-else class="grid grid-cols-2 gap-3">
                <li v-for="option in options" :key="option.table_ids.join('-')">
                    <button
                        type="button"
                        class="flex w-full flex-col items-start rounded-md border-2 border-steel/20 bg-white p-3 text-left hover:border-beer focus-visible:outline-2 focus-visible:outline-sign"
                        @click="emit('choose', option.table_ids)"
                    >
                        <span class="font-display text-3xl font-bold">{{
                            option.codes.join(' + ')
                        }}</span>
                        <span class="text-sm text-steel/70">
                            {{ option.capacity }} 席{{
                                option.waste > 0
                                    ? `，空 ${option.waste} 席`
                                    : '，剛好坐滿'
                            }}{{ option.combined ? '，併桌' : '' }}
                        </span>
                    </button>
                </li>
            </ul>
        </DialogContent>
    </Dialog>
</template>
