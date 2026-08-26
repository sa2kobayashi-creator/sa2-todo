<?php

namespace Tests\Unit;

use App\Services\Transit\Raptor\ItineraryScorer;
use App\Services\Transit\TransitOperatorCatalog;
use Tests\TestCase;

class TransitOperatorPreferenceTest extends TestCase
{
    public function test_nishitetsu_bus_matches_bus_but_not_rail_or_tokyo_metro(): void
    {
        $catalog = new TransitOperatorCatalog;
        $nishitetsu = $catalog->find('nishitetsu_bus');
        $metro = $catalog->find('tokyo_metro');
        $subway = $catalog->find('fukuoka_subway');

        $this->assertNotNull($nishitetsu);
        $this->assertNotNull($metro);
        $this->assertNotNull($subway);

        $westBus = [
            'summary' => '西鉄バス 天神 → 博多',
            'legs' => [['type' => 'ride', 'routeName' => '西鉄バス 1番', 'agency' => 'nishitetsu_bus']],
        ];
        $rail = [
            'summary' => '西鉄天神大牟田線',
            'legs' => [['type' => 'ride', 'routeName' => '西鉄天神大牟田線', 'agency' => 'nishitetsu_rail']],
        ];
        $tokyo = [
            'summary' => '東京メトロ東西線',
            'legs' => [['type' => 'ride', 'routeName' => '東京メトロ東西線']],
        ];
        $airportSubway = [
            'summary' => '地下鉄空港線',
            'legs' => [['type' => 'ride', 'routeName' => '地下鉄空港線', 'agency' => 'subway']],
        ];

        $this->assertTrue($catalog->itineraryMatches($westBus, $nishitetsu));
        $this->assertFalse($catalog->itineraryMatches($rail, $nishitetsu));
        $this->assertFalse($catalog->itineraryMatches($tokyo, $nishitetsu));
        $this->assertTrue($catalog->itineraryMatches($tokyo, $metro));
        $this->assertFalse($catalog->itineraryMatches($westBus, $metro));
        $this->assertTrue($catalog->itineraryMatches($airportSubway, $subway));
        $this->assertFalse($catalog->itineraryMatches($westBus, $subway));
    }

    public function test_preferred_operator_is_ranked_above_a_slightly_faster_alternative(): void
    {
        $catalog = new TransitOperatorCatalog;
        $scorer = new ItineraryScorer;
        $marked = $catalog->markItineraries([
            'ok' => true,
            'itineraries' => [
                [
                    'summary' => 'JR東日本',
                    'durationSec' => 1800,
                    'waitSec' => 0,
                    'transfers' => 0,
                    'fare' => 200,
                    'legs' => [['routeName' => 'JR山手線']],
                ],
                [
                    'summary' => '東京メトロ東西線',
                    'durationSec' => 2100,
                    'waitSec' => 0,
                    'transfers' => 0,
                    'fare' => 180,
                    'legs' => [['routeName' => '東京メトロ東西線']],
                ],
            ],
        ], 'tokyo_metro');

        $ranked = $scorer->rank($marked['itineraries'], ItineraryScorer::PREF_FASTEST, true);

        $this->assertTrue($ranked[0]['usesPreferredOperator']);
        $this->assertSame('東京メトロ東西線', $ranked[0]['summary']);
        $this->assertSame('東京メトロ', $ranked[0]['preferredOperatorName']);
    }

    public function test_empty_preference_does_not_mark_or_boost_any_operator(): void
    {
        $catalog = new TransitOperatorCatalog;
        $scorer = new ItineraryScorer;
        $marked = $catalog->markItineraries([
            'ok' => true,
            'itineraries' => [[
                'summary' => '西鉄バス',
                'durationSec' => 1200,
                'waitSec' => 0,
                'transfers' => 0,
                'fare' => 230,
                'legs' => [['routeName' => '西鉄バス', 'agency' => 'nishitetsu_bus']],
            ]],
        ], '');

        $this->assertSame('', $marked['preferredOperator']);
        $this->assertFalse($marked['itineraries'][0]['usesPreferredOperator']);

        $plain = $scorer->rank($marked['itineraries'], ItineraryScorer::PREF_FASTEST, true);
        $this->assertSame(
            $scorer->score($marked['itineraries'][0], ItineraryScorer::PREF_FASTEST, false),
            $plain[0]['score']
        );
    }
}
