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

        $doValidate = $validate === true || $validate === 'true' || $validate === '1';

        // Validate-first: test the new credentials before writing to config so that
        // a failed validation never overwrites a previously-working configuration.
        if ($doValidate) {
            $urlToTest = $serverUrl !== null ? $serverUrl : $this->immichService->getServerUrl();
            $keyToTest = ($apiKey !== null && $apiKey !== '') ? $apiKey : $this->immichService->getApiKey();

            $result = $this->immichService->validateConnectionWithCredentials($urlToTest, $keyToTest);
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
        }

        // Only persist after successful validation (or when validation is not requested).
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

        if ($doValidate) {
            $missingPermissions = $result['missing_permissions'] ?? [];
            if (!empty($missingPermissions)) {
                $this->logger->warning('Immich API key is missing required permissions: ' . implode(', ', $missingPermissions), [
                    'app' => Application::APP_ID,
                ]);
            }

            return new JSONResponse([
                'success' => true,
                'validation' => $result,
                'missing_permissions' => $missingPermissions,
            ]);
        }

        return new JSONResponse(['success' => true]);
    }

    /**
     * Returns the Immich user profile of the currently authenticated API-key owner.
     * The frontend uses this to determine album ownership (show/hide the delete button).
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function me(): JSONResponse {
        if (!$this->immichService->isConfigured()) {
            return new JSONResponse(
                ['error' => 'Immich is not configured'],
                Http::STATUS_PRECONDITION_FAILED
            );
        }
        try {
            $user = $this->immichService->getMe();
            // The frontend only needs the user id for ownership/permission checks.
            // Returning the full payload would expose unnecessary user data.
            return new JSONResponse(['id' => $user['id'] ?? null]);
        } catch (\Exception $e) {
            $this->logger->error('Immich /users/me request failed: ' . $e->getMessage(), [
                'app' => \OCA\IntegrationImmich\AppInfo\Application::APP_ID,
                'exception' => $e,
            ]);
            return new JSONResponse(
                ['error' => 'An internal error occurred'],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }
    }
}
