<?php
/**
 * HtmlSanitizer - Limpa HTML escrito por administradores antes de gravá-lo
 *
 * Usado na documentação editável: o HTML salvo por um admin é exibido para
 * todos os usuários, então não pode carregar script. Mantém a formatação
 * (tabelas, listas, código, classes do Bootstrap, abas com data-toggle) e
 * remove o que executa código: elementos como <script>/<iframe>/<form>,
 * atributos on*, links javascript:/data: e CSS com url()/expression().
 */

namespace App\Core;

class HtmlSanitizer
{
    /** Elementos removidos com todo o conteúdo */
    private const ELEMENTOS_PROIBIDOS = [
        'script', 'style', 'iframe', 'frame', 'frameset', 'object', 'embed', 'applet',
        'form', 'input', 'textarea', 'select', 'option', 'button', 'link', 'meta', 'base',
        'svg', 'math', 'template', 'noscript', 'audio', 'video', 'source', 'track',
        'canvas', 'portal', 'dialog',
    ];

    /** Atributos que levam URL */
    private const ATRIBUTOS_URL = ['href', 'src', 'action', 'formaction', 'xlink:href', 'poster', 'background', 'cite', 'srcset'];

    public static function limpar(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }

        if (!class_exists(\DOMDocument::class)) {
            throw new \RuntimeException('O PHP do servidor não tem a extensão dom, necessária para salvar a documentação.');
        }
        $doc = new \DOMDocument();
        $anterior = libxml_use_internal_errors(true);
        // O prefixo de encoding faz o libxml ler o texto como UTF-8 (sem ele,
        // acentos viram "Ã©"); a div raiz delimita o fragmento
        $doc->loadHTML(
            '<?xml encoding="utf-8"?><div id="__raiz_sanitizer">' . $html . '</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET
        );
        libxml_clear_errors();
        libxml_use_internal_errors($anterior);

        $raiz = $doc->getElementById('__raiz_sanitizer');
        if (!$raiz) {
            return '';
        }

        self::limparNo($raiz);

        $saida = '';
        foreach ($raiz->childNodes as $filho) {
            $saida .= $doc->saveHTML($filho);
        }
        // O libxml codifica as chaves dentro de href/src; o marcador da
        // documentação ({{URL_BASE}}) precisa continuar legível
        return str_ireplace('%7B%7BURL_BASE%7D%7D', '{{URL_BASE}}', $saida);
    }

    private static function limparNo(\DOMNode $no): void
    {
        // Copia a lista: remover filhos durante a iteração pula elementos
        $filhos = iterator_to_array($no->childNodes);
        foreach ($filhos as $filho) {
            if ($filho instanceof \DOMComment || $filho instanceof \DOMProcessingInstruction) {
                $no->removeChild($filho);
                continue;
            }
            if (!($filho instanceof \DOMElement)) {
                continue;
            }
            if (in_array(strtolower($filho->nodeName), self::ELEMENTOS_PROIBIDOS, true)) {
                $no->removeChild($filho);
                continue;
            }
            self::limparAtributos($filho);
            self::limparNo($filho);
        }
    }

    private static function limparAtributos(\DOMElement $el): void
    {
        $remover = [];
        foreach ($el->attributes as $attr) {
            $nome = strtolower($attr->nodeName);
            $valor = $attr->nodeValue ?? '';

            if (str_starts_with($nome, 'on') || $nome === 'formaction' || $nome === 'srcdoc') {
                $remover[] = $attr->nodeName;
            } elseif (in_array($nome, self::ATRIBUTOS_URL, true)) {
                if (!self::urlPermitida($valor, $nome === 'src' && strtolower($el->nodeName) === 'img')) {
                    $remover[] = $attr->nodeName;
                }
            } elseif ($nome === 'style') {
                // Cores e alinhamento do editor ficam; o que busca recurso
                // externo ou executa código sai
                if (preg_match('/expression|javascript|vbscript|url\s*\(|@import|behavior|-moz-binding/i', $valor)) {
                    $remover[] = $attr->nodeName;
                }
            }
        }
        foreach ($remover as $nome) {
            $el->removeAttribute($nome);
        }

        // Link que abre em outra aba não pode controlar a página de origem
        if (strtolower($el->getAttribute('target')) === '_blank') {
            $el->setAttribute('rel', 'noopener noreferrer');
        }
    }

    private static function urlPermitida(string $url, bool $imagem): bool
    {
        // Navegadores ignoram espaços e caracteres de controle no esquema
        // ("java\nscript:"), então eles saem antes da comparação
        $limpa = strtolower(preg_replace('/[\x00-\x20]+/', '', html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        if ($limpa === '' || $limpa[0] === '#' || $limpa[0] === '/' || $limpa[0] === '.' || $limpa[0] === '?') {
            return true;
        }
        if (!preg_match('/^([a-z][a-z0-9+.-]*):/', $limpa, $m)) {
            return true; // relativa (ex.: "documentacao#ttn")
        }
        if (in_array($m[1], ['http', 'https', 'mailto', 'tel'], true)) {
            return true;
        }
        // Imagem colada no editor chega como data:image/...;base64
        return $imagem && preg_match('#^data:image/(png|jpe?g|gif|webp);base64,#', $limpa) === 1;
    }
}
