<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Listeners;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Stancl\Tenancy\Contracts\SyncMaster;
use Stancl\Tenancy\Events\SyncedResourceChangedInForeignDatabase;
use Stancl\Tenancy\Events\SyncedResourceSaved;
use Stancl\Tenancy\Exceptions\ModelNotSyncMasterException;

class UpdateSyncedResource extends QueueableListener
{
    public static $shouldQueue = false;

    public function handle(SyncedResourceSaved $event)
    {
        $syncedAttributes = $event->model->only($event->model->getSyncedAttributeNames());

        // We update the central record only if the event comes from tenant context.
        if ($event->tenant) {
            $tenants = $this->updateResourceInCentralDatabaseAndGetTenants($event, $syncedAttributes);
        } else {
            $tenants = $this->getTenantsForCentralModel($event->model);
        }

        \Helix\Lego\Jobs\UpdateSyncedResource::dispatch($tenants, $event, $syncedAttributes);
    }

    protected function getTenantsForCentralModel($centralModel)
    {
        if (! $centralModel instanceof SyncMaster) {
            // If we're trying to use a tenant User model instead of the central User model, for example.
            throw new ModelNotSyncMasterException(get_class($centralModel));
        }

        /** @var SyncMaster|Model $centralModel */

        // Since this model is "dirty" (taken by reference from the event), it might have the tenants
        // relationship already loaded and cached. For this reason, we refresh the relationship.
        $centralModel->load('tenants');

        return $centralModel->tenants;
    }

    protected function updateResourceInCentralDatabaseAndGetTenants($event, $syncedAttributes)
    {
        /** @var Model|SyncMaster $centralModel */
        $centralModel = $event->model->getCentralModelName()
            ::where($event->model->getGlobalIdentifierKeyName(), $event->model->getGlobalIdentifierKey())
            ->first();

        // We disable events for this call, to avoid triggering this event & listener again.
        $event->model->getCentralModelName()::withoutEvents(function () use (&$centralModel, $syncedAttributes, $event) {
            if ($centralModel) {
                $centralModel->update($syncedAttributes);
                event(new SyncedResourceChangedInForeignDatabase($event->model, null));
            } else {
                $syncedAttributes = array_merge([$event->model->getGlobalIdentifierKeyName() => $event->model->getGlobalIdentifierKey()], $syncedAttributes);
                $centralModel = $event->model->getCentralModelName()::create($syncedAttributes);
            }
        });

        // If the model was just created, the mapping of the tenant to the user likely doesn't exist, so we create it.
        $currentTenantMapping = function ($model) use ($event) {
            return ((string) $model->pivot->tenant_id) === ((string) $event->tenant->getTenantKey());
        };

        $mappingExists = $centralModel->tenants->contains($currentTenantMapping);

        if (! $mappingExists) {
            // Here we should call TenantPivot, but we call general Pivot, so that this works
            // even if people use their own pivot model that is not based on our TenantPivot
            Pivot::withoutEvents(function () use ($centralModel, $event) {
                $centralModel->tenants()->attach($event->tenant->getTenantKey());
            });
        }

        return $centralModel->tenants->filter(function ($model) use ($currentTenantMapping) {
            // Remove the mapping for the current tenant.
            return ! $currentTenantMapping($model);
        });
    }
}
