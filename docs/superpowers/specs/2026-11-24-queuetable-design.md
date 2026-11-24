# QueueTable 餐廳候位叫號與帶位系統 — 設計文件

- 日期：2026-11-24
- 狀態：已與需求方確認（系統分析、架構、跳號定義採 B 資源版）
- 原始題目：`docs/requirements.md`（功能需求 1–8、驗收標準 1–14 的原文）；本文件只記錄「怎麼做」與「題目沒講清楚、由我們決定的部分」

---

## 1. 目的與背景

約 80 席熱炒店，尖峰 30 組以上在門外等。紙本叫號導致跳號、插隊爭執。系統要提供一條**公平、可稽核**的候位佇列，並用模擬器以數據證明預估準確、跳號上限不被破壞。

本專案的交付目的是**作品集與面試**。優先順序：
1. 14 條驗收標準每一條都有可指認的證據（測試、README、截圖、模擬器輸出）。
2. 程式架構清楚、可講解（面試時能說明分層、併發保證、演算法）。
3. 現場操作體驗「順、不卡」即可，不追求生產級營運細節。

## 2. 目標 / 非目標 / 反目標

**目標**：題目功能需求 1–8 全部實作；驗收標準 1–14 全部達成；測試 ≥ 45 個全數通過；GitHub Actions CI。

**非目標**：事先訂位、簡訊/LINE 通知、多店、三張以上併桌、入座後換桌、多語系、生產部署（HTTPS、反向代理）。

**反目標**（即使功能能跑也算失敗）：
- 同營業日同佇列號碼重複或跳號。
- 任何客人被跳過超過 3 次（模擬器中也不行）。
- 公開 WebSocket 頻道送出姓名或手機號碼。
- 任何測試等待真實時間（`sleep`）。
- 同一張桌子同時有兩組客人，或同一組客人有兩筆入座紀錄。

## 3. 技術限制與選型

| 項目 | 決定 |
|---|---|
| 後端 | PHP ≥ 8.3（composer 限制 `^8.3`），**Laravel 13**（符合「12 以上」） |
| 前端 | Laravel Vue Starter Kit：Inertia + Vue 3 + TypeScript + Tailwind，Vite 建置；**關閉註冊** |
| DB | SQLite，WAL、`busy_timeout`、交易模式 IMMEDIATE（若 Laravel 設定檔支援 `transaction_mode`；不支援則於連線建立時處理，builder 確認） |
| 即時 | Laravel Reverb + `laravel-echo` + `pusher-js` |
| 測試 | Pest |
| 其他前端套件 | `qrcode`（看板上產生取號 QR Code） |
| 啟動 | `composer run dev` 以 concurrently 同時啟動 `serve`、`reverb:start`、`schedule:work`、`vite` |
| 時區 | `app.timezone = Asia/Taipei`，DB 存台灣時間（單店、台灣無日光節約） |

## 4. 方案比較（Approaches）

### 4.1 「被跳過」的定義（需求方選定：B）
- **A 嚴格版**：只要後面的人先入座就算跳過；滿 3 次後任何人都不能在他之前入座。最公平但會讓小桌閒置、翻桌率差。
- **B 資源版（採用）**：只有後面的人拿走「P 用得到的桌子」才算跳過 P；滿 3 次後，P 用得到的空桌一律保留給 P。P 用不到的桌子照常給別人。缺點：門口客人仍可能覺得「晚來的先進去」，寫入 README 已知限制。

### 4.2 時鐘
- 自訂 Clock 介面注入：明確，但 Eloquent timestamps 仍用 `now()`，兩個時鐘易不同步。
- **採用：全系統只用 `now()`／`Carbon`**；測試用 `$this->travelTo()`／`Carbon::setTestNow()`；模擬器以 `Carbon::setTestNow()` 推進虛擬時間。題目明列此做法可接受。

### 4.3 等候預估
- 公式法（前面組數 ÷ 適合桌數 × 平均用餐時間）：好手算但跨佇列共用桌子時嚴重失準。
- **採用：前推模擬**——用帶位演算法本身把目前狀態「快轉」，樂觀/悲觀兩次得到區間。

### 4.4 前端
- 獨立 SPA：需自己處理 API 認證。
- **採用：Inertia + Vue Starter Kit**，認證現成。

