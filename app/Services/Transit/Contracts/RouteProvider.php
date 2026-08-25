<?php

namespace App\Services\Transit\Contracts;

/**
 * 経路検索の提供元。NAVITIME・駅すぱあと・内蔵 RAPTOR などをここで揃える。
 *
 * search() は画面と共通の itinerary 形式で返す:
 * ['ok' => bool, 'engine' => string, 'itineraries' => [
 *   ['departureTime','arrivalTime','durationLabel','waitLabel','transfers','fareLabel','legs'[], ...],
 * ]]
 */
interface RouteProvider
{
    /** 設定や ROUTE_PROVIDER で指定するキー（navitime / raptor など） */
    public function key(): string;

    /** 画面に出す名前 */
    public function label(): string;

    /** 契約情報が入っていて使える状態か */
    public function isReady(): bool;

    /**
     * @param  array<string, mixed>  $query
     * @return array{ok: bool, engine?: string, message?: string, itineraries: list<array<string, mixed>>}
     */
    public function search(array $query): array;
}
