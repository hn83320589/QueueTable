<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed } from 'vue';
import AdminShell from '@/components/admin/AdminShell.vue';
import { QUEUE_LABELS } from '@/types/queue';
import type { QueueLetter } from '@/types/queue';

type Report = {
    businessDate: string;
    issued: number;
    seated: number;
    noShowRate: number | null;
    cancelRate: number | null;
    perQueue: Record<QueueLetter, { avg: number | null; max: number | null }>;
    mae: number | null;
    intervalHitRate: number | null;
    turnover: Record<string, number>;
    skipDistribution: Record<string, number>;
};

const props = defineProps<{ date: string; today: string; report: Report }>();

const percent = (rate: number | null) =>
    rate === null ? '—' : `${(rate * 100).toFixed(1)}%`;
const minutes = (value: number | null) =>
    value === null ? '—' : value.toFixed(1);

const stats = computed(() => [
    { label: '取號數', value: String(props.report.issued), unit: '組' },
    { label: '入座數', value: String(props.report.seated), unit: '組' },
    { label: '過號率', value: percent(props.report.noShowRate), unit: '' },
    { label: '取消率', value: percent(props.report.cancelRate), unit: '' },
    {
        label: '預估平均絕對誤差',
        value: minutes(props.report.mae),
        unit: props.report.mae === null ? '' : '分',
    },
    {
        label: '實際等候落在預估區間內',
        value: percent(props.report.intervalHitRate),
        unit: '',
    },
]);

const turnover = computed(() => Object.entries(props.report.turnover));
const maxTurnover = computed(() =>
    Math.max(1, ...turnover.value.map(([, n]) => n)),
);
const skips = computed(() => Object.entries(props.report.skipDistribution));
const maxSkip = computed(() => Math.max(1, ...skips.value.map(([, n]) => n)));

function go(date: string) {
    router.get('/admin/reports', { date }, { preserveScroll: true });
}

function shift(days: number) {
    const d = new Date(`${props.date}T12:00:00`);
    d.setDate(d.getDate() + days);
    go(d.toISOString().slice(0, 10));
}
</script>

<template>
    <Head :title="`營業日報表 ${date}`" />
    <AdminShell title="營業日報表">
        <div class="mb-6 flex flex-wrap items-center gap-2">
            <button
                type="button"
                class="h-11 rounded-md border-2 border-steel/20 bg-white px-3 font-semibold"
                @click="shift(-1)"
            >
                前一天
            </button>
            <input
                type="date"
                :value="date"
                :max="today"
                class="h-11 rounded-md border-2 border-steel/20 bg-white px-3 text-lg"
                aria-label="營業日"
                @change="go(($event.target as HTMLInputElement).value)"
            />
            <button
                type="button"
                class="h-11 rounded-md border-2 border-steel/20 bg-white px-3 font-semibold disabled:opacity-40"
                :disabled="date >= today"
                @click="shift(1)"
            >
                後一天
            </button>
            <a
                :href="`/admin/reports/${date}/csv`"
                class="ml-auto flex h-11 items-center rounded-md bg-steel px-4 font-semibold text-board"
                >下載 CSV</a
            >
        </div>

        <p
            v-if="report.issued === 0"
            class="rounded-md bg-white p-6 text-steel/70"
        >
            這個營業日沒有任何取號紀錄。可以用「前一天」查看之前的營業日。
        </p>

        <template v-else>
            <dl
                class="mb-8 grid grid-cols-2 gap-px overflow-hidden rounded-md bg-steel/10 md:grid-cols-3 lg:grid-cols-6"
            >
                <div
                    v-for="stat in stats"
                    :key="stat.label"
                    class="bg-white p-4"
                >
                    <dt class="text-sm text-steel/70">{{ stat.label }}</dt>
                    <dd class="mt-1">
                        <span
                            class="font-display text-4xl font-bold tabular-nums"
                            >{{ stat.value }}</span
                        >
                        <span v-if="stat.unit" class="ml-1">{{
                            stat.unit
                        }}</span>
                    </dd>
                </div>
            </dl>

            <div class="grid gap-8 lg:grid-cols-2">
                <section aria-labelledby="queue-title">
                    <h2 id="queue-title" class="mb-3 font-bold">
                        各佇列等候時間（分鐘）
                    </h2>
                    <table
                        class="w-full border-collapse rounded-md bg-white text-left"
                    >
                        <thead>
                            <tr
                                class="border-b-2 border-steel/15 text-sm text-steel/70"
                            >
                                <th class="p-3 font-semibold">佇列</th>
                                <th class="p-3 text-right font-semibold">
                                    平均
                                </th>
                                <th class="p-3 text-right font-semibold">
                                    最長
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="(wait, queue) in report.perQueue"
                                :key="queue"
                                class="border-b border-steel/10 last:border-0"
                            >
                                <td class="p-3">
                                    <span
                                        class="font-display text-xl font-bold"
                                        >{{ queue }}</span
                                    >
                                    {{ QUEUE_LABELS[queue] }}
                                </td>
                                <td
                                    class="p-3 text-right font-display text-xl tabular-nums"
                                >
                                    {{ minutes(wait.avg) }}
                                </td>
                                <td
                                    class="p-3 text-right font-display text-xl tabular-nums"
                                >
                                    {{ wait.max ?? '—' }}
                                </td>
                            </tr>
                        </tbody>
                    </table>

                    <h2 class="mt-8 mb-3 font-bold">被跳過次數分布</h2>
                    <ul class="flex flex-col gap-2 rounded-md bg-white p-4">
                        <li
                            v-for="[times, count] in skips"
                            :key="times"
                            class="grid grid-cols-[6rem_1fr_3rem] items-center gap-3"
                        >
                            <span class="text-sm">被跳過 {{ times }} 次</span>
                            <span
                                class="h-5 rounded-sm"
                                :class="
                                    Number(times) >= 3
                                        ? 'bg-sign'
                                        : 'bg-steel/70'
                                "
                                :style="{
                                    width: `${(count / maxSkip) * 100}%`,
                                }"
                            />
                            <span
                                class="text-right font-display text-lg tabular-nums"
                                >{{ count }}</span
                            >
                        </li>
                    </ul>
                    <p class="mt-2 text-sm text-steel/60">
                        系統保證同一組最多被跳過 3 次。
                    </p>
                </section>

                <section aria-labelledby="turnover-title">
                    <h2 id="turnover-title" class="mb-3 font-bold">
                        各桌周轉次數
                    </h2>
                    <ul
                        class="grid grid-cols-1 gap-1.5 rounded-md bg-white p-4 sm:grid-cols-2"
                    >
                        <li
                            v-for="[code, count] in turnover"
                            :key="code"
                            class="grid grid-cols-[3rem_1fr_2rem] items-center gap-2"
                        >
                            <span class="font-display text-lg font-bold">{{
                                code
                            }}</span>
                            <span
                                class="h-4 rounded-sm bg-beer/80"
                                :style="{
                                    width: `${(count / maxTurnover) * 100}%`,
                                }"
                            />
                            <span
                                class="text-right font-display text-lg tabular-nums"
                                >{{ count }}</span
                            >
                        </li>
                    </ul>
                </section>
            </div>
        </template>
    </AdminShell>
</template>
