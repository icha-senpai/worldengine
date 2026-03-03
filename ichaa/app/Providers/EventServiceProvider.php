<?php

namespace App\Providers;

use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

use App\Domain\Identity\Events\EntityCreated;
use App\Domain\Identity\Events\EntityStatusChanged;
use App\Domain\Identity\Events\PowerTierChanged;
use App\Domain\Identity\Events\TrueNatureChanged;
use App\Domain\Identity\Events\EntityPublished;

use App\Domain\Identity\Listeners\CreateCanonStateOnTierChange;
use App\Domain\Identity\Listeners\UpdateCompletionScore;

class EventServiceProvider extends ServiceProvider
{
    protected $listen = [

        // --- IDENTITY DOMAIN ---

        EntityCreated::class => [
            [UpdateCompletionScore::class, 'handleEntityCreated'],
        ],

        EntityStatusChanged::class => [
            [CreateCanonStateOnTierChange::class, 'handleEntityStatusChanged'],
        ],

        PowerTierChanged::class => [
            [CreateCanonStateOnTierChange::class, 'handlePowerTierChanged'],
        ],

        TrueNatureChanged::class => [
            [CreateCanonStateOnTierChange::class, 'handleTrueNatureChanged'],
        ],

        EntityPublished::class => [
            // Reserved for future listeners
            // e.g. NotifyPublicFeedOfNewEntity
        ],

    ];

    public function boot(): void
    {
        //
    }

    public function shouldDiscoverEvents(): bool
    {
        return false;
    }
}
