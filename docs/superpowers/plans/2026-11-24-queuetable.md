# QueueTable 實作計畫

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 建立熱炒店候位叫號與帶位系統：取號、帶位建議（含併桌與跳號保護）、等候預估、即時看板、操作紀錄、營業日報表與尖峰模擬器，滿足 `docs/requirements.md` 的 14 條驗收標準。

**Architecture:** 三層：純 PHP Domain（`app/Domain`，無 I/O：選桌、帶位、跳號、預估）→ Application Services（`app/Services`，交易 + 操作紀錄 + 狀態同步 + 推播）→ 薄 Controller + Inertia/Vue 頁面。所有時間取自 `now()`；測試用 `travelTo()`，模擬器用 `Carbon::setTestNow()` 推進虛擬時間。併發保證交給 SQLite 的 partial unique index 與條件式更新。

**Tech Stack:** PHP ≥ 8.3、Laravel 13（`laravel/vue-starter-kit:dev-main`：Fortify、Inertia v3、Vue 3 + TS、Tailwind 4、Wayfinder、vite-plus）、SQLite、Laravel Reverb + laravel-echo、Pest。

**Spec:** `docs/superpowers/specs/2026-11-24-queuetable-design.md`（設計），`docs/requirements.md`（題目原文）。執行者兩份都要讀；本計畫的「§x.y」指設計文件章節。

## Global Constraints

- composer `"php": "^8.3"`；Laravel 13；不使用 Docker。
- `config/app.php`：`timezone = 'Asia/Taipei'`，`locale = 'zh_TW'`。
- SQLite 連線：`busy_timeout = 5000`、`journal_mode = 'wal'`、`transaction_mode = 'IMMEDIATE'`。
- 所有時間只能來自 `now()` / `Carbon`；任何程式與測試都不得呼叫 `sleep`／`usleep`。
- 所有業務常數集中在 `config/queuetable.php`，數值見 Task 1；程式不得寫死這些數字。
- 所有狀態變更只能透過 `app/Services` 內的方法，且同一交易內寫 `activity_logs`。
- 公開頻道 `queue` 的 payload 不得包含 `name`、`phone`、`token`。
- 使用者看得到的文字一律繁體中文。
- 新測試一律用 Pest；每個 Task 結束時 `./vendor/bin/pest`、`vendor/bin/pint --test` 都要全綠；動到前端的 Task 另外要 `npm run build` 與 `npm run types:check` 通過。
- Commit message 用繁體中文，說明「為什麼」；不得用 `--no-verify`。

## Review Focus

1. **同一支手機用不同格式輸入**（`0912-345-678`、`+886912345678`、`0912 345 678`）→ 正規化成 `0912345678`，仍然被「同手機已有號碼」擋下。測試在 Task 9。
2. **兩台平板對同一組重複操作**（連按兩次叫號、入座）→ 第二次得到中文錯誤訊息（HTTP 422 或 redirect back 帶 errors），不是 500。測試在 Task 10（服務層）、Task 13（HTTP）。
3. **停用已被保留或用餐中的桌子** → 拒絕並說明原因。測試在 Task 11。
4. **客人在被叫號後自行取消** → 保留的桌子釋放，帶位建議立即重算。測試在 Task 10。
5. **營業日邊界**：01:59 取的號屬於前一營業日，06:00 取的號屬於當天；02:30 打開平板頁仍顯示前一營業日的佇列。測試在 Task 2、Task 13。

---

## 檔案結構

```
config/queuetable.php                 業務常數
config/simulation.php                 模擬器參數
app/Domain/
  BusinessDay.php                     營業日計算
  QueueLetter.php                     enum A/B/C/D + fromPartySize
  Enums/{PartyStatus,TableStatus,TableArea,HoldReason,Channel,ActorType}.php
  Seating/{TableSnapshot,FloorSnapshot,TableOption,OptionFinder}.php
  Seating/{WaitingParty,Suggestion,AllocationResult,SeatingAllocator,SkipRule}.php
  Estimation/{TimeSlot,DurationStats,DiningStatsTable,DiningStatsBuilder}.php
  Estimation/{ActiveSeating,EstimatorInput,Estimate,WaitEstimator}.php
  DiningTimeModel.php                 lognormal 用餐時間模型（history seeder 與模擬器共用）
app/Exceptions/QueueTableException.php  與各子類（中文訊息）
app/Support/{ActorContext,PhoneNumber}.php
app/Models/{DiningTable,TableAdjacency,Party,TicketCounter,Seating,SeatingTable,NotificationLog,ActivityLog,Device,User}.php
app/Services/{ActivityLogger,FloorRepository,QueueStateRefresher,TicketService,SeatingService,TableService}.php
app/Services/{QueueSnapshotBuilder,HostStateBuilder,QueueBroadcaster,SkipAuditor}.php
app/Services/Reports/{ReportBuilder,DailyReport,ReportCsvExporter}.php
app/Events/{QueueUpdated,FloorUpdated}.php
app/Http/Middleware/{EnsureDeviceSelected,EnsureManager,ExpireNoShows}.php
app/Http/Controllers/{Board,Customer,Host,Admin}/...
app/Simulation/{SimulationConfig,ArrivalGenerator,SimArrival,SimulationRunner,SimulationResult}.php
app/Console/Commands/SimulateCommand.php
database/seeders/{DatabaseSeeder,TableSeeder,StaffSeeder,HistorySeeder}.php
resources/js/pages/{board,customer,host,admin,device}/...
resources/js/composables/useQueueChannel.ts
scripts/measure-latency.mjs
```

---

### Task 1：專案骨架與基礎設定

**Files:**
- Create: 整個 Laravel 專案（從 starter kit 複製進 repo 根目錄，保留既有的 `docs/`、`.git/`）
- Create: `config/queuetable.php`
- Modify: `config/app.php`、`config/database.php`、`config/fortify.php`、`composer.json`（`scripts.dev`）、`.env.example`
- Test: `tests/Feature/ConfigTest.php`

**Interfaces:**
- Produces：`config('queuetable.*')` 的鍵：
  `business_day_cutoff_hour=6, max_party_size=12, max_waste=2, max_skips=3, no_show_minutes=3, requeue_limit=1, cleaning_minutes=5, coming_soon_ahead=2, estimate_history_days=14, estimate_min_samples=5, default_dining_minutes=['A'=>50,'B'=>65,'C'=>80,'D'=>95], default_dining_spread=15, expiry_check_seconds=10`

- [ ] **Step 1：建立骨架**
  `composer create-project laravel/vue-starter-kit:dev-main <scratchpad>/app` → `rsync -a --exclude .git <scratchpad>/app/ ./`；`npm install`。
- [ ] **Step 2：改裝 Pest、安裝 Reverb**
  `composer require pestphp/pest pestphp/pest-plugin-laravel --dev -W`；建立 `tests/Pest.php`，讓 `tests/Feature` 與 `tests/Unit` 的 Pest 測試使用 `Tests\TestCase` + `RefreshDatabase`。starter kit 原有的 PHPUnit class 測試保留可執行。
  `php artisan install:broadcasting --reverb --no-interaction`（若仍互動，手動 `composer require laravel/reverb`、`php artisan reverb:install`、`npm i laravel-echo pusher-js`）。
