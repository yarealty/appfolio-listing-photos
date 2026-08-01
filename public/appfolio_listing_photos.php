<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

const APPFOLIO_API_BASE = 'https://api.appfolio.com';
const APPFOLIO_LISTINGS_PATH = '/api/v0/listings';
const APPFOLIO_UPDATED_FROM = '1970-01-01T00:00:00Z';
const APPFOLIO_MAX_PAGES = 100;
const APPFOLIO_CONFIG_PATH = __DIR__ . '/../config/appfolio.ini';
const UNITS_EXPORT_PATH = __DIR__ . '/../data/units_export.csv';

/** @return array{CLIENT_ID: string, CLIENT_SECRET: string, DEVELOPER_ID: string} */
function loadAppFolioConfig(): array
{
    $config = parse_ini_file(APPFOLIO_CONFIG_PATH, false, INI_SCANNER_RAW);

    if (!is_array($config)) {
        throw new RuntimeException('Unable to read the AppFolio configuration file.');
    }

    foreach (['CLIENT_ID', 'CLIENT_SECRET', 'DEVELOPER_ID'] as $key) {
        if (!isset($config[$key]) || !is_string($config[$key]) || trim($config[$key]) === '') {
            throw new RuntimeException("Missing {$key} in config/appfolio.ini.");
        }
    }

    return $config;
}

/** @return list<string> */
function loadTargetUnitIds(): array
{
    $csv = new SplFileObject(UNITS_EXPORT_PATH);
    $csv->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY);
    $headers = $csv->fgetcsv();

    if (!is_array($headers)) {
        throw new RuntimeException('Unable to read the units export header.');
    }

    $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $headers[0]);
    $columns = array_flip($headers);

    if (!isset($columns['active'], $columns['unit_id'])) {
        throw new RuntimeException('The units export must contain active and unit_id columns.');
    }

    $unitIds = [];
    foreach ($csv as $row) {
        if (!is_array($row) || trim((string) ($row[$columns['active']] ?? '')) !== '1') {
            continue;
        }

        $unitIds[] = trim((string) ($row[$columns['unit_id']] ?? ''));
    }

    $unitIds = array_values(array_unique($unitIds));
    $uuid = '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';

    if (!$unitIds || array_filter($unitIds, fn (string $id): bool => !preg_match($uuid, $id))) {
        throw new RuntimeException('The active rows in the units export must contain valid Unit ID UUIDs.');
    }

    return $unitIds;
}

/** @param array{CLIENT_ID: string, CLIENT_SECRET: string, DEVELOPER_ID: string} $config */
function createAppFolioClient(array $config): HttpClientInterface
{
    return HttpClient::createForBaseUri(APPFOLIO_API_BASE, [
        'auth_basic' => [$config['CLIENT_ID'], $config['CLIENT_SECRET']],
        'headers' => [
            'Accept' => 'application/json',
            'X-AppFolio-Developer-ID' => $config['DEVELOPER_ID'],
        ],
        'max_redirects' => 0,
        'timeout' => 60,
    ]);
}

/**
 * @param list<string> $targetUnitIds
 * @return array{listings: array<string, array<string, mixed>>, pages_fetched: int}
 */
function fetchActiveListings(HttpClientInterface $client, array $targetUnitIds): array
{
    $targets = array_fill_keys($targetUnitIds, true);
    $listings = [];

    for ($page = 1; $page <= APPFOLIO_MAX_PAGES; $page++) {
        $payload = $client->request('GET', APPFOLIO_LISTINGS_PATH, [
            'query' => [
                'filters' => ['LastUpdatedAtFrom' => APPFOLIO_UPDATED_FROM],
                'page' => ['number' => $page, 'size' => 1000],
            ],
        ])->toArray();

        if (!isset($payload['data']) || !is_array($payload['data'])) {
            throw new RuntimeException("Listings page {$page} did not contain a data array.");
        }

        foreach ($payload['data'] as $listing) {
            $unitId = is_array($listing) ? ($listing['UnitId'] ?? null) : null;
            if (is_string($unitId) && isset($targets[$unitId])) {
                $listings[$unitId] = $listing;
            }
        }

        if (count($listings) === count($targets) || empty($payload['next_page_path'])) {
            return ['listings' => $listings, 'pages_fetched' => $page];
        }
    }

    throw new RuntimeException('AppFolio pagination exceeded the configured safety limit.');
}

function photoUrl(mixed $value): ?string
{
    if (!is_string($value) || filter_var($value, FILTER_VALIDATE_URL) === false) {
        return null;
    }

    return strtolower((string) parse_url($value, PHP_URL_SCHEME)) === 'https' ? $value : null;
}

/** @param array<string, mixed>|null $listing @return list<array{url: string, thumbnail: string}> */
function listingPhotos(?array $listing): array
{
    $photos = [];

    foreach (($listing['UnitPhotos'] ?? []) as $photo) {
        if (!is_array($photo) || !($url = photoUrl($photo['Url'] ?? null))) {
            continue;
        }

        $photos[] = [
            'url' => $url,
            'thumbnail' => photoUrl($photo['ThumbnailUrl'] ?? null) ?? $url,
        ];
    }

    return $photos;
}

/** @param array<string, mixed>|null $listing */
function listingAddress(?array $listing, string $unitId): string
{
    $parts = array_filter([
        is_string($listing['Address1'] ?? null) ? trim($listing['Address1']) : '',
        is_string($listing['Address2'] ?? null) ? trim($listing['Address2']) : '',
    ]);

    return $parts ? implode(' · ', $parts) : "Unit {$unitId}";
}

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * @param list<string> $targetUnitIds
 * @param array<string, array<string, mixed>> $listings
 */
