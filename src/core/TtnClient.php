<?php
/**
 * TtnClient.php - Acesso ao The Things Network (The Things Stack v3)
 *
 * Busca os uplinks guardados pela Storage Integration da aplicação. É o
 * servidor que abre a conexão (HTTPS, porta 443), então funciona sem que o
 * TTN consiga alcançar o servidor — ao contrário do webhook.
 *
 * Documentação: https://www.thethingsindustries.com/docs/integrations/storage/retrieve/
 */

namespace App\Core;

class TtnClient
{
    // Clusters públicos do TTN. Só estes hosts são aceitos: o endereço vem do
    // formulário do gerente, e aceitar qualquer host faria o servidor buscar
    // URLs arbitrárias (SSRF).
    const CLUSTERS = [
        'eu1.cloud.thethings.network' => 'Europa (eu1)',
        'nam1.cloud.thethings.network' => 'América do Norte (nam1)',
        'au1.cloud.thethings.network' => 'Austrália (au1)',
    ];

    const TIMEOUT = 15; // segundos

    public static function isValidCluster($cluster)
    {
        return is_string($cluster) && isset(self::CLUSTERS[$cluster]);
    }

    /**
     * IDs do The Things Stack (aplicação e end device): letras minúsculas,
     * números e hífens não consecutivos, de 3 a 36 caracteres.
     */
    public static function isValidId($id)
    {
        return is_string($id) && strlen($id) <= 36
            && preg_match('/^[a-z0-9](?:-?[a-z0-9]){2,}$/', $id) === 1;
    }

    /**
     * Converte um horário RFC 3339 do TTN (com até 9 casas decimais) em
     * segundos Unix com fração. Devolve null se não for um horário válido.
     */
    public static function parseTime($rfc3339)
    {
        if (!is_string($rfc3339) || $rfc3339 === '') {
            return null;
        }
        // DateTime aceita no máximo 6 casas decimais (microssegundos)
        $normalizado = preg_replace('/\.(\d{6})\d+/', '.$1', $rfc3339);
        try {
            $dt = new \DateTimeImmutable($normalizado);
        } catch (\Exception $e) {
            return null;
        }
        return (float)$dt->format('U.u');
    }

    /**
     * Uplinks guardados na Storage Integration, do mais antigo para o mais
     * novo, recebidos depois de $after (RFC 3339; null = desde o início da
     * retenção). Cada item tem end_device_ids, received_at e
     * uplink_message.decoded_payload.
     *
     * @throws \RuntimeException com mensagem pronta para mostrar ao usuário
     */
    public static function fetchStoredUplinks($cluster, $application_id, $api_key, $after = null, $limit = 100)
    {
        if (!self::isValidCluster($cluster) || !self::isValidId($application_id)) {
            throw new \RuntimeException('Cluster ou ID da aplicação do TTN inválido.');
        }

        $query = [
            'limit' => (int)$limit,
            'order' => 'received_at',
            'field_mask' => 'up.uplink_message.decoded_payload',
        ];
        if ($after !== null && $after !== '') {
            $query['after'] = $after;
        }
        $url = 'https://' . $cluster . '/api/v3/as/applications/' . rawurlencode($application_id)
            . '/packages/storage/uplink_message?' . http_build_query($query);

        [$status, $body] = self::httpGet($url, [
            'Authorization: Bearer ' . $api_key,
            'Accept: text/event-stream',
        ]);

        switch ($status) {
            case 200:
                return self::parseJsonStream($body);
            case 401:
                throw new \RuntimeException('O TTN recusou a chave de API (inválida ou revogada).');
            case 403:
                throw new \RuntimeException('A chave de API não tem permissão para ler o tráfego da aplicação.');
            case 404:
                throw new \RuntimeException('Aplicação não encontrada no TTN, ou a Storage Integration não está ativada nela.');
            default:
                throw new \RuntimeException("O TTN respondeu com HTTP $status.");
        }
    }

    /**
     * A API devolve um objeto JSON por registro ({"result": {...}}), formatado
     * em várias linhas e separado por linhas em branco — não é um JSON único
     * nem uma linha por registro. Este leitor separa os objetos de nível
     * superior contando chaves fora de strings.
     */
    public static function parseJsonStream($body)
    {
        $records = [];
        $depth = 0;
        $inString = false;
        $escaped = false;
        $start = null;
        $length = strlen($body);

        for ($i = 0; $i < $length; $i++) {
            $c = $body[$i];
            if ($inString) {
                if ($escaped) {
                    $escaped = false;
                } elseif ($c === '\\') {
                    $escaped = true;
                } elseif ($c === '"') {
                    $inString = false;
                }
                continue;
            }
            if ($c === '"') {
                $inString = true;
            } elseif ($c === '{') {
                if ($depth === 0) {
                    $start = $i;
                }
                $depth++;
            } elseif ($c === '}' && $depth > 0) {
                $depth--;
                if ($depth === 0) {
                    $obj = json_decode(substr($body, $start, $i - $start + 1), true);
                    if (is_array($obj)) {
                        $records[] = $obj['result'] ?? $obj;
                    }
                }
            }
        }
        return $records;
    }

    /**
     * GET com cURL quando disponível; senão, com as funções de stream do PHP
     * (hospedagens sem a extensão curl).
     *
     * @return array [código HTTP, corpo]
     */
    private static function httpGet($url, array $headers)
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_TIMEOUT => self::TIMEOUT,
                CURLOPT_CONNECTTIMEOUT => self::TIMEOUT,
                CURLOPT_FOLLOWLOCATION => false,
            ]);
            $body = curl_exec($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $error = curl_error($ch);
            curl_close($ch);
            if ($body === false) {
                throw new \RuntimeException('Não foi possível conectar ao TTN: ' . $error);
            }
            return [$status, $body];
        }

        $context = stream_context_create(['http' => [
            'method' => 'GET',
            'header' => implode("\r\n", $headers),
            'timeout' => self::TIMEOUT,
            'ignore_errors' => true, // lê o corpo também em respostas 4xx/5xx
            'follow_location' => 0,
        ]]);
        $body = @file_get_contents($url, false, $context);
        if ($body === false) {
            throw new \RuntimeException('Não foi possível conectar ao TTN.');
        }
        $status = 0;
        foreach ($http_response_header ?? [] as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
                $status = (int)$m[1];
            }
        }
        return [$status, $body];
    }
}
?>
