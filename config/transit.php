<?php

return [

    /*
    |--------------------------------------------------------------------------
    | 経路検索に使う API
    |--------------------------------------------------------------------------
    |
    | auto     … 使えるものを上から順に（NAVITIME → 内蔵 RAPTOR）
    | navitime … NAVITIME API（設定 → API設定 で契約情報を登録）
    | raptor   … アプリ内蔵の経路探索（福岡都心の簡易ダイヤ・契約不要）
    |
    | 設定画面で選んだ値が優先され、未選択のときだけここが使われる。
    | Google は日本の交通機関ルートが API 提供対象外のため候補に入れていない
    | （Google Maps Platform FAQ: transit partners "except ... those in Japan"）。
    | 画面の「Google Maps でルート」は外部リンクとして残している。
    |
    */

    'provider' => env('ROUTE_PROVIDER', 'auto'),

];
