<?php

declare(strict_types=1);

namespace Ph20sr\Asaas\Tests;

use Ph20sr\Asaas\Http\HttpClient;
use Ph20sr\Asaas\Http\Response;

/** Devolve respostas enfileiradas e registra cada requisição enviada. */
final class FakeHttpClient implements HttpClient
{
    /** @var list<array{method: string, url: string, headers: array<string, string>, body: ?string}> */
    public array $requests = [];
    /** @var list<Response|\Throwable> */
    private array $queue = [];

    public function push(int $status, array|string $body = [], array $headers = []): self
    {
        $this->queue[] = new Response($status, is_string($body) ? $body : json_encode($body), $headers);
        return $this;
    }

    public function fail(\Throwable $error): self
    {
        $this->queue[] = $error;
        return $this;
    }

    public function send(string $method, string $url, array $headers, ?string $body): Response
    {
        $this->requests[] = compact('method', 'url', 'headers', 'body');
        $next = array_shift($this->queue) ?? throw new \LogicException("Requisição inesperada: {$method} {$url}");
        if ($next instanceof \Throwable) {
            throw $next;
        }
        return $next;
    }

    public function last(): array
    {
        return $this->requests[array_key_last($this->requests)];
    }
}
