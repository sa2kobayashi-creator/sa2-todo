#!/bin/sh
# Lolipop cron: R2 の古い原本を Backblaze B2 へアーカイブ
# ファイルパス例:
#   /home/users/2/heavy.jp-sa2/web/sa2todo/bin/photo-bat.sh

cd /home/users/2/heavy.jp-sa2/web/sa2todo || exit 1

/usr/local/php/8.3/bin/php artisan photos:archive-cold --limit=200 >> storage/logs/photo-archive.log 2>&1
