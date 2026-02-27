<?php

declare(strict_types=1);

namespace OCA\IntegrationImmich\Tests\Unit;

use OCA\IntegrationImmich\AppInfo\Application;
use Test\TestCase;

class ApplicationTest extends TestCase {

	public function testAppId(): void {
		$this->assertEquals('integration_immich', Application::APP_ID);
	}

	public function testAppCanBeInstantiated(): void {
		$app = new Application();
		$this->assertInstanceOf(Application::class, $app);
	}
}
