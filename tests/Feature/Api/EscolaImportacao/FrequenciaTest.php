<?php

namespace Tests\Feature\Api\EscolaImportacao;

use App\Models\LegacyRegistration;
use App\Models\LegacySchoolClass;
use App\Services\iDiarioService;
use Database\Factories\LegacyIndividualFactory;
use Database\Factories\LegacyRegistrationFactory;
use Database\Factories\LegacySchoolClassFactory;
use Database\Factories\LegacyStudentFactory;
use Database\Factories\LegacyUserFactory;
use Database\Factories\SchoolInepFactory;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Testing\TestResponse;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

class FrequenciaTest extends TestCase
{
    use DatabaseTransactions;

    private const URI = '/api/escola-importacao/escolas/frequencias';

    private const INEP = 22999001;

    private const DATE = '2026-02-26';

    private LegacySchoolClass $schoolClass;

    protected function setUp(): void
    {
        parent::setUp();

        $this->schoolClass = LegacySchoolClassFactory::new()->create([
            'nm_turma' => 'Turma B Manhã',
            'sgl_turma' => '112BM',
        ]);

        SchoolInepFactory::new()->create([
            'cod_escola' => $this->schoolClass->school_id,
            'cod_escola_inep' => self::INEP,
        ]);
    }

    private function authenticate(): void
    {
        $this->actingAs(LegacyUserFactory::new()->admin()->create());
    }

    private function request(array $query = []): TestResponse
    {
        return $this->getJson(self::URI . '?' . http_build_query($query));
    }

    private function enroll(array $individual = [], ?LegacySchoolClass $schoolClass = null): LegacyRegistration
    {
        $student = LegacyStudentFactory::new()->create([
            'ref_idpes' => LegacyIndividualFactory::new()->create($individual),
        ]);

        return LegacyRegistrationFactory::new()
            ->withStudent($student)
            ->withEnrollment($schoolClass ?? $this->schoolClass)
            ->create(['ano' => 2026]);
    }

