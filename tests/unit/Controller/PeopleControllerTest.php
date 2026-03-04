<?php

declare(strict_types=1);

namespace OCA\IntegrationImmich\Tests\Unit\Controller;

use OCA\IntegrationImmich\Controller\PeopleController;
use OCA\IntegrationImmich\Service\ImmichService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Test\TestCase;

class PeopleControllerTest extends TestCase {

	private PeopleController $controller;
	private ImmichService&MockObject $immichService;
	private IRequest&MockObject $request;
	private LoggerInterface&MockObject $logger;

	private const VALID_UUID = 'a1b2c3d4-e5f6-7890-abcd-ef1234567890';

	protected function setUp(): void {
		parent::setUp();

		$this->immichService = $this->createMock(ImmichService::class);
		$this->request = $this->createMock(IRequest::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->controller = new PeopleController(
			$this->request,
			$this->immichService,
			$this->logger,
		);
	}

	// --- index() ---

	public function testIndexReturns412WhenNotConfigured(): void {
		$this->immichService->method('isConfigured')->willReturn(false);

		$response = $this->controller->index();

		$this->assertEquals(Http::STATUS_PRECONDITION_FAILED, $response->getStatus());
	}

	public function testIndexReturnsPeople(): void {
		$this->immichService->method('isConfigured')->willReturn(true);
		$this->immichService->method('getPeople')->willReturn([
			['id' => 'uuid-1', 'name' => 'Alice'],
			['id' => 'uuid-2', 'name' => 'Bob'],
		]);

		$response = $this->controller->index();

		$this->assertEquals(Http::STATUS_OK, $response->getStatus());
		$this->assertCount(2, $response->getData());
	}

	public function testIndexReturns500OnException(): void {
		$this->immichService->method('isConfigured')->willReturn(true);
		$this->immichService->method('getPeople')->willThrowException(new \Exception('Connection error'));

		$response = $this->controller->index();

		$this->assertEquals(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertArrayHasKey('error', $response->getData());
	}

	// --- assets() ---

	public function testAssetsReturns412WhenNotConfigured(): void {
		$this->immichService->method('isConfigured')->willReturn(false);

		$response = $this->controller->assets('some-person-id');

		$this->assertEquals(Http::STATUS_PRECONDITION_FAILED, $response->getStatus());
	}

	public function testAssetsReturnsPersonAssets(): void {
		$this->immichService->method('isConfigured')->willReturn(true);
		$this->immichService->method('getPersonAssets')->willReturn([
			['id' => self::VALID_UUID],
		]);

		$response = $this->controller->assets(self::VALID_UUID);

		$this->assertEquals(Http::STATUS_OK, $response->getStatus());
		$this->assertCount(1, $response->getData());
	}

	public function testAssetsReturns500OnException(): void {
		$this->immichService->method('isConfigured')->willReturn(true);
		$this->immichService->method('getPersonAssets')->willThrowException(new \Exception('Timeout'));

		$response = $this->controller->assets(self::VALID_UUID);

		$this->assertEquals(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
	}
}
