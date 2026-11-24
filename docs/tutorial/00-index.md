# QueueTable 教學文件：導覽

QueueTable 是一間約 80 席熱炒店的**候位叫號與帶位系統**：客人用門口平板或手機取號，帶位人員的平板建議「叫哪一組、帶到哪一桌」，門口看板與顧客手機透過 WebSocket 即時更新，並附一個用加速時鐘跑完整晚的尖峰模擬器。

**技術棧**：PHP 8.3+／Laravel 13、Vue 3 + TypeScript + Inertia v3、SQLite、Laravel Reverb（WebSocket）、Pest。細節見 [04 技術選型](04-tech-stack.md)。

**文件對應版本**：`commit b77c678`（2026-11-24，PR #2 合併後的 `main`）

## 這份文件怎麼讀

| 路線 | 章節 | 適合 |
|---|---|---|
| 快速掌握（約 30 分鐘） | 00 → 03 → 07 | 想先知道程式長什麼樣子 |
| 完整學習 | 01 → 12 依序 | 想把整個專案從需求到部署走一遍 |
| 想學演算法 | 08 → 07「帶位」小節 → 09 | 帶位建議、跳號、等候預估 |
| 想學併發與資料一致性 | 05 → 03「橫切關注點」→ 11 | 兩台平板同時操作怎麼不出錯 |
| 想學即時推播 | 06 → 07「推播」小節 | Reverb、公開／私有頻道 |
| 想學怎麼用模擬驗證設計 | 08「模擬器」→ 09 → 11 | 加速時鐘、獨立稽核、量測取捨 |

## 章節清單

| 章節 | 檔案 | 狀態 |
|---|---|---|
| 01 需求分析 | [01-requirements.md](01-requirements.md) | 已產生 |
| 02 系統分析 | [02-system-analysis.md](02-system-analysis.md) | 已產生 |
| 03 架構設計 | [03-architecture.md](03-architecture.md) | 已產生 |
| 04 技術選型 | [04-tech-stack.md](04-tech-stack.md) | 已產生 |
| 05 資料庫設計 | [05-database.md](05-database.md) | 已產生 |
| 06 API 規格與資料流 | [06-api-dataflow.md](06-api-dataflow.md) | 已產生 |
| 07 程式內容導讀 | [07-code-walkthrough.md](07-code-walkthrough.md) | 已產生 |
| 08 核心演算法 | [08-algorithms.md](08-algorithms.md) | 已產生 |
| 09 測試 | [09-testing.md](09-testing.md) | 已產生 |
| 10 部署與環境設定 | [10-deployment.md](10-deployment.md) | 已產生 |
| 11 設計取捨與踩坑紀錄 | [11-tradeoffs-pitfalls.md](11-tradeoffs-pitfalls.md) | 已產生 |
| 12 延伸練習與自我檢核 | [12-exercises.md](12-exercises.md) | 已產生 |
| 名詞對照表 | [glossary.md](glossary.md) | 已產生 |

## 標記說明

- 📄 **依據**：有明確來源，例如題目原文 `docs/requirements.md`、設計文件、commit 訊息，或開發對話中使用者的說明。
- 🔍 **推斷**：從程式碼行為反推、或作者整理的理由，沒有直接的文字依據。讀到這類內容時，請以「當時可能這樣想」看待。

## 相關文件

這份教學文件之外，repo 裡還有：

| 文件 | 內容 |
|---|---|
| `README.md` | 安裝啟動、演算法摘要、模擬結果、驗收對照表、已知限制 |
| `docs/requirements.md` | 題目原文（功能需求 1–8、驗收標準 1–14） |
| `docs/superpowers/specs/2026-11-24-queuetable-design.md` | 開發前的設計文件（含之後的修訂註記） |
| `docs/superpowers/plans/2026-11-24-queuetable.md` | 18 個任務的實作計畫 |

## 修訂紀錄

| 日期 | 對應 commit | 更新內容 |
|---|---|---|
| 2026-11-24 | `b77c678` | 初版，產生 00–12 章與名詞對照表 |
