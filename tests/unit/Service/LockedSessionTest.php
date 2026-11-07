<?php

declare(strict_types=1);

namespace OCA\IntegrationImmich\Tests\Unit\Service;

use OCA\IntegrationImmich\Service\LockedSession;
use OCP\ISession;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Security\ICrypto;
use PHPUnit\Framework\MockObject\MockObject;
use Test\TestCase;

class LockedSessionTest extends TestCase {

	private array $stored = [];
	private IUserSession&MockObject $userSession;
	private LockedSession $lockedSession;

	protected function setUp(): void {
		parent::setUp();

		$session = $this->createMock(ISession::class);
		$session->method('get')->willReturnCallback(fn (string $key) => $this->stored[$key] ?? null);
		$session->method('set')->willReturnCallback(function (string $key, $value): void {
			$this->stored[$key] = $value;
		});
		$session->method('remove')->willReturnCallback(function (string $key): void {
			unset($this->stored[$key]);
		});
		$crypto = $this->createMock(ICrypto::class);
		$crypto->method('encrypt')->willReturnCallback(fn (string $plain) => 'enc:' . $plain);
		$crypto->method('decrypt')->willReturnCallback(fn (string $cipher) => substr($cipher, 4));
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('anna');
		$this->userSession = $this->createMock(IUserSession::class);
		$this->userSession->method('getUser')->willReturn($user);

		$this->lockedSession = new LockedSession($session, $crypto, $this->userSession);
	}

	public function testTokenIsKeptEncryptedForItsServer(): void {
		$this->lockedSession->store('secret-token', 'https://photos.example.com');

		$this->assertSame('enc:secret-token', $this->stored['integration_immich_locked_session']['token']);
		$this->assertSame('secret-token', $this->lockedSession->token('https://photos.example.com'));
		$this->assertNull($this->lockedSession->token('https://other.example.com'));
	}

	public function testClearForgetsTheToken(): void {
		$this->lockedSession->store('secret-token', 'https://photos.example.com');
		$this->lockedSession->clear();

		$this->assertNull($this->lockedSession->token('https://photos.example.com'));
	}

	public function testOAuthStateIsTakenOnceAndMustMatch(): void {
		$this->lockedSession->startOAuth('state-1', 'verifier-1', 'https://cloud.example.com/callback');

		$this->assertNull($this->lockedSession->takeOAuth('state-2'));
		$this->assertNull($this->lockedSession->takeOAuth('state-1'));

		$this->lockedSession->startOAuth('state-1', 'verifier-1', 'https://cloud.example.com/callback');
		$this->assertSame(
			['verifier' => 'verifier-1', 'redirectUri' => 'https://cloud.example.com/callback', 'purpose' => 'locked', 'server' => ''],
			$this->lockedSession->takeOAuth('state-1')
		);
		$this->assertNull($this->lockedSession->takeOAuth('state-1'));
	}

	public function testOAuthRemembersTheAccountsSignInAndItsServer(): void {
		$this->lockedSession->startOAuth('state-1', 'verifier-1', 'https://cloud.example.com/callback', LockedSession::PURPOSE_ACCOUNT, 'https://photos.example.com');

		$pending = $this->lockedSession->takeOAuth('state-1');

		$this->assertSame('account', $pending['purpose']);
		$this->assertSame('https://photos.example.com', $pending['server']);
	}

	public function testOAuthExpires(): void {
		$this->lockedSession->startOAuth('state-1', 'verifier-1', 'https://cloud.example.com/callback');
		$this->stored['integration_immich_locked_oauth']['at'] = time() - 3600;

		$this->assertNull($this->lockedSession->takeOAuth('state-1'));
	}
}
