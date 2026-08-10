<?php

namespace App\Http\Controllers\Settings;

use App\Http\Requests\Settings\CustomerPropertyRequest;
use App\Models\CustomerProperty;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;

/**
 * Features > Customers (spec §11). Enable/disable, immutable default property set (config)
 * plus user-defined custom properties with type-specific configuration.
 */
class CustomersSettingsController extends SettingsController
{
    /** GET /settings/customers */
    public function show(): View
    {
        $this->guardManage();
        $settings = $this->settings();

        return $this->page('customers', [
            'enabled' => $settings->customers_enabled,
            'defaults' => config('settings.default_customer_properties'),
            'types' => config('settings.customer_property_types'),
            'properties' => $this->properties(),
            'endpoints' => [
                'toggle' => route('settings.customers.toggle'),
                'properties' => route('settings.customers.properties.store'),
                'property' => route('settings.customers.properties.update', ['property' => '__ID__']),
            ],
        ]);
    }

    /** POST /settings/customers/toggle */
    public function toggle(): JsonResponse
    {
        $this->guardManage();
        $settings = $this->setFeature('customers_enabled', request()->boolean('enabled'));

        return response()->json(['ok' => true, 'enabled' => $settings->customers_enabled]);
    }

    /** POST /settings/customers/properties */
    public function storeProperty(CustomerPropertyRequest $request): JsonResponse
    {
        $this->guardManage();

        CustomerProperty::create($this->attrs($request) + ['position' => $this->nextPosition(CustomerProperty::class)]);

        return response()->json(['ok' => true, 'properties' => $this->properties()]);
    }

    /** PATCH /settings/customers/properties/{property} */
    public function updateProperty(CustomerPropertyRequest $request, CustomerProperty $property): JsonResponse
    {
        $this->guardManage();
        $property->forceFill($this->attrs($request))->save();

        return response()->json(['ok' => true, 'properties' => $this->properties()]);
    }

    /** DELETE /settings/customers/properties/{property} */
    public function destroyProperty(CustomerProperty $property): JsonResponse
    {
        $this->guardManage();
        $property->delete();

        return response()->json(['ok' => true, 'properties' => $this->properties()]);
    }

    /** @return array<string, mixed> */
    private function attrs(CustomerPropertyRequest $request): array
    {
        return [
            'title' => $request->validated('title'),
            'description' => $request->validated('description'),
            'type' => $request->validated('type'),
            'mandatory' => $request->boolean('mandatory'),
            'active' => $request->boolean('active'),
            'options' => $request->validated('type') === 'Dropdown' ? array_values($request->validated('options') ?? []) : null,
        ];
    }

    private function properties(): array
    {
        return CustomerProperty::query()->orderBy('position')->get()
            ->map(fn (CustomerProperty $p) => [
                'id' => $p->id, 'title' => $p->title, 'description' => $p->description,
                'type' => $p->type, 'mandatory' => $p->mandatory, 'active' => $p->active,
                'options' => $p->options ?? [],
            ])->all();
    }
}
