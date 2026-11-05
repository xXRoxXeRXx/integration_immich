<?php

declare(strict_types=1);

namespace OCA\IntegrationImmich\Tests\Unit\Listener;

use OCA\IntegrationImmich\Listener\LogoutListener;
use OCA\IntegrationImmich\Service\ImmichService;
use OCA\IntegrationImmich\Service\LockedSession;
use OCP\IUser;
use OCP\User\Events\BeforeUserLoggedOutEvent;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Test\TestCase;

class LogoutListenerTest extends TestCase {

	private ImmichService&MockObject $immichService;
	private LockedSession&MockObject $lockedSession;
	private LogoutListener $listener;

	protected function setUp(): void {
		parent::setUp();

		$this->immichService = $this->createMock(ImmichService::class);
		$this->immichService->method('getServerUrl')->willReturn('https://photos.example.com');
		$this->lockedSession = $this->createMock(LockedSession::class);
		$this->listener = new LogoutListener($this->immichService, $this->lockedSession, $this->createMock(LoggerInterface::class));
	}

	public function testSignsTheSessionOutOfImmich(): void {
		$session = $this->createMock(ImmichService::class);
		$this->lockedSession->method('token')->willReturn('token');
		$this->immichService->method('withSession')->with('token')->willReturn($session);
		$this->lockedSession->expects($this->once())->method('clear');
		$session->expects($this->once())->method('logout');

		$this->listener->handle(new BeforeUserLoggedOutEvent($this->createMock(IUser::class)));
	}

	public function testNothingBeforeASignIn(): void {
		$this->lockedSession->expects($this->never())->method('token');
		$this->immichService->expects($this->never())->method('withSession');

		$this->listener->handle(new BeforeUserLoggedOutEvent(null));
	}

	public function testNothingWithoutASession(): void {
		$this->lockedSession->method('token')->willReturn(null);
		$this->immichService->expects($this->never())->method('withSession');

		$this->listener->handle(new BeforeUserLoggedOutEvent($this->createMock(IUser::class)));
	}
}
