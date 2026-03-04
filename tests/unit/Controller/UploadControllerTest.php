<?php

declare(strict_types=1);

namespace OCA\IntegrationImmich\Tests\Unit\Controller;

use OCA\IntegrationImmich\Controller\UploadController;
use OCA\IntegrationImmich\Service\ImmichService;
use OCP\AppFramework\Http;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Test\TestCase;

class UploadControllerTest extends TestCase {

	private UploadController $controller;
	private ImmichService&MockObject $immichService;
	private IRequest&MockObject $request;
	private IRootFolder&MockObject $rootFolder;
	private LoggerInterface&MockObject $logger;

	protected function setUp(): void {
		parent::setUp();

		$this->immichService = $this->createMock(ImmichService::class);
		$this->request = $this->createMock(IRequest::class);
		$this->rootFolder = $this->createMock(IRootFolder::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->controller = new UploadController(
			$this->request,
			$this->immichService,
			$this->rootFolder,
			'testuser',
			$this->logger,
		);
	}

	public function testUploadReturns412WhenNotConfigured(): void {
		$this->immichService->method('isConfigured')->willReturn(false);

		$response = $this->controller->upload();

		$this->assertEquals(Http::STATUS_PRECONDITION_FAILED, $response->getStatus());
		$this->assertArrayHasKey('error', $response->getData());
	}

	public function testUploadReturns400WhenFileIdMissing(): void {
		$this->immichService->method('isConfigured')->willReturn(true);
		$this->request->method('getParam')->with('fileId')->willReturn(null);

		$response = $this->controller->upload();

		$this->assertEquals(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertArrayHasKey('error', $response->getData());
	}

	public function testUploadReturns404WhenFileNotFound(): void {
		$this->immichService->method('isConfigured')->willReturn(true);
		$this->request->method('getParam')->with('fileId')->willReturn('999');

		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('getById')->with(999)->willReturn([]);
		$this->rootFolder->method('getUserFolder')->with('testuser')->willReturn($userFolder);

		$response = $this->controller->upload();

		$this->assertEquals(Http::STATUS_NOT_FOUND, $response->getStatus());
	}

	public function testUploadReturns400WhenNotAFile(): void {
		$this->immichService->method('isConfigured')->willReturn(true);
		$this->request->method('getParam')->with('fileId')->willReturn('42');

		// Return a Folder (not a File) to simulate the "not a file" case
		$folder = $this->createMock(Folder::class);
		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('getById')->with(42)->willReturn([$folder]);
		$this->rootFolder->method('getUserFolder')->with('testuser')->willReturn($userFolder);

		$response = $this->controller->upload();

		$this->assertEquals(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testUploadSucceeds(): void {
		$this->immichService->method('isConfigured')->willReturn(true);
		$this->request->method('getParam')->with('fileId')->willReturn('42');

		$file = $this->createMock(File::class);
		$file->method('getContent')->willReturn('binary-data');
		$file->method('getName')->willReturn('photo.jpg');
		$file->method('getMimeType')->willReturn('image/jpeg');
		$file->method('getCreationTime')->willReturn(0);
		$file->method('getMTime')->willReturn(1700000000);

		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('getById')->with(42)->willReturn([$file]);
		$this->rootFolder->method('getUserFolder')->with('testuser')->willReturn($userFolder);

		$this->immichService->method('uploadAsset')->willReturn(['id' => 'new-asset-uuid', 'status' => 'created']);

		$response = $this->controller->upload();

		$this->assertEquals(Http::STATUS_OK, $response->getStatus());
		$this->assertArrayHasKey('id', $response->getData());
	}
}
