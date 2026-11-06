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
use OCP\AppFramework\Http\Attribute\UseSession;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\Security\ISecureRandom;
use Psr\Log\LoggerInterface;

/**
 * Signs the person in to their Immich account (password or OAuth) instead of an API key. The
 * session is kept (encrypted) in their settings, the password never; the Locked Folder then asks
 * only for the PIN, in a session of its own made from this one for each browser.
 */
class AccountController extends Controller {
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

    private function errorResponse(string $context, \Exception $e): JSONResponse {
        $this->logger->error('Immich ' . $context . ' failed: ' . $e->getMessage(), [
            'app' => Application::APP_ID,
            'exception' => $e,
        ]);
        return new JSONResponse(['error' => 'An internal error occurred'], Http::STATUS_INTERNAL_SERVER_ERROR);
    }

    /** The service for the server given with the request (not saved yet), or the configured one. */
    private function server(): ImmichService {
        $url = trim((string)$this->request->getParam('server_url', ''));
        return $this->immichService->withServerUrl($url !== '' ? $url : $this->immichService->getServerUrl());
    }

    /** Which sign-ins the server offers, and the address OAuth returns to. */
    #[NoAdminRequired]
    public function features(): JSONResponse {
        try {
            $features = $this->server()->getServerFeatures();
            return new JSONResponse([
                'passwordLogin' => ($features['passwordLogin'] ?? false) === true,
                'oauth' => ($features['oauth'] ?? false) === true,
                'callbackUrl' => $this->urlGenerator->linkToRouteAbsolute(Application::APP_ID . '.locked.oauthCallback'),
            ]);
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\Exception $e) {
            return $this->errorResponse('server features', $e);
        }
    }

    #[NoAdminRequired]
    #[BruteForceProtection(action: 'integration_immich_login')]
    public function login(): JSONResponse {
        $email = trim((string)$this->request->getParam('email', ''));
        $password = (string)$this->request->getParam('password', '');
        if ($email === '' || $password === '') {
            return new JSONResponse(['error' => 'E-mail and password are required'], Http::STATUS_BAD_REQUEST);
        }
        try {
            $server = $this->server();
            $token = $server->login($email, $password);
            $this->immichService->storeAccount($server->getServerUrl(), $token);
            return new JSONResponse(['email' => $this->immichService->getAccountEmail()]);
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (ImmichHttpException $e) {
            if (in_array($e->getStatus(), [Http::STATUS_BAD_REQUEST, Http::STATUS_UNAUTHORIZED], true)) {
                $response = new JSONResponse(['error' => 'Wrong e-mail or password'], Http::STATUS_FORBIDDEN);
                $response->throttle(['action' => 'integration_immich_login']);
                return $response;
            }
            return $this->errorResponse('account sign-in', $e);
        } catch (\Exception $e) {
            return $this->errorResponse('account sign-in', $e);
        }
    }

    /** Starts an OAuth sign-in (PKCE); it returns to the Locked Folder's callback, which knows it is this one. */
    #[NoAdminRequired]
    #[UseSession]
    public function oauthStart(): JSONResponse {
        $state = $this->random->generate(32, ISecureRandom::CHAR_ALPHANUMERIC);
        $verifier = $this->random->generate(64, ISecureRandom::CHAR_ALPHANUMERIC);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        $redirectUri = $this->urlGenerator->linkToRouteAbsolute(Application::APP_ID . '.locked.oauthCallback');
        try {
            $server = $this->server();
            $url = $server->oauthAuthorize($redirectUri, $state, $challenge);
            $this->lockedSession->startOAuth($state, $verifier, $redirectUri, LockedSession::PURPOSE_ACCOUNT, $server->getServerUrl());
            return new JSONResponse(['url' => $url]);
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\Exception $e) {
            return $this->errorResponse('account OAuth start', $e);
        }
    }

    /** Signs the account out of Immich; requests go with the API key again, if there is one. */
    #[NoAdminRequired]
    #[UseSession]
    public function signOut(): JSONResponse {
        $this->immichService->signOutAccount();
        // Its child, the Locked Folder's session of this browser, went with it in Immich.
        $this->lockedSession->clear();
        return new JSONResponse(['email' => '']);
    }
}
