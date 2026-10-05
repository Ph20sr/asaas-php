<?php

declare(strict_types=1);

namespace Ph20sr\Asaas;

enum Environment: string
{
    case Production = 'production';
    case Sandbox = 'sandbox';

    public function baseUrl(): string
    {
        return match ($this) {
            self::Production => 'https://api.asaas.com/v3',
            self::Sandbox => 'https://api-sandbox.asaas.com/v3',
        };
    }

    /** Chaves de produção começam com $aact_prod_, as de sandbox com $aact_hmlg_. */
    public static function fromApiKey(string $apiKey): self
    {
        return str_contains($apiKey, '_hmlg_') ? self::Sandbox : self::Production;
    }
}
