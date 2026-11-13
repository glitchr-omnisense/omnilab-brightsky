# Changelog

## [Unreleased]

## [1.0.0] - 2026-11-13

The first stable release: it installs with Composer's default minimum-stability (stable).

### Added

- Omnilab Bright Sky: the Deutscher Wetterdienst's open data through Bright Sky - hourly weather at
  a point or a DWD or WMO station, the observations back to 2010 joined to the MOSMIX forecasts
  (temperature, dew point, humidity, precipitation and its probability, pressure, wind and gusts,
  cloud cover, visibility, sunshine, solar irradiation), or days made from the hours; a station as
  an entity; no key needed.

### Release

- Requires `omnisense/omnilab` `^1.0`, stable versions, where it required `@dev` ones:
  `composer require omnilab/brightsky` installs in a project at Composer's default minimum-stability
  (stable). `extra.branch-alias` names the branch (`dev-1.x`: `1.x-dev`).
- CI: `.github/workflows/ci.yml` (glitchr-studio/ci's php-package.yml, pinned: the package
  installed alone, PHPUnit, PHPStan against its baseline, a consumer install) and `release.yml`
  (that CI on the tag, then glitchr-studio/ci's php-release.yml); `phpunit.xml.dist` runs the
  package's own tests.
- README: the install line and a first call.
