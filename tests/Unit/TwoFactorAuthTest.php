<?php

namespace Tests\Unit;

use App\Core\TwoFactorAuth;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Valida a implementação própria de TOTP (RFC 6238) contra os vetores de
 * teste oficiais da RFC, e cobre os comportamentos de segurança da classe
 * (janela de tolerância, proteção contra replay, formato dos códigos).
 */
final class TwoFactorAuthTest extends TestCase
{
    private function callPrivate(string $method, array $args)
    {
        $ref = new ReflectionClass(TwoFactorAuth::class);
        $m = $ref->getMethod($method);
        $m->setAccessible(true);
        return $m->invoke(null, ...$args);
    }

    public function testBase32RoundTrip(): void
    {
        $original = '12345678901234567890';
        $encoded = $this->callPrivate('base32Encode', [$original]);
        $decoded = $this->callPrivate('base32Decode', [$encoded]);

        $this->assertSame($original, $decoded);
        $this->assertSame('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', $encoded);
    }

    /**
     * RFC 6238 Appendix B - vetores de teste oficiais (segredo SHA1, 8 dígitos).
     * Nossa implementação gera 6 dígitos; comparamos com os últimos 6 dígitos
     * do vetor de 8 (matematicamente equivalente: valor mod 10^6).
     */
    public static function rfc6238VectorsProvider(): array
    {
        return [
            'time=59' => [59, '287082'],
            'time=1111111109' => [1111111109, '081804'],
            'time=1111111111' => [1111111111, '050471'],
            'time=1234567890' => [1234567890, '005924'],
            'time=2000000000' => [2000000000, '279037'],
        ];
    }

    #[DataProvider('rfc6238VectorsProvider')]
    public function testGeneratesCodeMatchingRfc6238Vectors(int $time, string $expected6Digits): void
    {
        $secretBase32 = $this->callPrivate('base32Encode', ['12345678901234567890']);
        $step = intdiv($time, 30);

        $codigo = $this->callPrivate('generateCodeAtStep', [$secretBase32, $step]);

        $this->assertSame($expected6Digits, $codigo);
    }

    public function testVerifyCodeAcceptsCurrentValidCode(): void
    {
        $secret = TwoFactorAuth::generateSecret();
        $currentStep = intdiv(time(), 30);
        $codigo = $this->callPrivate('generateCodeAtStep', [$secret, $currentStep]);

        $resultado = TwoFactorAuth::verifyCode($secret, $codigo, null);

        $this->assertSame($currentStep, $resultado);
    }

    public function testVerifyCodeRejectsWrongCode(): void
    {
        $secret = TwoFactorAuth::generateSecret();

        $this->assertFalse(TwoFactorAuth::verifyCode($secret, '000000', null));
    }

    public function testVerifyCodeRejectsMalformedInput(): void
    {
        $secret = TwoFactorAuth::generateSecret();

        $this->assertFalse(TwoFactorAuth::verifyCode($secret, '12345', null));   // curto demais
        $this->assertFalse(TwoFactorAuth::verifyCode($secret, 'abcdef', null));  // não numérico
        $this->assertFalse(TwoFactorAuth::verifyCode($secret, '', null));        // vazio
    }

    public function testVerifyCodeToleratesClockDriftWithinWindow(): void
    {
        $secret = TwoFactorAuth::generateSecret();
        $stepAnterior = intdiv(time(), 30) - 1;
        $codigo = $this->callPrivate('generateCodeAtStep', [$secret, $stepAnterior]);

        $resultado = TwoFactorAuth::verifyCode($secret, $codigo, null, 1);

        $this->assertSame($stepAnterior, $resultado);
    }

    public function testVerifyCodeRejectsReplayOfAlreadyUsedStep(): void
    {
        $secret = TwoFactorAuth::generateSecret();
        $currentStep = intdiv(time(), 30);
        $codigo = $this->callPrivate('generateCodeAtStep', [$secret, $currentStep]);

        // O mesmo step já foi marcado como usado (last_used_step = currentStep)
        $resultado = TwoFactorAuth::verifyCode($secret, $codigo, $currentStep);

        $this->assertFalse($resultado);
    }

    public function testGetOtpAuthUriContainsExpectedParameters(): void
    {
        $secret = 'JBSWY3DPEHPK3PXP';
        $uri = TwoFactorAuth::getOtpAuthUri($secret, 'user@example.com', 'IFSentral');

        $this->assertStringStartsWith('otpauth://totp/', $uri);
        $this->assertStringContainsString('secret=' . $secret, $uri);
        $this->assertStringContainsString('issuer=IFSentral', $uri);
        $this->assertStringContainsString('digits=6', $uri);
        $this->assertStringContainsString('period=30', $uri);
    }

    public function testGenerateBackupCodesReturnsCorrectCountAndFormat(): void
    {
        $codigos = TwoFactorAuth::generateBackupCodes(8);

        $this->assertCount(8, $codigos);
        $this->assertSame($codigos, array_unique($codigos), 'códigos de backup não devem se repetir');

        foreach ($codigos as $codigo) {
            $this->assertMatchesRegularExpression('/^[0-9A-F]{4}-[0-9A-F]{4}$/', $codigo);
        }
    }

    public function testGenerateSecretProducesValidBase32OfExpectedLength(): void
    {
        $secret = TwoFactorAuth::generateSecret(20);

        // 20 bytes -> 32 caracteres base32 (160 bits / 5 bits por caractere)
        $this->assertSame(32, strlen($secret));
        $this->assertMatchesRegularExpression('/^[A-Z2-7]+$/', $secret);
    }
}
