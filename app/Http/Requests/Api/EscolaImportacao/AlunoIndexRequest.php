<?php

namespace App\Http\Requests\Api\EscolaImportacao;

use App\Services\EscolaImportacao\Cpf;
use Illuminate\Foundation\Http\FormRequest;

class AlunoIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'inep' => ['required', 'integer', 'min:1'],
            'ano' => ['nullable', 'integer', 'digits:4'],
            'aluno_cpf' => ['nullable', 'string', 'max:14'],
        ];
    }

    public function inep(): int
    {
        return (int) $this->query('inep');
    }

    public function year(): int
    {
        return (int) ($this->query('ano') ?: now()->year);
    }

    public function cpf(): ?string
    {
        return Cpf::digits($this->query('aluno_cpf'));
    }
}
