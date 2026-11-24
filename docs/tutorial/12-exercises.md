# 12 延伸練習與自我檢核

## 理解檢核

1. 凌晨 01:59 取的號，為什麼不會變成 001？系統在哪裡決定它屬於哪一天？
   <details><summary>答案</summary>
   營業日以 06:00 切日：`BusinessDay::for()` 把時間往回推 6 小時再取日期，01:59 變成前一天 19:59。號碼計數器 `ticket_counters` 以營業日為 key，所以凌晨的號碼接續前一晚。見 07 章「營業日」與 `app/Domain/BusinessDay.php`。
   </details>

2. 兩台平板在同一瞬間把不同客人叫到同一張桌子，系統靠哪幾道防線保證只有一個成功？
   <details><summary>答案</summary>
   (1) SQLite `transaction_mode = IMMEDIATE`，寫入交易排隊執行；(2) `SeatingService::call()` 的條件式更新（`WHERE status = 'available' AND 未被別人保留`），影響筆數不符就 rollback；(3) 入座時 `seating_tables` 的 partial unique index。見 05 章與 07 章第 3 站。
   </details>

3. 為什麼「即將輪到」通知不管 refresh 被呼叫幾次都只會有一則？
   <details><summary>答案</summary>
   `notification_logs` 有唯一索引 `(party_id, type)`，寫入用 `insertOrIgnore`。重複寫入會被資料庫靜靜略過，不需要在程式裡先查有沒有發過。見 `app/Services/QueueStateRefresher.php`。
   </details>

4. 10 人組被 3 組 2 人客人越過後進入保護。之後又有 2 人客人坐進 2 人桌，為什麼不算第 4 次？
   <details><summary>答案</summary>
   保護中的組只有在後面的人拿走「他用得到的桌子」時才計數；2 人桌對 10 人組沒用，這次入座不影響他。他用得到的桌子都已保留給他，所以計數永遠停在 3。見 08 章「跳號計數」與 `SkipRule::counts()`。
   </details>

5. 等候預估器裡為什麼沒有任何帶位規則的程式碼？這樣設計的好處與代價是什麼？
   <details><summary>答案</summary>
   它在虛擬時間上重複呼叫真正的 `SeatingAllocator`，自己只負責推進時間。好處：規則改了預估自動跟著變，不會出現兩套規則。代價：計算量較大，而且公平規則越多（保護越多），預估越難準（MAE 6.2 → 8.7 分）。見 08 章第 4 節。
   </details>

6. 為什麼模擬器的跳號檢查不直接看 `parties.skip_count`？
   <details><summary>答案</summary>
   如果寫入 `skip_count` 的邏輯有 bug，直接看它就會跟著錯。`SkipAuditor` 只讀叫號紀錄（分配了哪些桌、前面有誰、當下哪些桌空著），用同一條規則依時間順序獨立重算，再和 `skip_count` 比對。見 07 章「獨立稽核」。
   </details>

7. 公開頻道 `queue` 和私有頻道 `staff` 送的內容為什麼不一樣？
   <details><summary>答案</summary>
   公開頻道誰都能訂閱，只能放本來就公開在看板上的資訊（號碼、狀態、前面組數、預估）。平板需要姓名和手機末三碼，所以私有頻道只送「有變動」的訊號，平板再用已登入的 HTTP 取資料。見 06 章「WebSocket 頻道」。
   </details>

8. `QueueBroadcaster` 為什麼要在容器中註冊成 `scoped`？
   <details><summary>答案</summary>
   它用 `$pending` 記住「這個營業日在這個交易內已經排過推播」，同一交易內的多次 refresh 只推一次。改成 `bind` 的話每次解析都是新實例，`$pending` 永遠是空的，合併就失效。見 07 章「啟動時做了什麼」。
   </details>

## 動手練習

### 練習一：新增一個「帶小孩」欄位

取號時多一個「是否帶嬰兒車」的勾選，有的話只能坐室內桌。

<details><summary>提示</summary>

- 資料：`parties` 加欄位（新的 migration），`Party` model 的 `#[Fillable]` 與 `@property`。
- 規則：`WaitingParty` 要帶上這個資訊；`OptionFinder` 判斷適合的桌子時排除戶外與吧台。想想 `isFree` callable 和 `allOptions()` 哪一個比較適合放這條規則。
- 輸入：`app/Http/Requests/IssueTicketRequest.php`、`TicketService::issue()`、取號頁 `resources/js/pages/customer/Take.vue` 與代客取號對話框。
- 測試：先在 `tests/Unit/Domain/OptionFinderTest.php` 寫失敗的測試。

</details>

### 練習二：快取歷史用餐統計

`FloorRepository::diningStats()` 每次狀態變更都重讀 14 天的資料，但整個營業日內結果不會變。

<details><summary>提示</summary>

- 用 `Cache::remember("dining-stats:{$businessDate}", ...)`，或在服務內以營業日為 key 記在屬性裡。
- 想想：店長修改桌位不影響歷史統計，但歷史 seeder 重跑時要不要清快取？
- 用模擬器量測修改前後的執行時間。

</details>

### 練習三：讓大組客人更容易坐到位子

模擬中 9 人以上的取消率約 92%。

<details><summary>提示</summary>

- 先用 `php artisan queuetable:simulate` 的依人數分組結果確認基準。
- 方向一：在店長後台多設幾組相鄰關係（例如 T04–T05 已相鄰，可坐 16 人，但浪費太多），或調整 `relaxed_max_waste` 讓大組適用不同上限。
- 方向二：保護組保留「一張空出的桌子」後，是否該把相鄰另一張用餐中的桌子標成「下一個保留」，讓平板提醒帶位人員？
- 每改一次都跑 5 個 seed，看 5–6、7–8 位有沒有被犧牲。

</details>

### 練習四：店長修改桌位後立即同步

目前修改桌位或相鄰關係，不會重算保護保留與預估。

<details><summary>提示</summary>

- 在 `app/Http/Controllers/Admin/TableController.php` 的 `update()`、`adjacency()` 裡包交易並呼叫 `QueueStateRefresher::refresh()`。
- 更好的做法：把桌位修改搬進一個 Service，和其他狀態變更走同一條路。
- 想想：用餐中的桌子能不能改座位數？

</details>

## 延伸學習

| 主題 | 本專案的位置 | 搜尋關鍵字 |
|---|---|---|
| SQLite 的鎖與交易模式 | `config/database.php` | `SQLite BEGIN IMMEDIATE vs DEFERRED`、`SQLite WAL mode` |
| Partial index | 第二個 migration | `partial unique index`、`filtered index` |
| 樂觀併發與條件式更新 | `SeatingService::call()` | `optimistic concurrency control`、`compare-and-swap update` |
| 離散事件模擬 | `SimulationRunner`、`WaitEstimator` | `discrete event simulation`、`Poisson arrival process` |
| 百分位數的算法 | `DiningStatsBuilder` | `nearest-rank percentile` |
| 用分數排序鍵插隊 | `SeatingService` 的重新排入 | `fractional indexing` |
| Laravel 推播 | `app/Events/`、`QueueBroadcaster` | `Laravel Reverb`、`ShouldBroadcastNow`、`DB::afterCommit` |
| 不變式測試與性質測試 | `SuggestionConsistencyTest` | `invariant testing`、`property-based testing PHP` |
| 公平排隊 | 跳號規則 | `queue fairness`、`restaurant table assignment problem` |
