<?php

declare(strict_types=1);

namespace Swotto\Tests;

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Swotto\Client;
use Swotto\Contract\HttpClientInterface;

class PopTraitTest extends TestCase
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

    public function testGetGenderPop(): void
    {
        $expectedData = [
            ['id' => 1, 'name' => 'Male'],
            ['id' => 2, 'name' => 'Female'],
        ];

        $this->mockHttpClient->expects($this->once())
            ->method('request')
            ->with('GET', 'open/gender', [
                'query' => ['public' => true, 'param' => 'value'],
            ])
            ->willReturn(['data' => $expectedData]);

        $result = $this->client->getGenderPop(['param' => 'value']);

        $this->assertEquals($expectedData, $result);
    }

    public function testGetUserRolePop(): void
    {
        $expectedData = [
            ['id' => 1, 'name' => 'Admin'],
            ['id' => 2, 'name' => 'User'],
        ];

        $this->mockHttpClient->expects($this->once())
            ->method('request')
            ->with('GET', 'open/role', [
                'query' => ['public' => true],
            ])
            ->willReturn(['data' => $expectedData]);

        $result = $this->client->getUserRolePop();

        $this->assertEquals($expectedData, $result);
    }

    public function testGetCountryPop(): void
    {
        $expectedData = [
            ['code' => 'IT', 'name' => 'Italy'],
            ['code' => 'US', 'name' => 'United States'],
        ];

        $this->mockHttpClient->expects($this->once())
            ->method('request')
            ->with('GET', 'open/country', [
                'query' => [
                    'limit' => 0,
                    'orderby' => 'name',
                    'columns' => 'code,name',
                ],
            ])
            ->willReturn(['data' => $expectedData]);

        $result = $this->client->getCountryPop();

        $this->assertEquals($expectedData, $result);
    }

    public function testGetCustomerPop(): void
    {
        $expectedData = [
            ['id' => 1, 'name' => 'Customer A'],
            ['id' => 2, 'name' => 'Customer B'],
        ];

        $this->mockHttpClient->expects($this->once())
            ->method('request')
            ->with('GET', 'customer', [
                'query' => [
                    'limit' => 0,
                    'orderby' => 'name',
                    'columns' => 'id,name',
                ],
            ])
            ->willReturn(['data' => $expectedData]);

        $result = $this->client->getCustomerPop();

        $this->assertEquals($expectedData, $result);
    }

    public function testGetIncotermByCode(): void
    {
        $expectedData = [
            ['uuid' => 'uuid1', 'name' => 'FOB', 'code' => 'FOB'],
        ];

        $this->mockHttpClient->expects($this->once())
            ->method('request')
            ->with('GET', 'open/incoterm/findByCode', [
                'query' => ['code' => 'FOB'],
            ])
            ->willReturn(['data' => $expectedData]);

        $result = $this->client->getIncotermByCode('FOB');

        $this->assertEquals($expectedData, $result);
    }

    public function testGetWarehouseZonePop(): void
    {
        $warehouseId = 123;
        $expectedData = [
            ['id' => 1, 'name' => 'Zone A'],
            ['id' => 2, 'name' => 'Zone B'],
        ];

        $this->mockHttpClient->expects($this->once())
            ->method('request')
            ->with('GET', 'warehouse/123/zone', [
                'query' => [
                    'limit' => 0,
                    'orderby' => 'name',
                    'custom' => 'param',
                ],
            ])
            ->willReturn(['data' => $expectedData]);

        $result = $this->client->getWarehouseZonePop($warehouseId, ['custom' => 'param']);

        $this->assertEquals($expectedData, $result);
    }

    public function testGetShiptypePop(): void
    {
        $expectedData = [
            ['id' => 1, 'name' => 'Vettore'],
            ['id' => 2, 'name' => 'Mittente'],
            ['id' => 3, 'name' => 'Destinatario'],
        ];

        $result = $this->client->getShiptypePop();

        $this->assertEquals($expectedData, $result);
    }

    public function testGetProjectPop(): void
    {
        $expectedData = [
            ['id' => 1, 'name' => 'Project Alpha'],
            ['id' => 2, 'name' => 'Project Beta'],
        ];

        $this->mockHttpClient->expects($this->once())
            ->method('request')
            ->with('GET', 'project/qpop', [
                'query' => [
                    'limit' => 0,
                    'orderby' => 'name',
                ],
            ])
            ->willReturn(['data' => $expectedData]);

        $result = $this->client->getProjectPop();

        $this->assertEquals($expectedData, $result);
    }

    public function testGetPaymentType(): void
    {
        $expectedData = [
            ['id' => 1, 'name' => 'Credit Card'],
            ['id' => 2, 'name' => 'Bank Transfer'],
        ];

        $this->mockHttpClient->expects($this->once())
            ->method('request')
            ->with('GET', 'payment/type', [
                'query' => [
                    'limit' => 0,
                    'orderby' => 'name',
                ],
            ])
            ->willReturn(['data' => $expectedData]);

        $result = $this->client->getPaymentType();

        $this->assertEquals($expectedData, $result);
    }

    public function testGetMeOrganization(): void
    {
        $expectedData = [
            ['id' => 1, 'name' => 'My Organization'],
        ];

        $this->mockHttpClient->expects($this->once())
            ->method('request')
            ->with('GET', 'me/organization', [
                'query' => ['param' => 'value'],
            ])
            ->willReturn(['data' => $expectedData]);

        $result = $this->client->getMeOrganization(['param' => 'value']);

        $this->assertEquals($expectedData, $result);
    }
}