### 4.5 過號偵測
- 只靠排程：排程沒跑就不會過號。
- **採用：排程每 10 秒檢查 + 每次讀取/寫入候位狀態前先執行一次過號檢查**（lazy expiry）。

## 5. 名詞與規則定義

### 5.1 營業日
`business_date = (台灣時間 now − 6 小時) 的日期`。例：11/25 01:30 取號 → 營業日 11/24。實作為單一純函式 `BusinessDay::for(CarbonInterface $t): string`。

### 5.2 佇列與號碼
- 佇列：A（1–2 人）、B（3–4）、C（5–6）、D（7 人以上）。人數上限 `config('queuetable.max_party_size')` = 12，超過拒絕並顯示「請洽現場店員」。
- 號碼：佇列字母 + 三位數，例 `B012`；每營業日每佇列從 001 起。
- 發號：在交易內對 `ticket_counters(business_date, queue)` 遞增並建立 party；任何失敗整筆 rollback → 不跳號。`parties` 有唯一索引 `(business_date, ticket_code)`。
- 「不跳號」的意思是**發出的號碼連續**；取消、過號不釋放號碼。

### 5.3 同手機限制
同營業日、同手機已有 `waiting` 或 `called` 狀態的 party → 拒絕，回傳原因（含既有號碼）。
雙重保障：應用層先查 + partial unique index `(business_date, phone) WHERE status IN ('waiting','called')`。手機號碼正規化為 `09xxxxxxxx`，格式錯誤拒絕。

### 5.4 全域順序與佇列位置
- 每個 party 有 `position_key`（REAL）。取號時 = 取號時間的 Unix timestamp。
- **全域順序**（決定誰先被帶位）= 所有 `waiting` party 依 `(position_key, id)` 升冪，跨佇列比較。`id` 為同秒取號時的決勝鍵（先寫入者先）。
- **前面還有幾組**（顧客頁顯示、即將輪到通知）= 同佇列、`waiting`、全域順序在前的組數。
- **重新排入**（目標：成為同佇列第 3 位，前面剛好 2 組）：令 k1、k2、k3 為同佇列 waiting 依序的 position_key。
  - waiting ≥ 3 組 → `(k2 + k3) / 2`
  - waiting 剛好 2 組 → `k2 + 1`（排在第 2 位之後，即第 3 位）
  - waiting < 2 組 → `now` 的 timestamp（排到最後；同佇列已不足 2 組，無法成為第 3 位）
  - 全域順序上，重新排入的組就落在上述 key 的位置；這是「插回第 3 位」在跨佇列順序上的自然結果，接受。
  - 若該手機在同營業日已另有 waiting/called 的號碼 → 拒絕重新排入並顯示原因（維持 5.3 的唯一性）。

### 5.5 Party 狀態機
```
waiting ──call──▶ called ──checkIn──▶ seated
waiting ──cancel──▶ cancelled
called  ──cancel──▶ cancelled（釋放保留的桌子）
called  ──expire(叫號滿 3 分鐘)──▶ no_show（釋放保留的桌子）
no_show ──requeue（requeue_count = 0 才可）──▶ waiting（requeue_count = 1）
```
- `called_at` 起算 3 分鐘（`config('queuetable.no_show_minutes')` = 3）。判斷條件：`now >= called_at + 3 分鐘`。
- 重新排入由帶位人員在平板上操作（客人回到門口時）。第二次 no_show 後「重新排入」按鈕不可用，API 也拒絕。
- `skip_count` 在重新排入後保留。

### 5.6 Table 狀態機
```
available ──seat──▶ occupied ──leave──▶ cleaning ──cleaned──▶ available
available / cleaning ──disable──▶ disabled ──enable──▶ available
```
- `occupied` 不可直接停用。
- 保留以兩個附加欄位表示：`held_for_party_id` 與 `hold_reason`（`called` 叫號保留 | `protected` 保護保留）。保留中的桌子狀態仍是 `available`，不能被其他組使用、不能停用。
- `protected` 保留由服務層在每次狀態變更的同一個交易內，依 `SeatingAllocator` 的輸出同步（新增／移除），所以資料庫中的保留永遠反映最新計算結果。
- 題目規定的四種狀態不變；「保留」是附加欄位，不是第五種狀態。