- [ ] **Step 3：寫失敗測試 `ConfigTest`**
  ```php
  it('uses Taipei timezone', fn () => expect(config('app.timezone'))->toBe('Asia/Taipei'));
  it('configures sqlite for concurrent writers', function () {
      expect(config('database.connections.sqlite.transaction_mode'))->toBe('IMMEDIATE')
          ->and(config('database.connections.sqlite.busy_timeout'))->toBe(5000);
  });
  it('exposes queuetable constants', fn () => expect(config('queuetable.max_skips'))->toBe(3)
      ->and(config('queuetable.no_show_minutes'))->toBe(3)->and(config('queuetable.max_waste'))->toBe(2));
  it('disables self registration', fn () => $this->get('/register')->assertNotFound());
  ```
- [ ] **Step 4：跑測試確認失敗** — `./vendor/bin/pest tests/Feature/ConfigTest.php`，預期 FAIL。
- [ ] **Step 5：設定**
  依 Global Constraints 修改 app/database 設定；新增 `config/queuetable.php`。
  `config/fortify.php` features 只保留登入（移除 registration、resetPasswords、emailVerification、twoFactorAuthentication、passkeys），並刪除對應的頁面與 starter kit 測試（Registration、EmailVerification、PasswordReset、TwoFactor、Passkey 等）。路由中的 `verified` middleware 一併移除。
  `composer.json` 的 `scripts.dev` 改為：`npx concurrently -c "#93c5fd,#c4b5fd,#fb7185,#fdba74" "php artisan serve --host=0.0.0.0" "php artisan reverb:start --host=0.0.0.0" "php artisan schedule:work" "npm run dev" --names=server,reverb,schedule,vite`。
  `.env.example`：`APP_TIMEZONE` 不需要（寫在 config）；Reverb 相關鍵由 install 指令產生；`BROADCAST_CONNECTION=reverb`。
- [ ] **Step 6：驗證** — `./vendor/bin/pest`、`vendor/bin/pint --test`、`npm run build` 全部通過。
- [ ] **Step 7：Commit** — `chore: 建立 Laravel 13 + Vue 骨架，改用 Pest、加入 Reverb 與業務常數`

---

### Task 2：營業日與佇列分類（Domain）

**Files:**
- Create: `app/Domain/BusinessDay.php`、`app/Domain/QueueLetter.php`、`app/Domain/Enums/*.php`
- Test: `tests/Unit/Domain/BusinessDayTest.php`、`tests/Unit/Domain/QueueLetterTest.php`

**Interfaces:**
- Produces：
  - `BusinessDay::for(CarbonInterface $at): string`（`Y-m-d`），`BusinessDay::today(): string`（= `for(now())`），`BusinessDay::window(string $date): array{0: CarbonImmutable, 1: CarbonImmutable}`（`[$date 06:00, 隔天 06:00)`）。cutoff 取自 config。
  - `enum QueueLetter: string { A, B, C, D }`，`static fromPartySize(int $size): self`（<1 或 > max_party_size 丟 `InvalidArgumentException`）。
  - Enums（皆 string-backed）：`PartyStatus{Waiting='waiting',Called='called',Seated='seated',NoShow='no_show',Cancelled='cancelled'}`、`TableStatus{Available='available',Occupied='occupied',Cleaning='cleaning',Disabled='disabled'}`、`TableArea{Indoor='indoor',Outdoor='outdoor',Bar='bar'}`、`HoldReason{Called='called',Protected='protected'}`、`Channel{Kiosk='kiosk',Remote='remote'}`、`ActorType{Staff='staff',Customer='customer',System='system'}`。每個 enum 有 `label(): string` 回傳中文。

- [ ] **Step 1：寫失敗測試**
  `BusinessDayTest`：`2026-11-24 17:00 → '2026-11-24'`；`2026-11-25 01:59 → '2026-11-24'`；`2026-11-25 05:59:59 → '2026-11-24'`；`2026-11-25 06:00 → '2026-11-25'`；`window('2026-11-24')` = `[2026-11-24 06:00, 2026-11-25 06:00]`。
  `QueueLetterTest`：dataset `[1→A, 2→A, 3→B, 4→B, 5→C, 6→C, 7→D, 12→D]`；`0` 與 `13` 丟例外。
- [ ] **Step 2：跑測試確認失敗**
- [ ] **Step 3：實作**
- [ ] **Step 4：跑測試確認通過** — `./vendor/bin/pest tests/Unit/Domain`
- [ ] **Step 5：Commit** — `feat: 營業日以 06:00 切日、依人數分佇列`

---

### Task 3：資料表、Model、Factory 與資料庫層約束

**Files:**
- Create: `database/migrations/*`（設計 §7 全部資料表，`users` 加 `role` 欄位）
- Create: `app/Models/*`（見檔案結構），`database/factories/{DiningTableFactory,PartyFactory,DeviceFactory}.php`，修改 `UserFactory`（預設 role=host，state `manager()`）
- Test: `tests/Feature/Database/ConstraintsTest.php`

**Interfaces:**
- Produces（Model 與欄位，cast 成 Task 2 的 enum）：
  - `DiningTable`：`code, capacity, area, status, held_for_party_id, hold_reason, status_changed_at`；關聯 `adjacentTables()`（雙向，經 `table_adjacencies`，存 `table_a_id < table_b_id`）。
  - `Party`：`business_date, queue, number, ticket_code, size, name, phone, channel, token(uuid), status, position_key(float), skip_count, no_show_count, requeue_count, issued_at, called_at, seated_at, cancelled_at, est_low_at_issue, est_high_at_issue, est_low, est_high`；`seating()` hasOne；`heldTables()` hasMany DiningTable。
  - `Seating`：`party_id, business_date, seated_at, left_at, user_id, device_id`；`tables()` belongsToMany 經 `seating_tables`（pivot `released_at`）。
  - `NotificationLog`：`party_id, type, message, created_at`。
  - `ActivityLog`：`occurred_at, actor_type, user_id, device_id, action, subject_type, subject_id, from_status, to_status, meta(json array)`。
  - `TicketCounter`：`business_date, queue, last_number`。`Device`：`name`。`User`：`role`（`manager`|`host`），`isManager(): bool`。
- 部分唯一索引以 `DB::statement` 建立：
  - `parties_active_phone_unique ON parties(business_date, phone) WHERE status IN ('waiting','called')`
  - `seating_tables_active_unique ON seating_tables(table_id) WHERE released_at IS NULL`

- [ ] **Step 1：寫失敗測試 `ConstraintsTest`**
  - `rejects duplicate ticket code in same business date`：建兩筆同 `(business_date, ticket_code)` → `QueryException`。
  - `rejects second active party with same phone`：兩筆 waiting 同手機 → `QueryException`；第一筆改 `cancelled` 後可建立。
  - `rejects a table in two active seatings`：同一 table 兩筆 `released_at = null` 的 seating_tables → `QueryException`；第一筆設 `released_at` 後可建立。
  - `rejects two seatings for one party`：`seatings.party_id` 唯一。
  - `rejects duplicate notification type per party`。
- [ ] **Step 2：跑測試確認失敗**
- [ ] **Step 3：實作 migrations、models、factories**
- [ ] **Step 4：跑測試確認通過**
- [ ] **Step 5：Commit** — `feat: 建立候位資料表，以 partial unique index 在資料庫層防止重複`

