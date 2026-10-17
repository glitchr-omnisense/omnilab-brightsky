# omnilab/brightsky

The Deutscher Wetterdienst's open data for [omniscient/omnilab](https://github.com/glitchr-studio/omnilab),
through [Bright Sky](https://brightsky.dev): hourly weather at a point or at a DWD or WMO station -
the observations back to 2010 (the stations' records, today's reports) joined to the MOSMIX
forecasts ten days ahead: temperature, dew point, humidity, precipitation and its probability,
pressure, wind and gusts, cloud cover, visibility, sunshine, solar irradiation -, or days made from
the hours; and a station as an entity. No key.

```php
$sky = (new BrightSkySourceFactory($http))->create(['mailto' => 'support@glitchr.io']);
$frankfurt = $sky->entity('dwd:01420');           // Frankfurt/Main: 50.0259,8.5213 (99.7 m), WMO 10637, records from 2010

[$temperature] = $sky->series(new SeriesQuery(['temperature'], Identifier::of('wmo', '10637'),
    new \DateTimeImmutable('2026-10-14'), new \DateTimeImmutable('2026-10-14')));
$temperature->values();                            // 13.2 12.9 12.2 ... 7.8 (°C, observed)

[$max] = $sky->series(new SeriesQuery(['temperature'], new Point(48.5734, 7.7521), new \DateTimeImmutable('2026-10-08'),
    new \DateTimeImmutable('2026-10-14'), SeriesQuery::DAILY, Series::MAX, 'Europe/Berlin'));
$max->values();                                    // 18.1 14.7 17.2 15.2 18.2 18.2 20.6 (Kehl-Odelshofen, across the Rhine)
```

```yaml
omnilab:
    sources:
        brightsky:
            factory: brightsky
            options:
                mailto: 'support@glitchr.io'   # in the User-Agent
                max_distance: ~                # metres: how far from a Point a record may come
                base_uri: 'https://api.brightsky.dev/' # or a self-hosted instance
```

The same weather for anywhere, from models, is [omnilab/open-meteo](https://github.com/glitchr-studio/omnilab-open-meteo);
NOAA's station records of the world, [omnilab/noaa](https://github.com/glitchr-studio/omnilab-noaa).

See [docs/](docs/index.md) for what is read, how it maps, and what the API does not give here.

## Terms of use

The data are the Deutscher Wetterdienst's, re-usable under [CC BY 4.0](https://creativecommons.org/licenses/by/4.0/)
"providing the source is duly acknowledged" ([DWD legal notice](https://www.dwd.de/EN/service/legal_notice/legal_notice.html)):
credit "Source: Deutscher Wetterdienst" where you show them, and name Bright Sky as the way they
came. Bright Sky's public instance is free for all purposes and needs no key; it publishes no
rate; heavy users can run their own instance (`base_uri`). The source makes one call per series
query, every variable at once, and one per station.

## Responsibility

This package is an independent, unofficial client: it is not affiliated with, endorsed by or
sponsored by the Deutscher Wetterdienst or the Bright Sky project. It reaches their service on behalf of whoever runs it, and it does not
redistribute their data beyond the short excerpts recorded as test fixtures. Its MIT licence
covers this package's code only and grants no right to the service or to its data.

Whoever installs and runs this package is responsible for complying with the terms above, for
obtaining whatever they require (an account, a key, a licence or subscription, an attribution or
a citation, a rate limit to respect), and for any use made of what it returns. The authors
publish it free of charge, make no commercial use of it, and accept no liability for how it is
used (see [LICENSE](LICENSE)).

License: MIT.
