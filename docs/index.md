# omnilab/brightsky

The Deutscher Wetterdienst's open data through Bright Sky (`https://api.brightsky.dev/`) for
[omnisense/omnilab](https://github.com/glitchr-omnisense/omnilab): hourly observations since 2010
joined to the MOSMIX forecasts, at a point or a DWD or WMO station, as series; the station itself
as an entity. No key.

## Installation

```sh
composer require omnilab/brightsky
```

```php
use Omnilab\BrightSky\BrightSkySourceFactory;

$sky = (new BrightSkySourceFactory($httpClient))->create([
    'mailto' => 'support@glitchr.io',  // optional
    'max_distance' => null,            // optional: metres from a Point
]);
```

With the Symfony bundle, the factory is registered as `brightsky` when the bundle knows it;
otherwise register `BrightSkySourceFactory` as a service.

## Credentials

None: Bright Sky's public instance is free for all purposes and needs no key. Heavy users can run
their own instance (open source) and name it by `base_uri`.

## Options

The factory's options, with the defaults it applies (`BrightSkySourceFactory`):

| Option | Type | Default | Required | Effect |
|---|---|---|---|---|
| `max_distance` | `?int` | null | no | how far from a `Point` a record may come, in metres, sent as `max_dist`; null: Bright Sky's default |
| `mailto` | `?string` | null | no | a contact address, sent in the User-Agent |
| `user_agent` | `?string` | null | no | the whole User-Agent, in place of the one made from `mailto` |
| `throttle` | `float` | `0` | no | seconds left between two calls: Bright Sky publishes no figure |
| `base_uri` | `string` | `https://api.brightsky.dev/` | no | the instance: the public one, or a self-hosted one |

## Capabilities

`capabilities()` answers `entity`, `series`, `forecast`, `historical`, `station`; an operation it does not answer throws `NotSupportedException` before any call.

| Capability | Supported | |
|---|---|---|
| `entity` | yes | a station: `dwd:01420`, `dwd.station:`, a WMO index (`wmo:10637`) or its WIGOS id; one call (`sources`); another scheme refused before any call |
| `search` | no |  |
| `series` | yes | the fourteen variables of [Calls](#calls) at a `Point` or a station, hourly or days made from the hours with an aggregation; one call for every variable; an unknown station, or a station without records, answers an empty list (no series) |
| `products` | no |  |
| `occurrences` | no |  |
| `datasets` | no |  |
| `jobs` | no |  |
| `open` | no |  |
| `structure` | no |  |
| `cross_references` | no | a station's WIGOS id beside its DWD id: no other base |
| `forecast` | yes | MOSMIX forecasts after the observations (`forecast` true, false, or null when mixed) |
| `historical` | yes | observations since 2010 |
| `area` | no | a box is refused before any call |
| `station` | yes | `dwd.station` and `wigos` (a WIGOS id with a WMO index) |
| `cloud_cover` | no |  |

## Which API

Bright Sky's public instance (`https://api.brightsky.dev/`, version 2.2.11 of its OpenAPI on
2026-10-16), a free open-source gateway to the DWD's open data server: `weather` (hourly records:
`date`, `last_date` - included -, `tz=UTC`, and `lat`/`lon` with `max_dist`, or `dwd_station_id`,
or `wmo_station_id`) and `sources` (a station's sources: one per kind of record). Units are
Bright Sky's default `dwd` ones. An unknown station or a point without records answers 404 ("No
sources match your criteria"): no entity, no series.

## Its shapes

A station is an **entity** of the core's `dwd.station` scheme (`dwd:01420`; a bare five-digit id
is read as a DWD id) or `wigos` (a WMO index, `wmo:10637`); its weather is a **series**
(`Capability::STATION`). A `Point` answers what Bright Sky picks near it (a station's
observations, then a forecast); a box is refused, and so is a WIGOS id without a WMO index.

## Calls

