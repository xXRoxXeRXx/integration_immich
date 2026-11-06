<?php

/**
 * SPDX-FileCopyrightText: 2026 Marcel Meyer <gh@grenzallee.eu>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */


declare(strict_types=1);

namespace OCA\IntegrationImmich\Service;

use OCP\ISession;
use OCP\IUserSession;
use OCP\Security\ICrypto;

/**
 * The Immich session that opens the Locked Folder.
 *
 * An API key cannot read the Locked Folder: Immich requires a session unlocked with the
 * person's PIN. The person signs in to Immich once (password or OAuth); its session token is
 * kept encrypted in this browser's Nextcloud session only, never stored in the database. Locking
 * keeps the sign-in (the PIN alone opens the folder again); it is signed out of Immich with
 * "Sign out of Immich" or when the person signs out of Nextcloud.
 */
class LockedSession {
    private const KEY = 'integration_immich_locked_session';
    private const OAUTH_KEY = 'integration_immich_locked_oauth';

    /** How long an OAuth sign-in may take, in seconds. */
    private const OAUTH_TTL = 600;

    public function __construct(
        private ISession $session,
        private ICrypto $crypto,
        private IUserSession $userSession,
    ) {
    }

    private function userId(): string {
        return $this->userSession->getUser()?->getUID() ?? '';
    }

    /** The Immich session token, when this person signed in to this server in this browser. */
    public function token(string $serverUrl): ?string {
        $stored = $this->session->get(self::KEY);
        if (!is_array($stored)
            || ($stored['user'] ?? null) !== $this->userId()
            || ($stored['server'] ?? null) !== $serverUrl) {
            return null;
        }
        try {
            return $this->crypto->decrypt((string)$stored['token']);
        } catch (\Exception $e) {
            return null;
        }
    }

    public function store(string $token, string $serverUrl): void {
        $this->session->set(self::KEY, [
            'user' => $this->userId(),
            'server' => $serverUrl,
            'token' => $this->crypto->encrypt($token),
        ]);
    }

    public function clear(): void {
        $this->session->remove(self::KEY);
    }

    /** Remembers an OAuth sign-in in progress (its state and PKCE verifier). */
    public function startOAuth(string $state, string $verifier, string $redirectUri): void {
        $this->session->set(self::OAUTH_KEY, [
            'user' => $this->userId(),
            'state' => $state,
            'verifier' => $this->crypto->encrypt($verifier),
            'redirectUri' => $redirectUri,
            'at' => time(),
        ]);
    }

    /**
     * The OAuth sign-in in progress whose state matches, at most once:
     * ['verifier' => ..., 'redirectUri' => ...], or null.
     */
    public function takeOAuth(string $state): ?array {
        $pending = $this->session->get(self::OAUTH_KEY);
        $this->session->remove(self::OAUTH_KEY);
        if (!is_array($pending)
            || ($pending['user'] ?? null) !== $this->userId()
            || time() - (int)($pending['at'] ?? 0) > self::OAUTH_TTL
            || !hash_equals((string)($pending['state'] ?? ''), $state)) {
            return null;
        }
        try {
            return [
                'verifier' => $this->crypto->decrypt((string)$pending['verifier']),
                'redirectUri' => (string)$pending['redirectUri'],
            ];
        } catch (\Exception $e) {
            return null;
        }
    }
}
