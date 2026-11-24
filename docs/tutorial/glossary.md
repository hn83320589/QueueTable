# 名詞對照表

## 業務術語

| 名詞 | 說明 | 出現位置 |
|---|---|---|
| 營業日 | 以台灣時間 06:00 切日的「一晚」；凌晨的號碼屬於前一個營業日 | `app/Domain/BusinessDay.php` |
| 佇列 | 依人數分的 A（1–2）、B（3–4）、C（5–6）、D（7+） | `app/Domain/QueueLetter.php` |
| 號碼 | 佇列字母加三位數，例如 `B012` | `parties.ticket_code` |
| 取號管道 | `kiosk` 門口平板代客取號；`remote` 手機遠端取號 | `app/Domain/Enums/Channel.php` |
| 叫號 | 帶位人員請某組到門口報到，桌子保留 3 分鐘 | `SeatingService::call()` |
| 報到入座 | 叫號後客人到門口，帶位人員按入座 | `SeatingService::checkIn()` |
| 過號 | 叫號滿 3 分鐘未報到 | `SeatingService::expireNoShows()` |
| 重新排入 | 過號的客人回來，排回同佇列第 3 位，每組一次 | `SeatingService::requeue()` |
| 併桌 | 兩張相鄰的桌子合起來坐一組 | `OptionFinder::allOptions()` |
| 相鄰 | 店長設定的「可以併桌」的兩張桌子 | `table_adjacencies` |
| 浪費座位 | 桌子座位數減人數 | `TableOption::$waste` |
| 被跳過 | 前面的組沒有適合的空桌，後面的組先入座 | `app/Domain/Seating/SkipRule.php` |
| 保護 | 被跳過滿 3 次的組；對他有用的空桌都保留給他 | `SeatingAllocator` |
| 叫號保留 | 叫號後 3 分鐘內，桌子保留給該組（`hold_reason = called`） | `app/Domain/Enums/HoldReason.php` |
| 保護保留 | 保護中的組的相關空桌（`hold_reason = protected`） | 同上 |
| 即將輪到 | 同佇列前面剩 2 組以內時寫入的通知 | `notification_logs` |
| 周轉次數 | 一張桌子在一個營業日內被入座的次數 | `ReportBuilder` |
| 清桌中 | 客人離開後、可以再帶位前的狀態 | `TableStatus::Cleaning` |

## 專案內的自訂名稱

| 名稱 | 說明 | 出現位置 |
|---|---|---|
| `FloorSnapshot` | 某一時刻整個樓面的桌況與相鄰關係（純資料） | `app/Domain/Seating/FloorSnapshot.php` |
| `TableOption` | 一個可帶位的選項：一張桌或兩張相鄰桌 | `app/Domain/Seating/TableOption.php` |
| `WaitingParty` | 一組等候中的客人（純資料），含排序鍵、被跳過次數、取號時間 | `app/Domain/Seating/WaitingParty.php` |
| 相關桌（relevant tables） | 出現在某組任何適合選項中的桌子，也就是「對他有用的桌子」 | `OptionFinder::relevantTableIds()` |
| `position_key` | 排隊順序；取號時為毫秒時間戳記，同營業日嚴格遞增，重新排入取中點 | `parties.position_key` |
| 全域順序 | 依 `(position_key, id)` 跨四個佇列排序 | `WaitingParty::sortByOrder()` |
| 前推模擬 | 預估器在虛擬時間上重複呼叫帶位演算法 | `app/Domain/Estimation/WaitEstimator.php` |
| `ActorContext` | 誰做了這個操作：員工（含平板）、顧客、系統 | `app/Support/ActorContext.php` |
| `QueueStateRefresher` | 每次狀態變更後同步保護保留、預估、通知、推播的單一入口 | `app/Services/QueueStateRefresher.php` |
| 快照（推播） | 公開頻道送出的候位狀態，不含個資 | `app/Services/QueueSnapshotBuilder.php` |
| `SkipAuditor` | 從操作紀錄獨立重算被跳過次數的稽核器 | `app/Services/SkipAuditor.php` |
| 建議一致性 | 模擬器檢查「系統建議都能成功叫號」 | `SimulationResult::suggestionsConsistent()` |
| 放寬（浪費上限） | 等滿 15 分鐘且沒有低浪費空桌時，允許浪費 3 席 | `OptionFinder::bestFreeOptionFor()` |

## 技術縮寫

| 縮寫 | 全名 | 說明 |
|---|---|---|
| MAE | Mean Absolute Error | 平均絕對誤差，本專案用來衡量預估準度 |
| P25／P50／P75 | Percentile | 第 25、50、75 百分位 |
| WAL | Write-Ahead Logging | SQLite 的日誌模式，讀取不被寫入擋住 |
| CSRF | Cross-Site Request Forgery | 跨站請求偽造；私有頻道驗證時要帶 XSRF token |
| BOM | Byte Order Mark | 檔案開頭的 `EF BB BF`，讓 Excel 以 UTF-8 開啟 CSV |
| CI | Continuous Integration | 持續整合，本專案用 GitHub Actions |
| E2E | End-to-End | 端對端測試（本專案沒有） |
| ADR | Architecture Decision Record | 架構決策紀錄，11 章的「設計取捨」是輕量版 |
