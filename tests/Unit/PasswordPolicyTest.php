<?php

namespace Tests\Unit;

use App\Auth\PasswordPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PasswordPolicyTest extends TestCase
{
    /** @return array<string, array{string, string, ?string}> */
    public static function cases(): array
    {
        return [
            'fine' => ['correct-horse', 'ana', null],
            'exactly eight' => ['12345678', 'ana', null],
            'too short' => ['1234567', 'ana', 'password_short'],
            'empty' => ['', 'ana', 'password_short'],
            'exactly 128' => [str_repeat('a', 128), 'ana', null],
            'too long' => [str_repeat('a', 129), 'ana', 'password_long'],
            'same as the username' => ['anaanaana', 'anaanaana', 'password_username'],
            'same as the username in another case' => ['AnaAnaAna', 'anaanaana', 'password_username'],
        ];
    }

    #[DataProvider('cases')]
    public function test_the_rules(string $password, string $username, ?string $expected): void
    {
        $this->assertSame($expected, PasswordPolicy::problem($password, $username));
    }
}
