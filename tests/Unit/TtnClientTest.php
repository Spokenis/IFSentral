<?php

namespace Tests\Unit;

use App\Core\TtnClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Cobre as partes do TtnClient que não dependem de rede: a leitura da
 * resposta da Storage Integration, a conversão dos horários do TTN e a
 * validação de IDs e clusters. fetchStoredUplinks() é exercitado pelos
 * testes E2E (testes-e2e/), contra um TTN simulado.
 */
final class TtnClientTest extends TestCase
{
    public function testParseJsonStreamReadsPrettyPrintedRecordsSeparatedByBlankLines(): void
    {
        // Formato da documentação: um objeto {"result": ...} formatado por registro
        $body = <<<JSON
        {
          "result": {
            "end_device_ids": {"device_id": "sensor-1"},
            "received_at": "2026-10-26T12:00:00.123456789Z",
            "uplink_message": {"decoded_payload": {"temperatura": 21.5}}
          }
        }

        {
          "result": {
            "end_device_ids": {"device_id": "sensor-2"},
            "received_at": "2026-10-26T12:01:00Z",
            "uplink_message": {"decoded_payload": {"umidade": 60}}
          }
        }

        JSON;

        $records = TtnClient::parseJsonStream($body);

        $this->assertCount(2, $records);
        $this->assertSame('sensor-1', $records[0]['end_device_ids']['device_id']);
        $this->assertSame(21.5, $records[0]['uplink_message']['decoded_payload']['temperatura']);
        $this->assertSame(60, $records[1]['uplink_message']['decoded_payload']['umidade']);
    }

    public function testParseJsonStreamIgnoresBracesAndEscapedQuotesInsideStrings(): void
    {
        $body = '{"result":{"uplink_message":{"decoded_payload":{"msg":"chaves { } e \\"aspas\\""}}}}'
            . '{"result":{"uplink_message":{"decoded_payload":{"n":2}}}}';

        $records = TtnClient::parseJsonStream($body);

        $this->assertCount(2, $records);
        $this->assertSame('chaves { } e "aspas"', $records[0]['uplink_message']['decoded_payload']['msg']);
        $this->assertSame(2, $records[1]['uplink_message']['decoded_payload']['n']);
    }

    public function testParseJsonStreamReturnsEmptyArrayForEmptyBody(): void
    {
        $this->assertSame([], TtnClient::parseJsonStream(''));
    }

    public function testParseTimeAcceptsNanosecondsFromTtn(): void
    {
        // O TTN usa 9 casas decimais; o DateTime do PHP só aceita 6
        $this->assertEqualsWithDelta(1793016000.123456, TtnClient::parseTime('2026-10-26T12:00:00.123456789Z'), 0.000001);
    }

    public function testParseTimeAcceptsTimestampWithoutFraction(): void
    {
        $this->assertEqualsWithDelta(1793016000.0, TtnClient::parseTime('2026-10-26T12:00:00Z'), 0.000001);
    }

    public function testParseTimeRejectsInvalidInput(): void
    {
        $this->assertNull(TtnClient::parseTime(''));
        $this->assertNull(TtnClient::parseTime(null));
        $this->assertNull(TtnClient::parseTime('ontem'));
    }

    public static function idsProvider(): array
    {
        return [
            'minúsculas e hífen' => ['sensor-sala-204', true],
            'só números' => ['204', true],
            '36 caracteres' => [str_repeat('a', 36), true],
            '2 caracteres' => ['ab', false],
            '37 caracteres' => [str_repeat('a', 37), false],
            'maiúscula' => ['Sensor-1', false],
            'sublinhado' => ['sensor_1', false],
            'hífens seguidos' => ['sensor--1', false],
            'hífen no fim' => ['sensor-', false],
            'caminho' => ['../admin', false],
        ];
    }

    #[DataProvider('idsProvider')]
    public function testIsValidId(string $id, bool $esperado): void
    {
        $this->assertSame($esperado, TtnClient::isValidId($id));
    }

    public function testOnlyPublicTtnClustersAreAccepted(): void
    {
        $this->assertTrue(TtnClient::isValidCluster('eu1.cloud.thethings.network'));
        $this->assertFalse(TtnClient::isValidCluster('169.254.169.254'));
        $this->assertFalse(TtnClient::isValidCluster('eu1.cloud.thethings.network.exemplo.com'));
        $this->assertFalse(TtnClient::isValidCluster(null));
    }
}
