<?php

/**
 * SPDX-FileCopyrightText: 2026 Marcel Meyer <gh@grenzallee.eu>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */


declare(strict_types=1);

namespace OCA\IntegrationImmich\AppInfo;

use OCA\Files\Event\LoadAdditionalScriptsEvent;
use OCA\IntegrationImmich\Listener\LoadAdditionalScriptsListener;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

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
    }

    public function boot(IBootContext $context): void {
    }
}
