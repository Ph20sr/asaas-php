# asaas-php

[![CI](https://github.com/Ph20sr/asaas-php/actions/workflows/ci.yml/badge.svg)](https://github.com/Ph20sr/asaas-php/actions/workflows/ci.yml)
![PHP](https://img.shields.io/badge/php-%3E%3D8.1-777bb4)
![license](https://img.shields.io/badge/license-MIT-blue)

SDK PHP para a **API v3 do Asaas**, feito a partir de integrações reais de cobrança recorrente em SaaS. Cobre o que costuma dar problema em produção: cobrança duplicada, webhook repetido, rate limit e chave de sandbox usada em produção.

- Clientes, cobranças (**Pix**, **boleto**, cartão), estornos e **assinaturas**
- **Erros tipados**: `ValidationException` (com os erros por campo), `AuthenticationException`, `NotFoundException`, `RateLimitException`
- **Retries seguros**: 429 é repetido em qualquer método (respeitando `Retry-After`), mas 5xx e falhas de rede só em `GET`/`PUT`/`DELETE`. Um `POST /payments` nunca é reenviado às cegas.
- **Paginação preguiçosa** com `all()`: percorre milhares de registros sem carregar tudo na memória
- **Webhooks**: validação do token com `hash_equals`, despacho por evento e idempotência
- Ambiente detectado pela chave (`$aact_hmlg_` → sandbox); transporte HTTP plugável
- Sem dependências além de `ext-curl`

## Instalação

```bash
composer config repositories.asaas-php vcs https://github.com/Ph20sr/asaas-php
composer require ph20sr/asaas-php:^1.0
```

## Uso

```php
use Ph20sr\Asaas\Asaas;

$asaas = new Asaas(getenv('ASAAS_API_KEY'));

// Não duplica o cliente se ele já comprou antes
$customer = $asaas->customers->firstOrCreate([
    'name'    => 'Maria Silva',
    'cpfCnpj' => '529.982.247-25',
    'email'   => 'maria@exemplo.com.br',
]);

// Cobrança Pix + QR Code em uma chamada
['payment' => $payment, 'pix' => $pix] = $asaas->payments->createPix(
    $customer['id'], 149.90, '2026-10-15', ['description' => 'Plano Pro - outubro'],
);
echo $pix['payload'];       // copia-e-cola
echo $pix['encodedImage'];  // PNG em base64

// Boleto
$boleto = $asaas->payments->create([
    'customer' => $customer['id'], 'billingType' => 'BOLETO',
    'value' => 149.90, 'dueDate' => '2026-10-15',
]);
$linha = $asaas->payments->boletoLine($boleto['id'])['identificationField'];

// Assinatura mensal
$sub = $asaas->subscriptions->create([
    'customer' => $customer['id'], 'billingType' => 'UNDEFINED',
    'value' => 149.90, 'nextDueDate' => '2026-11-05', 'cycle' => 'MONTHLY',
]);

// Todas as cobranças vencidas, página a página
foreach ($asaas->payments->all(['status' => 'OVERDUE']) as $overdue) {
    // ...
}
```

### Erros

```php
use Ph20sr\Asaas\Exception\ValidationException;
use Ph20sr\Asaas\Exception\ApiException;

try {
    $asaas->customers->create(['name' => 'X', 'cpfCnpj' => '123']);
} catch (ValidationException $e) {
    $e->errors;                          // [['code' => 'invalid_cpfCnpj', 'description' => '...']]
    $e->hasErrorCode('invalid_cpfCnpj'); // true
} catch (ApiException $e) {
    $e->status;                          // HTTP status
}
```

### Webhooks

```php
use Ph20sr\Asaas\Webhook\WebhookHandler;
use Ph20sr\Asaas\Webhook\WebhookEvent;

$handler = (new WebhookHandler(getenv('ASAAS_WEBHOOK_TOKEN'), $alreadyProcessed))
    ->on('PAYMENT_RECEIVED', fn (WebhookEvent $e) => liberarAcesso($e->payment()['customer']))
    ->on('PAYMENT_OVERDUE',  fn (WebhookEvent $e) => bloquearAcesso($e->payment()['customer']));

http_response_code($handler->handle(getallheaders(), file_get_contents('php://input')));
```

`handle()` devolve **401** (token inválido), **400** (corpo inválido) ou **200**. Se um listener lança exceção, ela é propagada. A resposta vira 500 e o Asaas reenvia o evento. O callback `$alreadyProcessed` recebe o `id` do evento e evita processar o mesmo pagamento duas vezes. Faça a marcação na mesma transação das atualizações. Assim, se um listener falhar, o reenvio do evento não é descartado. Há um exemplo completo com MySQL em [`examples/webhook.php`](examples/webhook.php).

### Transporte HTTP próprio

```php
use Ph20sr\Asaas\Http\HttpClient;
use Ph20sr\Asaas\Http\Response;

final class GuzzleTransport implements HttpClient
{
    public function send(string $method, string $url, array $headers, ?string $body): Response { /* ... */ }
}

$asaas = new Asaas($key, http: new GuzzleTransport());
```

## Testes

```bash
composer install
vendor/bin/phpunit
```

Os testes usam um `FakeHttpClient` e nunca chamam a API real. O CI roda em PHP 8.1, 8.2 e 8.3.

## Licença

MIT
