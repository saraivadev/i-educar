<?php

namespace App\Services\EscolaImportacao;

class Cpf
{
    /**
     * Converte o CPF armazenado como número em uma string de 11 dígitos.
     */
    public static function format(int|string|null $cpf): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $cpf);

        if ($digits === '' || (int) $digits === 0) {
            return null;
        }

        return str_pad($digits, 11, '0', STR_PAD_LEFT);
    }

    /**
     * Normaliza um CPF informado com ou sem máscara.
     */
    public static function digits(?string $cpf): ?string
    {
        return self::format($cpf);
    }
}
