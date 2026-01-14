<?php

declare(strict_types=1);

namespace Swotto\Tests;

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Swotto\Client;
use Swotto\Contract\HttpClientInterface;

/**
 * PopTraitCompleteTest.
 *
 * Complete test coverage for PopTrait methods not covered in PopTraitTest.
 */
class PopTraitCompleteTest extends TestCase
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

    // ========== Template POP ==========

    public function testGetTemplatePop(): void
    {
        $expectedData = [
            ['uuid' => 'uuid1', 'name' => 'Invoice Template'],
            ['uuid' => 'uuid2', 'name' => 'Quote Template'],
        ];

        $this->mockHttpClient->expects($this->once())
            ->method('request')
            ->with('GET', 'template', [
                'query' => [
                    'limit' => 0,
                    'orderby' => 'name',
                    'columns' => 'uuid,name',
                ],
            ])
            ->willReturn(['data' => $expectedData]);

        $result = $this->client->getTemplatePop();

        $this->assertEquals($expectedData, $result);
    }

    // ========== Incoterm POP ==========

    public function testGetIncotermPop(): void
    {
        $expectedData = [
            ['uuid' => 'uuid1', 'name' => 'Free On Board', 'code' => 'FOB'],
            ['uuid' => 'uuid2', 'name' => 'Cost Insurance Freight', 'code' => 'CIF'],
        ];

        $this->mockHttpClient->expects($this->once())
            ->method('request')
            ->with('GET', 'open/incoterm', [
                'query' => [
                    'limit' => 0,
                    'orderby' => 'name',
                    'columns' => 'uuid,name,code',
                ],
            ])
            ->willReturn(['data' => $expectedData]);

        $result = $this->client->getIncotermPop();

        $this->assertEquals($expectedData, $result);
    }

    // ========== Carrier POP ==========

    public function testGetCarrierPop(): void
    {
        $expectedData = [
            ['id' => 1, 'name' => 'DHL'],
            ['id' => 2, 'name' => 'FedEx'],
        ];

        $this->mockHttpClient->expects($this->once())
            ->method('request')
            ->with('GET', 'carrier', [
                'query' => [
                    'limit' => 0,
                    'orderby' => 'name',
                    'columns' => 'id,name',
                ],
            ])
            ->willReturn(['data' => $expectedData]);

        $result = $this->client->getCarrierPop();

        $this->assertEquals($expectedData, $result);
    }

    // ========== Category POP ==========

    public function testGetCategoryPop(): void
    {
        $expectedData = [
            ['id' => 1, 'name' => 'Electronics'],
            ['id' => 2, 'name' => 'Clothing'],
        ];

        $this->mockHttpClient->expects($this->once())
            ->method('request')
            ->with('GET', 'category', [
                'query' => [
                    'limit' => 0,
                    'orderby' => 'name',
                ],
            ])
            ->willReturn(['data' => $expectedData]);

        $result = $this->client->getCategoryPop();

        $this->assertEquals($expectedData, $result);
    }

    // ========== Supplier POP ==========

    public function testGetSupplierPop(): void
    {
        $expectedData = [
            ['id' => 1, 'name' => 'Supplier A'],
            ['id' => 2, 'name' => 'Supplier B'],
        ];

        $this->mockHttpClient->expects($this->once())
            ->method('request')
            ->with('GET', 'supplier', [
                'query' => [
                    'limit' => 0,
                    'orderby' => 'name',
                    'columns' => 'id,name',
                ],
            ])
            ->willReturn(['data' => $expectedData]);

        $result = $this->client->getSupplierPop();

        $this->assertEquals($expectedData, $result);
    }

    // ========== Warehouse POP ==========

    public function testGetWarehousePop(): void
    {
        $expectedData = [
            ['id' => 1, 'name' => 'Main Warehouse'],
            ['id' => 2, 'name' => 'Secondary Warehouse'],
        ];

        $this->mockHttpClient->expects($this->once())
            ->method('request')
            ->with('GET', 'warehouse', [
                'query' => [
                    'limit' => 0,
                    'orderby' => 'name',
                ],
            ])
            ->willReturn(['data' => $expectedData]);

        $result = $this->client->getWarehousePop();

        $this->assertEquals($expectedData, $result);
    }

    // ========== Family POP ==========

    public function testGetFamilyPop(): void
    {
        $expectedData = [
            ['id' => 1, 'name' => 'Family A'],
            ['id' => 2, 'name' => 'Family B'],
        ];

        $this->mockHttpClient->expects($this->once())
            ->method('request')
            ->with('GET', 'family', [
                'query' => [
                    'limit' => 0,
                    'orderby' => 'name',
                ],
            ])
            ->willReturn(['data' => $expectedData]);

        $result = $this->client->getFamilyPop();

        $this->assertEquals($expectedData, $result);
    }

    // ========== Warehouse Reason POP ==========

    public function testGetWhsreasonPop(): void
    {
        $expectedData = [
            ['id' => 1, 'name' => 'Sale'],
            ['id' => 2, 'name' => 'Return'],
        ];

        $this->mockHttpClient->expects($this->once())
            ->method('request')
            ->with('GET', 'whsreason', [
                'query' => [
                    'limit' => 0,
                    'orderby' => 'name',
                ],
            ])
            ->willReturn(['data' => $expectedData]);

        $result = $this->client->getWhsreasonPop();

        $this->assertEquals($expectedData, $result);
    }

    // ========== Warehouse Inbound POP ==========

    public function testGetWhsinboundPop(): void
    {
        $expectedData = [
            ['id' => 1, 'created_on' => '2024-01-01'],
            ['id' => 2, 'created_on' => '2024-01-02'],
        ];

        $this->mockHttpClient->expects($this->once())
            ->method('request')
            ->with('GET', 'whsinbound/qpop', [
                'query' => [
                    'limit' => 0,
                    'orderby' => 'created_on',
                ],
            ])
            ->willReturn(['data' => $expectedData]);

        $result = $this->client->getWhsinboundPop();

        $this->assertEquals($expectedData, $result);
    }

    // ========== Warehouse Order POP ==========

    public function testGetWhsorderPop(): void
    {
        $expectedData = [
            ['id' => 1, 'created_on' => '2024-01-01'],
            ['id' => 2, 'created_on' => '2024-01-02'],
        ];

        $this->mockHttpClient->expects($this->once())
            ->method('request')
            ->with('GET', 'whsorder', [
                'query' => [
                    'limit' => 0,
                    'orderby' => 'created_on',
                ],
            ])
            ->willReturn(['data' => $expectedData]);

        $result = $this->client->getWhsorderPop();

        $this->assertEquals($expectedData, $result);
    }

    // ========== Agreement POP ==========

    public function testGetAgreementPop(): void
    {
        $expectedData = [
            ['id' => 1, 'name' => 'Agreement 2024'],
            ['id' => 2, 'name' => 'Agreement 2023'],
        ];

        $this->mockHttpClient->expects($this->once())
            ->method('request')
            ->with('GET', 'agreement', [
                'query' => [
                    'limit' => 0,
                    'orderby' => 'name',
                ],
            ])
            ->willReturn(['data' => $expectedData]);

        $result = $this->client->getAgreementPop();

        $this->assertEquals($expectedData, $result);
    }

    // ========== Currency POP ==========

    public function testGetCurrencyPop(): void
    {
        $expectedData = [
            ['code' => 'EUR', 'name' => 'Euro'],
            ['code' => 'USD', 'name' => 'US Dollar'],
        ];

        $this->mockHttpClient->expects($this->once())
            ->method('request')
            ->with('GET', 'open/currency', [
                'query' => [
                    'limit' => 0,
                    'orderby' => 'name',
                    'columns' => 'code,name',
                ],
            ])
            ->willReturn(['data' => $expectedData]);

        $result = $this->client->getCurrencyPop();

        $this->assertEquals($expectedData, $result);
    }

    // ========== System Language POP ==========

    public function testGetSysLanguagePop(): void
    {
        $expectedData = [
            ['name' => 'English', 'code' => 'en'],
            ['name' => 'Italian', 'code' => 'it'],
        ];

        $this->mockHttpClient->expects($this->once())
            ->method('request')
            ->with('GET', 'open/language', [
                'query' => [
                    'limit' => 0,
                    'orderby' => 'name',
                    'columns' => 'name,code',
                ],
            ])
            ->willReturn(['data' => $expectedData]);

        $result = $this->client->getSysLanguagePop();

        $this->assertEquals($expectedData, $result);
    }

    // ========== Product POP ==========

    public function testGetProductPop(): void
    {
        $expectedData = [
            ['id' => 1, 'name' => 'Product A'],
            ['id' => 2, 'name' => 'Product B'],
        ];

        $this->mockHttpClient->expects($this->once())
            ->method('request')
            ->with('GET', 'product', [
                'query' => [
                    'limit' => 0,
                    'orderby' => 'name',
                    'columns' => 'id,name',
                ],
            ])
            ->willReturn(['data' => $expectedData]);

        $result = $this->client->getProductPop();

        $this->assertEquals($expectedData, $result);
    }

    // ========== Payment Type POP ==========

    public function testGetPaymentTypePop(): void
    {
        $expectedData = [
            ['id' => 1, 'name' => 'Cash'],
            ['id' => 2, 'name' => 'Credit Card'],
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

        $result = $this->client->getPaymentTypePop();

        $this->assertEquals($expectedData, $result);
    }

    /**
     * Test deprecated getPaymentType() calls getPaymentTypePop().
     */
    public function testGetPaymentTypeDeprecatedCallsNewMethod(): void
    {
        $expectedData = [
            ['id' => 1, 'name' => 'Cash'],
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

        // Call deprecated method - should work and call getPaymentTypePop internally
        $result = $this->client->getPaymentType();

        $this->assertEquals($expectedData, $result);
    }

    // ========== Edge Cases ==========

    /**
     * Test getMeOrganization with null query.
     */
    public function testGetMeOrganizationWithNullQuery(): void
    {
        $expectedData = [
            ['id' => 1, 'name' => 'My Org'],
        ];

        $this->mockHttpClient->expects($this->once())
            ->method('request')
            ->with('GET', 'me/organization', [
                'query' => [],
            ])
            ->willReturn(['data' => $expectedData]);

        $result = $this->client->getMeOrganization([]);

        $this->assertEquals($expectedData, $result);
    }

    /**
     * Test POP with custom query params.
     */
    public function testPopWithCustomQueryParams(): void
    {
        $expectedData = [
            ['id' => 1, 'name' => 'Template 1'],
        ];

        $this->mockHttpClient->expects($this->once())
            ->method('request')
            ->with('GET', 'template', [
                'query' => [
                    'limit' => 0,
                    'orderby' => 'name',
                    'columns' => 'uuid,name',
                    'filter' => 'active',
                    'custom_param' => 'value',
                ],
            ])
            ->willReturn(['data' => $expectedData]);

        $result = $this->client->getTemplatePop(['filter' => 'active', 'custom_param' => 'value']);

        $this->assertEquals($expectedData, $result);
    }

    /**
     * Test POP with empty response.
     */
    public function testPopWithEmptyResponse(): void
    {
        $this->mockHttpClient->expects($this->once())
            ->method('request')
            ->with('GET', 'category', $this->anything())
            ->willReturn(['data' => []]);

        $result = $this->client->getCategoryPop();

        $this->assertEquals([], $result);
    }

    /**
     * Test POP with no data key in response.
     */
    public function testPopWithNoDataKey(): void
    {
        $this->mockHttpClient->expects($this->once())
            ->method('request')
            ->with('GET', 'supplier', $this->anything())
            ->willReturn(['success' => true]); // No 'data' key

        $result = $this->client->getSupplierPop();

        $this->assertEquals([], $result);
    }

    /**
     * Test POP with null data in response.
     */
    public function testPopWithNullData(): void
    {
        $this->mockHttpClient->expects($this->once())
            ->method('request')
            ->with('GET', 'warehouse', $this->anything())
            ->willReturn(['data' => null]);

        $result = $this->client->getWarehousePop();

        $this->assertEquals([], $result);
    }

    /**
     * Test multiple POP calls maintain independence.
     */
    public function testMultiplePopCallsIndependent(): void
    {
        $categoryData = [['id' => 1, 'name' => 'Cat']];
        $supplierData = [['id' => 2, 'name' => 'Sup']];

        $this->mockHttpClient->expects($this->exactly(2))
            ->method('request')
            ->willReturnOnConsecutiveCalls(
                ['data' => $categoryData],
                ['data' => $supplierData]
            );

        $categories = $this->client->getCategoryPop();
        $suppliers = $this->client->getSupplierPop();

        $this->assertEquals($categoryData, $categories);
        $this->assertEquals($supplierData, $suppliers);
    }

    /**
     * Test getIncotermByCode (non-POP method in trait).
     */
    public function testGetIncotermByCodeWithEmptyResult(): void
    {
        $this->mockHttpClient->expects($this->once())
            ->method('request')
            ->with('GET', 'open/incoterm/findByCode', [
                'query' => ['code' => 'INVALID'],
            ])
            ->willReturn(['data' => []]);

        $result = $this->client->getIncotermByCode('INVALID');

        $this->assertEquals([], $result);
    }

    /**
     * Test getWarehouseZonePop with null query.
     */
    public function testGetWarehouseZonePopWithNullQuery(): void
    {
        $expectedData = [['id' => 1, 'name' => 'Zone A']];

        $this->mockHttpClient->expects($this->once())
            ->method('request')
            ->with('GET', 'warehouse/5/zone', [
                'query' => [
                    'limit' => 0,
                    'orderby' => 'name',
                ],
            ])
            ->willReturn(['data' => $expectedData]);

        $result = $this->client->getWarehouseZonePop(5, []);

        $this->assertEquals($expectedData, $result);
    }
}
