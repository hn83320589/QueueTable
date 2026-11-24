<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import IssueTicketDialog from '@/components/host/IssueTicketDialog.vue';
import OptionPicker from '@/components/host/OptionPicker.vue';
import TableGrid from '@/components/host/TableGrid.vue';
import { Toaster } from '@/components/ui/sonner';
import { useHostAction } from '@/composables/useHostAction';
import { useStaffChannel } from '@/composables/useQueueChannel';
import type {
    HostParty,
    HostState,
    HostTable,
    QueueLetter,
} from '@/types/queue';
import { estimateText, QUEUE_LABELS } from '@/types/queue';

const props = defineProps<{ state: HostState }>();

const { busy, post } = useHostAction();
const issuing = ref(false);
const picking = ref<HostParty | null>(null);
const now = ref(Date.now());

const letters: QueueLetter[] = ['A', 'B', 'C', 'D'];

const called = computed(() =>
    props.state.parties.filter((p) => p.status === 'called'),
);
const waitingBy = computed(() =>
    letters.map((letter) => ({
        letter,
        parties: props.state.parties.filter(
            (p) => p.status === 'waiting' && p.queue === letter,
        ),
    })),
);
const partyById = computed(
    () => new Map(props.state.parties.map((p) => [p.id, p])),
);

function reload() {
    router.reload({ only: ['state'] });
}

useStaffChannel(reload);

/** 叫號後剩餘報到時間 */
function remaining(party: HostParty): string {
    if (!party.called_at) {
        return '';
    }
    const left = Math.max(
        0,
        180 - Math.floor((now.value - Date.parse(party.called_at)) / 1000),
    );

    return `${Math.floor(left / 60)}:${String(left % 60).padStart(2, '0')}`;
}

function callTo(party: HostParty, tableIds: number[]) {
    picking.value = null;
    post(`/host/parties/${party.id}/call`, { table_ids: tableIds });
}

function tableAction(
    table: HostTable,
    action: 'leave' | 'clean' | 'disable' | 'enable',
) {
    post(`/host/tables/${table.id}/${action}`);
}

let tick: number | undefined;
let refresh: number | undefined;

onMounted(() => {
    tick = window.setInterval(() => (now.value = Date.now()), 1000);
    // 用餐分鐘數與過號狀態需要隨時間更新；推播之外每 30 秒再同步一次
    refresh = window.setInterval(reload, 30_000);
});

onBeforeUnmount(() => {
    window.clearInterval(tick);
    window.clearInterval(refresh);
});
</script>

