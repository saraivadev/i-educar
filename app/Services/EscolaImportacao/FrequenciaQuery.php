<?php

namespace App\Services\EscolaImportacao;

use App\Models\LegacyEnrollment;
use App\Models\LegacyRegistration;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class FrequenciaQuery
{
    /**
     * Códigos dos alunos com matrícula na escola para o CPF informado.
     *
     * @return array<int, int>
     */
    public function studentIdsByCpf(int $schoolId, string $cpf): array
    {
        return LegacyRegistration::query()
            ->where('ref_ref_cod_escola', $schoolId)
            ->where('ativo', 1)
            ->whereHas('student.individual', fn (Builder $query) => $query->where('cpf', (int) $cpf))
            ->pluck('ref_cod_aluno')
            ->unique()
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    /**
     * Enturmações da escola indexadas por "aluno|turma". A enturmação pode já estar
     * inativa: a frequência de uma data passada continua valendo.
     *
     * @param array<int, int> $studentIds
     * @param array<int, int> $schoolClassIds
     * @return Collection<string, LegacyEnrollment>
     */
    public function enrollments(int $schoolId, array $studentIds, array $schoolClassIds): Collection
    {
        if ($studentIds === [] || $schoolClassIds === []) {
            return new Collection;
        }

        return LegacyEnrollment::query()
            ->whereIn('ref_cod_turma', $schoolClassIds)
            ->whereHas('registration', function (Builder $query) use ($schoolId, $studentIds) {
                $query->where('ref_ref_cod_escola', $schoolId)
                    ->where('ativo', 1)
                    ->whereIn('ref_cod_aluno', $studentIds);
            })
            ->with([
                'registration.student.person',
                'registration.student.individual',
                'schoolClass',
            ])
            ->orderByDesc('ativo')
            ->orderByDesc('sequencial')
            ->get()
            ->unique(fn (LegacyEnrollment $enrollment) => self::key($enrollment->registration->ref_cod_aluno, $enrollment->ref_cod_turma))
            ->keyBy(fn (LegacyEnrollment $enrollment) => self::key($enrollment->registration->ref_cod_aluno, $enrollment->ref_cod_turma));
    }

    public static function key(int|string|null $studentId, int|string|null $schoolClassId): string
    {
        return (int) $studentId . '|' . (int) $schoolClassId;
    }
}
