<?php

declare(strict_types=1);

namespace Ph20sr\Asaas\Resource;

use Ph20sr\Asaas\Page;

final class Subscriptions extends Resource
{
    protected const PATH = 'subscriptions';

    public const CYCLE_WEEKLY = 'WEEKLY';
    public const CYCLE_BIWEEKLY = 'BIWEEKLY';
    public const CYCLE_MONTHLY = 'MONTHLY';
    public const CYCLE_BIMONTHLY = 'BIMONTHLY';
    public const CYCLE_QUARTERLY = 'QUARTERLY';
    public const CYCLE_SEMIANNUALLY = 'SEMIANNUALLY';
    public const CYCLE_YEARLY = 'YEARLY';

    /** Cobranças geradas por uma assinatura. */
    public function payments(string $id, array $filters = [], int $offset = 0, int $limit = 10): Page
    {
        return Page::fromJson($this->client->get(
            self::PATH . '/' . rawurlencode($id) . '/payments',
            [...$filters, 'offset' => $offset, 'limit' => max(1, min($limit, 100))],
        ));
    }
}
