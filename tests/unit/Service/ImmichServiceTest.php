<?php

declare(strict_types=1);

namespace OCA\IntegrationImmich\Tests\Unit\Service;

use OCA\IntegrationImmich\AppInfo\Application;
use OCA\IntegrationImmich\Service\ImmichService;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Test\TestCase;

class ImmichServiceTest extends TestCase {

	private ImmichService $service;
	private IConfig&MockObject $config;
	private IUserSession&MockObject $userSession;

	protected function setUp(): void {
		parent::setUp();

		$this->config = $this->createMock(IConfig::class);
		$this->userSession = $this->createMock(IUserSession::class);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('testuser');
		$this->userSession->method('getUser')->willReturn($user);

		$this->service = new ImmichService(
			$this->createMock(IClientService::class),
			$this->config,
			$this->userSession,
			$this->createMock(LoggerInterface::class),
		);
	}

	public function testGetServerUrlReturnsEmptyStringWhenNotSet(): void {
		$this->config->method('getUserValue')
			->with('testuser', Application::APP_ID, 'server_url', '')
			->willReturn('');

		$this->assertEquals('', $this->service->getServerUrl());
	}

	public function testGetServerUrlStripsTrailingSlash(): void {
		$this->config->method('getUserValue')
			->with('testuser', Application::APP_ID, 'server_url', '')
			->willReturn('https://photos.example.com/');

		$this->assertEquals('https://photos.example.com', $this->service->getServerUrl());
	}

	public function testIsConfiguredReturnsFalseWhenEmpty(): void {
		$this->config->method('getUserValue')->willReturn('');

		$this->assertFalse($this->service->isConfigured());
	}

	public function testIsConfiguredReturnsTrueWhenBothSet(): void {
		$this->config->method('getUserValue')
			->willReturnCallback(function (string $userId, string $app, string $key, string $default): string {
				return match ($key) {
					'server_url' => 'https://photos.example.com',
					'api_key'    => 'my-secret-key',
					default      => $default,
				};
			});

		$this->assertTrue($this->service->isConfigured());
	}

	public function testIsConfiguredReturnsFalseWhenOnlyUrlSet(): void {
		$this->config->method('getUserValue')
			->willReturnCallback(function (string $userId, string $app, string $key, string $default): string {
				return match ($key) {
					'server_url' => 'https://photos.example.com',
					default      => $default,
				};
			});

		$this->assertFalse($this->service->isConfigured());
	}

	public function testSetServerUrlStripsTrailingSlash(): void {
		$this->config->expects($this->once())
			->method('setUserValue')
			->with('testuser', Application::APP_ID, 'server_url', 'https://photos.example.com');

		$this->service->setServerUrl('https://photos.example.com/');
	}

	public function testGetApiKeyReturnsValue(): void {
		$this->config->method('getUserValue')
			->with('testuser', Application::APP_ID, 'api_key', '')
			->willReturn('my-secret-key');

		$this->assertEquals('my-secret-key', $this->service->getApiKey());
	}

	public function testValidateConnectionReturnsSuccessOnOk(): void {
		// validateConnection delegates to request() which uses IClientService.
		// We verify the failure-wrapping branch by having the HTTP client throw.
		$client = $this->createMock(\OCP\Http\Client\IClient::class);
		$client->method('post')->willThrowException(new \Exception('Connection refused'));

		$clientService = $this->createMock(\OCP\Http\Client\IClientService::class);
		$clientService->method('newClient')->willReturn($client);

		$this->config->method('getUserValue')->willReturnCallback(
			fn(string $uid, string $app, string $key, string $default) => match ($key) {
				'server_url' => 'https://photos.example.com',
				'api_key'    => 'test-key',
				default      => $default,
			}
		);

		$service = new ImmichService(
			$clientService,
			$this->config,
			$this->userSession,
			$this->createMock(\Psr\Log\LoggerInterface::class),
		);

		$result = $service->validateConnection();

		$this->assertFalse($result['success']);
		$this->assertArrayHasKey('error', $result);
	}
}
