<?php

namespace Omnischolar\Hal;

use Omnischolar\Config;
use Omnischolar\Source\SourceFactory;
use Omnischolar\Source\SourceInterface;
use Symfony\Component\HttpClient\HttpClient;

/**
 * HAL, the French open archive - no key.
 *
 *   options:
 *     domains: ['shs.droit']                       # a filter on every query (HAL domain codes); none by default
 *     base_uri: 'https://api.archives-ouvertes.fr/'
 */
final class HalSourceFactory extends SourceFactory
{
    protected function populate(Config $c): void
    {
        $c->defaults([
            'omnischolar.factory_name' => 'hal',
            'omnischolar.required_options' => [],
            'domains' => [],
            'base_uri' => HalSource::BASE_URI,
        ]);
    }

    protected function build(Config $c): SourceInterface
    {
        return new HalSource(
            $this->http ?? HttpClient::create(),
            array_values(array_map('strval', (array) $c->get('domains', []))),
            (string) $c->get('base_uri', HalSource::BASE_URI),
            ['User-Agent' => self::userAgent($c)],
        );
    }
}
