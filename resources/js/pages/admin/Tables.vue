<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, reactive } from 'vue';
import AdminShell from '@/components/admin/AdminShell.vue';
import InputError from '@/components/InputError.vue';

type AdminTable = {
    id: number;
    code: string;
    capacity: number;
    area: 'indoor' | 'outdoor' | 'bar';
    status: string;
    adjacent_ids: number[];
};

const props = defineProps<{ tables: AdminTable[]; maxCapacity: number }>();

const areaLabels = { indoor: '室內', outdoor: '戶外', bar: '吧台' } as const;

const create = useForm({
    code: '',
    capacity: 4,
    area: 'indoor' as AdminTable['area'],
});
const drafts = reactive<
    Record<number, { code: string; capacity: number; area: AdminTable['area'] }>
>({});
const errors = reactive<Record<number, Record<string, string>>>({});

const codeOf = computed(() => new Map(props.tables.map((t) => [t.id, t.code])));
const totalSeats = computed(() =>
    props.tables.reduce((sum, t) => sum + t.capacity, 0),
);

function draftOf(table: AdminTable) {
    drafts[table.id] ??= {
        code: table.code,
        capacity: table.capacity,
        area: table.area,
    };

    return drafts[table.id];
}

function save(table: AdminTable) {
    router.put(`/admin/tables/${table.id}`, draftOf(table), {
        preserveScroll: true,
        onSuccess: () => delete errors[table.id],
        onError: (e) => (errors[table.id] = e),
    });
}

function toggleAdjacent(table: AdminTable, otherId: number) {
    const ids = table.adjacent_ids.includes(otherId)
        ? table.adjacent_ids.filter((id) => id !== otherId)
        : [...table.adjacent_ids, otherId];
    router.put(
        `/admin/tables/${table.id}/adjacency`,
        { adjacent_ids: ids },
        { preserveScroll: true },
    );
}

function submit() {
    create.post('/admin/tables', {
        preserveScroll: true,
        onSuccess: () => create.reset('code'),
    });
}
</script>

<template>
    <Head title="桌位設定" />
    <AdminShell title="桌位設定">
        <p class="mb-6 text-steel/70">
            目前 {{ tables.length }} 張桌、共
            {{ totalSeats }}
            席。相鄰的兩張桌子可以併桌，系統只會建議併相鄰的兩張。
        </p>

        <form
            class="mb-8 flex flex-wrap items-end gap-3 rounded-md bg-white p-4"
            @submit.prevent="submit"
        >
            <label class="flex flex-col gap-1 text-sm">
                桌號
                <input
                    v-model="create.code"
                    class="h-11 w-28 rounded border-2 border-steel/20 px-2 text-base"
                    placeholder="I13"
                />
            </label>
            <label class="flex flex-col gap-1 text-sm">
                座位數
                <input
                    v-model.number="create.capacity"
                    type="number"
                    min="1"
                    :max="maxCapacity"
                    class="h-11 w-24 rounded border-2 border-steel/20 px-2 text-base"
                />
            </label>
            <label class="flex flex-col gap-1 text-sm">
                區域
                <select
                    v-model="create.area"
                    class="h-11 rounded border-2 border-steel/20 px-2 text-base"
                >
                    <option
                        v-for="(label, value) in areaLabels"
                        :key="value"
                        :value="value"
                    >
                        {{ label }}
                    </option>
                </select>
            </label>
            <button
                type="submit"
                class="h-11 rounded-md bg-sign px-5 font-bold text-white disabled:opacity-60"
                :disabled="create.processing"
            >
                新增桌子
            </button>
            <div class="w-full">
                <InputError
                    :message="
                        create.errors.code ??
                        create.errors.capacity ??
                        create.errors.area
                    "
                />
            </div>
        </form>

        <table class="w-full border-collapse text-left">
            <thead>
                <tr class="border-b-2 border-steel/20 text-sm text-steel/70">
                    <th class="py-2 pr-3 font-semibold">桌號</th>
                    <th class="py-2 pr-3 font-semibold">座位數</th>
                    <th class="py-2 pr-3 font-semibold">區域</th>
                    <th class="py-2 pr-3 font-semibold">
                        可併桌的相鄰桌（點選切換）
                    </th>
                    <th class="py-2" />
                </tr>
            </thead>
            <tbody>
                <tr
                    v-for="table in tables"
                    :key="table.id"
                    class="border-b border-steel/10 align-top"
                >
                    <td class="py-2 pr-3">
                        <input
                            v-model="draftOf(table).code"
                            class="h-10 w-20 rounded border-2 border-steel/20 px-2 font-display text-lg font-bold"
                            :aria-label="`${table.code} 的桌號`"
                        />
                    </td>
                    <td class="py-2 pr-3">
                        <input
                            v-model.number="draftOf(table).capacity"
                            type="number"
                            min="1"
                            :max="maxCapacity"
                            class="h-10 w-20 rounded border-2 border-steel/20 px-2"
                            :aria-label="`${table.code} 的座位數`"
                        />
                    </td>
                    <td class="py-2 pr-3">
                        <select
                            v-model="draftOf(table).area"
                            class="h-10 rounded border-2 border-steel/20 px-2"
                            :aria-label="`${table.code} 的區域`"
                        >
                            <option
                                v-for="(label, value) in areaLabels"
                                :key="value"
                                :value="value"
                            >
                                {{ label }}
                            </option>
                        </select>
                    </td>
                    <td class="py-2 pr-3">
                        <div class="flex flex-wrap gap-1">
                            <button
                                v-for="other in tables.filter(
                                    (t) =>
                                        t.id !== table.id &&
                                        t.area === table.area,
                                )"
                                :key="other.id"
                                type="button"
                                class="h-9 min-w-12 rounded border-2 px-2 font-display font-semibold"
                                :class="
                                    table.adjacent_ids.includes(other.id)
                                        ? 'border-steel bg-steel text-board'
                                        : 'border-steel/15 bg-white text-steel/60'
                                "
                                :aria-pressed="
                                    table.adjacent_ids.includes(other.id)
                                "
                                @click="toggleAdjacent(table, other.id)"
                            >
                                {{ codeOf.get(other.id) }}
                            </button>
                        </div>
                    </td>
                    <td class="py-2">
                        <button
                            type="button"
                            class="h-10 rounded-md border-2 border-steel/20 px-3 font-semibold"
                            @click="save(table)"
                        >
                            儲存
                        </button>
                        <InputError
                            :message="Object.values(errors[table.id] ?? {})[0]"
                        />
                    </td>
                </tr>
            </tbody>
        </table>
    </AdminShell>
</template>
