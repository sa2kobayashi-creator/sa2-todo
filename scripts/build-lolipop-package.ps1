# Deploy checklist helpers for Lolipop (sa2-plus.com)
# Does not upload secrets. Run from repo root.

$ErrorActionPreference = "Stop"
$root = Split-Path -Parent $PSScriptRoot
if (-not (Test-Path (Join-Path $root "artisan"))) {
  $root = (Get-Location).Path
}

$stamp = Get-Date -Format "yyyyMMdd-HHmmss"
$outDir = Join-Path $root "dist"
$stage = Join-Path $outDir "sa2todo-$stamp"
$zipPath = Join-Path $outDir "sa2todo-$stamp.zip"

New-Item -ItemType Directory -Force -Path $stage | Out-Null

$include = @(
  "app",
  "bootstrap",
  "config",
  "database",
  "lang",
  "public",
  "resources",
  "routes",
  "storage",
  "artisan",
  "composer.json",
  "composer.lock",
  ".env.production.example",
  ".env.sa2-plus.local.example"
)

foreach ($item in $include) {
  $src = Join-Path $root $item
  if (-not (Test-Path $src)) { continue }
  $dest = Join-Path $stage $item
  if (Test-Path $src -PathType Container) {
    Copy-Item -Path $src -Destination $dest -Recurse -Force
  } else {
    Copy-Item -Path $src -Destination $dest -Force
  }
}

# Keep storage structure, drop local logs/cache/uploads
$storageKeep = @(
  "storage\app\.gitignore",
  "storage\app\public\.gitignore",
  "storage\framework\cache\.gitignore",
  "storage\framework\sessions\.gitignore",
  "storage\framework\views\.gitignore",
  "storage\logs\.gitignore"
)
Remove-Item -Recurse -Force (Join-Path $stage "storage") -ErrorAction SilentlyContinue
New-Item -ItemType Directory -Force -Path (Join-Path $stage "storage\app\public") | Out-Null
New-Item -ItemType Directory -Force -Path (Join-Path $stage "storage\framework\cache") | Out-Null
New-Item -ItemType Directory -Force -Path (Join-Path $stage "storage\framework\sessions") | Out-Null
New-Item -ItemType Directory -Force -Path (Join-Path $stage "storage\framework\views") | Out-Null
New-Item -ItemType Directory -Force -Path (Join-Path $stage "storage\logs") | Out-Null
foreach ($rel in $storageKeep) {
  $from = Join-Path $root $rel
  if (Test-Path $from) {
    Copy-Item $from (Join-Path $stage $rel) -Force
  }
}

# Exclude local junk from public
Get-ChildItem (Join-Path $stage "public") -Recurse -File -ErrorAction SilentlyContinue |
  Where-Object { $_.Name -match '\.(mp3|log)$' } |
  Remove-Item -Force -ErrorAction SilentlyContinue

if (Test-Path (Join-Path $root "vendor")) {
  Write-Host "Skipping vendor (install on server with: composer install --no-dev --optimize-autoloader)"
}

Compress-Archive -Path (Join-Path $stage "*") -DestinationPath $zipPath -Force
Write-Host "Created: $zipPath"
Write-Host "Staging: $stage"
Write-Host "Size:" ((Get-Item $zipPath).Length / 1MB).ToString("0.0") "MB"
