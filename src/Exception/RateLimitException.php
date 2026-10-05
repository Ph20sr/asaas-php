<?php

declare(strict_types=1);

namespace Ph20sr\Asaas\Exception;

/** Limite de requisições atingido (HTTP 429), mesmo após as novas tentativas. */
final class RateLimitException extends ApiException
{
}
