<?php

namespace App\Services\EscolaImportacao;

use App\Models\LegacyEnrollment;
use App_Model_MatriculaSituacao;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class AlunoQuery
{
    /**
     * Enturmações ativas de matrículas em andamento da escola no ano letivo.
     *
     * @return Collection<int, LegacyEnrollment>
     */
    public function get(int $schoolId, int $year, ?string $cpf = null): Collection
    {
        return LegacyEnrollment::query()
            ->active()
            ->whereHas('registration', function (Builder $query) use ($schoolId, $year, $cpf) {
                $query->where('ref_ref_cod_escola', $schoolId)
                    ->where('ano', $year)
                    ->where('ativo', 1)
                    ->where('aprovado', App_Model_MatriculaSituacao::EM_ANDAMENTO);

                if ($cpf !== null) {
                    $query->whereHas('student.individual', fn (Builder $query) => $query->where('cpf', (int) $cpf));
                }
            })
            ->with([
                'registration.student.person',
                'registration.student.deficiencies',
                'registration.student.individual.mother.individual',
                'registration.student.individual.mother.phones',
                'registration.student.individual.father.individual',
                'registration.student.individual.father.phones',
                'registration.student.individual.responsible.individual',
                'registration.student.individual.responsible.phones',
                'registration.grade',
                'registration.course.educationLevel',
                'schoolClass.schoolClassTeachers.employee.person',
                'schoolClass.schoolClassTeachers.employee.individual',
                'schoolClass.schoolClassTeachers.disciplines',
            ])
            ->get()
            ->sortBy(fn (LegacyEnrollment $enrollment) => [
                mb_strtoupper((string) $enrollment->registration->student->person?->nome),
                $enrollment->ref_cod_matricula,
            ])
            ->values();
    }
}
