<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SignsIn;
use Tests\TestCase;

class DepartmentSignInTest extends TestCase
{
    use RefreshDatabase, SignsIn;

    public function test_every_department_route_needs_a_signed_in_person(): void
    {
        $this->api('GET', '/api/departments')->assertStatus(401)->assertExactJson(['error' => 'signin']);
        $this->api('POST', '/api/departments', ['name' => 'x'])->assertStatus(401);
        $this->api('PATCH', '/api/departments/1', ['name' => 'x'])->assertStatus(401);
    }
}
