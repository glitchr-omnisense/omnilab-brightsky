<?php

namespace Omnilab\BrightSky;

use Omnilab\Exception\NotSupportedException;
use Omnilab\Model\Domain;
use Omnilab\Model\Entity;
use Omnilab\Model\Identifier;
use Omnilab\Model\Point;
use Omnilab\Model\Property;
use Omnilab\Model\Quantity;
use Omnilab\Model\Scheme;
use Omnilab\Model\Series;
use Omnilab\Source\Capability;
use Omnilab\Source\HttpSource;
use Omnilab\Source\SeriesQuery;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The Deutscher Wetterdienst's open data through Bright Sky
 * (api.brightsky.dev): hourly weather at a point or at a DWD station -
 * observations back to 2010 (the station records, today's reports) joined
 * to the MOSMIX forecasts ten days ahead - temperature, dew point,
 * humidity, precipitation, pressure, wind and gusts, cloud cover,
 * visibility, sunshine, solar irradiation, the probability of
 * precipitation; and a station as an entity.
 *
 * No key. Observations are dense in Germany; the forecasts cover the
 * world, sparsely outside it.
 */
final class BrightSkySource extends HttpSource
{
    public const BASE_URI = 'https://api.brightsky.dev/';

    /** The variables series() serves, with their unit (Bright Sky's "dwd" units). */
    public const VARIABLES = [
        'temperature' => '°C',
        'dew_point' => '°C',
        'relative_humidity' => '%',
        'precipitation' => 'mm',
        'precipitation_probability' => '%',
        'pressure_msl' => 'hPa',
        'wind_speed' => 'km/h',
        'wind_direction' => '°',
        'wind_gust_speed' => 'km/h',
        'wind_gust_direction' => '°',
        'cloud_cover' => '%',
        'visibility' => 'm',
        'sunshine' => 'min',
        'solar' => 'kWh/m²',
    ];

    /**
     * @param int|null $maxDistance how far from a Point a record may come, in metres (null: Bright Sky's default)
     */
    public function __construct(
        HttpClientInterface $http,
        private readonly ?int $maxDistance = null,
        string $baseUri = self::BASE_URI,
        array $headers = [],
        float $throttle = 0.0,
    ) {
        parent::__construct($http, $baseUri, $headers, $throttle);
    }

    public function getName(): string
    {
        return 'brightsky';
    }

    public function capabilities(): array
    {
        return [Capability::ENTITY, Capability::SERIES, Capability::FORECAST, Capability::HISTORICAL, Capability::STATION];
    }

    /**
     * A DWD station (dwd:01420) or a WMO station (wmo:10637): its name,
     * place and height, the period of its records and the kinds of records
     * Bright Sky has of it (historical, current, synop, forecast). One call;
     * null when unknown.
     */
    public function entity(Identifier|string $id): ?Entity
    {
        $station = self::identify($id, Scheme::DWD_STATION, Scheme::WIGOS) ?? throw NotSupportedException::identifier('brightsky', (string) $id);
        $sources = array_values(array_filter($this->getJson('sources', $this->stationParameters($station))['sources'] ?? [], 'is_array'));
        if (!$sources) {
            return null;
        }
        // The historical record first: the station's measured place.
        usort($sources, static fn (array $a, array $b) => [('historical' === ($a['observation_type'] ?? null)) ? 0 : 1, -strtotime((string) ($a['last_record'] ?? ''))] <=> [('historical' === ($b['observation_type'] ?? null)) ? 0 : 1, -strtotime((string) ($b['last_record'] ?? ''))]);
        $main = $sources[0];
        $first = min(array_map(static fn (array $s) => (string) ($s['first_record'] ?? '9999'), $sources));
        $last = max(array_map(static fn (array $s) => (string) ($s['last_record'] ?? ''), $sources));
        $types = array_values(array_unique(array_filter(array_map(static fn (array $s) => self::text($s['observation_type'] ?? null), $sources))));
        $properties = [];
        $add = static function (string $key, string $label, mixed $value) use (&$properties): void {
            if (null !== $value && '' !== $value) {
                $properties[] = new Property($key, $label, $value, 'brightsky');
            }
        };
        $add('height', 'Height', is_numeric($main['height'] ?? null) ? new Quantity(0 + $main['height'], 'm') : null);
        $add('dwd_station', 'DWD station id', self::text($main['dwd_station_id'] ?? null));
        $add('wmo_station', 'WMO station id', self::text($main['wmo_station_id'] ?? null));
        $add('period_start', 'First record', null === ($t = self::time($first)) ? null : $t->format(\DATE_ATOM));
        $add('period_end', 'Last record', null === ($t = self::time($last)) ? null : $t->format(\DATE_ATOM));
        foreach ($types as $type) {
            $add('record_type', 'Kind of records', $type);
        }
        $identifiers = array_values(array_filter([
            Identifier::tryOf(Scheme::DWD_STATION, (string) ($main['dwd_station_id'] ?? '')),
            Identifier::tryOf(Scheme::WIGOS, (string) ($main['wmo_station_id'] ?? '')),
        ]));

        return new Entity(
            id: $station,
            label: self::text($main['station_name'] ?? null) ?? $station->value,
            domain: Domain::EARTH,
            kind: 'station',
            properties: $properties,
            identifiers: new \Omnilab\Model\Identifiers($identifiers),
            url: $station->url(),
            place: is_numeric($main['lat'] ?? null) && is_numeric($main['lon'] ?? null) ? new Point((float) $main['lat'], (float) $main['lon'], is_numeric($main['height'] ?? null) ? (float) $main['height'] : null) : null,
            source: 'brightsky',
        );
    }

    /**
     * The hourly weather at a Point (Bright Sky picks the nearest records
     * within max_dist, a station's observations then a forecast) or at a
     * station (dwd.station, or wigos for a WMO index), one series per
     * variable asked, in one call: hourly as given, or the days made from
     * the hours (SeriesQuery::DAILY with Series::MEAN, MAX, MIN or SUM) in the
     * query's time zone (UTC by default). The period defaults to today; a
     * date without a time is the whole day. A series is a forecast when
     * every hour comes from MOSMIX, observed when none does, null when mixed.
     */
    public function series(SeriesQuery $query): array
    {
        foreach ($query->variables as $variable) {
            if (!\array_key_exists($variable, self::VARIABLES)) {
                throw NotSupportedException::operation('brightsky', \sprintf('serve "%s" (it serves %s)', $variable, implode(', ', array_keys(self::VARIABLES))));
            }
        }
        $daily = SeriesQuery::DAILY === $query->step;
        if (!$daily && SeriesQuery::HOURLY !== $query->step) {
            throw NotSupportedException::operation('brightsky', \sprintf('serve a step of %s (hourly, or days made from the hours)', $query->step));
        }
        if ($daily && !\in_array($query->aggregation, [Series::MEAN, Series::MAX, Series::MIN, Series::SUM], true)) {
            throw NotSupportedException::operation('brightsky', 'make days without an aggregation (Series::MEAN, MAX, MIN or SUM)');
        }
        if (!$daily && null !== $query->aggregation) {
            throw NotSupportedException::operation('brightsky', 'aggregate hours (each hour is one record)');
        }
        $station = $this->station($query, Scheme::DWD_STATION, Scheme::WIGOS);
        if (null === $station && !$query->place instanceof Point) {
            throw NotSupportedException::operation('brightsky', 'serve a series over an area (a Point, or a station)');
        }
        if (null !== $station && Scheme::WIGOS === $station->scheme && !preg_match('~^0-20000-0-(\d{5})$~', $station->value)) {
            throw NotSupportedException::operation('brightsky', \sprintf('serve a WIGOS station that has no WMO index (%s)', $station->value));
        }
        $timezone = new \DateTimeZone($query->timezone ?? 'UTC');
        [$from, $to] = self::period($query->from, $query->to, $timezone);
        $data = $this->getJson('weather', [
            'date' => $from->format(\DATE_ATOM),
            'last_date' => $to->modify('-1 second')->format(\DATE_ATOM),
            'tz' => 'UTC',
        ] + (null === $station ? ['lat' => $query->place->latitude, 'lon' => $query->place->longitude, 'max_dist' => $this->maxDistance] : $this->stationParameters($station))) ?? [];

        $forecastSources = [];
        $places = [];
        foreach ($data['sources'] ?? [] as $source) {
            if (\is_array($source) && isset($source['id'])) {
                if ('forecast' === ($source['observation_type'] ?? null)) {
                    $forecastSources[(int) $source['id']] = true;
                }
                if (is_numeric($source['lat'] ?? null) && is_numeric($source['lon'] ?? null)) {
                    $places[(int) $source['id']] = [new Point((float) $source['lat'], (float) $source['lon'], is_numeric($source['height'] ?? null) ? (float) $source['height'] : null), self::text($source['station_name'] ?? null)];
                }
            }
        }
        $records = [];
        $kinds = [];
        $counts = [];
        foreach ($data['weather'] ?? [] as $record) {
            $time = \is_array($record) ? self::time($record['timestamp'] ?? null) : null;
            if (null === $time || $time < $from || $time >= $to) {
                continue;
            }
            $records[] = [$time->setTimezone($timezone), $record];
            $sourceId = (int) ($record['source_id'] ?? 0);
            $kinds[isset($forecastSources[$sourceId]) ? 'forecast' : 'observed'] = true;
            $counts[$sourceId] = ($counts[$sourceId] ?? 0) + 1;
        }
        usort($records, static fn (array $a, array $b) => $a[0] <=> $b[0]);
        $forecast = 1 === \count($kinds) ? isset($kinds['forecast']) : null;
        arsort($counts);
        $main = $places[array_key_first($counts) ?? -1] ?? null;
        $place = null === $station ? $query->place : ($main[0] ?? null);
        if (null === $place) {
            return [];
        }
        $label = null === $main ? null : ($main[1] ?? null);

        $series = [];
        foreach ($query->variables as $variable) {
            $points = array_map(static fn (array $r) => [$r[0], is_numeric($r[1][$variable] ?? null) ? 0 + $r[1][$variable] : null], $records);
            $series[] = $daily
                ? new Series($variable, self::VARIABLES[$variable], $place, self::days($points, $from, $to, $query->aggregation), SeriesQuery::DAILY, $query->aggregation, $label, $forecast, 'brightsky')
                : new Series($variable, self::VARIABLES[$variable], $place, $points, SeriesQuery::HOURLY, null, $label, $forecast, 'brightsky');
        }

        return $series;
    }

    /** @return array<string, string> */
    private function stationParameters(Identifier $station): array
    {
        return Scheme::DWD_STATION === $station->scheme
            ? ['dwd_station_id' => $station->value]
            : ['wmo_station_id' => preg_match('~^0-20000-0-(\d{5})$~', $station->value, $m) ? $m[1] : $station->value];
    }

    /**
     * The days of the period, each the aggregate of its hours.
     *
     * @param list<array{\DateTimeImmutable, int|float|null}> $points
     *
     * @return list<array{\DateTimeImmutable, int|float|null}>
     */
    private static function days(array $points, \DateTimeImmutable $from, \DateTimeImmutable $to, string $aggregation): array
    {
        $days = [];
        for ($day = $from->setTime(0, 0); $day < $to; $day = $day->modify('+1 day')) {
            $end = $day->modify('+1 day');
            $values = [];
            foreach ($points as [$time, $value]) {
                if ($time >= $day && $time < $end && null !== $value) {
                    $values[] = $value;
                }
            }
            $days[] = [$day, $values ? match ($aggregation) {
                Series::MAX => max($values),
                Series::MIN => min($values),
                Series::MEAN => round(array_sum($values) / \count($values), 2),
                Series::SUM => round(array_sum($values), 2),
            } : null];
        }

        return $days;
    }

    /**
     * The period as two instants: a date without a time stands for the whole
     * day; no start is today, no end the start's day.
     *
     * @return array{\DateTimeImmutable, \DateTimeImmutable}
     */
    private static function period(?\DateTimeInterface $from, ?\DateTimeInterface $to, \DateTimeZone $timezone): array
    {
        $local = static fn (\DateTimeInterface $t) => new \DateTimeImmutable($t->format('Y-m-d H:i:s'), $timezone);
        $start = null === $from ? (null === $to ? new \DateTimeImmutable('today', $timezone) : $local($to)->setTime(0, 0)) : $local($from);
        $end = null === $to ? $start->setTime(0, 0)->modify('+1 day') : $local($to);
        if (null !== $to && '00:00:00' === $end->format('H:i:s')) {
            $end = $end->modify('+1 day');
        }

        return [$start, $end];
    }
}