---

### Task 4：選桌選項（OptionFinder）

**Files:**
- Create: `app/Domain/Seating/{TableSnapshot,FloorSnapshot,TableOption,OptionFinder}.php`
- Test: `tests/Unit/Domain/OptionFinderTest.php`

**Interfaces:**
- Produces：
  - `final readonly class TableSnapshot(int $id, string $code, int $capacity, TableStatus $status, ?int $heldForPartyId = null, ?HoldReason $holdReason = null)`
  - `final readonly class FloorSnapshot(array $tables /* array<int,TableSnapshot> keyed by id */, array $adjacency /* list<array{int,int}> */)`；方法 `table(int $id): TableSnapshot`、`pairs(): list<array{int,int}>`、`with(array $tables): self`（回傳替換後的新 snapshot）。
  - `final readonly class TableOption(list<int> $tableIds, int $capacity, int $waste, bool $combined, string $sortCode)`
  - `final class OptionFinder(int $maxWaste)`：
    - `allOptions(int $size, FloorSnapshot $floor): list<TableOption>`：所有非 disabled 的適合單桌與相鄰兩桌組合，依 §5.7 排序（單桌優先 → waste → sortCode）。
    - `bestFreeOption(int $size, FloorSnapshot $floor, callable $isFree /* fn(TableSnapshot): bool */): ?TableOption`：先找全空的單桌選項，沒有才找全空的併桌選項。
    - `relevantTableIds(int $size, FloorSnapshot $floor): list<int>`：出現在任一 `allOptions` 中的桌子。
    - `isValidChoice(int $size, list<int> $tableIds, FloorSnapshot $floor, callable $isFree): bool`：tableIds 構成一個適合選項；若為併桌，則不得存在全空的單桌選項。

- [ ] **Step 1：寫失敗測試**（以小型 floor fixture：T2a(2)、T2b(2) 相鄰、T4(4)、T6(6)、T8(8)、T4x(4)–T6 相鄰）
  - `picks the table with least waste`：size 3，空 T4、T6 → T4。
  - `prefers single table over combo even when combo wastes less`：size 4，空 T2a+T2b（waste 0）與 T6（waste 2）→ T6。
  - `suggests combo only when no single table fits`：size 4，只有 T2a、T2b 空 → [T2a, T2b]。
  - `never combines non-adjacent tables`：size 4，只有 T2a 與一張不相鄰的 2 人桌空 → null。
  - `rejects option exceeding max waste`：size 1，只有 T4 空 → null（waste 3 > 2）。
  - `excludes disabled tables` 與 `relevantTableIds for size 10 returns T4x and T6`。
  - `isValidChoice rejects combo when a free single option exists`。
- [ ] **Step 2：跑測試確認失敗**
- [ ] **Step 3：實作**
- [ ] **Step 4：跑測試確認通過**
- [ ] **Step 5：Commit** — `feat: 選桌選項依單桌優先、浪費最少、僅相鄰併桌`

---

### Task 5：帶位演算法與跳號規則（SeatingAllocator、SkipRule）

**Files:**
- Create: `app/Domain/Seating/{WaitingParty,Suggestion,AllocationResult,SeatingAllocator,SkipRule}.php`
- Test: `tests/Unit/Domain/SeatingAllocatorTest.php`、`tests/Unit/Domain/SkipRuleTest.php`

**Interfaces:**
- Consumes：Task 4 的 `OptionFinder`、`FloorSnapshot`。
- Produces：
  - `final readonly class WaitingParty(int $id, QueueLetter $queue, int $size, float $positionKey, int $skipCount)`
  - `final readonly class Suggestion(int $partyId, TableOption $option)`
  - `final readonly class AllocationResult(list<Suggestion> $suggestions, array $protectedHolds /* array<int tableId,int partyId> */)`
  - `final class SeatingAllocator(OptionFinder $finder, int $maxSkips)`：`allocate(FloorSnapshot $floor, list<WaitingParty> $waiting): AllocationResult`。內部先依 `(positionKey, id)` 排序。free = `status === Available && holdReason !== HoldReason::Called`。演算法照 §5.8。
  - `final class SkipRule(OptionFinder $finder)`：`skippedPartyIds(list<int> $assignedTableIds, WaitingParty $called, list<WaitingParty> $waiting, FloorSnapshot $floor): list<int>`：全域順序在 `$called` 之前、且 `$assignedTableIds` 與其 `relevantTableIds` 有交集的 party id（§5.9）。

- [ ] **Step 1：寫失敗測試**
  - `suggests the earliest party that fits`：W1(size2) 在 W2(size4) 前；只有 T4 空 → 斷言 W1 → T4（waste 2 仍屬適合，先到先得）。
  - `lets a later smaller party go first when the head has no fitting table`：W1(size8, T8 occupied)、W2(size2)；T2a 空 → 建議 W2 → T2a。
  - `orders across queues by position key then id`：同 positionKey 時 id 小者先。
  - `can suggest several parties at once when several tables are free`。
  - `excludes tables held for a called party`。
  - `protected party gets a fully free option first`：W1(size2, skip 0)、P(size8, skip 3, key 較大)；T8 空 → P → T8。
  - `protected party holds every free relevant table`：P(size10, skip 3)、T4x 空、T6 occupied、W2(size4) 在後 → protectedHolds = [T4x => P]，W2 不得拿到 T4x（若無其他桌則無建議）。
  - `earlier protected party wins contested tables`。
  - `SkipRule counts only parties ahead whose relevant tables were taken`：P(size10) 在 X(size4) 前，X 拿 T4x → [P]；X 拿 T2a → []；排在 X 之後的組不算。
  - `SkipRule keeps counting protected parties`：P skip 3 且 X 拿走 P 的相關桌 → [P]（用來證明寫入關卡會攔下）。
- [ ] **Step 2：跑測試確認失敗**
- [ ] **Step 3：實作**（演算法見 §5.8；`allocate` 不修改傳入物件）
- [ ] **Step 4：跑測試確認通過**
- [ ] **Step 5：Commit** — `feat: 帶位建議演算法與資源版跳號規則`

---

### Task 6：等候預估（DiningStats、WaitEstimator）

**Files:**
- Create: `app/Domain/Estimation/*.php`
- Test: `tests/Unit/Domain/DiningStatsTest.php`、`tests/Unit/Domain/WaitEstimatorTest.php`

**Interfaces:**
- Consumes：`SeatingAllocator`、`SkipRule`、`FloorSnapshot`、`WaitingParty`。
- Produces：
  - `TimeSlot::of(CarbonInterface $t): int`：17–18 點 → 1、19–20 → 2、21–22 → 3、其他 → 4。
  - `final readonly class DurationStats(int $p25, int $p50, int $p75)`
  - `final readonly class DiningStatsTable(array $stats /* [queue][slot] => DurationStats */, array $queueFallback, array $defaults)`：`for(QueueLetter $q, int $slot): DurationStats`。
  - `DiningStatsBuilder::build(list<array{queue: QueueLetter, seated_at: CarbonInterface, minutes: int}> $samples, int $minSamples, array $defaultMinutes, int $spread): DiningStatsTable`；百分位用 nearest-rank：`rank = ceil(p/100 × n)`，取排序後第 rank 個。
  - `final readonly class ActiveSeating(list<int> $tableIds, QueueLetter $queue, CarbonImmutable $seatedAt)`
  - `final readonly class EstimatorInput(CarbonImmutable $now, FloorSnapshot $floor, list<ActiveSeating> $seatings, array $cleaningSince /* tableId => CarbonImmutable */, array $called /* list<array{queue: QueueLetter, tableIds: list<int>}> */, list<WaitingParty> $waiting)`
  - `final readonly class Estimate(?int $low, ?int $high)`（皆 null = 無法入座）
  - `final class WaitEstimator(SeatingAllocator $allocator, SkipRule $skipRule, int $cleaningMinutes)`：`estimate(EstimatorInput $in, DiningStatsTable $stats): array<int partyId, Estimate>`。演算法照 §5.11（樂觀 p25、悲觀 p75 各前推一次；虛擬帶位時用 SkipRule 更新虛擬 skip_count；區間 `[floor5(min), ceil5(max)]`，相等時上限 +5）。

