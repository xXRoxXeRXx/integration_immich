<?php

/**
 * SPDX-FileCopyrightText: 2026 Marcel Meyer <gh@grenzallee.eu>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\IntegrationImmich\Listener;

use OCA\Files\Event\LoadAdditionalScriptsEvent;
use OCA\IntegrationImmich\AppInfo\Application;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Util;

/** @template-implements IEventListener<LoadAdditionalScriptsEvent> */
class LoadAdditionalScriptsListener implements IEventListener {
    public function handle(Event $event): void {
        if (!$event instanceof LoadAdditionalScriptsEvent) {
            return;
        }

        // @nextcloud/files v4 (used in fileAction.js) only works with NC33+.
        // NC26-32 requires the v3 bundle which uses the old positional-args API.
        $ncMajorVersion = (int) Util::getVersion()[0];
        if ($ncMajorVersion >= 33) {
            Util::addScript(Application::APP_ID, 'integration_immich-fileAction');
        } else {
            Util::addScript(Application::APP_ID, 'integration_immich-fileAction-nc32');
        }
    }
}
