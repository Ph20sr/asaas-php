<?php

declare(strict_types=1);

namespace Ph20sr\Asaas\Tests;

use Ph20sr\Asaas\Webhook\InvalidWebhookException;
use Ph20sr\Asaas\Webhook\WebhookEvent;
use Ph20sr\Asaas\Webhook\WebhookHandler;
use PHPUnit\Framework\TestCase;

final class WebhookHandlerTest extends TestCase
{
    private const TOKEN = 'whsec_0123456789abcdef';

    private function body(string $event = 'PAYMENT_RECEIVED', string $id = 'evt_1'): string
    {
        return json_encode(['id' => $id, 'event' => $event, 'payment' => ['id' => 'pay_1', 'value' => 99.9]]);
    }

    public function testRejectsMissingOrWrongToken(): void
    {
        $handler = new WebhookHandler(self::TOKEN);
        $this->assertSame(401, $handler->handle([], $this->body()));
        $this->assertSame(401, $handler->handle(['asaas-access-token' => 'errado'], $this->body()));
    }

    public function testHeaderNameIsCaseInsensitive(): void
    {
        $handler = new WebhookHandler(self::TOKEN);
        $this->assertTrue($handler->verify(['Asaas-Access-Token' => self::TOKEN]));
        $this->assertTrue($handler->verify(['ASAAS-ACCESS-TOKEN' => [self::TOKEN]]));
    }

    public function testDispatchesToSpecificAndWildcardListeners(): void
    {
        $calls = [];
        $handler = (new WebhookHandler(self::TOKEN))
            ->on('PAYMENT_RECEIVED', function (WebhookEvent $e) use (&$calls): void {
                $calls[] = 'received:' . $e->payment()['id'];
            })
            ->on('PAYMENT_OVERDUE', function () use (&$calls): void {
                $calls[] = 'overdue';
            })
            ->on('*', function (WebhookEvent $e) use (&$calls): void {
                $calls[] = 'any:' . $e->event;
            });

        $status = $handler->handle(['asaas-access-token' => self::TOKEN], $this->body());

        $this->assertSame(200, $status);
        $this->assertSame(['received:pay_1', 'any:PAYMENT_RECEIVED'], $calls);
    }

    public function testSkipsAlreadyProcessedEvents(): void
    {
        $seen = ['evt_1' => true];
        $calls = 0;
        $handler = (new WebhookHandler(self::TOKEN, fn (string $id): bool => isset($seen[$id])))
            ->on('*', function () use (&$calls): void {
                $calls++;
            });

        $headers = ['asaas-access-token' => self::TOKEN];
        $this->assertSame(200, $handler->handle($headers, $this->body(id: 'evt_1')));
        $this->assertSame(200, $handler->handle($headers, $this->body(id: 'evt_2')));
        $this->assertSame(1, $calls);
    }

    public function testInvalidBodyReturns400(): void
    {
        $handler = new WebhookHandler(self::TOKEN);
        $headers = ['asaas-access-token' => self::TOKEN];
        $this->assertSame(400, $handler->handle($headers, '{nope'));
        $this->assertSame(400, $handler->handle($headers, '{"payment":{}}'));

        $this->expectException(InvalidWebhookException::class);
        $handler->parse('[]');
    }

    public function testIsPaid(): void
    {
        $this->assertTrue((new WebhookEvent('1', 'PAYMENT_CONFIRMED', []))->isPaid());
        $this->assertFalse((new WebhookEvent('1', 'PAYMENT_OVERDUE', []))->isPaid());
    }

    public function testRequiresStrongToken(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new WebhookHandler('curto');
    }
}