### 5.7 適合的桌子（option）
一個「選項」是一張桌子或兩張相鄰桌子的組合：
- **單桌適合**：`size ≤ capacity ≤ size + max_waste`（`max_waste` = 2，可設定）。
- **併桌適合**：兩桌在 `table_adjacencies` 中相鄰，`size ≤ cap1 + cap2 ≤ size + max_waste`。
- **只有在沒有任何「目前空著的單桌選項」時**，才建議併桌（驗收 #4）。
- 停用桌不構成任何選項。
- 選項排序（**所有步驟一律適用**）：單桌一律優先於併桌 → 浪費座位少者優先 → 桌號字典序小者優先（併桌以 `較小桌號+較大桌號` 字串比較），確保決定論。

### 5.8 帶位建議演算法 `SeatingAllocator`（純 PHP，無 I/O）
輸入：桌況快照（每桌狀態、容量、相鄰關係、是否已被叫號保留）、全域順序的 waiting 清單（含 size、skip_count）。
輸出：建議清單（party → option）與「保護保留」清單（table → party）。

```
free = 狀態 available 且未被「叫號保留」的桌子（protected 保留每次重新計算，不算在排除內）
1. 保護中的組（skip_count ≥ 3），依全域順序逐一：
   a. 若 free 中有完全空著的適合選項 → 建議該組 + 最佳選項，選項的桌子從 free 移除
   b. 否則：free 中「所有」對該組是相關桌（見 5.9）的桌子，全部標為「保護保留」給該組，
      從 free 移除。（先到的保護組先拿；後面的保護組只能拿剩下的。）
2. 其餘組依全域順序逐一：
   找 free 中最佳單桌選項；沒有才找最佳併桌選項；找到 → 建議，從 free 移除
3. 回傳所有建議（可能同時有多組，因為可能同時空出多張桌子）
```
帶位人員在平板上看到建議卡「叫 B012 → I05（浪費 0 席）」並按「叫號」。也可以為任一 waiting 組手動選擇其他合法選項；手動選擇走同一套驗證（見 5.9）。

### 5.9 跳號計數（B 資源版）
- **相關桌**：桌子 t 對 P 是「相關」的，若 t 本身是 P 的適合單桌，或 t 屬於某個 P 的適合併桌組合。
- **計數時機**：party X 被**叫號**時（桌子在此刻被分配）。
- **規則**：對每個 waiting 且全域順序在 X 之前的 P（含保護中的組）：若 X 分配到的桌子中有任何一張對 P 是相關桌，**且扣掉這些桌子後 P 當下已沒有全空的適合選項**（空 = 可用且未保留給別人）→ `P.skip_count += 1`。X 後來過號也不回溯。
  - 2026-11-24 修訂（需求方同意）：加上「P 當下已沒有其他選項」的條件。帶位人員把兩張建議卡反過來按時，前面那組自己也拿得到桌子，不應被算跳過。叫號紀錄 meta 另存 `free_tables`，讓 `SkipAuditor` 能用同一條規則獨立重算。
- **保護**：`skip_count` 達到 3 → 進入保護。保護組的「所有」相關空桌都被保留（5.8 步驟 1b），所以後面的人在結構上拿不到任何對 P 相關的桌子。
- **計數不設上限**：計數規則對保護組照樣執行（不會因為到 3 就停止累加）。正常情況下保護機制讓它不可能變成 4；若真的變成 4 就是 bug，會被下面兩道關卡抓到，而不是被「停止計數」掩蓋。
- **寫入時強制**：`SeatingService::call()` 在交易中重新計算；若這次叫號會讓任何人的 skip_count 變成 4，或使用了保護保留給別人的桌子 → 拒絕（手動覆寫也一樣）。
- **獨立稽核**：`SkipAuditor` 只讀 `activity_logs` 中的叫號紀錄（含被分配的桌號、當時 waiting 清單快照），以同一個「相關桌」定義獨立重算每組被跳過次數，不依賴 `parties.skip_count`。模擬器結束時用它輸出「最大被跳過次數」與 PASS/FAIL，作為驗收 #11 的證據。
- `max_skips` = 3，可設定。

### 5.10 入座與併發保證（驗收 #9）
- `seatings`：`id, party_id (unique), business_date, seated_at, left_at, user_id, device_id`。`party_id` 唯一 → 同一組只會有一筆入座。
- `seating_tables`：`seating_id, table_id, released_at`；partial unique index `table_id WHERE released_at IS NULL` → 同一張桌子同時只會屬於一筆進行中的入座。
- 服務層：交易內以條件式更新 `UPDATE tables SET status='occupied' WHERE id IN (...) AND status='available' AND (held_for_party_id IS NULL OR held_for_party_id = :party)`，影響筆數不符 → 丟出例外、rollback。
- 叫號時的保留也用條件式更新（`held_for_party_id IS NULL`），避免兩台平板同時叫號叫到同一張桌子。
- 測試不需要真正的平行執行：分別測「條件式更新失敗會 rollback」「唯一索引直接拒絕重複寫入」「重複入座被服務層拒絕」。

