<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SignsIn;
use Tests\TestCase;

class ArchiveSignInTest extends TestCase
{
    use RefreshDatabase, SignsIn;

    public function test_every_archive_route_needs_a_signed_in_person(): void
    {
        $this->api('GET', '/api/archive')->assertStatus(401)->assertExactJson(['error' => 'signin']);
        $this->api('POST', '/api/versions/1/archive')->assertStatus(401)->assertExactJson(['error' => 'signin']);
        $this->api('POST', '/api/versions/1/restore')->assertStatus(401)->assertExactJson(['error' => 'signin']);
        $this->api('GET', '/api/archive/rules')->assertStatus(401)->assertExactJson(['error' => 'signin']);
        $this->api('PUT', '/api/archive/rules/idle', ['enabled' => true, 'amount' => 1, 'unit' => 'days'])->assertStatus(401);
        $this->api('POST', '/api/archive/run')->assertStatus(401);
    }
}
