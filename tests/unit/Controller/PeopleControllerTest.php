<?php

declare(strict_types=1);

namespace OCA\IntegrationImmich\Tests\Unit\Controller;

use OCA\IntegrationImmich\Controller\PeopleController;
use OCA\IntegrationImmich\Service\ImmichService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use Test\TestCase;

class PeopleControllerTest extends TestCase {

	private PeopleController $controller;
	private ImmichService&MockObject $immichService;
	private IRequest&MockObject $request;

	protected function setUp(): void {
		parent::setUp();

		$this->immichService = $this->createMock(ImmichService::class);
		$this->request = $this->createMock(IRequest::class);

		$this->controller = new PeopleController(
			$this->request,
			$this->immichService,
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
			['id' => 'asset-uuid-1'],
		]);

		$response = $this->controller->assets('person-uuid-1');

		$this->assertEquals(Http::STATUS_OK, $response->getStatus());
		$this->assertCount(1, $response->getData());
	}

	public function testAssetsReturns500OnException(): void {
		$this->immichService->method('isConfigured')->willReturn(true);
		$this->immichService->method('getPersonAssets')->willThrowException(new \Exception('Timeout'));

		$response = $this->controller->assets('person-uuid-1');

		$this->assertEquals(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
	}
}
