<?php

namespace App\Domain\Identity\Listeners;

use App\Domain\Identity\Events\PowerTierChanged;
use App\Domain\Identity\Events\TrueNatureChanged;
use App\Domain\Identity\Events\EntityStatusChanged;
use App\Domain\Identity\Models\VersionAndCanonState;
use App\Domain\System\Models\Setting;

class CreateCanonStateOnTierChange
{
    // Handles three event types — all trigger canon state snapshots
    // but with different significance thresholds

    public function handlePowerTierChanged(PowerTierChanged $event): void
    {
        if (! $this->settings()->notificationFlag('auto_save_canon_state_on_power_tier_change', true)) {
            return;
        }

        // Ceiling changes always snapshot
        // Operating changes only snapshot if crossing the planetary threshold
        if (!$event->isCeilingChange() && !$event->crossedPlanetaryThreshold()) {
            return;
        }

        $this->createSnapshot(
            entity: $event->entity,
            triggeredByField: 'power_tier_' . $event->axis,
            label: $this->powerTierLabel($event),
        );
    }

    public function handleTrueNatureChanged(TrueNatureChanged $event): void
    {
        if (! $this->settings()->notificationFlag('auto_save_canon_state_on_true_nature_change', true)) {
            return;
        }

        // True nature changes always snapshot — no threshold check needed
        $this->createSnapshot(
            entity: $event->entity,
            triggeredByField: 'true_nature',
            label: $event->isInitialDefinition()
                ? "True nature defined — {$event->entity->name}"
                : "True nature updated — {$event->entity->name}",
        );
    }

    public function handleEntityStatusChanged(EntityStatusChanged $event): void
    {
        if (! $this->settings()->notificationFlag('auto_save_canon_state_on_status_change', false)) {
            return;
        }

        if (!$event->isSignificant()) {
            return;
        }

        $this->createSnapshot(
            entity: $event->entity,
            triggeredByField: 'status',
            label: "Status: {$event->previousStatus} → {$event->newStatus} — {$event->entity->name}",
        );
    }

    // --- PRIVATE ---

    private function createSnapshot(
        $entity,
        string $triggeredByField,
        string $label,
    ): void {
        // Mark the current version as no longer current
        VersionAndCanonState::where('entity_id', $entity->id)
            ->where('is_current', true)
            ->update([
                'is_current'      => false,
                'valid_until_era' => $entity->attributes['current_era'] ?? null,
            ]);

        // Determine the next version number
        $nextVersionNumber = VersionAndCanonState::where('entity_id', $entity->id)
            ->max('version_number') + 1;

        // Create the new canonical snapshot
        VersionAndCanonState::create([
            'entity_id'          => $entity->id,
            'version_type'       => 'soft',
            'version_number'     => $nextVersionNumber,
            'version_label'      => $label,
            'version_state'      => 'current',
            'is_current'         => true,
            'is_version_zero'    => false,
            'entity_snapshot'    => $entity->toArray(),
            'trigger_type'       => 'automatic',
            'triggered_by_field' => $triggeredByField,
            'valid_from_era'     => $entity->attributes['current_era'] ?? null,
            'visibility'         => $entity->visibility,
            'content_classification' => $entity->content_classification,
        ]);
    }

    private function powerTierLabel(PowerTierChanged $event): string
    {
        $axisLabel = match($event->axis) {
            'ceiling'   => 'Power ceiling',
            'operating' => 'Operating tier',
            'influence'  => 'Influence tier',
            default      => 'Power tier',
        };

        $direction = $event->isIncrease() ? 'ascended' : 'descended';

        return "{$axisLabel} {$direction}: {$event->previousTier} → {$event->newTier} — {$event->entity->name}";
    }

    private function settings(): Setting
    {
        return Setting::singleton();
    }
}
