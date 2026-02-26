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
use Psr\Log\LoggerInterface;

class ImmichService {
    private const CONFIG_SERVER_URL = 'server_url';
    private const CONFIG_API_KEY = 'api_key';

    public function __construct(
        private IClientService $clientService,
        private IConfig $config,
        private IUserSession $userSession,
        private LoggerInterface $logger,
    ) {
    }

    private function getUserId(): string {
        return $this->userSession->getUser()?->getUID() ?? '';
    }

    public function getServerUrl(): string {
        return rtrim(
            $this->config->getUserValue($this->getUserId(), Application::APP_ID, self::CONFIG_SERVER_URL, ''),
            '/'
        );
    }

    public function getApiKey(): string {
        return $this->config->getUserValue($this->getUserId(), Application::APP_ID, self::CONFIG_API_KEY, '');
    }

    public function setServerUrl(string $url): void {
        $this->config->setUserValue($this->getUserId(), Application::APP_ID, self::CONFIG_SERVER_URL, rtrim($url, '/'));
    }

    public function setApiKey(string $key): void {
        $this->config->setUserValue($this->getUserId(), Application::APP_ID, self::CONFIG_API_KEY, $key);
    }

    public function isConfigured(): bool {
        return $this->getServerUrl() !== '' && $this->getApiKey() !== '';
    }

    public function validateConnection(): array {
        try {
            $response = $this->request('POST', '/auth/validateToken');
            return ['success' => true, 'data' => $response];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    public function getTimelineBuckets(string $size = 'MONTH', ?string $personId = null, ?string $assetType = null, bool $isFavorite = false): array {
        $query = ['size' => $size];
        if ($personId !== null && $personId !== '') {
            $query['personId'] = $personId;
        }
        if ($assetType !== null && $assetType !== '') {
            $query['assetType'] = $assetType;
        }
        if ($isFavorite) {
            $query['isFavorite'] = 'true';
        }
        return $this->request('GET', '/timeline/buckets', ['query' => $query]);
    }

    public function getTimelineBucket(string $timeBucket, string $size = 'MONTH', ?string $personId = null, ?string $assetType = null, bool $isFavorite = false): array {
        $query = ['timeBucket' => $timeBucket, 'size' => $size];
        if ($personId !== null && $personId !== '') {
            $query['personId'] = $personId;
        }
        if ($assetType !== null && $assetType !== '') {
            $query['assetType'] = $assetType;
        }
        if ($isFavorite) {
            $query['isFavorite'] = 'true';
        }
        $raw = $this->request('GET', '/timeline/bucket', ['query' => $query]);
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

    public function getAlbums(): array {
        return $this->request('GET', '/albums');
    }

    public function getAlbum(string $id): array {
        return $this->request('GET', '/albums/' . $id);
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

    public function updateAsset(string $id, array $data): array {
        return $this->request('PUT', '/assets/' . $id, ['body' => $data]);
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

        $assets = [];
        foreach ($buckets as $bucket) {
            $timeBucket = $bucket['timeBucket'] ?? null;
            if (!$timeBucket) {
                continue;
            }
            $raw = $this->request('GET', '/timeline/bucket', [
                'query' => [
                    'timeBucket' => $timeBucket,
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
        string $fileContent,
        string $fileName,
        string $mimeType,
        string $createdAt,
        string $modifiedAt,
    ): array {
        $deviceAssetId = $fileName . '-' . md5($fileContent);
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

        return json_decode($response->getBody(), true);
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
                'DELETE' => $client->delete($url, $requestOptions),
                default => throw new \InvalidArgumentException('Unsupported HTTP method: ' . $method),
            };

            $body = $response->getBody();
            $decoded = json_decode($body, true);
            return $decoded ?? [];
        } catch (\Exception $e) {
            $this->logger->error('Immich API request failed: ' . $e->getMessage(), [
                'app' => Application::APP_ID,
                'endpoint' => $endpoint,
                'url' => $url,
            ]);
            throw $e;
        }
    }

    private function requestBinary(string $endpoint): array {
        $client = $this->clientService->newClient();
        $url = $this->getServerUrl() . '/api' . $endpoint;

        try {
            $response = $client->get($url, [
                'headers' => ['x-api-key' => $this->getApiKey()],
            ]);
            return [
                'body' => $response->getBody(),
                'contentType' => $response->getHeader('Content-Type'),
            ];
        } catch (\Exception $e) {
            $this->logger->error('Immich binary request failed: ' . $e->getMessage(), [
                'app' => Application::APP_ID,
                'endpoint' => $endpoint,
            ]);
            throw $e;
        }
    }

    private function requestBinaryPost(string $endpoint, array $body): array {
        $client = $this->clientService->newClient();
        $url = $this->getServerUrl() . '/api' . $endpoint;

        try {
            $response = $client->post($url, [
                'headers' => [
                    'x-api-key' => $this->getApiKey(),
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/octet-stream',
                ],
                'body' => json_encode($body),
            ]);
            return [
                'body' => $response->getBody(),
                'contentType' => $response->getHeader('Content-Type') ?: 'application/zip',
            ];
        } catch (\Exception $e) {
            $this->logger->error('Immich binary POST request failed: ' . $e->getMessage(), [
                'app' => Application::APP_ID,
                'endpoint' => $endpoint,
            ]);
            throw $e;
        }
    }
}
