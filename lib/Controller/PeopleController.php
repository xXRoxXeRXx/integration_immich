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

class PeopleController extends Controller {
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

        try {
            $people = $this->immichService->getPeople();
            return new JSONResponse($people);
        } catch (\Exception $e) {
            return $this->errorResponse('people list', $e);
        }
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function assets(string $id): JSONResponse {
        if (!$this->immichService->isConfigured()) {
            return new JSONResponse(
                ['error' => 'Immich is not configured'],
                Http::STATUS_PRECONDITION_FAILED
            );
        }
        if (!preg_match(ImmichService::UUID_PATTERN, $id)) {
            return new JSONResponse(['error' => 'Invalid person ID format'], Http::STATUS_BAD_REQUEST);
        }

        try {
            $assets = $this->immichService->getPersonAssets($id);
            return new JSONResponse($assets);
        } catch (\Exception $e) {
            return $this->errorResponse('person assets', $e);
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
            return new JSONResponse(['error' => 'Invalid person ID format'], Http::STATUS_BAD_REQUEST);
        }

        try {
            $result = $this->immichService->getPersonThumbnail($id);
            $response = new DataDownloadResponse(
                $result['body'],
                $id . '.jpg',
                $result['contentType'] ?? 'image/jpeg'
            );
            $response->cacheFor(3600);
            return $response;
        } catch (\Exception $e) {
            return $this->errorResponse('person thumbnail', $e);
        }
    }
}
