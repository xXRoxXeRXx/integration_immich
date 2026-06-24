<?php

/**
 * SPDX-FileCopyrightText: 2026 Marcel Meyer <gh@grenzallee.eu>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */


declare(strict_types=1);

namespace OCA\IntegrationImmich\Service;

use OCA\IntegrationImmich\AppInfo\Application;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use OCP\IUserSession;
use OCP\Security\ICrypto;
use Psr\Log\LoggerInterface;

class ImmichService {
    private const CONFIG_SERVER_URL = 'server_url';
    private const CONFIG_API_KEY = 'api_key';

    /** Regex for a canonical UUID v4 string. */
    public const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    /** Max monthly buckets fetched in a single bulk person-assets request (~2 years). */
    private const MAX_PERSON_BUCKETS = 24;

    public function __construct(
        private IClientService $clientService,
        private IConfig $config,
        private IUserSession $userSession,
        private LoggerInterface $logger,
        private ICrypto $crypto,
    ) {
    }

    private function getUserId(): string {
        $uid = $this->userSession->getUser()?->getUID();
        if ($uid === null) {
            throw new \RuntimeException('No authenticated user session available');
        }
        return $uid;
    }

    public function getServerUrl(): string {
        return rtrim(
            $this->config->getUserValue($this->getUserId(), Application::APP_ID, self::CONFIG_SERVER_URL, ''),
            '/'
        );
    }

    public function getApiKey(): string {
        $stored = $this->config->getUserValue($this->getUserId(), Application::APP_ID, self::CONFIG_API_KEY, '');
        if ($stored === '') {
            return '';
        }
        try {
            return $this->crypto->decrypt($stored);
        } catch (\Exception $e) {
            // Fallback: value was stored in plaintext before encryption was added.
            // Only treat it as plaintext when it looks like a raw key (no base64/HMAC wrapper).
            // Log a warning so admins know re-saving the key will encrypt it properly.
            $this->logger->warning('ICrypto decrypt failed for api_key — assuming legacy plaintext value. Re-save the key in settings to encrypt it.', [
                'app' => Application::APP_ID,
            ]);
            return $stored;
        }
    }

    public function setServerUrl(string $url): void {
        $url = rtrim($url, '/');
        $parsed = parse_url($url);
        if (!$parsed || !in_array($parsed['scheme'] ?? '', ['http', 'https'], true)) {
            throw new \InvalidArgumentException('Invalid server URL: must use http or https scheme');
        }
        if (empty($parsed['host'])) {
            throw new \InvalidArgumentException('Invalid server URL: missing host');
        }
        $this->config->setUserValue($this->getUserId(), Application::APP_ID, self::CONFIG_SERVER_URL, $url);
    }

    public function setApiKey(string $key): void {
        $encrypted = $this->crypto->encrypt($key);
        $this->config->setUserValue($this->getUserId(), Application::APP_ID, self::CONFIG_API_KEY, $encrypted);
    }

    public function isConfigured(): bool {
        return $this->getServerUrl() !== '' && $this->getApiKey() !== '';
    }

    /**
     * Permissions the integration requires to work correctly.
     * These map to Immich's permission strings (as shown in the API key editor).
     */
    private const REQUIRED_PERMISSIONS = [
        'asset.view',
        'asset.read',
        'asset.update',
        'asset.upload',
        'asset.download',
        'asset.delete',
        'album.read',
        'album.create',
        'album.update',
        'album.delete',
        'albumAsset.create',
        'albumAsset.delete',
        'person.read',
        'map.read',
    ];

