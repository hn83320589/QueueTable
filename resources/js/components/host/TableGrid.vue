<script setup lang="ts">
import { computed, ref } from 'vue';
import type { HostTable } from '@/types/queue';

const props = defineProps<{ tables: HostTable[]; busy: boolean }>();
const emit = defineEmits<{
    action: [
        table: HostTable,
        action: 'leave' | 'clean' | 'disable' | 'enable',
    ];
}>();

const open = ref<number | null>(null);

const areas = computed(() =>
    (
        [
            ['indoor', '室內'],
            ['outdoor', '戶外'],
            ['bar', '吧台'],
        ] as const
    ).map(([area, label]) => ({
        label,
        tables: props.tables.filter((t) => t.area === area),
    })),
);

function actionsFor(
    table: HostTable,
): { key: 'leave' | 'clean' | 'disable' | 'enable'; label: string }[] {
    switch (table.status) {
        case 'occupied':
            return [{ key: 'leave', label: '客人離開' }];
        case 'cleaning':
            return [
                { key: 'clean', label: '清桌完成' },
                { key: 'disable', label: '停用' },
            ];
        case 'available':
            return table.held_for ? [] : [{ key: 'disable', label: '停用' }];
        case 'disabled':
            return [{ key: 'enable', label: '啟用' }];
    }
}

const statusText: Record<HostTable['status'], string> = {
    available: '可用',
    occupied: '用餐中',
    cleaning: '清桌中',
    disabled: '停用',
};

function choose(
    table: HostTable,
    key: 'leave' | 'clean' | 'disable' | 'enable',
) {
    open.value = null;
    emit('action', table, key);
}
</script>

<template>
    <div class="flex flex-col gap-5">
        <section v-for="area in areas" :key="area.label">
            <h3 class="mb-2 text-sm font-semibold text-steel/70">
                {{ area.label }}
            </h3>
            <ul
                class="grid grid-cols-[repeat(auto-fill,minmax(7.5rem,1fr))] gap-2"
            >
                <li
                    v-for="table in area.tables"
                    :key="table.id"
                    class="relative"
                >
                    <button
                        type="button"
                        class="tile flex h-24 w-full flex-col justify-between rounded-md p-2 text-left focus-visible:outline-3 focus-visible:outline-offset-2 focus-visible:outline-sign"
                        :class="[
                            `tile-${table.status}`,
                            { 'tile-held': table.held_for },
                        ]"
                        :aria-label="`${table.code}，${table.capacity} 席，${statusText[table.status]}`"
                        :aria-expanded="open === table.id"
                        @click="open = open === table.id ? null : table.id"
                    >
                        <span class="flex items-baseline justify-between">
                            <span
                                class="font-display text-2xl leading-none font-bold"
                                >{{ table.code }}</span
                            >
                            <span class="text-xs opacity-80"
                                >{{ table.capacity }} 席</span
                            >
                        </span>
                        <span
                            v-if="table.status === 'occupied'"
                            class="text-sm leading-tight"
                        >
                            {{ table.party_ticket }}，{{
                                table.party_size
                            }}
                            位<br />
                            <span class="font-display text-lg font-semibold"
                                >{{ table.elapsed_minutes }} 分</span
                            >
                        </span>
                        <span
                            v-else-if="table.held_for"
                            class="text-sm leading-tight font-semibold text-sign"
                        >
                            {{
                                table.hold_reason === 'called'
                                    ? '叫號保留'
                                    : '保護保留'
                            }}<br />{{ table.held_for }}
                        </span>
                        <span v-else class="text-sm">{{
                            statusText[table.status]
                        }}</span>
                    </button>

                    <div
                        v-if="open === table.id"
                        class="absolute top-full left-0 z-20 mt-1 flex min-w-full flex-col gap-1 rounded-md bg-steel p-1.5 shadow-lg"
                    >
                        <button
                            v-for="action in actionsFor(table)"
                            :key="action.key"
                            type="button"
                            class="h-11 rounded bg-board px-3 text-left font-semibold text-steel hover:bg-white disabled:opacity-50"
                            :disabled="busy"
                            @click="choose(table, action.key)"
                        >
                            {{ action.label }}
                        </button>
                        <p
                            v-if="actionsFor(table).length === 0"
                            class="px-2 py-1 text-sm text-board/80"
                        >
                            保留中，不能操作
                        </p>
                    </div>
                </li>
            </ul>
        </section>
    </div>
</template>

<style scoped>
.tile-available {
    background: #fff;
    color: var(--color-steel);
    box-shadow: inset 0 0 0 2px var(--color-beer);
}
.tile-occupied {
    background: var(--color-steel);
    color: var(--color-board);
}
.tile-cleaning {
    background: var(--color-yolk);
    color: var(--color-steel);
}
.tile-disabled {
    color: var(--color-steel);
    background: repeating-linear-gradient(
        135deg,
        #e3e5e2 0 8px,
        #d3d6d3 8px 16px
    );
}
.tile-held {
    box-shadow: inset 0 0 0 2px var(--color-sign);
    outline: 2px dashed var(--color-sign);
    outline-offset: -6px;
}
</style>
