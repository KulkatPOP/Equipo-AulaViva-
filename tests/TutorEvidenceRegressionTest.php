<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../test/tutor-evidence.php';

final class TutorEvidenceRegressionTest extends TestCase
{
    public function testTutorEvidenceRegression(): void
    {
        runTutorEvidenceChecks(static function (string $nombre, bool $resultado): void {
            self::assertTrue($resultado, $nombre);
        });
    }
}
