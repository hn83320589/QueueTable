<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import AdminShell from '@/components/admin/AdminShell.vue';
import InputError from '@/components/InputError.vue';

defineProps<{
    users: {
        id: number;
        name: string;
        email: string;
        role: 'host' | 'manager';
    }[];
}>();

const form = useForm({
    name: '',
    email: '',
    password: '',
    role: 'host' as 'host' | 'manager',
});

function submit() {
    form.post('/admin/users', {
        preserveScroll: true,
        onSuccess: () => form.reset(),
    });
}
</script>

<template>
    <Head title="帳號" />
    <AdminShell title="帳號">
        <div class="grid gap-8 md:grid-cols-[1fr_22rem]">
            <table class="w-full border-collapse self-start text-left">
                <thead>
                    <tr
                        class="border-b-2 border-steel/20 text-sm text-steel/70"
                    >
                        <th class="py-2 font-semibold">姓名</th>
                        <th class="py-2 font-semibold">Email</th>
                        <th class="py-2 font-semibold">角色</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="user in users"
                        :key="user.id"
                        class="border-b border-steel/10"
                    >
                        <td class="py-2">{{ user.name }}</td>
                        <td class="py-2">{{ user.email }}</td>
                        <td class="py-2">
                            {{ user.role === 'manager' ? '店長' : '帶位人員' }}
                        </td>
                    </tr>
                </tbody>
            </table>

            <form
                class="flex flex-col gap-3 rounded-md bg-white p-4"
                @submit.prevent="submit"
            >
                <h2 class="font-bold">建立帳號</h2>
                <label class="flex flex-col gap-1 text-sm"
                    >姓名<input
                        v-model="form.name"
                        class="h-11 rounded border-2 border-steel/20 px-2 text-base"
                /></label>
                <InputError :message="form.errors.name" />
                <label class="flex flex-col gap-1 text-sm"
                    >Email<input
                        v-model="form.email"
                        type="email"
                        class="h-11 rounded border-2 border-steel/20 px-2 text-base"
                /></label>
                <InputError :message="form.errors.email" />
                <label class="flex flex-col gap-1 text-sm"
                    >密碼（至少 8 碼）<input
                        v-model="form.password"
                        type="password"
                        autocomplete="new-password"
                        class="h-11 rounded border-2 border-steel/20 px-2 text-base"
                /></label>
                <InputError :message="form.errors.password" />
                <label class="flex flex-col gap-1 text-sm">
                    角色
                    <select
                        v-model="form.role"
                        class="h-11 rounded border-2 border-steel/20 px-2 text-base"
                    >
                        <option value="host">帶位人員</option>
                        <option value="manager">店長</option>
                    </select>
                </label>
                <button
                    type="submit"
                    class="mt-2 h-11 rounded-md bg-sign font-bold text-white disabled:opacity-60"
                    :disabled="form.processing"
                >
                    建立帳號
                </button>
            </form>
        </div>
    </AdminShell>
</template>
