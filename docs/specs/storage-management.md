# ストレージ管理・データアーカイブ（実装）

正本の初期案: `docs/sa2todo_ストレージ管理_データアーカイブ仕様書.docx`  
本ファイルは実装時に決めた項目（仕様書 §15）を含む。

## 構成

| データ | 通常 | アーカイブ |
|---|---|---|
| アプリ / 現役 MySQL | ロリポップ | — |
| 写真・動画 | R2（hot） | B2（既存 `photos:archive-cold`） |
| `@sa2-plus.com` メール | ロリポップ IMAP | B2（EML）+ `mail_archives` |
| 古い完了 Todo / 完了・整理済みメモ | MySQL | B2（JSON）+ `data_archives` |
| DB 全体バックアップ | — | B2 `backup/db/YYYY-MM-DD.sql.gz` |

第3ストレージ（Wasabi 等）は対象外。

## しきい値（初期・config/storage_management.php）

| 対象 | 警告 | 開始 | 目標 |
|---|---|---|---|
| R2（ホット合計） | 8GB | 9GB | 運用上限 10GB |
| メール（1ボックス） | 700MB | 800MB | 約 600MB |
| DB（information_schema 合算） | 300MB | 500MB | 段階アーカイブ |

## Cron

- `storage:manage` … **毎時**（使用量計測 → 優先順位どおり退避）
- `storage:backup-db` … **毎日 03:00**（アーカイブとは別）
- 既存 `photos:archive-cold` は継続。R2 が 9GB 超のときは `storage:manage` からも呼び出す

## B2 パス

- `archives/mail/{userId}/{accountId}/{Y}/{m}/{imapUid}-{rand}.eml`
- `archives/db/{userId}/{table}/{Y}/{m}/{recordId}-{rand}.json`
- `backup/db/{Y-m-d}.sql.gz`

バケットは写真と同じ `backblaze` ディスク（private）。アプリからの参照はメタテーブル経由。

キーは B2 へ書く前に確定させる。メタ行 ID を待って採番すると「行を先に作る」順序になり、
put 失敗時に実体のない行が残るため。

## 優先順位（超過時）

1. 新しいデータは保護（メール直近 90 日、Todo/メモ 1 年未満・未完了は自動移動しない）
2. 古いメールを B2 へ（保存確認後に IMAP 削除＋EXPUNGE）
3. 古い写真・動画を B2 へ
4. 1 年以上前かつ完了済み Todo / 完了または整理済みメモを B2 へ
5. 対象が無いときは削除せず、ログに「整理が必要」と残す

自動アーカイブの対象外:

- `keep_on_server = true` の Todo / メモ（`/archives` から利用者が指定する）
- `group_id` のあるグループ共有 Todo / メモ（他メンバーの画面からも消えるため）
- ピン留めメール（flagged）、ピン留めメモ（pinned）
- 送信日時が読めないメール（保護期間を判定できないため触らない）

メモを退避するときは `note_attachments` の行も JSON に含める。`notes` 行を消すと
外部キーの cascade で添付メタが消えるため、これが無いと復元しても添付が戻らない。
添付の実体（R2 上のファイル）は消さない。

## 失敗時

B2 保存が確認できるまで元データは消さない。連続失敗 3 回でそのランは打ち切る。結果は `storage_management_logs`。

B2 保存後に IMAP 削除だけ失敗した場合は、`mail_archives` の
`(mail_account_id, folder_path, imap_uid)` 一意制約で二重保存を防ぎ、次回実行で削除だけ再試行する。

## 実行環境の制約（ロリポップ）

- メールの退避は 1 バッチにつき IMAP 接続 2 回（一括取得 → 一括削除）。1 通ごとに接続しない。
- DB バックアップは一時ファイルへ gzip ストリーム書き込みし、`writeStream` で B2 へ送る。
  ダンプ全体をメモリに載せない。
- `mysqldump` のパスワードは `MYSQL_PWD` で渡す。`--password=` は共有サーバーの `ps` から読めるため使わない。
- php フォールバックのダンプにも `CREATE TABLE` を含める（INSERT だけでは復元できない）。

## バックアップ保持

14 日分。古い `backup/db/*` は削除する。

## UI

- SuperAdmin: `/admin/storage-archive`（使用量・最終実行）
- メール: フォルダ「長期保存」（`SA2.B2`）でアーカイブ閲覧
- `/archives` で Todo / メモのアーカイブ検索・復元
- `/archives` 上部に「まもなく長期保存に移るもの」を出し、`POST /archives/keep` で
  `keep_on_server` を立てられる。復元時は元の `created_at` / `updated_at` を戻す
