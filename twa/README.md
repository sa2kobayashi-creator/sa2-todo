# Sa2 Plus TWA（Android）

PWA（https://sa2-plus.com）を Trusted Web Activity で Android アプリ化するための作業ディレクトリです。

## 前提

- PWA 確認済み（`start_url: /`、Service Worker 登録）
- Node.js / npm
- JDK 17（Bubblewrap 推奨）
- Android command-line tools（Bubblewrap が初回に案内・導入可）

## パッケージ ID

- `com.sa2_plus.twa`（Bubblewrap init で作成した実ID）
- ホスト: `sa2-plus.com`
- Web Manifest: `https://sa2-plus.com/manifest.webmanifest`
- `minSdkVersion`: **23**（Play Billing を使わない構成）

> init 時に Play Billing / Location Delegation をオンにすると minSdk 21 と衝突しやすいです。  
> 課金が不要なら `twa-manifest.json` の `features` は空、`minSdkVersion` は 23 にして `bubblewrap update` してください。

## 手順（この PC でビルドする場合）

### 1. JDK 17（**64-bit 必須**）

```powershell
winget install -e --id Microsoft.OpenJDK.17
```

インストール後、新しいターミナルで `java -version` に **64-Bit Server VM** と出ることを確認。

Bubblewrap が同梱の **32-bit JDK** を使うと、リリースの R8（minify）中に `Gradle build daemon disappeared` / metaspace OOM になります。また JDK パスにスペース（`C:\Program Files\...`）があると署名時に `'C:\Program' は認識されません` になります。`~/.bubblewrap/config.json` の `jdkPath` は **64-bit かつスペースなし（8.3 短縮パス可）** にしてください（BOM なし UTF-8）:

```json
{
  "jdkPath": "C:\\PROGRA~1\\MICROS~4\\JDK-17~1.8-H",
  "androidSdkPath": "C:\\Users\\<USER>\\.bubblewrap\\android_sdk"
}
```

変更後:

```powershell
cd twa
.\gradlew.bat --stop
npx @bubblewrap/cli build
```

### 2. Bubblewrap でプロジェクト生成

```powershell
cd twa
npx @bubblewrap/cli init --manifest=https://sa2-plus.com/manifest.webmanifest
```

対話で聞かれる主な項目（推奨値）:

| 項目 | 推奨 |
|---|---|
| Domain | `sa2-plus.com`（https なし） |
| Application name | `Sa2 Plus` |
| Launcher name | `Sa2 Plus` |
| Package ID | `com.sa2_plus.twa` |
| Start URL | `/` |
| Display mode | `standalone` |
| Theme / Background | `#1A1F24` |
| Icon | manifest の 512 を利用 |
| Signing key | 新規作成で可（パスワードを必ず控える） |

既に `twa-manifest.json` がある場合は、内容を確認してから:

```powershell
npx @bubblewrap/cli update
npx @bubblewrap/cli build
```

### 3. Digital Asset Links（必須）

ビルド後、署名指紋を取得:

```powershell
npx @bubblewrap/cli fingerprint generateAssetLinks
```

または keytool:

```powershell
keytool -list -v -keystore android.keystore -alias android
```

表示された **SHA-256** を `public/.well-known/assetlinks.json` の

`REPLACE_WITH_UPLOAD_KEY_SHA256`

に置き換えて本番へデプロイ。

確認 URL:

`https://sa2-plus.com/.well-known/assetlinks.json`

### 4. 実機インストール

- 生成された `app-release-signed.apk` を Android 実機へサイドロード
- アドレスバーが出る場合は assetlinks の指紋不一致（上記 3 を再確認）
- バーが出ずフルスクリーンなら TWA 成功

### 5. Google Play（後工程）

- Play Console でアプリ作成
- AAB（`app-release-bundle.aab`）を内部テストへアップロード
- Play App Signing を使う場合は、**Play のアプリ署名鍵の SHA-256 も** assetlinks に追加

## リポジトリに含めないもの

- `android.keystore` / パスワード
- `app-release-*.apk` / `*.aab`（大きいバイナリ）
- ローカルの Android SDK / JDK パス設定（`~/.bubblewrap/config.json`）

## Web 側ファイル

| パス | 役割 |
|---|---|
| `public/manifest.webmanifest` | PWA / TWA の元 |
| `public/.well-known/assetlinks.json` | サイト↔アプリの紐付け |
| `twa/twa-manifest.json` | Bubblewrap 設定のたたき台 |

## 通話の着信通知（Web Push / Firebase）

Sa2 Plus は **Web Push を本線**にします。Android TWA は `enableNotifications: true` により Chrome の通知委任を利用するため、Web版と同じ購読情報でアプリ未起動時にも着信を表示できます。Firebase Cloud Messaging をネイティブSDKで二重実装する必要はありません。

1. Firebase Console で Web Push 証明書（VAPID）を生成する
2. 本番 `.env` に次を設定する（秘密鍵はコミットしない）

```dotenv
WEB_PUSH_VAPID_SUBJECT=mailto:admin@example.com
WEB_PUSH_VAPID_PUBLIC_KEY=
WEB_PUSH_VAPID_PRIVATE_KEY=
```

3. 本番を反映後、アプリの **設定 → 通知設定 → 通話の着信通知を登録** で端末ごとに許可する
4. TWA を再ビルド・再配布する

```powershell
cd twa
npx @bubblewrap/cli update
npx @bubblewrap/cli build
```

通知をタップすると該当DMを開き、90秒間有効な着信中であれば応答できます。端末の通知権限や省電力設定によっては通知が遅延・抑止される場合があります。
