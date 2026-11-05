<?php

declare(strict_types=1);

namespace OCA\IntegrationImmich\Tests\Unit\Controller;

use OCA\IntegrationImmich\Controller\LockedController;
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

class LockedControllerTest extends TestCase {

	private const SERVER = 'https://photos.example.com';
	private const CALLBACK = 'https://cloud.example.com/apps/integration_immich/api/v1/locked/oauth/callback';

	private LockedController $controller;
	private ImmichService&MockObject $immichService;
	private ImmichService&MockObject $session;
	private LockedSession&MockObject $lockedSession;
	private IRequest&MockObject $request;

	protected function setUp(): void {
		parent::setUp();

		$this->immichService = $this->createMock(ImmichService::class);
		$this->immichService->method('isConfigured')->willReturn(true);
		$this->immichService->method('getServerUrl')->willReturn(self::SERVER);
		$this->immichService->method('getServerFeatures')->willReturn(['passwordLogin' => true, 'oauth' => true]);
		$this->immichService->method('getApiKeyInfo')->willReturn(['id' => 'key-1']);
		$this->session = $this->createMock(ImmichService::class);
		$this->immichService->method('withSession')->willReturn($this->session);
		$this->lockedSession = $this->createMock(LockedSession::class);
		$this->request = $this->createMock(IRequest::class);
		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('linkToRouteAbsolute')->willReturn(self::CALLBACK);
		$random = $this->createMock(ISecureRandom::class);
		$random->method('generate')->willReturnCallback(fn (int $length) => str_repeat('a', $length));

		$this->controller = new LockedController(
			$this->request,
			$this->immichService,
			$this->lockedSession,
			$urlGenerator,
			$random,
			$this->createMock(LoggerInterface::class),
		);
	}

	public function testStatusWithoutSignIn(): void {
		$this->lockedSession->method('token')->willReturn(null);

		$data = $this->controller->status()->getData();

		$this->assertTrue($data['passwordLogin']);
		$this->assertTrue($data['oauth']);
		$this->assertFalse($data['signedIn']);
		$this->assertFalse($data['unlocked']);
		$this->assertSame(self::CALLBACK, $data['callbackUrl']);
	}

	public function testStatusOfAnUnlockedSession(): void {
		$this->lockedSession->method('token')->with(self::SERVER)->willReturn('token');
		$this->session->method('getAuthStatus')->willReturn([
			'pinCode' => true, 'isElevated' => true, 'pinExpiresAt' => '2026-11-05T12:00:00.000Z',
		]);

		$data = $this->controller->status()->getData();

		$this->assertTrue($data['signedIn']);
		$this->assertTrue($data['unlocked']);
		$this->assertSame('2026-11-05T12:00:00.000Z', $data['expiresAt']);
	}

	public function testStatusTellsTheSecondsLeftByTheServersClock(): void {
		$this->lockedSession->method('token')->willReturn('token');
		$this->session->method('getAuthStatus')->willReturn([
			'pinCode' => true, 'isElevated' => true, 'pinExpiresAt' => gmdate('Y-m-d\\TH:i:s.000\\Z', time() + 600),
		]);

		$expiresIn = $this->controller->status()->getData()['expiresIn'];

		$this->assertGreaterThanOrEqual(598, $expiresIn);
		$this->assertLessThanOrEqual(600, $expiresIn);
	}

	public function testStatusOfAPastOrMissingExpiry(): void {
		$this->lockedSession->method('token')->willReturn('token');
		$this->session->method('getAuthStatus')->willReturnOnConsecutiveCalls(
			['pinCode' => true, 'isElevated' => false, 'pinExpiresAt' => '2020-01-01T00:00:00.000Z'],
			['pinCode' => true, 'isElevated' => false],
		);

		$this->assertSame(0, $this->controller->status()->getData()['expiresIn']);
		$this->assertNull($this->controller->status()->getData()['expiresIn']);
	}

	public function testStatusForgetsASessionSignedOutInImmich(): void {
		$this->lockedSession->method('token')->willReturn('token');
		$this->session->method('getAuthStatus')->willThrowException(new ImmichHttpException(401));
		$this->lockedSession->expects($this->once())->method('clear');

		$data = $this->controller->status()->getData();

		$this->assertFalse($data['signedIn']);
	}

