<?php

namespace Tests\Unit;

use App\Support\SafeEmail;
use PHPUnit\Framework\TestCase;

class SafeEmailTest extends TestCase
{
    public function test_accepts_normal_email_addresses(): void
    {
        $this->assertTrue(SafeEmail::isValid('USER@example.test'));
        $this->assertSame('user@example.test', SafeEmail::normalize(' USER@example.test '));
    }

    public function test_rejects_crlf_in_email_addresses(): void
    {
        $this->assertFalse(SafeEmail::isValid("victim@example.test\r\nBcc: attacker@example.test"));
        $this->assertFalse(SafeEmail::isValid("victim@example.test\n"));
    }

    public function test_validation_rule_rejects_crlf_addresses(): void
    {
        $failed = false;

        SafeEmail::validationRule()('email', "victim@example.test\r\nBcc: attacker@example.test", function () use (&$failed): void {
            $failed = true;
        });

        $this->assertTrue($failed);
    }
}
