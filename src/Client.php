<?php

declare(strict_types=1);

namespace Ph20sr\Asaas;

use Ph20sr\Asaas\Exception\ApiException;
use Ph20sr\Asaas\Exception\ConnectionException;
use Ph20sr\Asaas\Http\CurlHttpClient;
use Ph20sr\Asaas\Http\HttpClient;
use Ph20sr\Asaas\Http\Response;

/**
 * Cliente HTTP de baixo nível: autenticação, serialização, erros tipados e
 * novas tentativas com backoff exponencial em 429/5xx e falhas de conexão.
 */
final class Client
{
    public const VERSION = '1.0.0';

    private readonly HttpClient $http;
    private readonly string $baseUrl;
    /** @var callable(int): void */
    private $sleep;

    /**
     * @param callable(int): void|null $sleep recebe milissegundos (injetável nos testes)
     */
    public function __construct(
        #[\SensitiveParameter] private readonly string $apiKey,
        ?Environment $environment = null,
        ?HttpClient $http = null,
        private readonly int $maxRetries = 2,
        ?callable $sleep = null,
    ) {
        if (trim($apiKey) === '') {
            throw new \InvalidArgumentException('A chave de API do Asaas não pode ser vazia');
        }
        $this->baseUrl = ($environment ?? Environment::fromApiKey($apiKey))->baseUrl();
        $this->http = $http ?? new CurlHttpClient();
        $this->sleep = $sleep ?? static fn (int $ms) => usleep($ms * 1000);
    }

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed>|null $body
     * @return array<string, mixed>
     */
    public function request(string $method, string $path, array $query = [], ?array $body = null): array
    {
        $url = $this->baseUrl . '/' . ltrim($path, '/');
        $query = array_filter($query, static fn ($v): bool => $v !== null);
        if ($query !== []) {
            $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }

        $headers = [
            'access_token' => $this->apiKey,
            'Accept' => 'application/json',
            'User-Agent' => 'ph20sr-asaas-php/' . self::VERSION,
        ];
        $payload = null;
        if ($body !== null) {
            $headers['Content-Type'] = 'application/json';
            $payload = json_encode($body === [] ? new \stdClass() : $body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        $attempt = 0;
        while (true) {
            try {
                $response = $this->http->send($method, $url, $headers, $payload);
            } catch (ConnectionException $e) {
                if ($attempt >= $this->maxRetries || !$this->isIdempotent($method)) {
                    throw $e;
                }
                ($this->sleep)($this->backoff($attempt++, null));
                continue;
            }

            if ($response->isSuccessful()) {
                return $response->json();
            }
            if ($attempt < $this->maxRetries && $this->shouldRetry($method, $response)) {
                ($this->sleep)($this->backoff($attempt++, $response));
                continue;
            }
            throw ApiException::fromResponse($response);
        }
    }

    /** @return array<string, mixed> */
    public function get(string $path, array $query = []): array
    {
        return $this->request('GET', $path, $query);
    }

    /** @return array<string, mixed> */
    public function post(string $path, array $body = []): array
    {
        return $this->request('POST', $path, [], $body);
    }

    /** @return array<string, mixed> */
    public function put(string $path, array $body = []): array
    {
        return $this->request('PUT', $path, [], $body);
    }

    /** @return array<string, mixed> */
    public function delete(string $path): array
    {
        return $this->request('DELETE', $path);
    }

    private function isIdempotent(string $method): bool
    {
        return in_array(strtoupper($method), ['GET', 'PUT', 'DELETE'], true);
    }

    private function shouldRetry(string $method, Response $response): bool
    {
        // 429 é seguro para qualquer método: a requisição não foi processada.
        if ($response->status === 429) {
            return true;
        }
        // 5xx só em métodos idempotentes, para não duplicar cobranças.
        return $response->status >= 500 && $this->isIdempotent($method);
    }

    private function backoff(int $attempt, ?Response $response): int
    {
        $retryAfter = $response?->header('retry-after');
        if ($retryAfter !== null && ctype_digit($retryAfter)) {
            return min((int) $retryAfter * 1000, 60_000);
        }
        return (int) (250 * 2 ** $attempt) + random_int(0, 100);
    }
}