- [ ] **Step 1：寫失敗測試**
  - `DiningStatsTest`：samples `[30,40,50,60,70]`（A, slot1）→ p25=40、p50=50、p75=60；同佇列他時段補足時退回 queueFallback；完全無樣本 → 預設 A=50、p25=35、p75=65。`TimeSlot`：17:30→1、20:59→2、22:00→3、23:10→4、02:30→4。
  - `WaitEstimatorTest::matches hand calculation`（驗收 #10，手算寫在註解）：
    - floor：T1(2) occupied 自 17:30 由 A 組入座；T2(4) occupied 自 17:20 由 B 組入座；無相鄰；cleaning 5；max_waste 2。
    - stats：A slot1 = (40,50,60)；B slot1 = (60,70,80)。now = 18:00。
    - waiting：W1(A,2,key1)、W2(B,4,key2)、W3(A,1,key3)。
    - 手算：樂觀 T1 18:15 空 → W1 等 15；T2 18:25 → W2 等 25；T1 19:00 → W3 等 60（W3 不能坐 T2：waste 3）。悲觀 T1 18:35 → W1 35；T2 18:45 → W2 45；T1 19:40 → W3 100。
    - 斷言：W1 = [15,35]、W2 = [25,45]、W3 = [60,100]。
  - `returns null estimate when no option can ever seat the party`（size 12，無 12 人以上選項）。
  - `widens a zero-width interval by five minutes`。
- [ ] **Step 2：跑測試確認失敗**
- [ ] **Step 3：實作**
- [ ] **Step 4：跑測試確認通過**
- [ ] **Step 5：Commit** — `feat: 以前推模擬計算等候區間，百分位採 nearest-rank 方便手算`

---

### Task 7：Seeder（桌位、帳號、平板、30 天歷史）

**Files:**
- Create: `database/seeders/{TableSeeder,StaffSeeder,HistorySeeder}.php`、`app/Domain/DiningTimeModel.php`；Modify: `DatabaseSeeder.php`
- Test: `tests/Feature/Database/SeederTest.php`

**Interfaces:**
- Consumes：Task 3 models、Task 4 `OptionFinder`（測試用）。
- Produces：
  - 桌位配置與相鄰關係照 §5.17（20 桌、80 席）。帳號 `manager@queuetable.test`（manager）、`host1@queuetable.test`、`host2@queuetable.test`（host），密碼 `password`；裝置「平板 A」「平板 B」。
  - `DiningTimeModel(Random\Randomizer $rng)`：`sample(QueueLetter $q, int $slot): int` 分鐘。lognormal，中位數 A 50、B 65、C 80、D 95，σ = 0.25，時段倍率 S1 0.95、S2 1.05、S3 1.0、S4 0.9，下限 20 分鐘。
  - `HistorySeeder`：以 `Randomizer(new Mt19937(20261124))` 產生今天以前 30 個營業日；每天 100–150 組；入座指派用「各桌最早空出時間」的貪婪法（必須用 `OptionFinder` 確認容量與相鄰）；5% cancelled、4% no_show（`no_show_count = 1`）；已入座組設定 `est_low_at_issue/est_high_at_issue` = 實際等候 ± 0–10 分鐘的雜訊（四捨五入到 5）；`skip_count = 0`；所有 seating 有 `left_at` 且 seating_tables 有 `released_at`。以 chunk insert 寫入。

- [ ] **Step 1：寫失敗測試 `SeederTest`**（`$this->seed()`）
  - `creates 20 tables with 80 seats`。
  - `every party size from 1 to 12 has at least one option`（dataset 1..12，用 `OptionFinder::allOptions` 不為空）。
  - `creates 30 business days of history before today`（distinct business_date = 30，最大 < `BusinessDay::today()`）。
  - `never overlaps seatings on the same table`（SQL：同 table_id 的兩筆 seating `a.seated_at < b.left_at AND b.seated_at < a.left_at` 筆數 = 0）。
  - `never seats more people than the table capacity`。
  - `creates manager, hosts and two devices`。
- [ ] **Step 2：跑測試確認失敗**
- [ ] **Step 3：實作**
- [ ] **Step 4：跑測試確認通過**；並手動 `php artisan migrate:fresh --seed` 在 5 秒內完成。
- [ ] **Step 5：Commit** — `feat: seeder 建立 20 桌、帳號、平板與 30 天歷史用餐紀錄`

---

### Task 8：操作紀錄、狀態讀取與同步（ActivityLogger、FloorRepository、QueueStateRefresher）

**Files:**
- Create: `app/Support/ActorContext.php`、`app/Services/{ActivityLogger,FloorRepository,QueueStateRefresher}.php`、`app/Exceptions/QueueTableException.php`
- Test: `tests/Feature/Services/QueueStateRefresherTest.php`、`tests/Feature/Services/ActivityLoggerTest.php`

**Interfaces:**
- Produces：
  - `final readonly class ActorContext(ActorType $type, ?int $userId = null, ?int $deviceId = null)`；`static system()`、`static customer()`、`static staff(User $u, Device $d)`、`static fromRequest(Request $r)`（已登入 + cookie `device_id` → staff，否則 customer）。
  - `ActivityLogger::record(ActorContext $actor, string $action, Model $subject, ?string $from, ?string $to, array $meta = []): ActivityLog`（`occurred_at = now()`）。action 名稱：`party.issued`、`party.called`、`party.seated`、`party.cancelled`、`party.no_show`、`party.requeued`、`table.occupied`、`table.cleaning`、`table.available`、`table.disabled`、`table.enabled`、`table.hold_changed`。
  - `FloorRepository`：`floor(): FloorSnapshot`、`waiting(string $date): list<WaitingParty>`（status waiting，依 `(position_key,id)`）、`estimatorInput(string $date): EstimatorInput`、`diningStats(string $date): DiningStatsTable`（近 `estimate_history_days` 個營業日、不含當天）、`aheadInQueue(Party $p): int`。
  - `QueueStateRefresher::refresh(string $date, ActorContext $actor): void`：必須在呼叫端的交易中執行；依序 (1) `SeatingAllocator::allocate` → 同步 `hold_reason = protected` 的保留（新增或移除時寫 `table.hold_changed`）；(2) `WaitEstimator` → 更新所有 waiting 的 `est_low/est_high`；(3) 對同佇列前面組數 ≤ `coming_soon_ahead` 的 waiting 組 `insertOrIgnore` 一筆 `notification_logs(type='coming_soon', message='您的號碼 {ticket} 即將輪到，請回到店門口')`；(4) `QueueBroadcaster::afterCommit($date)`（Task 12 才實作；本 Task 先定義空殼介面 `QueueBroadcaster::afterCommit(string $date): void`）。也提供 `currentSuggestions(string $date): AllocationResult`。
  - `QueueTableException extends RuntimeException`（訊息為中文，給使用者看）；子類：`DuplicateActivePhone`、`InvalidTransition`、`TableUnavailable`、`InvalidTableChoice`、`SkipLimitExceeded`、`RequeueNotAllowed`、`PartySizeOutOfRange`。

