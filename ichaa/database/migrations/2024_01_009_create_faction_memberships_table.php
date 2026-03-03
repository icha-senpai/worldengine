<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('faction_memberships', function (Blueprint $table) {
            $table->id();

            // --- THE TWO PARTIES ---

            // The faction, organization, government, or nation
            // entity_type should be: organization, nation, government,
            // culture, religion, or any grouping entity
            $table->foreignId('faction_entity_id')
                ->constrained('entities')
                ->cascadeOnDelete();

            // The member — any entity type
            // Characters most commonly, but locations, artifacts,
            // or other factions can also be members of a faction
            $table->foreignId('member_entity_id')
                ->constrained('entities')
                ->cascadeOnDelete();

            // --- MEMBERSHIP DETAILS ---

            // Their position or title within the faction
            $table->string('rank_or_role')->nullable();
            // e.g. Founder, High Inquisitor, Field Operative,
            // Installed Leader, Shadow Controller, Initiate

            // The nature of this membership
            $table->string('membership_status');
            // active       — currently a member in good standing
            // former       — was a member, no longer
            // founding     — helped establish this faction
            // involuntary  — conscripted, enslaved, or forced
            // undercover   — member as cover, true loyalty elsewhere
            // suspected    — believed to be a member, unconfirmed
            // honorary     — recognized but not active participant

            // --- TEMPORAL SCOPE ---

            $table->string('joined_at_era')->nullable();
            $table->string('left_at_era')->nullable();  // Null means still active
            $table->text('departure_reason')->nullable();

            // --- SOCIAL NETWORK ---
            // Who brought this entity into the faction
            // Useful for understanding faction architecture and loyalty chains
            $table->unsignedBigInteger('recruited_by_entity_id')->nullable();

            // --- PERCEPTION LAYER ---
            // Is this membership publicly known
            // Seraphine's puppets have official memberships in their governments
            // but the true controller is her — not public knowledge

            $table->boolean('public_membership_known')->default(true);

            // For undercover or double agents — who they actually serve
            // This is the load-bearing field for Seraphine's apparatus model
            // A puppet leader's true_loyalty_entity_id points to Seraphine
            $table->unsignedBigInteger('true_loyalty_entity_id')->nullable();

            // Notes on the gap between public membership and true loyalty
            $table->text('loyalty_notes')->nullable();

            // --- GENERAL NOTES ---

            $table->text('notes')->nullable();

            // --- SOFT DELETE AND TIMESTAMPS ---
            // No visibility field — membership records are always private
            // Public-facing faction membership surfaces through
            // the entity's public profile, not this table directly

            $table->softDeletes();
            $table->timestamps();
        });

        // --- INDEXES ---

        Schema::table('faction_memberships', function (Blueprint $table) {
            $table->index('faction_entity_id');
            $table->index('member_entity_id');
            $table->index('membership_status');
            $table->index('recruited_by_entity_id');
            $table->index('true_loyalty_entity_id');
            $table->index('public_membership_known');
            $table->index('deleted_at');

            // Compound index for the primary faction query:
            // "give me all active members of this faction"
            $table->index(['faction_entity_id', 'membership_status']);

            // Compound index for member history:
            // "show me all factions this entity has ever belonged to"
            $table->index(['member_entity_id', 'membership_status']);

            // Compound index for the puppet apparatus query:
            // "show me all entities whose true loyalty is to Seraphine"
            $table->index(['true_loyalty_entity_id', 'membership_status']);

            // Compound index for era-scoped membership:
            // "who were the active members of this faction during cycle 12"
            $table->index(['faction_entity_id', 'joined_at_era', 'left_at_era']);

            // Prevent exact duplicate active memberships
            $table->unique(
                ['faction_entity_id', 'member_entity_id'],
                'faction_memberships_unique_pair'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('faction_memberships');
    }
};

/*
|--------------------------------------------------------------------------
| MEMBERSHIP STATUS ENUM
|--------------------------------------------------------------------------
|
|   active       — currently a member in good standing
|   former       — was a member, no longer active
|   founding     — helped establish this faction
|   involuntary  — conscripted, enslaved, forced into membership
|   undercover   — member as cover identity, true loyalty elsewhere
|   suspected    — believed to be a member, not confirmed
|   honorary     — recognized affiliation without active participation
|
|--------------------------------------------------------------------------
| TRUE LOYALTY — THE PUPPET APPARATUS MODEL
|--------------------------------------------------------------------------
|
| During Seraphine's puppet cycles:
|
|   Puppet Leader membership record:
|     faction_entity_id:      United Earth Government (puppet era)
|     member_entity_id:       Puppet Character
|     rank_or_role:           Prime Minister / Chancellor / etc.
|     membership_status:      active
|     public_membership_known: true  (world sees them as legitimate leader)
|     true_loyalty_entity_id: Seraphine
|     loyalty_notes:          "Installed by Seraphine via [mechanism].
|                              Publicly appears as independent leader.
|                              All major decisions routed through
|                              Harry v69 as intermediary."
|
|   Seraphine's own membership record in her apparatus:
|     faction_entity_id:      Morbraith Apparatus — [Cycle N]
|     member_entity_id:       Seraphine
|     rank_or_role:           Shadow Controller
|     membership_status:      active
|     public_membership_known: false
|     true_loyalty_entity_id: null (she IS the loyalty target)
|
|--------------------------------------------------------------------------
| THE UNIQUE CONSTRAINT
|--------------------------------------------------------------------------
|
| One membership record per faction-member pair.
| If a character leaves and rejoins a faction, update the existing record
| rather than creating a duplicate. The era fields and status track
| the full history of that membership.
|
| If genuinely different membership types need to coexist —
| e.g. a character who is simultaneously an active member AND
| an undercover operative in the same faction — use notes
| to capture the complexity rather than duplicate records.
|
|--------------------------------------------------------------------------
| DISTINCTION FROM COLLECTIONS
|--------------------------------------------------------------------------
|
| Collections group entities organizationally.
| Faction memberships record explicit in-world affiliations with
| rank, status, era, loyalty, and recruitment chain.
|
| The Morbraith Syndicate as a collection groups everything related to it.
| Faction memberships record who actually belongs to it and how.
|
*/
