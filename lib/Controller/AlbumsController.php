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
use OCP\IRequest;
use Psr\Log\LoggerInterface;

class AlbumsController extends Controller {
    public function __construct(
        IRequest $request,
        private ImmichService $immichService,
        private LoggerInterface $logger,
    ) {
        parent::__construct(Application::APP_ID, $request);
    }

    private function errorResponse(string $context, \Exception $e): JSONResponse {
        $this->logger->error('Immich ' . $context . ' failed: ' . $e->getMessage(), [
            'app' => Application::APP_ID,
            'exception' => $e,
        ]);
        return new JSONResponse(
            ['error' => 'An internal error occurred'],
            Http::STATUS_INTERNAL_SERVER_ERROR
        );
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function index(): JSONResponse {
        if (!$this->immichService->isConfigured()) {
            return new JSONResponse(
                ['error' => 'Immich is not configured'],
                Http::STATUS_PRECONDITION_FAILED
            );
        }

        $assetId = $this->request->getParam('assetId', '');
        if ($assetId !== '' && !preg_match(ImmichService::UUID_PATTERN, $assetId)) {
            return new JSONResponse(['error' => 'Invalid asset ID format'], Http::STATUS_BAD_REQUEST);
        }

        try {
            $albums = $this->immichService->getAlbums($assetId);
            return new JSONResponse($albums);
        } catch (\Exception $e) {
            return $this->errorResponse('albums list', $e);
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
        if (!preg_match(ImmichService::UUID_PATTERN, $id)) {
            return new JSONResponse(['error' => 'Invalid album ID format'], Http::STATUS_BAD_REQUEST);
        }

        try {
            $album = $this->immichService->getAlbum($id);
            return new JSONResponse($album);
        } catch (\Exception $e) {
            return $this->errorResponse('album show', $e);
        }
    }

    #[NoAdminRequired]
    public function create(): JSONResponse {
        if (!$this->immichService->isConfigured()) {
            return new JSONResponse(['error' => 'Immich is not configured'], Http::STATUS_PRECONDITION_FAILED);
        }

        $albumName = $this->request->getParam('albumName', '');
        $assetIds = $this->request->getParam('assetIds', []);

        if (trim($albumName) === '') {
            return new JSONResponse(['error' => 'albumName is required'], Http::STATUS_BAD_REQUEST);
        }

        $assetIds = is_array($assetIds) ? $assetIds : [];
        foreach ($assetIds as $assetId) {
            if (!preg_match(ImmichService::UUID_PATTERN, (string)$assetId)) {
                return new JSONResponse(['error' => 'Invalid asset ID format'], Http::STATUS_BAD_REQUEST);
            }
        }

        try {
            $album = $this->immichService->createAlbum($albumName, $assetIds);
            return new JSONResponse($album, Http::STATUS_CREATED);
        } catch (\Exception $e) {
            return $this->errorResponse('album create', $e);
        }
    }

    #[NoAdminRequired]
    public function delete(string $id): JSONResponse {
        if (!$this->immichService->isConfigured()) {
            return new JSONResponse(['error' => 'Immich is not configured'], Http::STATUS_PRECONDITION_FAILED);
        }
        if (!preg_match(ImmichService::UUID_PATTERN, $id)) {
            return new JSONResponse(['error' => 'Invalid album ID format'], Http::STATUS_BAD_REQUEST);
        }
        try {
            $this->immichService->deleteAlbum($id);
            return new JSONResponse(['success' => true]);
        } catch (\Exception $e) {
            return $this->errorResponse('album delete', $e);
        }
    }

    #[NoAdminRequired]
    public function rename(string $id): JSONResponse {
        if (!$this->immichService->isConfigured()) {
            return new JSONResponse(['error' => 'Immich is not configured'], Http::STATUS_PRECONDITION_FAILED);
        }
        if (!preg_match(ImmichService::UUID_PATTERN, $id)) {
            return new JSONResponse(['error' => 'Invalid album ID format'], Http::STATUS_BAD_REQUEST);
        }
        $albumName = trim($this->request->getParam('albumName', ''));
        if ($albumName === '') {
            return new JSONResponse(['error' => 'albumName is required'], Http::STATUS_BAD_REQUEST);
        }
        try {
            $album = $this->immichService->renameAlbum($id, $albumName);
            return new JSONResponse($album);
        } catch (\Exception $e) {
            return $this->errorResponse('album rename', $e);
        }
    }

    #[NoAdminRequired]
    public function removeAssets(string $id): JSONResponse {
        if (!$this->immichService->isConfigured()) {
            return new JSONResponse(['error' => 'Immich is not configured'], Http::STATUS_PRECONDITION_FAILED);
        }
        if (!preg_match(ImmichService::UUID_PATTERN, $id)) {
            return new JSONResponse(['error' => 'Invalid album ID format'], Http::STATUS_BAD_REQUEST);
        }
        $assetIds = $this->request->getParam('assetIds', []);
        if (!is_array($assetIds) || empty($assetIds)) {
            return new JSONResponse(['error' => 'assetIds must be a non-empty array'], Http::STATUS_BAD_REQUEST);
        }
        foreach ($assetIds as $assetId) {
            if (!preg_match(ImmichService::UUID_PATTERN, (string)$assetId)) {
                return new JSONResponse(['error' => 'Invalid asset ID format'], Http::STATUS_BAD_REQUEST);
            }
        }
        try {
            $result = $this->immichService->removeAssetsFromAlbum($id, $assetIds);
            return new JSONResponse($result);
        } catch (\Exception $e) {
            return $this->errorResponse('album remove assets', $e);
        }
    }

    #[NoAdminRequired]
    public function addAssets(string $id): JSONResponse {
        if (!$this->immichService->isConfigured()) {
            return new JSONResponse(['error' => 'Immich is not configured'], Http::STATUS_PRECONDITION_FAILED);
        }

        if (!preg_match(ImmichService::UUID_PATTERN, $id)) {
            return new JSONResponse(['error' => 'Invalid album ID format'], Http::STATUS_BAD_REQUEST);
        }

        $assetIds = $this->request->getParam('assetIds', []);
        if (!is_array($assetIds) || empty($assetIds)) {
            return new JSONResponse(['error' => 'assetIds must be a non-empty array'], Http::STATUS_BAD_REQUEST);
        }

        foreach ($assetIds as $assetId) {
            if (!preg_match(ImmichService::UUID_PATTERN, (string)$assetId)) {
                return new JSONResponse(['error' => 'Invalid asset ID format'], Http::STATUS_BAD_REQUEST);
            }
        }

        try {
            $result = $this->immichService->addAssetsToAlbum($id, $assetIds);
            return new JSONResponse($result);
        } catch (\Exception $e) {
            return $this->errorResponse('album add assets', $e);
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
        if (!preg_match(ImmichService::UUID_PATTERN, $id)) {
            return new JSONResponse(['error' => 'Invalid album ID format'], Http::STATUS_BAD_REQUEST);
        }

        try {
            $album = $this->immichService->getAlbum($id);
            $thumbnailAssetId = $album['albumThumbnailAssetId'] ?? null;

            if (!$thumbnailAssetId && !empty($album['assets'])) {
                $thumbnailAssetId = $album['assets'][0]['id'];
            }

            if (!$thumbnailAssetId) {
                return new JSONResponse(
                    ['error' => 'No thumbnail available'],
                    Http::STATUS_NOT_FOUND
                );
            }

            $result = $this->immichService->getAssetThumbnail($thumbnailAssetId);
            $response = new DataDownloadResponse(
                $result['body'],
                $id . '.jpg',
                $result['contentType'] ?? 'image/jpeg'
            );
            $response->cacheFor(3600);
            return $response;
        } catch (\Exception $e) {
            return $this->errorResponse('album thumbnail', $e);
        }
    }
}
