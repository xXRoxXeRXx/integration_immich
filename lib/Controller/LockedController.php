<?php

/**
 * SPDX-FileCopyrightText: 2026 Marcel Meyer <gh@grenzallee.eu>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */


declare(strict_types=1);

namespace OCA\IntegrationImmich\Controller;

use OCA\IntegrationImmich\AppInfo\Application;
use OCA\IntegrationImmich\Service\ImmichHttpException;
use OCA\IntegrationImmich\Service\ImmichService;
use OCA\IntegrationImmich\Service\LockedSession;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\BruteForceProtection;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\UseSession;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\Security\ISecureRandom;
use Psr\Log\LoggerInterface;

/**
 * Opens Immich's Locked Folder: the person signs in to Immich (password or OAuth), then unlocks
 * the session with their PIN. API keys cannot do this (Immich allows it only to sessions).
 */
class LockedController extends Controller {
    /** The OAuth answer's parameters passed back to Immich (iss is checked by some providers). */
    private const OAUTH_PARAMS = ['code', 'state', 'iss', 'scope', 'session_state', 'error', 'error_description'];

    public function __construct(
        IRequest $request,
        private ImmichService $immichService,
        private LockedSession $lockedSession,
        private IURLGenerator $urlGenerator,
        private ISecureRandom $random,
        private LoggerInterface $logger,
    ) {
        parent::__construct(Application::APP_ID, $request);
    }

    private function notConfigured(): JSONResponse {
        return new JSONResponse(['error' => 'Immich is not configured'], Http::STATUS_PRECONDITION_FAILED);
    }

    private function errorResponse(string $context, \Exception $e): JSONResponse {
        $this->logger->error('Immich ' . $context . ' failed: ' . $e->getMessage(), [
            'app' => Application::APP_ID,
            'exception' => $e,
        ]);
        return new JSONResponse(['error' => 'An internal error occurred'], Http::STATUS_INTERNAL_SERVER_ERROR);
    }

    private function callbackUrl(): string {
        return $this->urlGenerator->linkToRouteAbsolute(Application::APP_ID . '.locked.oauthCallback');
    }

    /** The signed-in session's service, or null when the person has not signed in here. */
    private function session(): ?ImmichService {
        $token = $this->lockedSession->token($this->immichService->getServerUrl());
        return $token === null ? null : $this->immichService->withSession($token);
    }

    /**
     * Keeps a new session when it belongs to the API key's own account, else signs it out:
     * the Locked Folder shown must be that of the library shown everywhere else. The key's
     * account shows as the key being among the session's own keys (a key without user.read
     * cannot ask /users/me).
     */
    private function keep(string $token): ?JSONResponse {
        $session = $this->immichService->withSession($token);
        try {
            $keyId = $this->immichService->getApiKeyInfo()['id'] ?? null;
            $sessionKeys = array_column($session->getApiKeys(), 'id');
        } catch (\Exception $e) {
            $this->endSession($session);
            throw $e;
        }
        if ($keyId === null || !in_array($keyId, $sessionKeys, true)) {
            $this->endSession($session);
            return new JSONResponse(
                ['error' => 'This Immich account is not the one of your API key'],
                Http::STATUS_FORBIDDEN
            );
        }
        $this->lockedSession->store($token, $this->immichService->getServerUrl());
        return null;
    }

    private function endSession(ImmichService $session): void {
        try {
            $session->logout();
        } catch (\Exception $e) {
            $this->logger->warning('Immich sign-out failed: ' . $e->getMessage(), ['app' => Application::APP_ID]);
        }
    }

    #[NoAdminRequired]
    #[UseSession]
    public function status(): JSONResponse {
        if (!$this->immichService->isConfigured()) {
            return $this->notConfigured();
        }
        try {
            $features = $this->immichService->getServerFeatures();
            $state = [
                'passwordLogin' => ($features['passwordLogin'] ?? false) === true,
                'oauth' => ($features['oauth'] ?? false) === true,
                'callbackUrl' => $this->callbackUrl(),
                'signedIn' => false,
                'pinCode' => true,
                'unlocked' => false,
                'expiresAt' => null,
            ];
            $session = $this->session();
            if ($session !== null) {
                try {
                    $status = $session->getAuthStatus();
                    $state['signedIn'] = true;
                    $state['pinCode'] = ($status['pinCode'] ?? false) === true;
                    $state['unlocked'] = ($status['isElevated'] ?? false) === true;
                    $state['expiresAt'] = $status['pinExpiresAt'] ?? null;
                } catch (ImmichHttpException $e) {
                    if ($e->getStatus() !== Http::STATUS_UNAUTHORIZED) {
                        throw $e;
                    }
                    // Signed out in Immich meanwhile (e.g. from its list of devices).
                    $this->lockedSession->clear();
                }
            }
            return new JSONResponse($state);
        } catch (\Exception $e) {
            return $this->errorResponse('Locked Folder status', $e);
        }
    }

