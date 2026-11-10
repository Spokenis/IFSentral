<?php
/**
 * MqttHash.php - Hash de senha no formato do Mosquitto ($7$, PBKDF2-SHA512)
 *
 * Mesmo formato gerado por cadastrar_device.php e aceito pelo password_file
 * do Mosquitto 2.x: $7$<iterações>$<salt base64>$<hash base64>.
 */

if (!function_exists('mqtt_hash_senha')) {
    function mqtt_hash_senha(string $senha, int $iteracoes = 101): string
    {
        $salt = random_bytes(12);
        $hash = hash_pbkdf2('sha512', $senha, $salt, $iteracoes, 64, true);
        return sprintf('$7$%d$%s$%s', $iteracoes, base64_encode($salt), base64_encode($hash));
    }

    /** A senha em texto corresponde ao hash que o Mosquitto vai conferir? */
    function mqtt_senha_confere(string $senha, string $hash): bool
    {
        $partes = explode('$', $hash);
        if (count($partes) !== 5 || $partes[1] !== '7') {
            return false;
        }
        [, , $iteracoes, $salt, $esperado] = $partes;
        $calc = hash_pbkdf2('sha512', $senha, (string) base64_decode($salt), (int) $iteracoes, 64, true);
        return hash_equals((string) base64_decode($esperado), $calc);
    }
}
