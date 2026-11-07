<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\SignsIn;
use Tests\TestCase;

class SignInTest extends TestCase
{
    use RefreshDatabase, SignsIn;

    private function actions(): array
    {
        return DB::table('audit_logs')->orderBy('id')->pluck('action')->all();
    }

    public function test_the_right_password_signs_in_and_is_audited(): void
    {
        $user = User::factory()->create(['username' => 'ana']);

        $this->signIn('ana')
            ->assertOk()
            ->assertJsonPath('user.username', 'ana')
            ->assertJsonMissingPath('user.password');

        $this->assertNotNull($user->fresh()->last_login_at);
        $this->assertSame(['sign-in'], $this->actions());
        $this->assertSame($user->id, DB::table('audit_logs')->value('actor_user_id'));
    }

    public function test_the_session_endpoint_knows_who_is_signed_in(): void
    {
        User::factory()->create(['username' => 'ana']);

        $this->api('GET', '/api/session')->assertOk()->assertJsonPath('user', null);

        $this->signIn('ana');
        $this->api('GET', '/api/session')->assertOk()->assertJsonPath('user.username', 'ana');
    }

    public function test_the_username_is_not_case_sensitive(): void
    {
        User::factory()->create(['username' => 'ana']);

        $this->signIn('  ANA ')->assertOk()->assertJsonPath('user.username', 'ana');
    }

    public function test_a_wrong_password_is_refused_and_audited(): void
    {
        User::factory()->create(['username' => 'ana']);

        $this->signIn('ana', 'not-the-password')->assertStatus(401)->assertExactJson(['error' => 'bad_login']);

        $this->assertSame(['sign-in.failed'], $this->actions());
        $this->api('GET', '/api/session')->assertJsonPath('user', null);
    }

    public function test_an_unknown_username_gets_the_same_answer_as_a_wrong_password(): void
    {
        $this->signIn('nobody')->assertStatus(401)->assertExactJson(['error' => 'bad_login']);

        $this->assertSame(['sign-in.failed'], $this->actions());
        $this->assertSame('nobody', DB::table('audit_logs')->value('actor_username'));
    }

    public function test_an_empty_password_is_refused(): void
    {
        User::factory()->create(['username' => 'ana']);

        $this->signIn('ana', '')->assertStatus(401)->assertJsonPath('error', 'bad_login');
    }

    public function test_five_wrong_passwords_lock_the_username_even_for_the_right_password(): void
    {
        User::factory()->create(['username' => 'ana']);

        for ($i = 0; $i < 5; $i++) {
            $this->signIn('ana', 'wrong-wrong')->assertStatus(401);
        }

        $this->signIn('ana')->assertStatus(429)->assertJsonPath('error', 'locked')->assertJsonPath('minutes', 15);
        $this->api('GET', '/api/session')->assertJsonPath('user', null);
    }

    public function test_the_lock_ends_after_fifteen_minutes(): void
    {
        User::factory()->create(['username' => 'ana']);
        for ($i = 0; $i < 5; $i++) {
            $this->signIn('ana', 'wrong-wrong');
        }

        $this->travel(16)->minutes();

        $this->signIn('ana')->assertOk();
    }

    public function test_a_successful_sign_in_clears_the_username_count(): void
    {
        User::factory()->create(['username' => 'ana']);
        for ($i = 0; $i < 4; $i++) {
            $this->signIn('ana', 'wrong-wrong');
        }
        $this->signIn('ana')->assertOk();

        for ($i = 0; $i < 4; $i++) {
            $this->signIn('ana', 'wrong-wrong')->assertStatus(401);
        }
    }

    public function test_one_address_trying_many_usernames_gets_locked(): void
    {
        for ($i = 0; $i < 25; $i++) {
            $this->signIn("nobody{$i}")->assertStatus(401);
        }

        User::factory()->create(['username' => 'ana']);
        $this->signIn('ana')->assertStatus(429);
    }