    #[NoAdminRequired]
    #[UseSession]
    #[BruteForceProtection(action: 'integration_immich_login')]
    public function login(): JSONResponse {
        if (!$this->immichService->isConfigured()) {
            return $this->notConfigured();
        }
        $email = trim((string)$this->request->getParam('email', ''));
        $password = (string)$this->request->getParam('password', '');
        if ($email === '' || $password === '') {
            return new JSONResponse(['error' => 'E-mail and password are required'], Http::STATUS_BAD_REQUEST);
        }
        try {
            $token = $this->immichService->login($email, $password);
            return $this->keep($token) ?? new JSONResponse(['signedIn' => true]);
        } catch (ImmichHttpException $e) {
            if (in_array($e->getStatus(), [Http::STATUS_BAD_REQUEST, Http::STATUS_UNAUTHORIZED], true)) {
                $response = new JSONResponse(['error' => 'Wrong e-mail or password'], Http::STATUS_FORBIDDEN);
                $response->throttle(['action' => 'integration_immich_login']);
                return $response;
            }
            return $this->errorResponse('Locked Folder sign-in', $e);
        } catch (\Exception $e) {
            return $this->errorResponse('Locked Folder sign-in', $e);
        }
    }

    #[NoAdminRequired]
    #[UseSession]
    public function oauthStart(): JSONResponse {
        if (!$this->immichService->isConfigured()) {
            return $this->notConfigured();
        }
        $state = $this->random->generate(32, ISecureRandom::CHAR_ALPHANUMERIC);
        $verifier = $this->random->generate(64, ISecureRandom::CHAR_ALPHANUMERIC);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        $redirectUri = $this->callbackUrl();
        try {
            $url = $this->immichService->oauthAuthorize($redirectUri, $state, $challenge);
            $this->lockedSession->startOAuth($state, $verifier, $redirectUri);
            return new JSONResponse(['url' => $url]);
        } catch (\Exception $e) {
            return $this->errorResponse('Locked Folder OAuth start', $e);
        }
    }

    /** Where the identity provider sends the person back; the page tells them to go back. */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[UseSession]
    public function oauthCallback(): TemplateResponse {
        $outcome = 'failed';
        $pending = $this->lockedSession->takeOAuth((string)$this->request->getParam('state', ''));
        if ($pending !== null && $this->immichService->isConfigured()) {
            $query = [];
            foreach (self::OAUTH_PARAMS as $name) {
                $value = $this->request->getParam($name);
                if (is_string($value)) {
                    $query[$name] = $value;
                }
            }
            try {
                $token = $this->immichService->oauthCallback(
                    $pending['redirectUri'] . '?' . http_build_query($query),
                    (string)$query['state'],
                    $pending['verifier'],
                );
                $outcome = $this->keep($token) === null ? 'signed-in' : 'other-account';
            } catch (\Exception $e) {
                $this->logger->error('Immich OAuth sign-in failed: ' . $e->getMessage(), [
                    'app' => Application::APP_ID,
                    'exception' => $e,
                ]);
            }
        }
        return new TemplateResponse(Application::APP_ID, 'lockedSignIn', [
            'outcome' => $outcome,
        ], TemplateResponse::RENDER_AS_GUEST);
    }

    #[NoAdminRequired]
    #[UseSession]
    #[BruteForceProtection(action: 'integration_immich_pin')]
    public function unlock(): JSONResponse {
        if (!$this->immichService->isConfigured()) {
            return $this->notConfigured();
        }
        $pin = (string)$this->request->getParam('pinCode', '');
        if (!preg_match('/^\d{4,6}$/', $pin)) {
            return new JSONResponse(['error' => 'The PIN has 4 to 6 digits'], Http::STATUS_BAD_REQUEST);
        }
        $session = $this->session();
        if ($session === null) {
            return new JSONResponse(['error' => 'Sign in to Immich first', 'signedIn' => false], Http::STATUS_LOCKED);
        }
        try {
            $session->unlockSession($pin);
            return new JSONResponse(['unlocked' => true]);
        } catch (ImmichHttpException $e) {
            if ($e->getStatus() === Http::STATUS_UNAUTHORIZED) {
                $this->lockedSession->clear();
                return new JSONResponse(['error' => 'Sign in to Immich again', 'signedIn' => false], Http::STATUS_LOCKED);
            }
            if ($e->getStatus() === Http::STATUS_BAD_REQUEST) {
                $response = new JSONResponse(['error' => 'Wrong PIN'], Http::STATUS_FORBIDDEN);
                $response->throttle(['action' => 'integration_immich_pin']);
                return $response;
            }
            return $this->errorResponse('Locked Folder unlock', $e);
        } catch (\Exception $e) {
            return $this->errorResponse('Locked Folder unlock', $e);
        }
    }

    /** Locks the folder again; the sign-in stays, so the PIN alone opens it next time. */
    #[NoAdminRequired]
    public function lock(): JSONResponse {
        if (!$this->immichService->isConfigured()) {
            return $this->notConfigured();
        }
        $session = $this->session();
        if ($session !== null) {
            try {
                $session->lockSession();
            } catch (ImmichHttpException $e) {
                if ($e->getStatus() !== Http::STATUS_UNAUTHORIZED) {
                    return $this->errorResponse('Locked Folder lock', $e);
                }
            } catch (\Exception $e) {
                return $this->errorResponse('Locked Folder lock', $e);
            }
        }
        return new JSONResponse(['locked' => true]);
    }

    /** Locks the folder and signs the session out of Immich (it leaves the list of devices). */
    #[NoAdminRequired]
    #[UseSession]
    public function signOut(): JSONResponse {
        if (!$this->immichService->isConfigured()) {
            return $this->notConfigured();
        }
        $session = $this->session();
        $this->lockedSession->clear();
        if ($session !== null) {
            $this->endSession($session);
        }
        return new JSONResponse(['signedIn' => false]);
    }
}
