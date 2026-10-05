<?php

declare(strict_types=1);

namespace Ph20sr\Asaas\Resource;

final class Payments extends Resource
{
    protected const PATH = 'payments';

    public const BILLING_PIX = 'PIX';
    public const BILLING_BOLETO = 'BOLETO';
    public const BILLING_CREDIT_CARD = 'CREDIT_CARD';
    public const BILLING_UNDEFINED = 'UNDEFINED';

    /**
     * Atalho para criar uma cobrança Pix e já devolver o QR Code.
     *
     * @return array{payment: array<string, mixed>, pix: array<string, mixed>}
     */
    public function createPix(string $customerId, float $value, string $dueDate, array $extra = []): array
    {
        $payment = $this->create([
            ...$extra,
            'customer' => $customerId,
            'billingType' => self::BILLING_PIX,
            'value' => round($value, 2),
            'dueDate' => $dueDate,
        ]);
        return ['payment' => $payment, 'pix' => $this->pixQrCode((string) $payment['id'])];
    }

    /** QR Code (imagem em base64) e copia-e-cola do Pix. */
    public function pixQrCode(string $id): array
    {
        return $this->client->get(self::PATH . '/' . rawurlencode($id) . '/pixQrCode');
    }

    /** Linha digitável e código de barras do boleto. */
    public function boletoLine(string $id): array
    {
        return $this->client->get(self::PATH . '/' . rawurlencode($id) . '/identificationField');
    }

    /** Estorno total (sem $value) ou parcial. */
    public function refund(string $id, ?float $value = null, ?string $description = null): array
    {
        return $this->client->post(self::PATH . '/' . rawurlencode($id) . '/refund', array_filter([
            'value' => $value === null ? null : round($value, 2),
            'description' => $description,
        ], static fn ($v): bool => $v !== null));
    }

    /** Registra um recebimento feito fora do Asaas (dinheiro, transferência...). */
    public function receiveInCash(string $id, string $paymentDate, float $value, bool $notifyCustomer = false): array
    {
        return $this->client->post(self::PATH . '/' . rawurlencode($id) . '/receiveInCash', [
            'paymentDate' => $paymentDate,
            'value' => round($value, 2),
            'notifyCustomer' => $notifyCustomer,
        ]);
    }

    /** Status atual da cobrança (PENDING, RECEIVED, CONFIRMED, OVERDUE...). */
    public function status(string $id): string
    {
        return (string) ($this->client->get(self::PATH . '/' . rawurlencode($id) . '/status')['status'] ?? '');
    }
}
