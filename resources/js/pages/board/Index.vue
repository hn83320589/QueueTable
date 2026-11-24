<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import QRCode from 'qrcode';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useQueueChannel } from '@/composables/useQueueChannel';
import type { QueueLetter, QueueSnapshot } from '@/types/queue';
import { QUEUE_LABELS } from '@/types/queue';

const props = defineProps<{
    snapshot: QueueSnapshot;
    takeUrl: string;
}>();

const snapshot = ref<QueueSnapshot>(props.snapshot);
const qr = ref('');
const now = ref(new Date());
/** 剛換號的佇列，用來觸發一次換號動畫 */
const justCalled = ref<QueueLetter | null>(null);

const letters: QueueLetter[] = ['A', 'B', 'C', 'D'];

useQueueChannel((next) => {
    snapshot.value = next;
});

watch(
    () => letters.map((l) => snapshot.value.queues[l].now_calling),
    (current, previous) => {
        const changed = letters.find(
            (l, i) => current[i] && current[i] !== previous?.[i],
        );
        if (changed) {
            justCalled.value = null;
            requestAnimationFrame(() => (justCalled.value = changed));
        }
    },
);

const clock = computed(() =>
    now.value.toLocaleTimeString('zh-TW', {
        hour: '2-digit',
        minute: '2-digit',
        hour12: false,
    }),
);
const totalWaiting = computed(() =>
    letters.reduce((sum, l) => sum + snapshot.value.queues[l].waiting_count, 0),
);

let timer: number | undefined;

onMounted(async () => {
    qr.value = await QRCode.toDataURL(props.takeUrl, {
        margin: 1,
        width: 320,
        color: { dark: '#23292f', light: '#ffffff' },
    });
    timer = window.setInterval(() => (now.value = new Date()), 15_000);
});

onBeforeUnmount(() => window.clearInterval(timer));
</script>

<template>
    <Head title="候位看板" />

    <main class="flex min-h-screen flex-col bg-steel text-board">
        <header
            class="flex items-end justify-between px-[3vw] pt-[2.5vh] pb-[2vh]"
        >
            <h1 class="text-[3.2vw] leading-none font-bold tracking-wide">
                候位叫號
            </h1>
            <p
                class="font-display text-[3.2vw] leading-none font-semibold tabular-nums"
            >
                {{ clock }}
            </p>
        </header>

        <section
            class="grid flex-1 grid-cols-[repeat(4,1fr)_minmax(0,0.9fr)] gap-[1.4vw] px-[3vw] pb-[3vh]"
        >
            <article
                v-for="letter in letters"
                :key="letter"
                class="menu-strip flex flex-col items-center bg-board px-[1vw] pt-[3vh] pb-[3vh] text-steel"
            >
                <p class="font-display text-[5vw] leading-none font-bold">
                    {{ letter }}
                </p>
                <p class="mt-[1vh] text-[1.7vw] font-semibold">
                    {{ QUEUE_LABELS[letter] }}
                </p>

                <p class="mt-auto text-[1.4vw] text-steel/70">目前叫號</p>
                <p
                    class="font-display text-[7vw] leading-[0.95] font-bold text-sign tabular-nums"
                    :class="{ 'number-flip': justCalled === letter }"
                    :aria-live="'polite'"
                >
                    {{ snapshot.queues[letter].now_calling ?? '—' }}
                </p>

                <p class="mt-auto text-[1.6vw]">
                    <template v-if="snapshot.queues[letter].waiting_count > 0">
                        還有
                        <span class="font-display text-[2.6vw] font-bold">{{
                            snapshot.queues[letter].waiting_count
                        }}</span>
                        組在等
                    </template>
                    <template v-else>目前不用等</template>
                </p>
            </article>

            <aside
                class="flex flex-col items-center justify-center gap-[2vh] text-center"
            >
                <img
                    v-if="qr"
                    :src="qr"
                    alt="手機取號的 QR Code"
                    class="w-[14vw] rounded-sm bg-white p-[0.6vw]"
                />
                <p class="text-[2vw] leading-snug font-bold">手機掃描取號</p>
                <p class="text-[1.3vw] leading-relaxed text-board/80">
                    不用站在門口等<br />快輪到時頁面會提醒你
                </p>
                <p class="mt-[2vh] text-[1.3vw] text-board/70">
                    全部共
                    <span
                        class="font-display text-[2vw] font-bold text-board"
                        >{{ totalWaiting }}</span
                    >
                    組候位中
                </p>
            </aside>
        </section>
    </main>
</template>

<style scoped>
/* 熱炒店牆上的直式菜單板：白底、紅色雙線框 */
.menu-strip {
    border-radius: 2px;
    box-shadow:
        inset 0 0 0 0.35vw var(--color-board),
        inset 0 0 0 0.5vw var(--color-sign),
        inset 0 0 0 0.75vw var(--color-board),
        inset 0 0 0 0.85vw var(--color-sign);
}

.number-flip {
    animation: flip-in 900ms cubic-bezier(0.2, 0.8, 0.2, 1);
}

@keyframes flip-in {
    0% {
        transform: rotateX(90deg) scale(1.1);
        opacity: 0;
    }
    60% {
        transform: rotateX(-12deg) scale(1.04);
        opacity: 1;
    }
    100% {
        transform: none;
    }
}

@media (prefers-reduced-motion: reduce) {
    .number-flip {
        animation: none;
    }
}
</style>