    public function validateConnection(): array {
        try {
            $response = $this->request('POST', '/auth/validateToken');

            // Check that the API key has all required permissions.
            // Immich returns the granted permissions in the token validation response.
            $missingPermissions = $this->detectMissingPermissions($response);

            return [
                'success' => true,
                'data' => $response,
                'missing_permissions' => $missingPermissions,
            ];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Validate the given credentials without reading from or writing to config.
     * Used by ConfigController to test new credentials before persisting them,
     * so a failed validation never overwrites a previously-working configuration.
     */
    public function validateConnectionWithCredentials(string $serverUrl, string $apiKey): array {
        $client = $this->clientService->newClient();
        $url = rtrim($serverUrl, '/') . '/api/auth/validateToken';

        try {
            $response = $client->post($url, [
                'headers' => [
                    'x-api-key' => $apiKey,
                    'Accept'    => 'application/json',
                ],
                'http_errors' => false,
                'timeout'     => 60,
            ]);

            $statusCode = $response->getStatusCode();
            if ($statusCode < 200 || $statusCode >= 300) {
                return ['success' => false, 'error' => 'HTTP ' . $statusCode];
            }

            $decoded = json_decode($response->getBody(), true) ?? [];
            $missingPermissions = $this->detectMissingPermissions($decoded);

            return [
                'success'             => true,
                'data'                => $decoded,
                'missing_permissions' => $missingPermissions,
            ];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Compare the permissions granted to the API key against those required
     * by the integration. Returns a (possibly empty) list of missing permission strings.
     */
    private function detectMissingPermissions(array $tokenResponse): array {
        // Immich returns granted permissions under different keys depending on version.
        // v2.x: $tokenResponse['permissions'] is an array of permission strings,
        // or the key may be absent for "all permissions" keys created before v1.98.
        $granted = $tokenResponse['permissions'] ?? null;

        // If the key predates scoped permissions, 'permissions' will be absent — treat as all-granted.
        if ($granted === null) {
            return [];
        }

        // 'all' is a special wildcard that grants everything.
        if (in_array('all', (array) $granted, true)) {
            return [];
        }

        $granted = array_flip((array) $granted);
        $missing = [];
        foreach (self::REQUIRED_PERMISSIONS as $required) {
            if (!isset($granted[$required])) {
                $missing[] = $required;
            }
        }
        return $missing;
    }

    public function getTimelineBuckets(string $size = 'MONTH', ?string $personId = null, ?string $albumId = null, ?string $assetType = null, bool $isFavorite = false): array {
        $query = ['size' => $size];
        if ($personId !== null && $personId !== '') {
            $query['personId'] = $personId;
        }
        if ($albumId !== null && $albumId !== '') {
            $query['albumId'] = $albumId;
        }
        if ($assetType !== null && $assetType !== '') {
            $query['assetType'] = $assetType;
        }
        if ($isFavorite) {
            $query['isFavorite'] = 'true';
        }
        return $this->request('GET', '/timeline/buckets', ['query' => $query]);
    }

    public function getTimelineBucket(string $timeBucket, string $size = 'MONTH', ?string $personId = null, ?string $albumId = null, ?string $assetType = null, bool $isFavorite = false): array {
        // Immich v2 requires ISO-8601 format (YYYY-MM-DDTHH:MM:SS.000Z) for timeBucket.
        // Older API responses returned short dates (YYYY-MM-DD); normalize them here.
        if (strlen($timeBucket) === 10) {
            $timeBucket .= 'T00:00:00.000Z';
        }
        $query = ['timeBucket' => $timeBucket, 'size' => $size];
        if ($personId !== null && $personId !== '') {
            $query['personId'] = $personId;
        }
        if ($albumId !== null && $albumId !== '') {
            $query['albumId'] = $albumId;
        }
        if ($assetType !== null && $assetType !== '') {
            $query['assetType'] = $assetType;
        }
        if ($isFavorite) {
            $query['isFavorite'] = 'true';
        }
        $raw = $this->request('GET', '/timeline/bucket', ['query' => $query]);

        // Debug: log the asset count returned by Immich so we can diagnose empty-bucket issues.
        $assetCount = isset($raw['id']) && is_array($raw['id']) ? count($raw['id']) : count($raw);
        $this->logger->debug('Immich /timeline/bucket returned ' . $assetCount . ' assets', [
            'app'        => Application::APP_ID,
            'timeBucket' => $timeBucket,
            'size'       => $size,
            'query'      => $query,
            'rawKeys'    => array_keys($raw),
        ]);

        return $this->transformBucketAssets($raw);
    }

    private function transformBucketAssets(array $raw): array {
        if (!isset($raw['id']) || !is_array($raw['id'])) {
            return $raw;
        }
        $count = count($raw['id']);
        $keys = array_keys($raw);
        $assets = [];
        for ($i = 0; $i < $count; $i++) {
            $asset = [];
            foreach ($keys as $key) {
                $asset[$key] = is_array($raw[$key]) ? ($raw[$key][$i] ?? null) : $raw[$key];
            }
            $assets[] = $asset;
        }
        return $assets;
    }

    public function getAsset(string $id): array {
        return $this->request('GET', '/assets/' . $id);
    }

    public function getAssetThumbnail(string $id, string $size = 'thumbnail'): array {
        return $this->requestBinary('/assets/' . $id . '/thumbnail?size=' . urlencode($size));
    }

    public function getAssetOriginal(string $id): array {
        return $this->requestBinary('/assets/' . $id . '/original');
    }

    public function downloadArchive(array $assetIds): array {
        return $this->requestBinaryPost('/download/archive', ['assetIds' => $assetIds]);
    }

    public function getVideoStream(string $id, string $rangeHeader = ''): array {
        $client = $this->clientService->newClient();
        $url = $this->getServerUrl() . '/api/assets/' . $id . '/video/playback';

        $headers = ['x-api-key' => $this->getApiKey()];
        if ($rangeHeader !== '') {
            $headers['Range'] = $rangeHeader;
        }

        try {
            $response = $client->get($url, [
                'headers' => $headers,
                'http_errors' => false,
            ]);
            return [
                'body'          => $response->getBody(),
                'statusCode'    => $response->getStatusCode(),
                'contentType'   => $response->getHeader('Content-Type') ?: 'video/mp4',
                'contentLength' => $response->getHeader('Content-Length') ?: '',
                'contentRange'  => $response->getHeader('Content-Range') ?: '',
                'acceptRanges'  => $response->getHeader('Accept-Ranges') ?: 'bytes',
            ];
        } catch (\Exception $e) {
            $this->logger->error('Immich video stream request failed: ' . $e->getMessage(), [
                'app'      => Application::APP_ID,
                'endpoint' => '/assets/' . $id . '/video/playback',
            ]);
            throw $e;
        }
    }

    public function getAlbums(string $assetId = ''): array {
        // When filtering by asset, a single call is sufficient.
        if ($assetId !== '') {
            return $this->request('GET', '/albums', ['query' => ['assetId' => $assetId]]);
        }

        // Fetch albums owned by the user.
        $owned = $this->request('GET', '/albums', []);

        // Fetch albums shared with the user (owned by someone else).
        // Immich returns different sets for these two calls — mirroring the
        // behaviour of the official Immich web client.
        $shared = $this->request('GET', '/albums', ['query' => ['shared' => 'true']]);

        // Merge and deduplicate by album id so that albums the user both owns
        // AND has shared with others appear only once.
        $merged = $owned;
        $seenIds = array_column($owned, 'id');
        foreach ($shared as $album) {
            if (!in_array($album['id'], $seenIds, true)) {
                $merged[] = $album;
            }
        }

        return $merged;
    }

    public function getAlbum(string $id): array {
        return $this->request('GET', '/albums/' . $id);
    }

    /**
     * Returns the Immich user profile of the currently authenticated API key owner.
     */
    public function getMe(): array {
        return $this->request('GET', '/users/me');
    }

    public function createAlbum(string $albumName, array $assetIds = []): array {
        $body = ['albumName' => $albumName];
        if (!empty($assetIds)) {
            $body['assetIds'] = $assetIds;
        }
        return $this->request('POST', '/albums', ['body' => $body]);
    }

    public function addAssetsToAlbum(string $albumId, array $assetIds): array {
        return $this->request('PUT', '/albums/' . $albumId . '/assets', ['body' => ['ids' => $assetIds]]);
    }

    public function removeAssetsFromAlbum(string $albumId, array $assetIds): array {
        return $this->request('DELETE', '/albums/' . $albumId . '/assets', ['body' => ['ids' => $assetIds]]);
    }

    public function deleteAlbum(string $albumId): void {
        $this->request('DELETE', '/albums/' . $albumId);
    }

    public function renameAlbum(string $albumId, string $albumName): array {
        return $this->request('PATCH', '/albums/' . $albumId, ['body' => ['albumName' => $albumName]]);
    }

    public function updateAsset(string $id, array $data): array {
        return $this->request('PUT', '/assets/' . $id, ['body' => $data]);
    }

    public function deleteAssets(array $assetIds): void {
        $this->request('DELETE', '/assets', ['body' => ['ids' => $assetIds]]);
    }

    // ---- People ----

    public function getPeople(): array {
        $result = $this->request('GET', '/people');
        return $result['people'] ?? (array) $result;
    }

    public function getPersonAssets(string $id): array {
        // Immich v2.x does not expose /people/{id}/assets.
        // Fetch all timeline buckets filtered by personId, then load each bucket.
        $buckets = $this->request('GET', '/timeline/buckets', [
            'query' => ['size' => 'MONTH', 'personId' => $id],
        ]);

        if (!is_array($buckets)) {
            return [];
        }

        // Cap bucket fetches to avoid holding the worker for too long.
        // The frontend can implement pagination if more are needed.
        $assets = [];
        foreach (array_slice($buckets, 0, self::MAX_PERSON_BUCKETS) as $bucket) {
            $timeBucket = $bucket['timeBucket'] ?? null;
            if (!$timeBucket) {
                continue;
            }
            $raw = $this->request('GET', '/timeline/bucket', [
                'query' => [
                    'timeBucket' => (strlen($timeBucket) === 10 ? $timeBucket . 'T00:00:00.000Z' : $timeBucket),
                    'size' => 'MONTH',
                    'personId' => $id,
                ],
            ]);
            $assets = array_merge($assets, $this->transformBucketAssets($raw));
        }

        return $assets;
    }

    public function getPersonThumbnail(string $id): array {
        return $this->requestBinary('/people/' . $id . '/thumbnail');
    }

    // ---- Map ----

    public function getMapMarkers(): array {
        return $this->request('GET', '/map/markers', [
            'query' => ['isArchived' => 'false'],
        ]);
    }

    /**
     * Search assets by location field (city / country / state).
     * Uses Immich's POST /search/metadata to return AssetResponseDto objects
     * with width + height — which getItemRatio() in the frontend can use for masonry.
     */
    public function searchByLocation(string $field, string $value): array {
        // Map frontend field paths (e.g. "exifInfo.city") to MetadataSearchDto keys.
        $fieldMap = [
            'exifInfo.city'    => 'city',
            'exifInfo.country' => 'country',
            'exifInfo.state'   => 'state',
            'city'             => 'city',
            'country'          => 'country',
            'state'            => 'state',
        ];
        $key = $fieldMap[$field] ?? $field;

        $body = [
            $key         => $value,
            'isArchived' => false,
            'size'       => 1000,
        ];

        $result = $this->request('POST', '/search/metadata', ['body' => $body]);
        return $result['assets']['items'] ?? [];
    }

    // ---- Explore ----

    public function getExplore(): array {
        // Immich v2.x does not expose /explore.
        // Build explore sections by grouping map markers by city and country.
        try {
            $markers = $this->request('GET', '/map/markers', [
                'query' => ['isArchived' => 'false'],
            ]);

            if (!is_array($markers) || empty($markers)) {
                return [];
            }

            $cities = [];
            $countries = [];

            foreach ($markers as $marker) {
                $id = $marker['id'] ?? null;
                if (!$id) {
                    continue;
                }
                $city = $marker['city'] ?? null;
                $country = $marker['country'] ?? null;

                if ($city !== null && $city !== '' && !isset($cities[$city])) {
                    $cities[$city] = ['value' => $city, 'data' => ['id' => $id]];
                }
                if ($country !== null && $country !== '' && !isset($countries[$country])) {
                    $countries[$country] = ['value' => $country, 'data' => ['id' => $id]];
                }
            }

            $result = [];
            if (!empty($cities)) {
                $result[] = ['fieldName' => 'exifInfo.city', 'items' => array_values($cities)];
            }
            if (!empty($countries)) {
                $result[] = ['fieldName' => 'exifInfo.country', 'items' => array_values($countries)];
            }

            return $result;
        } catch (\Exception $e) {
            $this->logger->warning('Explore via map markers failed: ' . $e->getMessage(), [
                'app' => Application::APP_ID,
            ]);
            return [];
        }
    }

    // ---- Upload ----

    public function uploadAsset(
        mixed $fileContent,
        string $fileName,
        string $mimeType,
        string $createdAt,
        string $modifiedAt,
    ): array {
        $deviceAssetId = $fileName . '-' . bin2hex(random_bytes(8));
        $client = $this->clientService->newClient();
        $url = $this->getServerUrl() . '/api/assets';

        $response = $client->post($url, [
            'headers' => [
                'x-api-key' => $this->getApiKey(),
                'Accept' => 'application/json',
            ],
            'multipart' => [
                ['name' => 'assetData', 'contents' => $fileContent, 'filename' => $fileName, 'headers' => ['Content-Type' => $mimeType]],
                ['name' => 'deviceAssetId', 'contents' => $deviceAssetId],
                ['name' => 'deviceId', 'contents' => 'nextcloud-integration'],
                ['name' => 'fileCreatedAt', 'contents' => $createdAt],
                ['name' => 'fileModifiedAt', 'contents' => $modifiedAt],
            ],
        ]);

        $decoded = json_decode($response->getBody(), true);
        return is_array($decoded) ? $decoded : ['status' => 'unknown', 'raw' => (string)$response->getBody()];
    }

    // ---- HTTP helpers ----

    private function request(string $method, string $endpoint, array $options = []): array {
        $client = $this->clientService->newClient();
        $url = $this->getServerUrl() . '/api' . $endpoint;
        if (isset($options['query']) && !empty($options['query'])) {
            $url .= '?' . http_build_query($options['query']);
        }

        $requestOptions = [
            'headers' => [
                'x-api-key' => $this->getApiKey(),
                'Accept' => 'application/json',
            ],
            // Prevent the worker from hanging indefinitely if Immich is slow or
            // unresponsive. 60 s should be generous even for large timeline buckets.
            'timeout' => 60,
            // Do not throw exceptions for 4xx/5xx — we handle status codes ourselves.
            'http_errors' => false,
        ];

        if (isset($options['body'])) {
            $requestOptions['body'] = json_encode($options['body']);
            $requestOptions['headers']['Content-Type'] = 'application/json';
        }

        try {
            $response = match (strtoupper($method)) {
                'GET' => $client->get($url, $requestOptions),
                'POST' => $client->post($url, $requestOptions),
                'PUT' => $client->put($url, $requestOptions),
                'PATCH' => $client->patch($url, $requestOptions),
                'DELETE' => $client->delete($url, $requestOptions),
                default => throw new \InvalidArgumentException('Unsupported HTTP method: ' . $method),
            };

            $statusCode = $response->getStatusCode();

            // 403 means the API key lacks the required permission for this endpoint.
            // Return an empty result instead of throwing so the UI shows empty content
            // rather than a generic 500 error. A warning is logged to help with debugging.
            if ($statusCode === 403) {
                $this->logger->warning('Immich API returned 403 for ' . $endpoint . ' — API key may be missing required permissions.', [
                    'app'      => Application::APP_ID,
                    'endpoint' => $endpoint,
                    'method'   => $method,
                ]);
                return [];
            }

            // For other non-2xx responses, log and throw so callers can handle them.
            if ($statusCode < 200 || $statusCode >= 300) {
                $this->logger->error('Immich API returned HTTP ' . $statusCode . ' for ' . $endpoint, [
                    'app'      => Application::APP_ID,
                    'endpoint' => $endpoint,
                    'method'   => $method,
                ]);
                throw new \RuntimeException('Immich API error: HTTP ' . $statusCode);
            }

            $body = $response->getBody();
            $decoded = json_decode($body, true);
            return $decoded ?? [];
        } catch (\Exception $e) {
            $this->logger->error('Immich API request failed: ' . $e->getMessage(), [
                'app'      => Application::APP_ID,
                'endpoint' => $endpoint,
                'method'   => $method,
                'url'      => $url,
            ]);
            throw $e;
        }
    }

    private function requestBinary(string $endpoint): array {
        $client = $this->clientService->newClient();
        $url = $this->getServerUrl() . '/api' . $endpoint;

        try {
            $response = $client->get($url, [
                'headers'     => ['x-api-key' => $this->getApiKey()],
                'timeout'     => 60,
                'http_errors' => false,
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Immich binary request failed: ' . $e->getMessage(), [
                'app'      => Application::APP_ID,
                'endpoint' => $endpoint,
            ]);
            throw $e;
        }

        $statusCode = $response->getStatusCode();
        if ($statusCode < 200 || $statusCode >= 300) {
            $this->logger->error('Immich binary request returned HTTP ' . $statusCode . ' for ' . $endpoint, [
                'app'      => Application::APP_ID,
                'endpoint' => $endpoint,
            ]);
            throw new \RuntimeException('Immich API error: HTTP ' . $statusCode);
        }

        return [
            'body'        => $response->getBody(),
            'contentType' => $response->getHeader('Content-Type'),
        ];
    }

    private function requestBinaryPost(string $endpoint, array $body): array {
        $client = $this->clientService->newClient();
        $url = $this->getServerUrl() . '/api' . $endpoint;

        try {
            $response = $client->post($url, [
                'headers' => [
                    'x-api-key'    => $this->getApiKey(),
                    'Content-Type' => 'application/json',
                    'Accept'       => 'application/octet-stream',
                ],
                'body'        => json_encode($body),
                'http_errors' => false,
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Immich binary POST request failed: ' . $e->getMessage(), [
                'app'      => Application::APP_ID,
                'endpoint' => $endpoint,
            ]);
            throw $e;
        }

        $statusCode = $response->getStatusCode();
        if ($statusCode < 200 || $statusCode >= 300) {
            $this->logger->error('Immich binary POST request returned HTTP ' . $statusCode . ' for ' . $endpoint, [
                'app'      => Application::APP_ID,
                'endpoint' => $endpoint,
            ]);
            throw new \RuntimeException('Immich API error: HTTP ' . $statusCode);
        }

        return [
            'body'        => $response->getBody(),
            'contentType' => $response->getHeader('Content-Type') ?: 'application/zip',
        ];
    }
}
