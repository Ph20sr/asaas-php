<?php

declare(strict_types=1);

namespace Ph20sr\Asaas\Tests;

use Ph20sr\Asaas\Asaas;
use Ph20sr\Asaas\Environment;
use PHPUnit\Framework\TestCase;

final class ResourceTest extends TestCase
{
    private FakeHttpClient $http;
    private Asaas $asaas;

    protected function setUp(): void
    {
        $this->http = new FakeHttpClient();
        $this->asaas = new Asaas('$aact_prod_test', Environment::Production, $this->http);
    }

    public function testListBuildsPaginationQuery(): void
    {
        $this->http->push(200, ['data' => [['id' => 'cus_1']], 'totalCount' => 30, 'hasMore' => true, 'offset' => 0, 'limit' => 1]);
        $page = $this->asaas->customers->list(['name' => 'Ana'], 0, 500);

        $this->assertSame('https://api.asaas.com/v3/customers?name=Ana&offset=0&limit=100', $this->http->last()['url']);
        $this->assertCount(1, $page);
        $this->assertSame(30, $page->totalCount);
        $this->assertTrue($page->hasMore);
    }

    public function testAllWalksEveryPageLazily(): void
    {
        $this->http
            ->push(200, ['data' => [['id' => 'a'], ['id' => 'b']], 'hasMore' => true])
            ->push(200, ['data' => [['id' => 'c']], 'hasMore' => false]);

        $ids = array_map(static fn (array $c): string => $c['id'], iterator_to_array($this->asaas->customers->all([], 2), false));

        $this->assertSame(['a', 'b', 'c'], $ids);
        $this->assertStringContainsString('offset=2', $this->http->last()['url']);
    }

    public function testFirstOrCreateReusesExistingCustomer(): void
    {
        $this->http->push(200, ['data' => [['id' => 'cus_existing']], 'hasMore' => false]);
        $customer = $this->asaas->customers->firstOrCreate(['name' => 'Ana', 'cpfCnpj' => '529.982.247-25']);

        $this->assertSame('cus_existing', $customer['id']);
        $this->assertStringContainsString('cpfCnpj=52998224725', $this->http->last()['url']);
        $this->assertCount(1, $this->http->requests);
    }

    public function testFirstOrCreateCreatesWhenMissing(): void
    {
        $this->http->push(200, ['data' => [], 'hasMore' => false])->push(200, ['id' => 'cus_new']);
        $customer = $this->asaas->customers->firstOrCreate(['name' => 'Ana', 'cpfCnpj' => '52998224725']);

        $this->assertSame('cus_new', $customer['id']);
        $this->assertSame('POST', $this->http->last()['method']);
    }

    public function testCreatePixReturnsPaymentAndQrCode(): void
    {
        $this->http
            ->push(200, ['id' => 'pay_1', 'status' => 'PENDING'])
            ->push(200, ['encodedImage' => 'iVBOR...', 'payload' => '00020101...']);

        $result = $this->asaas->payments->createPix('cus_1', 99.899, '2026-10-10', ['description' => 'Plano Pro']);

        $created = json_decode((string) $this->http->requests[0]['body'], true);
        $this->assertSame('PIX', $created['billingType']);
        $this->assertSame(99.9, $created['value']);
        $this->assertSame('Plano Pro', $created['description']);
        $this->assertSame('https://api.asaas.com/v3/payments/pay_1/pixQrCode', $this->http->last()['url']);
        $this->assertSame('00020101...', $result['pix']['payload']);
    }

    public function testRefundSendsOnlyProvidedFields(): void
    {
        $this->http->push(200, ['status' => 'REFUNDED']);
        $this->asaas->payments->refund('pay_1');
        $this->assertSame('{}', $this->http->last()['body']);

        $this->http->push(200, ['status' => 'REFUNDED']);
        $this->asaas->payments->refund('pay_1', 10.5);
        $this->assertSame('{"value":10.5}', $this->http->last()['body']);
    }

    public function testEscapesIdsInPath(): void
    {
        $this->http->push(200, []);
        $this->asaas->subscriptions->find('sub/../x');
        $this->assertSame('https://api.asaas.com/v3/subscriptions/sub%2F..%2Fx', $this->http->last()['url']);
    }
}