	public function testLoginKeepsTheSessionOfTheKeysAccount(): void {
		$this->request->method('getParam')->willReturnMap([
			['email', '', 'anna@example.org'],
			['password', '', 'secret'],
		]);
		$this->immichService->expects($this->once())->method('login')->with('anna@example.org', 'secret')->willReturn('token');
		$this->session->method('getApiKeys')->willReturn([['id' => 'key-0'], ['id' => 'key-1']]);
		$this->lockedSession->expects($this->once())->method('store')->with('token', self::SERVER);

		$response = $this->controller->login();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testLoginRefusesAnotherAccountAndSignsItOut(): void {
		$this->request->method('getParam')->willReturnMap([
			['email', '', 'ben@example.org'],
			['password', '', 'secret'],
		]);
		$this->immichService->method('login')->willReturn('token');
		$this->session->method('getApiKeys')->willReturn([['id' => 'key-9']]);
		$this->session->expects($this->once())->method('logout');
		$this->lockedSession->expects($this->never())->method('store');

		$response = $this->controller->login();

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}

	public function testWrongPasswordIsThrottled(): void {
		$this->request->method('getParam')->willReturnMap([
			['email', '', 'anna@example.org'],
			['password', '', 'wrong'],
		]);
		$this->immichService->method('login')->willThrowException(new ImmichHttpException(401));

		$response = $this->controller->login();

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertTrue($response->isThrottled());
	}

	public function testOAuthStartUsesPkceAndRemembersTheState(): void {
		$verifier = str_repeat('a', 64);
		$challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
		$this->immichService->expects($this->once())
			->method('oauthAuthorize')
			->with(self::CALLBACK, str_repeat('a', 32), $challenge)
			->willReturn('https://auth.example.com/authorize?x=1');
		$this->lockedSession->expects($this->once())
			->method('startOAuth')
			->with(str_repeat('a', 32), $verifier, self::CALLBACK);

		$data = $this->controller->oauthStart()->getData();

		$this->assertSame('https://auth.example.com/authorize?x=1', $data['url']);
	}

	public function testOAuthCallbackCompletesTheSignIn(): void {
		$this->request->method('getParam')->willReturnCallback(fn (string $name, $default = null) => match ($name) {
			'state' => 'state-1',
			'code' => 'code-1',
			'iss' => 'https://auth.example.com',
			default => $default,
		});
		$this->lockedSession->method('takeOAuth')->with('state-1')
			->willReturn(['verifier' => 'verifier-1', 'redirectUri' => self::CALLBACK]);
		$this->immichService->expects($this->once())
			->method('oauthCallback')
			->with(self::CALLBACK . '?code=code-1&state=state-1&iss=https%3A%2F%2Fauth.example.com', 'state-1', 'verifier-1')
			->willReturn('token');
		$this->session->method('getApiKeys')->willReturn([['id' => 'key-0'], ['id' => 'key-1']]);
		$this->lockedSession->expects($this->once())->method('store')->with('token', self::SERVER);

		$response = $this->controller->oauthCallback();

		$this->assertSame('signed-in', $response->getParams()['outcome']);
	}

	public function testOAuthCallbackWithAnUnknownStateFails(): void {
		$this->request->method('getParam')->willReturnCallback(fn (string $name, $default = null) => $name === 'state' ? 'forged' : $default);
		$this->lockedSession->method('takeOAuth')->willReturn(null);
		$this->immichService->expects($this->never())->method('oauthCallback');

		$this->assertSame('failed', $this->controller->oauthCallback()->getParams()['outcome']);
	}

	public function testUnlockRejectsMalformedPin(): void {
		$this->request->method('getParam')->willReturnMap([['pinCode', '', '12a4']]);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $this->controller->unlock()->getStatus());
	}

	public function testUnlockNeedsTheSignIn(): void {
		$this->request->method('getParam')->willReturnMap([['pinCode', '', '1234']]);
		$this->lockedSession->method('token')->willReturn(null);

		$response = $this->controller->unlock();

		$this->assertSame(Http::STATUS_LOCKED, $response->getStatus());
		$this->assertFalse($response->getData()['signedIn']);
	}

	public function testUnlockWithTheRightPin(): void {
		$this->request->method('getParam')->willReturnMap([['pinCode', '', '1234']]);
		$this->lockedSession->method('token')->willReturn('token');
		$this->session->expects($this->once())->method('unlockSession')->with('1234');

		$this->assertSame(Http::STATUS_OK, $this->controller->unlock()->getStatus());
	}

	public function testWrongPinIsThrottled(): void {
		$this->request->method('getParam')->willReturnMap([['pinCode', '', '0000']]);
		$this->lockedSession->method('token')->willReturn('token');
		$this->session->method('unlockSession')->willThrowException(new ImmichHttpException(400, 'Wrong PIN code'));

		$response = $this->controller->unlock();

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertTrue($response->isThrottled());
	}

	public function testLockKeepsTheSignIn(): void {
		$this->lockedSession->method('token')->willReturn('token');
		$this->lockedSession->expects($this->never())->method('clear');
		$this->session->expects($this->once())->method('lockSession');
		$this->session->expects($this->never())->method('logout');

		$this->assertSame(Http::STATUS_OK, $this->controller->lock()->getStatus());
	}

	public function testSignOutEndsTheImmichSession(): void {
		$this->lockedSession->method('token')->willReturn('token');
		$this->lockedSession->expects($this->once())->method('clear');
		$this->session->expects($this->once())->method('logout');

		$this->assertSame(Http::STATUS_OK, $this->controller->signOut()->getStatus());
	}
}
