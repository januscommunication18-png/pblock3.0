<?php

namespace App\Http\Controllers\Settings;

use App\Http\Requests\Settings\LabelRequest;
use App\Models\InitiativeLabel;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;

/**
 * Features > Initiatives (spec §9). Enable/disable (default off). Initiative labels are
 * LOCKED while the feature is disabled — the API rejects label writes until it is enabled
 * (SET §9), never relying on UI hiding alone.
 */
class InitiativesSettingsController extends SettingsController
{
    /** GET /settings/initiatives */
    public function show(): View
    {
        $this->guardManage();
        $settings = $this->settings();

        return $this->page('initiatives', [
            'enabled' => $settings->initiatives_enabled,
            'labels' => $this->labels(),
            'color' => $this->colorMeta(),
            'endpoints' => [
                'toggle' => route('settings.initiatives.toggle'),
                'labels' => route('settings.initiatives.labels.store'),
                'label' => route('settings.initiatives.labels.update', ['label' => '__ID__']),
            ],
        ]);
    }

    /** POST /settings/initiatives/toggle */
    public function toggle(): JsonResponse
    {
        $this->guardManage();
        $settings = $this->setFeature('initiatives_enabled', request()->boolean('enabled'));

        return response()->json(['ok' => true, 'enabled' => $settings->initiatives_enabled]);
    }

    /** POST /settings/initiatives/labels */
    public function storeLabel(LabelRequest $request): JsonResponse
    {
        $this->guardManage();
        $this->guardEnabled();

        InitiativeLabel::create($request->only('name', 'color') + ['position' => $this->nextPosition(InitiativeLabel::class)]);

        return response()->json(['ok' => true, 'labels' => $this->labels()]);
    }

    /** PATCH /settings/initiatives/labels/{label} */
    public function updateLabel(LabelRequest $request, InitiativeLabel $label): JsonResponse
    {
        $this->guardManage();
        $this->guardEnabled();
        $label->forceFill($request->only('name', 'color'))->save();

        return response()->json(['ok' => true, 'labels' => $this->labels()]);
    }

    /** DELETE /settings/initiatives/labels/{label} */
    public function destroyLabel(InitiativeLabel $label): JsonResponse
    {
        $this->guardManage();
        $this->guardEnabled();
        $label->delete();

        return response()->json(['ok' => true, 'labels' => $this->labels()]);
    }

    /** Reject label writes while Initiatives is disabled (spec §9 lock). */
    private function guardEnabled(): void
    {
        abort_unless($this->settings()->initiatives_enabled, 422, 'Enable Initiatives to manage labels.');
    }

    private function labels(): array
    {
        return InitiativeLabel::query()->orderBy('position')->get()
            ->map(fn (InitiativeLabel $l) => ['id' => $l->id, 'name' => $l->name, 'color' => $l->color])->all();
    }
}
