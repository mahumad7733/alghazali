<?php
declare(strict_types=1);

namespace App\Includes;

final class Seo
{
    /** @param array<string,mixed> $input */
    public static function tags(array $input = []): string
    {
        $siteName = trim((string) ($input['site_name'] ?? 'منصة رِحلة')) ?: 'منصة رِحلة';
        $title = trim((string) ($input['title'] ?? $siteName));
        $description = trim((string) ($input['description'] ?? 'احجز رحلات الباصات بين المدن بسهولة وأمان مع منصة رِحلة. ابحث عن الرحلة، اختر مقعدك، وتابع حجزك من حسابك.'));
        $path = (string) ($input['path'] ?? '/');
        $canonical = 'https://rihla.kesug.com' . ($path === '/' ? '/' : '/' . ltrim($path, '/'));
        $image = 'https://rihla.kesug.com/uploads/site/logo.png';
        $type = (string) ($input['type'] ?? 'website');
        $robots = (string) ($input['robots'] ?? 'index,follow,max-image-preview:large,max-snippet:-1,max-video-preview:-1');
        $json = [
            '@context' => 'https://schema.org',
            '@type' => 'Organization',
            'name' => $siteName,
            'url' => 'https://rihla.kesug.com/',
            'logo' => $image,
            'description' => $description,
            'sameAs' => [],
        ];
        if (($input['page_type'] ?? '') === 'home') {
            $json['@type'] = 'WebSite';
            $json['potentialAction'] = [
                '@type' => 'SearchAction',
                'target' => 'https://rihla.kesug.com/?from={search_term_string}',
                'query-input' => 'required name=search_term_string',
            ];
        }
        $e = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        return implode("\n  ", [
            '<meta name="description" content="' . $e($description) . '">',
            '<meta name="robots" content="' . $e($robots) . '">',
            '<link rel="canonical" href="' . $e($canonical) . '">',
            '<meta property="og:type" content="' . $e($type) . '">',
            '<meta property="og:site_name" content="' . $e($siteName) . '">',
            '<meta property="og:title" content="' . $e($title) . '">',
            '<meta property="og:description" content="' . $e($description) . '">',
            '<meta property="og:url" content="' . $e($canonical) . '">',
            '<meta property="og:image" content="' . $e($image) . '">',
            '<meta property="og:locale" content="ar_YE">',
            '<meta name="twitter:card" content="summary_large_image">',
            '<meta name="twitter:title" content="' . $e($title) . '">',
            '<meta name="twitter:description" content="' . $e($description) . '">',
            '<meta name="twitter:image" content="' . $e($image) . '">',
            '<script type="application/ld+json">' . json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) . '</script>',
        ]);
    }
}
