<?php

namespace Tests\Unit\EscolaImportacao;

use App\Models\LegacyGeneralConfiguration;
use App\Models\LegacyInstitution;
use App\Services\iDiarioService;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class IDiarioDailyFrequenciesTest extends TestCase
{
    /**
     * @var array<int, array<string, mixed>>
     */
    private array $history = [];

    private function service(Response|\Throwable $response): iDiarioService
    {
        $this->history = [];

        $stack = HandlerStack::create(new MockHandler([$response]));
        $stack->push(Middleware::history($this->history));

        $institution = new LegacyInstitution;
        $institution->setRelation('generalConfiguration', new LegacyGeneralConfiguration([
            'url_novo_educacao' => 'http://idiario.test/',
            'token_novo_educacao' => 'token-idiario',
        ]));

        return new iDiarioService($institution, new Client(['handler' => $stack]));
    }

    /** CA-47 */
    public function test_requests_the_daily_frequencies_with_token_and_school_code(): void
    {
        $body = [['student_api_code' => '10', 'classroom_api_code' => '20', 'situacao_frequencia' => 'P']];

        $result = $this->service(new Response(200, [], json_encode($body)))
            ->getDailyFrequencies(2, '2026-02-26', [10, 11]);

        $request = $this->history[0]['request'];

        $this->assertSame($body, $result);
        $this->assertSame('GET', $request->getMethod());
        $this->assertSame('/api/v2/escola_importacao/frequencias', $request->getUri()->getPath());
        $this->assertSame('token-idiario', $request->getHeaderLine('token'));
        $this->assertSame(
            'unity_api_code=2&date=2026-02-26&student_api_codes[]=10&student_api_codes[]=11',
            urldecode($request->getUri()->getQuery())
        );
    }

    public function test_does_not_send_the_student_filter_when_empty(): void
    {
        $this->service(new Response(200, [], '[]'))->getDailyFrequencies(2, '2026-02-26');

        $this->assertSame('unity_api_code=2&date=2026-02-26', $this->history[0]['request']->getUri()->getQuery());
    }

    /** CA-45 */
    public function test_throws_when_idiario_fails(): void
    {
        $this->expectException(RuntimeException::class);

        $this->service(new Response(500))->getDailyFrequencies(2, '2026-02-26');
    }

    /** CA-45 */
    public function test_throws_when_idiario_is_unreachable(): void
    {
        $this->expectException(RuntimeException::class);

        $this->service(new ConnectException('timeout', new Request('GET', '/')))->getDailyFrequencies(2, '2026-02-26');
    }

    public function test_throws_when_the_response_is_not_a_list(): void
    {
        $this->expectException(RuntimeException::class);

        $this->service(new Response(200, [], '<html>'))->getDailyFrequencies(2, '2026-02-26');
    }
}
