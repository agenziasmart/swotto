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

    public function testPatch(): void
    {
        $expectedResponse = ['data' => ['id' => 123]];

        $this->mockHttpClient->expects($this->once())
            ->method('request')
            ->with('PATCH', 'test-endpoint/123', ['json' => ['name' => 'Patched']])
            ->willReturn($expectedResponse);

        $result = $this->client->patch('test-endpoint/123', ['name' => 'Patched']);

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
}
