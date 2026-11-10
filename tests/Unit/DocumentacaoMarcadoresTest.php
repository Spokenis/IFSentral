<?php
declare(strict_types=1);

use App\Core\Documentacao;
use PHPUnit\Framework\TestCase;

/** Marcador {{URL_BASE}} da documentação trocado pelo endereço da instalação */
final class DocumentacaoMarcadoresTest extends TestCase
{
    private array $servidor;

    protected function setUp(): void { $this->servidor = $_SERVER; }
    protected function tearDown(): void { $_SERVER = $this->servidor; }

    public function testUsaHttpsQuandoAtrasDoProxy(): void
    {
        if (function_exists('app_absolute_url')) {
            $this->markTestSkipped('Lite usa app_absolute_url');
        }
        $_SERVER['HTTP_HOST'] = '10.50.0.30:8443';
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
        unset($_SERVER['HTTPS']);
        $html = Documentacao::aplicarMarcadores('<a href="{{URL_BASE}}/api/enviar-payload">{{URL_BASE}}</a> <a href="%7B%7BURL_BASE%7D%7D/x">y</a>');
        $this->assertSame('<a href="https://10.50.0.30:8443/api/enviar-payload">https://10.50.0.30:8443</a> <a href="https://10.50.0.30:8443/x">y</a>', $html);
    }

    public function testHostComAspasNaoQuebraOAtributo(): void
    {
        if (function_exists('app_absolute_url')) {
            $this->markTestSkipped('Lite usa app_absolute_url');
        }
        $_SERVER['HTTP_HOST'] = 'x"onmouseover="alert(1)';
        unset($_SERVER['HTTPS'], $_SERVER['HTTP_X_FORWARDED_PROTO']);
        $html = Documentacao::aplicarMarcadores('<a href="{{URL_BASE}}">a</a>');
        $this->assertStringNotContainsString('"onmouseover', $html);
    }
}
