<?php

declare(strict_types=1);

namespace OCA\IntegrationImmich\Tests\Unit\Controller;

use OCA\IntegrationImmich\Controller\AssetsController;
use OCA\IntegrationImmich\Service\ImmichService;
use OCP\AppFramework\Http;
use OCP\Files\IRootFolder;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Test\TestCase;

class AssetsControllerTest extends TestCase {

	private AssetsController $controller;
	private ImmichService&MockObject $immichService;
	private IRequest&MockObject $request;
	private LoggerInterface&MockObject $logger;

	protected function setUp(): void {
		parent::setUp();

		$this->immichService = $this->createMock(ImmichService::class);
		$this->request = $this->createMock(IRequest::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->controller = new AssetsController(
			$this->request,
			$this->immichService,
			$this->createMock(IRootFolder::class),
			'testuser',
			$this->logger,
		);
	}

	// --- timeline() ---

	public function testTimelineReturns412WhenNotConfigured(): void {
		$this->immichService->method('isConfigured')->willReturn(false);

		$response = $this->controller->timeline();

		$this->assertEquals(Http::STATUS_PRECONDITION_FAILED, $response->getStatus());
		$this->assertArrayHasKey('error', $response->getData());
	}

	public function testTimelineReturnsBuckets(): void {
		$this->immichService->method('isConfigured')->willReturn(true);
		$this->request->method('getParam')->willReturnMap([
			['timeBucket', null, null],
			['size', 'MONTH', 'MONTH'],
			['personId', null, null],
			['assetType', null, null],
			['isFavorite', null, null],
		]);
		$this->immichService->method('getTimelineBuckets')->willReturn([['count' => 5]]);

		$response = $this->controller->timeline();

		$this->assertEquals(Http::STATUS_OK, $response->getStatus());
		$this->assertIsArray($response->getData());
	}

	public function testTimelineFiltersImageAssets(): void {
		$this->immichService->method('isConfigured')->willReturn(true);
		$this->request->method('getParam')->willReturnMap([
			['timeBucket', null, '2024-01-01T00:00:00.000Z'],
			['size', 'MONTH', 'MONTH'],
			['personId', null, null],
			['assetType', null, 'IMAGE'],
			['isFavorite', null, null],
		]);

		$assets = [
			['id' => 'uuid-1', 'isImage' => true],
			['id' => 'uuid-2', 'isImage' => false],
			['id' => 'uuid-3', 'isImage' => true],
		];
		$this->immichService->method('getTimelineBucket')->willReturn($assets);

		$response = $this->controller->timeline();
		$data = $response->getData();

		$this->assertCount(2, $data);
		$this->assertTrue($data[0]['isImage']);
		$this->assertTrue($data[1]['isImage']);
	}

	public function testTimelineFiltersVideoAssets(): void {
		$this->immichService->method('isConfigured')->willReturn(true);
		$this->request->method('getParam')->willReturnMap([
			['timeBucket', null, '2024-01-01T00:00:00.000Z'],
			['size', 'MONTH', 'MONTH'],
			['personId', null, null],
			['assetType', null, 'VIDEO'],
			['isFavorite', null, null],
		]);

		$assets = [
			['id' => 'uuid-1', 'isImage' => true],
			['id' => 'uuid-2', 'isImage' => false],
		];
		$this->immichService->method('getTimelineBucket')->willReturn($assets);

		$response = $this->controller->timeline();
		$data = $response->getData();

		$this->assertCount(1, $data);
		$this->assertFalse($data[0]['isImage']);
	}

	// --- update() ---

	public function testUpdateReturns412WhenNotConfigured(): void {
		$this->immichService->method('isConfigured')->willReturn(false);

		$response = $this->controller->update('some-id');

		$this->assertEquals(Http::STATUS_PRECONDITION_FAILED, $response->getStatus());
	}

	public function testUpdateReturns400OnInvalidUuid(): void {
		$this->immichService->method('isConfigured')->willReturn(true);

		$response = $this->controller->update('not-a-valid-uuid');

		$this->assertEquals(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertArrayHasKey('error', $response->getData());
	}

	public function testUpdateReturns400WhenNoValidFields(): void {
		$this->immichService->method('isConfigured')->willReturn(true);
		$this->request->method('getParams')->willReturn(['unknownField' => 'value']);

		$response = $this->controller->update('a1b2c3d4-e5f6-7890-abcd-ef1234567890');

		$this->assertEquals(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testUpdateSucceedsWithValidFields(): void {
		$this->immichService->method('isConfigured')->willReturn(true);
		$this->request->method('getParams')->willReturn(['isFavorite' => true]);
		$this->immichService->method('updateAsset')->willReturn(['id' => 'a1b2c3d4-e5f6-7890-abcd-ef1234567890']);

		$response = $this->controller->update('a1b2c3d4-e5f6-7890-abcd-ef1234567890');

		$this->assertEquals(Http::STATUS_OK, $response->getStatus());
	}

	// --- mapMarkers() ---

	public function testMapMarkersReturns412WhenNotConfigured(): void {
		$this->immichService->method('isConfigured')->willReturn(false);

		$response = $this->controller->mapMarkers();

		$this->assertEquals(Http::STATUS_PRECONDITION_FAILED, $response->getStatus());
	}

	public function testMapMarkersReturnsData(): void {
		$this->immichService->method('isConfigured')->willReturn(true);
		$this->immichService->method('getMapMarkers')->willReturn([['lat' => 48.0, 'lon' => 11.0]]);

		$response = $this->controller->mapMarkers();

		$this->assertEquals(Http::STATUS_OK, $response->getStatus());
		$this->assertCount(1, $response->getData());
	}
}
