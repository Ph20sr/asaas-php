<?php

declare(strict_types=1);

namespace Ph20sr\Asaas;

/**
 * Página de uma listagem do Asaas.
 *
 * @implements \IteratorAggregate<int, array<string, mixed>>
 */
final class Page implements \IteratorAggregate, \Countable
{
    /** @param list<array<string, mixed>> $data */
    public function __construct(
        public readonly array $data,
        public readonly int $totalCount,
        public readonly bool $hasMore,
        public readonly int $offset,
        public readonly int $limit,
    ) {
    }

    /** @param array<string, mixed> $json */
    public static function fromJson(array $json): self
    {
        return new self(
            array_values($json['data'] ?? []),
            (int) ($json['totalCount'] ?? 0),
            (bool) ($json['hasMore'] ?? false),
            (int) ($json['offset'] ?? 0),
            (int) ($json['limit'] ?? 0),
        );
    }

    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->data);
    }

    public function count(): int
    {
        return count($this->data);
    }
}
