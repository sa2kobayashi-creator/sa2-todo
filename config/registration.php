<?php

return [
    /*
    | 新規登録の招待コード（フォールバック）。
    | 管理者が /admin/users で保存した値が優先されます。
    | DB 未設定のときだけ、この env が使われます。空なら登録閉鎖。
    */
    'invite_code' => trim((string) env('REGISTRATION_INVITE_CODE', '')),
];
