<?php

namespace Tests\Unit;

use App\Core\Csrf;
use PHPUnit\Framework\TestCase;

/**
 * Cobre getToken()/regenerateToken(). requireValidToken() não é testado aqui
 * de propósito: ele chama getallheaders() (indisponível no SAPI CLI, onde o
 * PHPUnit roda) e termina a requisição com exit() em caso de falha — ambos
 * incompatíveis com um processo de teste unitário. Esse método é validado
 * manualmente contra os endpoints reais (ver sessão de desenvolvimento).
 */
final class CsrfTest extends TestCase
{
    protected function setUp(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        $_SESSION = [];
    }

    public function testGetTokenGeneratesATokenWhenNoneExists(): void
    {
        $token = Csrf::getToken();

        $this->assertNotEmpty($token);
        $this->assertSame(64, strlen($token), 'esperado hex de 32 bytes = 64 caracteres');
        $this->assertSame($token, $_SESSION['csrf_token']);
    }

    public function testGetTokenReturnsTheSameTokenOnSubsequentCalls(): void
    {
        $primeiro = Csrf::getToken();
        $segundo = Csrf::getToken();

        $this->assertSame($primeiro, $segundo);
    }

    public function testRegenerateTokenProducesADifferentToken(): void
    {
        $original = Csrf::getToken();
        $novo = Csrf::regenerateToken();

        $this->assertNotSame($original, $novo);
        $this->assertSame($novo, $_SESSION['csrf_token']);
    }
}