### 5.11 等候時間預估 `WaitEstimator`（純 PHP）
**歷史統計 `DiningStats`**（從 DB 讀取後傳入純函式）：
- 樣本：營業日在今天之前 14 天內、已離開的入座紀錄；用餐時間 = `left_at − seated_at`（分鐘）。
- 分組：佇列 × 時段。時段以入座時間的「小時」分 4 段：17–18 點 → S1、19–20 點 → S2、21–22 點 → S3、其餘所有小時（23 點到隔天 16 點，含模擬器超過 02:00 的部分）→ S4。
- 每組取 P25、P50、P75（用 nearest-rank 法，方便手算）。
- 樣本 < 5 時退回「同佇列全部時段」；仍 < 5 時用設定檔預設值（A 50、B 65、C 80、D 95 分鐘，P25/P75 = ±15 分鐘）。

**前推模擬**（每次狀態變更後對所有 waiting 組重算）：
```
對 情境 ∈ {樂觀: P25, 悲觀: P75}：
  每張 occupied 桌的預計空出時間 = max(now, seated_at + 該組用餐時間[情境]) + cleaning_minutes
  cleaning 桌的預計空出時間 = 轉為 cleaning 的時間 + cleaning_minutes（且 ≥ now）
  已叫號的組：視為立即入座其保留桌，空出時間 = now + 用餐時間[情境] + cleaning_minutes
  虛擬時間 t = now；重複：
     以 SeatingAllocator 對 t 時的空桌與剩餘 waiting 組（含 skip_count）求建議
     每個建議：該組預計入座時間 = t；其桌子空出時間 = t + 用餐時間[情境] + cleaning_minutes
     （模擬中被跳過的組 skip_count 也要遞增，保護規則一致）
     t = 下一個空出時間；直到所有 waiting 組都有預計入座時間
每組在兩個情境各得到一個「剩餘等候」= 預計入座時間 − now（分鐘）
a = min(兩情境), b = max(兩情境)   ← 貪婪分配可能讓悲觀情境反而較短，所以取 min/max
預估區間 = [floor5(a), ceil5(b)]；若兩者相等則上限 + 5
```
- 顯示的永遠是「從現在起的剩餘等候」。取號當下 now = issued_at，所以存下來的 `est_*_at_issue` 就是從取號起算的預估，可直接與實際等候比較。
- `cleaning_minutes` = 5（可設定）。
- 永遠無法入座（所有選項都不存在）的組顯示「請洽現場店員」。
- 時段選擇：以「預計入座時間」所在時段取用餐統計。
- **誤差量測**：取號時算出的區間存入 `parties.est_low_at_issue`、`est_high_at_issue`。入座後實際等候 = `seated_at − issued_at`。單組誤差 = |實際 − (low + high)/2|。MAE = 已入座組誤差的平均。另外報告區間命中率（實際落在區間內的比例）。
- 驗收 #10 測試：2–3 張桌、3 組客人、固定歷史資料，手算期望值寫在測試註解裡。

### 5.12 即將輪到通知（驗收 #8）
- 每次重算後，對每個 waiting 組：若同佇列「前面還有幾組」≤ 2，且尚未通知 → 寫入 `notification_logs(party_id, type='coming_soon', created_at, message)`。
- 唯一索引 `(party_id, type)`，寫入用 insert-or-ignore → 剛好一則，重新排入後也不會重複。
- 取號當下就 ≤ 2 組 → 立刻產生通知。

