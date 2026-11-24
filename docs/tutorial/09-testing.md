# 09 測試

目前共 232 個測試案例（178 個測試函式，部分以 dataset 展開），全部通過。題目要求至少 45 個。

## 測試策略

| 層級 | 位置 | 測什麼 | 怎麼測 |
|---|---|---|---|
| 單元（Domain） | `tests/Unit/Domain/` | 選桌、帶位建議、跳號規則、等候預估、營業日、百分位 | 直接 `new` 物件，用 `makeFloor()` 組桌況快照，不碰資料庫 |
| 單元（工具） | `tests/Unit/PhoneNumberTest.php` | 手機正規化 | dataset 列舉各種寫法 |
| 服務整合 | `tests/Feature/Services/` | 交易、條件式更新、操作紀錄、保護保留、通知 | 用記憶體 SQLite，factory 建資料，`travelTo()` 控制時間 |
| 資料庫約束 | `tests/Feature/Database/` | partial unique index、seeder 產出 | 直接寫入違規資料，期待 `QueryException` |
| HTTP | `tests/Feature/Http/` | 路由、權限、錯誤訊息、Inertia props | `get`／`post`／`postJson` + `assertInertia` |
| 推播 | `tests/Feature/Broadcasting/` | 事件有送出、payload 無個資、rollback 不送、推播失敗只記 warning | `Event::fake()`、自訂一個會丟例外的 broadcaster |
| 報表 | `tests/Feature/Reports/` | 每個數字與手算一致、CSV 有 BOM | 固定資料 `tests/Feature/Reports/fixture.php` |
| 模擬器 | `tests/Feature/Simulation/` | 跑得完、同 seed 結果相同、跳號與建議一致性 PASS、不動主資料庫 | 縮短到 18:30，用獨立的 SQLite 檔 |
| 排程 | `tests/Feature/Console/ExpireScheduleTest.php` | 過號檢查每 10 秒、執行後會過號 | 取出排程事件直接執行 |

> 🔍 推斷：沒有瀏覽器端對端（E2E）測試。前端的即時更新改用兩種方式驗證：推播事件的功能測試，加上 `scripts/measure-latency.mjs` 實測延遲，以及開發時用 Playwright 人工操作確認。

## 工具

- **Pest 4**（底層 PHPUnit 12），設定在 `tests/Pest.php`：`tests/Feature` 使用 `RefreshDatabase`，`tests/Unit` 只啟動 Laravel 不清資料庫。
- **測試輔助函式**：`tests/Helpers/floor.php` 提供 `makeFloor()`、`tid()`、`codes()`、`wp()`、`freeTables()`；`tests/Pest.php` 提供 `actingAsHost()`。
- **不依賴前端建置**：`tests/TestCase.php` 在 `setUp()` 呼叫 `withoutVite()`。

## 執行指令

```bash
./vendor/bin/pest                                    # 只跑測試
./vendor/bin/pest tests/Unit/Domain                  # 只跑 Domain 單元測試
./vendor/bin/pest --filter="matches the hand calculation"
composer test                                        # Pint + PHPStan level 7 + 測試
composer ci:check                                    # 再加上前端格式、lint、TypeScript 型別檢查
```

整套約 25 秒，其中模擬器測試約 20 秒。

## 覆蓋範圍

### 有測的核心邏輯

| 主題 | 測試檔 |
|---|---|
| 營業日跨午夜、06:00 切日 | `tests/Unit/Domain/BusinessDayTest.php` |
| 號碼格式、連續、隔天重置、失敗不吃號 | `tests/Feature/Services/TicketServiceTest.php` |
| 同手機不同寫法仍被擋 | `TicketServiceTest`、`tests/Unit/PhoneNumberTest.php` |
| 單桌優先、浪費最少、只併相鄰桌、放寬浪費上限 | `tests/Unit/Domain/OptionFinderTest.php` |
| 帶位建議、保護保留、同輪不超過上限 | `tests/Unit/Domain/SeatingAllocatorTest.php`、`tests/Feature/Services/SuggestionConsistencyTest.php` |
| 跳號規則三種情況 | `tests/Unit/Domain/SkipRuleTest.php` |
| 10 人組被越過 3 次後進入保護 | `tests/Feature/Services/SkipProtectionTest.php` |
| 3 分鐘過號（2:59 不過、3:00 過）、重新排入第 3 位 | `tests/Feature/Services/NoShowTest.php` |
| 同一張桌不帶兩組、同一組不入座兩次 | `tests/Feature/Services/SeatingServiceTest.php`、`tests/Feature/Database/ConstraintsTest.php` |
| 預估與手算一致 | `tests/Unit/Domain/WaitEstimatorTest.php`、`DiningStatsTest.php` |
| 即將輪到通知剛好一則 | `tests/Feature/Services/QueueStateRefresherTest.php` |
| 報表與 CSV | `tests/Feature/Reports/ReportBuilderTest.php`、`ReportCsvTest.php` |
| 獨立稽核與系統紀錄一致 | `tests/Feature/Services/SkipAuditorTest.php` |

