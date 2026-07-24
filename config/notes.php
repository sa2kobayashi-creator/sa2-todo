<?php

return [
    /** 1ファイルあたりの上限（バイト）。初期値 20MB */
    'max_attachment_bytes' => (int) env('NOTE_MAX_ATTACHMENT_BYTES', 20 * 1024 * 1024),

    /** 1メモあたりの添付上限 */
    'max_attachments_per_note' => (int) env('NOTE_MAX_ATTACHMENTS_PER_NOTE', 10),

    /**
     * 添付の保存ディスク。空なら photos.disk（パイプライン設定）を使う。
     */
    'attachment_disk' => env('NOTE_ATTACHMENT_DISK', ''),
];
