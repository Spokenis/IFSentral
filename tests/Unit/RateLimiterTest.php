<?php

namespace Tests\Unit;

use App\Core\RateLimiter;
use PDO;
use PDOException;
use PDOStatement;
use PHPUnit\Framework\TestCase;

/**
 * RateLimiter::checkLoginAttempts()/recordLoginAttempt() usam SQL específico
 * do MySQL (DATE_SUB, INTERVAL) que não roda em SQLite — por isso, ao invés
 * de um banco real, usamos um PDO mockado para testar a lógica de decisão da
 * classe (habilitado/desabilitado, limite excedido, falha aberta em erro de
 * banco) sem depender de MySQL/Docker.
 */
final class RateLimiterTest extends TestCase
{
    private function mockConn(PDOStatement $settingsStmt, ?PDOStatement $queryStmt = null, bool $throwOnQuery = false): PDO
    {
        $conn = $this->createMock(PDO::class);
        $conn->method('prepare')->willReturnCallback(
            function (string $sql) use ($settingsStmt, $queryStmt, $throwOnQuery) {
                if (str_contains($sql, 'api_settings')) {
                    return $settingsStmt;
                }
                if ($throwOnQuery) {
                    throw new PDOException('conexão perdida');
                }
                return $queryStmt;
            }
        );
        return $conn;
    }

