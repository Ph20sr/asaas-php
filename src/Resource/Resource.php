<?php

declare(strict_types=1);

namespace Ph20sr\Asaas\Resource;

use Ph20sr\Asaas\Client;
use Ph20sr\Asaas\Page;

/** CRUD comum aos endpoints REST do Asaas. */
abstract class Resource
{
    protected const PATH = '';
    private const MAX_PAGE_SIZE = 100;

    public function __construct(protected readonly Client $client)
    {
    }

    /** @return array<string, mixed> */
    public function create(array $data): array
    {
        return $this->client->post(static::PATH, $data);
    }

    /** @return array<string, mixed> */
    public function find(string $id): array
    {
        return $this->client->get(static::PATH . '/' . rawurlencode($id));
    }

    /** @return array<string, mixed> */
    public function update(string $id, array $data): array
    {
        return $this->client->put(static::PATH . '/' . rawurlencode($id), $data);
    }

    /** @return array<string, mixed> */
    public function delete(string $id): array
    {
        return $this->client->delete(static::PATH . '/' . rawurlencode($id));
    }

    public function list(array $filters = [], int $offset = 0, int $limit = 10): Page
    {
        $limit = max(1, min($limit, self::MAX_PAGE_SIZE));
        return Page::fromJson($this->client->get(static::PATH, [...$filters, 'offset' => $offset, 'limit' => $limit]));
    }

    /**
     * Percorre todas as páginas sob demanda, sem carregar tudo na memória.
     *
     * @return \Generator<int, array<string, mixed>>
     */
    public function all(array $filters = [], int $pageSize = self::MAX_PAGE_SIZE): \Generator
    {
        $offset = 0;
        do {
            $page = $this->list($filters, $offset, $pageSize);
            yield from $page->data;
            $offset += count($page->data);
        } while ($page->hasMore && count($page->data) > 0);
    }
}
