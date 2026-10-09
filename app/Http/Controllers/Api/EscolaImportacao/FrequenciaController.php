<?php

namespace App\Http\Controllers\Api\EscolaImportacao;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\EscolaImportacao\FrequenciaIndexRequest;
use App\Http\Resources\EscolaImportacao\AlunoResource;
use App\Models\SchoolInep;
use App\Services\EscolaImportacao\FrequenciaQuery;
use App\Services\iDiarioService;
use Illuminate\Http\JsonResponse;
use RuntimeException;

class FrequenciaController extends Controller
{
    public function index(FrequenciaIndexRequest $request, FrequenciaQuery $query): JsonResponse
    {
        $school = SchoolInep::query()->where('cod_escola_inep', $request->inep())->first();

        if ($school === null) {
            return response()->json(['message' => 'Escola não encontrada para o INEP informado.'], 404);
        }

        $schoolId = (int) $school->cod_escola;
        $studentIds = [];

        if ($request->cpf() !== null) {
            $studentIds = $query->studentIdsByCpf($schoolId, $request->cpf());

            if ($studentIds === []) {
                return response()->json([]);
            }
        }

        try {
            $frequencies = app(iDiarioService::class)->getDailyFrequencies($schoolId, $request->referenceDate(), $studentIds);
        } catch (RuntimeException $exception) {
            report($exception);

            return response()->json(['message' => 'Serviço de frequência indisponível.'], 502);
        }

        $enrollments = $query->enrollments(
            $schoolId,
            array_map('intval', array_column($frequencies, 'student_api_code')),
            array_map('intval', array_column($frequencies, 'classroom_api_code')),
        );

        $items = [];

        foreach ($frequencies as $frequency) {
            $key = FrequenciaQuery::key($frequency['student_api_code'] ?? null, $frequency['classroom_api_code'] ?? null);
            $enrollment = $enrollments->get($key);

            if ($enrollment === null) {
                continue;
            }

            $student = $enrollment->registration->student;

            $items[] = [
                'aluno_cpf' => AlunoResource::cpf($student->individual),
                'aluno_nome' => $student->person?->nome,
                'matricula' => (string) $enrollment->ref_cod_matricula,
                'turma' => AlunoResource::schoolClassCode($enrollment->schoolClass),
                'data_referencia' => $request->referenceDate(),
                'situacao_frequencia' => $frequency['situacao_frequencia'] ?? null,
                'professores' => array_map(fn (array $teacher) => [
                    'nome' => $teacher['nome'] ?? null,
                    'disciplina' => $teacher['disciplina'] ?? null,
                ], $frequency['professores'] ?? []),
            ];
        }

        usort($items, fn (array $a, array $b) => [mb_strtoupper((string) $a['aluno_nome']), $a['matricula']] <=> [mb_strtoupper((string) $b['aluno_nome']), $b['matricula']]);

        return response()->json($items);
    }
}