### 5.13 即時推播（驗收 #7）
- 每個改變候位或桌況的服務方法，在交易提交後（`afterCommit`）以 `ShouldBroadcastNow` 送出事件。不需要 queue worker。
- **公開頻道 `queue`**，事件 `QueueUpdated`：`{ queues: {A: {now_calling, waiting_count}, ...}, parties: [{ticket_code, queue, status, ahead, est_low, est_high}] }`。不含姓名與手機。看板與所有顧客頁訂閱同一頻道；顧客頁用自己的 ticket_code 找到自己那筆。
- **私有頻道 `staff`**，事件 `FloorUpdated`：只送通知訊號，平板收到後用 Inertia partial reload 重新取得資料（資料含姓名，走已登入的 HTTP）。
- 推播失敗（Reverb 未啟動）：記 log（`Log::warning`），不讓業務操作失敗。不能無聲吞掉。
- 驗證：功能測試確認事件在正確動作後被送出、payload 正確、不含個資；另附一支 Node 腳本，連上 Reverb、觸發動作、量測收到事件的延遲，輸出寫進 README。

### 5.14 操作紀錄（驗收 #13）
- `activity_logs`：`id, occurred_at, actor_type (staff|customer|system), user_id, device_id, action, subject_type, subject_id, from_status, to_status, meta (JSON)`。
- 所有狀態變更只能透過 Application Service 進行，並在同一交易內寫入紀錄。
- 操作者情境 `ActorContext`：middleware 依請求建立（已登入員工 → staff + user + device；顧客頁 → customer；排程與模擬器 → system）。
- 平板識別：`devices` 表（seed「平板 A」「平板 B」）。登入頁除了帳密，還要選平板，存入加密長效 cookie。員工路由的 middleware 要求必須有 device。

### 5.15 營業日報表（驗收 #12）
`ReportBuilder::for(string $businessDate)` 回傳：
| 指標 | 定義 |
|---|---|
| 取號數 | 該營業日 parties 數 |
| 入座數 | 有 seating 的 parties 數 |
| 過號率 | `no_show_count ≥ 1` 的 parties ÷ 取號數 |
| 取消率 | 最終狀態為 cancelled 的 parties ÷ 取號數 |
| 各佇列平均／最長等候 | 已入座組的 `seated_at − issued_at`（分鐘，小數 1 位） |
| 預估誤差 MAE | 見 5.11 |
| 各桌周轉次數 | 該營業日開始的 seatings 數（併桌時兩張桌各 +1） |
| 被跳過次數分布 | skip_count = 0/1/2/3 的組數 |

- CSV：UTF-8 **含 BOM**，CRLF 換行，以「區段」排列（指標區、佇列區、桌位區、跳號分布區，區段間空一行）。
- 測試：建立固定 fixture，手算各數字。

### 5.16 尖峰模擬器（驗收 #11）
- 指令：`php artisan queuetable:simulate {--seed=42} {--date=}`。
- 使用獨立 DB 檔 `database/simulation.sqlite`：每次執行 fresh migrate，並用同一個歷史 seeder 產生前 30 天歷史。不影響開發資料。
- 推播關閉（`broadcasting.default = null`）。
- **加速時鐘**：虛擬時間每步 30 秒，以 `Carbon::setTestNow()` 推進，從 17:00 跑到所有客人處理完（最晚 03:00 強制結束並報告剩餘組數）。
- 每步依序執行：新客到達與取號 → 已叫號客人報到（或過號）→ 用餐結束離開 → 清桌完成 → 過號檢查 → 自動依建議叫號（模擬帶位人員）。全部呼叫**真正的 Application Service**。
- 預設參數（`config/simulation.php`，可調）：
  - 到達率（組/小時）：17 時 10、18 時 25、19 時 30、20 時 20、21 時 15、22 時 12、23 時 10、0 時 6、1 時 3；01:30 後不再取號。Poisson 到達。
  - 人數分布：1 人 5%、2 人 30%、3 人 15%、4 人 25%、5 人 8%、6 人 8%、7 人 3%、8 人 3%、9–12 人 3%。
  - 用餐時間：依歷史資料的佇列 × 時段分布抽樣（與 seeder 同一組 lognormal 參數）。
  - 叫號後報到延遲：0.5–2.5 分鐘均勻分布；8% 不報到（過號），其中一半回來重新排入。
  - 取消：每組有耐心值（30–90 分鐘均勻），預估等候超過耐心時有機率取消；另外 3% 隨機中途取消。
  - 清桌：3–7 分鐘均勻。
  - 取號管道：60% kiosk、40% remote。
- 輸出：取號／入座／過號／取消數、MAE、區間命中率、平均與最長等候（含各佇列）、最大被跳過次數（`SkipAuditor` 從操作紀錄獨立重算，並與 `parties.skip_count` 交叉比對）與「是否 ≤ 3：PASS/FAIL」、桌位使用率（用餐中桌分鐘 ÷ 開放桌分鐘，17:00–02:00）。
- 同一個 seed 結果必須完全相同（有測試）。