function renderPage(array $targetUnitIds, array $listings, int $pagesFetched, ?string $error = null): void
{
    $photoCounts = array_map(
        fn (string $unitId): int => count(listingPhotos($listings[$unitId] ?? null)),
        $targetUnitIds,
    );
    $unitsWithPhotos = count(array_filter($photoCounts));
    $totalPhotos = array_sum($photoCounts);

    header("Content-Security-Policy: default-src 'none'; img-src https:; style-src 'unsafe-inline'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'");
    header('Referrer-Policy: no-referrer');
    header('X-Content-Type-Options: nosniff');
    ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>AppFolio Listing Photos</title>
    <style>
        :root { color-scheme: light; font-family: Inter, ui-sans-serif, system-ui, sans-serif; color: #172033; background: #eef2f7; }
        * { box-sizing: border-box; }
        body { margin: 0; }
        header { padding: 2.5rem clamp(1rem, 5vw, 4rem); color: white; background: linear-gradient(135deg, #075985, #2563eb); }
        h1 { margin: 0 0 .5rem; font-size: clamp(1.8rem, 4vw, 3rem); }
        header p { margin: 0; color: #dbeafe; }
        main { width: min(1400px, calc(100% - 2rem)); margin: 1rem auto 4rem; }
        .summary, .listing { border: 1px solid #dbe2ea; border-radius: 14px; background: white; box-shadow: 0 8px 25px #0f172a0d; }
        .summary { display: grid; grid-template-columns: repeat(4, 1fr); margin-bottom: 1rem; padding: 1rem; gap: .75rem; }
        .metric { padding: .75rem; border-radius: 10px; background: #f8fafc; }
        .metric strong { display: block; font-size: 1.35rem; }
        .metric span, .unit-id { color: #64748b; font-size: .85rem; }
        .error, .empty { padding: 1rem; border-radius: 10px; }
        .error { margin-bottom: 1rem; color: #991b1b; background: #fee2e2; }
        .listing { margin: 1rem 0; padding: 1rem; }
        .listing-head { display: flex; align-items: start; justify-content: space-between; gap: 1rem; margin-bottom: 1rem; }
        h2 { margin: 0 0 .25rem; font-size: 1.15rem; }
        .badge { flex: none; padding: .35rem .65rem; border-radius: 999px; color: #166534; background: #dcfce7; font-size: .8rem; font-weight: 700; }
        .badge.zero { color: #9a3412; background: #ffedd5; }
        .photos { display: grid; grid-template-columns: repeat(auto-fill, minmax(210px, 1fr)); gap: .75rem; }
        .photo { display: block; overflow: hidden; border-radius: 10px; aspect-ratio: 4 / 3; background: #e2e8f0; }
        .photo img { width: 100%; height: 100%; object-fit: cover; transition: transform .18s ease; }
        .photo:hover img { transform: scale(1.025); }
        .empty { color: #92400e; background: #fffbeb; }
        @media (max-width: 700px) { .summary { grid-template-columns: repeat(2, 1fr); } .listing-head { display: block; } .badge { display: inline-block; margin-top: .65rem; } }
    </style>
</head>
<body>
<header>
    <h1>AppFolio Listing Photos</h1>
    <p>Photos returned by the read-only Listings API for <?= count($targetUnitIds) ?> active Unit IDs from the units export csv.</p>
</header>
<main>
    <?php if ($error): ?>
        <div class="error"><strong>Unable to load listings:</strong> <?= h($error) ?></div>
    <?php endif; ?>
    <section class="summary" aria-label="Summary">
        <div class="metric"><strong><?= count($targetUnitIds) ?></strong><span>target units</span></div>
        <div class="metric"><strong><?= count($listings) ?></strong><span>listings found</span></div>
        <div class="metric"><strong><?= $unitsWithPhotos ?></strong><span>units with photos</span></div>
        <div class="metric"><strong><?= $totalPhotos ?></strong><span>photos across <?= $pagesFetched ?> pages</span></div>
    </section>

    <?php foreach ($targetUnitIds as $index => $unitId): ?>
        <?php $listing = $listings[$unitId] ?? null; $photos = listingPhotos($listing); ?>
        <section class="listing">
            <div class="listing-head">
                <div>
                    <h2><?= $index + 1 ?>. <?= h(listingAddress($listing, $unitId)) ?></h2>
                    <div class="unit-id">Unit ID <?= h($unitId) ?></div>
                </div>
                <span class="badge<?= $photos ? '' : ' zero' ?>"><?= count($photos) ?> photo<?= count($photos) === 1 ? '' : 's' ?></span>
            </div>
            <?php if ($photos): ?>
                <div class="photos">
                    <?php foreach ($photos as $index => $photo): ?>
                        <a class="photo" href="<?= h($photo['url']) ?>" target="_blank" rel="noopener noreferrer">
                            <img src="<?= h($photo['thumbnail']) ?>" alt="<?= h(listingAddress($listing, $unitId)) ?> photo <?= $index + 1 ?>" loading="lazy">
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="empty"><?= $listing ? 'The listing returned no UnitPhotos.' : 'No listing was returned for this Unit ID.' ?></div>
            <?php endif; ?>
        </section>
    <?php endforeach; ?>
</main>
</body>
</html>
    <?php
}

if (!defined('APPFOLIO_GALLERY_SKIP_MAIN')) {
    $targetUnitIds = [];

    try {
        $targetUnitIds = loadTargetUnitIds();
        $config = loadAppFolioConfig();
        $result = fetchActiveListings(createAppFolioClient($config), $targetUnitIds);
        renderPage($targetUnitIds, $result['listings'], $result['pages_fetched']);
    } catch (Throwable $exception) {
        http_response_code(502);
        renderPage($targetUnitIds, [], 0, $exception->getMessage());
    }
}
