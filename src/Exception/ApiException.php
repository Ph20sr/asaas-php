<?php

declare(strict_types=1);

namespace Ph20sr\Asaas\Exception;

use Ph20sr\Asaas\Http\Response;

/** O Asaas respondeu com erro (4xx/5xx). */
class ApiException extends AsaasException
{
    /**
     * @param list<array{code: string, description: string}> $errors
     */
    public function __construct(
        string $message,
        public readonly int $status,
        public readonly array $errors = [],
        public readonly ?Response $response = null,
    ) {
        parent::__construct($message, $status);
    }

    public static function fromResponse(Response $response): self
    {
        $errors = array_values(array_filter(
            $response->json()['errors'] ?? [],
            static fn ($e): bool => is_array($e),
        ));
        $errors = array_map(static fn (array $e): array => [
            'code' => (string) ($e['code'] ?? ''),
            'description' => (string) ($e['description'] ?? ''),
        ], $errors);

        $message = $errors !== []
            ? implode('; ', array_column($errors, 'description'))
            : "Asaas respondeu HTTP {$response->status}";

        return match (true) {
            $response->status === 400 => new ValidationException($message, 400, $errors, $response),
            $response->status === 401 => new AuthenticationException($message, 401, $errors, $response),
            $response->status === 404 => new NotFoundException($message, 404, $errors, $response),
            $response->status === 429 => new RateLimitException($message, 429, $errors, $response),
            default => new self($message, $response->status, $errors, $response),
        };
    }

    public function hasErrorCode(string $code): bool
    {
        return in_array($code, array_column($this->errors, 'code'), true);
    }
}