- [ ] **Step 1：寫失敗測試**
  - `creates exactly one coming-soon notification`（驗收 #8）：同佇列 4 組 waiting；refresh → 第 1–3 組各一則（前面 0、1、2 組），第 4 組沒有；再 refresh 兩次 → 總數不變；第 1 組改 seated 後 refresh → 第 4 組多一則，總數 4。
  - `syncs protected holds and removes them when no longer needed`。
  - `updates estimates for all waiting parties`。
  - `ActivityLoggerTest`：記錄 actor 的 user_id、device_id、occurred_at（`travelTo` 固定時間後斷言）。
- [ ] **Step 2：跑測試確認失敗**
- [ ] **Step 3：實作**（Allocator／Estimator 以 container 綁定，參數取自 config）
- [ ] **Step 4：跑測試確認通過**
- [ ] **Step 5：Commit** — `feat: 狀態同步器統一處理保護保留、預估更新與即將輪到通知`

---

### Task 9：取號與取消（TicketService）

**Files:**
- Create: `app/Services/TicketService.php`、`app/Support/PhoneNumber.php`
- Test: `tests/Feature/Services/TicketServiceTest.php`、`tests/Unit/PhoneNumberTest.php`

**Interfaces:**
- Consumes：`QueueStateRefresher`、`ActivityLogger`、`BusinessDay`、`QueueLetter`。
- Produces：
  - `PhoneNumber::normalize(string $raw): string`：去掉空白與 `-`，`+886` 開頭換成 `0`；結果必須符合 `/^09\d{8}$/`，否則丟 `InvalidArgumentException`。
  - `TicketService::issue(int $size, string $name, string $phone, Channel $channel, ActorContext $actor): Party`：在 `DB::transaction` 內：正規化手機 → 檢查同營業日 active 同手機（丟 `DuplicateActivePhone`，訊息含既有號碼，例如「此手機今天已有等候中的號碼 B012」）→ `ticket_counters` 遞增 → 建立 party（`position_key = now()->getPreciseTimestamp(3)/1000`、token = uuid）→ refresh → 把 refresh 後的 `est_low/high` 複製到 `est_*_at_issue` → log `party.issued`。
  - `TicketService::cancel(Party $party, ActorContext $actor): Party`：限 waiting/called；called 時釋放 `held_for_party_id = party` 的桌子；log；refresh。

- [ ] **Step 1：寫失敗測試**
  - `formats ticket as letter plus three digits`（size 3 → `B001`）。
  - `numbers are sequential without gaps per queue`：連續發 B、B、A、B → `B001, B002, A001, B003`。
  - `does not consume a number when issuing fails`：第二次重複手機失敗後，下一筆仍是 `B002`。
  - `restarts numbering on the next business day`：`travelTo('2026-11-25 01:59')` 取號 → 營業日 11-24；`travelTo('2026-11-25 17:00')` → `A001`、營業日 11-25。
  - `rejects a second active ticket for the same phone with reason`（驗收 #3）：訊息含既有號碼。
  - `treats differently formatted phones as the same`（Review Focus 1）：`0912-345-678` 與 `+886912345678`。
  - `allows a new ticket after cancelling`、`allows the same phone on a new business day`。
  - `rejects party size 0 and 13`。
  - `stores the estimate at issue time`。
  - `logs issue and cancel with actor`（驗收 #13）。
  - `PhoneNumberTest`：dataset。
- [ ] **Step 2：跑測試確認失敗**
- [ ] **Step 3：實作**
- [ ] **Step 4：跑測試確認通過**
- [ ] **Step 5：Commit** — `feat: 取號在交易內發號，同手機同營業日只能一筆`

---

### Task 10：叫號、入座、過號、重新排入（SeatingService）

**Files:**
- Create: `app/Services/SeatingService.php`
- Test: `tests/Feature/Services/SeatingServiceTest.php`、`tests/Feature/Services/NoShowTest.php`、`tests/Feature/Services/SkipProtectionTest.php`

**Interfaces:**
- Consumes：`OptionFinder::isValidChoice`、`SkipRule`、`FloorRepository`、`QueueStateRefresher`、`ActivityLogger`。
- Produces：
  - `call(Party $party, list<int> $tableIds, ActorContext $actor): Party`：交易內，(1) party 必須 waiting，否則 `InvalidTransition`；(2) `isValidChoice`，否則 `InvalidTableChoice`；(3) 這些桌子不可被「其他組」以 called 或 protected 保留，否則 `TableUnavailable`；(4) `SkipRule` 求被跳過的組，若任一組 `skip_count + 1 > max_skips` → `SkipLimitExceeded`；(5) 條件式更新 `UPDATE dining_tables SET held_for_party_id=?, hold_reason='called' WHERE id IN (...) AND status='available' AND (held_for_party_id IS NULL OR held_for_party_id=?)`，影響筆數 ≠ 桌數 → `TableUnavailable`；(6) 被跳過的組 `skip_count++`；(7) party → called、`called_at = now()`；(8) log `party.called`，meta：`{table_ids, ahead: [{party_id, size}], skipped_party_ids}`；(9) refresh。
  - `checkIn(Party $party, ActorContext $actor): Seating`：party 必須 called；建立 seating + seating_tables；桌子條件式更新為 occupied（`WHERE held_for_party_id = party AND status='available'`），清除保留；party → seated、`seated_at`；log `party.seated` 與每張桌 `table.occupied`；refresh。
  - `expireNoShows(ActorContext $actor): int`：`called_at <= now() - no_show_minutes` 的組 → no_show、`no_show_count++`、釋放保留；各寫 log；有變動才 refresh。回傳筆數。
  - `requeue(Party $party, ActorContext $actor): Party`：必須 no_show 且 `requeue_count < requeue_limit`，否則 `RequeueNotAllowed`（訊息「每組只能重新排入一次」）；同手機已有其他 active 號碼 → `DuplicateActivePhone`；`position_key` 依 §5.4；`requeue_count++`；→ waiting；log；refresh。

