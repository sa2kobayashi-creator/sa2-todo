# Sa2 ToDo — Laravel 12 版

Node.js 版（`D:\Development\todo-app`）から Laravel 12 + PostgreSQL + Blade へ移行したプロジェクトです。

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
cd D:\Development\todo-app-laravel
php artisan migrate
php artisan db:seed
```

## 起動方法

### 方法 A: artisan serve

```powershell
cd D:\Development\todo-app-laravel
php artisan serve
```

→ http://127.0.0.1:8000

### 方法 B: XAMPP Apache

`DocumentRoot` を `D:/Development/todo-app-laravel/public` に設定してください。

## ログイン

- メール: `admin@example.com`
- パスワード: Node 版と同じ

## 移行状況

- 認証、Todo 一覧、メモ、カレンダーダッシュボード、休日設定: 完了
- LINE 連携 / Web Push 通知: 次フェーズ（設定画面にスタブ表示）
