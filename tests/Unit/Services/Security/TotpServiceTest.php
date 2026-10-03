<?php

namespace Tests\Unit\Services\Security;

use App\Services\Security\TotpService;
use PHPUnit\Framework\TestCase;

class TotpServiceTest extends TestCase
{
    /** Base32 of the RFC 6238 SHA-1 test secret "12345678901234567890". */
    private const RFC_SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

    public function test_matches_the_rfc_6238_reference_codes(): void
    {
        $totp = new TotpService;

        $this->assertSame('287082', $totp->codeAt(self::RFC_SECRET, intdiv(59, 30)));
        $this->assertSame('081804', $totp->codeAt(self::RFC_SECRET, intdiv(1111111109, 30)));
        $this->assertSame('005924', $totp->codeAt(self::RFC_SECRET, intdiv(1234567890, 30)));
    }

    public function test_accepts_one_step_of_clock_drift_but_not_more(): void
    {
        $totp = new TotpService;
        $timestamp = 1_800_000_000;
        $previous = $totp->codeAt(self::RFC_SECRET, intdiv($timestamp, 30) - 1);
        $tooOld = $totp->codeAt(self::RFC_SECRET, intdiv($timestamp, 30) - 2);

        $this->assertSame(intdiv($timestamp, 30) - 1, $totp->matchingStep(self::RFC_SECRET, $previous, $timestamp));
        $this->assertNull($totp->matchingStep(self::RFC_SECRET, $tooOld, $timestamp));
        $this->assertNull($totp->matchingStep(self::RFC_SECRET, 'abc123', $timestamp));
    }

    public function test_generated_secrets_are_base32_and_work_in_the_provisioning_uri(): void
    {
        $totp = new TotpService;
        $secret = $totp->generateSecret();

        $this->assertMatchesRegularExpression('/^[A-Z2-7]{32}$/', $secret);
        $this->assertStringStartsWith('otpauth://totp/KontoTrack:arta%40example.test?secret='.$secret, $totp->provisioningUri($secret, 'arta@example.test', 'KontoTrack'));
    }
}
