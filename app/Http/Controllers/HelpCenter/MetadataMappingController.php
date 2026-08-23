<?php

namespace App\Http\Controllers\HelpCenter;

use App\Http\Controllers\Controller;
use App\Http\Controllers\HelpCenter\Concerns\GuardsHelpCenter;
use App\Http\Requests\HelpCenter\MetadataMappingRequest;
use App\Jobs\ReprocessSpaceMetadata;
use App\Models\HelpCenterMetadataMapping;
use App\Models\HelpCenterSpace;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

/**
 * Ticket Metadata Mapping (docs/features/help-center.md, P75 §3–§4, §14).
 *
 * Nested under the Space, like the custom fields the mappings point at: the row and the Space
 * are checked together, so a mapping belonging to another Space cannot be reached through this
 * Space's URL.
 *
 * Seeding lives here too. The first time somebody opens the page the table is empty, and an
 * empty table asks them to re-derive from scratch what an email already carries — so `seed`
 * writes the requirement's own default rows (`help-center.mapping_defaults`) on request rather
 * than at Space creation, where it would have run for every Space that never uses the feature.
 */
class MetadataMappingController extends Controller
{
    use GuardsHelpCenter;

    /** POST /help-center/spaces/{space}/metadata-mappings */
    public function store(MetadataMappingRequest $request, HelpCenterSpace $space): JsonResponse
    {
        $this->guard($space);

        if ($space->metadataMappings()->count() >= self::max()) {
            return response()->json([
                'ok' => false,
                'message' => 'This Space already has the maximum number of mappings.',
            ], 422);
        }

        $data = $request->validated();

        try {
            $mapping = $space->metadataMappings()->create($data + [
                'tenant_id' => $space->tenant_id,
                'position' => (int) $space->metadataMappings()->max('position') + 1,
                'created_by' => Auth::id(),
            ]);
        } catch (UniqueConstraintViolationException) {
            // The schema refuses two mappings writing one destination. Reported as a field
            // error rather than a 500, because it is a thing the person can see and fix.
            return $this->duplicate();
        }

        return response()->json([
            'ok' => true,
            'mapping' => $mapping->toPayload(),
            'message' => 'Mapping added.',
        ]);
    }

    /**
     * PATCH /help-center/spaces/{space}/metadata-mappings/{mapping}
     *
     * Takes the whole row — Save Changes sends it, and so does the row's Enable/Disable switch.
     * The client has the row in front of it either way, and two shapes for one write would be
     * two places that have to agree about what a missing key means.
     */
    public function update(
        MetadataMappingRequest $request,
        HelpCenterSpace $space,
        HelpCenterMetadataMapping $mapping,
    ): JsonResponse {
        $this->guard($space);
        abort_unless((int) $mapping->help_center_space_id === (int) $space->id, 404);

        try {
            $mapping->forceFill($request->validated())->save();
        } catch (UniqueConstraintViolationException) {
            return $this->duplicate();
        }

        return response()->json([
            'ok' => true,
            'mapping' => $mapping->fresh()->toPayload(),
            'message' => 'Mapping saved.',
        ]);
    }

    /**
     * DELETE /help-center/spaces/{space}/metadata-mappings/{mapping}
     *
     * A hard delete, unlike a custom field's Disable. A mapping holds no data of its own — it is
     * a rule — and the rule already has an off switch for the case where somebody means to come
     * back to it.
     */
    public function destroy(HelpCenterSpace $space, HelpCenterMetadataMapping $mapping): JsonResponse
    {
        $this->guard($space);
        abort_unless((int) $mapping->help_center_space_id === (int) $space->id, 404);

        $mapping->delete();

        return response()->json(['ok' => true, 'message' => 'Mapping deleted.']);
    }

    /**
     * POST /help-center/spaces/{space}/metadata-mappings/seed
     *
     * The packaged defaults, for a Space with none. Refuses when the table is not empty rather
     * than merging: "restore the defaults" over a table somebody has edited is a different and
     * more destructive action than the button offers.
     */
    public function seed(HelpCenterSpace $space): JsonResponse
    {
        $this->guard($space);

        if ($space->metadataMappings()->exists()) {
            return response()->json([
                'ok' => false,
                'message' => 'This Space already has mappings.',
            ], 422);
        }

        $position = 0;

        foreach ((array) config('help-center.mapping_defaults') as $row) {
            $space->metadataMappings()->create($row + [
                'tenant_id' => $space->tenant_id,
                'is_active' => true,
                'position' => ++$position,
                'created_by' => Auth::id(),
            ]);
        }

        return response()->json([
            'ok' => true,
            'mappings' => $space->metadataMappings()->ordered()->get()->map->toPayload()->all(),
            'message' => 'Default mappings added.',
        ]);
    }

    /**
     * POST /help-center/spaces/{space}/metadata-mappings/reprocess
     *
     * Reprocess Existing Records (§14).
     *
     * Queued, because it re-runs the mappings over every Request in the Space and that is not
     * work a browser should hold open. The confirmation is on the client — the requirement asks
     * for one before any bulk update — and this endpoint is what it confirms.
     */
    public function reprocess(HelpCenterSpace $space): JsonResponse
    {
        $this->guard($space);

        ReprocessSpaceMetadata::dispatch((int) $space->id, (string) $space->tenant_id);

        return response()->json([
            'ok' => true,
            'message' => 'Reprocessing started. Existing customers and companies will be updated in the background.',
        ]);
    }

    private function guard(HelpCenterSpace $space): void
    {
        $this->helpCenterWorkspace();
        abort_unless(Auth::user()->can('update', $space), 403);
    }

    private function duplicate(): JsonResponse
    {
        return response()->json([
            'ok' => false,
            'message' => 'Another mapping already writes to that field.',
            'errors' => ['destination' => ['Another mapping already writes to that field.']],
        ], 422);
    }

    private static function max(): int
    {
        return (int) config('help-center.mapping_max', 60);
    }
}
