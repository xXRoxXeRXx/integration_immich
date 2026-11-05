<?php

/**
 * SPDX-FileCopyrightText: 2026 Marcel Meyer <gh@grenzallee.eu>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\IntegrationImmich\Listener;

use OCA\IntegrationImmich\AppInfo\Application;
use OCA\IntegrationImmich\Service\ImmichService;
use OCA\IntegrationImmich\Service\LockedSession;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\User\Events\BeforeUserLoggedOutEvent;
use Psr\Log\LoggerInterface;

/**
 * Signs the Locked Folder's Immich session out when the person signs out of Nextcloud, so that
 * it does not stay in their list of Immich devices.
 *
 * @implements IEventListener<BeforeUserLoggedOutEvent>
 */
class LogoutListener implements IEventListener {
    public function __construct(
        private ImmichService $immichService,
        private LockedSession $lockedSession,
        private LoggerInterface $logger,
    ) {
    }

    public function handle(Event $event): void {
        // Nextcloud also signs out before each sign-in, when nobody is signed in yet.
        if (!($event instanceof BeforeUserLoggedOutEvent) || $event->getUser() === null) {
            return;
        }
        try {
            $token = $this->lockedSession->token($this->immichService->getServerUrl());
            if ($token === null) {
                return;
            }
            $this->lockedSession->clear();
            $this->immichService->withSession($token)->logout();
        } catch (\Exception $e) {
            $this->logger->warning('Immich sign-out at logout failed: ' . $e->getMessage(), [
                'app' => Application::APP_ID,
            ]);
        }
    }
}
