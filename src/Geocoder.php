<?php
declare(strict_types=1);

/**
 * Geocodes a text address to lat/lon using Nominatim (OpenStreetMap).
 * Results are persisted in a JSON cache file to avoid redundant API calls.
 *
 * @return array{lat: float|null, lon: float|null}
 */
function geocode(string $address, string $cachePath): array
{
    if ($address === '') {
        return ['lat' => null, 'lon' => null];
    }

    // Load cache
    $cache = [];
    if (file_exists($cachePath)) {
        $cache = json_decode(file_get_contents($cachePath), true) ?: [];
    }

    if (isset($cache[$address])) {
        return $cache[$address];
    }

    $url  = 'https://nominatim.openstreetmap.org/search?'
          . http_build_query(['q' => $address, 'format' => 'json', 'limit' => 1]);
    $opts = ['http' => ['header' => "User-Agent: SPS-pointage\r\n", 'timeout' => 5]];
    $json = @file_get_contents($url, false, stream_context_create($opts));

    $result = ['lat' => null, 'lon' => null];
    if ($json !== false) {
        $data = json_decode($json, true);
        if (!empty($data[0])) {
            $result = ['lat' => (float) $data[0]['lat'], 'lon' => (float) $data[0]['lon']];
        }
    }

    // Persist cache
    $cache[$address] = $result;
    @file_put_contents($cachePath, json_encode($cache, JSON_PRETTY_PRINT));

    return $result;
}
