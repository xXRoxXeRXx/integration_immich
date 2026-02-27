<?php

declare(strict_types=1);

namespace OCA\IntegrationImmich\Tests\Unit\Controller;

use OCA\IntegrationImmich\Controller\AlbumsController;
use OCA\IntegrationImmich\Service\ImmichService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use Test\TestCase;

class AlbumsControllerTest extends TestCase {

	private AlbumsController $controller;
	private ImmichService&MockObject $immichService;
	private IRequest&MockObject $request;

	private const VALID_UUID   = 'a1b2c3d4-e5f6-7890-abcd-ef1234567890';
	private const INVALID_UUID = 'not-a-valid-uuid';

	protected function setUp(): void {
		parent::setUp();

		$this->immichService = $this->createMock(ImmichService::class);
		$this->request = $this->createMock(IRequest::class);

		$this->controller = new AlbumsController(
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

	public function testIndexReturns400OnInvalidAssetId(): void {
		$this->immichService->method('isConfigured')->willReturn(true);
		$this->request->method('getParam')->with('assetId', '')->willReturn(self::INVALID_UUID);

		$response = $this->controller->index();

		$this->assertEquals(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testIndexReturnsAlbums(): void {
		$this->immichService->method('isConfigured')->willReturn(true);
		$this->request->method('getParam')->with('assetId', '')->willReturn('');
		$this->immichService->method('getAlbums')->willReturn([['id' => self::VALID_UUID, 'albumName' => 'Test']]);

		$response = $this->controller->index();

		$this->assertEquals(Http::STATUS_OK, $response->getStatus());
		$this->assertCount(1, $response->getData());
	}

	// --- show() ---

	public function testShowReturns400OnInvalidId(): void {
		$this->immichService->method('isConfigured')->willReturn(true);

		$response = $this->controller->show(self::INVALID_UUID);

		$this->assertEquals(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testShowReturnsAlbum(): void {
		$this->immichService->method('isConfigured')->willReturn(true);
		$this->immichService->method('getAlbum')->willReturn(['id' => self::VALID_UUID]);

		$response = $this->controller->show(self::VALID_UUID);

		$this->assertEquals(Http::STATUS_OK, $response->getStatus());
	}

	// --- create() ---

	public function testCreateReturns400WhenAlbumNameEmpty(): void {
		$this->immichService->method('isConfigured')->willReturn(true);
		$this->request->method('getParam')->willReturnMap([
			['albumName', '', '   '],
			['assetIds', [], []],
		]);

		$response = $this->controller->create();

		$this->assertEquals(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testCreateReturns400OnInvalidAssetId(): void {
		$this->immichService->method('isConfigured')->willReturn(true);
		$this->request->method('getParam')->willReturnMap([
			['albumName', '', 'My Album'],
			['assetIds', [], [self::INVALID_UUID]],
		]);

		$response = $this->controller->create();

		$this->assertEquals(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testCreateReturns201OnSuccess(): void {
		$this->immichService->method('isConfigured')->willReturn(true);
		$this->request->method('getParam')->willReturnMap([
			['albumName', '', 'My Album'],
			['assetIds', [], []],
		]);
		$this->immichService->method('createAlbum')->willReturn(['id' => self::VALID_UUID]);

		$response = $this->controller->create();

		$this->assertEquals(Http::STATUS_CREATED, $response->getStatus());
	}

	// --- delete() ---

	public function testDeleteReturns400OnInvalidId(): void {
		$this->immichService->method('isConfigured')->willReturn(true);

		$response = $this->controller->delete(self::INVALID_UUID);

		$this->assertEquals(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testDeleteReturnsSuccess(): void {
		$this->immichService->method('isConfigured')->willReturn(true);
		$this->immichService->method('deleteAlbum')->willReturn(null);

		$response = $this->controller->delete(self::VALID_UUID);

		$this->assertEquals(Http::STATUS_OK, $response->getStatus());
		$this->assertTrue($response->getData()['success']);
	}

	// --- rename() ---

	public function testRenameReturns400WhenNameEmpty(): void {
		$this->immichService->method('isConfigured')->willReturn(true);
		$this->request->method('getParam')->with('albumName', '')->willReturn('');

		$response = $this->controller->rename(self::VALID_UUID);

		$this->assertEquals(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testRenameSucceeds(): void {
		$this->immichService->method('isConfigured')->willReturn(true);
		$this->request->method('getParam')->with('albumName', '')->willReturn('New Name');
		$this->immichService->method('renameAlbum')->willReturn(['id' => self::VALID_UUID, 'albumName' => 'New Name']);

		$response = $this->controller->rename(self::VALID_UUID);

		$this->assertEquals(Http::STATUS_OK, $response->getStatus());
	}

	// --- removeAssets() ---

	public function testRemoveAssetsReturns400WhenArrayEmpty(): void {
		$this->immichService->method('isConfigured')->willReturn(true);
		$this->request->method('getParam')->with('assetIds', [])->willReturn([]);

		$response = $this->controller->removeAssets(self::VALID_UUID);

		$this->assertEquals(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testRemoveAssetsReturns400OnInvalidAssetId(): void {
		$this->immichService->method('isConfigured')->willReturn(true);
		$this->request->method('getParam')->with('assetIds', [])->willReturn([self::INVALID_UUID]);

		$response = $this->controller->removeAssets(self::VALID_UUID);

		$this->assertEquals(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	// --- addAssets() ---

	public function testAddAssetsReturns400WhenArrayEmpty(): void {
		$this->immichService->method('isConfigured')->willReturn(true);
		$this->request->method('getParam')->with('assetIds', [])->willReturn([]);

		$response = $this->controller->addAssets(self::VALID_UUID);

		$this->assertEquals(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testAddAssetsSucceeds(): void {
		$this->immichService->method('isConfigured')->willReturn(true);
		$this->request->method('getParam')->with('assetIds', [])->willReturn([self::VALID_UUID]);
		$this->immichService->method('addAssetsToAlbum')->willReturn([['success' => true]]);

		$response = $this->controller->addAssets(self::VALID_UUID);

		$this->assertEquals(Http::STATUS_OK, $response->getStatus());
	}
}
