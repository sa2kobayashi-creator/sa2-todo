# 製品の方向（公開販売）

ステータス: **実装中（2026-08-26）**  
対象: 自分の Photos と生活の便利さから始めたアプリを、招待制のまま公開販売する

関連:

- `docs/specs/commercial-tenants.md` … テナント契約の中身
- `docs/specs/commercial-public-readiness.md` … 公開ギャップ・販路
- `docs/specs/commercial-dedicated-instance.md` … 専用インスタンス（別サーバー）
- `docs/specs/commercial-storage.md` … ストレージはアプリ全体1セット＋ユーザー枠

単価の実行値は `config/commercial.php`（環境変数で上書き可）。

## 売り（看板）

容量の安売り比較はしない。売りは次の4点。

1. **写真・動画を、極力安価で安定した場所に置く**（原本は R2／B2。ライト約 50GB／スタンダード約 200GB）
2. **カレンダーとメモを簡単に表示・登録する。仕事用にも切り替えられる**
3. **英語表示**（同居家族向け）
4. **これから Cloudflare Workers AI で生活を助ける**（下の5本）

販売で特に厚くするのは **テナント契約**。共有 `sa2-plus.com` 上で家族・小規模が管理者つきで使える、「極力安価で安定」の本命。

## テナント契約の公開料金（税別）

「料金はお問い合わせ」は出さない。**使用期間つきの優良**を最初から出す。

| 項目 | 公開額（推奨・config で変更） |
|---|---|
| 試用 | **最初の 30 日は月額なし**（`tenant_trial_days`） |
| 月額 | **¥3,980**（5名まで・代表含む、`@sa2-plus.com` 各1込み） |
| 年額 | 月額 × 11か月分（専用保守と同じ割引） |
| 6人目以降 | ¥1,000／人／月（運営が上限を上げる） |

専用インスタンス（初期 ¥50,000・月額 ¥8,000・別サーバー）より安い。個人スタンダード5人分（¥4,900）より、管理者とメール込みで安い。

実課金（Stripe）は未実装。公開額で申し込みを受け、運営が `/admin/tenants` で契約を作り、試用終了日を記録する。試用切れで自動停止はしない（請求は手運用）。

## Workers AI の5本

運営（またはテナント代表）が **設定 → AI** に Cloudflare アカウント ID と Workers AI トークンを入れる。未設定なら運営キーにフォールバック（既存 LLM と同じ）。

既定モデル: `@cf/meta/llama-3.1-8b-instruct-fp8`（安価・現行カタログ。品質を上げるなら設定で Llama 4 Scout 等へ）。

| # | 内容 | 段階 |
|---|---|---|
| 1 | Workers AI ＋ **NAVITIME API** で精度の高い路線案内 | **実装済み** … 路線検索が NAVITIME の経路検索を使う（未設定・失敗時は内蔵 RAPTOR）。同じ画面に AI 相談も組み込み済み |
| 2 | 生活の知恵・ガイド・話し相手 | **実装済み** … メニュー「生活ガイド」 |
| 3 | Workers AI ＋ カレンダー | **第一版** … 今日の予定／Todo を添えて案内 |
| 4 | Workers AI ＋ **Travelpayouts** で航空運賃 | **運営者のみ（試作）** … `/travel` と Travelpayouts 設定は SuperAdmin だけ。管理者・Standard にはメニューも付与もしない |
| 5 | 料理レシピ・調理方法 | **実装済み** … 生活ガイドの話題切替 |

生活ガイドに並ぶ話題は **既定3つ（生活の知恵・料理レシピ・カレンダー）＋ ユーザーが自分で追加した話題**（`guide_topics`、1人 12 個まで）。追加時に名前・絵文字・AI への指示を持たせ、指示はシステムプロンプトに足す。路線の相談は生活ガイドの一覧には出さず `/transit` に埋め込む。航空の AI は運営者の `/travel` のみ。路線の AI は **相談 → 経路検索 API → その結果で回答 → 再相談** と回し、時刻・乗換・運賃は API の itinerary だけを正とする。

ユーザーあたり日次上限（`USER_WORKERS_AI_REQUESTS_PER_DAY`、既定 20。`0` で無制限）。**スーパー管理者は運営本人なので上限なし**、管理者以下に適用する。原価は Cloudflare Neurons／トークン従量。

