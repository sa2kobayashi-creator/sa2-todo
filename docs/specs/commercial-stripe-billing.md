# Stripe 実課金（2B）設計

ステータス: **第1段階まで実装済み（本番キー未投入）**
記録日: 2026-08-27 / 更新: 2026-08-27

## 実装状況

| 項目 | 状態 | 場所 |
|---|---|---|
| Cashier 導入（v16） | 済 | `composer.json`、`AppServiceProvider::register()` で `Cashier::ignoreRoutes()` |
| `users` の Stripe カラム | 済 | `2026_08_27_185527_create_customer_columns.php`（`trial_ends_at` は既存カラムを共用） |
| subscriptions / subscription_items | 済 | Cashier 標準 migration |
| Webhook の受信ログと冪等化 | 済 | `billing_events` テーブル、`app/Models/BillingEvent.php` |
| プラン定義（price の allowlist） | 済 | `config/billing.php` |
| Stripe → エンタイトルメント写像 | 済 | `app/Services/StripeBillingService.php` |
| Checkout / Billing Portal | 済 | `app/Http/Controllers/BillingController.php`、`/mypage/plan` |
| Webhook 受信 | 済 | `app/Http/Controllers/StripeWebhookController.php`、`POST /webhooks/stripe` |
| テスト | 済 | `tests/Feature/StripeBillingTest.php`（19件） |
| **ストレージ超過の従量報告** | **未** | `StripeBillingService::overageUnits()` はあるが、Stripe への数量反映は未実装 |
| **請求書（テナント／専用）運用** | **未** | 手動の管理画面のまま |
| **本番の price 作成と設定画面への投入** | **未** | 下記「本番投入前チェック」参照 |

### 本番投入前チェック

1. Stripe ダッシュボードで商品と price を作る（通貨 JPY = ゼロ十進通貨。`980` は 980円であって 9.8円ではない）
2. 設定 → 公開販売 に公開可能キー・シークレット・Webhook 署名・Price ID を保存する（.env は未保存時のフォールバック）
3. Webhook エンドポイントに `https://sa2-plus.com/webhooks/stripe` を登録し、`checkout.session.completed`・`customer.subscription.*`・`invoice.paid`・`invoice.payment_failed` を購読
4. `/tokushoho` の `LEGAL_*` を埋める（未設定だと画面に警告が出る）
5. 最後に `BILLING_ENABLED=true`。これを false のままにしておけば申し込みボタンは出ない
対象: 販路 B（共有インスタンス）の Standard 月額・年額、テナント契約、ストレージ超過、メールボックス

関連（金額・製品判断の正はこちら。本書は**実装方法**の正）:

- `docs/specs/commercial-public-readiness.md` … 販路・ロール・金額案
- `docs/specs/commercial-storage.md` … クォータと超過の考え方
- `docs/specs/commercial-tenants.md` … テナント契約
- `docs/specs/commercial-billing-inventory.md` … 運営側の原価

---

## 0. 前提：受け皿はもうある

Stripe を入れる前に、判定側は 2026-08-21 に実装済み。**今回作るのは「Stripe → 既存カラムを更新する経路」だけ**で、機能ゲートのロジックは書き換えない。

| 既存の資産 | 場所 |
|---|---|
| 契約状態 enum（none/trial/active/past_due/canceled） | `app/Enums/SubscriptionStatus.php` |
| 判定の入口 | `app/Services/BillingEntitlementService.php` |
| 一括更新メソッド（Stripe 前提のコメント入り） | `BillingEntitlementService::apply()` |
| ユーザー側カラム | `users.subscription_status` / `trial_ends_at` / `storage_overage_active` / `mailbox_addon_active` / `special_quota` / `tenant_id` |
| 手動運用の管理画面 | `app/Http/Controllers/Admin/UserController.php:233` |
| 超過の停止判定 | `PhotoService::uploadsBlockedForUser()` / `userAllowsPaidOverageUploads()` |

つまり Webhook が `BillingEntitlementService::apply()` を呼べば、Photos の停止解除もメールボックスも既存コードのまま動く。**手動の管理画面は残す**（Stripe 障害時と請求書販売の逃げ道）。

---

## 1. 方針の決定事項