    /**
     * @param array<int, array<string, mixed>> $teachers
     * @return array<string, mixed>
     */
    private function frequency(LegacyRegistration $registration, string $situation = 'P', array $teachers = [], ?int $schoolClassId = null): array
    {
        return [
            'student_api_code' => (string) $registration->ref_cod_aluno,
            'classroom_api_code' => (string) ($schoolClassId ?? $this->schoolClass->cod_turma),
            'situacao_frequencia' => $situation,
            'aulas' => 1,
            'faltas' => $situation === 'P' ? 0 : 1,
            'professores' => $teachers,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $frequencies
     * @param array<int, int>|null $studentIds
     */
    private function mockIDiario(array $frequencies, ?array $studentIds = []): void
    {
        $this->mock(iDiarioService::class, function (MockInterface $mock) use ($frequencies, $studentIds) {
            $mock->shouldReceive('getDailyFrequencies')
                ->once()
                ->with($this->schoolClass->school_id, self::DATE, $studentIds)
                ->andReturn($frequencies);
        });
    }

    private function iDiarioMustNotBeCalled(): void
    {
        $this->mock(iDiarioService::class, function (MockInterface $mock) {
            $mock->shouldNotReceive('getDailyFrequencies');
        });
    }

    /** CA-40 */
    public function test_requires_authentication(): void
    {
        $this->request(['inep' => self::INEP, 'data' => self::DATE])->assertUnauthorized();
    }

    /** CA-40 */
    public function test_validates_parameters(): void
    {
        $this->authenticate();
        $this->iDiarioMustNotBeCalled();

        $this->request(['data' => self::DATE])->assertUnprocessable()->assertJsonValidationErrors('inep');
        $this->request(['inep' => self::INEP])->assertUnprocessable()->assertJsonValidationErrors('data');
        $this->request(['inep' => self::INEP, 'data' => '26/02/2026'])->assertUnprocessable()->assertJsonValidationErrors('data');
        $this->request(['inep' => self::INEP, 'data' => '2026-02-31'])->assertUnprocessable()->assertJsonValidationErrors('data');
        $this->request(['inep' => self::INEP, 'data' => now()->addDay()->format('Y-m-d')])->assertUnprocessable()->assertJsonValidationErrors('data');
    }

    /** CA-41 */
    public function test_unknown_inep_returns_not_found_without_calling_idiario(): void
    {
        $this->authenticate();
        $this->iDiarioMustNotBeCalled();

        $this->request(['inep' => 22999999, 'data' => self::DATE])
            ->assertNotFound()
            ->assertJson(['message' => 'Escola não encontrada para o INEP informado.']);
    }

    /** CA-42, CA-47 */
    public function test_merges_idiario_frequency_with_student_data(): void
    {
        $this->authenticate();

        $registration = $this->enroll(['cpf' => 1234567891]);

        $this->mockIDiario([
            $this->frequency($registration, 'P', [
                ['teacher_api_code' => '89', 'nome' => 'PROFESSOR DE TESTE', 'disciplina' => 'GEOGRAFIA'],
            ]),
        ]);

        $this->request(['inep' => self::INEP, 'data' => self::DATE])
            ->assertOk()
            ->assertExactJson([
                [
                    'aluno_cpf' => '01234567891',
                    'aluno_nome' => $registration->student->person->nome,
                    'matricula' => (string) $registration->cod_matricula,
                    'turma' => '112BM',
                    'data_referencia' => self::DATE,
                    'situacao_frequencia' => 'P',
                    'professores' => [
                        ['nome' => 'PROFESSOR DE TESTE', 'disciplina' => 'GEOGRAFIA'],
                    ],
                ],
            ]);
    }

    public function test_keeps_students_whose_enrollment_is_no_longer_active(): void
    {
        $this->authenticate();

        $registration = $this->enroll();
        $registration->enrollments()->update(['ativo' => 0]);

        $this->mockIDiario([$this->frequency($registration, 'F')]);

        $this->request(['inep' => self::INEP, 'data' => self::DATE])
            ->assertJsonCount(1)
            ->assertJsonPath('0.situacao_frequencia', 'F');
    }

    /** CA-43 */
    public function test_discards_students_that_are_not_enrolled_in_the_school(): void
    {
        $this->authenticate();

        $otherSchoolClass = LegacySchoolClassFactory::new()->create();
        $fromOtherSchool = $this->enroll([], $otherSchoolClass);
        $enrolled = $this->enroll();

        $this->mockIDiario([
            $this->frequency($fromOtherSchool, 'P', [], $otherSchoolClass->cod_turma),
            $this->frequency($enrolled, 'P', [], 999999999),
            ['student_api_code' => '999999999', 'classroom_api_code' => (string) $this->schoolClass->cod_turma, 'situacao_frequencia' => 'P'],
        ]);

        $this->request(['inep' => self::INEP, 'data' => self::DATE])->assertOk()->assertExactJson([]);
    }

    /** CA-44 */
    public function test_filters_by_student_cpf(): void
    {
        $this->authenticate();

        $target = $this->enroll(['cpf' => 1234567891]);
        $this->enroll(['cpf' => 12345678901]);

        $this->mockIDiario([$this->frequency($target)], [$target->ref_cod_aluno]);

        $this->request(['inep' => self::INEP, 'data' => self::DATE, 'aluno_cpf' => '012.345.678-91'])
            ->assertJsonCount(1)
            ->assertJsonPath('0.matricula', (string) $target->cod_matricula);
    }

    /** CA-44 */
    public function test_unknown_cpf_returns_empty_without_calling_idiario(): void
    {
        $this->authenticate();
        $this->iDiarioMustNotBeCalled();

        $this->enroll(['cpf' => 1234567891]);

        $this->request(['inep' => self::INEP, 'data' => self::DATE, 'aluno_cpf' => '11111111111'])
            ->assertOk()
            ->assertExactJson([]);
    }

    /** CA-45 */
    public function test_returns_bad_gateway_when_idiario_fails(): void
    {
        $this->authenticate();

        $this->mock(iDiarioService::class, function (MockInterface $mock) {
            $mock->shouldReceive('getDailyFrequencies')->andThrow(new RuntimeException('falha'));
        });

        $this->request(['inep' => self::INEP, 'data' => self::DATE])
            ->assertStatus(502)
            ->assertExactJson(['message' => 'Serviço de frequência indisponível.']);
    }

    /** CA-45 */
    public function test_returns_bad_gateway_when_idiario_is_not_configured(): void
    {
        $this->authenticate();

        $this->app->bind(iDiarioService::class, function () {
            throw new RuntimeException('É necessário configurar a URL e Token de integração com o i-Diário.');
        });

        $this->request(['inep' => self::INEP, 'data' => self::DATE])->assertStatus(502);
    }

    /** CA-46 */
    public function test_returns_empty_array_when_there_is_no_frequency(): void
    {
        $this->authenticate();
        $this->mockIDiario([]);

        $this->request(['inep' => self::INEP, 'data' => self::DATE])->assertOk()->assertExactJson([]);
    }

    public function test_orders_by_student_name(): void
    {
        $this->authenticate();

        $frequencies = [];

        foreach (['Carla', 'Ana', 'Bruno'] as $name) {
            $student = LegacyStudentFactory::new()->create([
                'ref_idpes' => LegacyIndividualFactory::new()->withName($name)->create(),
            ]);

            $frequencies[] = $this->frequency(
                LegacyRegistrationFactory::new()->withStudent($student)->withEnrollment($this->schoolClass)->create(['ano' => 2026])
            );
        }

        $this->mockIDiario($frequencies);

        $names = array_column($this->request(['inep' => self::INEP, 'data' => self::DATE])->json(), 'aluno_nome');

        $this->assertSame(['Ana', 'Bruno', 'Carla'], $names);
    }
}
