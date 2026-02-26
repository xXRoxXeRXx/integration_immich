<?php

/**
 * SPDX-FileCopyrightText: 2026 Marcel Meyer <gh@grenzallee.eu>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */


declare(strict_types=1);

namespace OCA\IntegrationImmich\Controller;

use OCA\IntegrationImmich\AppInfo\Application;
use OCA\IntegrationImmich\Service\ImmichService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IRequest;

class AssetsController extends Controller {
    public function __construct(
        IRequest $request,
        private ImmichService $immichService,
        private IRootFolder $rootFolder,
        private ?string $userId,
    ) {
        parent::__construct(Application::APP_ID, $request);
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function timeline(): JSONResponse {
        if (!$this->immichService->isConfigured()) {
            return new JSONResponse(
                ['error' => 'Immich is not configured'],
                Http::STATUS_PRECONDITION_FAILED
            );
        }

        try {
            $timeBucket = $this->request->getParam('timeBucket');
            $size = $this->request->getParam('size', 'MONTH');
            $personId = $this->request->getParam('personId');
            $assetType = $this->request->getParam('assetType');
            $isFavoriteParam = $this->request->getParam('isFavorite');
            $isFavorite = $isFavoriteParam === 'true';

            if ($timeBucket) {
                $data = $this->immichService->getTimelineBucket($timeBucket, $size, $personId, null, $isFavorite);
                // Immich timeline/bucket does not support assetType filtering.
                // Immich returns isImage (bool) instead of a type field — filter in PHP.
                if ($assetType === 'IMAGE') {
                    $data = array_values(array_filter(
                        $data,
                        static fn(array $asset): bool => (bool)($asset['isImage'] ?? true)
                    ));
                } elseif ($assetType === 'VIDEO') {
                    $data = array_values(array_filter(
                        $data,
                        static fn(array $asset): bool => !($asset['isImage'] ?? true)
                    ));
                }
            } else {
                $data = $this->immichService->getTimelineBuckets($size, $personId, null, $isFavorite);
            }

            return new JSONResponse($data);
        } catch (\Exception $e) {
            return new JSONResponse(
                ['error' => $e->getMessage()],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function show(string $id): JSONResponse {
        if (!$this->immichService->isConfigured()) {
            return new JSONResponse(
                ['error' => 'Immich is not configured'],
                Http::STATUS_PRECONDITION_FAILED
            );
        }

        try {
            $data = $this->immichService->getAsset($id);
            return new JSONResponse($data);
        } catch (\Exception $e) {
            return new JSONResponse(
                ['error' => $e->getMessage()],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function update(string $id): JSONResponse {
        if (!$this->immichService->isConfigured()) {
            return new JSONResponse(['error' => 'Immich is not configured'], Http::STATUS_PRECONDITION_FAILED);
        }
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $id)) {
            return new JSONResponse(['error' => 'Invalid asset ID format'], Http::STATUS_BAD_REQUEST);
        }
        $allowed = ['isFavorite', 'isArchived', 'description'];
        $data = array_intersect_key($this->request->getParams(), array_flip($allowed));
        if (empty($data)) {
            return new JSONResponse(['error' => 'No valid fields provided'], Http::STATUS_BAD_REQUEST);
        }
        try {
            $result = $this->immichService->updateAsset($id, $data);
            return new JSONResponse($result);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function thumbnail(string $id): DataDownloadResponse|JSONResponse {
        if (!$this->immichService->isConfigured()) {
            return new JSONResponse(
                ['error' => 'Immich is not configured'],
                Http::STATUS_PRECONDITION_FAILED
            );
        }

        try {
            $size = $this->request->getParam('size', 'thumbnail');
            $result = $this->immichService->getAssetThumbnail($id, $size);
            $response = new DataDownloadResponse(
                $result['body'],
                $id . '.jpg',
                $result['contentType'] ?? 'image/jpeg'
            );
            $response->cacheFor(3600);
            return $response;
        } catch (\Exception $e) {
            return new JSONResponse(
                ['error' => $e->getMessage()],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function original(string $id): DataDownloadResponse|JSONResponse {
        if (!$this->immichService->isConfigured()) {
            return new JSONResponse(
                ['error' => 'Immich is not configured'],
                Http::STATUS_PRECONDITION_FAILED
            );
        }

        try {
            $result = $this->immichService->getAssetOriginal($id);
            $response = new DataDownloadResponse(
                $result['body'],
                $id,
                $result['contentType'] ?? 'application/octet-stream'
            );
            return $response;
        } catch (\Exception $e) {
            return new JSONResponse(
                ['error' => $e->getMessage()],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function videoStream(string $id): DataDownloadResponse|JSONResponse {
        if (!$this->immichService->isConfigured()) {
            return new JSONResponse(
                ['error' => 'Immich is not configured'],
                Http::STATUS_PRECONDITION_FAILED
            );
        }

        try {
            $rangeHeader = $this->request->getHeader('Range') ?? '';
            $result = $this->immichService->getVideoStream($id, $rangeHeader);

            $response = new DataDownloadResponse(
                $result['body'],
                $id,
                $result['contentType']
            );

            // Override Content-Disposition so the browser plays the video inline
            $response->addHeader('Content-Disposition', 'inline');
            $response->addHeader('Accept-Ranges', $result['acceptRanges'] ?: 'bytes');

            if ($result['contentLength'] !== '') {
                $response->addHeader('Content-Length', $result['contentLength']);
            }
            if ($result['contentRange'] !== '') {
                $response->addHeader('Content-Range', $result['contentRange']);
            }

            $response->setStatus((int)($result['statusCode'] ?? Http::STATUS_OK));

            return $response;
        } catch (\Exception $e) {
            return new JSONResponse(
                ['error' => $e->getMessage()],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function mapMarkers(): JSONResponse {
        if (!$this->immichService->isConfigured()) {
            return new JSONResponse(
                ['error' => 'Immich is not configured'],
                Http::STATUS_PRECONDITION_FAILED
            );
        }

        try {
            $markers = $this->immichService->getMapMarkers();
            return new JSONResponse($markers);
        } catch (\Exception $e) {
            return new JSONResponse(
                ['error' => $e->getMessage()],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function explore(): JSONResponse {
        if (!$this->immichService->isConfigured()) {
            return new JSONResponse(
                ['error' => 'Immich is not configured'],
                Http::STATUS_PRECONDITION_FAILED
            );
        }

        try {
            $data = $this->immichService->getExplore();
            return new JSONResponse($data);
        } catch (\Exception $e) {
            return new JSONResponse(
                ['error' => $e->getMessage()],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function downloadAssets(): DataDownloadResponse|JSONResponse {
        if (!$this->immichService->isConfigured()) {
            return new JSONResponse(
                ['error' => 'Immich is not configured'],
                Http::STATUS_PRECONDITION_FAILED
            );
        }

        $assetIds = $this->request->getParam('assetIds', []);
        if (!is_array($assetIds)) {
            $assetIds = [$assetIds];
        }

        if (empty($assetIds)) {
            return new JSONResponse(['error' => 'assetIds must be a non-empty array'], Http::STATUS_BAD_REQUEST);
        }

        foreach ($assetIds as $id) {
            if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', (string)$id)) {
                return new JSONResponse(['error' => 'Invalid asset ID format'], Http::STATUS_BAD_REQUEST);
            }
        }

        try {
            if (count($assetIds) === 1) {
                // Single asset → GET /api/assets/{id}/original
                $assetId = (string) $assetIds[0];
                $asset = $this->immichService->getAsset($assetId);
                $fileName = $asset['originalFileName'] ?? ($assetId . '.bin');

                $result = $this->immichService->getAssetOriginal($assetId);
                $response = new DataDownloadResponse(
                    $result['body'],
                    $fileName,
                    $result['contentType'] ?? 'application/octet-stream'
                );
                return $response;
            }

            // Multiple assets → POST /api/download/archive → Immich builds the ZIP
            $zipName = 'immich-download-' . date('Y-m-d') . '.zip';
            $result = $this->immichService->downloadArchive(array_values(array_map('strval', $assetIds)));
            $response = new DataDownloadResponse(
                $result['body'],
                $zipName,
                'application/zip'
            );
            return $response;
        } catch (\Exception $e) {
            return new JSONResponse(
                ['error' => $e->getMessage()],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function saveToNextcloud(): JSONResponse {
        if (!$this->immichService->isConfigured()) {
            return new JSONResponse(
                ['error' => 'Immich is not configured'],
                Http::STATUS_PRECONDITION_FAILED
            );
        }

        $assetIds = $this->request->getParam('assetIds', []);
        $path = $this->request->getParam('path', '');

        if (empty($assetIds) || !is_array($assetIds)) {
            return new JSONResponse(['error' => 'assetIds must be a non-empty array'], Http::STATUS_BAD_REQUEST);
        }

        if ($path === '' || $path === null) {
            return new JSONResponse(['error' => 'path is required'], Http::STATUS_BAD_REQUEST);
        }

        $userFolder = $this->rootFolder->getUserFolder($this->userId);
        $normalizedPath = ltrim((string)$path, '/');

        try {
            $targetNode = $userFolder->get($normalizedPath);
            if (!($targetNode instanceof Folder)) {
                return new JSONResponse(['error' => 'Path is not a folder'], Http::STATUS_BAD_REQUEST);
            }
        } catch (NotFoundException $e) {
            return new JSONResponse(['error' => 'Folder not found: ' . $normalizedPath], Http::STATUS_NOT_FOUND);
        }

        $saved = 0;
        $failed = 0;
        $errors = [];

        foreach ($assetIds as $assetId) {
            try {
                // Fetch metadata to get the original filename
                $asset = $this->immichService->getAsset((string)$assetId);
                $fileName = $asset['originalFileName'] ?? ($assetId . '.bin');

                // Ensure unique filename in target folder
                $fileName = $this->getUniqueFileName($targetNode, (string)$fileName);

                // Fetch the original binary (works for images and videos)
                $result = $this->immichService->getAssetOriginal((string)$assetId);

                // Write to Nextcloud
                $file = $targetNode->newFile($fileName);
                $file->putContent($result['body']);

                $saved++;
            } catch (\Exception $e) {
                $failed++;
                $errors[] = ['id' => $assetId, 'error' => $e->getMessage()];
            }
        }

        return new JSONResponse(['saved' => $saved, 'failed' => $failed, 'errors' => $errors]);
    }

    private function getUniqueFileName(Folder $folder, string $fileName): string {
        if (!$folder->nodeExists($fileName)) {
            return $fileName;
        }

        $info = pathinfo($fileName);
        $name = $info['filename'];
        $ext = isset($info['extension']) ? '.' . $info['extension'] : '';

        $i = 1;
        do {
            $candidate = $name . ' (' . $i . ')' . $ext;
            $i++;
        } while ($folder->nodeExists($candidate));

        return $candidate;
    }
}
