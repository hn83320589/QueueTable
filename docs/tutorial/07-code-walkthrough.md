# 07 程式內容導讀

這一章帶你實際讀一遍程式碼。先建議閱讀順序，再從啟動開始，走完一次「叫號」的完整生命週期，最後逐一看核心模組。

## 建議的閱讀順序

| 順序 | 檔案 | 為什麼先讀它 |
|---|---|---|
| 1 | `config/queuetable.php` | 所有業務常數都在這裡，先知道規則的參數 |
| 2 | `app/Domain/Enums/PartyStatus.php`、`TableStatus.php`、`HoldReason.php` | 狀態的詞彙表 |
| 3 | `app/Domain/Seating/OptionFinder.php` | 最底層的規則：一組人能坐哪些桌子 |
| 4 | `app/Domain/Seating/SeatingAllocator.php`、`SkipRule.php` | 帶位建議與跳號 |
| 5 | `app/Services/SeatingService.php` | 規則如何被包進交易、條件式更新與紀錄 |
| 6 | `app/Services/QueueStateRefresher.php` | 每次狀態變更後同步什麼 |
| 7 | `app/Domain/Estimation/WaitEstimator.php` | 用規則本身前推預估 |
| 8 | `resources/js/pages/host/Index.vue` | 平板怎麼把這些串起來 |
| 9 | `app/Simulation/SimulationRunner.php` | 用模擬驗證整套規則 |

## 啟動時做了什麼

`bootstrap/app.php` 設定路由、中介軟體別名與例外轉譯；`app/Providers/AppServiceProvider.php` 做三件事：

```php
// app/Providers/AppServiceProvider.php
public function register(): void
{
    // Domain 物件的參數取自 config；用 bind（非 singleton），模擬器與測試改 config 時才會生效
    $this->app->bind(OptionFinder::class, fn () => new OptionFinder(
        (int) config('queuetable.max_waste'),
        (int) config('queuetable.relaxed_max_waste'),
        (int) config('queuetable.relax_after_minutes'),
    ));
    // ...SeatingAllocator、SkipRule、WaitEstimator 同樣從 config 取參數

    // 同一個請求內共用，才能把同一交易內的多次推播合併成一次
    $this->app->scoped(QueueBroadcaster::class);
}
```

兩個值得注意的選擇：

- **Domain 類別不讀 config**。`OptionFinder` 的建構子只收數字，由容器在這裡把 config 傳進去。所以單元測試可以直接 `new OptionFinder(maxWaste: 2)`，不需要啟動 Laravel。
- **`QueueBroadcaster` 用 `scoped`**。它要記住「這個交易已經排過推播了」，所以同一個請求內必須是同一個實例；用 `bind` 每次都是新的，合併就失效。

`boot()` 另外設定 `Date::use(CarbonImmutable::class)`（`now()` 回傳不可變物件），以及 `configureDevCommands()`（`composer run dev` 要啟動哪些程序）。

## 一次叫號的完整生命週期

以「帶位人員按下建議卡的叫號」為例，依序經過每一層。

### 第 1 站：前端送出

```ts
// resources/js/composables/useHostAction.ts
function post(url: string, data: Record<string, unknown> = {}, onSuccess?: () => void) {
    busy.value = true;
    router.post(url, data as never, {
        preserveScroll: true,
        preserveState: true,
        only: ['state'],
        onError: (errors) => {
            toast.error(Object.values(errors)[0] ?? '操作失敗，請重新整理後再試');
        },
        // ...
    });
}
```

`only: ['state']` 讓 Inertia 只重新取回平板狀態，而不是整頁重新渲染。業務規則違反時，後端回傳的錯誤直接用 toast 顯示中文原因。

### 第 2 站：Controller

```php
// app/Http/Controllers/Host/PartyActionController.php
public function call(Request $request, Party $party): RedirectResponse
{
    $data = $request->validate([
        'table_ids' => ['required', 'array', 'min:1', 'max:2'],
        'table_ids.*' => ['integer'],
    ], [], ['table_ids' => '桌子']);

    $ids = array_values(array_map(intval(...), $data['table_ids']));
    $this->seating->call($party, $ids, ActorContext::fromRequest($request));

    return $this->done("請 {$party->ticket_code} 到門口報到");
}
```

Controller 只做三件事：驗證輸入格式、把 request 轉成 `ActorContext`（誰、哪台平板）、呼叫服務。沒有 try/catch，規則違反由 `bootstrap/app.php` 統一處理。

### 第 3 站：SeatingService::call()

