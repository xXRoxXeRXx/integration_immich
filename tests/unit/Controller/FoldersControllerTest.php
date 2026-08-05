<?php

declare(strict_types=1);

namespace OCA\IntegrationImmich\Tests\Unit\Controller;

use OCA\IntegrationImmich\Controller\FoldersController;
use OCA\IntegrationImmich\Service\ImmichService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Test\TestCase;

class FoldersControllerTest extends TestCase {

	private FoldersController $controller;
	private ImmichService&MockObject $immichService;
	private IRequest&MockObject $request;
	private LoggerInterface&MockObject $logger;

	protected function setUp(): void {
		parent::setUp();

		$this->immichService = $this->createMock(ImmichService::class);
		$this->request       = $this->createMock(IRequest::class);
		$this->logger        = $this->createMock(LoggerInterface::class);

		$this->controller = new FoldersController(
			$this->request,
			$this->immichService,
			$this->logger,
		);
	}

	// --- content(): not configured ---

	public function testContentReturns412WhenNotConfigured(): void {
		$this->immichService->method('isConfigured')->willReturn(false);

		$response = $this->controller->content();

		$this->assertEquals(Http::STATUS_PRECONDITION_FAILED, $response->getStatus());
	}

	// --- content(): path validation ---

	public function testContentRejects400ForDotDotSegment(): void {
		$this->immichService->method('isConfigured')->willReturn(true);
		$this->request->method('getParam')->willReturn('/foo/../bar');

		$response = $this->controller->content();

		$this->assertEquals(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertArrayHasKey('error', $response->getData());
	}

	public function testContentRejects400ForLeadingDotDot(): void {
		$this->immichService->method('isConfigured')->willReturn(true);
		$this->request->method('getParam')->willReturn('../etc/passwd');

		$response = $this->controller->content();

		$this->assertEquals(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testContentAllowsLegitimateNameContainingDots(): void {
		$this->immichService->method('isConfigured')->willReturn(true);
		$this->request->method('getParam')->willReturn('/foo..bar/baz');
		$this->immichService->method('getUniqueFolderPaths')->willReturn([]);
		$this->immichService->method('getFolderAssets')->willReturn([]);

		$response = $this->controller->content();

		$this->assertEquals(Http::STATUS_OK, $response->getStatus());
	}

	// --- content(): path normalisation ---

	public function testContentNormalisesPathWithoutLeadingSlash(): void {
		$this->immichService->method('isConfigured')->willReturn(true);
		$this->request->method('getParam')->willReturn('DCIM/Camera');
		$this->immichService->method('getUniqueFolderPaths')->willReturn(['/DCIM/Camera']);
		$this->immichService
			->expects($this->once())
			->method('getFolderAssets')
			->with('/DCIM/Camera')
			->willReturn([]);

		$response = $this->controller->content();

		$this->assertEquals(Http::STATUS_OK, $response->getStatus());
	}

	public function testContentNormalisesTrailingSlash(): void {
		$this->immichService->method('isConfigured')->willReturn(true);
		$this->request->method('getParam')->willReturn('/DCIM/Camera/');
		$this->immichService->method('getUniqueFolderPaths')->willReturn(['/DCIM/Camera']);
		$this->immichService
			->expects($this->once())
			->method('getFolderAssets')
			->with('/DCIM/Camera')
			->willReturn([]);

		$this->controller->content();
	}

	// --- content(): auto base-path detection ---

	public function testContentAutoDetectsBasePathWhenAtRoot(): void {
		$this->immichService->method('isConfigured')->willReturn(true);
		$this->request->method('getParam')->willReturn('/');

		$uniquePaths = [
			'/usr/src/app/upload/library/user1/DCIM/Camera',
			'/usr/src/app/upload/library/user1/Photos',
		];
		$this->immichService->method('getUniqueFolderPaths')->willReturn($uniquePaths);
		$this->immichService->method('getFolderAssets')->willReturn([]);

		$response = $this->controller->content();
		$data     = $response->getData();

		$this->assertEquals(Http::STATUS_OK, $response->getStatus());
		$this->assertEquals('/usr/src/app/upload/library/user1', $data['basePath']);
		$this->assertEquals('/usr/src/app/upload/library/user1', $data['currentPath']);
		// Should expose DCIM and Photos as immediate subdirs
		$dirNames = array_column($data['folders'], 'name');
		$this->assertContains('DCIM', $dirNames);
		$this->assertContains('Photos', $dirNames);
	}

	public function testContentBasePathIsSortedAlphabetically(): void {
		$this->immichService->method('isConfigured')->willReturn(true);
		$this->request->method('getParam')->willReturn('/');

		$this->immichService->method('getUniqueFolderPaths')->willReturn([
			'/library/Zebra',
			'/library/Apple',
			'/library/Mango',
		]);
		$this->immichService->method('getFolderAssets')->willReturn([]);

		$data     = $this->controller->content()->getData();
		$dirNames = array_column($data['folders'], 'name');

		$this->assertEquals(['Apple', 'Mango', 'Zebra'], $dirNames);
	}

	// --- content(): happy path ---

	public function testContentReturnsSubdirsAndAssets(): void {
		$this->immichService->method('isConfigured')->willReturn(true);
		$this->request->method('getParam')->willReturn('/DCIM');
		$this->immichService->method('getUniqueFolderPaths')->willReturn([
			'/DCIM/Camera',
			'/DCIM/WhatsApp',
		]);
		$this->immichService->method('getFolderAssets')->willReturn([
			['id' => 'asset-uuid-1'],
		]);

		$response = $this->controller->content();
		$data     = $response->getData();

		$this->assertEquals(Http::STATUS_OK, $response->getStatus());
		$this->assertCount(2, $data['folders']);
		$this->assertCount(1, $data['assets']);
		$this->assertEquals('/DCIM', $data['currentPath']);
	}

	public function testContentReturnsEmptyFoldersForLeafPath(): void {
		$this->immichService->method('isConfigured')->willReturn(true);
		$this->request->method('getParam')->willReturn('/DCIM/Camera');
		$this->immichService->method('getUniqueFolderPaths')->willReturn(['/DCIM/Camera']);
		$this->immichService->method('getFolderAssets')->willReturn([['id' => 'uuid-1']]);

		$data = $this->controller->content()->getData();

		$this->assertEmpty($data['folders']);
		$this->assertCount(1, $data['assets']);
	}

	// --- content(): error handling ---

	public function testContentReturns500OnException(): void {
		$this->immichService->method('isConfigured')->willReturn(true);
		$this->request->method('getParam')->willReturn('/');
		$this->immichService->method('getUniqueFolderPaths')
			->willThrowException(new \Exception('Immich unreachable'));

		$response = $this->controller->content();

		$this->assertEquals(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertArrayHasKey('error', $response->getData());
	}
}
