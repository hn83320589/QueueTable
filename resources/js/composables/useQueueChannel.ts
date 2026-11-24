import { useEcho, useEchoPublic } from '@laravel/echo-vue';
import type { QueueSnapshot } from '@/types/queue';

/**
 * 訂閱公開的候位頻道（看板、顧客頁）。快照不含個資。
 */
export function useQueueChannel(onSnapshot: (snapshot: QueueSnapshot) => void) {
    return useEchoPublic<QueueSnapshot>('queue', '.queue.updated', onSnapshot);
}

/**
 * 訂閱帶位平板的私有頻道：只收到「有變動」，呼叫端再以 HTTP 重新取得含姓名的資料。
 */
export function useStaffChannel(onChange: () => void) {
    return useEcho('staff', '.floor.updated', onChange);
}
