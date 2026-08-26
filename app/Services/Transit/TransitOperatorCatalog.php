<?php

namespace App\Services\Transit;

/**
 * 全国の主要交通機関。地域優先の選択と、経路へのキーワード照合に使う。
 */
class TransitOperatorCatalog
{
    /**
     * @return list<array{id: string, name: string, region: string, area: string, keywords: list<string>, raptorAgency?: string}>
     */
    public function all(): array
    {
        return [
            ['id' => 'jr_hokkaido', 'name' => 'JR北海道', 'region' => '北海道', 'area' => '北海道', 'keywords' => ['JR北海道', '函館本線', '千歳線', '札沼線']],
            ['id' => 'sapporo_subway', 'name' => '札幌市営地下鉄', 'region' => '北海道', 'area' => '札幌', 'keywords' => ['札幌市営地下鉄', '南北線', '東西線', '東豊線']],
            ['id' => 'sapporo_streetcar', 'name' => '札幌市電', 'region' => '北海道', 'area' => '札幌', 'keywords' => ['札幌市電', '市電']],
            ['id' => 'chuo_bus', 'name' => '北海道中央バス', 'region' => '北海道', 'area' => '札幌', 'keywords' => ['北海道中央バス', '中央バス']],

            ['id' => 'jr_east', 'name' => 'JR東日本', 'region' => '関東', 'area' => '東日本', 'keywords' => ['JR東日本', '山手線', '中央線', '京浜東北', '総武', '埼京', '常磐', '湘南新宿', '上野東京']],
            ['id' => 'tokyo_metro', 'name' => '東京メトロ', 'region' => '関東', 'area' => '東京', 'keywords' => ['東京メトロ', '銀座線', '丸ノ内線', '日比谷線', '東西線', '千代田線', '有楽町線', '半蔵門線', '南北線', '副都心線']],
            ['id' => 'toei_subway', 'name' => '都営地下鉄', 'region' => '関東', 'area' => '東京', 'keywords' => ['都営', '浅草線', '三田線', '新宿線', '大江戸線']],
            ['id' => 'toei_bus', 'name' => '都営バス', 'region' => '関東', 'area' => '東京', 'keywords' => ['都営バス']],
            ['id' => 'tokyu', 'name' => '東急電鉄', 'region' => '関東', 'area' => '東京', 'keywords' => ['東急', '田園都市線', '東横線', '目黒線', '大井町線', '池上線', '多摩川線']],
            ['id' => 'keio', 'name' => '京王電鉄', 'region' => '関東', 'area' => '東京', 'keywords' => ['京王', '井の頭線']],
            ['id' => 'odakyu', 'name' => '小田急電鉄', 'region' => '関東', 'area' => '東京', 'keywords' => ['小田急']],
            ['id' => 'seibu', 'name' => '西武鉄道', 'region' => '関東', 'area' => '東京', 'keywords' => ['西武']],
            ['id' => 'tobu', 'name' => '東武鉄道', 'region' => '関東', 'area' => '東京', 'keywords' => ['東武', 'スカイツリーライン', '伊勢崎線', '日光線']],
            ['id' => 'keisei', 'name' => '京成電鉄', 'region' => '関東', 'area' => '千葉', 'keywords' => ['京成', '成田スカイアクセス', 'スカイライナー']],
            ['id' => 'keikyu', 'name' => '京急電鉄', 'region' => '関東', 'area' => '神奈川', 'keywords' => ['京急', '京浜急行']],
            ['id' => 'sotetsu', 'name' => '相模鉄道', 'region' => '関東', 'area' => '神奈川', 'keywords' => ['相鉄', '相模鉄道']],
            ['id' => 'yokohama_subway', 'name' => '横浜市営地下鉄', 'region' => '関東', 'area' => '横浜', 'keywords' => ['横浜市営地下鉄', 'ブルーライン', 'グリーンライン']],
            ['id' => 'yokohama_bus', 'name' => '横浜市営バス', 'region' => '関東', 'area' => '横浜', 'keywords' => ['横浜市営バス']],
            ['id' => 'tx', 'name' => 'つくばエクスプレス', 'region' => '関東', 'area' => '茨城', 'keywords' => ['つくばエクスプレス', 'TX']],
            ['id' => 'yurikamome', 'name' => 'ゆりかもめ', 'region' => '関東', 'area' => '東京', 'keywords' => ['ゆりかもめ']],
            ['id' => 'rinkai', 'name' => '東京臨海高速鉄道', 'region' => '関東', 'area' => '東京', 'keywords' => ['りんかい線', '臨海']],
            ['id' => 'tokyo_monorail', 'name' => '東京モノレール', 'region' => '関東', 'area' => '東京', 'keywords' => ['東京モノレール']],
            ['id' => 'sendai_subway', 'name' => '仙台市地下鉄', 'region' => '東北', 'area' => '仙台', 'keywords' => ['仙台市地下鉄', '南北線', '東西線']],

            ['id' => 'jr_central', 'name' => 'JR東海', 'region' => '中部', 'area' => '東海', 'keywords' => ['JR東海', '東海道本線', '中央本線', '新幹線のぞみ', 'ひかり', 'こだま']],
            ['id' => 'meitetsu', 'name' => '名古屋鉄道', 'region' => '中部', 'area' => '名古屋', 'keywords' => ['名鉄', '名古屋鉄道']],
            ['id' => 'nagoya_subway', 'name' => '名古屋市営地下鉄', 'region' => '中部', 'area' => '名古屋', 'keywords' => ['名古屋市営地下鉄', '東山線', '名城線', '鶴舞線', '桜通線']],
            ['id' => 'nagoya_bus', 'name' => '名古屋市営バス', 'region' => '中部', 'area' => '名古屋', 'keywords' => ['名古屋市営バス']],
            ['id' => 'shizutetsu', 'name' => '静岡鉄道', 'region' => '中部', 'area' => '静岡', 'keywords' => ['静岡鉄道', '静鉄']],

            ['id' => 'jr_west', 'name' => 'JR西日本', 'region' => '近畿', 'area' => '西日本', 'keywords' => ['JR西日本', '大阪環状', '京都線', '神戸線', '学研都市', 'JR東西']],
            ['id' => 'osaka_metro', 'name' => '大阪メトロ', 'region' => '近畿', 'area' => '大阪', 'keywords' => ['大阪メトロ', '御堂筋線', '谷町線', '四つ橋線', '中央線', '千日前線', '堺筋線']],
            ['id' => 'osaka_city_bus', 'name' => '大阪シティバス', 'region' => '近畿', 'area' => '大阪', 'keywords' => ['大阪シティバス', '市バス']],
            ['id' => 'hankyu', 'name' => '阪急電鉄', 'region' => '近畿', 'area' => '大阪', 'keywords' => ['阪急']],
            ['id' => 'hanshin', 'name' => '阪神電鉄', 'region' => '近畿', 'area' => '大阪', 'keywords' => ['阪神']],
            ['id' => 'keihan', 'name' => '京阪電鉄', 'region' => '近畿', 'area' => '京都', 'keywords' => ['京阪']],
            ['id' => 'nankai', 'name' => '南海電鉄', 'region' => '近畿', 'area' => '大阪', 'keywords' => ['南海', 'ラピート']],
            ['id' => 'kintetsu', 'name' => '近畿日本鉄道', 'region' => '近畿', 'area' => '大阪', 'keywords' => ['近鉄', '近畿日本鉄道']],
            ['id' => 'kyoto_subway', 'name' => '京都市営地下鉄', 'region' => '近畿', 'area' => '京都', 'keywords' => ['京都市営地下鉄', '烏丸線', '東西線']],
            ['id' => 'kyoto_bus', 'name' => '京都市バス', 'region' => '近畿', 'area' => '京都', 'keywords' => ['京都市バス']],
            ['id' => 'kobe_subway', 'name' => '神戸市営地下鉄', 'region' => '近畿', 'area' => '神戸', 'keywords' => ['神戸市営地下鉄', '西神', '山手線', '海岸線']],

            ['id' => 'hiroden', 'name' => '広島電鉄', 'region' => '中国', 'area' => '広島', 'keywords' => ['広島電鉄', '広電']],
            ['id' => 'okayama_tram', 'name' => '岡山電気軌道', 'region' => '中国', 'area' => '岡山', 'keywords' => ['岡山電気軌道', '岡電']],

            ['id' => 'jr_shikoku', 'name' => 'JR四国', 'region' => '四国', 'area' => '四国', 'keywords' => ['JR四国', '予讃', '土讃', '高徳']],
            ['id' => 'kotoden', 'name' => '高松琴平電気鉄道', 'region' => '四国', 'area' => '香川', 'keywords' => ['ことでん', '高松琴平']],
            ['id' => 'iyotetsu', 'name' => '伊予鉄道', 'region' => '四国', 'area' => '愛媛', 'keywords' => ['伊予鉄', '伊予鉄道']],

            ['id' => 'jr_kyushu', 'name' => 'JR九州', 'region' => '九州', 'area' => '九州', 'keywords' => ['JR九州', '鹿児島本線', '福北ゆたか', '香椎線', '日豊']],
            ['id' => 'nishitetsu_bus', 'name' => '西鉄バス', 'region' => '九州', 'area' => '福岡', 'keywords' => ['西鉄バス', '西鉄'], 'raptorAgency' => 'nishitetsu_bus'],
            ['id' => 'nishitetsu_rail', 'name' => '西鉄電車', 'region' => '九州', 'area' => '福岡', 'keywords' => ['西鉄天神大牟田', '西鉄太宰府', '西鉄貝塚', '西鉄電車']],
            ['id' => 'fukuoka_subway', 'name' => '福岡市地下鉄', 'region' => '九州', 'area' => '福岡', 'keywords' => ['福岡市地下鉄', '空港線', '箱崎線', '七隈線', '地下鉄']],
            ['id' => 'fukuoka_ferry', 'name' => '福岡市営渡船', 'region' => '九州', 'area' => '福岡', 'keywords' => ['渡船', '市営渡船', '志賀島']],
            ['id' => 'kitakyushu_monorail', 'name' => '北九州モノレール', 'region' => '九州', 'area' => '北九州', 'keywords' => ['北九州モノレール']],
            ['id' => 'kumamoto_tram', 'name' => '熊本市電', 'region' => '九州', 'area' => '熊本', 'keywords' => ['熊本市電']],
            ['id' => 'nagasaki_tram', 'name' => '長崎電気軌道', 'region' => '九州', 'area' => '長崎', 'keywords' => ['長崎電気軌道', '長崎市電']],
            ['id' => 'kagoshima_tram', 'name' => '鹿児島市電', 'region' => '九州', 'area' => '鹿児島', 'keywords' => ['鹿児島市電']],

            ['id' => 'yui_rail', 'name' => 'ゆいレール', 'region' => '沖縄', 'area' => '那覇', 'keywords' => ['ゆいレール', '沖縄都市モノレール']],
            ['id' => 'naha_bus', 'name' => '那覇バス', 'region' => '沖縄', 'area' => '那覇', 'keywords' => ['那覇バス']],
            ['id' => 'ryukyu_bus', 'name' => '琉球バス交通', 'region' => '沖縄', 'area' => '沖縄', 'keywords' => ['琉球バス']],
        ];
    }