<template>
    <Head title="帶位平板" />

    <div class="flex h-screen flex-col bg-board text-steel">
        <header class="flex items-center gap-4 bg-steel px-5 py-2.5 text-board">
            <p class="text-lg font-bold">帶位</p>
            <p class="text-sm text-board/70">
                營業日 {{ state.business_date }}
            </p>
            <button
                type="button"
                class="ml-auto h-10 rounded-md bg-sign px-4 font-bold text-white focus-visible:outline-2 focus-visible:outline-board"
                @click="issuing = true"
            >
                代客取號
            </button>
            <Link
                href="/device"
                class="h-10 rounded-md px-3 leading-10 text-board/80 hover:text-board"
                >換平板</Link
            >
            <Link
                href="/logout"
                method="post"
                as="button"
                class="h-10 rounded-md px-3 text-board/80 hover:text-board"
                >登出</Link
            >
        </header>

        <div class="grid min-h-0 flex-1 grid-cols-[minmax(22rem,2fr)_3fr]">
            <!-- 左：該叫誰 -->
            <aside
                class="flex min-h-0 flex-col gap-5 overflow-y-auto border-r-2 border-steel/10 p-4"
            >
                <section aria-labelledby="suggest-title">
                    <h2 id="suggest-title" class="mb-2 font-bold">建議叫號</h2>
                    <p
                        v-if="state.suggestions.length === 0"
                        class="rounded-md bg-white p-3 text-sm text-steel/70"
                    >
                        目前沒有空桌可以帶位。桌子清好後會自動出現建議。
                    </p>
                    <ul class="flex flex-col gap-2">
                        <li
                            v-for="s in state.suggestions"
                            :key="s.party_id"
                            class="flex items-center gap-3 rounded-md bg-white p-3 shadow-[inset_4px_0_0_var(--color-sign)]"
                        >
                            <div class="min-w-0 flex-1">
                                <p class="flex items-baseline gap-2">
                                    <span
                                        class="font-display text-3xl leading-none font-bold"
                                        >{{ s.ticket_code }}</span
                                    >
                                    <span class="text-sm">{{ s.size }} 位</span>
                                </p>
                                <p class="mt-1 text-sm">
                                    帶到
                                    <span
                                        class="font-display text-xl font-bold"
                                        >{{ s.table_codes.join(' + ') }}</span
                                    >
                                    <span class="ml-1 text-steel/70"
                                        >{{ s.combined ? '併桌，' : ''
                                        }}{{
                                            s.waste > 0
                                                ? `空 ${s.waste} 席`
                                                : '剛好坐滿'
                                        }}</span
                                    >
                                </p>
                            </div>
                            <button
                                type="button"
                                class="h-12 rounded-md bg-sign px-5 text-lg font-bold text-white disabled:opacity-50"
                                :disabled="busy"
                                @click="
                                    post(`/host/parties/${s.party_id}/call`, {
                                        table_ids: s.table_ids,
                                    })
                                "
                            >
                                叫號
                            </button>
                            <button
                                type="button"
                                class="h-12 rounded-md border-2 border-steel/20 px-3 font-semibold"
                                @click="
                                    picking = partyById.get(s.party_id) ?? null
                                "
                            >
                                改桌
                            </button>
                        </li>
                    </ul>
                </section>

                <section v-if="called.length" aria-labelledby="called-title">
                    <h2 id="called-title" class="mb-2 font-bold">等待報到</h2>
                    <ul class="flex flex-col gap-2">
                        <li
                            v-for="p in called"
                            :key="p.id"
                            class="flex items-center gap-3 rounded-md bg-sign/10 p-3"
                        >
                            <div class="flex-1">
                                <p class="flex items-baseline gap-2">
                                    <span
                                        class="font-display text-2xl font-bold"
                                        >{{ p.ticket_code }}</span
                                    >
                                    <span class="text-sm"
                                        >{{ p.name }}，{{ p.size }} 位</span
                                    >
                                </p>
                                <p class="text-sm">
                                    剩
                                    <span
                                        class="font-display text-lg font-bold tabular-nums"
                                        >{{ remaining(p) }}</span
                                    >
                                    未報到就過號
                                </p>
                            </div>
                            <button
                                type="button"
                                class="h-12 rounded-md bg-beer px-4 font-bold text-white disabled:opacity-50"
                                :disabled="busy"
                                @click="post(`/host/parties/${p.id}/check-in`)"
                            >
                                入座
                            </button>
                            <button
                                type="button"
                                class="h-12 rounded-md border-2 border-steel/20 px-3 font-semibold"
                                :disabled="busy"
                                @click="post(`/host/parties/${p.id}/cancel`)"
                            >
                                取消
                            </button>
                        </li>
                    </ul>
                </section>

                <section aria-labelledby="waiting-title">
                    <h2 id="waiting-title" class="mb-2 font-bold">候位中</h2>
                    <div
                        v-for="group in waitingBy"
                        :key="group.letter"
                        class="mb-3"
                    >
                        <h3 class="mb-1 flex items-baseline gap-2 text-sm">
                            <span class="font-display text-xl font-bold">{{
                                group.letter
                            }}</span>
                            <span
                                >{{ QUEUE_LABELS[group.letter] }}，{{
                                    group.parties.length
                                }}
                                組</span
                            >
                        </h3>
                        <ul
                            class="divide-y divide-steel/10 rounded-md bg-white"
                        >
                            <li
                                v-for="p in group.parties"
                                :key="p.id"
                                class="flex items-center gap-3 px-3 py-2"
                            >
                                <span
                                    class="w-16 font-display text-xl font-bold"
                                    >{{ p.ticket_code }}</span
                                >
                                <span class="min-w-0 flex-1 text-sm">
                                    {{ p.name }}（{{ p.size }} 位，尾號
                                    {{ p.phone_tail }}）<br />
                                    <span class="text-steel/70"
                                        >已等 {{ p.waited_minutes }} 分，{{
                                            estimateText(p.est_low, p.est_high)
                                        }}</span
                                    >
                                </span>
                                <span
                                    v-if="p.skip_count > 0"
                                    class="rounded px-1.5 py-0.5 text-xs font-semibold"
                                    :class="
                                        p.skip_count >= 3
                                            ? 'bg-sign text-white'
                                            : 'bg-yolk/30'
                                    "
                                >
                                    被跳過 {{ p.skip_count }} 次
                                </span>
                                <button
                                    type="button"
                                    class="h-10 rounded-md border-2 border-steel/20 px-3 text-sm font-semibold"
                                    @click="picking = p"
                                >
                                    指定桌
                                </button>
                                <button
                                    type="button"
                                    class="h-10 rounded-md px-2 text-sm text-steel/70 hover:text-sign"
                                    :disabled="busy"
                                    @click="
                                        post(`/host/parties/${p.id}/cancel`)
                                    "
                                >
                                    取消
                                </button>
                            </li>
                            <li
                                v-if="group.parties.length === 0"
                                class="px-3 py-2 text-sm text-steel/50"
                            >
                                沒有人在等
                            </li>
                        </ul>
                    </div>
                </section>

                <section
                    v-if="state.no_shows.length"
                    aria-labelledby="noshow-title"
                >
                    <h2 id="noshow-title" class="mb-2 font-bold">過號</h2>
                    <ul class="divide-y divide-steel/10 rounded-md bg-white">
                        <li
                            v-for="p in state.no_shows"
                            :key="p.id"
                            class="flex items-center gap-3 px-3 py-2"
                        >
                            <span
                                class="w-16 font-display text-xl font-bold text-steel/60"
                                >{{ p.ticket_code }}</span
                            >
                            <span class="flex-1 text-sm"
                                >{{ p.name }}，{{ p.size }} 位</span
                            >
                            <button
                                v-if="p.can_requeue"
                                type="button"
                                class="h-10 rounded-md border-2 border-steel/20 px-3 text-sm font-semibold"
                                :disabled="busy"
                                @click="post(`/host/parties/${p.id}/requeue`)"
                            >
                                重新排入
                            </button>
                            <span v-else class="text-xs text-steel/60"
                                >已重新排入過</span
                            >
                        </li>
                    </ul>
                </section>
            </aside>

            <!-- 右：桌況 -->
            <section
                class="min-h-0 overflow-y-auto p-4"
                aria-labelledby="tables-title"
            >
                <h2 id="tables-title" class="mb-3 font-bold">
                    桌況（點桌子操作）
                </h2>
                <TableGrid
                    :tables="state.tables"
                    :busy="busy"
                    @action="tableAction"
                />
            </section>
        </div>

        <OptionPicker
            :party="picking"
            @close="picking = null"
            @choose="(ids) => picking && callTo(picking, ids)"
        />
        <IssueTicketDialog :open="issuing" @close="issuing = false" />
        <Toaster position="top-center" rich-colors />
    </div>
</template>