| 論点 | 決定 | 理由 |
|---|---|---|
| ライブラリ | **Laravel Cashier（`laravel/cashier`）** | Checkout・Billing Portal・署名検証・再試行が揃う。自前実装は事故が多い |
| 決済 UI | **Stripe Checkout（ホスト型）** | カード番号が自社サーバーを通らない。PCI DSS の対象範囲を最小化できる |
| 契約変更・解約 | **Stripe Billing Portal** | 解約・支払い方法変更・領収書ダウンロードを自前で作らない |
| 権限反映 | **Webhook 経由のみ**。成功画面では付与しない | 成功 URL は偽装できる |
| 通貨 | JPY（**ゼロ十進通貨**。`unit_amount` は 980 と書く。98000 ではない） | 単位を間違えると100倍請求になる |
| 販路 A（専用インスタンス） | **Stripe に載せない**。請求書・銀行振込のまま | 件数が少なく、初期費用と保守が個別見積 |
| ストレージ超過 | **数量課金（100GB 単位の quantity 更新）**。Billing Meters は使わない | 実装が軽く、ユーザーにも金額が読める |

### Cashier 導入時の注意（衝突する）

Cashier の `Billable` トレイトは **`users.trial_ends_at` を汎用トライアルとして使う**。本アプリは同名カラムを既に持っている。

意味はほぼ一致する（`SubscriptionStatus::Trial` の期限＝汎用トライアル終了日）ので、**共用する**。ただし次を必ず守る。

- `trial_ends_at` を触るのは `BillingEntitlementService::apply()` と Cashier だけ
- Cashier の `onGenericTrial()` と自前の `status()` が食い違わないよう、`BillingEntitlementService::status()` に「`trial_ends_at` が未来なら Trial」の判定を残す（現状どおり）
- Cashier が追加する `subscriptions` / `subscription_items` テーブルは **Stripe の写し**。アプリの判定はこれを見ない（見るのは `users` の4カラム）

---

## 2. Stripe 側のオブジェクト設計

金額は `commercial-public-readiness.md` の「0-3. 金額案」に合わせる。**すべて税込・総額表示**。

| Product | Price（lookup_key） | 金額 | 課金 |
|---|---|---|---|
| Sa2 Plus Standard | `standard_monthly` | ¥980 / 月 | recurring・licensed |
| Sa2 Plus Standard | `standard_yearly` | ¥9,800 / 年 | recurring・licensed |
| テナント契約 | `tenant_monthly` | ¥3,980 / 月（試用30日） | recurring・licensed |
| テナント契約 | `tenant_yearly` | ¥43,780 / 年 | recurring・licensed |
| ストレージ超過 | `storage_overage_100gb` | ¥300 / 月 | recurring・**quantity = 100GB 単位** |
| メールボックス | `mailbox_monthly` | ¥300 / 月 | recurring・licensed |
| メールボックス | `mailbox_yearly` | ¥3,000 / 年 | recurring・licensed |

### 構成のルール

- **1顧客につき Subscription は1本**。Standard（またはテナント）を主アイテムにし、超過・メールボックスを **同じ Subscription の追加 item** にする。請求書が1枚にまとまり、支払い失敗時の扱いも1本化できる。
- Price ID は環境ごとに変わるので、コードに直書きしない。**`lookup_key` で引く**か `config/billing.php` に env から流す。
- 顧客側から price を指定させない。**リクエストで受け取るのはプラン名（`standard_monthly` 等）だけ**で、サーバーが allowlist から price を引く。

### 消費税とインボイス

- **免税事業者のうちは**税額を分けて表示しない。「¥980（税込）」とだけ書く。
- 課税事業者になったら、Stripe の請求書に **適格請求書発行事業者の登録番号** を出す（Invoice の `custom_fields` または `footer`、Dashboard の請求書テンプレートで設定）。
- 総額表示義務があるので、料金ページ・Checkout・特商法表記のすべてで税込額を一致させる。

---

## 3. データベース追加

Cashier の標準マイグレーション（`users.stripe_id` / `pm_type` / `pm_last_four`、`subscriptions`、`subscription_items`）に加えて、次を1つ足す。

```
billing_events
  id
  stripe_event_id  string unique   -- 冪等性の要。同じイベントを二度処理しない
  type             string          -- customer.subscription.updated など
  payload          json
  status           string          -- received / processed / failed
  error            text nullable
  processed_at     timestamp nullable
  timestamps
```

任意（あると運用が楽）:

```
billing_usage_reports   -- ストレージ超過の数量を Stripe に送った履歴
  id, user_id, period_start, period_end, used_bytes, quota_bytes, billed_units, reported_at
```

`users` に足すのは Cashier の分だけ。契約状態は既存4カラムを使い回す。

---

## 4. 画面と導線

| 画面 | パス | 中身 |
|---|---|---|
| 料金ページ（未ログイン可） | `/pricing` | プラン比較。特商法表記・規約へのリンク必須 |
| プラン管理 | `/mypage/plan` | 現在の契約、次回請求日、使用量、変更・解約ボタン |
| 申し込み | `POST /billing/checkout` | Checkout Session を作って redirect |
| 戻り（成功） | `/billing/success` | 「反映まで少し時間がかかります」。**ここで権限を付けない** |
| 戻り（中止） | `/billing/cancel` | プラン管理へ戻すだけ |
| 契約変更・解約・領収書 | `POST /billing/portal` | Billing Portal へ redirect |
| Webhook | `POST /webhooks/stripe` | 署名検証。CSRF 除外 |

