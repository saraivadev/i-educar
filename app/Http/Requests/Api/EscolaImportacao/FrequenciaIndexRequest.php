<?php

namespace App\Http\Requests\Api\EscolaImportacao;

use App\Services\EscolaImportacao\Cpf;
use Illuminate\Foundation\Http\FormRequest;

class FrequenciaIndexRequest extends FormRequest
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
            'data' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'aluno_cpf' => ['nullable', 'string', 'max:14'],
        ];
    }

    public function inep(): int
    {
        return (int) $this->query('inep');
    }

    public function referenceDate(): string
    {
        return (string) $this->query('data');
    }

    public function cpf(): ?string
    {
        return Cpf::digits($this->query('aluno_cpf'));
    }
}
