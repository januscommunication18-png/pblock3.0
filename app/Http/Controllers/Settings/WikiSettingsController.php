<?php

namespace App\Http\Controllers\Settings;

use App\Http\Requests\Settings\LabelRequest;
use App\Models\WikiLabel;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;

/**
 * Products > Wiki (spec §7). Enable/disable + workspace wiki labels (labels appear only
 * once the feature is enabled; disabled by default).
 */
class WikiSettingsController extends SettingsController
{
    /** GET /settings/wiki */
    public function show(): View
    {
        $this->guardManage();
        $settings = $this->settings();

        return $this->page('wiki', [
            'enabled' => $settings->wiki_enabled,
            'labels' => $this->labels(),
            'color' => $this->colorMeta(),
            'endpoints' => [
                'toggle' => route('settings.wiki.toggle'),
                'labels' => route('settings.wiki.labels.store'),
                'label' => route('settings.wiki.labels.update', ['label' => '__ID__']),
            ],
        ]);
    }

    /** POST /settings/wiki/toggle */
    public function toggle(): JsonResponse
    {
        $this->guardManage();
        $settings = $this->setFeature('wiki_enabled', request()->boolean('enabled'));

        return response()->json(['ok' => true, 'enabled' => $settings->wiki_enabled]);
    }

    /** POST /settings/wiki/labels */
    public function storeLabel(LabelRequest $request): JsonResponse
    {
        $this->guardManage();
        WikiLabel::create($request->only('name', 'color') + ['position' => $this->nextPosition(WikiLabel::class)]);

        return response()->json(['ok' => true, 'labels' => $this->labels()]);
    }

    /** PATCH /settings/wiki/labels/{label} */
    public function updateLabel(LabelRequest $request, WikiLabel $label): JsonResponse
    {
        $this->guardManage();
        $label->forceFill($request->only('name', 'color'))->save();

        return response()->json(['ok' => true, 'labels' => $this->labels()]);
    }

    /** DELETE /settings/wiki/labels/{label} */
    public function destroyLabel(WikiLabel $label): JsonResponse
    {
        $this->guardManage();
        $label->delete();

        return response()->json(['ok' => true, 'labels' => $this->labels()]);
    }

    private function labels(): array
    {
        return WikiLabel::query()->orderBy('position')->get()
            ->map(fn (WikiLabel $l) => ['id' => $l->id, 'name' => $l->name, 'color' => $l->color])->all();
    }
}