### 5.17 Seeder
- 20 張桌、80 席（建議配置，builder 可微調，但要確保 1–12 人每種人數都至少有一個合法選項）：
  - 室內：I01–I04 2 人桌、I05–I10 4 人桌、I11–I12 6 人桌（44 席）
  - 戶外：O01–O03 4 人桌、O04–O05 8 人桌（28 席）
  - 吧台：B01–B02 2 人、B03 4 人（8 席）
  - 相鄰：I01–I02、I03–I04、I05–I06、I07–I08、I09–I10、I10–I11、I11–I12、O01–O02、O02–O03、O04–O05、B01–B02
  - 以 `max_waste = 2` 驗證：1–8 人有單桌，9–10 人 I10+I11，11–12 人 I11+I12。
- 近 30 天歷史：每天約 100–150 組，含 parties（seated/cancelled/no_show）與 seatings（含 left_at）。以輕量產生器產生（不跑完整模擬），但要保證同一張桌子時間不重疊、人數不超過容量。固定亂數 seed。
- 帳號：店長 `manager@queuetable.test`、帶位 `host1@…`、`host2@…`，密碼 `password`（README 註明僅供展示）。
- 平板：「平板 A」「平板 B」。

## 6. 架構

```
 平板(/host)      看板(/board)      手機(/take, /q/{token})
     │ Inertia HTTP     │ WebSocket         │
     ▼                  ▼                   ▼
 ┌────────────────── Laravel 13 ──────────────────────┐    ┌─────────┐
 │ HTTP Controllers（薄；驗證 → 呼叫服務 → 回應）        │    │ Reverb  │
 │        │                                           │──▶ │ :8080   │
 │        ▼                                           │    └─────────┘
 │ Application Services（交易 + 操作紀錄 + 事件）        │
 │   TicketService   SeatingService   TableService     │
 │   NoShowExpirer   EstimateRefresher ReportBuilder   │
 │        │                                           │
 │        ▼                                           │
 │ Domain（純 PHP，無 I/O；測試、預估、模擬器共用）       │
 │   SeatingAllocator  WaitEstimator  DiningStats       │
 │   BusinessDay  QueueClassifier  TableOption          │
 │        │                                           │
 │ Eloquent Models ──▶ SQLite（WAL）                    │
 └────────────────────────────────────────────────────┘
   schedule:work：每 10 秒 NoShowExpirer
   artisan queuetable:simulate：獨立 sqlite 檔、虛擬時鐘
```

目錄建議（builder 可調整）：
```
app/Domain/        純邏輯（值物件、Allocator、Estimator）
app/Services/      Application Services
app/Http/Controllers/{Host,Board,Customer,Admin}/
app/Events/        QueueUpdated, FloorUpdated
app/Console/Commands/SimulateCommand.php
app/Simulation/    模擬器的到達產生器、模擬帶位人員
resources/js/pages/{host,board,customer,admin}/
```

## 7. 資料表

| 表 | 主要欄位 | 約束 |
|---|---|---|
| users | name, email, password, role(manager/host) | |
| devices | name | |
| dining_tables | code, capacity, area(indoor/outdoor/bar), status, held_for_party_id, hold_reason(called/protected), status_changed_at | code unique |
| table_adjacencies | table_a_id, table_b_id（a < b） | unique pair |
| ticket_counters | business_date, queue, last_number | unique(business_date, queue) |
| parties | business_date, queue, ticket_code, number, size, name, phone, channel(kiosk/remote), token, status, position_key, skip_count, no_show_count, requeue_count, issued_at, called_at, seated_at, cancelled_at, est_low_at_issue, est_high_at_issue, est_low, est_high | unique(business_date, ticket_code)、unique(token)、partial unique(business_date, phone) where active |
| seatings | party_id, business_date, seated_at, left_at, user_id, device_id | unique(party_id) |
| seating_tables | seating_id, table_id, released_at | partial unique(table_id) where released_at is null |
| notification_logs | party_id, type, message, created_at | unique(party_id, type) |
| activity_logs | 見 5.14 | index(occurred_at)、index(subject) |

（桌子的 model 叫 `DiningTable`，避免與 SQL 保留字、Laravel 慣用語混淆。）

