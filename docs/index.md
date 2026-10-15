# omnilab/brightsky

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

## Verified, and not

Against the real API on 2026-10-16: Frankfurt/Main by its DWD id (its WMO index 10637, records
from 2010-01-01, four kinds of records) and an unknown station (404, null); its hourly temperature
(7.8 to 19.5 °C) and precipitation on 2026-10-14 by its WMO index, observed; Strasbourg's point,
the daily maxima of 2026-10-08 to 10-14 in Berlin's days (18.1 to 20.6 °C, from
Kehl-Odelshofen); a day two days ahead, 24 hours of MOSMIX forecast (KEHL). The fixtures in
`Tests/Fixtures/` are its answers.

Not verified: a self-hosted instance; the public instance's behaviour under heavy use (no rate
published, none met).
