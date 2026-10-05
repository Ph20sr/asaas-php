<?php

declare(strict_types=1);

namespace Ph20sr\Asaas\Webhook;

final class WebhookEvent
{
    /** @param array<string, mixed> $payload */
    public function __construct(
        public readonly string $id,
        public readonly string $event,
        public readonly array $payload,
    ) {
    }

    /** Objeto principal do evento (payment, subscription...), se houver. */
    public function payment(): ?array
    {
        return is_array($this->payload['payment'] ?? null) ? $this->payload['payment'] : null;
    }

    public function subscription(): ?array
    {
        return is_array($this->payload['subscription'] ?? null) ? $this->payload['subscription'] : null;
    }

    /** O dinheiro entrou (Pix/boleto compensado ou cartão confirmado). */
    public function isPaid(): bool
    {
        return in_array($this->event, ['PAYMENT_RECEIVED', 'PAYMENT_CONFIRMED'], true);
    }
}