## 8. 畫面

| 路由 | 對象 | 內容 |
|---|---|---|
| `/login` | 員工 | 帳密 + 選擇平板 |
| `/host` | 帶位人員（橫向平板） | 左：四個佇列的候位清單（號碼、人數、稱呼、等候時間、skip 次數、狀態）＋ 帶位建議卡；右：桌位平面格（依區域分組，顏色代表狀態，顯示入座時間／已用餐分鐘／保留給誰）。動作：叫號、報到入座、取消、重新排入、離開、清桌完成、停用/啟用、代客取號 |
| `/board` | 門口看板（16:9） | 四個佇列各自「目前叫號」與「等候組數」大字顯示；取號 QR Code |
| `/take` | 顧客 | 人數、稱呼、手機 → 取號 → 導向 `/q/{token}` |
| `/q/{token}` | 顧客 | 號碼、前面幾組、預估區間、狀態（叫號時醒目提示）、取消按鈕 |
| `/admin/tables` | 店長 | 桌位 CRUD、相鄰設定 |
| `/admin/users` | 店長 | 建立帶位人員帳號 |
| `/admin/reports/{date?}` | 店長 | 營業日報表 + 下載 CSV |

視覺方向：熱炒店現場用，高對比、大字、觸控按鈕 ≥ 48px；看板可遠距閱讀。

## 9. 測試策略（目標 ≥ 60 個，驗收要求 ≥ 45）

| 驗收 | 測試重點 |
|---|---|
| #1 | seeder 產生 20 桌、30 天歷史；三個主要頁面 HTTP 200 |
| #2 | 號碼格式、連續、不重複；跨午夜營業日歸屬；隔天重置；失敗 rollback 不吃號 |
| #3 | 同手機重複取號被拒並回傳原因；取消後可再取；隔營業日可再取；DB 唯一索引 |
| #4 | 選浪費最少；單桌優先；只有併桌坐得下才建議併桌；只併相鄰桌；停用桌排除 |
| #5 | 跳號計數規則（相關桌才算）；第 3 次後「所有」相關空桌被保護保留；不會出現第 4 次；手動覆寫被拒；SkipAuditor 與 skip_count 一致 |
| #6 | 叫號滿 3 分鐘過號（travelTo）、2:59 不過號；重新排入在第 3 位；不足 2 組排最後；第二次過號不能重新排入 |
| #7 | 入座／離開／取消後送出事件；payload 正確且無個資 |
| #8 | 前面剩 2 組時剛好一則；重算多次不重複；重新排入不重複 |
| #9 | 同桌不能帶兩組；同組不能兩筆入座；條件式更新失敗 rollback |
| #10 | 固定歷史資料下預估結果等於手算值；百分位數；時段退回規則 |
| #11 | 模擬器小規模跑完、同 seed 結果相同、skip ≤ 3 |
| #12 | 報表各數字等於手算值；CSV 有 BOM、欄位正確 |
| #13 | 每種狀態變更都有操作紀錄，含 user、device、時間 |
| 其他 | 營業日函式邊界、權限（host 不能進 admin）、表單驗證 |

## 10. 交付與 README

README 章節：安裝與啟動（含 Reverb）、營業日與號碼重置規則、帶位建議與跳號上限演算法、等候預估方法與模擬器量測結果、系統架構圖（Mermaid）、畫面截圖（帶位平板、門口看板、顧客頁、報表，放在 `docs/screenshots/`）、已知限制。
CI：GitHub Actions，`composer install` → `npm ci` → `npm run build` → `php artisan test`。
推上 GitHub 前會先徵求使用者同意（對外發布）。

## 11. 已知限制（預計寫入 README）
- 跳號採資源版定義：P 用不到的桌子給晚來的人，不算跳過 P。
- 預估以歷史分位數與確定性前推計算，不處理突發大量取消。
- 推播在 Reverb 未啟動時只記 log，畫面需重新整理才會更新。
- SQLite 單機；兩台平板的併發依賴資料庫鎖，未做高併發壓測。
- 不處理三張以上併桌、入座後換桌。

## 12. 留給 builder 的決定
- 各套件確切版本、目錄微調、Vue 元件拆分。
- 頁面視覺細節（遵守第 8 節的方向）。
- 歷史 seeder 產生器的實作細節（遵守 5.17 的約束）。
- Node 延遲量測腳本的實作方式。