- [ ] **Step 1：寫失敗測試**
  - `SeatingServiceTest`
    - `calls a party and holds its tables`。
    - `seats a called party and occupies tables`。
    - `refuses to seat two parties at one table`（驗收 #9）：A 被叫號到 T1 後，B 叫號 T1 → `TableUnavailable`。
    - `refuses to seat a party twice`（驗收 #9）：checkIn 兩次 → 第二次 `InvalidTransition`；seatings 只有 1 筆（Review Focus 2）。
    - `rolls back when the conditional update loses the race`：測試中先直接把 T1 改為 occupied 再呼叫 `call` → 例外，party 仍 waiting、無 log。
    - `rejects combo when a single table is free`（驗收 #4）。
    - `releases tables when a called party cancels`（Review Focus 4）：取消後 T1 `held_for_party_id` 為 null，`currentSuggestions` 重新包含 T1。
    - `logs call and seat with user and device`（驗收 #13）。
  - `NoShowTest`（驗收 #6，全用 `travelTo`）
    - `does not expire at 2 minutes 59 seconds`、`expires at exactly 3 minutes`。
    - `requeues at third position`：同佇列 W1..W4 waiting，過號組重新排入後順序為 W1、W2、R、W3、W4。
    - `requeues after the second when exactly two are waiting`、`requeues last when fewer than two are waiting`。
    - `cannot requeue after the second no-show`。
    - `rejects requeue when the phone already has another active ticket`。
  - `SkipProtectionTest`（驗收 #5）
    - 情境：P(size10) 最先排；T4x–T6 相鄰，T6 occupied；後面 X1..X4(size4)。依序有 T4x 空出並叫 X1、X2、X3 → P.skip_count 分別到 1、2、3。
    - `holds the next relevant table for a party skipped three times`：第 3 次後 T4x 再空出 → refresh 後 T4x `hold_reason = protected`、`held_for_party_id = P`，`currentSuggestions` 不含 X4 → T4x。
    - `rejects a manual call that would skip a protected party a fourth time`：手動 `call(X4, [T4x])` → `SkipLimitExceeded`。
    - `seats the protected party when its full option frees up`：T6 離開並清桌 → 建議 P → [T4x, T6]。
- [ ] **Step 2：跑測試確認失敗**
- [ ] **Step 3：實作**
- [ ] **Step 4：跑測試確認通過**
- [ ] **Step 5：Commit** — `feat: 叫號入座以條件式更新防止重複帶位，過號重新排入第 3 位`

---

### Task 11：桌況操作（TableService）

**Files:**
- Create: `app/Services/TableService.php`
- Test: `tests/Feature/Services/TableServiceTest.php`

**Interfaces:**
- Produces：
  - `leave(DiningTable $table, ActorContext $actor): Seating`：table 必須 occupied；找出包含此桌的進行中 seating → `left_at = now()`；該 seating 所有桌子 `released_at = now()`、狀態改為 cleaning；log；refresh。
  - `markCleaned(DiningTable $t, ActorContext $a): DiningTable`（cleaning → available）、`disable(...)`（available 或 cleaning，且未被保留；否則 `InvalidTransition`，訊息說明原因）、`enable(...)`（disabled → available）。每次都更新 `status_changed_at`、寫 log、refresh。

- [ ] **Step 1：寫失敗測試**
  - `leaving a combined seating moves both tables to cleaning`。
  - `cleaned table becomes available and triggers new suggestions`。
  - `cannot disable an occupied table`、`cannot disable a held table`（Review Focus 3）。
  - `every table transition is logged with actor`（驗收 #13）：跑一輪 occupied→cleaning→available→disabled→available，斷言 4 筆 log 都有 user_id、device_id、occurred_at、from/to。
- [ ] **Step 2：跑測試確認失敗**
- [ ] **Step 3：實作**
- [ ] **Step 4：跑測試確認通過**
- [ ] **Step 5：Commit** — `feat: 桌況操作（離開、清桌、停用）並記錄操作人員與平板`

---

### Task 12：即時推播與過號排程

**Files:**
- Create: `app/Events/{QueueUpdated,FloorUpdated}.php`、`app/Services/{QueueSnapshotBuilder,QueueBroadcaster}.php`；Modify: `routes/channels.php`、`routes/console.php`
- Test: `tests/Feature/Broadcasting/QueueBroadcastTest.php`、`tests/Feature/Console/ExpireScheduleTest.php`

**Interfaces:**
- Produces：
  - `QueueSnapshotBuilder::build(string $date): array`，形狀：`['business_date'=>..., 'generated_at'=>ISO8601, 'queues'=>['A'=>['now_calling'=>?string,'waiting_count'=>int], ...], 'parties'=>[['ticket_code','queue','status','ahead','est_low','est_high'], ...]]`。`now_calling` = 該佇列 `called_at` 最新者的號碼；`parties` 只含 waiting/called。
  - `QueueUpdated(array $snapshot)`：`ShouldBroadcastNow`，`Channel('queue')`，`broadcastAs() = 'queue.updated'`。
  - `FloorUpdated(string $businessDate)`：`ShouldBroadcastNow`，`PrivateChannel('staff')`，`broadcastAs() = 'floor.updated'`。`routes/channels.php`：`staff` 頻道只允許已登入使用者。
  - `QueueBroadcaster::afterCommit(string $date): void`：`DB::afterCommit` 後送出兩個事件；同一交易內多次呼叫只送一次；送出失敗 `Log::warning('broadcast failed', [...])`，不往外丟。
  - 排程：`Schedule::call(fn () => app(SeatingService::class)->expireNoShows(ActorContext::system()))->everyTenSeconds()`。

- [ ] **Step 1：寫失敗測試**（驗收 #7）
  - `broadcasts queue and floor updates after seat, leave and cancel`：`Event::fake([QueueUpdated::class, FloorUpdated::class])`，分別執行 checkIn、leave、cancel → 每次各 assertDispatched。
  - `public snapshot never contains personal data`：`json_encode($snapshot)` 不含 name、phone、token 的值。
  - `snapshot reports now calling and waiting counts`。
  - `does not broadcast when the transaction rolls back`。
  - `logs a warning when broadcasting fails`：mock broadcaster 丟例外 → 業務操作成功、`Log::shouldReceive('warning')` 一次。
  - `ExpireScheduleTest`：`travelTo` 叫號 + 3 分鐘後執行 `schedule:run` → party 變 no_show。
- [ ] **Step 2：跑測試確認失敗**
- [ ] **Step 3：實作**（把 Task 8 的空殼換成真正實作）
- [ ] **Step 4：跑測試確認通過**
- [ ] **Step 5：Commit** — `feat: 交易提交後同步推播候位快照，排程每 10 秒檢查過號`

---

### Task 13：HTTP 層（路由、權限、平板識別、Controller）

**Files:**
- Create: `app/Http/Middleware/{EnsureDeviceSelected,EnsureManager,ExpireNoShows}.php`、`app/Services/HostStateBuilder.php`、`app/Http/Controllers/{Board/BoardController,Customer/TakeTicketController,Customer/PartyStatusController,Host/HostController,Host/PartyActionController,Host/TableActionController,DeviceController}.php`、`app/Http/Requests/IssueTicketRequest.php`；Modify: `routes/web.php`、`bootstrap/app.php`（例外轉換）
- Test: `tests/Feature/Http/{CustomerFlowTest,HostActionsTest,AccessControlTest,BoardTest}.php`