### Checkout Session の作り方（要点）

```php
$user->newSubscription('default', $priceId)   // Cashier
    ->trialDays($plan->trialDays)             // Standard 14日 / テナント 30日
    ->allowPromotionCodes()
    ->checkout([
        'success_url' => route('billing.success').'?session_id={CHECKOUT_SESSION_ID}',
        'cancel_url'  => route('billing.cancel'),
        'locale'      => 'ja',
        'customer_update' => ['address' => 'auto', 'name' => 'auto'],
    ]);
```

- `$priceId` は **allowlist から引いた値のみ**
- 既に有効な契約がある場合は Checkout を作らず Portal へ流す（二重契約の防止）
- `client_reference_id` に `user_id` を入れておくと、Webhook 側の突合が確実になる

### Billing Portal の設定（Stripe Dashboard 側）

- 解約: **期末解約のみ**許可（即時解約・日割り返金は許可しない）
- プラン変更: Standard 月↔年のみ許可。テナント契約は運営が対応（誤操作防止）
- 支払い方法の更新: 許可
- 請求書履歴: 許可

---

## 5. Webhook の設計（ここが本体）

`routes/web.php` に置き、`bootstrap/app.php` の `validateCsrfTokens(except: [...])` に `webhooks/stripe` を追加する（既存の LINE / Messenger と同じ扱い）。

### 処理の順番

1. `Stripe\Webhook::constructEvent()` で **署名検証**。失敗は 400 を返して終わり
2. `billing_events` に `stripe_event_id` で **insert を試みる**。ユニーク制約で弾かれたら「処理済み」として 200 を返す（Stripe は再送してくる）
3. イベント種別ごとに `BillingEntitlementService::apply()` を呼ぶ
4. 例外時は `status=failed` を記録し **500 を返す**（Stripe が自動再送する）

### 扱うイベントと反映

| イベント | やること |
|---|---|
| `checkout.session.completed` | `client_reference_id` → ユーザー特定。`stripe_id` を保存 |
| `customer.subscription.created` / `.updated` | Stripe の `status` を `SubscriptionStatus` に写す。price から機能を反映 |
| `customer.subscription.deleted` | `canceled`。期末を過ぎていれば Light 相当へ |
| `invoice.paid` | `active` に戻す。`past_due` の解除 |
| `invoice.payment_failed` | `past_due`。ユーザーへ通知メール |
| `customer.subscription.trial_will_end` | 3日前の案内メール |

### Stripe status → SubscriptionStatus

| Stripe | アプリ |
|---|---|
| `trialing` | `Trial` |
| `active` | `Active` |
| `past_due` / `unpaid` | `PastDue` |
| `canceled` / `incomplete_expired` | `Canceled` |
| `incomplete` | 変更しない（決済未完了。まだ何も開けない） |

### price → 権限の反映

`config/billing.php` にプラン定義を置き、Webhook はそれを引くだけにする。

```php
'plans' => [
    'standard_monthly' => [
        'role' => UserRole::Standard,
        'grants' => ['storage_overage' => false, 'mailbox' => true],
        'quota_bytes' => 200 * 1024 ** 3,
        'trial_days' => 14,
    ],
    // ...
],
```

**ロールを自動で書き換えてよいのは Light ↔ Standard だけ。** `SuperAdmin` / `Admin`（テナント代表）は Webhook から触らない。運営が手で管理する人を、決済イベントで降格させないため。

---

## 6. ストレージ超過の課金

```
月次バッチ（cron・毎月1日）
  ├─ ユーザーごとに前月の使用バイトを集計（PhotoService::userUsedBytesApprox 相当）
  ├─ 超過 = 使用量 − プラン枠（Light 50GB / Standard 200GB）
  ├─ 課金単位 = ceil(超過 / 100GB)   ※0なら item を削除
  ├─ Subscription item の quantity を更新（proration_behavior: 'none'）
  └─ billing_usage_reports に記録
```

- **日次で quantity を上下させない。** 月1回だけ確定させる。日割り計算が絡むと請求が読めなくなる。
- 超過が発生する前に**アプリ内で警告**する（枠の 80% / 100%）。黙って課金しない。
- ユーザーが「超過課金を使わない」を選べるようにし、その場合は現状どおり `PHOTO_BLOCK_UPLOADS_OVER_FREE_QUOTA` で停止する。これが既定。

