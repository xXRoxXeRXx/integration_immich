<?php

/**
 * SPDX-FileCopyrightText: 2026 Marcel Meyer <gh@grenzallee.eu>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\IntegrationImmich\Listener;

use OCP\AppFramework\Http\ContentSecurityPolicy;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Security\CSP\AddContentSecurityPolicyEvent;

/**
 * Extends Nextcloud's Content Security Policy to allow loading
 * OpenStreetMap tile images used by the Map view (Leaflet).
 *
 * @implements IEventListener<AddContentSecurityPolicyEvent>
 */
class CspListener implements IEventListener {
    public function handle(Event $event): void {
        if (!($event instanceof AddContentSecurityPolicyEvent)) {
            return;
        }

        $policy = new ContentSecurityPolicy();

        // OpenStreetMap tile servers (a/b/c subdomains)
        $policy->addAllowedImageDomain('https://*.tile.openstreetmap.org');

        $event->addPolicy($policy);
    }
}