**Interfaces:**
- Produces（路由）：
  - 公開：`GET /` → 入口頁（連到看板、取號、登入）；`GET /board`（Inertia `board/Index`，prop `snapshot`、`takeUrl`）；`GET /take`、`POST /take`（channel remote → redirect `/q/{token}`）；`GET /q/{token}`（`customer/Status`，props `party{ticket_code,queue,status,size}`、`snapshot`）；`POST /q/{token}/cancel`。
  - auth：`GET /device`、`POST /device`（存 cookie `device_id`，`Cookie::forever`）。
  - auth + device：`GET /host`（`host/Index`，prop `state` = `HostStateBuilder::build($date)`）；`POST /host/parties`（channel kiosk）；`GET /host/parties/{party}/options`（JSON：該組合法選項）；`POST /host/parties/{party}/{call|check-in|cancel|requeue}`（call 帶 `table_ids[]`）；`POST /host/tables/{table}/{leave|clean|disable|enable}`。
  - manager：`/admin/*`（Task 15、16）。
  - `ExpireNoShows` middleware 套用在 `/host*`、`/q/*`、`/board`：先執行 `expireNoShows(ActorContext::system())`。
  - `HostStateBuilder::build(string $date): array`：`parties`（waiting/called，含姓名、手機末三碼、人數、等候分鐘、skip_count、status、est）、`tables`（code、capacity、area、status、held_for(ticket)、hold_reason、seated_at、elapsed_minutes、party ticket）、`suggestions`（ticket_code、table_codes、waste）。
  - 營業日一律用 `BusinessDay::today()`。
  - `bootstrap/app.php`：`QueueTableException` → web 請求 `back()->withErrors(['action' => $e->getMessage()])`，JSON 請求 422 `{message}`。

- [ ] **Step 1：寫失敗測試**
  - `CustomerFlowTest`：取號後 redirect 到 `/q/{token}` 且頁面含號碼；重複手機 → session errors 含原因；顧客可取消；錯誤 token 404。
  - `BoardTest`：`/board` 200、props 含四個佇列。
  - `HostActionsTest`：host 叫號 → 入座 → 離開 → 清桌，每步 302 且 log 帶 device_id；重複入座回錯誤訊息不是 500（Review Focus 2）；`travelTo('2026-11-25 02:30')` 時 `/host` 顯示 11-24 營業日的佇列（Review Focus 5）。
  - `AccessControlTest`：訪客進 `/host` → login；已登入但未選平板 → `/device`；host 進 `/admin/reports` → 403；`/`、`/board`、`/take` 訪客可開（驗收 #1）。
- [ ] **Step 2：跑測試確認失敗**
- [ ] **Step 3：實作**
- [ ] **Step 4：跑測試確認通過**
- [ ] **Step 5：Commit** — `feat: 帶位、顧客、看板路由與平板識別`

---

### Task 14：前端頁面（帶位平板、看板、取號、顧客狀態、選平板）

**Files:**
- Create: `resources/js/pages/{Welcome.vue, board/Index.vue, customer/Take.vue, customer/Status.vue, host/Index.vue, device/Select.vue}`、`resources/js/components/host/{QueueColumn,SuggestionCard,TableGrid,TableTile,IssueTicketDialog,TableOptionPicker}.vue`、`resources/js/composables/useQueueChannel.ts`；npm 套件 `qrcode`
- Test: 驗證以 build + types check + 瀏覽器實測

**Interfaces:**
- Consumes：Task 13 的 props 與路由；Task 12 的頻道與事件名稱。
- Produces：`useQueueChannel(onSnapshot: (s: QueueSnapshot) => void)`：訂閱 `queue` 頻道 `.queue.updated`；`useStaffChannel(onChange: () => void)`：訂閱 private `staff` 的 `.floor.updated`，呼叫端以 `router.reload({ only: ['state'] })` 更新。

- [ ] **Step 1：開始前先載入 `frontend-design:frontend-design` skill**，視覺方向照 §8：高對比、大字、觸控目標 ≥ 48px；看板 16:9 可遠距閱讀。
- [ ] **Step 2：實作各頁**
  - 平板：左側四個佇列欄 + 建議卡（「叫號」一鍵、可展開 `TableOptionPicker` 改選其他合法選項），右側桌位格依區域分組，顏色對應四種狀態 + 保留標記；每張桌顯示已用餐分鐘；動作按鈕依狀態顯示；錯誤以 toast 顯示中文訊息；「代客取號」對話框。
  - 看板：四欄大字「目前叫號」與「等候 N 組」、取號 QR Code（`qrcode` 產生 `takeUrl`）。
  - 顧客狀態：號碼、前面幾組、預估區間（null 時顯示「請洽現場店員」）、被叫號時整頁醒目提示、取消按鈕（確認用頁內對話框，不用 `window.confirm`）。
- [ ] **Step 3：驗證** — `npm run build`、`npm run types:check`、`npm run check` 通過。
- [ ] **Step 4：瀏覽器實測** — `composer run dev` + `php artisan migrate:fresh --seed`；用兩個瀏覽器視窗分別開 `/board` 與 `/q/{token}`，在 `/host` 入座／離開／取消，確認另外兩頁不重新整理就更新（驗收 #7 的人工確認）。
- [ ] **Step 5：Commit** — `feat: 帶位平板、門口看板與顧客頁面，WebSocket 即時更新`

---

### Task 15：店長管理（桌位、相鄰、帳號）

**Files:**
- Create: `app/Http/Controllers/Admin/{TableController,UserController}.php`、`resources/js/pages/admin/{Tables.vue,Users.vue}`
- Test: `tests/Feature/Http/AdminTest.php`

**Interfaces:**
- Produces：`GET/POST /admin/tables`、`PUT /admin/tables/{table}`（code、capacity 1–12、area）、`PUT /admin/tables/{table}/adjacency`（`adjacent_ids[]`，同步雙向關係，不能包含自己）；`GET/POST /admin/users`（name、email、password、role）。桌位與帳號變更都寫 activity log。

- [ ] **Step 1：寫失敗測試** — manager 可新增桌位、設定相鄰（雙向生效）、建立 host 帳號；host 呼叫回 403；新建的 host 可以登入。
- [ ] **Step 2：跑測試確認失敗**
- [ ] **Step 3：實作**
- [ ] **Step 4：跑測試與 build 確認通過**
- [ ] **Step 5：Commit** — `feat: 店長可設定桌位、相鄰關係與帶位人員帳號`

---

### Task 16：營業日報表與 CSV

**Files:**
- Create: `app/Services/Reports/{ReportBuilder,DailyReport,ReportCsvExporter}.php`、`app/Http/Controllers/Admin/ReportController.php`、`resources/js/pages/admin/Report.vue`
- Test: `tests/Feature/Reports/ReportBuilderTest.php`、`tests/Feature/Reports/ReportCsvTest.php`

**Interfaces:**
- Produces：
  - `ReportBuilder::build(string $date): DailyReport`；`DailyReport` 為 readonly：`issued, seated, noShowRate(float 0–1), cancelRate, perQueue: array<'A'|'B'|'C'|'D', array{avg: ?float, max: ?int}>, mae: ?float, intervalHitRate: ?float, turnover: array<string tableCode, int>, skipDistribution: array<0|1|2|3, int>`。定義照 §5.15；分鐘取 1 位小數。
  - `ReportCsvExporter::export(DailyReport $r): string`：UTF-8 BOM（`"\xEF\xBB\xBF"`）+ CRLF；四個區段：`指標,數值`、`佇列,平均等候(分),最長等候(分)`、`桌號,周轉次數`、`被跳過次數,組數`，區段之間空一行。
  - 路由：`GET /admin/reports?date=Y-m-d`（預設今天）、`GET /admin/reports/{date}/csv`（檔名 `queuetable-report-{date}.csv`，`Content-Type: text/csv; charset=UTF-8`）。

