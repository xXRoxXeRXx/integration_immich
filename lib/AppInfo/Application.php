<?php

/**
 * SPDX-FileCopyrightText: 2026 Marcel Meyer <gh@grenzallee.eu>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */


declare(strict_types=1);

namespace OCA\IntegrationImmich\AppInfo;

use OCA\Files\Event\LoadAdditionalScriptsEvent;
use OCA\IntegrationImmich\Listener\LoadAdditionalScriptsListener;
use OCA\IntegrationImmich\Listener\LogoutListener;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\Security\CSP\AddContentSecurityPolicyEvent;
use OCP\User\Events\BeforeUserLoggedOutEvent;

class Application extends App implements IBootstrap {
    public const APP_ID = 'integration_immich';

    public function __construct(array $urlParams = []) {
        parent::__construct(self::APP_ID, $urlParams);
    }

    public function register(IRegistrationContext $context): void {
        $context->registerEventListener(
            LoadAdditionalScriptsEvent::class,
            LoadAdditionalScriptsListener::class
        );
        $context->registerEventListener(
            AddContentSecurityPolicyEvent::class,
            \OCA\IntegrationImmich\Listener\CspListener::class
        );
        // Signing out of Nextcloud signs the Locked Folder's session out of Immich.
        $context->registerEventListener(
            BeforeUserLoggedOutEvent::class,
            LogoutListener::class
        );
    }

    public function boot(IBootContext $context): void {
    }
}
