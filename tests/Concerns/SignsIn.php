<?php

namespace Tests\Concerns;

use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Talks to the API as the app does (the X-Documentator header) and carries the session cookie from a sign-in to the
 * next requests of the same test.
 *
 * @mixin TestCase
 */
trait SignsIn
{
    private function api(string $method, string $uri, array $data = [], array $headers = []): TestResponse
    {
        return $this->json($method, $uri, $data, ['X-Documentator' => '1'] + $headers);
    }

    private function signIn(string $username, string $password = 'correct-horse'): TestResponse
    {
        $response = $this->api('POST', '/api/login', ['username' => $username, 'password' => $password]);
        if ($cookie = $response->getCookie(config('session.cookie'), false)) {
            $this->withUnencryptedCookie(config('session.cookie'), $cookie->getValue());
        }

        return $response;
    }
}