### 照實寫出的缺口

- **真正的併發**：PHP 測試是單一程序，無法讓兩個交易交錯執行。條件式更新那一行沒有直接被「讀寫之間被搶走」的情境測到；改為測「另一台平板剛入座的桌子會被拒絕且不留紀錄」，資料庫層由唯一索引測試覆蓋。📄 開發紀錄 Task 10 的 Ruling。
- **前端元件**：沒有 Vue 元件測試。
- **店長修改桌位後的狀態同步**：修改桌位不會觸發 refresh，也沒有測試。列在延後處理清單。

## 代表性測試走讀

### 走讀一：預估與手算一致

```php
// tests/Unit/Domain/WaitEstimatorTest.php（節錄）
it('matches the hand calculation for a fixed history', function () {
    /*
     * 桌況：T1（2 人）17:30 起由 A 組用餐；T2（4 人）17:20 起由 B 組用餐。
     * 等候：W1（A，2 人）、W2（B，4 人）、W3（A，1 人）。現在 18:00。
     * 樂觀（P25）：T1 空出 17:30+40+5 = 18:15 → W1 入座，等 15 分 ...
     */
    $floor = makeFloor([['T1', 2, 'occupied'], ['T2', 4, 'occupied']]);
    $input = new EstimatorInput(now: $this->now, floor: $floor, seatings: [...], ...);

    $estimates = $this->estimator->estimate($input, $this->stats);

    expect([$estimates[1]->low, $estimates[1]->high])->toBe([15, 35])
        ->and([$estimates[2]->low, $estimates[2]->high])->toBe([25, 45])
        ->and([$estimates[3]->low, $estimates[3]->high])->toBe([60, 100]);
});
```

設計重點：

- **手算過程寫在註解裡**，讀者可以拿紙筆驗算，這正是驗收 10「與手算結果一致」要的證據。
- **故意讓 W3 坐不了 T2**：1 個人坐 4 人桌浪費 3 席，超過上限，所以 W3 必須等 T1 第二次空出。一個例子同時驗證浪費上限與前推模擬。
- **統計直接用 `DiningStatsTable` 建構**，不經過資料庫，讓測試只聚焦在預估演算法。

### 走讀二：不變式測試

```php
// tests/Feature/Services/SuggestionConsistencyTest.php（節錄）
function callEverySuggestion(object $test): array
{
    $called = [];
    foreach (app(QueueStateRefresher::class)->currentSuggestions('2026-11-24')->suggestions as $s) {
        $test->seating->call(Party::findOrFail($s->partyId), $s->option->tableIds, $test->system);
        $called[] = $s->partyId;
    }

    return $called;
}
```

這不是測某個輸入對應某個輸出，而是測一條**不變式**：「系統給的每一個建議，依序按下去都必須成功」。只要建議演算法和叫號檢查對規則的理解有任何不一致，這個測試就會丟例外。

它是在最終審查發現「系統建議的併桌按下去被拒絕」之後補上的。教訓是：兩個模組各自都有單元測試，但兩者之間的一致性需要另外一種測試來守。

## 建議補強的測試

以下都是**建議**，目前不存在：

- **兩個程序真正同時寫入**：用 `pcntl_fork` 或兩個 artisan 程序同時對同一張桌子叫號，驗證只有一個成功。
- **瀏覽器 E2E**：用 Pest 的瀏覽器測試或 Playwright，驗證平板按叫號後，另一個分頁的看板在 2 秒內更新。
- **性質測試（property-based）**：隨機產生桌況與等候清單，驗證「建議的桌子互不重疊」「沒有人超過 3 次」「預估下限不大於上限」。
- **店長修改桌位後的同步**：修改座位數或相鄰關係後，保護保留與預估應該立即更新（目前不會，需要先改程式）。
