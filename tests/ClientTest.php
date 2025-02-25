<?php

declare(strict_types=1);

namespace Swotto\Tests;

use Swotto\Client;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;
use Swotto\Contract\HttpClientInterface;
use Swotto\Exception\ConnectionException;
use Swotto\Exception\ValidationException;

class ClientTest extends TestCase
{
  private $mockHttpClient;
  private $mockLogger;
  private $client;

  protected function setUp(): void
  {
    $this->mockHttpClient = $this->createMock(HttpClientInterface::class);
    $this->mockLogger = $this->createMock(LoggerInterface::class);

    $this->client = new Client(
      ['url' => 'https://api.example.com'],
      $this->mockLogger,
      $this->mockHttpClient
    );
  }

  public function testGet(): void
  {
    $expectedResponse = ['data' => ['key' => 'value']];

    $this->mockHttpClient->expects($this->once())
      ->method('request')
      ->with('GET', 'test-endpoint', ['query' => ['param' => 'value']])
      ->willReturn($expectedResponse);

    $result = $this->client->get('test-endpoint', ['query' => ['param' => 'value']]);

    $this->assertEquals($expectedResponse, $result);
  }

  public function testPost(): void
  {
    $expectedResponse = ['data' => ['id' => 123]];

    $this->mockHttpClient->expects($this->once())
      ->method('request')
      ->with('POST', 'test-endpoint', ['json' => ['name' => 'Test']])
      ->willReturn($expectedResponse);

    $result = $this->client->post('test-endpoint', ['json' => ['name' => 'Test']]);

    $this->assertEquals($expectedResponse, $result);
  }

  public function testPut(): void
  {
    $expectedResponse = ['data' => ['id' => 123]];

    $this->mockHttpClient->expects($this->once())
      ->method('request')
      ->with('PUT', 'test-endpoint/123', ['json' => ['name' => 'Updated']])
      ->willReturn($expectedResponse);

    $result = $this->client->put('test-endpoint/123', ['json' => ['name' => 'Updated']]);

    $this->assertEquals($expectedResponse, $result);
  }

  public function testDelete(): void
  {
    $expectedResponse = [];

    $this->mockHttpClient->expects($this->once())
      ->method('request')
      ->with('DELETE', 'test-endpoint/123', [])
      ->willReturn($expectedResponse);

    $result = $this->client->delete('test-endpoint/123');

    $this->assertEquals($expectedResponse, $result);
  }

  public function testCheckConnectionSuccess(): void
  {
    $this->mockHttpClient->expects($this->once())
      ->method('request')
      ->with('GET', 'ping', [])
      ->willReturn(['status' => 'ok']);

    $result = $this->client->checkConnection();

    $this->assertTrue($result);
  }

  public function testCheckConnectionFailure(): void
  {
    $this->mockHttpClient->expects($this->once())
      ->method('request')
      ->with('GET', 'ping', [])
      ->willThrowException(new ConnectionException('Connection failed', 'https://api.example.com'));

    $result = $this->client->checkConnection();

    $this->assertFalse($result);
  }

  public function testFetchPop(): void
  {
    $expectedData = [
      ['id' => 1, 'name' => 'Item 1'],
      ['id' => 2, 'name' => 'Item 2']
    ];

    $this->mockHttpClient->expects($this->once())
      ->method('request')
      ->with('GET', 'test-pop', ['query' => ['param' => 'value']])
      ->willReturn(['data' => $expectedData]);

    $result = $this->client->fetchPop('test-pop', ['param' => 'value']);

    $this->assertEquals($expectedData, $result);
  }

  public function testFetchPopWithEmptyResponse(): void
  {
    $this->mockHttpClient->expects($this->once())
      ->method('request')
      ->with('GET', 'empty-pop', ['query' => []])
      ->willReturn([]);

    $result = $this->client->fetchPop('empty-pop');

    $this->assertEquals([], $result);
  }

  public function testExceptionHandling(): void
  {
    $this->mockHttpClient->expects($this->once())
      ->method('request')
      ->with('GET', 'invalid-endpoint', [])
      ->willThrowException(new ValidationException('Invalid request', ['field' => 'error'], 400));

    $this->expectException(ValidationException::class);
    $this->expectExceptionMessage('Invalid request');
    $this->expectExceptionCode(400);

    $this->client->get('invalid-endpoint');
  }

  public function testSetSessionId(): void
  {
    $sessionId = 'test-session-id';

    $this->mockHttpClient->expects($this->once())
      ->method('initialize')
      ->with($this->callback(function ($config) use ($sessionId) {
        return $config['session_id'] === $sessionId;
      }));

    $this->client->setSessionId($sessionId);
  }

  public function testSetLanguage(): void
  {
    $language = 'it';

    $this->mockHttpClient->expects($this->once())
      ->method('initialize')
      ->with($this->callback(function ($config) use ($language) {
        return $config['language'] === $language;
      }));

    $this->client->setLanguage($language);
  }

  public function testSetAccept(): void
  {
    $accept = 'application/xml';

    $this->mockHttpClient->expects($this->once())
      ->method('initialize')
      ->with($this->callback(function ($config) use ($accept) {
        return $config['accept'] === $accept;
      }));

    $this->client->setAccept($accept);
  }
}
