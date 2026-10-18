<?php

declare(strict_types=1);

namespace App\Infrastructure\Service;

use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final readonly class SitemapGenerator
{
    public function __construct(
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /**
     * @return array<string>
     */
    private function getRouteNames(): array
    {
        return [
            'redirect_to_home',
            'website_home',
            'app_login',
            'website_client_access',
            'app_register',
            'website_features',
            'website_terms_and_conditions',
            'website_policy',
            'website_mentions',
        ];
    }

    public function generate(): string
    {
        $xml = new \SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"></urlset>');
        $lastmod = new \DateTimeImmutable()->format(\DateTimeInterface::ATOM);

        foreach ($this->getRouteNames() as $routeName) {
            $url = $xml->addChild('url');
            $url->addChild('loc', $this->urlGenerator->generate($routeName, [], UrlGeneratorInterface::ABSOLUTE_URL));
            $url->addChild('lastmod', $lastmod);
        }

        return $xml->asXML() ?: '';
    }
}
