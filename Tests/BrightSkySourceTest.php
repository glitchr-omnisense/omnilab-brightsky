<?php

namespace Omnilab\BrightSky\Tests;

use Omnilab\BrightSky\BrightSkySource;
use Omnilab\BrightSky\BrightSkySourceFactory;
use Omnilab\Exception\NotSupportedException;
use Omnilab\Exception\UnavailableException;
use Omnilab\Model\BBox;
use Omnilab\Model\Domain;
use Omnilab\Model\Identifier;
use Omnilab\Model\Point;
use Omnilab\Model\Quantity;
use Omnilab\Model\Scheme;
use Omnilab\Model\Series;
use Omnilab\Source\Capability;
use Omnilab\Source\SeriesQuery;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Fixtures recorded from api.brightsky.dev on 2026-10-16: Frankfurt/Main's
 * sources (DWD 01420, WMO 10637); its weather on 2026-10-14; Strasbourg's
 * point from 2026-10-08 to 10-14 (in Berlin's days) and on 2026-10-18 (a
 * forecast); an unknown station (404).
 */
final class BrightSkySourceTest extends TestCase
{
    /** @var list<array{string, array<string, string>}> */
    private array $calls = [];

    private function source(?MockResponse $response = null, array $options = []): BrightSkySource
    {
        $http = new MockHttpClient(function (string $method, string $url) use ($response): MockResponse {
            $path = (string) parse_url($url, \PHP_URL_PATH);
            parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);
            $this->calls[] = [$path, $query];
            if ($response) {
                return $response;
            }
            $fixture = match (true) {
                '/sources' === $path => '01420' === ($query['dwd_station_id'] ?? null) ? 'sources-01420.json' : 'sources-unknown.json',
                isset($query['wmo_station_id']) || isset($query['dwd_station_id']) => 'weather-10637-2026-10-14.json',
                str_starts_with($query['date'], '2026-10-18') => 'weather-strasbourg-2026-10-18.json',
                default => 'weather-strasbourg-2026-10-08.json',
            };

            return new MockResponse((string) file_get_contents(__DIR__.'/Fixtures/'.$fixture), ['http_code' => 'sources-unknown.json' === $fixture ? 404 : 200]);
        });
        $source = (new BrightSkySourceFactory($http))->create($options);
        self::assertInstanceOf(BrightSkySource::class, $source);

