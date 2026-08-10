<?php

namespace App\Http\Controllers\Settings;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;

/**
 * Renders the nav-only sections that are not Phase 3 functional deliverables (spec §12):
 * Coming-Soon (Templates, Integrations) and placeholders (Billing, Imports, Exports,
 * Project Block AI, Connections, Webhooks, Access Tokens). No feature behavior is exposed —
 * the section shows a Coming-Soon / placeholder panel. Unknown keys 404.
 */
class PlaceholderSettingsController extends SettingsController
{
    /** GET /settings/{section} (fallback for non-active sections). */
    public function show(string $section): View
    {
        $this->guardManage();

        $item = $this->navItems()->firstWhere('key', $section);
        abort_if($item === null || ($item['status'] ?? '') === 'active', 404);

        return $this->page('placeholder', [
            'label' => $item['label'],
            'comingSoon' => $item['status'] === 'soon',
        ]);
    }

    private function navItems(): Collection
    {
        return collect(config('settings.nav'))->flatMap(fn ($items) => $items);
    }
}