```php
// app/Services/SeatingService.php
return DB::transaction(function () use ($party, $tableIds, $actor): Party {
    $party->refresh();                                                    // ①
    // ...確認狀態是 waiting

    $floor = $this->floors->floor();
    $this->assertNotHeldByOthers($party, $tableIds, $floor, HoldReason::Called);   // ②
    $this->assertValidChoice($party, $tableIds, $floor);
    $waiting = $this->floors->waiting($party->business_date);
    $skipped = $this->assertSkipLimit($party, $tableIds, $waiting, $floor);
    $this->assertNotHeldByOthers($party, $tableIds, $floor, HoldReason::Protected);

    $affected = DiningTable::query()                                      // ③
        ->whereIn('id', $tableIds)
        ->where('status', TableStatus::Available)
        ->where(fn ($q) => $q->whereNull('held_for_party_id')->orWhere('held_for_party_id', $party->id))
        ->update(['held_for_party_id' => $party->id, 'hold_reason' => HoldReason::Called]);
    if ($affected !== count($tableIds)) {
        throw new TableUnavailable('桌子剛被其他平板使用，請重新整理後再試');
    }
    // ...skip_count++、party 改 called、寫操作紀錄（meta 含 ahead、free_tables）
    $this->refresher->refresh($party->business_date, $actor);             // ④

    return $party;
});
```

① **在交易內重新讀取**。Controller 拿到的 `$party` 是路由綁定時讀的，可能已經過時（另一台平板剛叫了他）。

② **檢查順序決定錯誤訊息**。先擋別人的叫號保留，再檢查選項是否合法，再檢查跳號上限，最後擋保護保留。順序反過來，手動把後面的人帶到保護組的桌子時，使用者只會看到籠統的「桌子不可用」，而不是「B003 已被跳過 3 次，這張桌子必須保留給他」。📄 開發紀錄 Task 10 的 Ruling。

③ **條件式更新**。`WHERE` 裡重複一次「可用、沒被別人保留」的條件，再檢查影響筆數。即使前面的讀取和這裡的寫入之間有別的程序動了桌子，也只會有一個成功。搭配 `IMMEDIATE` 交易，這是第二道防線；第三道是資料庫的唯一索引（[05 章](05-database.md)）。

④ **最後一定呼叫 refresh**。叫號改變了桌況，所有人的預估、保護保留、通知都要跟著更新。

### 第 4 站：QueueStateRefresher::refresh()

```php
// app/Services/QueueStateRefresher.php
public function refresh(string $businessDate, ActorContext $actor): void
{
    $this->syncProtectedHolds($this->currentSuggestions($businessDate), $actor);
    $this->updateEstimates($businessDate);
    $this->createComingSoonNotifications($businessDate);
    $this->broadcaster->afterCommit($businessDate);
}
```

四件事各一行，順序有意義：先決定保護保留（會影響預估），再重算預估，再看誰前面剩 2 組要通知，最後排定推播。

「即將輪到」通知用 `insertOrIgnore` 搭配唯一索引 `(party_id, type)`，所以 refresh 被呼叫幾次都只會有一則（驗收 8）。

### 第 5 站：交易提交後推播

```php
// app/Services/QueueBroadcaster.php
public function afterCommit(string $businessDate): void
{
    if (isset($this->pending[$businessDate])) {
        return;                                    // 同一交易內已排過，不重複送
    }
    $this->pending[$businessDate] = true;

    DB::afterCommit(function () use ($businessDate) {
        unset($this->pending[$businessDate]);
        $this->dispatch($businessDate);            // 送出 QueueUpdated、FloorUpdated
    });
}
```

`DB::afterCommit` 讓推播在交易真正提交後才送出；交易 rollback 的話，callback 不會執行，畫面不會出現一個其實沒發生的變動。`dispatch()` 用 try/catch 包住，Reverb 沒啟動時只記 warning，不讓已完成的叫號失敗。

### 第 6 站：其他畫面收到更新

```ts
// resources/js/composables/useQueueChannel.ts
export function useQueueChannel(onSnapshot: (snapshot: QueueSnapshot) => void) {
    return useEchoPublic<QueueSnapshot>('queue', '.queue.updated', onSnapshot);
}

export function useStaffChannel(onChange: () => void) {
    return useEcho('staff', '.floor.updated', onChange);
}
```

