<?php

declare(strict_types=1);

namespace OCA\IntegrationImmich\Tests\Unit\Controller;

use OCA\IntegrationImmich\Controller\AssetsController;
use OCA\IntegrationImmich\Service\ImmichHttpException;
use OCA\IntegrationImmich\Service\ImmichService;
use OCA\IntegrationImmich\Service\LockedSession;
use OCP\AppFramework\Http;
use OCP\Files\File;
use OCP\Files\Folder;
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
	private LockedSession&MockObject $lockedSession;

	protected function setUp(): void {
		parent::setUp();

		$this->immichService = $this->createMock(ImmichService::class);
		$this->request = $this->createMock(IRequest::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->lockedSession = $this->createMock(LockedSession::class);

		$this->controller = new AssetsController(
			$this->request,
			$this->immichService,
			$this->createMock(IRootFolder::class),
			'testuser',
			$this->logger,
			$this->lockedSession,
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

	public function testTimelineForwardsPartnerTimelineUserId(): void {
		$userId = 'a1b2c3d4-e5f6-7890-abcd-ef1234567890';
		$this->immichService->method('isConfigured')->willReturn(true);
		$this->request->method('getParam')->willReturnMap([
			['timeBucket', null, null],
			['size', 'MONTH', 'MONTH'],
			['personId', null, null],
			['albumId', null, null],
			['assetType', null, null],
			['userId', null, $userId],
			['withPartners', null, null],
			['isFavorite', null, null],
		]);
		$this->immichService->expects($this->once())
			->method('getTimelineBuckets')
			->with('MONTH', null, null, null, false, $userId, false)
			->willReturn([]);

		$response = $this->controller->timeline();

		$this->assertEquals(Http::STATUS_OK, $response->getStatus());
	}

	public function testTimelineReturns400ForInvalidUserId(): void {
		$this->immichService->method('isConfigured')->willReturn(true);
		$this->request->method('getParam')->willReturnMap([
			['timeBucket', null, null],
			['size', 'MONTH', 'MONTH'],
			['personId', null, null],
			['albumId', null, null],
			['assetType', null, null],
			['userId', null, 'not-a-uuid'],
		]);

		$response = $this->controller->timeline();

		$this->assertEquals(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testPartnersReturnsList(): void {
		$partners = [['id' => 'a1b2c3d4-e5f6-7890-abcd-ef1234567890', 'name' => 'Partner']];
		$this->immichService->method('isConfigured')->willReturn(true);
		$this->immichService->expects($this->once())->method('getPartners')->willReturn($partners);

		$response = $this->controller->partners();

		$this->assertEquals(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($partners, $response->getData());
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

	// --- timeline(visibility) ---

	public function testTimelineForwardsArchiveVisibility(): void {
		$this->immichService->method('isConfigured')->willReturn(true);
		$this->request->method('getParam')->willReturnMap([
			['timeBucket', null, null],
			['size', 'MONTH', 'MONTH'],
			['visibility', null, 'archive'],
		]);
		$this->immichService->expects($this->once())
			->method('getTimelineBuckets')
			->with('MONTH', null, null, null, false, null, false, 'archive')
			->willReturn([['count' => 2]]);

		$response = $this->controller->timeline();

		$this->assertEquals(Http::STATUS_OK, $response->getStatus());
	}

	public function testTimelineIgnoresUnknownVisibility(): void {
		$this->immichService->method('isConfigured')->willReturn(true);
		$this->request->method('getParam')->willReturnMap([
			['timeBucket', null, null],
			['size', 'MONTH', 'MONTH'],
			['visibility', null, 'hidden'],
		]);
		$this->immichService->expects($this->once())
			->method('getTimelineBuckets')
			->with('MONTH', null, null, null, false, null, false, 'timeline')
			->willReturn([]);

		$this->controller->timeline();
	}

	// --- visibility() ---

	public function testVisibilityReturns412WhenNotConfigured(): void {
		$this->immichService->method('isConfigured')->willReturn(false);

		$response = $this->controller->visibility();

		$this->assertEquals(Http::STATUS_PRECONDITION_FAILED, $response->getStatus());
	}

	public function testVisibilityReturns400WithoutAssets(): void {
		$this->immichService->method('isConfigured')->willReturn(true);
		$this->request->method('getParam')->willReturnMap([
			['assetIds', [], []],
			['visibility', '', 'archive'],
		]);

		$response = $this->controller->visibility();

		$this->assertEquals(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testVisibilityReturns400OnInvalidUuid(): void {
		$this->immichService->method('isConfigured')->willReturn(true);
		$this->request->method('getParam')->willReturnMap([
			['assetIds', [], ['not-a-uuid']],
			['visibility', '', 'archive'],
		]);
		$this->immichService->expects($this->never())->method('setAssetsVisibility');

		$response = $this->controller->visibility();

		$this->assertEquals(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testVisibilityReturns400OnUnknownVisibility(): void {
		$this->immichService->method('isConfigured')->willReturn(true);
		$this->request->method('getParam')->willReturnMap([
			['assetIds', [], ['a1b2c3d4-e5f6-7890-abcd-ef1234567890']],
			['visibility', '', 'hidden'],
		]);
		$this->immichService->expects($this->never())->method('setAssetsVisibility');

		$response = $this->controller->visibility();

		$this->assertEquals(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testVisibilityMovesAssetsToLockedFolder(): void {
		$ids = ['a1b2c3d4-e5f6-7890-abcd-ef1234567890', 'b1b2c3d4-e5f6-7890-abcd-ef1234567890'];
		$this->immichService->method('isConfigured')->willReturn(true);
		$this->request->method('getParam')->willReturnMap([
			['assetIds', [], $ids],
			['visibility', '', 'locked'],
		]);
		$this->immichService->expects($this->once())
			->method('setAssetsVisibility')
			->with($ids, 'locked');

		$response = $this->controller->visibility();

		$this->assertEquals(Http::STATUS_OK, $response->getStatus());
	}

	// --- the Locked Folder ---

	public function testLockedRequestWithoutSignInIsLocked(): void {
		$this->immichService->method('isConfigured')->willReturn(true);
		$this->request->method('getParam')->willReturnMap([['locked', null, '1']]);
		$this->lockedSession->method('token')->willReturn(null);

		$response = $this->controller->thumbnail('a1b2c3d4-e5f6-7890-abcd-ef1234567890');

		$this->assertEquals(Http::STATUS_LOCKED, $response->getStatus());
		$this->assertTrue($response->getData()['locked']);
	}

	public function testLockedTimelineUsesTheSession(): void {
		$session = $this->createMock(ImmichService::class);
		$this->immichService->method('isConfigured')->willReturn(true);
		$this->immichService->method('getServerUrl')->willReturn('https://photos.example.com');
		$this->immichService->method('withSession')->with('token')->willReturn($session);
		$this->immichService->expects($this->never())->method('getTimelineBuckets');
		$this->lockedSession->method('token')->with('https://photos.example.com')->willReturn('token');
		$this->request->method('getParam')->willReturnMap([
			['locked', null, '1'],
			['timeBucket', null, null],
			['size', 'MONTH', 'MONTH'],
			['visibility', null, 'locked'],
		]);
		$session->expects($this->once())
			->method('getTimelineBuckets')
			->with('MONTH', null, null, null, false, null, false, 'locked')
			->willReturn([['count' => 1]]);

		$response = $this->controller->timeline();

		$this->assertEquals(Http::STATUS_OK, $response->getStatus());
	}

	public function testLockedVisibilityNeedsTheLockedRequest(): void {
		$this->immichService->method('isConfigured')->willReturn(true);
		$this->request->method('getParam')->willReturnMap([
			['timeBucket', null, null],
			['size', 'MONTH', 'MONTH'],
			['visibility', null, 'locked'],
		]);
		$this->immichService->expects($this->once())
			->method('getTimelineBuckets')
			->with('MONTH', null, null, null, false, null, false, 'timeline')
			->willReturn([]);

		$this->controller->timeline();
	}

	public function testExpiredPinAnswersLocked(): void {
		$session = $this->createMock(ImmichService::class);
		$this->immichService->method('isConfigured')->willReturn(true);
		$this->immichService->method('withSession')->willReturn($session);
		$this->lockedSession->method('token')->willReturn('token');
		$this->request->method('getParam')->willReturnMap([['locked', null, '1']]);
		$session->method('getAssetThumbnail')->willThrowException(new ImmichHttpException(401, 'Elevated permission is required'));

		$response = $this->controller->thumbnail('a1b2c3d4-e5f6-7890-abcd-ef1234567890');

		$this->assertEquals(Http::STATUS_LOCKED, $response->getStatus());
	}

	public function testLockedThumbnailIsNotCached(): void {
		$session = $this->createMock(ImmichService::class);
		$this->immichService->method('isConfigured')->willReturn(true);
		$this->immichService->method('withSession')->willReturn($session);
		$this->lockedSession->method('token')->willReturn('token');
		$this->request->method('getParam')->willReturnMap([['locked', null, '1'], ['size', 'thumbnail', 'thumbnail']]);
		$session->method('getAssetThumbnail')->willReturn(['body' => 'jpeg', 'contentType' => 'image/jpeg']);

		$response = $this->controller->thumbnail('a1b2c3d4-e5f6-7890-abcd-ef1234567890');

		$this->assertEquals(Http::STATUS_OK, $response->getStatus());
		$this->assertStringNotContainsString('max-age=3600', $response->getHeaders()['Cache-Control'] ?? '');
	}

	public function testLockedVideoAnswersLockedWhenThePinLapsed(): void {
		$session = $this->createMock(ImmichService::class);
		$this->immichService->method('isConfigured')->willReturn(true);
		$this->immichService->method('withSession')->willReturn($session);
		$this->lockedSession->method('token')->willReturn('token');
		$this->request->method('getParam')->willReturnMap([['locked', null, '1']]);
		$this->request->method('getHeader')->willReturn('');
		$session->method('getVideoStream')->willReturn([
			'body' => '', 'statusCode' => 401, 'contentType' => 'application/json',
			'contentLength' => '', 'contentRange' => '', 'acceptRanges' => '',
		]);

		$response = $this->controller->videoStream('a1b2c3d4-e5f6-7890-abcd-ef1234567890');

		$this->assertEquals(Http::STATUS_LOCKED, $response->getStatus());
		$this->assertTrue($response->getData()['locked']);
	}

	public function testVideoPassesOnUnauthorizedOutsideTheLockedFolder(): void {
		$this->immichService->method('isConfigured')->willReturn(true);
		$this->request->method('getHeader')->willReturn('');
		$this->immichService->method('getVideoStream')->willReturn([
			'body' => '', 'statusCode' => 401, 'contentType' => 'application/json',
			'contentLength' => '', 'contentRange' => '', 'acceptRanges' => '',
		]);

		$response = $this->controller->videoStream('a1b2c3d4-e5f6-7890-abcd-ef1234567890');

		$this->assertEquals(Http::STATUS_UNAUTHORIZED, $response->getStatus());
	}

	// --- saveToNextcloud() ---

	/** A controller whose user folder holds the folder "Photos", which takes the new files. */
	private function saveController(Folder&MockObject $target): AssetsController {
		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('get')->with('Photos')->willReturn($target);
		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->method('getUserFolder')->willReturn($userFolder);
		return new AssetsController(
			$this->request,
			$this->immichService,
			$rootFolder,
			'testuser',
			$this->logger,
			$this->lockedSession,
		);
	}

	public function testLockedSaveStopsWhenThePinLapsedAndNamesWhatWasSaved(): void {
		$first = 'a1b2c3d4-e5f6-7890-abcd-ef1234567890';
		$second = 'b1b2c3d4-e5f6-7890-abcd-ef1234567890';
		$third = 'c1b2c3d4-e5f6-7890-abcd-ef1234567890';
		$session = $this->createMock(ImmichService::class);
		$this->immichService->method('isConfigured')->willReturn(true);
		$this->immichService->method('withSession')->willReturn($session);
		$this->lockedSession->method('token')->willReturn('token');
		$this->request->method('getParam')->willReturnMap([
			['locked', null, '1'],
			['assetIds', [], [$first, $second, $third]],
			['path', '', 'Photos'],
		]);
		$session->method('getAsset')->willReturnCallback(static function (string $id) use ($second): array {
			if ($id === $second) {
				throw new ImmichHttpException(401, 'Elevated permission is required');
			}
			return ['originalFileName' => $id . '.jpg'];
		});
		$session->method('getAssetOriginal')->willReturn(['body' => 'jpeg']);
		$target = $this->createMock(Folder::class);
		$target->method('nodeExists')->willReturn(false);
		// Only the first file is written; the third is not tried once the session is locked.
		$target->expects($this->once())->method('newFile')->with($first . '.jpg')
			->willReturn($this->createMock(File::class));

		$response = $this->saveController($target)->saveToNextcloud();

		$this->assertEquals(Http::STATUS_LOCKED, $response->getStatus());
		$data = $response->getData();
		$this->assertTrue($data['locked']);
		$this->assertSame(1, $data['saved']);
		$this->assertSame([$first], $data['savedIds']);
	}

	public function testSaveCountsOtherFailuresAndGoesOn(): void {
		$first = 'a1b2c3d4-e5f6-7890-abcd-ef1234567890';
		$second = 'b1b2c3d4-e5f6-7890-abcd-ef1234567890';
		$this->immichService->method('isConfigured')->willReturn(true);
		$this->request->method('getParam')->willReturnMap([
			['assetIds', [], [$first, $second]],
			['path', '', 'Photos'],
		]);
		$this->immichService->method('getAsset')->willReturnCallback(static function (string $id) use ($first): array {
			if ($id === $first) {
				throw new ImmichHttpException(401);
			}
			return ['originalFileName' => $id . '.jpg'];
		});
		$this->immichService->method('getAssetOriginal')->willReturn(['body' => 'jpeg']);
		$target = $this->createMock(Folder::class);
		$target->method('nodeExists')->willReturn(false);
		$target->method('newFile')->willReturn($this->createMock(File::class));

		$response = $this->saveController($target)->saveToNextcloud();

		$this->assertEquals(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		$this->assertSame(1, $data['saved']);
		$this->assertSame([$second], $data['savedIds']);
		$this->assertSame(1, $data['failed']);
	}

	// --- searchLocation() ---

	public function testSearchLocationReturns412WhenNotConfigured(): void {
		$this->immichService->method('isConfigured')->willReturn(false);

		$response = $this->controller->searchLocation();

		$this->assertEquals(Http::STATUS_PRECONDITION_FAILED, $response->getStatus());
		$this->assertArrayHasKey('error', $response->getData());
	}

	public function testSearchLocationReturns400WhenFieldMissing(): void {
		$this->immichService->method('isConfigured')->willReturn(true);
		$this->request->method('getParam')->willReturnMap([
			['field', '', ''],
			['value', '', 'Berlin'],
		]);

		$response = $this->controller->searchLocation();

		$this->assertEquals(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertArrayHasKey('error', $response->getData());
	}

	public function testSearchLocationReturns400WhenValueMissing(): void {
		$this->immichService->method('isConfigured')->willReturn(true);
		$this->request->method('getParam')->willReturnMap([
			['field', '', 'city'],
			['value', '', ''],
		]);

		$response = $this->controller->searchLocation();

		$this->assertEquals(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertArrayHasKey('error', $response->getData());
	}

	public function testSearchLocationReturns400OnInvalidField(): void {
		$this->immichService->method('isConfigured')->willReturn(true);
		$this->request->method('getParam')->willReturnMap([
			['field', '', 'exifInfo.make'],
			['value', '', 'Sony'],
		]);

		$response = $this->controller->searchLocation();

		$this->assertEquals(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$data = $response->getData();
		$this->assertArrayHasKey('error', $data);
		$this->assertEquals('Invalid field', $data['error']);
	}

	/**
	 * @dataProvider allowedLocationFieldsProvider
	 */
	public function testSearchLocationSucceedsForAllowedFields(string $field): void {
		$assets = [['id' => 'uuid-1', 'city' => 'Berlin']];

		$this->immichService->method('isConfigured')->willReturn(true);
		$this->request->method('getParam')->willReturnMap([
			['field', '', $field],
			['value', '', 'Berlin'],
		]);
		$this->immichService
			->expects($this->once())
			->method('searchByLocation')
			->with($field, 'Berlin')
			->willReturn($assets);

		$response = $this->controller->searchLocation();

		$this->assertEquals(Http::STATUS_OK, $response->getStatus());
		$this->assertCount(1, $response->getData());
	}

	public static function allowedLocationFieldsProvider(): array {
		return [
			['exifInfo.city'],
			['exifInfo.country'],
			['exifInfo.state'],
			['city'],
			['country'],
			['state'],
		];
	}

	public function testSearchLocationPropagatesServiceException(): void {
		$this->immichService->method('isConfigured')->willReturn(true);
		$this->request->method('getParam')->willReturnMap([
			['field', '', 'city'],
			['value', '', 'Berlin'],
		]);
		$this->immichService
			->method('searchByLocation')
			->willThrowException(new \Exception('Connection failed'));

		$response = $this->controller->searchLocation();

		$this->assertGreaterThanOrEqual(400, $response->getStatus());
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
