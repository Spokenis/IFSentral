<?php
/**
 * TwoFactorAuth.php - TOTP (RFC 6238 / HOTP RFC 4226) sem dependências externas.
 * Compatível com Google Authenticator, Authy, 1Password, etc.
 */

namespace App\Core;

class TwoFactorAuth
{
    const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    const TIME_STEP = 30;
    const DIGITS = 6;

    /**
     * Gera um novo segredo aleatório (160 bits, formato Base32)
     */
    public static function generateSecret($bytes = 20)
    {
        return self::base32Encode(random_bytes($bytes));
    }

    /**
     * Monta a URI otpauth:// para configuração manual ou geração de QR code
     */
    public static function getOtpAuthUri($secret, $accountEmail, $issuer = 'IFSentral')
    {
        $label = rawurlencode($issuer . ':' . $accountEmail);
        $params = http_build_query([
            'secret' => $secret,
            'issuer' => $issuer,
            'algorithm' => 'SHA1',
            'digits' => self::DIGITS,
            'period' => self::TIME_STEP,
        ]);
        return "otpauth://totp/{$label}?{$params}";
    }

    /**
     * Gera o código TOTP de 6 dígitos para um dado instante (RFC 6238)
     */
    private static function generateCodeAtStep($secretBase32, $step)
    {
        $secret = self::base32Decode($secretBase32);
        // Contador de 8 bytes big-endian (RFC 4226 §5.2)
        $counterBin = pack('N*', 0, $step);
        $hash = hash_hmac('sha1', $counterBin, $secret, true);

        // Truncamento dinâmico (RFC 4226 §5.3)
        $offset = ord($hash[19]) & 0x0F;
        $part = substr($hash, $offset, 4);
        $value = unpack('N', $part)[1] & 0x7FFFFFFF;

        $otp = $value % (10 ** self::DIGITS);
        return str_pad((string)$otp, self::DIGITS, '0', STR_PAD_LEFT);
    }

    /**
     * Valida um código digitado pelo usuário, tolerando deriva de relógio
     * (janela de ±1 passo = ±30s) e bloqueando reuso do mesmo passo já usado
     * (proteção simples contra replay caso o código vaze).
     *
     * Retorna o número do "step" que validou (para persistir em last_used_step)
     * ou false se inválido.
     */
    public static function verifyCode($secretBase32, $code, $lastUsedStep = null, $window = 1)
    {
        $code = preg_replace('/\s+/', '', (string)$code);
        if (!preg_match('/^\d{6}$/', $code)) {
            return false;
        }

        $currentStep = (int) floor(time() / self::TIME_STEP);

        for ($i = -$window; $i <= $window; $i++) {
            $step = $currentStep + $i;

            if ($lastUsedStep !== null && $step <= (int)$lastUsedStep) {
                continue; // já usado (ou anterior ao último uso válido)
            }

            $expected = self::generateCodeAtStep($secretBase32, $step);
            if (hash_equals($expected, $code)) {
                return $step;
            }
        }

        return false;
    }

    /**
     * Gera códigos de backup de uso único (formato XXXX-XXXX)
     */
    public static function generateBackupCodes($count = 8)
    {
        $codes = [];
        for ($i = 0; $i < $count; $i++) {
            $raw = strtoupper(bin2hex(random_bytes(4))); // 8 caracteres hex
            $codes[] = substr($raw, 0, 4) . '-' . substr($raw, 4, 4);
        }
        return $codes;
    }

    private static function base32Encode($data)
    {
        if ($data === '') {
            return '';
        }

        $binaryString = '';
        foreach (str_split($data) as $char) {
            $binaryString .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
        }

        $encoded = '';
        foreach (str_split($binaryString, 5) as $chunk) {
            if (strlen($chunk) < 5) {
                $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
            }
            $encoded .= self::ALPHABET[bindec($chunk)];
        }

        return $encoded;
    }

    private static function base32Decode($base32)
    {
        $base32 = strtoupper(rtrim((string)$base32, '='));

        $binaryString = '';
        foreach (str_split($base32) as $char) {
            $pos = strpos(self::ALPHABET, $char);
            if ($pos === false) {
                continue;
            }
            $binaryString .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
        }

        $bytes = '';
        foreach (str_split($binaryString, 8) as $byteBits) {
            if (strlen($byteBits) === 8) {
                $bytes .= chr(bindec($byteBits));
            }
        }

        return $bytes;
    }
}
?>