- [ ] **Step 1：寫失敗測試**（驗收 #12；fixture 營業日 2026-11-20，手算寫在註解）
  - A001（2 人）18:00 取號、18:10 入座 I01、18:25 離開，at_issue [5,15]，skip 0
  - A002（2 人）18:05 取號、18:35 入座 I01，at_issue [10,20]，skip 1
  - B001（4 人）18:00 取號、18:20 入座 I05，at_issue [20,30]，skip 0
  - B002（3 人）cancelled，skip 2
  - D001（8 人）no_show（no_show_count 1），skip 3
  - 斷言：issued 5、seated 3、noShowRate 0.2、cancelRate 0.2；A avg 20.0 / max 30；B avg 20.0 / max 20；C、D 為 null；MAE = (0+15+5)/3 = 6.7；turnover I01=2、I05=1、其他 0；skipDistribution `[0=>2, 1=>1, 2=>1, 3=>1]`。
  - `ReportCsvTest`：前 3 bytes 是 BOM；含 `取號數,5`；用 `str_getcsv` 解析區段正確；下載路由 header 正確；host 無法下載（403）。
- [ ] **Step 2：跑測試確認失敗**
- [ ] **Step 3：實作**（含報表頁：日期選擇、指標卡、各佇列表、周轉表、跳號分布、下載按鈕）
- [ ] **Step 4：跑測試與 build 確認通過**
- [ ] **Step 5：Commit** — `feat: 營業日報表與含 BOM 的 CSV 匯出`

---

### Task 17：跳號稽核與尖峰模擬器

**Files:**
- Create: `app/Services/SkipAuditor.php`、`app/Simulation/*.php`、`app/Console/Commands/SimulateCommand.php`、`config/simulation.php`；Modify: `config/database.php`（新增 `simulation` 連線，database 預設 `database_path('simulation.sqlite')`）、`.gitignore`
- Test: `tests/Feature/Services/SkipAuditorTest.php`、`tests/Feature/Simulation/SimulateCommandTest.php`

**Interfaces:**
- Consumes：`TicketService`、`SeatingService`、`TableService`、`QueueStateRefresher::currentSuggestions`、`DiningTimeModel`、`HistorySeeder`、`ReportBuilder`。
- Produces：
  - `SkipAuditor::audit(string $date): array<int partyId, int>`：只讀 `activity_logs` 中 `party.called` 的 `meta.table_ids` 與 `meta.ahead`，用 `OptionFinder::relevantTableIds` 獨立重算；不讀 `parties.skip_count`。
  - `config/simulation.php`：參數照 §5.16（到達率、人數分布、報到延遲、過號率 0.08、過號後重新排入 0.5、耐心 30–90、隨機取消 0.03、清桌 3–7、kiosk 0.6、tick 30 秒、取號截止 01:30、強制結束 03:00）。
  - `ArrivalGenerator::generate(Randomizer $rng, string $date, array $config): list<SimArrival>`；`SimArrival` readonly：`at, size, name, phone, channel, patienceMinutes, willNoShow, willRequeue, randomCancelAt`。
  - `SimulationRunner::run(string $date, int $seed): SimulationResult`；每個 tick 的順序照 §5.16；全部透過 Application Service 執行，actor 用 `ActorContext::system()`。
  - `SimulationResult` readonly：`issued, seated, noShows, cancelled, mae, intervalHitRate, avgWait, maxWait, perQueueMaxWait, maxSkipAudited, maxSkipStored, skipCheckPassed(bool), utilization, unservedAtEnd`。
  - 指令：`php artisan queuetable:simulate {--seed=42} {--date=} {--until=03:00} {--json=}`。切換到 `simulation` 連線 → `migrate:fresh` → seed 桌位與歷史 → 執行 → 以表格輸出，`--json` 時另外寫入檔案。broadcasting 設為 `null`。`skipCheckPassed = maxSkipAudited ≤ 3 且 maxSkipStored ≤ 3 且兩者一致`；不通過時 exit code 1。

- [ ] **Step 1：寫失敗測試**
  - `SkipAuditorTest`：重用 Task 10 的 P/X1..X3 情境 → audit 結果 P = 3，與 `skip_count` 一致；X 拿不相關桌的叫號不計。
  - `SimulateCommandTest`（`simulation` 連線指到 `storage/framework/testing/sim.sqlite`，`--until=19:00` 縮短時間）：exit 0；輸出含「跳號檢查 PASS」；相同 seed 跑兩次，`--json` 結果相同；不同 seed 結果不同。
- [ ] **Step 2：跑測試確認失敗**
- [ ] **Step 3：實作**
- [ ] **Step 4：跑測試確認通過**；完整跑 `php artisan queuetable:simulate --seed=42`，確認在 60 秒內結束且 PASS。若 MAE 明顯偏大（> 15 分鐘），先回報分析結果，不擅自改預估方法。
- [ ] **Step 5：Commit** — `feat: 尖峰模擬器與獨立跳號稽核`

---

### Task 18：延遲量測、README、截圖、CI

**Files:**
- Create: `scripts/measure-latency.mjs`、`README.md`、`docs/screenshots/{host,board,customer,report}.png`
- Modify: `.github/workflows/tests.yml`

**Interfaces:**
- Consumes：所有前述成果。

- [ ] **Step 1：延遲量測腳本** — Node（使用既有的 `pusher-js` 依賴）：訂閱 `queue` 頻道，以 HTTP 執行取號，再取消，量測從送出請求到收到 `queue.updated` 的毫秒數，重複 20 次，輸出 p50/max。在本機跑一次，把結果記下來（驗收 #7 的證據）。
- [ ] **Step 2：截圖** — 使用 Playwright MCP，在 seed 後並加入幾組候位的狀態下截四張圖：平板 1280×800、看板 1920×1080、顧客頁 390×844、報表 1280×900。
- [ ] **Step 3：README** — 章節：專案簡介、安裝與啟動（`composer install`、`npm install`、`cp .env.example .env`、`php artisan key:generate`、`touch database/database.sqlite`、`php artisan migrate --seed`、`composer run dev`；平板與手機如何連到主機 IP、Reverb 設定）、展示帳號、營業日定義與號碼重置、帶位建議與跳號上限演算法（含 B 資源版定義與例子）、等候預估方法、模擬器量測結果（seed 42 的實際輸出表）、延遲量測結果、系統架構圖（Mermaid）、資料表、截圖、測試（數量與執行方式）、已知限制（§11）。
- [ ] **Step 4：CI** — 修改 `tests.yml`：PHP 8.3、Node 22；`composer install` → `npm ci` → `cp .env.example .env && php artisan key:generate` → `npm run build` → `vendor/bin/pint --test` → `./vendor/bin/pest`。
- [ ] **Step 5：最終驗證** — 全新 clone 到 scratchpad，照 README 執行一次；`./vendor/bin/pest` 測試數 ≥ 45 且全部通過；逐條對照 `docs/requirements.md` 的驗收 1–14，在 README 末尾附「驗收對照表」（每條對應的測試檔或證據）。
- [ ] **Step 6：Commit** — `docs: README、截圖、延遲量測與 CI`
- [ ] **Step 7：推上 GitHub 前先徵求使用者同意**（建立 repo 是對外發布）。
