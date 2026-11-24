<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../config/tutor/TutorService.php';

#[RunTestsInSeparateProcesses]
final class TutorServiceTest extends TestCase
{
    private const QUESTION = '¿Cuántas fichas requiere el juego?';
    private const SOURCE = 'El juego requiere 4 fichas.';

    private string $log;

    protected function setUp(): void
    {
        $this->log = tempnam(sys_get_temp_dir(), 'tutor-log-');
        ini_set('error_log', $this->log);
        session_start(['use_cookies' => false, 'cache_limiter' => '']);
        $_SESSION = ['usuario_id' => 7, 'colegio_id' => 3, 'rol' => 'alumno'];
    }

    protected function tearDown(): void
    {
        ini_restore('error_log');
        unlink($this->log);
        session_destroy();
        $_SESSION = [];
    }

    public static function scenarios(): array
    {
        return [
            'evidence still authorized' => ['answered', 'ANSWERED'],
            'nominal instrument answer' => ['instrument', 'ANSWERED'],
            'missing enrollment' => ['denied', 'INSUFFICIENT_EVIDENCE'],
            'no retrievable material' => ['empty', 'INSUFFICIENT_EVIDENCE'],
            'retrieval does not answer the question' => ['unrelated', 'INSUFFICIENT_EVIDENCE'],
            'material removed before generation' => ['stale', 'INSUFFICIENT_EVIDENCE'],
            'material removed during generation' => ['removed', 'INSUFFICIENT_EVIDENCE'],
            'partial response' => ['partial', 'VALIDATION_FAILED'],
            'generation timeout' => ['timeout', 'GENERATION_TIMEOUT'],
            'provider unavailable' => ['unavailable', 'GENERATION_UNAVAILABLE'],
        ];
    }

    #[DataProvider('scenarios')]
    public function testAnswerRequiresCurrentEvidence(string $scenario, string $status): void
    {
        $question = $scenario === 'instrument'
            ? '¿Qué instrumento mide el diámetro?'
            : self::QUESTION;

        $source = $scenario === 'instrument'
            ? 'A caliper measures diameter.'
            : self::SOURCE;

        $fact = $scenario === 'instrument'
            ? 'Calibre.'
            : self::SOURCE;

        $auth = $this->createMock(PDOStatement::class);
        $auth->method('fetchColumn')->willReturn($scenario !== 'denied');
        $auth->method('execute')->with([7, 3, 11]);

        $scope = $this->createMock(PDOStatement::class);
        $scope->method('execute')->with(['usuario' => 7, 'colegio' => 3, 'aula' => 11]);
        $scope->method('fetchColumn')->willReturn($scenario !== 'empty');

        $ranked = $this->createMock(PDOStatement::class);
        $ranked->method('execute')->with(self::callback(static function (array $params): bool {
            return $params['usuario'] === 7 && $params['colegio'] === 3 && $params['aula'] === 11
                && $params['config'] === 'english' && count(json_decode($params['vector'], true)) === 768;
        }));
        $ranked->method('fetchAll')->willReturn([
            ['fragment_id' => 18, 'material_id' => 4, 'page' => 2, 'rrf_score' => 0.03,
                'text' => $scenario === 'unrelated' ? 'La bolsa pesa dos kilogramos.' : $source],
        ]);

        $live = $this->createMock(PDOStatement::class);
        $live->method('execute')->with([18, 11]);
        $live->method('fetchColumn')->willReturn(...match ($scenario) {
            'stale' => [0], 'removed' => [1, 0], default => [1],
        });

        $pdo = $this->createStub(PDO::class);
        $pdo->method('prepare')->willReturnCallback(static fn (string $sql): PDOStatement => match (true) {
            str_contains($sql, 'WITH permitidos') => $ranked,
            str_contains($sql, 'SELECT count(*)') => $live,
            str_contains($sql, 'LIMIT 1') => $scope,
            default => $auth,
        });
        $embeddings = $this->createMock(EmbeddingProvider::class);
        $embeddings->expects(in_array($scenario, ['denied', 'empty'], true) ? self::never() : self::once())
            ->method('generar')->with([$question])->willReturn([array_fill(0, 768, 0.125)]);

        $provider = $this->createMock(AIProvider::class);
        $provider->method('modelo')->willReturn('model-test');
        $provider->expects(in_array($scenario, ['denied', 'empty', 'unrelated', 'stale'], true) ? self::never() : self::once())
            ->method('generate')->willReturnCallback(static function ($prompt, $actualQuestion, $context, $timeout) use ($scenario, $question, $source, $fact): AIResponse {
                self::assertSame($question, $actualQuestion);

                self::assertStringContainsString(
                    'Copia literalmente cada hecho obligatorio del checklist en el campo text.',
                    $prompt
                );

                self::assertStringContainsString(
                    'No lo reformules, no lo amplíes y no agregues palabras.',
                    $prompt
                );

                self::assertSame([
                    [
                        'label' => 'F1',
                        'page' => 2,
                        'text' => $source,
                        'facts' => [$fact],
                    ],
                ], $context);

                self::assertGreaterThan(0, $timeout);

                return match ($scenario) {
                    'timeout' => throw new TutorGenerationTimeout(),
                    'unavailable' => throw new RuntimeException('Provider unavailable'),
                    default => new AIResponse(
                        json_encode([
                            'claims' => [
                                [
                                    'text' => $fact,
                                    'citation' => '[F1, p. 2]',
                                ],
                            ],
                        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                        $scenario !== 'partial'
                    ),
                };
            });

        $retriever = new TutorRetriever($pdo, $embeddings, 'english');
        $service = new TutorService($pdo, $retriever, new EvidenceGate(), $provider);
        $response = $service->ask(11, $question);

        self::assertSame($status, $response->status);
        if ($status === 'ANSWERED') {
            self::assertSame($fact . ' [F1, p. 2]', $response->answer);
            self::assertSame([['fragment_id' => 18, 'page' => 2]], $response->citations);
            self::assertSame('model-test', $response->jsonSerialize()['provider']);
        } else {
            self::assertSame([], $response->citations);
        }
    }
}
