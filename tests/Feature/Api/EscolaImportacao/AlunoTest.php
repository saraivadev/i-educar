<?php

namespace Tests\Feature\Api\EscolaImportacao;

use App\Models\LegacyIndividual;
use App\Models\LegacyRegistration;
use App\Models\LegacySchoolClass;
use App_Model_MatriculaSituacao;
use Database\Factories\EmployeeFactory;
use Database\Factories\LegacyDeficiencyFactory;
use Database\Factories\LegacyDisciplineFactory;
use Database\Factories\LegacyIndividualFactory;
use Database\Factories\LegacyPhoneFactory;
use Database\Factories\LegacyRegistrationFactory;
use Database\Factories\LegacySchoolClassFactory;
use Database\Factories\LegacySchoolClassTeacherDisciplineFactory;
use Database\Factories\LegacySchoolClassTeacherFactory;
use Database\Factories\LegacyStudentFactory;
use Database\Factories\LegacyUserFactory;
use Database\Factories\SchoolInepFactory;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class AlunoTest extends TestCase
{
    use DatabaseTransactions;

    private const URI = '/api/escola-importacao/escolas/alunos';

    private const INEP = 22999001;

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

    private function enroll(array $individual = [], array $registration = [], ?LegacySchoolClass $schoolClass = null): LegacyRegistration
    {
        $student = LegacyStudentFactory::new()->create([
            'ref_idpes' => LegacyIndividualFactory::new()->create($individual),
        ]);

        return LegacyRegistrationFactory::new()
            ->withStudent($student)
            ->withEnrollment($schoolClass ?? $this->schoolClass)
            ->create($registration);
    }

    private function individual(array $attributes = []): LegacyIndividual
    {
        return LegacyIndividualFactory::new()->create($attributes);
    }

    /** CA-01 */
    public function test_requires_authentication(): void
    {
        $this->request(['inep' => self::INEP])->assertUnauthorized();
    }

    /** CA-02 */
    public function test_inep_is_required(): void
    {
        $this->authenticate();

        $this->request()->assertUnprocessable()->assertJsonValidationErrors('inep');
        $this->request(['inep' => 'abc'])->assertUnprocessable()->assertJsonValidationErrors('inep');
    }

    /** CA-03 */
    public function test_unknown_inep_returns_not_found(): void
    {
        $this->authenticate();

        $this->request(['inep' => 22999999])
            ->assertNotFound()
            ->assertJson(['message' => 'Escola não encontrada para o INEP informado.']);
    }

    /** CA-04 */
    public function test_school_without_registrations_returns_empty_array(): void
    {
        $this->authenticate();

        $this->request(['inep' => self::INEP])->assertOk()->assertExactJson([]);
    }

    /** CA-05, CA-06 */
    public function test_returns_enrolled_student_in_the_expected_format(): void
    {
        $this->authenticate();

        $registration = $this->enroll([
            'cpf' => 1234567891,
            'sexo' => 'M',
            'data_nasc' => '2017-01-15',
        ]);

        $response = $this->request(['inep' => self::INEP])->assertOk();

        $response->assertJsonCount(1);
        $response->assertExactJson([
            [
                'matricula' => (string) $registration->cod_matricula,
                'aluno_nome' => $registration->student->person->nome,
                'sexo' => 'M',
                'aluno_cpf' => '01234567891',
                'data_nascimento' => '2017-01-15',
                'turma_codigo' => '112BM',
                'serie_descricao' => $registration->grade->nm_serie,
                'nivelEnsino' => $registration->course->educationLevel->nm_nivel,
                'comorbidades' => [],
                'responsaveis' => [],
                'professores' => [],
            ],
        ]);
    }

    /** CA-07 */
    public function test_missing_cpf_and_gender_are_null(): void
    {
        $this->authenticate();

        $this->enroll(['cpf' => null, 'sexo' => null]);

        $this->request(['inep' => self::INEP])
            ->assertOk()
            ->assertJsonPath('0.aluno_cpf', null)
            ->assertJsonPath('0.sexo', null);
    }

    public function test_class_name_is_used_when_there_is_no_abbreviation(): void
    {
        $this->authenticate();

        $this->schoolClass->update(['sgl_turma' => '']);
        $this->enroll();

        $this->request(['inep' => self::INEP])->assertJsonPath('0.turma_codigo', 'Turma B Manhã');
    }

    /** CA-08, CA-09 */
    public function test_filters_by_year_and_defaults_to_current_year(): void
    {
        $this->authenticate();

        $current = $this->enroll();
        $previous = $this->enroll([], ['ano' => now()->year - 1]);

        $this->request(['inep' => self::INEP])
            ->assertJsonCount(1)
            ->assertJsonPath('0.matricula', (string) $current->cod_matricula);

        $this->request(['inep' => self::INEP, 'ano' => now()->year - 1])
            ->assertJsonCount(1)
            ->assertJsonPath('0.matricula', (string) $previous->cod_matricula);
    }

    /** CA-10 */
    public function test_filters_by_student_cpf_with_or_without_mask(): void
    {
        $this->authenticate();

        $target = $this->enroll(['cpf' => 1234567891]);
        $this->enroll(['cpf' => 12345678901]);

        foreach (['01234567891', '012.345.678-91'] as $cpf) {
            $this->request(['inep' => self::INEP, 'aluno_cpf' => $cpf])
                ->assertJsonCount(1)
                ->assertJsonPath('0.matricula', (string) $target->cod_matricula);
        }
    }

    /** CA-11 */
    public function test_ignores_registrations_that_are_not_ongoing_and_enrolled(): void
    {
        $this->authenticate();

        $this->enroll([], ['ativo' => 0]);
        $this->enroll([], ['aprovado' => App_Model_MatriculaSituacao::TRANSFERIDO]);

        $withoutActiveEnrollment = $this->enroll();
        $withoutActiveEnrollment->enrollments()->update(['ativo' => 0]);

        LegacyRegistrationFactory::new()->create([
            'ref_ref_cod_escola' => $this->schoolClass->school_id,
        ]);

        $this->request(['inep' => self::INEP])->assertOk()->assertExactJson([]);
    }

    /** CA-12 */
    public function test_lists_guardians_without_duplicating_the_same_person(): void
    {
        $this->authenticate();

        $mother = $this->individual(['cpf' => 9876543211, 'data_nasc' => '1990-03-15']);
        $father = $this->individual(['cpf' => null]);

        LegacyPhoneFactory::new()->create(['idpes' => $mother->idpes, 'tipo' => 1, 'ddd' => 86, 'fone' => 999447715]);

        $this->enroll([
            'idpes_mae' => $mother->idpes,
            'idpes_pai' => $father->idpes,
            'idpes_responsavel' => $mother->idpes,
        ]);

        $this->request(['inep' => self::INEP])
            ->assertOk()
            ->assertJsonPath('0.responsaveis', [
                [
                    'nome' => $mother->person->nome,
                    'parentesco' => 'mae',
                    'telefone' => '86999447715',
                    'responsavel_cpf' => '09876543211',
                    'data_nascimento' => '1990-03-15',
                ],
                [
                    'nome' => $father->person->nome,
                    'parentesco' => 'pai',
                    'telefone' => null,
                    'responsavel_cpf' => null,
                    'data_nascimento' => $father->data_nasc->format('Y-m-d'),
                ],
            ]);
    }

    public function test_lists_a_guardian_other_than_the_parents(): void
    {
        $this->authenticate();

        $guardian = $this->individual();

        $this->enroll(['idpes_responsavel' => $guardian->idpes]);

        $this->request(['inep' => self::INEP])
            ->assertJsonCount(1, '0.responsaveis')
            ->assertJsonPath('0.responsaveis.0.nome', $guardian->person->nome)
            ->assertJsonPath('0.responsaveis.0.parentesco', 'responsavel');
    }

    /** CA-13, CA-14 */
    public function test_lists_deficiencies_as_comorbidities(): void
    {
        $this->authenticate();

        $registration = $this->enroll();
        $deficiencies = LegacyDeficiencyFactory::new()->count(2)->create();
        $registration->student->deficiencies()->attach($deficiencies->pluck('cod_deficiencia'));

        $names = $this->request(['inep' => self::INEP])->assertOk()->json('0.comorbidades');

        $this->assertEqualsCanonicalizing($deficiencies->pluck('nm_deficiencia')->all(), $names);
    }

    /** CA-15 */
    public function test_lists_one_teacher_entry_per_discipline(): void
    {
        $this->authenticate();

        $this->enroll();

        $teacher = $this->individual(['cpf' => 9876543211]);
        $employee = EmployeeFactory::new()->create(['id' => $teacher->idpes]);
        $link = LegacySchoolClassTeacherFactory::new()->create([
            'turma_id' => $this->schoolClass,
            'servidor_id' => $employee,
        ]);

        $disciplines = LegacyDisciplineFactory::new()->count(2)->create();

        foreach ($disciplines as $discipline) {
            LegacySchoolClassTeacherDisciplineFactory::new()->create([
                'professor_turma_id' => $link,
                'componente_curricular_id' => $discipline,
            ]);
        }

        $teachers = $this->request(['inep' => self::INEP])->assertOk()->json('0.professores');

        $this->assertCount(2, $teachers);
        $this->assertEqualsCanonicalizing($disciplines->pluck('nome')->all(), array_column($teachers, 'disciplina'));

        foreach ($teachers as $item) {
            $this->assertSame(['nome', 'professor_cpf', 'disciplina'], array_keys($item));
            $this->assertSame($teacher->person->nome, $item['nome']);
            $this->assertSame('09876543211', $item['professor_cpf']);
        }
    }

    /** CA-16 */
    public function test_ignores_students_from_other_schools(): void
    {
        $this->authenticate();

        $this->enroll([], [], LegacySchoolClassFactory::new()->create());

        $this->request(['inep' => self::INEP])->assertOk()->assertExactJson([]);
    }

    public function test_orders_by_student_name(): void
    {
        $this->authenticate();

        foreach (['Carla', 'Ana', 'Bruno'] as $name) {
            $student = LegacyStudentFactory::new()->create([
                'ref_idpes' => LegacyIndividualFactory::new()->withName($name)->create(),
            ]);

            LegacyRegistrationFactory::new()->withStudent($student)->withEnrollment($this->schoolClass)->create();
        }

        $names = array_column($this->request(['inep' => self::INEP])->json(), 'aluno_nome');

        $this->assertSame(['Ana', 'Bruno', 'Carla'], $names);
    }

    /** CA-17 */
    public function test_number_of_queries_does_not_grow_with_the_number_of_students(): void
    {
        $this->authenticate();

        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->request(['inep' => self::INEP])->assertOk();
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };

        $enroll = function (int $quantity): void {
            for ($i = 0; $i < $quantity; $i++) {
                $mother = $this->individual();
                $registration = $this->enroll(['idpes_mae' => $mother->idpes]);
                $registration->student->deficiencies()->attach(LegacyDeficiencyFactory::new()->create()->cod_deficiencia);
            }
        };

        $enroll(2);
        $withTwo = $count();

        $enroll(6);
        $withEight = $count();

        $this->assertSame($withTwo, $withEight);
    }
}