    /** @return array{id: string, name: string, region: string, area: string, keywords: list<string>, raptorAgency?: string}|null */
    public function find(string $id): ?array
    {
        $id = trim($id);
        if ($id === '') {
            return null;
        }
        foreach ($this->all() as $operator) {
            if ($operator['id'] === $id) {
                return $operator;
            }
        }

        return null;
    }

    /** @param  array<string, mixed>  $itinerary  @param  array<string, mixed>  $operator */
    public function itineraryMatches(array $itinerary, array $operator): bool
    {
        $blob = (string) ($itinerary['summary'] ?? '');
        foreach ($itinerary['legs'] ?? [] as $leg) {
            if (! is_array($leg)) {
                continue;
            }
            $blob .= (string) ($leg['routeName'] ?? '').(string) ($leg['label'] ?? '').(string) ($leg['agency'] ?? '');
        }
        foreach ($operator['keywords'] ?? [] as $keyword) {
            $keyword = trim((string) $keyword);
            if ($keyword !== '' && mb_stripos($blob, $keyword) !== false) {
                return true;
            }
        }
        $agency = (string) ($operator['raptorAgency'] ?? '');
        if ($agency !== '') {
            foreach ($itinerary['legs'] ?? [] as $leg) {
                if (is_array($leg) && ($leg['agency'] ?? '') === $agency) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  array{ok?: bool, itineraries?: list<array<string, mixed>>, ...}  $result
     * @return array<string, mixed>
     */
    public function markItineraries(array $result, string $operatorId): array
    {
        $operator = $this->find($operatorId);
        $itineraries = is_array($result['itineraries'] ?? null) ? $result['itineraries'] : [];
        $operatorName = is_array($operator) ? (string) ($operator['name'] ?? '') : '';
        foreach ($itineraries as &$itinerary) {
            $itinerary['usesPreferredOperator'] = $operator !== null && $this->itineraryMatches($itinerary, $operator);
            $itinerary['preferredOperatorName'] = $operatorName;
        }
        unset($itinerary);
        $result['itineraries'] = $itineraries;
        $result['preferredOperator'] = is_array($operator) ? (string) ($operator['id'] ?? '') : '';
        $result['preferredOperatorName'] = $operatorName;

        return $result;
    }

    /** 画面のセレクト用（地域 → 事業者） */
    public function grouped(): array
    {
        $grouped = [];
        foreach ($this->all() as $operator) {
            $grouped[$operator['region']][] = $operator;
        }

        return $grouped;
    }
}
