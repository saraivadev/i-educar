<?php

namespace Tests\Unit\EscolaImportacao;

use App\Services\EscolaImportacao\Cpf;
use PHPUnit\Framework\TestCase;

class CpfTest extends TestCase
{
    public function test_format_pads_with_leading_zeros(): void
    {
        $this->assertSame('01234567891', Cpf::format(1234567891));
        $this->assertSame('01234567891', Cpf::format('1234567891'));
        $this->assertSame('12345678901', Cpf::format('12345678901'));
    }

    public function test_format_returns_null_when_empty(): void
    {
        $this->assertNull(Cpf::format(null));
        $this->assertNull(Cpf::format(''));
        $this->assertNull(Cpf::format(0));
    }

    public function test_digits_removes_mask(): void
    {
        $this->assertSame('01234567891', Cpf::digits('012.345.678-91'));
        $this->assertSame('01234567891', Cpf::digits('01234567891'));
        $this->assertNull(Cpf::digits(''));
        $this->assertNull(Cpf::digits(null));
    }
}
