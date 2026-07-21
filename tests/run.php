<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

function assert_true(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$rss = '<?xml version="1.0" encoding="utf-8"?><rss><channel><title>RSS Aktive Nødvarsler</title><item><title>Test</title><description>Dette er en test</description><link>https://example.com</link><pubDate>Wed, 08 Jul 2026 10:00:00 GMT</pubDate></item></channel></rss>';
$alerts = erdet_parse_nodvarsel_rss($rss);
assert_true(count($alerts) === 1, 'RSS parser ett item');
assert_true($alerts[0]['title'] === 'Test', 'RSS parser title');
assert_true(!erdet_alert_matches_war($alerts[0]), 'Vanlig testvarsel trigger ikke krig');
assert_true(erdet_alert_matches_war(['title' => 'Væpnet angrep mot Norge', 'description' => '', 'link' => '', 'publishedAt' => null]), 'Krig/angrep trigger AI');

$review = erdet_enforce_conservative_review([
    'classification' => 'confirmed_yes',
    'confidence' => 'medium',
    'applies_to_norway_now' => true,
    'explicit_war_or_armed_attack' => true,
    'is_test_or_exercise' => false,
    'reason' => 'Mulig alvorlig varsel.',
], '2026-07-08T10:00:00+00:00', 'test-model');
assert_true($review['classification'] === 'uncertain', 'confirmed_yes uten high confidence nedgraderes');
assert_true(erdet_has_uncertain_review([$review]), 'Uncertain AI-review oppdages');
assert_true(!erdet_has_uncertain_review([['classification' => 'no']]), 'No AI-review er ikke uncertain');

$detailHtml = '<html><head><meta property="og:title" content="Øvelse Test"><meta name="description" content="Forsvaret øver."></head><body><ul><li><strong>Hvor</strong> Troms</li><li><strong>Når</strong> 1. juli til 31. juli 2026</li></ul></body></html>';
$notice = erdet_parse_forsvaret_exercise_detail($detailHtml, ['title' => 'Fallback', 'url' => 'https://www.forsvaret.no/test'], new DateTimeImmutable('2026-07-09', new DateTimeZone('Europe/Oslo')));
assert_true($notice !== null, 'Aktiv øvelse parses');
assert_true($notice['location'] === 'Troms', 'Øvelse location parses');

$overHtml = '<html><body>Øvelsen er avsluttet<ul><li><strong>Når</strong> 1. juli til 31. juli 2026</li></ul></body></html>';
assert_true(erdet_parse_forsvaret_exercise_detail($overHtml, ['title' => 'Over', 'url' => 'https://www.forsvaret.no/test'], new DateTimeImmutable('2026-07-09', new DateTimeZone('Europe/Oslo'))) === null, 'Avsluttet øvelse skjules');

$seoStatus = [
    'status' => 'no',
    'label' => 'NEI',
    'message' => 'Ingen aktive Nødvarsler er tolket som krig eller væpnet angrep mot Norge.',
    'checkedAt' => '2026-07-21T18:00:00+00:00',
    'source' => ['state' => 'ok'],
];
$faqItems = erdet_faq_items($seoStatus);
$pageJsonLd = erdet_page_json_ld(
    $faqItems,
    'https://erdetkriginorge.no',
    'Er det krig i Norge nå?',
    'Testbeskrivelse'
);
assert_true(($pageJsonLd['@graph'][0]['@type'] ?? null) === 'WebSite', 'JSON-LD inneholder WebSite');
assert_true(($pageJsonLd['@graph'][0]['url'] ?? null) === 'https://erdetkriginorge.no/', 'WebSite bruker kanonisk URL');
assert_true(in_array('FAQPage', $pageJsonLd['@graph'][1]['@type'] ?? [], true), 'JSON-LD inneholder FAQPage');
assert_true(count($pageJsonLd['@graph'][1]['mainEntity'] ?? []) === count($faqItems), 'Alle synlige FAQ-svar finnes i JSON-LD');
assert_true(str_contains(json_encode($pageJsonLd, JSON_UNESCAPED_SLASHES), ERDET_GITHUB_REPOSITORY_URL), 'Åpent GitHub-repo finnes i strukturert FAQ');

$projectRoot = dirname(__DIR__);
$robots = file_get_contents($projectRoot . '/robots.txt');
$sitemap = file_get_contents($projectRoot . '/sitemap.xml');
$indexSource = file_get_contents($projectRoot . '/index.php');
$htaccess = file_get_contents($projectRoot . '/.htaccess');
assert_true($robots !== false && str_contains($robots, 'Sitemap: https://erdetkriginorge.no/sitemap.xml'), 'robots.txt peker til sitemap');
assert_true($sitemap !== false && str_contains($sitemap, '<loc>https://erdetkriginorge.no/</loc>'), 'sitemap bruker kanonisk forside');
assert_true($indexSource !== false && str_contains($indexSource, '<h1 class="statusQuestion">'), 'Spørsmålet er sidens H1');
assert_true($indexSource !== false && !str_contains($indexSource, '<h1 class="statusAnswer">'), 'Statussvaret brukes ikke som H1');
assert_true($htaccess !== false && str_contains($htaccess, 'RewriteRule ^index\.php$ https://erdetkriginorge.no/'), '/index.php videresendes');
assert_true($htaccess !== false && str_contains($htaccess, '^www\.erdetkriginorge\.no$'), 'www videresendes');

echo "All PHP tests passed\n";