---

## 7. 支払い失敗と解約の扱い

| 状態 | アプリの挙動 |
|---|---|
| `past_due` 0〜7日 | 全機能そのまま。画面上部に警告バナーと更新導線 |
| `past_due` 8日〜 | アップロードと Standard 追加機能を停止。閲覧・エクスポートは維持 |
| `canceled`（期末） | Light 相当へ。**データは消さない** |
| 解約後の保持 | 規約に書いた期間（例: 90日）で凍結。その後に削除予告メール → 削除 |

Stripe 側は Smart Retries と dunning メールを有効にする。**データの即時削除は絶対にしない**（返金トラブルの元）。

---

## 8. 規約・特商法との接続（実装前に必須）

Stripe の審査でも、下の3つは実質求められる。

1. **特定商取引法に基づく表記**（`/legal/tokushoho`）— 現状ページが無い。販売事業者名・所在地・連絡先・価格・支払方法・支払時期・提供時期・**返品/キャンセルの可否**を明記。個人事業主は原則として氏名・住所の記載が必要
2. **利用規約に課金条項**— 自動更新、解約は期末、日割り返金なし、値上げ時の手続き（定型約款の変更）
3. **プライバシーポリシーに Stripe を追記**— 決済情報は Stripe に直接送られ、当方はカード番号を保持しない旨。越境移転（米国）にも触れる

料金ページ・Checkout・特商法表記で**金額が1円でも食い違わないこと**。

---

## 9. .env に足すもの

```
STRIPE_KEY=pk_live_xxx
STRIPE_SECRET=sk_live_xxx
STRIPE_WEBHOOK_SECRET=whsec_xxx
CASHIER_CURRENCY=jpy
CASHIER_CURRENCY_LOCALE=ja_JP
CASHIER_LOGGER=stack

BILLING_ENABLED=false                 # 段階公開のスイッチ
BILLING_PRICE_STANDARD_MONTHLY=price_xxx
BILLING_PRICE_STANDARD_YEARLY=price_xxx
BILLING_PRICE_TENANT_MONTHLY=price_xxx
BILLING_PRICE_TENANT_YEARLY=price_xxx
BILLING_PRICE_STORAGE_OVERAGE=price_xxx
BILLING_PRICE_MAILBOX_MONTHLY=price_xxx
BILLING_PRICE_MAILBOX_YEARLY=price_xxx
```

**Stripe の鍵は設定 → 公開販売（運営者のみ）が正。** `media_storage_settings` の `secrets`（encrypted:array）に保存する。画面では保存後 `••••` としか出ない。`.env` は未保存時のフォールバック。

---

## 10. テスト

| 対象 | 方法 |
|---|---|
| Webhook の反映 | Feature テスト。署名検証を通した固定 payload で `users` の4カラムを検証 |
| 冪等性 | 同じ `stripe_event_id` を2回投げて1回しか処理されないこと |
| 更新・失敗・解約 | Stripe **Test Clocks** で1か月進める。`invoice.payment_failed` はテストカード `4000 0000 0000 0341` |
| ローカル中継 | `stripe listen --forward-to localhost:8000/webhooks/stripe` |
| 金額 | JPY がゼロ十進であること（¥980 → `unit_amount: 980`）をテストで固定する |

既存の `BillingEntitlementService` にはテストが薄いので、Stripe より先にここのテストを足す。

---

## 11. 実装順序

| # | やること | 目安 |
|---|---|---|
| 1 | 特商法表記ページ・規約の課金条項・プライバシーの Stripe 追記 | 小（ただし先） |
| 2 | Stripe アカウント開設（個人事業主）・本人確認・Product/Price 作成 | 小 |
| 3 | Cashier 導入、`config/billing.php`、`billing_events` テーブル | 中 |
| 4 | `/pricing` と `/mypage/plan` | 中 |
| 5 | Checkout / Portal / Webhook | 中 |
| 6 | Standard 月額だけで少人数ベータ（招待制のまま） | — |
| 7 | ストレージ超過の月次数量更新 | 中 |
| 8 | メールボックスを Stripe 連動に（現状の手動承認から） | 小 |
| 9 | 運営向け請求ダッシュボード（失敗一覧・使用量） | 中 |

**6 まで通ってから 7 に進む。** 最初から従量課金を混ぜない。

---

## 12. やらないこと（初版）

- 販路 A（専用インスタンス）の Stripe 化。請求書のまま
- DeepL / 鮮明化 / 航空のアドオン課金。売らない方針が先
- Billing Meters による秒単位の従量課金
- 複数通貨・海外向け価格
- アプリ内でのカード情報の保持（永久にやらない）
