<?php
declare(strict_types=1);

use App\Core\Agregados;
use PHPUnit\Framework\TestCase;

/**
 * Extração dos valores numéricos que alimentam as tabelas dos gráficos
 * (device_payload_values / device_payload_hourly).
 */
final class AgregadosTest extends TestCase
{
    public function testExtraiNumerosETextosNumericos(): void
    {
        $v = Agregados::extrairNumericos(['temperatura' => 21.5, 'umidade' => 60, 'pressao' => '1013.2', 'neg' => '-3', 'exp' => '1e3']);
        $this->assertSame(['temperatura' => 21.5, 'umidade' => 60.0, 'pressao' => 1013.2, 'neg' => -3.0, 'exp' => 1000.0], $v);
    }

    public function testIgnoraTextoBooleanoNuloEObjetos(): void
    {
        $v = Agregados::extrairNumericos(['nome' => 'sala 1', 'ligado' => true, 'x' => null, 'gps' => ['lat' => 1], 'vazio' => '', 'meio' => '12abc']);
        $this->assertSame([], $v);
    }

    public function testIgnoraNomesInvalidosOuLongos(): void
    {
        $v = Agregados::extrairNumericos(['ok_1' => 1, 'com espaço' => 2, "a'b" => 3, str_repeat('a', 101) => 4, 'b.c' => 5]);
        $this->assertSame(['ok_1' => 1.0], $v);
    }

    public function testAceitaObjetoDecodificadoDoJson(): void
    {
        $v = Agregados::extrairNumericos(json_decode('{"t": 20, "s": "x"}'));
        $this->assertSame(['t' => 20.0], $v);
    }

    public function testIgnoraInfinitoENaN(): void
    {
        $this->assertSame([], Agregados::extrairNumericos(['a' => INF, 'b' => NAN]));
    }

    public function testListaSemChavesTextoNaoGeraVariaveis(): void
    {
        $this->assertSame([], Agregados::extrairNumericos([1, 2, 3]));
    }
}
