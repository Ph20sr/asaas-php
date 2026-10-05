<?php

declare(strict_types=1);

namespace Ph20sr\Asaas\Webhook;

/**
 * Valida e despacha webhooks do Asaas.
 *
 *     $handler = (new WebhookHandler(getenv('ASAAS_WEBHOOK_TOKEN')))
 *         ->on('PAYMENT_RECEIVED', fn (WebhookEvent $e) => liberarAcesso($e->payment()))
 *         ->on('PAYMENT_OVERDUE', fn (WebhookEvent $e) => avisarAtraso($e->payment()));
 *
 *     http_response_code($handler->handle(getallheaders(), file_get_contents('php://input')));
 *
 * O Asaas reenvia o evento enquanto não receber 2xx e pausa a fila após
 * erros seguidos, então os handlers devem ser idempotentes. Use
 * $event->id (ou o callback $alreadyProcessed) para ignorar repetidos.
 */
final class WebhookHandler
{
    public const TOKEN_HEADER = 'asaas-access-token';

    /** @var array<string, list<callable(WebhookEvent): void>> */
    private array $listeners = [];
    /** @var callable(string): bool|null */
    private $alreadyProcessed;

    /**
     * @param callable(string): bool|null $alreadyProcessed recebe o id do evento
     */
    public function __construct(
        #[\SensitiveParameter] private readonly string $token,
        ?callable $alreadyProcessed = null,
    ) {
        if (strlen($token) < 16) {
            throw new \InvalidArgumentException('Use um token de webhook com pelo menos 16 caracteres');
        }
        $this->alreadyProcessed = $alreadyProcessed;
    }

    /** @param callable(WebhookEvent): void $listener use '*' para todos os eventos */
    public function on(string $event, callable $listener): self
    {
        $this->listeners[$event][] = $listener;
        return $this;
    }

    /** @param array<string, string|string[]> $headers */
    public function verify(array $headers): bool
    {
        foreach ($headers as $name => $value) {
            if (strtolower((string) $name) === self::TOKEN_HEADER) {
                $received = is_array($value) ? (string) ($value[0] ?? '') : (string) $value;
                return hash_equals($this->token, $received);
            }
        }
        return false;
    }

    /** @throws InvalidWebhookException */
    public function parse(string $body): WebhookEvent
    {
        try {
            $payload = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new InvalidWebhookException('Corpo do webhook não é um JSON válido', 0, $e);
        }
        if (!is_array($payload) || !is_string($payload['event'] ?? null)) {
            throw new InvalidWebhookException('Webhook sem o campo "event"');
        }
        return new WebhookEvent((string) ($payload['id'] ?? ''), $payload['event'], $payload);
    }

    /**
     * Valida, interpreta e despacha. Devolve o status HTTP a responder:
     * 401 token inválido, 400 corpo inválido, 200 processado (ou repetido).
     * Exceções dos listeners são propagadas para que o Asaas reenvie.
     *
     * @param array<string, string|string[]> $headers
     */
    public function handle(array $headers, string $body): int
    {
        if (!$this->verify($headers)) {
            return 401;
        }
        try {
            $event = $this->parse($body);
        } catch (InvalidWebhookException) {
            return 400;
        }

        if ($event->id !== '' && $this->alreadyProcessed !== null && ($this->alreadyProcessed)($event->id)) {
            return 200;
        }

        foreach ([...($this->listeners[$event->event] ?? []), ...($this->listeners['*'] ?? [])] as $listener) {
            $listener($event);
        }
        return 200;
    }
}
