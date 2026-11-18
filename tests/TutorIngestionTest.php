<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../config/tutor/IngestaTutor.php';

final class TutorIngestionTest extends TestCase
{
    public static function outcomes(): array
    {
        return [['processed'], ['no text'], ['embedding error'], ['authorization revoked'], ['busy']];
    }

    #[DataProvider('outcomes')]
    public function testIngestionPersistsOnlyAuthorizedCompleteFragments(string $scenario): void
    {
        $writes = [];
        $pdo = $this->createMock(PDO::class);
        $transactions = match ($scenario) {
            'processed', 'no text', 'authorization revoked' => 2,
            'busy' => 0,
            default => 1,
        };
        $pdo->expects(self::exactly($transactions))->method('beginTransaction')->willReturn(true);
        $pdo->expects(self::exactly($transactions - (int) ($scenario === 'authorization revoked')))->method('commit')->willReturn(true);
        $pdo->expects(self::exactly((int) ($scenario === 'authorization revoked')))->method('rollBack')->willReturn(true);
        $authorizations = 0;
        $pdo->method('prepare')->willReturnCallback(function (string $sql) use (&$writes, &$authorizations, $scenario): PDOStatement {
            $operation = match (true) {
                str_contains($sql, 'pg_try_advisory_lock') => 'lock',
                str_contains($sql, 'pg_advisory_unlock') => 'unlock',
                str_contains($sql, 'FROM materiales m') => 'authorize',
                str_contains($sql, 'INSERT INTO material_ingestas') => 'start',
                str_contains($sql, 'DELETE FROM material_fragmentos') => 'delete',
                str_contains($sql, 'INSERT INTO material_fragmentos') => 'insert',
                str_contains($sql, "estado = 'error'") => 'error',
                str_contains($sql, 'UPDATE material_ingestas') => 'state',
                default => throw new LogicException('Unexpected statement'),
            };
            $stmt = $this->createStub(PDOStatement::class);
            $stmt->method('execute')->willReturnCallback(static function (array $args) use (&$writes, $operation): bool {
                $writes[] = [$operation, $args];
                return true;
            });
            if ($operation === 'authorize') {
                $authorizations++;
                $stmt->method('fetchColumn')->willReturn(match (true) {
                    $authorizations === 1 => base64_encode('%PDF-test'),
                    $authorizations === 3 && $scenario === 'authorization revoked' => false,
                    default => '12',
                });
            } elseif ($operation === 'lock') {
                $stmt->method('fetchColumn')->willReturn($scenario === 'busy' ? 0 : 1);
            }
            return $stmt;
        });
        $extractor = $this->createMock(ExtractorInterface::class);
        $extractor->expects($scenario === 'busy' ? self::never() : self::once())->method('extraer')->with('%PDF-test')
            ->willReturn($scenario === 'no text' ? [1 => '  '] : [2 => 'Primera página.', 4 => 'Segunda página.']);
        $embeddings = $this->createMock(EmbeddingProvider::class);
        $vector = array_fill(0, 768, 0.125);
        $embeddings->expects(in_array($scenario, ['no text', 'busy'], true) ? self::never() : self::once())
            ->method('generar')->with(['Primera página.', 'Segunda página.'])
            ->willReturn($scenario === 'embedding error' ? [$vector] : [$vector, $vector]);
        $ingestion = new IngestaTutor(new RepositorioFragmentosTutor($pdo), $extractor, $embeddings);
        $result = $error = null;
        try {
            $result = $ingestion->procesar(12, 7);
        } catch (RuntimeException $failure) {
            $error = $failure->getMessage();
        }
        $start = [['authorize', [12, 7]], ['lock', [12]]];
        if ($scenario === 'busy') {
            self::assertSame('Ya hay una ingestión en curso para este material.', $error);
            self::assertSame($start, $writes);
            return;
        }

        array_push($start, ['authorize', [12, 7]], ['start', [12]]);
        if ($scenario !== 'embedding error') {
            $start[] = ['authorize', [12, 7]];
        }
        if ($scenario === 'processed' || $scenario === 'no text') {
            $state = $scenario === 'processed' ? 'procesado' : 'sin_texto';
            $start[] = ['delete', [12]];
            if ($scenario === 'processed') {
                $serialized = json_encode($vector, JSON_THROW_ON_ERROR);
                $start[] = ['insert', [12, 1, 2, 'Primera página.', $serialized]];
                $start[] = ['insert', [12, 2, 4, 'Segunda página.', $serialized]];
            }
            $start[] = ['state', [$state, 12]];
            self::assertSame(['estado' => $state, 'fragmentos' => $scenario === 'processed' ? 2 : 0], $result);
        } else {
            $expected = $scenario === 'embedding error'
                ? 'Cantidad incorrecta de embeddings.' : 'Material no disponible para este docente.';
            self::assertSame($expected, $error);
            $start[] = ['error', [$expected, 12]];
        }
        self::assertSame([...$start, ['unlock', [12]]], $writes);
    }

    public function testFragmentationKeepsPageNumbersAndWords(): void
    {
        $text = str_repeat('energía ', 170) . 'final.';
        $parts = FragmentadorTutor::fragmentar([3 => $text, 4 => '   ', 5 => 'Cierre.']);
        self::assertSame([3, 3, 5], array_column($parts, 'pagina'));
        self::assertSame([1, 2, 3], array_column($parts, 'orden'));
        self::assertSame($text, $parts[0]['texto'] . $parts[1]['texto']);
        self::assertLessThanOrEqual(1200, mb_strlen($parts[0]['texto']));
        self::assertStringEndsWith('energía', $parts[0]['texto']);
    }
}
