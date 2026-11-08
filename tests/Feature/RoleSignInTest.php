<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SignsIn;
use Tests\TestCase;

class RoleSignInTest extends TestCase
{
    use RefreshDatabase, SignsIn;

    public function test_every_role_route_needs_a_signed_in_person(): void
    {
        $this->api('GET', '/api/roles')->assertStatus(401)->assertExactJson(['error' => 'signin']);
        $this->api('POST', '/api/roles', ['name' => 'x'])->assertStatus(401);
        $this->api('PATCH', '/api/roles/1', ['name' => 'x'])->assertStatus(401);
        $this->api('DELETE', '/api/roles/1')->assertStatus(401);
    }
}
