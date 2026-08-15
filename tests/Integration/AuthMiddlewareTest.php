<?php

namespace Tests\Integration;

use App\Core\AuthMiddleware;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Testa as checagens de autorização de AuthMiddleware contra um banco SQLite
 * em memória (schema mínimo equivalente ao usado em produção no MySQL). Não
 * precisa de Docker/MySQL rodando. Cobre especificamente o cenário de IDOR
 * entre projetos corrigido em salvar_grafico_avancado.php/atualizar_grafico_avancado.php:
 * um usuário não pode ter acesso a um dispositivo/gráfico de um projeto do
 * qual não é membro, mesmo sendo membro de outro projeto qualquer.
 */
final class AuthMiddlewareTest extends TestCase
{
    private PDO $conn;

    protected function setUp(): void
    {
        $this->conn = new PDO('sqlite::memory:');
        $this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $this->conn->exec('
            CREATE TABLE roles (id INTEGER PRIMARY KEY, name TEXT)
        ');
        $this->conn->exec('
            CREATE TABLE projects (id INTEGER PRIMARY KEY, name TEXT, deletedAt TEXT DEFAULT NULL)
        ');
        $this->conn->exec('
            CREATE TABLE users_projects (user_id INTEGER, project_id INTEGER, role_id INTEGER)
        ');
        $this->conn->exec('
            CREATE TABLE devices (id INTEGER PRIMARY KEY, project_id INTEGER, deletedAt TEXT DEFAULT NULL)
        ');
        $this->conn->exec('
            CREATE TABLE charts (id INTEGER PRIMARY KEY, project_id INTEGER)
        ');

        $this->conn->exec("INSERT INTO roles (id, name) VALUES (1, 'Gerente'), (2, 'Participante')");

        // Projeto 1: user 10 é Gerente
        // Projeto 2: user 20 é Participante
        // user 30 não é membro de nenhum projeto
        $this->conn->exec('INSERT INTO projects (id, name) VALUES (1, "Projeto A"), (2, "Projeto B")');
        $this->conn->exec('INSERT INTO projects (id, name, deletedAt) VALUES (3, "Projeto Deletado", "2024-01-01 00:00:00")');

        $this->conn->exec('
            INSERT INTO users_projects (user_id, project_id, role_id) VALUES
            (10, 1, 1),
            (20, 2, 2),
            (10, 3, 1)
        ');

        $this->conn->exec('INSERT INTO devices (id, project_id) VALUES (100, 1), (200, 2)');
        $this->conn->exec('INSERT INTO charts (id, project_id) VALUES (1000, 1)');
    }

    public function testHasProjectAccessForMember(): void
    {
        $this->assertTrue(AuthMiddleware::hasProjectAccess($this->conn, 10, 1));
    }

    public function testHasProjectAccessDeniedForNonMember(): void
    {
        $this->assertFalse(AuthMiddleware::hasProjectAccess($this->conn, 30, 1));
    }

    public function testHasProjectAccessDeniedForSoftDeletedProject(): void
    {
        $this->assertFalse(AuthMiddleware::hasProjectAccess($this->conn, 10, 3));
    }

    public function testIsProjectManagerTrueForGerente(): void
    {
        $this->assertTrue(AuthMiddleware::isProjectManager($this->conn, 10, 1));
    }

    public function testIsProjectManagerFalseForParticipante(): void
    {
        $this->assertFalse(AuthMiddleware::isProjectManager($this->conn, 20, 2));
    }

    public function testHasDeviceAccessForMemberOfSameProject(): void
    {
        $this->assertTrue(AuthMiddleware::hasDeviceAccess($this->conn, 10, 100));
    }

    /**
     * Regressão do IDOR: usuário 10 é membro do projeto 1, mas o dispositivo
     * 200 pertence ao projeto 2 (do qual ele não participa). Antes da
     * correção, um gráfico podia apontar para o device_id de outro projeto
     * sem essa checagem barrar o acesso.
     */
    public function testHasDeviceAccessDeniedForDeviceOfAnotherProject(): void
    {
        $this->assertFalse(AuthMiddleware::hasDeviceAccess($this->conn, 10, 200));
    }

    public function testHasDeviceAccessForRightfulMember(): void
    {
        $this->assertTrue(AuthMiddleware::hasDeviceAccess($this->conn, 20, 200));
    }

    public function testHasChartAccessForProjectMember(): void
    {
        $this->assertTrue(AuthMiddleware::hasChartAccess($this->conn, 10, 1000));
    }

    public function testHasChartAccessDeniedForNonMember(): void
    {
        $this->assertFalse(AuthMiddleware::hasChartAccess($this->conn, 20, 1000));
    }

    public function testRequireAccessThrowsOnDeniedDeviceAccess(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Acesso ao dispositivo negado');

        AuthMiddleware::requireAccess($this->conn, 10, 'device', 200);
    }

    public function testRequireAccessPassesSilentlyWhenAllowed(): void
    {
        AuthMiddleware::requireAccess($this->conn, 10, 'device', 100);
        $this->addToAssertionCount(1); // não lançou exceção
    }
}