| Method | Reads | Calls |
|---|---|---|
| `entity()` | `dwd:01420`, `dwd.station:01420`, `wmo:10637`, `wigos:0-20000-0-10637` | `sources?dwd_station_id=` or `?wmo_station_id=` |
| `series()` | the variables below; a `Point` or a station; the period (today by default; a date alone is the whole day, in the query's time zone, UTC by default); `SeriesQuery::HOURLY`, or `DAILY` with `Series::MEAN`, `MAX`, `MIN` or `SUM` | one `weather` call for every variable |

| Variable | Unit | |
|---|---|---|
| `temperature`, `dew_point` | °C | at 2 m |
| `relative_humidity`, `cloud_cover`, `precipitation_probability` | % | |
| `precipitation` | mm | the previous hour's |
| `pressure_msl` | hPa | reduced to sea level |
| `wind_speed`, `wind_gust_speed` | km/h | the previous hour's mean at 10 m, the gusts |
| `wind_direction`, `wind_gust_direction` | ° | |
| `visibility` | m | |
| `sunshine` | min | the previous hour's |
| `solar` | kWh/m² | the previous hour's irradiation |

A series' `label` is the name of the station most of its hours come from; its `place` the
query's point, or the station's (with its height). `forecast` is true when every hour comes from
a MOSMIX source, false when none does, null when mixed. The days are made from the hours in the
query's zone (Bright Sky has no daily records). Another step (`PT15M`), an hourly aggregation, a
day without one, or another variable are refused before any call.

## What an entity holds

`id`, `label` (`Frankfurt/Main`), `domain` earth, `kind` `station`, `place` (with its height),
`identifiers` (its DWD id and its WIGOS id from the WMO index, when it has them: some DWD
stations carry a pseudo-index such as `Q404`, which is not read), and `height` (m),
`dwd_station`, `wmo_station`, `period_start` and `period_end` (over all its sources, the
forecast's end included), `record_type` (`historical`, `current`, `synop`, `forecast`: one each).

## The Collector

The `Collector` follows a station's WIGOS id into a source that reads stations of that scheme
(none other than this one does yet); Bright Sky gives no other base's id.

## What the API does not give here

The current weather (`current_weather`), the radar composite (`radar`), the DWD's weather alerts
(`alerts`), the 6-hour precipitation probability, the condition and icon words, the fallback
sources of each field, and the SI units are not read.

## Example

```php
<?php

require __DIR__.'/vendor/autoload.php';

use Omnilab\BrightSky\BrightSkySourceFactory;
use Omnilab\Model\Point;
use Omnilab\Model\Series;
use Omnilab\Source\SeriesQuery;
use Symfony\Component\HttpClient\HttpClient;

$sky = (new BrightSkySourceFactory(HttpClient::create()))->create(['mailto' => 'you@example.org']);

$frankfurt = $sky->entity('dwd:01420');
echo $frankfurt->label, ' (', $frankfurt->id, ', WMO ', $frankfurt->value('wmo_station'), '): ', $frankfurt->place, ', ', $frankfurt->property('height'), ', records from ', $frankfurt->value('period_start'), "\n";

$day = new DateTimeImmutable('2026-10-26');
[$temperature, $rain] = $sky->series(new SeriesQuery(['temperature', 'precipitation'], $frankfurt->id, $day, $day));
printf("%s, %s: %s to %s %s, %s mm of rain, forecast: %s\n", $temperature->label, $day->format('Y-m-d'), $temperature->min(), $temperature->max(), $temperature->unit, array_sum(array_filter($rain->values())), var_export($temperature->forecast, true));

[$max] = $sky->series(new SeriesQuery(['temperature'], new Point(48.5734, 7.7521), new DateTimeImmutable('2026-10-28'), new DateTimeImmutable('2026-10-29'), SeriesQuery::DAILY, Series::MAX, 'Europe/Berlin'));
foreach ($max as [$date, $value]) {
    echo '  ', $max->label, ' ', $date->format('Y-m-d'), ' max ', $value, ' °C (forecast: ', var_export($max->forecast, true), ")\n";
}
```

```
Frankfurt/Main (dwd.station:01420, WMO 10637): 50.0259,8.5213, 99.7 m, records from 2010-01-01T00:00:00+00:00
FRANKFURT/M, 2026-10-26: 6 to 13.2 °C, 0 mm of rain, forecast: false
  KEHL 2026-10-28 max 14 °C (forecast: true)
  KEHL 2026-10-29 max 13.2 °C (forecast: true)
```

(as answered on 2026-10-27: three calls - the station, a day of its records, two days of MOSMIX
forecast near Strasbourg made into daily maxima)

```yaml
# config/packages/omnilab.yaml
omnilab:
    sources:
        brightsky: { factory: brightsky, options: { mailto: '%env(OMNILAB_MAILTO)%' } }
```

## Errors

Every call goes through `HttpSource`: a 404 or a 410 (and a 204) is null - an empty page for a list -; a 429 is a `RateLimitedException` (`retryAfter` from `Retry-After`); a 5xx, a time-out or a host that does not answer an `UnavailableException`; any other 4xx a `ProviderException` with its `status` and what the service said; an answer that is not JSON a `ProviderException`, a web page answered for JSON an `UnavailableException`.

Before any call (`NotSupportedException`): a variable not served, a step other than hourly or
daily, days without an aggregation or hours with one, a box, a WIGOS station without a WMO index,
a station of another scheme. An unknown station or a point without records is Bright Sky's 404
("No sources match your criteria"): `entity()` null, `series()` an empty list.

## Verified, and not

Against the real API on 2026-10-16: Frankfurt/Main by its DWD id (its WMO index 10637, records
from 2010-01-01, four kinds of records) and an unknown station (404, null); its hourly temperature
(7.8 to 19.5 °C) and precipitation on 2026-10-14 by its WMO index, observed; Strasbourg's point,
the daily maxima of 2026-10-08 to 10-14 in Berlin's days (18.1 to 20.6 °C, from
Kehl-Odelshofen); a day two days ahead, 24 hours of MOSMIX forecast (KEHL). The fixtures in
`Tests/Fixtures/` are its answers.

Again on 2026-10-27: Frankfurt/Main by its DWD id, a day of its records by that id, two days of
MOSMIX forecast near Strasbourg as daily maxima (the example above).

Not verified: a self-hosted instance; the public instance's behaviour under heavy use (no rate
published, none met).

## Responsibility

This package is an independent, unofficial client: it is not affiliated with, endorsed by or
sponsored by Bright Sky or the Deutscher Wetterdienst. The data are the DWD's, re-usable under
[CC BY 4.0](https://creativecommons.org/licenses/by/4.0/) with the source acknowledged ("Source:
Deutscher Wetterdienst"), Bright Sky named as the way they came, as the README's
[Terms of use](https://github.com/glitchr-omnisense/omnilab-brightsky#terms-of-use) says. Whoever
runs the package is responsible for complying with them and for any use made of what it returns:
see the README's [Responsibility](https://github.com/glitchr-omnisense/omnilab-brightsky#responsibility)
section.
