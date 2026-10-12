<?php

namespace App\Http\Resources\EscolaImportacao;

use App\Models\LegacyEnrollment;
use App\Models\LegacyIndividual;
use App\Models\LegacyPerson;
use App\Models\LegacySchoolClass;
use App\Services\EscolaImportacao\Cpf;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin LegacyEnrollment
 */
class AlunoResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $registration = $this->registration;
        $student = $registration->student;
        $individual = $student->individual;

        return [
            'matricula' => (string) $registration->cod_matricula,
            'aluno_nome' => $student->person?->nome,
            'sexo' => self::gender($individual),
            'aluno_cpf' => self::cpf($individual),
            'data_nascimento' => $individual?->data_nasc?->format('Y-m-d'),
            'turma_codigo' => self::schoolClassCode($this->schoolClass),
            'serie_descricao' => $registration->grade?->nm_serie,
            'nivelEnsino' => $registration->course?->educationLevel?->nm_nivel ?: $registration->course?->nm_curso,
            'comorbidades' => $student->deficiencies->pluck('nm_deficiencia')->values()->all(),
            'responsaveis' => $this->guardians($individual, $student->person ? self::phone($student->person) : null),
            'professores' => $this->teachers($this->schoolClass),
        ];
    }

    public static function schoolClassCode(?LegacySchoolClass $schoolClass): ?string
    {
        return trim((string) $schoolClass?->sgl_turma) ?: $schoolClass?->nm_turma;
    }

    public static function cpf(?LegacyIndividual $individual): ?string
    {
        return Cpf::format($individual?->getRawOriginal('cpf'));
    }

    private static function gender(?LegacyIndividual $individual): ?string
    {
        $gender = strtoupper(trim((string) $individual?->sexo));

        return in_array($gender, ['M', 'F'], true) ? $gender : null;
    }

    /**
     * Mãe, pai e responsável, sem repetir a mesma pessoa. As escolas costumam
     * registrar o contato da família na ficha do aluno, então esse telefone é
     * usado quando o responsável não tem um próprio.
     *
     * @return array<int, array<string, mixed>>
     */
    private function guardians(?LegacyIndividual $individual, ?string $studentPhone): array
    {
        if ($individual === null) {
            return [];
        }

        $guardians = [];

        $candidates = [
            'mae' => $individual->mother,
            'pai' => $individual->father,
            'responsavel' => $individual->responsible,
        ];

        foreach ($candidates as $kinship => $person) {
            if ($person === null || isset($guardians[$person->idpes])) {
                continue;
            }

            $guardians[$person->idpes] = [
                'nome' => $person->nome,
                'parentesco' => $kinship,
                'telefone' => self::phone($person) ?? $studentPhone,
                'responsavel_cpf' => self::cpf($person->individual),
                'data_nascimento' => $person->individual?->data_nasc?->format('Y-m-d'),
            ];
        }

        return array_values($guardians);
    }

    private static function phone(LegacyPerson $person): ?string
    {
        $phone = $person->phones
            ->filter(fn ($phone) => (int) $phone->fone > 0)
            ->sortBy('tipo')
            ->first();

        if ($phone === null) {
            return null;
        }

        return ((int) $phone->ddd ?: '') . (int) $phone->fone;
    }

    /**
     * Um item por professor e disciplina.
     *
     * @return array<int, array<string, mixed>>
     */
    private function teachers(?LegacySchoolClass $schoolClass): array
    {
        $teachers = [];

        foreach ($schoolClass?->schoolClassTeachers ?? [] as $link) {
            $employee = $link->employee;

            if ($employee === null) {
                continue;
            }

            $disciplines = $link->disciplines->pluck('nome')->all() ?: [null];

            foreach ($disciplines as $discipline) {
                $teachers[$employee->cod_servidor . '|' . $discipline] = [
                    'nome' => $employee->person?->nome,
                    'professor_cpf' => self::cpf($employee->individual),
                    'disciplina' => $discipline,
                ];
            }
        }

        return array_values($teachers);
    }
}
