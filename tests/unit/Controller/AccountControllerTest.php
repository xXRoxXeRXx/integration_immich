<?php

declare(strict_types=1);

namespace OCA\IntegrationImmich\Tests\Unit\Controller;

use OCA\IntegrationImmich\Controller\AccountController;
use OCA\IntegrationImmich\Service\ImmichHttpException;
use OCA\IntegrationImmich\Service\ImmichService;
use OCA\IntegrationImmich\Service\LockedSession;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Test\TestCase;

class AccountControllerTest extends TestCase {

	private const SERVER = 'https://photos.example.com';
	private const CALLBACK = 'https://cloud.example.com/apps/integration_immich/api/v1/locked/oauth/callback';

	private AccountController $controller;
	private ImmichService&MockObject $immichService;
	private ImmichService&MockObject $server;
	private LockedSession&MockObject $lockedSession;
	private IRequest&MockObject $request;
	private array $params = [];

	protected function setUp(): void {
		parent::setUp();

		$this->immichService = $this->createMock(ImmichService::class);
		$this->immichService->method('getServerUrl')->willReturn('');
		// The server given with the request, not saved yet.
		$this->server = $this->createMock(ImmichService::class);
		$this->server->method('getServerUrl')->willReturn(self::SERVER);
		$this->immichService->method('withServerUrl')->willReturnCallback(function (string $url): ImmichService {
			if ($url !== self::SERVER) {
				throw new \InvalidArgumentException('Invalid server URL: missing host');
			}
			return $this->server;
		});
		$this->lockedSession = $this->createMock(LockedSession::class);
		$this->request = $this->createMock(IRequest::class);
		$this->request->method('getParam')->willReturnCallback(fn (string $name, $default = null) => $this->params[$name] ?? $default);
		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('linkToRouteAbsolute')->willReturn(self::CALLBACK);
		$random = $this->createMock(ISecureRandom::class);
		$random->method('generate')->willReturnCallback(fn (int $length) => str_repeat('a', $length));

		$this->controller = new AccountController(
			$this->request,
			$this->immichService,
			$this->lockedSession,
			$urlGenerator,
			$random,
			$this->createMock(LoggerInterface::class),
		);
	}

	public function testFeaturesOfTheServerInTheForm(): void {
		$this->params = ['server_url' => self::SERVER];
		$this->server->method('getServerFeatures')->willReturn(['passwordLogin' => false, 'oauth' => true]);

		$data = $this->controller->features()->getData();

		$this->assertFalse($data['passwordLogin']);
		$this->assertTrue($data['oauth']);
		$this->assertSame(self::CALLBACK, $data['callbackUrl']);
	}

	public function testLoginStoresTheSessionForTheServer(): void {
		$this->params = ['server_url' => self::SERVER, 'email' => 'anna@example.org', 'password' => 'secret'];
		$this->server->expects($this->once())->method('login')->with('anna@example.org', 'secret')->willReturn('token');
		$this->immichService->expects($this->once())->method('storeAccount')->with(self::SERVER, 'token');
		$this->immichService->method('getAccountEmail')->willReturn('anna@example.org');

		$response = $this->controller->login();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('anna@example.org', $response->getData()['email']);
	}

	public function testLoginNeedsBothFields(): void {
		$this->params = ['server_url' => self::SERVER, 'email' => 'anna@example.org'];
		$this->server->expects($this->never())->method('login');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $this->controller->login()->getStatus());
	}

	public function testLoginRefusesAnInvalidServer(): void {
		$this->params = ['server_url' => 'https://', 'email' => 'anna@example.org', 'password' => 'secret'];
		$this->immichService->expects($this->never())->method('storeAccount');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $this->controller->login()->getStatus());
	}

	public function testWrongPasswordIsThrottled(): void {
		$this->params = ['server_url' => self::SERVER, 'email' => 'anna@example.org', 'password' => 'wrong'];
		$this->server->method('login')->willThrowException(new ImmichHttpException(401));
		$this->immichService->expects($this->never())->method('storeAccount');

		$response = $this->controller->login();

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertTrue($response->isThrottled());
	}

	public function testOAuthStartRemembersTheAccountsSignInAndItsServer(): void {
		$this->params = ['server_url' => self::SERVER];
		$verifier = str_repeat('a', 64);
		$challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
		$this->server->expects($this->once())
			->method('oauthAuthorize')
			->with(self::CALLBACK, str_repeat('a', 32), $challenge)
			->willReturn('https://auth.example.com/authorize?x=1');
		$this->lockedSession->expects($this->once())
			->method('startOAuth')
			->with(str_repeat('a', 32), $verifier, self::CALLBACK, LockedSession::PURPOSE_ACCOUNT, self::SERVER);

		$this->assertSame('https://auth.example.com/authorize?x=1', $this->controller->oauthStart()->getData()['url']);
	}

	public function testSignOutEndsTheAccountAndThisBrowsersLockedFolderSession(): void {
		$this->immichService->expects($this->once())->method('signOutAccount');
		$this->lockedSession->expects($this->once())->method('clear');

		$this->assertSame(Http::STATUS_OK, $this->controller->signOut()->getStatus());
	}
}
