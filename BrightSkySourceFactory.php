<?php

namespace Omnilab\BrightSky;

use Omnilab\Config;
use Omnilab\Source\SourceFactory;
use Omnilab\Source\SourceInterface;
use Symfony\Component\HttpClient\HttpClient;

/**
 * Bright Sky: the Deutscher Wetterdienst's observations and MOSMIX
 * forecasts, hourly, at a point or a station - no key.
 *
 *   options:
 *     mailto: 'support@glitchr.io'     # in the User-Agent
 *     max_distance: ~                  # metres: how far from a Point a record may come (null: Bright Sky's default)
 *     throttle: 0                      # seconds between two calls: Bright Sky publishes no figure
 *     base_uri: 'https://api.brightsky.dev/'   # or a self-hosted instance
 */
final class BrightSkySourceFactory extends SourceFactory
{
    protected function populate(Config $c): void
    {
        $c->defaults([
            'omnilab.factory_name' => 'brightsky',
            'omnilab.required_options' => [],
            'max_distance' => null,
            'throttle' => 0.0,
            'base_uri' => BrightSkySource::BASE_URI,
        ]);
    }

    protected function build(Config $c): SourceInterface
    {
        $distance = $c->get('max_distance');

        return new BrightSkySource(
            $this->http ?? HttpClient::create(),
            null === $distance || '' === $distance ? null : (int) $distance,
            (string) $c->get('base_uri'),
            ['User-Agent' => self::userAgent($c)],
            (float) $c->get('throttle'),
        );
    }
}
