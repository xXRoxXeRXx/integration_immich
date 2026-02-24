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

class ConfigController extends Controller {
    public function __construct(
        IRequest $request,
        private ImmichService $immichService,
    ) {
        parent::__construct(Application::APP_ID, $request);
    }

    #[NoCSRFRequired]
    public function getConfig(): JSONResponse {
        $apiKey = $this->immichService->getApiKey();
        return new JSONResponse([
            'server_url' => $this->immichService->getServerUrl(),
            'api_key_set' => $apiKey !== '',
            'api_key_masked' => $apiKey !== '' ? substr($apiKey, 0, 4) . '****' : '',
        ]);
    }

    #[NoCSRFRequired]
    public function setConfig(): JSONResponse {
        $serverUrl = $this->request->getParam('server_url');
        $apiKey = $this->request->getParam('api_key');
        $validate = $this->request->getParam('validate', false);

        if ($serverUrl !== null) {
            $this->immichService->setServerUrl($serverUrl);
        }
        if ($apiKey !== null && $apiKey !== '') {
            $this->immichService->setApiKey($apiKey);
        }

        if ($validate) {
            $result = $this->immichService->validateConnection();
            if (!$result['success']) {
                return new JSONResponse(
                    ['error' => $result['error']],
                    Http::STATUS_BAD_REQUEST
                );
            }
            return new JSONResponse(['success' => true, 'validation' => $result]);
        }

        return new JSONResponse(['success' => true]);
    }
}
