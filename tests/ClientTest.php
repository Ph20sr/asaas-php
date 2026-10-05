<?php

declare(strict_types=1);

namespace Ph20sr\Asaas\Tests;

use Ph20sr\Asaas\Client;
use Ph20sr\Asaas\Environment;
use Ph20sr\Asaas\Exception\ApiException;
use Ph20sr\Asaas\Exception\AuthenticationException;
use Ph20sr\Asaas\Exception\ConnectionException;
use Ph20sr\Asaas\Exception\NotFoundException;
use Ph20sr\Asaas\Exception\RateLimitException;
use Ph20sr\Asaas\Exception\ValidationException;
use PHPUnit\Framework\TestCase;

final class ClientTest extends TestCase
{
    private FakeHttpClient $http;
    /** @var list<int> */
    private array $sleeps = [];

    protected function setUp(): void
    {
        $this->http = new FakeHttpClient();
        $this->sleeps = [];
    }

    private function client(string $key = '$aact_hmlg_test', int $retries = 2): Client
    {
        return new Client($key, null, $this->http, $retries, function (int $ms): void {
            $this->sleeps[] = $ms;
        });
    }

    public function testDetectsEnvironmentFromApiKey(): void
    {
        $this->assertSame(Environment::Sandbox, Environment::fromApiKey('$aact_hmlg_abc'));
        $this->assertSame(Environment::Production, Environment::fromApiKey('$aact_prod_abc'));
    }

    public function testSendsAuthHeadersQueryAndJsonBody(): void
    {
        $this->http->push(200, ['id' => 'cus_1']);
        $result = $this->client()->request('POST', '/customers', ['foo' => 'a b', 'skip' => null], ['name' => 'José']);

        $request = $this->http->last();
        $this->assertSame(['id' => 'cus_1'], $result);
        $this->assertSame('POST', $request['method']);
        $this->assertSame('https://api-sandbox.asaas.com/v3/customers?foo=a%20b', $request['url']);
        $this->assertSame('$aact_hmlg_test', $request['headers']['access_token']);
        $this->assertSame('application/json', $request['headers']['Content-Type']);
        $this->assertSame('{"name":"José"}', $request['body']);
    }

    public function testRejectsEmptyApiKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Client('  ', null, $this->http);
    }

    public function testMapsValidationErrors(): void
    {
        $this->http->push(400, ['errors' => [
            ['code' => 'invalid_cpfCnpj', 'description' => 'O CPF/CNPJ informado é inválido.'],
        ]]);

        try {
            $this->client()->post('customers', ['cpfCnpj' => '123']);
            $this->fail('Deveria lançar ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame(400, $e->status);
            $this->assertSame('O CPF/CNPJ informado é inválido.', $e->getMessage());
            $this->assertTrue($e->hasErrorCode('invalid_cpfCnpj'));
        }
    }

    public function testMapsStatusCodesToExceptions(): void
    {
        $cases = [401 => AuthenticationException::class, 404 => NotFoundException::class, 403 => ApiException::class];
        foreach ($cases as $status => $class) {
            $this->http->push($status, '');
            try {
                $this->client()->get('payments/x');
                $this->fail("HTTP {$status} deveria lançar exceção");
            } catch (ApiException $e) {
                $this->assertInstanceOf($class, $e);
                $this->assertSame($status, $e->status);
            }
        }
    }

    public function testRetriesRateLimitEvenOnPostRespectingRetryAfter(): void
    {
        $this->http->push(429, '', ['retry-after' => '2'])->push(200, ['id' => 'pay_1']);

        $this->assertSame(['id' => 'pay_1'], $this->client()->post('payments', ['value' => 10]));
        $this->assertCount(2, $this->http->requests);
        $this->assertSame([2000], $this->sleeps);
    }

    public function testGivesUpAfterMaxRetries(): void
    {
        $this->http->push(429)->push(429)->push(429);
        $this->expectException(RateLimitException::class);
        $this->client()->get('payments');
    }

    public function testRetriesServerErrorsOnlyForIdempotentMethods(): void
    {
        $this->http->push(502)->push(200, ['ok' => true]);
        $this->assertSame(['ok' => true], $this->client()->get('payments/pay_1'));

        $this->http->push(502);
        try {
            $this->client()->post('payments', ['value' => 10]);
            $this->fail('POST com 5xx não pode ser repetido (risco de cobrança duplicada)');
        } catch (ApiException $e) {
            $this->assertSame(502, $e->status);
        }
        $this->assertCount(3, $this->http->requests);
    }

    public function testRetriesConnectionFailuresForGet(): void
    {
        $this->http->fail(new ConnectionException('timeout'))->push(200, ['ok' => true]);
        $this->assertSame(['ok' => true], $this->client()->get('customers'));
        $this->assertCount(1, $this->sleeps);

        $this->http->fail(new ConnectionException('timeout'));
        $this->expectException(ConnectionException::class);
        $this->client()->post('customers', []);
    }
}
