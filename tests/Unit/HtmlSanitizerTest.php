<?php
declare(strict_types=1);

use App\Core\HtmlSanitizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Sanitizador do HTML da documentação editável (o admin cola conteúdo do
 * editor; nada que execute código pode chegar aos visitantes).
 */
final class HtmlSanitizerTest extends TestCase
{
    public static function ataques(): array
    {
        return [
            'script'                 => ['<p>a</p><script>alert(1)</script>', 'script'],
            'onerror em img'         => ['<img src="x.png" onerror="alert(1)">', 'onerror'],
            'onclick maiúsculo'      => ['<a href="/x" OnClick="alert(1)">x</a>', 'onclick'],
            'javascript: no href'    => ['<a href="javascript:alert(1)">x</a>', 'javascript'],
            'javascript: ofuscado'   => ["<a href=\"java\nscript:alert(1)\">x</a>", 'script:'],
            'javascript: entidades'  => ['<a href="&#106;avascript:alert(1)">x</a>', 'avascript'],
            'vbscript'               => ['<a href="vbscript:msgbox(1)">x</a>', 'vbscript'],
            'data:text/html no link' => ['<a href="data:text/html,<script>alert(1)</script>">x</a>', 'data:'],
            'iframe'                 => ['<iframe src="https://mal.example"></iframe>', 'iframe'],
            'svg com onload'         => ['<svg onload="alert(1)"><circle/></svg>', 'svg'],
            'style com expression'   => ['<p style="width:expression(alert(1))">x</p>', 'expression'],
            'style com url()'        => ['<p style="background:url(https://mal.example/x)">x</p>', 'url('],
            'form'                   => ['<form action="https://mal.example"><input name="senha"></form>', 'form'],
            'meta refresh'           => ['<meta http-equiv="refresh" content="0;url=https://mal.example">', 'meta'],
            'srcdoc'                 => ['<div srcdoc="<script>alert(1)</script>">x</div>', 'srcdoc'],
            'base'                   => ['<base href="https://mal.example/">', 'base'],
        ];
    }

    #[DataProvider('ataques')]
    public function testRemoveConteudoPerigoso(string $entrada, string $proibido): void
    {
        $saida = HtmlSanitizer::limpar($entrada);
        $this->assertStringNotContainsStringIgnoringCase($proibido, $saida, "saída: $saida");
    }

    public function testMantemFormatacaoDoEditor(): void
    {
        $html = '<h3>Título</h3><p><strong>negrito</strong> <em>itálico</em> <code>x</code></p>'
              . '<ul><li>um</li></ul><table><tr><td style="text-align:center;color:#1B7D3D">c</td></tr></table>';
        $saida = HtmlSanitizer::limpar($html);
        foreach (['<h3>Título</h3>', '<strong>negrito</strong>', '<em>itálico</em>', '<code>x</code>', '<li>um</li>', 'text-align:center'] as $parte) {
            $this->assertStringContainsString($parte, $saida);
        }
    }

    public function testMantemLinksEImagensSeguros(): void
    {
        $saida = HtmlSanitizer::limpar('<a href="https://ifsc.edu.br">a</a> <a href="/documentacao#mqtt">b</a> <img src="/assets/img/x.png" alt="x">');
        $this->assertStringContainsString('href="https://ifsc.edu.br"', $saida);
        $this->assertStringContainsString('href="/documentacao#mqtt"', $saida);
        $this->assertStringContainsString('src="/assets/img/x.png"', $saida);
    }

    public function testLinkEmNovaAbaGanhaNoopener(): void
    {
        $saida = HtmlSanitizer::limpar('<a href="https://ifsc.edu.br" target="_blank">a</a>');
        $this->assertStringContainsString('rel="noopener noreferrer"', $saida);
    }

    public function testPreservaMarcadorDeUrlBaseEAcentos(): void
    {
        $saida = HtmlSanitizer::limpar('<p>Endereço: <a href="{{URL_BASE}}/api/enviar-payload">{{URL_BASE}}</a> ção</p>');
        $this->assertStringContainsString('href="{{URL_BASE}}/api/enviar-payload"', $saida);
        $this->assertStringContainsString('ção', $saida);
    }

    public function testRemoveComentariosEDevolveVazioParaEntradaVazia(): void
    {
        $this->assertStringNotContainsString('segredo', HtmlSanitizer::limpar('<p>a</p><!-- segredo -->'));
        $this->assertSame('', HtmlSanitizer::limpar("  \n "));
    }
}
