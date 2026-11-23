<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * A basic test example.
     */
    public function test_the_application_returns_a_successful_response(): void
    {
        $response = $this->get('/');

        // '/' butuh auth -> redirect 302 ke login itu perilaku benar.
        // Terima 200 (guest page) atau 302 (redirect login).
        $response->assertRedirect('/login');
    }
}