        return $source;
    }

    public function testAStation(): void
    {
        $frankfurt = $this->source()->entity('dwd:01420');

        self::assertSame(['dwd.station:01420', 'Frankfurt/Main', Domain::EARTH, 'station'], [$frankfurt->id->key(), $frankfurt->label, $frankfurt->domain, $frankfurt->kind]);
        self::assertEquals(new Point(50.0259, 8.5213, 99.7), $frankfurt->place);
        self::assertEquals(new Quantity(99.7, 'm'), $frankfurt->value('height'));
        self::assertSame(['dwd.station:01420', 'wigos:0-20000-0-10637'], $frankfurt->identifiers()->keys());
        self::assertSame('2010-01-01T00:00:00+00:00', $frankfurt->value('period_start'));
        self::assertSame(['historical', 'forecast', 'synop', 'current'], array_map('strval', $frankfurt->properties('record_type')));
        self::assertSame(['dwd_station_id' => '01420'], $this->calls[0][1]);
        self::assertNull($this->source()->entity('dwd:99999'));
    }

    public function testAStationByItsWmoIndex(): void
    {
        $this->source()->entity('wmo:10637');

        self::assertSame(['wmo_station_id' => '10637'], $this->calls[0][1]);
    }

    public function testAnotherBaseIsRefusedBeforeAnyCall(): void
    {
        $this->expectException(NotSupportedException::class);
        try {
            $this->source()->entity('ghcn:GME00102380');
        } finally {
            self::assertSame([], $this->calls);
        }
    }

    public function testCapabilities(): void
    {
        self::assertSame([Capability::ENTITY, Capability::SERIES, Capability::FORECAST, Capability::HISTORICAL, Capability::STATION], $this->source()->capabilities());
    }

    public function testAStationsHoursInOneCall(): void
    {
        [$temperature, $precipitation] = $this->source()->series(new SeriesQuery(['temperature', 'precipitation'], Identifier::of(Scheme::WIGOS, '10637'), new \DateTimeImmutable('2026-10-14'), new \DateTimeImmutable('2026-10-14')));

        self::assertSame(['temperature', '°C', SeriesQuery::HOURLY, false, 'brightsky', 'Frankfurt/Main'], [$temperature->variable, $temperature->unit, $temperature->step, $temperature->forecast, $temperature->source, $temperature->label]);
        self::assertCount(24, $temperature);
        self::assertSame([13.2, 12.9, 12.2], \array_slice($temperature->values(), 0, 3));
        self::assertSame(19.5, $temperature->max());
        self::assertSame(['mm', 0.0], [$precipitation->unit, $precipitation->max()]);
        self::assertEquals(new Point(50.0259, 8.5213, 99.7), $temperature->place);
        self::assertSame(['date' => '2026-10-14T00:00:00+00:00', 'last_date' => '2026-10-14T23:59:59+00:00', 'tz' => 'UTC', 'wmo_station_id' => '10637'], $this->calls[0][1]);
    }

    public function testDaysMadeFromTheHoursInAZone(): void
    {
        [$max] = $this->source()->series(new SeriesQuery(['temperature'], new Point(48.5734, 7.7521), new \DateTimeImmutable('2026-10-08'), new \DateTimeImmutable('2026-10-14'), SeriesQuery::DAILY, Series::MAX, 'Europe/Berlin'));

        self::assertSame([18.1, 14.7, 17.2, 15.2, 18.2, 18.2, 20.6], $max->values());
        self::assertSame('Kehl-Odelshofen', $max->label);
        self::assertSame('2026-10-08T00:00:00+02:00', $max->times()[0]->format(\DATE_ATOM));
        self::assertEquals(new Point(48.5734, 7.7521), $max->place);
        self::assertSame(['48.5734', '7.7521', '2026-10-08T00:00:00+02:00'], [$this->calls[0][1]['lat'], $this->calls[0][1]['lon'], $this->calls[0][1]['date']]);
    }

    public function testAForecast(): void
    {
        [$temperature] = $this->source(null, ['max_distance' => 20000])->series(new SeriesQuery(['temperature'], new Point(48.5734, 7.7521), new \DateTimeImmutable('2026-10-18'), new \DateTimeImmutable('2026-10-18')));

        self::assertTrue($temperature->forecast);
        self::assertSame('KEHL', $temperature->label);
        self::assertSame([8.7, 8.6, 8.4], \array_slice($temperature->values(), 0, 3));
        self::assertSame('20000', $this->calls[0][1]['max_dist']);
    }

    public function testRefusedBeforeAnyCall(): void
    {
        $source = $this->source();
        foreach ([
            new SeriesQuery(['humidity'], new Point(50, 8)),
            new SeriesQuery(['temperature'], new BBox(8, 49, 9, 50)),
            new SeriesQuery(['temperature'], Identifier::of('ghcn', 'GME00102380')),
            new SeriesQuery(['temperature'], new Point(50, 8), step: 'PT15M'),
            new SeriesQuery(['temperature'], new Point(50, 8), step: SeriesQuery::DAILY),
            new SeriesQuery(['temperature'], new Point(50, 8), aggregation: Series::MAX),
            new SeriesQuery(['temperature'], Identifier::of(Scheme::WIGOS, '0-276-2001-A1')),
        ] as $query) {
            try {
                $source->series($query);
                self::fail('Refused.');
            } catch (NotSupportedException) {
            }
        }
        self::assertSame([], $this->calls);
    }

    public function testNoRecordsNearAPoint(): void
    {
        self::assertSame([], $this->source(new MockResponse('{"detail":"No sources match your criteria"}', ['http_code' => 404]))->series(new SeriesQuery(['temperature'], Identifier::of(Scheme::DWD_STATION, '99999'))));
    }

    public function testADownServiceIsUnavailable(): void
    {
        $this->expectException(UnavailableException::class);
        $this->source(new MockResponse('', ['http_code' => 502]))->series(new SeriesQuery(['temperature'], new Point(50, 8)));
    }
}
