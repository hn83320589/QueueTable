# 04 技術選型

題目已經限定了大部分技術（PHP、Laravel、Vue 3、SQLite、不用 Docker），所以這一章的重點是「在限制內選了哪個版本、哪個套件，以及為什麼」。

## 後端

| 技術 | 版本 | 用途 | 選用理由 | 替代方案 |
|---|---|---|---|---|
| PHP | 8.3 以上（本機開發 8.6，CI 驗證 8.3、8.4） | 執行環境 | 📄 題目要求「PHP 8.3 以上」 | — |
| Laravel | 13.43 | Web 框架 | 📄 題目要求「Laravel 12 以上」；開發時 13 是最新版 | Laravel 12 |
| Laravel Fortify | 1.43 | 登入 | 📄 Vue Starter Kit 內建；只保留登入，關閉註冊、重設密碼、2FA、Passkey（題目：帳號由店長建立） | 自寫登入 |
| Laravel Reverb | 1.14 | WebSocket 伺服器 | 📄 題目指定「Laravel Reverb，或其他不需要 Docker 的 WebSocket 方案」 | Soketi、Pusher 雲端服務 |
| SQLite | PHP 內建 `pdo_sqlite` | 資料庫 | 📄 題目指定 | — |
| Pest | 4.7（PHPUnit 12.5） | 測試 | 📄 題目允許 Pest 或 PHPUnit；開發計畫選 Pest | PHPUnit class 寫法 |
| Larastan / PHPStan | 3.15，level 7 | 靜態分析 | 📄 Starter Kit 內建，定為「完成定義」的一部分 | — |
| Laravel Pint | 1.32 | PHP 格式化 | 📄 Starter Kit 內建 | PHP-CS-Fixer |

## 前端

| 技術 | 版本 | 用途 | 選用理由 | 替代方案 |
|---|---|---|---|---|
| Vue | 3.5 | UI | 📄 題目指定 Vue 3 | — |
| Inertia | v3（`@inertiajs/vue3`、`inertiajs/inertia-laravel` 3.8） | 前後端串接 | 📄 設計文件 §4.4：登入流程現成，不必另外處理 API 認證 | 獨立 SPA + Sanctum |
| TypeScript | 5 | 型別 | 📄 Starter Kit 預設 | JavaScript |
| Tailwind CSS | 4 | 樣式 | 📄 Starter Kit 預設 | — |
| vite-plus | 1.4 | 建置、格式化、lint | 📄 Starter Kit 預設（Vite 的封裝，`vp build`、`vp check`） | 原生 Vite + ESLint + Prettier |
| Wayfinder | 0.1 | 由 Laravel 路由產生 TypeScript 路由函式 | 📄 Starter Kit 預設 | 手寫 URL |
| `@laravel/echo-vue`、`laravel-echo`、`pusher-js` | 2.6 / 2.6 / 8.6 | 訂閱 Reverb 頻道 | 📄 Reverb 官方搭配的用戶端；`install:broadcasting --reverb` 會安裝 | Socket.IO |
| `qrcode` | 1.5 | 看板上產生取號 QR Code | 📄 設計文件 §3（計畫時告知使用者會新增此套件） | 後端產生 QR 圖片 |
| Barlow Condensed（字型） | — | 號碼與數字 | 📄 開發對話：視覺取材自熱炒店牆上手寫菜單，號碼用窄體大字遠距可讀 | 系統字型 |

## 版本相關的注意事項

### 為了 PHP 8.3 刻意用較舊的版本

> 📄 依據：commit `5371d60`「相依套件鎖定在 PHP 8.3 可安裝的版本」

開發初期裝的是 Pest 5、PHPUnit 13，Symfony 元件也是 8.x，三者都需要 PHP 8.4。題目寫的是「PHP 8.3 以上」，所以在 `composer.json` 設定：

```json
"config": {
    "platform": {
        "php": "8.3.0"
    }
}
```

這讓 composer 一律解析「PHP 8.3 也能安裝」的版本組合：Laravel 13 + Symfony 7.4 + Pest 4 + PHPUnit 12。CI 同時在 8.3 與 8.4 執行，確保兩者都能過。

**代價**：之後升級套件時，要先確認還需不需要支援 8.3；拿掉 `platform` 設定，composer 才會升到需要 8.4 的版本。

### Starter Kit 拿掉的東西

Laravel 13 的 Vue Starter Kit 附帶個人設定頁、2FA、Passkey、Email 驗證。題目只需要「店長建立的帳號用帳號密碼登入」，所以：

- `config/fortify.php` 的 `features` 清空，只留登入。
- 依賴 2FA／Passkey 的設定頁、元件與測試整組移除（Wayfinder 產生的路由函式會跟著消失，留著會讓前端建置失敗）。

> 📄 依據：開發紀錄 Task 1 的 Ruling。

### 開發指令用 Laravel 13 內建的 `artisan dev`

原本計畫用 `concurrently` 寫 `composer run dev`，但 Laravel 13 已經有 `php artisan dev` 加 `DevCommands` 註冊表。所以改在 `app/Providers/AppServiceProvider.php` 的 `configureDevCommands()` 註冊 Reverb 與排程，並移除用不到的 queue worker。

> 📄 依據：開發紀錄 Task 1 的 Ruling。

## 刻意沒用的東西

| 沒用 | 原因 |
|---|---|
| Queue worker | 推播用 `ShouldBroadcastNow`，交易提交後直接送出，少一個要啟動的程序。📄 設計文件 §5.13 |
| Redis | 單機、SQLite、每晚 150 組的規模用不到。🔍 推斷：題目限定 SQLite 且不需 Docker，加 Redis 只會增加部署負擔 |
| 自訂 Clock 介面 | Eloquent 的 timestamp 本來就用 `now()`，另外一個時鐘容易和它不同步。📄 設計文件 §4.2，見 [11 章](11-tradeoffs-pitfalls.md) |