    private function emptySettingsStmt(): PDOStatement
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetch')->willReturn(false); // sem overrides -> usa defaults
        return $stmt;
    }

    public function testCheckLoginAttemptsAllowsWhenUnderLimit(): void
    {
        $queryStmt = $this->createMock(PDOStatement::class);
        $queryStmt->method('execute')->willReturn(true);
        $queryStmt->method('fetch')->willReturn(['by_identifier' => 2, 'by_ip' => 2]);

        $conn = $this->mockConn($this->emptySettingsStmt(), $queryStmt);
        $limiter = new RateLimiter($conn);

        $resultado = $limiter->checkLoginAttempts('user@example.com', '127.0.0.1');

        $this->assertTrue($resultado['allowed']);
        $this->assertSame(2, $resultado['attempts']);
        $this->assertSame(5, $resultado['max_attempts']); // default
    }

    public function testCheckLoginAttemptsDeniesWhenAtOrOverLimit(): void
    {
        $queryStmt = $this->createMock(PDOStatement::class);
        $queryStmt->method('execute')->willReturn(true);
        $queryStmt->method('fetch')->willReturn(['by_identifier' => 5, 'by_ip' => 5]);

        $conn = $this->mockConn($this->emptySettingsStmt(), $queryStmt);
        $limiter = new RateLimiter($conn);

        $resultado = $limiter->checkLoginAttempts('user@example.com', '127.0.0.1');

        $this->assertFalse($resultado['allowed']);
        $this->assertSame(5, $resultado['attempts']);
    }

    public function testCheckLoginAttemptsIgnoresOtherAccountsFailuresFromSameIp(): void
    {
        // Rede com NAT: 6 falhas de outra conta no mesmo IP não bloqueiam esta
        $queryStmt = $this->createMock(PDOStatement::class);
        $queryStmt->method('execute')->willReturn(true);
        $queryStmt->method('fetch')->willReturn(['by_identifier' => 0, 'by_ip' => 6]);

        $conn = $this->mockConn($this->emptySettingsStmt(), $queryStmt);
        $limiter = new RateLimiter($conn);

        $resultado = $limiter->checkLoginAttempts('user@example.com', '127.0.0.1');

        $this->assertTrue($resultado['allowed']);
    }

    public function testCheckLoginAttemptsDeniesWhenIpExceedsItsHigherLimit(): void
    {
        // Muitas contas diferentes testadas a partir de um só IP (4x o limite)
        $queryStmt = $this->createMock(PDOStatement::class);
        $queryStmt->method('execute')->willReturn(true);
        $queryStmt->method('fetch')->willReturn(['by_identifier' => 0, 'by_ip' => 20]);

        $conn = $this->mockConn($this->emptySettingsStmt(), $queryStmt);
        $limiter = new RateLimiter($conn);

        $resultado = $limiter->checkLoginAttempts('user@example.com', '127.0.0.1');

        $this->assertFalse($resultado['allowed']);
    }

    public function testCheckLoginAttemptsFailsOpenWhenDatabaseThrows(): void
    {
        $conn = $this->mockConn($this->emptySettingsStmt(), null, throwOnQuery: true);
        $limiter = new RateLimiter($conn);

        $resultado = $limiter->checkLoginAttempts('user@example.com', '127.0.0.1');

        $this->assertTrue($resultado['allowed'], 'deve falhar aberto para não derrubar o login por erro de infraestrutura');
    }

    public function testCheckLoginAttemptsSkipsQueryWhenDisabledViaSettings(): void
    {
        $settingsStmt = $this->createMock(PDOStatement::class);
        $settingsStmt->method('execute')->willReturn(true);
        $settingsStmt->method('fetch')->willReturnOnConsecutiveCalls(
            ['setting_key' => 'LOGIN_RATE_LIMIT_ENABLED', 'setting_value' => '0'],
            false
        );

        // queryStmt é null de propósito: se checkLoginAttempts tentar consultar
        // login_attempts mesmo desabilitado, o prepare() lança e o teste falha.
        $conn = $this->mockConn($settingsStmt, null, throwOnQuery: true);
        $limiter = new RateLimiter($conn);

        $resultado = $limiter->checkLoginAttempts('user@example.com', '127.0.0.1');

        $this->assertTrue($resultado['allowed']);
        $this->assertSame(0, $resultado['max_attempts']);
    }

    public function testRecordLoginAttemptSendsExpectedParameters(): void
    {
        $insertStmt = $this->createMock(PDOStatement::class);
        $insertStmt->expects($this->once())
            ->method('execute')
            ->with(['user@example.com', '127.0.0.1', false]);

        $conn = $this->createMock(PDO::class);
        $conn->method('prepare')->willReturnCallback(
            function (string $sql) use ($insertStmt) {
                if (str_contains($sql, 'api_settings')) {
                    return $this->emptySettingsStmt();
                }
                return $insertStmt;
            }
        );

        $limiter = new RateLimiter($conn);
        $limiter->recordLoginAttempt('user@example.com', '127.0.0.1', false);
    }

    public function testRecordLoginAttemptDoesNotThrowWhenDatabaseFails(): void
    {
        $conn = $this->mockConn($this->emptySettingsStmt(), null, throwOnQuery: true);
        $limiter = new RateLimiter($conn);

        $limiter->recordLoginAttempt('user@example.com', '127.0.0.1', true);
        $this->addToAssertionCount(1); // não lançou exceção
    }

    public function testIpNaListaAceitaIpExatoERedesCidr(): void
    {
        $lista = '10.50.0.0/16, 192.168.1.20 2001:db8::/32';
        $this->assertTrue(RateLimiter::ipNaLista('10.50.3.7', $lista));
        $this->assertTrue(RateLimiter::ipNaLista('192.168.1.20', $lista));
        $this->assertTrue(RateLimiter::ipNaLista('2001:db8::1', $lista));
        $this->assertFalse(RateLimiter::ipNaLista('10.51.0.1', $lista));
        $this->assertFalse(RateLimiter::ipNaLista('192.168.1.21', $lista));
        $this->assertFalse(RateLimiter::ipNaLista('', $lista));
        $this->assertFalse(RateLimiter::ipNaLista('10.50.3.7', ''));
        $this->assertTrue(RateLimiter::ipNaLista('172.21.0.9', '172.16.0.0/12'));
        $this->assertFalse(RateLimiter::ipNaLista('172.32.0.1', '172.16.0.0/12'));
    }

    public function testNormalizarListaIpsSeparaInvalidos(): void
    {
        [$ok, $invalidos] = RateLimiter::normalizarListaIps("10.50.0.0/16\n192.168.1.20; abc, 10.0.0.0/40, 10.50.0.0/16");
        $this->assertSame('10.50.0.0/16, 192.168.1.20', $ok);
        $this->assertSame(['abc', '10.0.0.0/40'], $invalidos);
    }

    public function testLimitePorIpIgnoradoParaIpIsento(): void
    {
        $settingsStmt = $this->createMock(PDOStatement::class);
        $settingsStmt->method('execute')->willReturn(true);
        $settingsStmt->method('fetch')->willReturnOnConsecutiveCalls(
            ['setting_key' => 'LOGIN_RATE_LIMIT_MAX_PER_IP', 'setting_value' => '20'],
            ['setting_key' => 'LOGIN_RATE_LIMIT_IP_ISENTOS', 'setting_value' => '10.50.0.0/16'],
            false
        );
        $query = $this->createMock(PDOStatement::class);
        $query->method('execute')->willReturn(true);
        $query->method('fetch')->willReturn(['by_identifier' => 0, 'by_ip' => 500]);
        $conn = $this->createMock(PDO::class);
        $conn->method('prepare')->willReturnCallback(fn(string $sql) => str_contains($sql, 'api_settings') ? $settingsStmt : $query);
        $limiter = new RateLimiter($conn);

        $this->assertTrue($limiter->checkLoginAttempts('a@b.c', '10.50.9.9')['allowed'], 'rede isenta: só o limite por conta vale');
        $r = $limiter->checkLoginAttempts('a@b.c', '200.1.2.3');
        $this->assertFalse($r['allowed']);
        $this->assertSame('ip', $r['motivo']);
    }
}
