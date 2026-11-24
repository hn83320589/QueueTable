<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import ShopShell from '@/components/shop/ShopShell.vue';
import { useQueueChannel } from '@/composables/useQueueChannel';
import type { PartyStatus, QueueLetter, QueueSnapshot } from '@/types/queue';
import { estimateText, QUEUE_LABELS } from '@/types/queue';

const props = defineProps<{
    party: {
        ticket_code: string;
        queue: QueueLetter;
        status: PartyStatus;
        size: number;
    };
    snapshot: QueueSnapshot;
    cancelUrl: string;
}>();

const snapshot = ref<QueueSnapshot>(props.snapshot);
const confirming = ref(false);
const cancelling = ref(false);

const mine = computed(() =>
    snapshot.value.parties.find(
        (p) => p.ticket_code === props.party.ticket_code,
    ),
);
const status = computed<PartyStatus>(
    () => mine.value?.status ?? props.party.status,
);
const active = computed(
    () => status.value === 'waiting' || status.value === 'called',
);

useQueueChannel((next) => {
    snapshot.value = next;
    // 自己的號碼從快照消失（入座、過號、取消）時，向伺服器確認最新狀態
    const stillListed = next.parties.some(
        (p) => p.ticket_code === props.party.ticket_code,
    );
    if (!stillListed && active.value) {
        router.reload({ only: ['party', 'snapshot'] });
    }
});

function cancel() {
    cancelling.value = true;
    router.post(
        props.cancelUrl,
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                cancelling.value = false;
                confirming.value = false;
            },
        },
    );
}

const endMessage: Partial<Record<PartyStatus, string>> = {
    seated: '已經入座，用餐愉快！',
    cancelled: '這個號碼已取消。需要的話可以重新取號。',
    no_show:
        '叫號 3 分鐘內沒有報到，已經過號。回到門口告訴店員，可以重新排入一次。',
};
</script>

<template>
    <Head :title="`號碼 ${party.ticket_code}`" />
    <ShopShell title="我的候位">
        <section
            class="rounded-md border-2 px-6 py-8 text-center transition-colors"
            :class="
                status === 'called'
                    ? 'border-sign bg-sign text-white'
                    : 'border-steel bg-white'
            "
            aria-live="polite"
        >
            <p
                class="text-base"
                :class="status === 'called' ? 'text-white/90' : 'text-steel/70'"
            >
                {{ party.queue }} 佇列（{{ QUEUE_LABELS[party.queue] }}），{{
                    party.size
                }}
                位
            </p>
            <p
                class="font-display text-[6.5rem] leading-none font-bold tabular-nums"
                :class="status === 'called' ? 'text-white' : 'text-sign'"
            >
                {{ party.ticket_code }}
            </p>

            <template v-if="status === 'called'">
                <p class="mt-4 text-2xl font-bold">輪到您了</p>
                <p class="mt-1 text-lg">請在 3 分鐘內到門口找帶位人員報到</p>
            </template>
            <template v-else-if="status === 'waiting' && mine">
                <p class="mt-4 text-xl">
                    <template v-if="mine.ahead > 0">
                        前面還有
                        <span class="font-display text-4xl font-bold">{{
                            mine.ahead
                        }}</span>
                        組
                    </template>
                    <template v-else>下一組就是您</template>
                </p>
                <p class="mt-1 text-lg text-steel/80">
                    {{ estimateText(mine.est_low, mine.est_high) }}
                </p>
                <p
                    v-if="mine.ahead <= 2"
                    class="mt-4 rounded bg-yolk/20 px-3 py-2 text-base font-semibold"
                >
                    快輪到了，請回到店門口附近
                </p>
            </template>
            <p v-else-if="endMessage[status]" class="mt-4 text-lg">
                {{ endMessage[status] }}
            </p>
        </section>

        <p class="mt-4 text-center text-sm text-steel/60">
            這個頁面會自動更新，不需要重新整理。
        </p>

        <div v-if="active" class="mt-8">
            <button
                v-if="!confirming"
                type="button"
                class="h-12 w-full rounded-md border-2 border-steel/40 text-base font-semibold hover:border-steel focus-visible:outline-2 focus-visible:outline-sign"
                @click="confirming = true"
            >
                不等了，取消這個號碼
            </button>
            <div
                v-else
                class="rounded-md border-2 border-sign bg-white p-4"
                role="alertdialog"
                aria-labelledby="confirm-title"
            >
                <p id="confirm-title" class="text-base font-semibold">
                    確定要取消
                    {{ party.ticket_code }}？取消後無法恢復原本的順序。
                </p>
                <div class="mt-4 grid grid-cols-2 gap-3">
                    <button
                        type="button"
                        class="h-12 rounded-md border-2 border-steel/40 font-semibold"
                        @click="confirming = false"
                    >
                        保留號碼
                    </button>
                    <button
                        type="button"
                        class="h-12 rounded-md bg-sign font-bold text-white disabled:opacity-60"
                        :disabled="cancelling"
                        @click="cancel"
                    >
                        確定取消
                    </button>
                </div>
            </div>
        </div>
        <a
            v-else
            href="/take"
            class="mt-8 flex h-12 items-center justify-center rounded-md bg-steel font-semibold text-board"
            >重新取號</a
        >
    </ShopShell>
</template>
