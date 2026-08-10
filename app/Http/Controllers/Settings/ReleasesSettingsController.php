<?php

namespace App\Http\Controllers\Settings;

use App\Http\Requests\Settings\LabelRequest;
use App\Http\Requests\Settings\ReleaseTagRequest;
use App\Models\ReleaseLabel;
use App\Models\ReleaseTag;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;

/**
 * Features > Releases (spec §8). Enable/disable (default on), Release Tags + Labels tabs.
 */
class ReleasesSettingsController extends SettingsController
{
    /** GET /settings/releases */
    public function show(): View
    {
        $this->guardManage();
        $settings = $this->settings();

        return $this->page('releases', [
            'enabled' => $settings->releases_enabled,
            'tags' => $this->tags(),
            'labels' => $this->labels(),
            'color' => $this->colorMeta(),
            'endpoints' => [
                'toggle' => route('settings.releases.toggle'),
                'tags' => route('settings.releases.tags.store'),
                'tag' => route('settings.releases.tags.update', ['tag' => '__ID__']),
                'labels' => route('settings.releases.labels.store'),
                'label' => route('settings.releases.labels.update', ['label' => '__ID__']),
            ],
        ]);
    }

    /** POST /settings/releases/toggle */
    public function toggle(): JsonResponse
    {
        $this->guardManage();
        $settings = $this->setFeature('releases_enabled', request()->boolean('enabled'));

        return response()->json(['ok' => true, 'enabled' => $settings->releases_enabled]);
    }

    /** POST /settings/releases/tags */
    public function storeTag(ReleaseTagRequest $request): JsonResponse
    {
        $this->guardManage();
        ReleaseTag::create([
            'name' => $request->validated('name'),
            'color' => $request->validated('color'),
            'position' => $this->nextPosition(ReleaseTag::class),
        ]);

        return response()->json(['ok' => true, 'tags' => $this->tags()]);
    }

    /** PATCH /settings/releases/tags/{tag} */
    public function updateTag(ReleaseTagRequest $request, ReleaseTag $tag): JsonResponse
    {
        $this->guardManage();
        $tag->forceFill(['name' => $request->validated('name'), 'color' => $request->validated('color')])->save();

        return response()->json(['ok' => true, 'tags' => $this->tags()]);
    }

    /** DELETE /settings/releases/tags/{tag} */
    public function destroyTag(ReleaseTag $tag): JsonResponse
    {
        $this->guardManage();
        $tag->delete();

        return response()->json(['ok' => true, 'tags' => $this->tags()]);
    }

    /** POST /settings/releases/labels */
    public function storeLabel(LabelRequest $request): JsonResponse
    {
        $this->guardManage();
        ReleaseLabel::create($request->only('name', 'color') + ['position' => $this->nextPosition(ReleaseLabel::class)]);

        return response()->json(['ok' => true, 'labels' => $this->labels()]);
    }

    /** PATCH /settings/releases/labels/{label} */
    public function updateLabel(LabelRequest $request, ReleaseLabel $label): JsonResponse
    {
        $this->guardManage();
        $label->forceFill($request->only('name', 'color'))->save();

        return response()->json(['ok' => true, 'labels' => $this->labels()]);
    }

    /** DELETE /settings/releases/labels/{label} */
    public function destroyLabel(ReleaseLabel $label): JsonResponse
    {
        $this->guardManage();
        $label->delete();

        return response()->json(['ok' => true, 'labels' => $this->labels()]);
    }

    private function tags(): array
    {
        return ReleaseTag::query()->orderBy('position')->get()
            ->map(fn (ReleaseTag $t) => ['id' => $t->id, 'name' => $t->name, 'color' => $t->color])->all();
    }

    private function labels(): array
    {
        return ReleaseLabel::query()->orderBy('position')->get()
            ->map(fn (ReleaseLabel $l) => ['id' => $l->id, 'name' => $l->name, 'color' => $l->color])->all();
    }
}
