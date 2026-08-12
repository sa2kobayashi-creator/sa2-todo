# 販売レベル化・ストレージ仕様（将来実装）

ステータス: **未着手（記録のみ）**  
記録日: 2026-07-28  
前提: UI / 使いやすさの改善を優先し、販売準備として後から実装する。

関連: 公開・複数利用の実装ギャップは `docs/specs/commercial-public-readiness.md`。

## ゴール

本アプリを販売可能なマルチテナント製品にする。  
顧客（ユーザー）ごとにデータ・課金・（必要なら）ストレージ接続を分離する。

## 現状（2026-07-28）

| 項目 | 現状 |
|------|------|
| R2 / B2 / Cloudinary 等の API キー | **アプリ全体で1セット**（`media_storage_settings` は `provider` 単位。`user_id` なし） |
| 写真・動画データ | **ユーザーごと**（`photos.user_id`） |
| 無料枠 | **ユーザーごと合計 20GB**（R2+B2 合算相当） |
| 超過時 | 新規追加を拒否（`PHOTO_BLOCK_UPLOADS_OVER_FREE_QUOTA`） |
| 課金 | **見込表示のみ**（2A）。実徴収（Stripe 等・2B）は未実装 |
| 有料超過フック | `PHOTO_PAID_OVERAGE_ENABLED` + 将来の `User::storage_overage_active` |

## 決定済みの製品方針（クォータ）

- **1A:** 無料枠はユーザーごと **合計 20GB**
- **2A（今）:** 超過は見込料金表示のみ
- **3B:** 無料枠超過かつ有料未契約ならアップロード等を停止
- **2B（将来）:** Stripe 等で実課金し、超過利用を解禁

## 販売レベルで将来実装する項目

### 必須候補

1. **テナント分離**
   - データ・設定・請求の境界をユーザー（または組織）単位で明確化
2. **実課金（2B）**
   - Stripe（または同等）で無料枠超過分 / プラン課金
   - 未払い時は現状の 3B（追加停止）と連携
3. **ストレージ接続の販売モデル（どちらか、または併用）**
   - **A. 運営共有ストレージ（推奨しやすい SaaS 形）**  
     運営の R2/B2 を使い、ユーザーごとクォータ＋従量課金。キーは運営が保持。
   - **B. ユーザー（顧客）ごと API キー／バケット**  
     各顧客が自分の Cloudflare R2 / B2 等を設定画面で登録。  
     キーは `user_id`（または `organization_id`）付きで保存・暗号化。  
     「自分のクラウドに保管」が売りになる場合向け。
4. **管理画面**
   - 顧客・使用量・請求・障害の把握
5. **削除・退会・バックアップ方針**
   - データ保持期間、退会時のオブジェクト削除、監査ログ

### ストレージ設定をユーザーごとにする場合（B）の実装メモ

- `media_storage_settings` をユーザー（または組織）スコープに拡張、または別テーブル化
- ランタイムで「現在ユーザーのディスク設定」を `applyRuntimeDisks` 相当に載せる
- 既存のアプリ全体設定は「運営デフォルト／スーパー管理者用」として残すか移行する
- 接続テスト・権限（本人のみ編集）・キー再入力 UX
- パス接頭辞は引き続き `photos/{userId}/...` でオブジェクト衝突を避ける

## 実装順序（案）

1. UI / UX 改善（優先・進行中）
2. Stripe 等の実課金（2B）＋超過解禁
3. 販売モデル確定（共有ストレージ課金 vs 顧客持ちキー）
4. 必要ならユーザーごとストレージ設定（B）
5. 管理画面・退会・運用ドキュメント

## 関連コード（現状）

- `config/photos.php` … `user_free_quota_bytes`, `paid_overage_enabled`, `block_uploads_over_free_quota`
- `app/Services/PhotoService.php` … `userFreeQuotaBytes`, `assertWithinFreeQuotaOrPaid`, `userAllowsPaidOverageUploads`
- `app/Models/MediaStorageSetting.php` … プロバイダ単位のグローバル設定
- `app/Services/MediaStorageConfigService.php` … ランタイムディスク適用

## メモ

UI 改善後に販売着手。本ファイルは忘れ防止の仕様メモであり、実装チケット化は販売フェーズで行う。
