<?php

declare(strict_types=1);

namespace Ph20sr\Asaas\Resource;

final class Customers extends Resource
{
    protected const PATH = 'customers';

    /** Busca um cliente pelo CPF/CNPJ (com ou sem máscara). */
    public function findByDocument(string $cpfCnpj): ?array
    {
        $document = preg_replace('/[^0-9A-Za-z]/', '', $cpfCnpj);
        $page = $this->list(['cpfCnpj' => strtoupper((string) $document)], 0, 1);
        return $page->data[0] ?? null;
    }

    /**
     * Retorna o cliente com esse CPF/CNPJ, criando-o se ainda não existir.
     * Evita clientes duplicados quando o mesmo comprador volta.
     *
     * @param array<string, mixed> $data precisa conter 'cpfCnpj' e 'name'
     * @return array<string, mixed>
     */
    public function firstOrCreate(array $data): array
    {
        if (empty($data['cpfCnpj'])) {
            throw new \InvalidArgumentException('firstOrCreate exige o campo cpfCnpj');
        }
        return $this->findByDocument((string) $data['cpfCnpj']) ?? $this->create($data);
    }

    /** @return array<string, mixed> */
    public function restore(string $id): array
    {
        return $this->client->post(self::PATH . '/' . rawurlencode($id) . '/restore');
    }
}
