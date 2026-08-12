# Sa2 Plus TWA 初期セットアップ補助（Windows）
# 使い方: powershell -ExecutionPolicy Bypass -File scripts/setup-twa.ps1

$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
$twaDir = Join-Path $root 'twa'

Write-Host '== Sa2 Plus TWA setup ==' -ForegroundColor Cyan

# JDK
$java = Get-Command java -ErrorAction SilentlyContinue
if (-not $java) {
  Write-Host 'JDK が見つかりません。OpenJDK 17 のインストールを試みます...' -ForegroundColor Yellow
  winget install -e --id Microsoft.OpenJDK.17 --accept-package-agreements --accept-source-agreements
  Write-Host 'インストール後、ターミナルを開き直してから再実行してください。' -ForegroundColor Yellow
  exit 1
}

Write-Host ("Java: " + (java -version 2>&1 | Select-Object -First 1))
Write-Host "Manifest: https://sa2-plus.com/manifest.webmanifest"
Write-Host "Package:  com.sa2_plus.twa"
Write-Host "Workdir:  $twaDir"
Write-Host ''
Write-Host '次を対話実行します（Bubblewrap の質問に答えてください）:' -ForegroundColor Cyan
Write-Host '  npx @bubblewrap/cli init --manifest=https://sa2-plus.com/manifest.webmanifest'
Write-Host ''

Set-Location $twaDir
npx --yes @bubblewrap/cli init --manifest=https://sa2-plus.com/manifest.webmanifest

Write-Host ''
Write-Host '完了後の流れ:' -ForegroundColor Green
Write-Host '  1) npx @bubblewrap/cli build'
Write-Host '  2) npx @bubblewrap/cli fingerprint generateAssetLinks'
Write-Host '  3) SHA-256 を public/.well-known/assetlinks.json に反映して本番デプロイ'
Write-Host '  4) app-release-signed.apk を実機へインストール'
