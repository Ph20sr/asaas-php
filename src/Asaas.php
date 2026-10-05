<?php

declare(strict_types=1);

namespace Ph20sr\Asaas;

use Ph20sr\Asaas\Http\HttpClient;
use Ph20sr\Asaas\Resource\Customers;
use Ph20sr\Asaas\Resource\Payments;
use Ph20sr\Asaas\Resource\Subscriptions;

/**
 * Ponto de entrada do SDK.
 *
 *     $asaas = new Asaas(getenv('ASAAS_API_KEY'));
 *     $customer = $asaas->customers->firstOrCreate([...]);
 */
final class Asaas
{
    public readonly Client $client;
    public readonly Customers $customers;
    public readonly Payments $payments;
    public readonly Subscriptions $subscriptions;

    public function __construct(
        #[\SensitiveParameter] string $apiKey,
        ?Environment $environment = null,
        ?HttpClient $http = null,
        int $maxRetries = 2,
    ) {
        $this->client = new Client($apiKey, $environment, $http, $maxRetries);
        $this->customers = new Customers($this->client);
        $this->payments = new Payments($this->client);
        $this->subscriptions = new Subscriptions($this->client);
    }
}
