import { router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { toast } from 'vue-sonner';

/**
 * 帶位平板的操作：POST 後只重新載入 state，失敗時把伺服器的中文原因用 toast 顯示。
 */
export function useHostAction() {
    const busy = ref(false);

    function post(
        url: string,
        data: Record<string, unknown> = {},
        onSuccess?: () => void,
    ) {
        busy.value = true;
        router.post(url, data as never, {
            preserveScroll: true,
            preserveState: true,
            only: ['state'],
            onSuccess: () => onSuccess?.(),
            onError: (errors) => {
                toast.error(
                    Object.values(errors)[0] ?? '操作失敗，請重新整理後再試',
                );
            },
            onFinish: () => {
                busy.value = false;
            },
        });
    }

    return { busy, post };
}
