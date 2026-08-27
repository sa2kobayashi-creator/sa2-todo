# Sa2 ToDo — Laravel 12 版

Node.js 版から Laravel 12 + Blade へ移行したプロジェクトです。プロジェクトパス: `D:\Development\todo_sa2`

## データベースは環境ごとに違う

| 環境 | ドライバ | 正となる設定 |
|------|----------|--------------|
| 本番（ロリポップ） | **MySQL** | `.env.production.example` |
| ローカル開発 | **PostgreSQL 18** | 下表 |
| テスト | sqlite（インメモリ） | `phpunit.xml` |

本番は MySQL なので、**PostgreSQL 専用の SQL を無条件に書かないこと**。ドライバ差が出るところは
`DB::connection()->getDriverName()` で分岐する（既存例: `DatabaseSeeder::resetSequences()`、
`StorageUsageService::databaseBytes()`、`PhotoService::listPhotoYears()`）。
DB バックアップ（`DatabaseBackupService`）は `mysqldump` 前提で、PostgreSQL では動かない。

## 環境

| 項目 | 値 |
|------|-----|
| PHP | XAMPP `C:\xampp\php\php.exe`（8.3+） |
| DB | PostgreSQL 18（ローカル） |
| DB名 | `todo_app` |
| ユーザー | `postgres` / パスワード `postgres` |
| ポート | `5432` |

## 初回セットアップ

```powershell
cd D:\Development\todo_sa2
php artisan migrate
php artisan db:seed
```

## 起動方法

### 方法 A: artisan serve

```powershell
cd D:\Development\todo_sa2
php artisan serve
```

→ http://127.0.0.1:8000

### 方法 B: XAMPP Apache

`DocumentRoot` を `D:/Development/todo_sa2/public` に設定してください。

## ログイン

- メール: `admin@example.com`
- パスワード: Node 版と同じ

## 移行状況

- 認証、Todo 一覧、メモ、カレンダーダッシュボード、休日設定: 完了
- LINE 連携 / Web Push 通知: 次フェーズ（設定画面にスタブ表示）
