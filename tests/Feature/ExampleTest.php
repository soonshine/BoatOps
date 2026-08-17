<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    public function test_root_redirects_to_operator_today(): void
    {
        $response = $this->get('/');

        $response->assertRedirect('/operator/today');
    }
}
