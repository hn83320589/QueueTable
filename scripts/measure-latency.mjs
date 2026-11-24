/**
 * 量測「顧客取號／取消 → 看板收到 WebSocket 更新」的延遲（驗收 #7：2 秒內）。
 *
 * 走跟真實使用者完全相同的路徑：HTTP 表單送出（含 CSRF）→ Laravel → Reverb → 訂閱 queue 頻道的用戶端。
 *
 * 使用前先啟動網站與 Reverb（composer run dev），然後：
 *   node scripts/measure-latency.mjs [網站網址] [次數]
 *   node scripts/measure-latency.mjs http://127.0.0.1:8000 10
 */
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);
const Pusher = require('pusher-js');

const baseUrl = process.argv[2] ?? 'http://127.0.0.1:8000';
// /take 每分鐘限制 20 次，取號與取消各算一次動作，預設 10 輪 = 20 次量測
const rounds = Number(process.argv[3] ?? 10);

const env = Object.fromEntries(
    readFileSync(new URL('../.env', import.meta.url), 'utf8')
        .split('\n')
        .filter((line) => /^[A-Z_]+=/.test(line))
        .map((line) => {
            const [key, ...rest] = line.split('=');

            return [key, rest.join('=').replace(/^"|"$/g, '')];
        }),
);

/** 簡易 cookie jar，讓表單送出時帶上 session 與 XSRF-TOKEN */
const jar = new Map();

function remember(response) {
    for (const cookie of response.headers.getSetCookie()) {
        const [pair] = cookie.split(';');
        const [name, ...value] = pair.split('=');
        jar.set(name, value.join('='));
    }
}

function cookieHeader() {
    return [...jar].map(([k, v]) => `${k}=${v}`).join('; ');
}

async function post(path, body) {
    const response = await fetch(`${baseUrl}${path}`, {
        method: 'POST',
        redirect: 'manual',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            Cookie: cookieHeader(),
            'X-XSRF-TOKEN': decodeURIComponent(jar.get('XSRF-TOKEN') ?? ''),
        },
        body: new URLSearchParams(body),
    });
    remember(response);

    if (response.status !== 302) {
        throw new Error(`${path} 回應 ${response.status}`);
    }

    return response.headers.get('location');
}

const pusher = new Pusher(env.REVERB_APP_KEY, {
    wsHost: new URL(baseUrl).hostname,
    wsPort: Number(env.REVERB_PORT ?? 8080),
    forceTLS: (env.REVERB_SCHEME ?? 'http') === 'https',
    enabledTransports: ['ws', 'wss'],
    cluster: 'mt1',
});

let waiting = null;
pusher.subscribe('queue').bind('queue.updated', () => waiting?.());

function nextUpdate() {
    return new Promise((resolve, reject) => {
        const timer = setTimeout(
            () => reject(new Error('5 秒內沒有收到更新')),
            5000,
        );
        waiting = () => {
            clearTimeout(timer);
            waiting = null;
            resolve(performance.now());
        };
    });
}

async function measure(action) {
    const update = nextUpdate();
    const start = performance.now();
    await action();

    return (await update) - start;
}

await new Promise((resolve) => pusher.connection.bind('connected', resolve));
remember(await fetch(`${baseUrl}/take`));

const samples = [];
for (let i = 0; i < rounds; i++) {
    let location = '';
    samples.push(
        await measure(async () => {
            location = await post('/take', {
                size: '2',
                name: '量測',
                phone: `0987${String(Date.now()).slice(-6)}`,
            });
        }),
    );
    const token = new URL(location).pathname.split('/').pop();
    samples.push(await measure(() => post(`/q/${token}/cancel`, {})));
}

pusher.disconnect();

const sorted = [...samples].sort((a, b) => a - b);
const pick = (p) =>
    sorted[
        Math.min(sorted.length - 1, Math.ceil((p / 100) * sorted.length) - 1)
    ];
const ms = (n) => `${Math.round(n)} ms`;

console.log(`量測 ${samples.length} 次（取號與取消各 ${rounds} 次）`);
console.log(
    `中位數 ${ms(pick(50))}，P95 ${ms(pick(95))}，最長 ${ms(sorted.at(-1))}`,
);
console.log(
    sorted.at(-1) < 2000 ? '結果：全部在 2 秒內' : '結果：有超過 2 秒的更新',
);
