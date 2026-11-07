<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SetPasswordCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_makes_the_first_account(): void
    {
        $this->artisan('documentator:set-password', ['username' => 'Admin', '--create' => true, '--name' => 'The Admin'])
            ->expectsQuestion('Password', 'long-enough-1')
            ->expectsQuestion('Password again', 'long-enough-1')
            ->assertSuccessful();

        $user = User::findByUsername('admin');
        $this->assertSame('The Admin', $user->name);
        $this->assertTrue($user->active);
        $this->assertTrue(Hash::check('long-enough-1', $user->password));
        $this->assertSame(['user.created'], DB::table('audit_logs')->pluck('action')->all());
    }

    public function test_without_create_an_unknown_account_is_refused(): void
    {
        $this->artisan('documentator:set-password', ['username' => 'ghost'])->assertFailed();

        $this->assertSame(0, User::count());
    }

    public function test_a_weak_password_is_refused(): void
    {
        $this->artisan('documentator:set-password', ['username' => 'admin', '--create' => true])
            ->expectsQuestion('Password', 'short')
            ->expectsQuestion('Password again', 'short')
            ->assertFailed();

        $this->assertSame(0, User::count());
    }

    public function test_two_different_passwords_are_refused(): void
    {
        $this->artisan('documentator:set-password', ['username' => 'admin', '--create' => true])
            ->expectsQuestion('Password', 'long-enough-1')
            ->expectsQuestion('Password again', 'long-enough-2')
            ->assertFailed();

        $this->assertSame(0, User::count());
    }

    public function test_a_bad_username_is_refused(): void
    {
        $this->artisan('documentator:set-password', ['username' => 'a b', '--create' => true])->assertFailed();
    }

    public function test_changing_a_password_signs_the_person_out_everywhere(): void
    {
        $user = User::factory()->create(['username' => 'ana']);
        DB::table('sessions')->insert(['id' => 's1', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);

        $this->artisan('documentator:set-password', ['username' => 'ana'])
            ->expectsQuestion('Password', 'brand-new-pass')
            ->expectsQuestion('Password again', 'brand-new-pass')
            ->assertSuccessful();

        $this->assertSame(0, DB::table('sessions')->count());
        $this->assertTrue(Hash::check('brand-new-pass', $user->fresh()->password));
        $this->assertSame(['user.password-set'], DB::table('audit_logs')->pluck('action')->all());
    }
}
