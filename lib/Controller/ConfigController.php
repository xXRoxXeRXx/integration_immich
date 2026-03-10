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

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function getConfig(): JSONResponse {
        return new JSONResponse([
            'server_url' => $this->immichService->getServerUrl(),
            'api_key_set' => $this->immichService->getApiKey() !== '',
        ]);
    }

    #[NoAdminRequired]
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

        if ($validate === true || $validate === 'true' || $validate === '1') {
            $result = $this->immichService->validateConnection();
            if (!$result['success']) {
                $errorMsg = $result['error'] ?? 'unknown';
                $this->logger->warning('Immich connection validation failed: ' . $errorMsg, [
                    'app' => Application::APP_ID,
                ]);

                // Detect Nextcloud's SSRF protection blocking local/private IPs
                $isLocalAccessBlocked = str_contains($errorMsg, 'violates local access rules');

                return new JSONResponse(
                    [
                        'error' => 'Connection validation failed',
                        'detail' => $errorMsg,
                        'local_access_blocked' => $isLocalAccessBlocked,
                    ],
                    Http::STATUS_BAD_REQUEST
                );
            }
            return new JSONResponse(['success' => true, 'validation' => $result]);
        }

        return new JSONResponse(['success' => true]);
    }
}