## 経路検索の切り替え（RouteSearchService）

路線検索の窓口は `App\Services\Transit\RouteSearchService`。画面とフロント（`public/transit.js`）はここだけを呼び、どの API を使うかは知らない。

```text
/transit → TransitController → RouteSearchService → RouteProvider
                                                     ├ GoogleRoutesRouteProvider（google）
                                                     ├ NavitimeRouteProvider（navitime）
                                                     ├ EkispertRouteProvider（ekispert）
                                                     └ RaptorRouteProvider（raptor・契約不要）
```

プロバイダは `App\Services\Transit\Contracts\RouteProvider`（`key` / `label` / `isReady` / `search`）を実装し、**画面共通の itinerary 形式**（`departureTime` `arrivalTime` `durationLabel` `transfers` `fareLabel` `legs[]`）で返す。新しい API を足すときは、この変換クラスを1つ書いて `RouteSearchService` の `$providers` に並べるだけで、画面もフロントも変更しない。

使う API の決まり方は **設定 → API設定 → 経路検索に使う API ＞ `ROUTE_PROVIDER` ＞ `auto`**。`auto` と、選んだ API が失敗したときは、契約情報が入っている次のプロバイダへ回して結果を必ず返す（切り替わったときは `engineNote` に理由を入れる）。契約情報は同じ画面の **Google Maps Routes / NAVITIME / 駅すぱあと** で登録する。

契約前の比較は `php artisan transit:compare 志賀島 博多駅 --at="2026-08-26 08:00" --legs` で、全プロバイダの出発・到着・所要・乗換・運賃を並べて確認する。

## NAVITIME API（路線検索）

**設定 → API設定 → NAVITIME** で契約情報を保存する（`media_storage_settings` の `navitime`。キーは暗号化、テナント単位）。契約は2通り。

| mode | 入力するもの | 備考 |
|---|---|---|
| `rapidapi` | API キー・ルート検索のホスト（既定 `navitime-route-totalnavi.p.rapidapi.com`）・任意で地点検索のホスト | RapidAPI / SBI API Hub。バスの実時刻表や始発終発は契約対象外なので、志賀島のようなバス主体の区間の比較には向かない |
| `direct` | ベース URL `https://{HOST}/{CID}/v1`・認証ヘッダ名・API キー | ナビタイムジャパン直接契約。IP 制限のみの契約ならキーは空でよい |

`POST /transit/search` は NAVITIME が有効なら `route_transit` を呼び、結果を RAPTOR と同じ itinerary 形式に変換して返す（`engine` に `NAVITIME`）。未設定・失敗時は内蔵 RAPTOR にフォールバックし、`engineNote` に理由を入れる。

本来の精度（バス時刻表を含む）を確かめるなら、ナビタイムジャパンが直接契約検討者向けに出している **90日間の無料お試し環境** を使う。`direct` モードにベース URL と認証ヘッダを入れれば、そのまま検証できる。

地名 → 緯度経度は「NAVITIME の地点検索（契約時のみ）→ Google Maps ジオコーディング」の順に解決し、24 時間キャッシュする。呼び出し回数は `integration_usage_dailies` の `navitime` に計上する（設定 → 利用量）。

## Google Maps Routes API（路線検索）

**設定 → API設定 → Google Maps Routes API** で有効化し、キーを保存する（`media_storage_settings` の `google_routes`。キーは暗号化、テナント単位）。専用キーが空なら、同じ画面の Google マップキーを流用する。`POST /transit/search` は Routes API の `computeRoutes`（`travelMode: TRANSIT`）を呼び、結果を RAPTOR と同じ itinerary 形式に変換して返す（`engine` に `Google Maps Routes`）。

## 駅すぱあと（路線検索）

**設定 → API設定 → 駅すぱあと** でアクセスキーを保存する（`media_storage_settings` の `ekispert`。キーは暗号化、テナント単位）。`search/course/extreme` を呼び、結果を RAPTOR と同じ itinerary 形式に変換して返す（`engine` に `駅すぱあと`）。

## 個人プランとの関係

ライト（¥0）とスタンダード（¥980／月）は招待の入口。家族で管理者とメールが必要ならテナントへ。サーバー分離が必要なら専用へ。
