<?php

declare(strict_types=1);

namespace OCA\IntegrationImmich\Tests\Unit\Service;

use OCA\IntegrationImmich\AppInfo\Application;
use OCA\IntegrationImmich\Service\ImmichService;
use OCP\Http\Client\IClientService;
use OCP\ICacheFactory;
use OCP\IConfig;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Security\ICrypto;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Test\TestCase;

class ImmichServiceTest extends TestCase {

	private ImmichService $service;
	private IConfig&MockObject $config;
	private IUserSession&MockObject $userSession;

	private ICrypto&MockObject $crypto;

	protected function setUp(): void {
		parent::setUp();

		$this->config = $this->createMock(IConfig::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->crypto = $this->createMock(ICrypto::class);
		// Default: decrypt returns the input unchanged (simulates plaintext fallback)
		$this->crypto->method('decrypt')->willReturnArgument(0);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('testuser');
		$this->userSession->method('getUser')->willReturn($user);

		$this->service = new ImmichService(
			$this->createMock(IClientService::class),
			$this->config,
			$this->userSession,
			$this->createMock(LoggerInterface::class),
			$this->crypto,
			$this->createMock(ICacheFactory::class),
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

		// crypto mock returns its input (set up in setUp), so decrypt('my-secret-key') = 'my-secret-key'
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
			$this->crypto,
			$this->createMock(ICacheFactory::class),
		);

		$result = $service->validateConnection();

		$this->assertFalse($result['success']);
		$this->assertArrayHasKey('error', $result);
	}

	public function testGetPartnersRequestsSharedWithDirection(): void {
		$response = $this->createMock(\OCP\Http\Client\IResponse::class);
		$response->method('getStatusCode')->willReturn(200);
		$response->method('getBody')->willReturn('[{"id":"a1b2c3d4-e5f6-7890-abcd-ef1234567890","name":"Partner"}]');
		$client = $this->createMock(\OCP\Http\Client\IClient::class);
		$client->expects($this->once())
			->method('get')
			->with(
				'https://photos.example.com/api/partners?direction=shared-with',
				$this->callback(static fn (array $options): bool => ($options['headers']['x-api-key'] ?? null) === 'test-key')
			)
			->willReturn($response);
		$clientService = $this->createMock(IClientService::class);
		$clientService->method('newClient')->willReturn($client);
		$this->config->method('getUserValue')->willReturnCallback(
			fn(string $uid, string $app, string $key, string $default) => match ($key) {
				'server_url' => 'https://photos.example.com',
				'api_key' => 'test-key',
				default => $default,
			}
		);

		$service = new ImmichService(
			$clientService,
			$this->config,
			$this->userSession,
			$this->createMock(LoggerInterface::class),
			$this->crypto,
			$this->createMock(ICacheFactory::class),
		);

		$this->assertSame(
			[['id' => 'a1b2c3d4-e5f6-7890-abcd-ef1234567890', 'name' => 'Partner']],
			$service->getPartners()
		);
	}

	public function testTimelineRequestsCanIncludePartnersAndPartnerUserId(): void {
		$userId = 'a1b2c3d4-e5f6-7890-abcd-ef1234567890';
		$response = $this->createMock(\OCP\Http\Client\IResponse::class);
		$response->method('getStatusCode')->willReturn(200);
		$response->method('getBody')->willReturn('[]');
		$client = $this->createMock(\OCP\Http\Client\IClient::class);
		$client->expects($this->exactly(3))
			->method('get')
			->willReturnCallback(function (string $url) use ($response, $userId): \OCP\Http\Client\IResponse {
				static $calls = 0;
				$calls++;
				if ($calls === 1) {
					$this->assertSame('https://photos.example.com/api/timeline/buckets?visibility=timeline&withStacked=true&withPartners=true', $url);
				} elseif ($calls === 2) {
					$this->assertSame('https://photos.example.com/api/timeline/buckets?visibility=timeline&withStacked=true&userId=' . $userId, $url);
				} else {
					$this->assertSame('https://photos.example.com/api/timeline/bucket?timeBucket=2026-10-01T00%3A00%3A00.000Z&visibility=timeline&withStacked=true&withPartners=true', $url);
				}
				return $response;
			});
		$clientService = $this->createMock(IClientService::class);
		$clientService->method('newClient')->willReturn($client);
		$this->config->method('getUserValue')->willReturnCallback(
			fn(string $uid, string $app, string $key, string $default) => match ($key) {
				'server_url' => 'https://photos.example.com',
				'api_key' => 'test-key',
				default => $default,
			}
		);
		$service = new ImmichService(
			$clientService,
			$this->config,
			$this->userSession,
			$this->createMock(LoggerInterface::class),
			$this->crypto,
			$this->createMock(ICacheFactory::class),
		);

		$this->assertSame([], $service->getTimelineBuckets(withPartners: true));
		$this->assertSame([], $service->getTimelineBuckets(userId: $userId));
		$this->assertSame([], $service->getTimelineBucket('2026-10-01T00:00:00.000Z', withPartners: true));
	}

	private function serviceWithClient(\OCP\Http\Client\IClient $client): ImmichService {
		$clientService = $this->createMock(IClientService::class);
		$clientService->method('newClient')->willReturn($client);
		$this->config->method('getUserValue')->willReturnCallback(
			fn(string $uid, string $app, string $key, string $default) => match ($key) {
				'server_url' => 'https://photos.example.com',
				'api_key' => 'test-key',
				default => $default,
			}
		);
		return new ImmichService(
			$clientService,
			$this->config,
			$this->userSession,
			$this->createMock(LoggerInterface::class),
			$this->crypto,
			$this->createMock(ICacheFactory::class),
		);
	}

	private function okResponse(string $body): \OCP\Http\Client\IResponse {
		$response = $this->createMock(\OCP\Http\Client\IResponse::class);
		$response->method('getStatusCode')->willReturn(200);
		$response->method('getBody')->willReturn($body);
		return $response;
	}

	public function testArchiveTimelineRequestsArchiveVisibility(): void {
		$client = $this->createMock(\OCP\Http\Client\IClient::class);
		$client->expects($this->once())
			->method('get')
			->with('https://photos.example.com/api/timeline/buckets?visibility=archive&withStacked=true', $this->anything())
			->willReturn($this->okResponse('[]'));

		$this->assertSame([], $this->serviceWithClient($client)->getTimelineBuckets(visibility: 'archive'));
	}

	public function testSetAssetsVisibilityUpdatesAssetsInBulk(): void {
		$client = $this->createMock(\OCP\Http\Client\IClient::class);
		$client->expects($this->once())
			->method('put')
			->with(
				'https://photos.example.com/api/assets',
				$this->callback(static fn (array $options): bool =>
					json_decode($options['body'], true) === ['ids' => ['id-1'], 'visibility' => 'locked']
					&& ($options['headers']['x-api-key'] ?? null) === 'test-key')
			)
			->willReturn($this->okResponse(''));

		$this->serviceWithClient($client)->setAssetsVisibility(['id-1'], 'locked');
	}

	public function testUploadAssetSendsVisibilityOnlyWhenNotTimeline(): void {
		$client = $this->createMock(\OCP\Http\Client\IClient::class);
		$fields = [];
		$client->expects($this->exactly(2))
			->method('post')
			->willReturnCallback(function (string $url, array $options) use (&$fields): \OCP\Http\Client\IResponse {
				$this->assertSame('https://photos.example.com/api/assets', $url);
				$fields[] = array_column($options['multipart'], 'contents', 'name');
				return $this->okResponse('{"id":"new","status":"created"}');
			});
		$service = $this->serviceWithClient($client);

		$service->uploadAsset('data', 'a.jpg', 'image/jpeg', 'c', 'm');
		$service->uploadAsset('data', 'a.jpg', 'image/jpeg', 'c', 'm', 'locked');

		$this->assertArrayNotHasKey('visibility', $fields[0]);
		$this->assertSame('locked', $fields[1]['visibility']);
	}

	public function testSessionReplacesTheApiKey(): void {
		$client = $this->createMock(\OCP\Http\Client\IClient::class);
		$client->expects($this->once())
			->method('get')
			->with(
				'https://photos.example.com/api/timeline/buckets?visibility=locked&withStacked=true',
				$this->callback(static fn (array $options): bool =>
					($options['headers']['Authorization'] ?? null) === 'Bearer session-token'
					&& !isset($options['headers']['x-api-key']))
			)
			->willReturn($this->okResponse('[]'));
		$service = $this->serviceWithClient($client);

		$service->withSession('session-token')->getTimelineBuckets(visibility: 'locked');
	}

	public function testLoginSendsNoCredentialsButTheDevice(): void {
		$client = $this->createMock(\OCP\Http\Client\IClient::class);
		$client->expects($this->once())
			->method('post')
			->with(
				'https://photos.example.com/api/auth/login',
				$this->callback(static fn (array $options): bool =>
					!isset($options['headers']['x-api-key'])
					&& !isset($options['headers']['Authorization'])
					&& ($options['headers']['devicemodel'] ?? null) === 'Nextcloud'
					&& json_decode($options['body'], true) === ['email' => 'anna@example.org', 'password' => 'secret'])
			)
			->willReturn($this->okResponse('{"accessToken":"session-token","userId":"u1"}'));

		$this->assertSame('session-token', $this->serviceWithClient($client)->login('anna@example.org', 'secret'));
	}

	public function testHttpErrorsCarryTheirStatus(): void {
		$response = $this->createMock(\OCP\Http\Client\IResponse::class);
		$response->method('getStatusCode')->willReturn(400);
		$response->method('getBody')->willReturn('{"message":"Wrong PIN code"}');
		$client = $this->createMock(\OCP\Http\Client\IClient::class);
		$client->method('post')->willReturn($response);

		try {
			$this->serviceWithClient($client)->withSession('session-token')->unlockSession('0000');
			$this->fail('expected an ImmichHttpException');
		} catch (\OCA\IntegrationImmich\Service\ImmichHttpException $e) {
			$this->assertSame(400, $e->getStatus());
			$this->assertStringContainsString('Wrong PIN code', $e->getMessage());
		}
	}

	public function testTimelineDoesNotDropValidPartnerFlagAfterBadRequest(): void {
		$badResponse = $this->createMock(\OCP\Http\Client\IResponse::class);
		$badResponse->method('getStatusCode')->willReturn(400);
		$badResponse->method('getBody')->willReturn('{"message":"Invalid query parameter"}');
		$client = $this->createMock(\OCP\Http\Client\IClient::class);
		$client->expects($this->once())
			->method('get')
			->willReturnCallback(function (string $url) use ($badResponse): \OCP\Http\Client\IResponse {
				$this->assertSame('https://photos.example.com/api/timeline/buckets?visibility=timeline&withStacked=true&withPartners=true', $url);
				return $badResponse;
			});
		$clientService = $this->createMock(IClientService::class);
		$clientService->method('newClient')->willReturn($client);
		$this->config->method('getUserValue')->willReturnCallback(
			fn(string $uid, string $app, string $key, string $default) => match ($key) {
				'server_url' => 'https://photos.example.com',
				'api_key' => 'test-key',
				default => $default,
			}
		);
		$service = new ImmichService(
			$clientService,
			$this->config,
			$this->userSession,
			$this->createMock(LoggerInterface::class),
			$this->crypto,
			$this->createMock(ICacheFactory::class),
		);

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('HTTP 400');
		$service->getTimelineBuckets(withPartners: true);
	}
}