事件名稱前面的 `.` 是因為事件類別自訂了 `broadcastAs()`；沒有這個點，Echo 會自動加上 `App\Events\` 命名空間而對不上。看板直接用收到的快照更新畫面；平板收到訊號後 `router.reload({ only: ['state'] })`。

## 核心模組

### 營業日：`app/Domain/BusinessDay.php`

**職責**：時間 → 營業日字串。

```php
// app/Domain/BusinessDay.php
public static function for(CarbonInterface $at): string
{
    return CarbonImmutable::instance($at)
        ->setTimezone(config('app.timezone'))
        ->subHours(self::cutoffHour())
        ->toDateString();
}
```

**值得學的寫法**：「往回推 6 小時再取日期」把跨午夜的問題變成一行。凌晨 01:59 減 6 小時是前一天 19:59，自然落在前一個營業日。所有用到「今天」的地方都呼叫 `BusinessDay::today()`，沒有任何地方直接用 `now()->toDateString()`。

### 選桌：`app/Domain/Seating/OptionFinder.php`

**職責**：給定人數與桌況，列出所有適合的選項（單桌或相鄰兩桌），並依「單桌優先 → 浪費少 → 桌號小」排序。

**關鍵方法**：

- `allOptions()`：所有非停用桌構成的選項，不管空不空。
- `bestFreeOption()`：找第一個全部空著的選項。「空」由呼叫端傳入的 `callable $isFree` 決定。
- `relevantTableIds()`：出現在任何適合選項裡的桌子，也就是「對這組有用的桌子」。
- `bestFreeOptionFor()`、`isValidChoiceFor()`：加上「等候滿 15 分鐘可放寬浪費上限」的版本。

**值得學的寫法**：「空」不寫死在 `OptionFinder` 裡，而是由呼叫端傳入 `callable $isFree`。帶位建議、叫號檢查、跳號判斷、預估器對「空」的定義各有細微差異（例如保留給自己的桌子算不算空），同一個方法就能服務所有人。

**可以改進的地方**：`relevantTableIds()` 每次都重新列舉所有選項。20 張桌的規模沒問題，但預估器在前推時會呼叫很多次，桌數變多時可以依人數快取。

### 帶位建議：`app/Domain/Seating/SeatingAllocator.php`

演算法細節見 [08 章](08-algorithms.md)。這裡看一個實作上的小陷阱：

```php
// app/Domain/Seating/SeatingAllocator.php
// 以參照捕捉：已分配或已保留的桌子從 $free 移除後，後面的組就看不到
$isFree = function (TableSnapshot $t) use (&$free): bool {
    return isset($free[$t->id]);
};
```

最初寫成箭頭函式 `fn (TableSnapshot $t) => isset($free[$t->id])`。PHP 的箭頭函式是**以值捕捉**外部變數，所以之後 `unset($free[$id])` 對它沒有效果，同一張桌子被建議給兩組人。改成 `use (&$free)` 以參照捕捉才修好。📄 開發紀錄 Task 5（測試 `suggests the earliest party that fits` 抓到）。

### 叫號、入座、過號：`app/Services/SeatingService.php`

**職責**：`call()`、`checkIn()`、`expireNoShows()`、`requeue()` 四個狀態變更。

**值得學的寫法**：重新排入的排序鍵計算。

```php
// app/Services/SeatingService.php（thirdPositionKey 節錄）
if (count($keys) >= 3) {
    return ($keys[1] + $keys[2]) / 2;      // 第 2、3 位中點 → 成為第 3 位
}
if (count($keys) === 2) {
    return $keys[1] + 1;                   // 只有 2 組 → 排第 2 組後面
}
$last = end($keys);

