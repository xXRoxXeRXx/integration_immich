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
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

class ConfigController extends Controller {
    public function __construct(
        IRequest $request,
        private ImmichService $immichService,
        private LoggerInterface $logger,
    ) {
        parent::__construct(Application::APP_ID, $request);
    }

    #[NoCSRFRequired]
    public function getConfig(): JSONResponse {
        return new JSONResponse([
            'server_url' => $this->immichService->getServerUrl(),
            'api_key_set' => $this->immichService->getApiKey() !== '',
        ]);
    }

    public function setConfig(): JSONResponse {
        $serverUrl = $this->request->getParam('server_url');
        $apiKey = $this->request->getParam('api_key');
        $validate = $this->request->getParam('validate', false);

        if ($serverUrl !== null) {
            try {
                $this->immichService->setServerUrl($serverUrl);
            } catch (\InvalidArgumentException $e) {
                return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
            }
        }
        if ($apiKey !== null && $apiKey !== '') {
            $this->immichService->setApiKey($apiKey);
        }

        if ($validate) {
            $result = $this->immichService->validateConnection();
            if (!$result['success']) {
                $this->logger->warning('Immich connection validation failed: ' . ($result['error'] ?? 'unknown'), [
                    'app' => Application::APP_ID,
                ]);
                return new JSONResponse(
                    ['error' => 'Connection validation failed'],
                    Http::STATUS_BAD_REQUEST
                );
            }
            return new JSONResponse(['success' => true, 'validation' => $result]);
        }

        return new JSONResponse(['success' => true]);
    }
}
