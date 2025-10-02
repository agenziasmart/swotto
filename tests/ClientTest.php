<?php

declare(strict_types=1);

namespace Swotto\Tests;

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Swotto\Client;
use Swotto\Contract\HttpClientInterface;
use Swotto\Exception\ConnectionException;
use Swotto\Exception\ValidationException;

class ClientTest extends TestCase
{
    private HttpClientInterface $mockHttpClient;

    private LoggerInterface $mockLogger;

    private Client $client;

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

        $result = $this->client->post('test-endpoint', ['name' => 'Test']);

        $this->assertEquals($expectedResponse, $result);
    }

    public function testPut(): void
    {
        $expectedResponse = ['data' => ['id' => 123]];

        $this->mockHttpClient->expects($this->once())
          ->method('request')
          ->with('PUT', 'test-endpoint/123', ['json' => ['name' => 'Updated']])
          ->willReturn($expectedResponse);

        $result = $this->client->put('test-endpoint/123', ['name' => 'Updated']);

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
          ['id' => 2, 'name' => 'Item 2'],
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

    public function testGetParsed(): void
    {
        $mockResponse = [
            'success' => true,
            'data' => [
                ['id' => 1, 'name' => 'Customer 1'],
                ['id' => 2, 'name' => 'Customer 2'],
            ],
            'meta' => [
                'pagination' => [
                    'current_page' => 1,
                    'per_page' => 10,
                    'total_pages' => 5,
                    'total' => 50,
                ],
            ],
        ];

        $this->mockHttpClient->expects($this->once())
          ->method('request')
          ->with('GET', 'customers', ['query' => ['active' => true]])
          ->willReturn($mockResponse);

        $result = $this->client->getParsed('customers', ['query' => ['active' => true]]);

        $this->assertTrue($result['success']);
        $this->assertEquals($mockResponse['data'], $result['data']);
        $this->assertIsArray($result['paginator']);
        $this->assertEquals(1, $result['paginator']['current']);
        $this->assertEquals(5, $result['paginator']['last']);
        $this->assertEquals(10, $result['paginator']['per_page']);
        $this->assertEquals(50, $result['paginator']['results']);
    }

    public function testPostParsed(): void
    {
        $mockResponse = [
            'success' => true,
            'data' => ['id' => 123, 'name' => 'New Customer'],
            'meta' => [],
        ];

        $this->mockHttpClient->expects($this->once())
          ->method('request')
          ->with('POST', 'customers', ['json' => ['name' => 'New Customer']])
          ->willReturn($mockResponse);

        $result = $this->client->postParsed('customers', ['name' => 'New Customer']);

        $this->assertTrue($result['success']);
        $this->assertEquals($mockResponse['data'], $result['data']);
        $this->assertEquals([], $result['paginator']);
    }

    public function testPatchParsed(): void
    {
        $mockResponse = [
            'success' => true,
            'data' => ['id' => 123, 'name' => 'Updated Customer'],
            'meta' => [],
        ];

        $this->mockHttpClient->expects($this->once())
          ->method('request')
          ->with('PATCH', 'customers/123', ['json' => ['name' => 'Updated Customer']])
          ->willReturn($mockResponse);

        $result = $this->client->patchParsed('customers/123', ['name' => 'Updated Customer']);

        $this->assertTrue($result['success']);
        $this->assertEquals($mockResponse['data'], $result['data']);
    }

    public function testPutParsed(): void
    {
        $mockResponse = [
            'success' => true,
            'data' => ['id' => 123, 'name' => 'Replaced Customer'],
            'meta' => [],
        ];

        $this->mockHttpClient->expects($this->once())
          ->method('request')
          ->with('PUT', 'customers/123', ['json' => ['name' => 'Replaced Customer']])
          ->willReturn($mockResponse);

        $result = $this->client->putParsed('customers/123', ['name' => 'Replaced Customer']);

        $this->assertTrue($result['success']);
        $this->assertEquals($mockResponse['data'], $result['data']);
    }

    public function testDeleteParsed(): void
    {
        $mockResponse = [
            'success' => true,
            'data' => [],
            'meta' => [],
        ];

        $this->mockHttpClient->expects($this->once())
          ->method('request')
          ->with('DELETE', 'customers/123', [])
          ->willReturn($mockResponse);

        $result = $this->client->deleteParsed('customers/123');

        $this->assertTrue($result['success']);
        $this->assertEquals([], $result['data']);
    }

    public function testParsedWithEmptyResponse(): void
    {
        $mockResponse = [
            'success' => false,
            'data' => null,
        ];

        $this->mockHttpClient->expects($this->once())
          ->method('request')
          ->with('GET', 'empty-endpoint', [])
          ->willReturn($mockResponse);

        $result = $this->client->getParsed('empty-endpoint');

        $this->assertFalse($result['success']);
        $this->assertEquals([], $result['data']);
        $this->assertEquals([], $result['paginator']);
    }

    public function testParsedWithComplexPagination(): void
    {
        $mockResponse = [
            'success' => true,
            'data' => [['id' => 1]],
            'meta' => [
                'pagination' => [
                    'current_page' => 3,
                    'per_page' => 10,
                    'total_pages' => 10,
                    'total' => 100,
                ],
            ],
        ];

        $this->mockHttpClient->expects($this->once())
          ->method('request')
          ->with('GET', 'paginated-endpoint', [])
          ->willReturn($mockResponse);

        $result = $this->client->getParsed('paginated-endpoint');

        $this->assertTrue($result['success']);
        $this->assertEquals(3, $result['paginator']['current']);
        $this->assertEquals(10, $result['paginator']['last']);
        $this->assertEquals(100, $result['paginator']['results']);
        $this->assertIsArray($result['paginator']['range']);
        // Test range contains page numbers
        $this->assertContains(1, $result['paginator']['range']);
        $this->assertContains(10, $result['paginator']['range']);
    }
}
