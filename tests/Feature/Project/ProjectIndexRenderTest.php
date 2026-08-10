<?php

namespace Tests\Feature\Project;

use Illuminate\Foundation\Testing\RefreshDatabase;

class ProjectIndexRenderTest extends ProjectTestCase
{
    use RefreshDatabase;

    public function test_index_renders_bootstrap_and_cache_busted_assets(): void
    {
        [$owner, $ws] = $this->owner();
        $this->makeProject($ws, $owner, ['name' => 'Testing', 'identifier' => 'TESTI', 'emoji' => '👍']);

        $html = $this->actingAs($owner)->get(route('projects.index'))->assertOk()->getContent();

        // Bootstrap payload is embedded for the Vue app to hydrate.
        $this->assertStringContainsString('id="settings-root"', $html);
        $this->assertStringContainsString('TESTI', $html);
        $this->assertStringContainsString('"canCreate":true', $html);

        // App JS is loaded and cache-busted via ?v=<mtime> so browsers refetch on change.
        $this->assertMatchesRegularExpression(
            '/assets\/js\/projects\/index\.js\?v=\d+/',
            $html,
            'projects/index.js should be served with a ?v= cache-busting query.'
        );
        $this->assertMatchesRegularExpression(
            '/assets\/js\/settings\/app\.js\?v=\d+/',
            $html,
            'settings/app.js should be served with a ?v= cache-busting query.'
        );
    }
}
