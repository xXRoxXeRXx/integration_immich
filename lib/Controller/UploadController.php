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
use OCP\AppFramework\Http\JSONResponse;
use OCP\Files\IRootFolder;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

class UploadController extends Controller {
    public function __construct(
        IRequest $request,
        private ImmichService $immichService,
        private IRootFolder $rootFolder,
        private ?string $userId,
        private LoggerInterface $logger,
    ) {
        parent::__construct(Application::APP_ID, $request);
    }

    #[NoAdminRequired]
    public function upload(): JSONResponse {
        if (!$this->immichService->isConfigured()) {
            return new JSONResponse(
                ['error' => 'Immich is not configured'],
                Http::STATUS_PRECONDITION_FAILED
            );
        }

        $fileId = $this->request->getParam('fileId');
        if (!$fileId) {
            return new JSONResponse(
                ['error' => 'fileId is required'],
                Http::STATUS_BAD_REQUEST
            );
        }

        try {
            $userFolder = $this->rootFolder->getUserFolder($this->userId);
            $files = $userFolder->getById((int)$fileId);

            if (empty($files)) {
                return new JSONResponse(
                    ['error' => 'File not found'],
                    Http::STATUS_NOT_FOUND
                );
            }

            $file = $files[0];

            if (!($file instanceof \OCP\Files\File)) {
                return new JSONResponse(
                    ['error' => 'Not a file'],
                    Http::STATUS_BAD_REQUEST
                );
            }

            $creationTime = $file->getCreationTime() ?: $file->getMTime();
            $result = $this->immichService->uploadAsset(
                $file->getContent(),
                $file->getName(),
                $file->getMimeType(),
                date('c', $creationTime),
                date('c', $file->getMTime()),
            );

            return new JSONResponse($result);
        } catch (\Exception $e) {
            $this->logger->error('Immich upload failed: ' . $e->getMessage(), [
                'app' => Application::APP_ID,
                'exception' => $e,
            ]);
            return new JSONResponse(
                ['error' => 'An internal error occurred'],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }
    }
}