    public function test_a_switched_off_account_cannot_sign_in(): void
    {
        User::factory()->inactive()->create(['username' => 'ana']);

        $this->signIn('ana')->assertStatus(403)->assertExactJson(['error' => 'inactive']);

        $this->assertSame(['sign-in.refused'], $this->actions());
        $this->api('GET', '/api/session')->assertJsonPath('user', null);
    }

    public function test_switching_an_account_off_ends_its_session_at_once(): void
    {
        $user = User::factory()->create(['username' => 'ana']);
        $this->signIn('ana')->assertOk();

        $user->update(['active' => false]);
        // a real request starts with a fresh guard; the test app keeps the one it loaded before
        $this->app['auth']->forgetGuards();

        $this->api('GET', '/api/session')->assertJsonPath('user', null);
    }

    public function test_a_session_older_than_the_absolute_limit_ends_even_when_in_use(): void
    {
        config(['documentator.session_max_days' => 0.02]); // about 29 minutes
        User::factory()->create(['username' => 'ana']);
        $this->signIn('ana')->assertOk();

        $this->travel(20)->minutes();
        $this->api('GET', '/api/session')->assertJsonPath('user.username', 'ana');

        $this->travel(20)->minutes();
        $this->api('GET', '/api/session')->assertJsonPath('user', null);
    }

    public function test_an_account_that_belongs_to_onehub_cannot_use_a_local_password(): void
    {
        User::factory()->create(['username' => 'ana', 'auth_source' => User::AUTH_ONEHUB, 'external_id' => 'oh-1']);

        $this->signIn('ana')->assertStatus(401)->assertJsonPath('error', 'bad_login');
    }

    public function test_signing_out_ends_the_session_and_is_audited(): void
    {
        User::factory()->create(['username' => 'ana']);
        $this->signIn('ana');

        $this->api('POST', '/api/logout')->assertOk();

        $this->api('GET', '/api/session')->assertJsonPath('user', null);
        $this->assertSame(['sign-in', 'sign-out'], $this->actions());
    }

    public function test_a_request_without_the_app_header_is_refused(): void
    {
        $this->json('POST', '/api/login', ['username' => 'ana', 'password' => 'x'])
            ->assertStatus(403)->assertExactJson(['error' => 'header']);
    }

    public function test_a_request_from_another_origin_is_refused(): void
    {
        $this->api('POST', '/api/login', ['username' => 'ana', 'password' => 'x'], ['Origin' => 'https://evil.example'])
            ->assertStatus(403)->assertExactJson(['error' => 'origin']);
    }

    public function test_a_body_that_is_not_a_json_object_is_refused(): void
    {
        $this->call('POST', '/api/login', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_DOCUMENTATOR' => '1'], '[1,2]')
            ->assertStatus(400)->assertJsonPath('error', 'body');
    }

    public function test_the_session_cookie_is_strict_and_http_only(): void
    {
        User::factory()->create(['username' => 'ana']);

        $cookie = $this->signIn('ana')->getCookie(config('session.cookie'), false);

        $this->assertNotNull($cookie);
        $this->assertSame('strict', $cookie->getSameSite());
        $this->assertTrue($cookie->isHttpOnly());
    }

    public function test_the_signed_in_group_answers_401_signin_to_a_stranger_and_lets_a_person_in(): void
    {
        Route::middleware(['api', 'documentator.signed-in'])->get('api/probe', fn () => response()->json(['ok' => true]));
        User::factory()->create(['username' => 'ana']);

        $this->api('GET', '/api/probe')->assertStatus(401)->assertExactJson(['error' => 'signin']);

        $this->signIn('ana');
        $this->api('GET', '/api/probe')->assertOk();
    }

    public function test_an_unknown_api_route_answers_in_the_error_shape(): void
    {
        $this->api('GET', '/api/nothing-here')->assertStatus(404)->assertExactJson(['error' => 'route']);
    }
}
