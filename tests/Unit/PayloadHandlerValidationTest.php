<?php

namespace Tests\Unit;

use App\Core\PayloadHandler;
use PHPUnit\Framework\TestCase;

/**
 * Cobre PayloadHandler::validatePayload(), que é lógica pura (não toca no
 * banco) — por isso pode ser testada sem PDO/MySQL. savePayload() não é
 * testado aqui pois depende de uma conexão real com o banco.
 */
final class PayloadHandlerValidationTest extends TestCase
{
    private PayloadHandler $handler;

    protected function setUp(): void
    {
        $this->handler = new PayloadHandler(null);
    }

    public function testAcceptsASimpleValidPayload(): void
    {
        $resultado = $this->handler->validatePayload(['temperatura' => 25.5, 'umidade' => 60]);

        $this->assertTrue($resultado['valid']);
        $this->assertEmpty($resultado['errors']);
    }

    public function testRejectsNonArrayNonObjectPayload(): void
    {
        $resultado = $this->handler->validatePayload('uma string qualquer');

        $this->assertFalse($resultado['valid']);
        $this->assertNotEmpty($resultado['errors']);
    }

    public function testRejectsEmptyPayload(): void
    {
        $resultado = $this->handler->validatePayload([]);

        $this->assertFalse($resultado['valid']);
        $this->assertStringContainsString('vazio', $resultado['errors'][0]);
    }

    public function testRejectsKeyLongerThan40Chars(): void
    {
        $chaveGrande = str_repeat('a', 41);
        $resultado = $this->handler->validatePayload([$chaveGrande => 1]);

        $this->assertFalse($resultado['valid']);
        $this->assertStringContainsString('40 caracteres', $resultado['errors'][0]);
    }

    public function testAcceptsKeyOfExactly40Chars(): void
    {
        $chaveLimite = str_repeat('a', 40);
        $resultado = $this->handler->validatePayload([$chaveLimite => 1]);

        $this->assertTrue($resultado['valid']);
    }

    public function testRejectsStringValueLongerThan255Chars(): void
    {
        $valorGrande = str_repeat('x', 256);
        $resultado = $this->handler->validatePayload(['campo' => $valorGrande]);

        $this->assertFalse($resultado['valid']);
        $this->assertStringContainsString('muito longo', $resultado['errors'][0]);
    }

    public function testRejectsNestingDeeperThan5Levels(): void
    {
        // 7 níveis de chave (a..g): o valor de 'f' é um array, forçando uma
        // 7ª chamada recursiva com depth=6, que excede o limite (depth > 5)
        $payload = ['a' => ['b' => ['c' => ['d' => ['e' => ['f' => ['g' => 'fundo']]]]]]];
        $resultado = $this->handler->validatePayload($payload);

        $this->assertFalse($resultado['valid']);
        $this->assertStringContainsString('profundidade máxima', $resultado['errors'][0]);
    }

    public function testAcceptsNestingAtTheDepthLimit(): void
    {
        // 6 níveis de chave (a..f): último valor ('fundo') é processado na
        // chamada com depth=5, dentro do limite (depth > 5 só falha em 6)
        $payload = ['a' => ['b' => ['c' => ['d' => ['e' => ['f' => 'fundo']]]]]];
        $resultado = $this->handler->validatePayload($payload);

        $this->assertTrue($resultado['valid']);
    }

    public function testRejectsMoreThan50TotalKeys(): void
    {
        $payload = [];
        for ($i = 0; $i < 51; $i++) {
            $payload["campo$i"] = $i;
        }
        $resultado = $this->handler->validatePayload($payload);

        $this->assertFalse($resultado['valid']);
        $this->assertStringContainsString('número excessivo de chaves', $resultado['errors'][0]);
    }

    public function testAcceptsUpTo50TotalKeys(): void
    {
        $payload = [];
        for ($i = 0; $i < 50; $i++) {
            $payload["campo$i"] = $i;
        }
        $resultado = $this->handler->validatePayload($payload);

        $this->assertTrue($resultado['valid']);
    }

    public function testAcceptsStdClassObjectPayload(): void
    {
        $payload = new \stdClass();
        $payload->temperatura = 22.1;

        $resultado = $this->handler->validatePayload($payload);

        $this->assertTrue($resultado['valid']);
    }
}
