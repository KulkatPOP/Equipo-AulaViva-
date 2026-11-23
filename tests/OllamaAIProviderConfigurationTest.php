<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../config/tutor/AIProvider.php';

final class OllamaAIProviderConfigurationTest extends TestCase
{
    private array $previousEnvironment = [];

    protected function setUp(): void
    {
        foreach (['OLLAMA_BASE_URL', 'OLLAMA_CHAT_MODEL', 'OLLAMA_ALLOW_DOCKER_HOST'] as $name) {
            $this->previousEnvironment[$name] = getenv($name);
            putenv($name);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->previousEnvironment as $name => $value) {
            putenv($value === false ? $name : $name . '=' . $value);
        }
    }

    public static function allowedConfigurations(): array
    {
        return [
            'explicit Docker Desktop opt-in' => ['http://host.docker.internal:11434', 'true'],
            'IPv4 loopback without opt-in' => ['http://127.0.0.1:11434', null],
            'localhost without opt-in' => ['http://localhost:11434', null],
            'IPv6 loopback without opt-in' => ['http://[::1]:11434', null],
            'HTTPS loopback remains supported' => ['https://localhost:11434', null],
            'loopback retains existing port support' => ['http://localhost:11435', 'false'],
            'loopback remains supported with opt-in' => ['http://127.0.0.1:11434', 'true'],
        ];
    }

    #[DataProvider('allowedConfigurations')]
    public function testAcceptsOnlyAuthorizedConfiguration(string $url, ?string $flag): void
    {
        putenv('OLLAMA_BASE_URL=' . $url);
        putenv('OLLAMA_CHAT_MODEL=qwen3:1.7b');
        if ($flag !== null) {
            putenv('OLLAMA_ALLOW_DOCKER_HOST=' . $flag);
        }

        self::assertSame('qwen3:1.7b', OllamaAIProvider::desdeEntorno()->modelo());
    }

    public static function rejectedConfigurations(): array
    {
        $docker = 'http://host.docker.internal:11434';
        return [
            'unset opt-in' => [$docker, null],
            'disabled opt-in' => [$docker, 'false'],
            'numeric opt-in' => [$docker, '1'],
            'uppercase opt-in' => [$docker, 'TRUE'],
            'empty opt-in' => [$docker, ''],
            'whitespace opt-in' => [$docker, ' true '],
            'other host' => ['http://example.invalid:11434', 'true'],
            'other private host' => ['http://192.168.65.254:11434', 'true'],
            'hostname suffix' => ['http://host.docker.internal.example.invalid:11434', 'true'],
            'other port' => ['http://host.docker.internal:11435', 'true'],
            'implicit port' => ['http://host.docker.internal', 'true'],
            'HTTPS Docker host' => ['https://host.docker.internal:11434', 'true'],
            'FTP scheme' => ['ftp://host.docker.internal:11434', 'true'],
            'file scheme' => ['file:///tmp/ollama', 'true'],
            'embedded username' => ['http://user@host.docker.internal:11434', 'true'],
            'query string' => [$docker . '?redirect=other', 'true'],
            'empty query' => [$docker . '?', 'true'],
            'fragment' => [$docker . '#other', 'true'],
            'empty fragment' => [$docker . '#', 'true'],
            'malformed URL' => ['http://[', 'true'],
        ];
    }

    #[DataProvider('rejectedConfigurations')]
    public function testRejectsUnauthorizedConfiguration(string $url, ?string $flag): void
    {
        putenv('OLLAMA_BASE_URL=' . $url);
        putenv('OLLAMA_CHAT_MODEL=qwen3:1.7b');
        if ($flag !== null) {
            putenv('OLLAMA_ALLOW_DOCKER_HOST=' . $flag);
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Configuración local del generador inválida.');
        OllamaAIProvider::desdeEntorno();
    }

    public static function rejectedModels(): array
    {
        return [[''], ['qwen3:cloud'], ['cloud-model'], ['qwen3:1.7b/other']];
    }

    #[DataProvider('rejectedModels')]
    public function testDockerOptInPreservesModelRestrictions(string $model): void
    {
        putenv('OLLAMA_ALLOW_DOCKER_HOST=true');
        $this->expectException(RuntimeException::class);
        new OllamaAIProvider('http://host.docker.internal:11434', $model);
    }
}
