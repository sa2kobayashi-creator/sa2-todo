<?php

return [
    /** 1ファイルあたりの上限（バイト）。初期値 20MB */
    'max_attachment_bytes' => (int) env('MESSAGE_MAX_ATTACHMENT_BYTES', 20 * 1024 * 1024),

    /** 1メッセージあたりの添付上限 */
    'max_attachments_per_message' => (int) env('MESSAGE_MAX_ATTACHMENTS', 5),

    /**
     * 添付の保存ディスク。空なら photos.disk を使う。
     */
    'attachment_disk' => env('MESSAGE_ATTACHMENT_DISK', ''),

    'allowed_extensions' => [
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'heic', 'heif',
        'pdf', 'txt', 'csv', 'doc', 'docx', 'xls', 'xlsx',
        'mp4', 'mov', 'mp3', 'm4a', 'zip',
    ],
];
