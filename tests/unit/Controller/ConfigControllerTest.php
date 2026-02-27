<?php

declare(strict_types=1);

namespace OCA\IntegrationImmich\Tests\Unit\Controller;

use OCA\IntegrationImmich\Controller\ConfigController;
use OCA\IntegrationImmich\Service\ImmichService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use Test\TestCase;

class ConfigControllerTest extends TestCase {

	private ConfigController $controller;
	private ImmichService&MockObject $immichService;
	private IRequest&MockObject $request;

	protected function setUp(): void {
		parent::setUp();

		$this->immichService = $this->createMock(ImmichService::class);
		$this->request = $this->createMock(IRequest::class);

		$this->controller = new ConfigController(
			$this->request,
			$this->immichService,
		);
	}

	public function testGetConfigReturnsServerUrlAndMaskedKey(): void {
		$this->immichService->method('getServerUrl')->willReturn('https://photos.example.com');
		$this->immichService->method('getApiKey')->willReturn('abcdefgh');

		$response = $this->controller->getConfig();
		$data = $response->getData();

		$this->assertEquals(Http::STATUS_OK, $response->getStatus());
		$this->assertEquals('https://photos.example.com', $data['server_url']);
		$this->assertTrue($data['api_key_set']);
		$this->assertEquals('abcd****', $data['api_key_masked']);
	}

	public function testGetConfigReturnsApiKeyNotSetWhenEmpty(): void {
		$this->immichService->method('getServerUrl')->willReturn('');
		$this->immichService->method('getApiKey')->willReturn('');

		$response = $this->controller->getConfig();
		$data = $response->getData();

		$this->assertFalse($data['api_key_set']);
		$this->assertEquals('', $data['api_key_masked']);
	}

	public function testSetConfigSavesUrlAndKey(): void {
		$this->request->method('getParam')->willReturnMap([
			['server_url', null, 'https://photos.example.com'],
			['api_key', null, 'my-api-key'],
			['validate', false, false],
		]);

		$this->immichService->expects($this->once())->method('setServerUrl')->with('https://photos.example.com');
		$this->immichService->expects($this->once())->method('setApiKey')->with('my-api-key');

		$response = $this->controller->setConfig();

		$this->assertEquals(Http::STATUS_OK, $response->getStatus());
		$this->assertTrue($response->getData()['success']);
	}

	public function testSetConfigDoesNotOverwriteKeyWhenEmpty(): void {
		$this->request->method('getParam')->willReturnMap([
			['server_url', null, 'https://photos.example.com'],
			['api_key', null, ''],
			['validate', false, false],
		]);

		$this->immichService->expects($this->once())->method('setServerUrl');
		$this->immichService->expects($this->never())->method('setApiKey');

		$this->controller->setConfig();
	}

	public function testSetConfigWithValidationSuccessful(): void {
		$this->request->method('getParam')->willReturnMap([
			['server_url', null, null],
			['api_key', null, null],
			['validate', false, true],
		]);

		$this->immichService->method('validateConnection')->willReturn(['success' => true, 'data' => []]);

		$response = $this->controller->setConfig();

		$this->assertEquals(Http::STATUS_OK, $response->getStatus());
		$this->assertTrue($response->getData()['success']);
	}

	public function testSetConfigWithValidationFailed(): void {
		$this->request->method('getParam')->willReturnMap([
			['server_url', null, null],
			['api_key', null, null],
			['validate', false, true],
		]);

		$this->immichService->method('validateConnection')->willReturn(['success' => false, 'error' => 'Connection refused']);

		$response = $this->controller->setConfig();

		$this->assertEquals(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertArrayHasKey('error', $response->getData());
	}
}
