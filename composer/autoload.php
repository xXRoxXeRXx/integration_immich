<?php
// SPDX-FileCopyrightText: 2026 Marcel Meyer <gh@grenzallee.eu>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

// Register PSR-4 autoloader for this app independent of Nextcloud's autoloader.
// This is required when the app has no vendor/composer/autoload.php.
spl_autoload_register(static function (string $class): void {
	if (!str_starts_with($class, 'OCA\\IntegrationImmich\\')) {
		return;
	}
	$file = __DIR__ . '/../lib/' . str_replace('\\', '/', substr($class, strlen('OCA\\IntegrationImmich\\'))) . '.php';
	if (file_exists($file)) {
		require_once $file;
	}
}, prepend: true);