return $last === false ? $now : max($now, $last + 1);
```

用浮點數排序鍵，插隊只要取中點，不必改動其他人的資料。這依賴「取號時的排序鍵嚴格遞增」；同一毫秒取號時兩個鍵相同，中點會等於其中一個，重新排入的組就會因為 id 較小排到第 2 位。`TicketService::nextPositionKey()` 因此保證同營業日內嚴格遞增（commit `9ace858`）。

**可以改進的地方**：排序鍵是十億級的時間戳記，double 在這個量級的最小間距約 2.4×10⁻⁷ 秒；同一個間隙連續取中點二、三十次就會耗盡精度。每組只能重新排入一次，實務上不會發生，所以沒處理。

### 取號：`app/Services/TicketService.php`

**職責**：發號與取消。

**值得學的寫法**：號碼計數器。

```php
// app/Services/TicketService.php（nextNumber 節錄）
TicketCounter::query()->insertOrIgnore(['business_date' => $date, 'queue' => $queue->value, 'last_number' => 0]);
TicketCounter::query()->where('business_date', $date)->where('queue', $queue->value)->increment('last_number');
$number = (int) TicketCounter::query()->where('business_date', $date)->where('queue', $queue->value)->value('last_number');
```

先確保計數器存在，再用 SQL 的 `last_number = last_number + 1` 遞增，最後讀回來。整段在發號的交易內，任何一步失敗就 rollback，號碼不會被吃掉，所以不會跳號（驗收 2）。

### 誰做的：`app/Support/ActorContext.php`

**職責**：把「員工（含哪台平板）、顧客、系統」包成一個不可變物件，傳給每個 Service 方法。

**值得學的寫法**：Service 方法明確收 `ActorContext` 參數，而不是在 Service 裡讀 `auth()->user()`。好處是排程（`ActorContext::system()`）和模擬器也能呼叫同一個 Service，測試也能直接指定操作者，不必模擬登入。

### 等候預估：`app/Domain/Estimation/WaitEstimator.php`

**職責**：樂觀（P25）與悲觀（P75）兩個情境各前推一次，得到每組的預計入座時間。

前推的核心迴圈：

```php
// app/Domain/Estimation/WaitEstimator.php（simulate 節錄）
while ($remaining !== []) {
    $floor = $this->floorAt($input->floor, $freeAt, $t);                   // 虛擬時間 t 的桌況
    $result = $this->allocator->allocate($floor, $remaining, $t->getTimestamp());

    foreach ($result->suggestions as $suggestion) {
        // ...記下這組在 t 入座
        // ...用 SkipRule 更新其他人的虛擬跳過次數
        // ...這組的桌子在 t + 用餐時間 + 清桌時間 後再空出
    }
    // ...t 前進到下一張桌子空出的時間；沒有就結束
}
```

**值得學的寫法**：預估器裡沒有任何「帶位規則」，它只負責推進虛擬時間，帶位交給真正的 `SeatingAllocator`。規則改了，預估自動跟著變。

**可以改進的地方**：每次狀態變更都會重算所有人的預估，還會重讀 14 天的歷史統計。每晚 150 組的規模沒問題，但歷史統計在同一營業日內不會變，可以快取。

### 推播的快照：`app/Services/QueueSnapshotBuilder.php`

**職責**：組出公開頻道的快照。

**值得學的寫法**：快照的欄位是**白名單**：只放號碼、佇列、狀態、前面組數、預估。測試 `never puts personal data on the public channel` 會建立一個有姓名、手機的客人，再確認序列化後的 JSON 裡找不到這些值。

### 帶位平板：`resources/js/pages/host/Index.vue`

**職責**：左邊是建議叫號、等待報到、各佇列候位、過號清單；右邊是桌況格（`resources/js/components/host/TableGrid.vue`）。

**值得學的寫法**：

- 「等待報到」的倒數只在前端每秒計算，不需要伺服器推播。
- 除了 WebSocket，另外每 30 秒 reload 一次 `state`：用餐分鐘數會隨時間變，過號也可能在沒有任何操作時發生。
- 改桌對話框（`resources/js/components/host/OptionPicker.vue`）從 `/host/parties/{party}/options` 取得選項，顯示的一定是後端認為合法的選項，前端不做規則判斷。

### 門口看板：`resources/js/pages/board/Index.vue`

**值得學的寫法**：看板只在「目前叫號」改變時播一次翻牌動畫，其他時候不動；並用 `prefers-reduced-motion` 尊重使用者的減少動態設定。視覺取材自熱炒店牆上的直式手寫菜單板（白底紅色雙線框）。📄 開發對話中的設計說明。

**可以改進的地方**：看板與顧客頁沒有檢查收到的快照是不是同一個營業日；06:00 前後收到前一營業日的推播時，顧客頁會多 reload 一次。列在延後處理清單。

### 尖峰模擬器：`app/Simulation/SimulationRunner.php`

**職責**：用加速時鐘（每步 30 秒）跑完整晚，所有動作都呼叫真正的 Service。

**值得學的寫法**：

```php
// app/Simulation/SimulationRunner.php
// 7. 帶位人員依系統建議叫號：和平板一樣，每叫一組就重新取得建議
while (($suggestion = $this->refresher->currentSuggestions($date)->suggestions[0] ?? null) !== null) {
    $before = array_sum($rejected);
    $this->attempt(fn () => $this->seating->call(...), $rejected);
    if (array_sum($rejected) > $before) {
        break; // 建議被拒絕：已計數，下一步再試，避免無限重試
    }
}
```

被業務規則拒絕的建議**不吞掉**，依例外類別計數並輸出；只要有任何系統建議被拒，就判定「建議一致性 FAIL」。最初的版本把例外靜默吞掉，結果藏住了一個「系統建議按下去會失敗」的 bug（見 [11 章](11-tradeoffs-pitfalls.md)）。

### 獨立稽核：`app/Services/SkipAuditor.php`

**職責**：只讀操作紀錄中的叫號（分配了哪些桌、當時排在前面的有誰、當下哪些桌空著），用同一條 `SkipRule::counts()` 依時間順序重算每組被跳過幾次，不讀 `parties.skip_count`。

**值得學的寫法**：「是否已進入保護」也用稽核器自己累計的次數判斷，所以就算系統寫入 `skip_count` 的邏輯有 bug，稽核結果也不會跟著錯，兩者一比對就會發現。

**可以改進的地方**：判斷「對他有用的桌子」時用的是**現在**的桌位設定；營業中修改過桌位，稽核結果可能和當時不同。
