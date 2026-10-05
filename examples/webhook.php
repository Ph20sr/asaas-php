<?php

/**
 * Endpoint de webhook do Asaas pronto para produção.
 * Configure no painel: Integrações > Webhooks > URL = https://seu-dominio/webhook.php
 * e o mesmo token em ASAAS_WEBHOOK_TOKEN.
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Ph20sr\Asaas\Webhook\WebhookEvent;
use Ph20sr\Asaas\Webhook\WebhookHandler;

$pdo = new PDO(getenv('DB_DSN'), getenv('DB_USER'), getenv('DB_PASS'), [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

// Tabela de idempotência: CREATE TABLE webhook_events (id VARCHAR(64) PRIMARY KEY, received_at DATETIME)
$alreadyProcessed = static function (string $id) use ($pdo): bool {
    $stmt = $pdo->prepare('INSERT IGNORE INTO webhook_events (id, received_at) VALUES (?, NOW())');
    $stmt->execute([$id]);
    return $stmt->rowCount() === 0;
};

$handler = (new WebhookHandler((string) getenv('ASAAS_WEBHOOK_TOKEN'), $alreadyProcessed))
    ->on('PAYMENT_RECEIVED', static function (WebhookEvent $event) use ($pdo): void {
        $payment = $event->payment();
        $pdo->prepare('UPDATE invoices SET status = ?, paid_at = NOW() WHERE asaas_id = ?')
            ->execute(['paid', $payment['id']]);
    })
    ->on('PAYMENT_CONFIRMED', static function (WebhookEvent $event) use ($pdo): void {
        $pdo->prepare('UPDATE invoices SET status = ? WHERE asaas_id = ?')
            ->execute(['paid', $event->payment()['id']]);
    })
    ->on('PAYMENT_OVERDUE', static function (WebhookEvent $event) use ($pdo): void {
        $pdo->prepare('UPDATE invoices SET status = ? WHERE asaas_id = ?')
            ->execute(['overdue', $event->payment()['id']]);
    });

// A marcação de idempotência e as atualizações ficam na mesma transação:
// se um listener falhar, o id do evento não fica registrado e o reenvio
// do Asaas será processado normalmente.
$pdo->beginTransaction();
try {
    $status = $handler->handle(getallheaders(), (string) file_get_contents('php://input'));
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    error_log('Falha ao processar webhook do Asaas: ' . $e->getMessage());
    $status = 500;
}

http_response_code($status);
