<?php

namespace App\Services\Security;

/**
 * Time-based one-time passwords (RFC 6238, SHA-1, 6 digits, 30-second steps), compatible with common authenticator apps.
 */
class TotpService
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    private const PERIOD_SECONDS = 30;

    public function generateSecret(): string
    {
        return $this->base32Encode(random_bytes(20));
    }

    public function provisioningUri(string $secret, string $accountName, string $issuer): string
    {
        return 'otpauth://totp/'.rawurlencode($issuer).':'.rawurlencode($accountName)
            .'?secret='.$secret.'&issuer='.rawurlencode($issuer).'&algorithm=SHA1&digits=6&period='.self::PERIOD_SECONDS;
    }

    public function codeAt(string $secret, int $timeStep): string
    {
        $hash = hash_hmac('sha1', pack('N*', 0, $timeStep), $this->base32Decode($secret), true);
        $offset = ord($hash[19]) & 0x0F;
        $binary = ((ord($hash[$offset]) & 0x7F) << 24) | (ord($hash[$offset + 1]) << 16) | (ord($hash[$offset + 2]) << 8) | ord($hash[$offset + 3]);

        return str_pad((string) ($binary % 1_000_000), 6, '0', STR_PAD_LEFT);
    }

    /**
     * Return the matching time step, allowing one step of clock drift either way, or null when the code is wrong.
     */
    public function matchingStep(string $secret, string $code, int $timestamp): ?int
    {
        $code = preg_replace('/\s+/', '', $code) ?? '';

        if (preg_match('/^\d{6}$/', $code) !== 1) {
            return null;
        }

        $currentStep = intdiv($timestamp, self::PERIOD_SECONDS);

        foreach ([$currentStep, $currentStep - 1, $currentStep + 1] as $step) {
            if (hash_equals($this->codeAt($secret, $step), $code)) {
                return $step;
            }
        }

        return null;
    }

    private function base32Encode(string $bytes): string
    {
        $bits = '';

        foreach (str_split($bytes) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }

        $encoded = '';

        foreach (str_split($bits, 5) as $chunk) {
            $encoded .= self::ALPHABET[bindec(str_pad($chunk, 5, '0'))];
        }

        return $encoded;
    }

    private function base32Decode(string $secret): string
    {
        $bits = '';

        foreach (str_split(strtoupper(rtrim($secret, '='))) as $character) {
            $position = strpos(self::ALPHABET, $character);
            $bits .= $position === false ? '' : str_pad(decbin($position), 5, '0', STR_PAD_LEFT);
        }

        $bytes = '';

        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) === 8) {
                $bytes .= chr(bindec($chunk));
            }
        }

        return $bytes;
    }
}
