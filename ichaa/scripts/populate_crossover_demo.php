<?php

declare(strict_types=1);

use App\Domain\Connections\Models\FactionMembership;
use App\Domain\Connections\Models\GroupRelationship;
use App\Domain\Connections\Models\GroupRelationshipEntity;
use App\Domain\Connections\Models\Relationship;
use App\Domain\Connections\Services\RelationshipService;
use App\Domain\Identity\Listeners\FlipEntityCompletionFlags;
use App\Domain\Identity\Listeners\UpdateCompletionScore;
use App\Domain\Identity\Models\Entity;
use App\Domain\Identity\Models\EntityAlias;
use App\Domain\Identity\Models\EntityNote;
use App\Domain\Identity\Models\EntityQuestion;
use App\Domain\Identity\Models\MediaReference;
use App\Domain\Identity\Services\EntityService;
use App\Domain\Identity\ValueObjects\ContentClassification;
use App\Domain\Identity\ValueObjects\EntityType;
use App\Domain\Identity\ValueObjects\PowerTier;
use App\Domain\Identity\ValueObjects\SourceUniverse;
use App\Domain\Identity\ValueObjects\VisibilityLevel;
use App\Domain\Intelligence\Models\KnowledgeState;
use App\Domain\Intelligence\Models\PerceptionState;
use App\Domain\Intelligence\Models\Secret;
use App\Domain\Intelligence\Services\IntelligenceService;
use App\Domain\Lore\Models\CanonReferenceEntity;
use App\Domain\Lore\Models\CrossoverEntryPoint;
use App\Domain\Lore\Models\Document;
use App\Domain\Lore\Models\DocumentEntity;
use App\Domain\Lore\Models\SourceCanonReference;
use App\Domain\Organization\Models\Collection;
use App\Domain\Organization\Models\Glossary;
use App\Domain\Organization\Services\CollectionService;
use App\Domain\Production\Models\Meta;
use App\Domain\Production\Models\PipelineItem;
use App\Domain\Production\Models\SessionLog;
use App\Domain\Temporal\Models\CharacterStateTracker;
use App\Domain\Temporal\Models\ConcurrencyGroup;
use App\Domain\Temporal\Models\Timeline;
use App\Domain\Temporal\Models\TimelineEntity;
use App\Domain\Temporal\Services\TemporalService;
use App\Domain\World\Models\GalacticRegion;
use App\Domain\World\Models\LocationContainment;
use App\Domain\World\Models\LocationControlHistory;
use App\Domain\World\Models\PowerInteraction;
use App\Domain\World\Models\PowerInteractionInstance;
use App\Domain\World\Models\TravelRoute;
use App\Domain\World\Services\WorldService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

DB::transaction(function (): void {
    $entityService = app(EntityService::class);
    $relationshipService = app(RelationshipService::class);
    $temporalService = app(TemporalService::class);
    $intelligenceService = app(IntelligenceService::class);
    $worldService = app(WorldService::class);
    $collectionService = app(CollectionService::class);
    $flagFlipper = app(FlipEntityCompletionFlags::class);
    $scoreUpdater = app(UpdateCompletionScore::class);

    $entityData = [
        'Harry Potter' => [
            'public_title' => 'The Boy Who Lived, Rewritten',
            'entity_type' => EntityType::CHARACTER,
            'entity_sub_type' => 'wizard',
            'summary' => rich([
                'Harry is the post-war anchor who keeps Britain from treating the new crossings like a second apocalypse.',
                'He leads by exhaustion, instinct, and a refusal to let frightened institutions decide who counts as human once Roshar starts bleeding into the edges of wizarding space.',
            ]),
            'public_summary' => rich([
                'Auror-adjacent field anchor managing magical fallout after the Mirror Stair crossings begin.',
            ]),
            'source_universes' => [SourceUniverse::HARRY_POTTER],
            'origin_type' => 'canonical',
            'canon_deviation' => 'major',
            'origin_notes' => rich([
                'This version of Harry survived canon but carries a stable death-resonance that lets him perceive thresholds other wizards miss.',
            ]),
            'power_tier_ceiling' => PowerTier::COSMIC,
            'power_tier_operating' => PowerTier::REGIONAL,
            'power_tier_influence' => PowerTier::NATIONAL,
            'status' => 'active',
            'type_status' => 'Core Character',
            'visibility' => VisibilityLevel::PRIVATE,
            'content_classification' => ContentClassification::RESTRICTED,
            'public_persona' => rich([
                'Seen as a tired war hero doing too much triage and not enough politics.',
            ]),
            'true_nature' => rich([
                'Harry is the living pressure seal between death magic and oath-based light, and every major faction is quietly measuring how much longer he can keep carrying that load.',
            ]),
            'persona_divergence' => 'significant',
            'control_state' => 'autonomous',
            'attributes' => [
                'house' => 'Gryffindor',
                'wand' => '11-inch holly, phoenix feather',
                'patronus' => 'stag',
                'current_role' => 'field anchor for crossover containment',
                'core_theme' => 'mercy without softness',
            ],
        ],
        'Hermione Granger' => [
            'public_title' => 'Systems Architect of the Impossible',
            'entity_type' => EntityType::CHARACTER,
            'entity_sub_type' => 'wizard',
            'summary' => rich([
                'Hermione becomes the legal and theoretical spine of the crossover response.',
                'She treats the Rosharan arrival not as an anomaly to fear, but as a governance problem demanding language, precedent, and ruthless clarity.',
            ]),
            'public_summary' => rich([
                'Research lead and policy architect for the Mirror Stair crisis.',
            ]),
            'source_universes' => [SourceUniverse::HARRY_POTTER],
            'origin_type' => 'canonical',
            'canon_deviation' => 'moderate',
            'origin_notes' => rich([
                'Her divergence is mostly positional: she enters the crossings as a builder of systems rather than a reluctant participant.',
            ]),
            'power_tier_ceiling' => PowerTier::REGIONAL,
            'power_tier_operating' => PowerTier::REGIONAL,
            'power_tier_influence' => PowerTier::GLOBAL,
            'status' => 'active',
            'type_status' => 'Research Lead',
            'visibility' => VisibilityLevel::PRIVATE,
            'content_classification' => ContentClassification::RESTRICTED,
            'public_persona' => rich([
                'Brilliant, exacting, and visibly overprepared.',
            ]),
            'true_nature' => rich([
                'Hermione is the first person in Britain to realize the crossings can be regulated without being domesticated, which makes her dangerous to everyone who profits from panic.',
            ]),
            'persona_divergence' => 'surface',
            'control_state' => 'autonomous',
            'attributes' => [
                'focus' => 'translation, law, magical systems',
                'current_role' => 'joint protocol author',
                'signature_strength' => 'pattern recognition under pressure',
            ],
        ],
        'Kaladin Stormblessed' => [
            'public_title' => 'Bridgeleader in Exile',
            'entity_type' => EntityType::CHARACTER,
            'entity_sub_type' => 'windrunner',
            'summary' => rich([
                'Kaladin arrives through an Oathgate failure and immediately starts measuring Britain by how it treats the frightened, the strange, and the disposable.',
                'He becomes the field commander most willing to trust Harry, and the one least willing to forgive the Archive when it hides the cost of containment.',
            ]),
            'public_summary' => rich([
                'Windrunner captain operating on the British side of the crossings.',
            ]),
            'source_universes' => [SourceUniverse::STORMLIGHT, SourceUniverse::COSMERE],
            'origin_type' => 'canonical',
            'canon_deviation' => 'major',
            'origin_notes' => rich([
                'He is displaced after a failed transit during a highstorm-adjacent surge event and spends this arc learning how non-Rosharan institutions weaponize secrecy.',
            ]),
            'power_tier_ceiling' => PowerTier::COSMIC,
            'power_tier_operating' => PowerTier::CONTINENTAL,
            'power_tier_influence' => PowerTier::NATIONAL,
            'status' => 'active',
            'type_status' => 'Core Character',
            'visibility' => VisibilityLevel::PRIVATE,
            'content_classification' => ContentClassification::RESTRICTED,
            'public_persona' => rich([
                'A disciplined soldier with obvious moral authority and almost no patience for bureaucratic theater.',
            ]),
            'true_nature' => rich([
                'Kaladin acts like a displaced commander, but functionally he becomes the conscience test for the entire crossover project: if a policy feels monstrous to him, it is usually because it is.',
            ]),
            'persona_divergence' => 'significant',
            'control_state' => 'autonomous',
            'attributes' => [
                'order' => 'Windrunner',
                'spren_bond' => 'Sylphrena',
                'current_role' => 'expeditionary defense lead',
                'core_theme' => 'protection under impossible terms',
            ],
        ],
        'Shallan Davar' => [
            'public_title' => 'Cartographer of False Faces',
            'entity_type' => EntityType::CHARACTER,
            'entity_sub_type' => 'lightweaver',
            'summary' => rich([
                'Shallan turns the British side of the breach into a map of masks, official narratives, and useful lies.',
                'She and Hermione respect each other instantly and trust each other slowly.',
            ]),
            'public_summary' => rich([
                'Lightweaver researcher tracing how identity fractures across the crossings.',
            ]),
            'source_universes' => [SourceUniverse::STORMLIGHT, SourceUniverse::COSMERE],
            'origin_type' => 'canonical',
            'canon_deviation' => 'moderate',
            'origin_notes' => rich([
                'Her divergence intensifies around institutional deception rather than court politics.',
            ]),
            'power_tier_ceiling' => PowerTier::COSMIC,
            'power_tier_operating' => PowerTier::REGIONAL,
            'power_tier_influence' => PowerTier::FACTIONAL,
            'status' => 'active',
            'type_status' => 'Perspective Character',
            'visibility' => VisibilityLevel::PRIVATE,
            'content_classification' => ContentClassification::RESTRICTED,
            'public_persona' => rich([
                'Charming, artistic, slightly unserious, and easy to underestimate on purpose.',
            ]),
            'true_nature' => rich([
                'Shallan is one of the few people who can tell when the Mirror Stair is lying, because she already knows what it sounds like when a self edits itself to survive.',
            ]),
            'persona_divergence' => 'complete',
            'control_state' => 'autonomous',
            'attributes' => [
                'order' => 'Lightweaver',
                'signature_tools' => ['sketchbook', 'veil-work', 'patterning illusions'],
                'current_role' => 'identity fracture researcher',
            ],
        ],
        'Dalinar Kholin' => [
            'public_title' => 'Strategist of Oaths and Burdens',
            'entity_type' => EntityType::HISTORICAL_FIGURE,
            'entity_sub_type' => 'bondsmith',
            'summary' => rich([
                'Dalinar remains primarily offstage but his decisions define the Rosharan side of the expedition.',
                'He treats the British response as both a diplomatic opening and a moral stress test.',
            ]),
            'public_summary' => rich([
                'Bondsmith sponsor of the Urithiru expeditionary cell.',
            ]),
            'source_universes' => [SourceUniverse::STORMLIGHT, SourceUniverse::COSMERE],
            'origin_type' => 'canonical',
            'canon_deviation' => 'surface',
            'origin_notes' => rich([
                'He stays close to canon temperament while being forced to think in interworld rather than interstate terms.',
            ]),
            'power_tier_ceiling' => PowerTier::COSMIC,
            'power_tier_operating' => PowerTier::CONTINENTAL,
            'power_tier_influence' => PowerTier::CIVILIZATIONAL,
            'status' => 'active',
            'type_status' => 'Sponsor Figure',
            'visibility' => VisibilityLevel::PRIVATE,
            'content_classification' => ContentClassification::RESTRICTED,
            'public_persona' => rich([
                'A hard man trying to become the sort of leader who deserves trust.',
            ]),
            'true_nature' => rich([
                'Dalinar knows the crossings could become an empire-making instrument and is quietly terrified by how many plausible people would use them that way.',
            ]),
            'persona_divergence' => 'surface',
            'control_state' => 'autonomous',
            'attributes' => [
                'order' => 'Bondsmith',
                'current_role' => 'distant strategic authority',
            ],
        ],
        'Seraphine Vale' => [
            'public_title' => 'Archivist of the Grey Line',
            'entity_type' => EntityType::CHARACTER,
            'entity_sub_type' => 'threshold operative',
            'summary' => rich([
                'Seraphine is the original character holding the whole crossover rig together: archivist, liar, triage engineer, and the person most likely to decide a secret is kinder than the truth.',
                'She built the Grey Line Archive to catalog crossings, then turned it into a covert emergency state when the crossings escalated.',
            ]),
            'public_summary' => rich([
                'Private archivist coordinating containment around the Mirror Stair.',
            ]),
            'source_universes' => [SourceUniverse::ORIGINAL],
            'origin_type' => 'native',
            'canon_deviation' => 'none',
            'origin_notes' => rich([
                'Seraphine is fully original and exists to bridge the ethical language of both canons without belonging cleanly to either.',
            ]),
            'power_tier_ceiling' => PowerTier::REGIONAL,
            'power_tier_operating' => PowerTier::LOCAL,
            'power_tier_influence' => PowerTier::FACTIONAL,
            'status' => 'active',
            'type_status' => 'Original Anchor',
            'visibility' => VisibilityLevel::PRIVATE,
            'content_classification' => ContentClassification::SECRET,
            'public_persona' => rich([
                'A patient researcher with immaculate notes and suspiciously good timing.',
            ]),
            'true_nature' => rich([
                'Seraphine is not just an archivist. She is a living threshold stabilizer whose body was altered by a failed early crossing, and the Archive survives because she quietly lets it feed on her endurance instead of on civilians.',
            ]),
            'persona_divergence' => 'complete',
            'control_state' => 'autonomous',
            'attributes' => [
                'current_role' => 'archive director and threshold stabilizer',
                'specialty' => 'containment logic, field recovery, emotional compartmentalization',
                'anchor_condition' => 'mirror-burn scarring across sternum and hands',
            ],
        ],
        'Grey Line Archive' => [
            'public_title' => 'Archive and Containment Network',
            'entity_type' => EntityType::ORGANIZATION,
            'entity_sub_type' => 'secret archive',
            'summary' => rich([
                'The Grey Line Archive looks like a private research house. In practice it is the emergency bureaucracy that grew around the first known stable crossover points.',
                'Its culture rewards discretion, competence, and a disturbing willingness to decide who gets told the truth.',
            ]),
            'public_summary' => rich([
                'Discreet archive managing records and containment around interworld thresholds.',
            ]),
            'source_universes' => [SourceUniverse::ORIGINAL],
            'origin_type' => 'native',
            'canon_deviation' => 'none',
            'origin_notes' => rich([
                'Built as a native institution so the setting has an existing body capable of responding to crossings before either canon fully understands them.',
            ]),
            'status' => 'active',
            'type_status' => 'Original Faction',
            'visibility' => VisibilityLevel::PRIVATE,
            'content_classification' => ContentClassification::SECRET,
            'public_persona' => rich([
                'A specialty archive with odd donor money and aggressive confidentiality.',
            ]),
            'true_nature' => rich([
                'The Archive is a shadow state for threshold management, and its kindest people are also the ones most practiced at redaction.',
            ]),
            'persona_divergence' => 'complete',
            'control_state' => 'puppet',
            'attributes' => [
                'founded_for' => 'threshold cataloging',
                'real_function' => 'containment, triage, black-file diplomacy',
                'operational_cells' => ['field recovery', 'redaction', 'threshold maintenance'],
            ],
        ],
        'Order Remnant' => [
            'public_title' => 'Post-War British Response Cell',
            'entity_type' => EntityType::ORGANIZATION,
            'entity_sub_type' => 'postwar network',
            'summary' => rich([
                'The Order Remnant is what happens when veterans of one war refuse to pretend the next crisis will politely announce itself.',
            ]),
            'public_summary' => rich([
                'Loose post-war network mobilized around cross-world containment.',
            ]),
            'source_universes' => [SourceUniverse::HARRY_POTTER],
            'origin_type' => 'canonical',
            'canon_deviation' => 'major',
            'origin_notes' => rich([
                'This is not the original Order intact. It is the afterimage that stayed functional because too many of its survivors never learned how to stand down.',
            ]),
            'status' => 'active',
            'type_status' => 'Field Faction',
            'visibility' => VisibilityLevel::PRIVATE,
            'content_classification' => ContentClassification::RESTRICTED,
            'public_persona' => rich([
                'Mutual aid, old loyalties, and unofficial logistics.',
            ]),
            'true_nature' => rich([
                'The Remnant is the only British faction with enough trust capital to work with Rosharans without immediately reducing them to assets.',
            ]),
            'persona_divergence' => 'surface',
            'control_state' => 'autonomous',
            'attributes' => [
                'strengths' => ['local trust', 'field improvisation', 'survivor solidarity'],
                'weaknesses' => ['exhaustion', 'limited legitimacy', 'scar tissue from the war'],
            ],
        ],
        'Knights Radiant Expeditionary Cell' => [
            'public_title' => 'Urithiru Forward Team',
            'entity_type' => EntityType::ORGANIZATION,
            'entity_sub_type' => 'expeditionary cell',
            'summary' => rich([
                'A small Radiant task force tasked with learning whether the British-side threshold is an accident, a weapon, or the beginning of a permanent corridor.',
            ]),
            'public_summary' => rich([
                'Radiant-led expedition operating from Urithiru into Britain.',
            ]),
            'source_universes' => [SourceUniverse::STORMLIGHT, SourceUniverse::COSMERE],
            'origin_type' => 'canonical',
            'canon_deviation' => 'major',
            'origin_notes' => rich([
                'This cell is purpose-built for the AU rather than lifted whole cloth from canon command structures.',
            ]),
            'status' => 'active',
            'type_status' => 'Field Faction',
            'visibility' => VisibilityLevel::PRIVATE,
            'content_classification' => ContentClassification::RESTRICTED,
            'public_persona' => rich([
                'A formal diplomatic and recovery team.',
            ]),
            'true_nature' => rich([
                'The cell is also a listening post for catastrophic edge cases in power interaction, which makes half its mission scientific and the other half quietly existential.',
            ]),
            'persona_divergence' => 'significant',
            'control_state' => 'autonomous',
            'attributes' => [
                'primary_orders' => ['Windrunner', 'Lightweaver', 'Bondsmith support'],
                'mission' => 'recover displaced Rosharans and map the threshold rules',
            ],
        ],
        'Wizarding Britain' => [
            'public_title' => 'Primary British Magical Territory',
            'entity_type' => EntityType::LOCATION,
            'entity_sub_type' => 'country',
            'summary' => rich([
                'Wizarding Britain is the main political and magical container for the British side of the story.',
            ]),
            'public_summary' => rich([
                'Primary British magical territory affected by threshold bleed.',
            ]),
            'source_universes' => [SourceUniverse::HARRY_POTTER],
            'origin_type' => 'canonical',
            'canon_deviation' => 'surface',
            'origin_notes' => rich([
                'The divergence is infrastructural: the territory has to function under the strain of an interworld border.',
            ]),
            'status' => 'active',
            'type_status' => 'Anchor Territory',
            'visibility' => VisibilityLevel::PRIVATE,
            'content_classification' => ContentClassification::RESTRICTED,
            'public_persona' => rich([
                'A familiar magical nation under manageable strain.',
            ]),
            'true_nature' => rich([
                'The territory is already reconfiguring around hidden transit law, denial, and the fear that another war may arrive wearing a different cosmology.',
            ]),
            'persona_divergence' => 'surface',
            'space_type' => 'territory',
            'coordinates' => ['world' => 'Earth', 'region' => 'Britain'],
            'attributes' => [
                'anchor_status' => 'primary human-side jurisdiction',
                'notable_pressures' => ['ministry denial', 'archive secrecy', 'cross-world refugee triage'],
            ],
        ],
        'Hogwarts Castle' => [
            'public_title' => 'School, Fortress, Threshold Marker',
            'entity_type' => EntityType::LOCATION,
            'entity_sub_type' => 'castle',
            'summary' => rich([
                'Hogwarts becomes one of the safest and most symbolically fraught places in the setting once the crossings start.',
                'Its wards do not reject Rosharan Investiture outright; they reinterpret it, which is more dangerous and more useful.',
            ]),
            'public_summary' => rich([
                'Hogwarts repurposed as a warded refuge and negotiations ground.',
            ]),
            'source_universes' => [SourceUniverse::HARRY_POTTER],
            'origin_type' => 'canonical',
            'canon_deviation' => 'major',
            'origin_notes' => rich([
                'The AU leans hard into Hogwarts as a living magical system rather than a static backdrop.',
            ]),
            'status' => 'active',
            'type_status' => 'Anchor Location',
            'visibility' => VisibilityLevel::PRIVATE,
            'content_classification' => ContentClassification::RESTRICTED,
            'public_persona' => rich([
                'A school recovering from war and opening itself carefully to survivors.',
            ]),
            'true_nature' => rich([
                'The castle is acting like an intelligence at the edge of self-awareness, selectively cooperating with the threshold response because it recognizes the shape of siege conditions.',
            ]),
            'persona_divergence' => 'significant',
            'space_type' => 'fortress_school',
            'coordinates' => ['world' => 'Earth', 'region' => 'Scottish Highlands'],
            'attributes' => [
                'ward_behavior' => 'adaptive resonance with oath-bound light',
                'current_function' => 'refuge, triage, diplomacy',
            ],
        ],
        'Forbidden Forest' => [
            'public_title' => 'Residual Wild Zone',
            'entity_type' => EntityType::LOCATION,
            'entity_sub_type' => 'forest',
            'summary' => rich([
                'The Forest becomes a pressure sink where magical ecosystems absorb some of the threshold spill at a cost no one fully understands.',
            ]),
            'public_summary' => rich([
                'Wild zone absorbing some threshold instability near Hogwarts.',
            ]),
            'source_universes' => [SourceUniverse::HARRY_POTTER],
            'origin_type' => 'canonical',
            'canon_deviation' => 'moderate',
            'origin_notes' => rich([
                'It shifts from ominous backdrop to ecological buffer.',
            ]),
            'status' => 'active',
            'type_status' => 'Pressure Zone',
            'visibility' => VisibilityLevel::PRIVATE,
            'content_classification' => ContentClassification::RESTRICTED,
            'public_persona' => rich([
                'Dangerous but familiar magical woodland.',
            ]),
            'true_nature' => rich([
                'The Forest is metabolizing excess resonance and quietly mutating its own rules to survive.',
            ]),
            'persona_divergence' => 'significant',
            'space_type' => 'wild_zone',
            'coordinates' => ['world' => 'Earth', 'region' => 'Hogwarts perimeter'],
            'attributes' => [
                'function' => 'ecological pressure sink',
                'hazards' => ['resonance blooms', 'distorted time pockets', 'territorial creatures'],
            ],
        ],
        'Roshar' => [
            'public_title' => 'Primary Rosharan Theater',
            'entity_type' => EntityType::REALM,
            'entity_sub_type' => 'planetary_civilization',
            'summary' => rich([
                'Roshar remains the source-side pressure engine of the crossover: violent, oath-driven, and too strategically important to lose sight of.',
            ]),
            'public_summary' => rich([
                'Source-side Rosharan theater connected to the British threshold.',
            ]),
            'source_universes' => [SourceUniverse::STORMLIGHT, SourceUniverse::COSMERE],
            'origin_type' => 'canonical',
            'canon_deviation' => 'surface',
            'origin_notes' => rich([
                'Roshar is mostly treated as canon-accurate while the interworld consequences diverge outward.',
            ]),
            'status' => 'active',
            'type_status' => 'Source Realm',
            'visibility' => VisibilityLevel::PRIVATE,
            'content_classification' => ContentClassification::RESTRICTED,
            'public_persona' => rich([
                'Distant origin world of the displaced expedition.',
            ]),
            'true_nature' => rich([
                'Every British policy question becomes sharper when Roshar is remembered as a place still at war, still sacred, and still capable of sending far worse things through the breach.',
            ]),
            'persona_divergence' => 'surface',
            'space_type' => 'planetary_realm',
            'coordinates' => ['system' => 'Rosharan system', 'world' => 'Roshar'],
            'attributes' => [
                'pressure_source' => 'storm, oath, war',
                'importance' => 'source-side strategic baseline',
            ],
        ],
        'Urithiru' => [
            'public_title' => 'Vertical Stronghold of the Cell',
            'entity_type' => EntityType::LOCATION,
            'entity_sub_type' => 'tower_city',
            'summary' => rich([
                'Urithiru is the Rosharan command and recovery node tied to the crossover response.',
                'Its scholars and Radiants treat the British threshold like a battlefield, a theorem, and a temptation all at once.',
            ]),
            'public_summary' => rich([
                'Rosharan command city coordinating the expeditionary response.',
            ]),
            'source_universes' => [SourceUniverse::STORMLIGHT, SourceUniverse::COSMERE],
            'origin_type' => 'canonical',
            'canon_deviation' => 'surface',
            'origin_notes' => rich([
                'Urithiru keeps its canon identity while assuming a new interworld command function.',
            ]),
            'status' => 'active',
            'type_status' => 'Anchor Location',
            'visibility' => VisibilityLevel::PRIVATE,
            'content_classification' => ContentClassification::RESTRICTED,
            'public_persona' => rich([
                'A disciplined stronghold of recovery and command.',
            ]),
            'true_nature' => rich([
                'Urithiru is also a measurement instrument. The way its systems distort around the crossings is the clearest warning anyone has that the breach may grow teeth.',
            ]),
            'persona_divergence' => 'surface',
            'space_type' => 'tower_city',
            'coordinates' => ['world' => 'Roshar', 'region' => 'central peaks'],
            'attributes' => [
                'current_function' => 'command, recovery, transit research',
                'notable_assets' => ['scholars', 'Radiants', 'Oathgate adjacency'],
            ],
        ],
        'The Mirror Stair' => [
            'public_title' => 'Threshold Architecture',
            'entity_type' => EntityType::CONVERGENCE_POINT,
            'entity_sub_type' => 'cross-world breach',
            'summary' => rich([
                'The Mirror Stair is the primary crossover threshold: a place that behaves like architecture, grief, and law all at once.',
                'It does not simply connect worlds. It edits how crossing is remembered.',
            ]),
            'public_summary' => rich([
                'Primary threshold connecting the British side to Rosharan spill corridors.',
            ]),
            'source_universes' => [SourceUniverse::ORIGINAL, SourceUniverse::HARRY_POTTER, SourceUniverse::STORMLIGHT],
            'origin_type' => 'hybrid',
            'canon_deviation' => 'complete',
            'origin_notes' => rich([
                'This is the AU’s core invented crossover mechanism.',
            ]),
            'status' => 'active',
            'type_status' => 'Original Threshold',
            'visibility' => VisibilityLevel::PRIVATE,
            'content_classification' => ContentClassification::SECRET,
            'public_persona' => rich([
                'A sealed archive annex with unstable reflective wards.',
            ]),
            'true_nature' => rich([
                'The Mirror Stair is a convergence organism wearing the shape of a staircase. It selects for grief, oath-pressure, and unresolved death resonance when deciding what may pass through it.',
            ]),
            'persona_divergence' => 'complete',
            'space_type' => 'threshold_site',
            'coordinates' => ['world' => 'Earth', 'region' => 'below the Grey Line Archive'],
            'attributes' => [
                'trigger_inputs' => ['oath-pressure', 'death resonance', 'light saturation'],
                'current_condition' => 'stable but hungry',
                'known_cost' => 'memory abrasion on frequent operators',
            ],
        ],
        'British Wandcraft' => [
            'public_title' => 'British Wand-Bound Spellwork',
            'entity_type' => EntityType::MAGIC_SYSTEM,
            'entity_sub_type' => 'wandcraft',
            'summary' => rich([
                'The local magical system of incantation, will, focus, and inherited infrastructure.',
            ]),
            'public_summary' => rich([
                'British wand-based spellwork under crossover stress.',
            ]),
            'source_universes' => [SourceUniverse::HARRY_POTTER],
            'origin_type' => 'canonical',
            'canon_deviation' => 'moderate',
            'status' => 'active',
            'type_status' => 'System Anchor',
            'visibility' => VisibilityLevel::PRIVATE,
            'content_classification' => ContentClassification::RESTRICTED,
            'public_persona' => rich(['Flexible, precise, incantation-guided magic.']),
            'true_nature' => rich([
                'Under threshold pressure, wandcraft behaves less like a closed local system and more like a legal language trying to interpret foreign metaphysics.',
            ]),
            'persona_divergence' => 'surface',
            'attributes' => [
                'core_modes' => ['spellcasting', 'warding', 'ritual inheritance'],
                'crossing_response' => 'interpretive resonance with Stormlight',
            ],
        ],
        'Surgebinding' => [
            'public_title' => 'Rosharan Oath-Bound Investiture',
            'entity_type' => EntityType::POWER_SYSTEM,
            'entity_sub_type' => 'investiture',
            'summary' => rich([
                'Rosharan power expressed through oaths, spren bonds, and light-fueled transformation.',
            ]),
            'public_summary' => rich([
                'Rosharan Investiture system now interacting with wandcraft.',
            ]),
            'source_universes' => [SourceUniverse::STORMLIGHT, SourceUniverse::COSMERE],
            'origin_type' => 'canonical',
            'canon_deviation' => 'surface',
            'status' => 'active',
            'type_status' => 'System Anchor',
            'visibility' => VisibilityLevel::PRIVATE,
            'content_classification' => ContentClassification::RESTRICTED,
            'public_persona' => rich(['Power governed by oath, intent, and light saturation.']),
            'true_nature' => rich([
                'When translated through the Mirror Stair, Surgebinding acts like a truth-seeking solvent. It stresses every false administrative category built to hold it.',
            ]),
            'persona_divergence' => 'surface',
            'attributes' => [
                'orders_present' => ['Windrunner', 'Lightweaver', 'Bondsmith support'],
                'crossing_response' => 'amplified by threshold grief loads',
            ],
        ],
        'Convergence Timeline' => [
            'public_title' => 'Primary AU Event Spine',
            'entity_type' => EntityType::TIMELINE,
            'entity_sub_type' => 'master_timeline',
            'summary' => rich([
                'The master timeline that tracks crossover chronology on the AU side.',
            ]),
            'public_summary' => rich(['Primary AU event spine.']),
            'source_universes' => [SourceUniverse::ORIGINAL, SourceUniverse::HARRY_POTTER, SourceUniverse::STORMLIGHT],
            'origin_type' => 'hybrid',
            'canon_deviation' => 'complete',
            'status' => 'active',
            'type_status' => 'Timeline',
            'visibility' => VisibilityLevel::PRIVATE,
            'content_classification' => ContentClassification::RESTRICTED,
            'public_persona' => rich(['Operational chronology for the crossover project.']),
            'true_nature' => rich([
                'This timeline is less a list than a declaration of causality, and half the battle is deciding what counts as the same event across worlds.',
            ]),
            'persona_divergence' => 'surface',
            'attributes' => ['scope' => 'master'],
        ],
        'Harry Potter Original Timeline' => [
            'public_title' => 'Canonical British Baseline',
            'entity_type' => EntityType::TIMELINE,
            'entity_sub_type' => 'source_timeline',
            'summary' => rich([
                'Baseline chronology for the British-side source material.',
            ]),
            'public_summary' => rich(['British source timeline baseline.']),
            'source_universes' => [SourceUniverse::HARRY_POTTER],
            'origin_type' => 'canonical',
            'canon_deviation' => 'none',
            'status' => 'active',
            'type_status' => 'Source Timeline',
            'visibility' => VisibilityLevel::PRIVATE,
            'content_classification' => ContentClassification::RESTRICTED,
            'public_persona' => rich(['Baseline source chronology.']),
            'true_nature' => rich(['Used as the control column whenever the AU starts lying to itself.']),
            'persona_divergence' => 'none',
            'attributes' => ['scope' => 'harry_potter_control'],
        ],
        'Roshar Refugee Timeline' => [
            'public_title' => 'Displacement and Transit Chronology',
            'entity_type' => EntityType::TIMELINE,
            'entity_sub_type' => 'source_timeline',
            'summary' => rich([
                'Tracks the Rosharan side of displacement, transit, and adaptation after the Oathgate fracture.',
            ]),
            'public_summary' => rich(['Rosharan displacement timeline.']),
            'source_universes' => [SourceUniverse::STORMLIGHT, SourceUniverse::COSMERE],
            'origin_type' => 'canonical',
            'canon_deviation' => 'major',
            'status' => 'active',
            'type_status' => 'Source Timeline',
            'visibility' => VisibilityLevel::PRIVATE,
            'content_classification' => ContentClassification::RESTRICTED,
            'public_persona' => rich(['Tracks Rosharan arrivals and related fallout.']),
            'true_nature' => rich(['Without this timeline, British observers mistake trauma logistics for military intent.']),
            'persona_divergence' => 'surface',
            'attributes' => ['scope' => 'rosharan_displacement'],
        ],
        'Post-War Britain' => [
            'public_title' => 'Recovery Era',
            'entity_type' => EntityType::ERA,
            'entity_sub_type' => 'recovery_era',
            'summary' => rich(['The British recovery era after Voldemort and before the crossings become public history.']),
            'public_summary' => rich(['British recovery era.']),
            'source_universes' => [SourceUniverse::HARRY_POTTER],
            'origin_type' => 'canonical',
            'canon_deviation' => 'surface',
            'status' => 'active',
            'type_status' => 'Era',
            'visibility' => VisibilityLevel::PRIVATE,
            'content_classification' => ContentClassification::RESTRICTED,
            'public_persona' => rich(['An uneasy peace period.']),
            'true_nature' => rich(['The era never really ended; it simply found a more elaborate emergency.']),
            'persona_divergence' => 'surface',
            'attributes' => ['date_range' => '1998-2005'],
        ],
        'First Convergence Season' => [
            'public_title' => 'Initial Threshold Era',
            'entity_type' => EntityType::ERA,
            'entity_sub_type' => 'threshold_era',
            'summary' => rich(['The first season in which the Mirror Stair produces repeatable interworld crossings.']),
            'public_summary' => rich(['Initial threshold era.']),
            'source_universes' => [SourceUniverse::ORIGINAL, SourceUniverse::HARRY_POTTER, SourceUniverse::STORMLIGHT],
            'origin_type' => 'hybrid',
            'canon_deviation' => 'complete',
            'status' => 'active',
            'type_status' => 'Era',
            'visibility' => VisibilityLevel::PRIVATE,
            'content_classification' => ContentClassification::RESTRICTED,
            'public_persona' => rich(['The era of first stable crossings.']),
            'true_nature' => rich(['The first convergence season is also the period in which every faction starts deciding what sort of world they want this to become.']),
            'persona_divergence' => 'surface',
            'attributes' => ['date_range' => '2003-2004'],
        ],
        'Battle of Hogwarts Echo' => [
            'public_title' => 'Residual Battle Harmonics',
            'entity_type' => EntityType::CONFLICT,
            'entity_sub_type' => 'echo_event',
            'summary' => rich([
                'Not the battle itself, but the way its magical residue becomes newly relevant once the threshold starts responding to death-marked survivors.',
            ]),
            'public_summary' => rich(['Residual battle harmonics tied to Harry and the threshold.']),
            'source_universes' => [SourceUniverse::HARRY_POTTER],
            'origin_type' => 'canonical',
            'canon_deviation' => 'major',
            'status' => 'active',
            'type_status' => 'Foundational Event',
            'visibility' => VisibilityLevel::PRIVATE,
            'content_classification' => ContentClassification::RESTRICTED,
            'public_persona' => rich(['Historical magical residue.']),
            'true_nature' => rich(['The battle leaves behind a resonance signature the Mirror Stair later uses as one of its key permissions.']),
            'persona_divergence' => 'complete',
            'attributes' => ['event_role' => 'threshold precursor'],
        ],
        'Oathgate Fracture Over Urithiru' => [
            'public_title' => 'Transit Failure',
            'entity_type' => EntityType::PHENOMENON,
            'entity_sub_type' => 'transit_fracture',
            'summary' => rich([
                'The surge event that displaces the first Rosharan expeditionary survivors into the crossover corridor.',
            ]),
            'public_summary' => rich(['Oathgate transit failure that starts the Rosharan displacement arc.']),
            'source_universes' => [SourceUniverse::STORMLIGHT, SourceUniverse::COSMERE],
            'origin_type' => 'canonical',
            'canon_deviation' => 'major',
            'status' => 'active',
            'type_status' => 'Foundational Event',
            'visibility' => VisibilityLevel::PRIVATE,
            'content_classification' => ContentClassification::RESTRICTED,
            'public_persona' => rich(['A severe but analyzable transit failure.']),
            'true_nature' => rich(['The fracture was partly facilitated by an answering structure on the British side, which means it was not purely an accident.']),
            'persona_divergence' => 'significant',
            'attributes' => ['event_role' => 'rosharan displacement origin'],
        ],
        'The Weeping Crossing' => [
            'public_title' => 'First Stable Human Passage',
            'entity_type' => EntityType::EVENT,
            'entity_sub_type' => 'crossing_event',
            'summary' => rich([
                'The first intentionally managed passage through the Mirror Stair, named for the condensation that formed like tears on every reflective surface in the chamber.',
            ]),
            'public_summary' => rich(['First stable human passage through the Mirror Stair.']),
            'source_universes' => [SourceUniverse::ORIGINAL, SourceUniverse::HARRY_POTTER, SourceUniverse::STORMLIGHT],
            'origin_type' => 'hybrid',
            'canon_deviation' => 'complete',
            'status' => 'active',
            'type_status' => 'Pivotal Event',
            'visibility' => VisibilityLevel::PRIVATE,
            'content_classification' => ContentClassification::RESTRICTED,
            'public_persona' => rich(['A controlled breakthrough in threshold travel.']),
            'true_nature' => rich(['It was only stable because Harry, Kaladin, and Seraphine together unknowingly formed the exact emotional and magical equation the threshold wanted.']),
            'persona_divergence' => 'complete',
            'attributes' => ['event_role' => 'proof of concept'],
        ],
        'Archive Break-In at the Mirror Stair' => [
            'public_title' => 'Containment Breach',
            'entity_type' => EntityType::EVENT,
            'entity_sub_type' => 'breach_event',
            'summary' => rich([
                'An internal breach at the Archive that exposes how many secrets were being held under the rhetoric of necessary containment.',
            ]),
            'public_summary' => rich(['Internal breach exposing Archive secrecy.']),
            'source_universes' => [SourceUniverse::ORIGINAL],
            'origin_type' => 'native',
            'canon_deviation' => 'none',
            'status' => 'active',
            'type_status' => 'Major Event',
            'visibility' => VisibilityLevel::PRIVATE,
            'content_classification' => ContentClassification::SECRET,
            'public_persona' => rich(['A failed intrusion and documentation loss.']),
            'true_nature' => rich(['The break-in is an inside job born from moral panic inside the Archive itself.']),
            'persona_divergence' => 'complete',
            'attributes' => ['event_role' => 'trust fracture'],
        ],
        'The Grey Line' => [
            'public_title' => 'Threshold Theory',
            'entity_type' => EntityType::CONCEPT,
            'entity_sub_type' => 'threshold_concept',
            'summary' => rich([
                'The Grey Line is the Archive term for the liminal pressure band where worlds can briefly negotiate contact.',
            ]),
            'public_summary' => rich(['Archive theory of the pressure band between worlds.']),
            'source_universes' => [SourceUniverse::ORIGINAL],
            'origin_type' => 'native',
            'canon_deviation' => 'none',
            'status' => 'active',
            'type_status' => 'Core Concept',
            'visibility' => VisibilityLevel::PRIVATE,
            'content_classification' => ContentClassification::RESTRICTED,
            'public_persona' => rich(['Technical language for interworld threshold pressure.']),
            'true_nature' => rich(['The term is also ideological cover. Calling it a line makes it sound manageable when it is really a negotiation with appetite.']),
            'persona_divergence' => 'significant',
            'attributes' => ['core_function' => 'threshold theory framing'],
        ],
    ];

    $entities = [];

    foreach ($entityData as $name => $data) {
        $existing = Entity::query()->where('name', $name)->first();
        $payload = filterColumns('entities', array_merge($data, ['name' => $name]));

        if ($existing) {
            $entity = $entityService->update($existing, $payload);
        } else {
            $entity = $entityService->create($payload);
        }

        $entities[$name] = $entity;
    }

    $upsertAlias = static function (Entity $entity, string $alias, array $data = []): EntityAlias {
        return EntityAlias::query()->updateOrCreate(
            [
                'entity_id' => $entity->id,
                'alias' => $alias,
            ],
            array_merge([
                'alias_type' => 'common',
                'context' => null,
                'era_start' => null,
                'era_end' => null,
                'is_active' => true,
                'known_by_entity_ids' => [],
                'visibility' => VisibilityLevel::PRIVATE,
                'content_classification' => ContentClassification::RESTRICTED,
            ], $data)
        );
    };

    $upsertNote = static function (Entity $entity, string $label, string $content, int $sortOrder): EntityNote {
        return EntityNote::query()->updateOrCreate(
            [
                'entity_id' => $entity->id,
                'note_label' => $label,
            ],
            [
                'content' => $content,
                'sort_order' => $sortOrder,
            ]
        );
    };

    $upsertQuestion = static function (Entity $entity, string $question, array $data = []): EntityQuestion {
        return EntityQuestion::query()->updateOrCreate(
            [
                'entity_id' => $entity->id,
                'question' => $question,
            ],
            array_merge([
                'context' => null,
                'status' => 'open',
                'resolution' => null,
                'resolved_at' => null,
                'priority' => 'medium',
                'linked_entity_ids' => [],
                'linked_group_relationship_ids' => [],
                'source_session_log_id' => null,
                'sort_order' => 0,
            ], $data)
        );
    };

    $upsertAlias($entities['Harry Potter'], 'The Boy Who Lived', ['alias_type' => 'public_title']);
    $upsertAlias($entities['Harry Potter'], 'Master of Death', ['alias_type' => 'hidden_title', 'known_by_entity_ids' => [$entities['Hermione Granger']->id, $entities['Seraphine Vale']->id]]);
    $upsertAlias($entities['Kaladin Stormblessed'], 'Bridgeleader', ['alias_type' => 'public_title']);
    $upsertAlias($entities['Kaladin Stormblessed'], 'Stormblessed', ['alias_type' => 'honorific']);
    $upsertAlias($entities['Shallan Davar'], 'Veil', ['alias_type' => 'persona']);
    $upsertAlias($entities['Seraphine Vale'], 'Grey Line Dossier Subject Zero', ['alias_type' => 'classified', 'known_by_entity_ids' => [$entities['Harry Potter']->id, $entities['Hermione Granger']->id]]);
    $upsertAlias($entities['Grey Line Archive'], 'The Archive', ['alias_type' => 'common']);
    $upsertAlias($entities['The Mirror Stair'], 'Threshold Twelve', ['alias_type' => 'classified', 'known_by_entity_ids' => [$entities['Grey Line Archive']->id]]);
    $upsertAlias($entities['Hogwarts Castle'], 'The Castle', ['alias_type' => 'common']);

    $upsertNote($entities['Harry Potter'], 'Operational Reality', 'Harry functions best when he has one concrete person to protect. Large abstract mandates make him harsher, not calmer.', 10);
    $upsertNote($entities['Kaladin Stormblessed'], 'Field Read', 'Kaladin notices institutional cowardice almost instantly and reacts badly when it is dressed up as prudence.', 10);
    $upsertNote($entities['Seraphine Vale'], 'Containment Cost', 'Seraphine underreports her threshold fatigue because she believes any admission of weakness will trigger a takeover by people with worse ethics.', 10);
    $upsertNote($entities['The Mirror Stair'], 'Threshold Behavior', 'The Stair reacts faster to grief borne in silence than to openly expressed sorrow. That difference matters operationally.', 10);

    $upsertQuestion($entities['Hermione Granger'], 'How much legal personhood should displaced Rosharan spren receive inside British magical law?', [
        'context' => 'Needed before any public-facing treaty language can hold together.',
        'priority' => 'blocking',
        'sort_order' => 10,
    ]);
    $upsertQuestion($entities['Grey Line Archive'], 'What happens if the Archive loses Seraphine as its stabilizer before a replacement threshold method exists?', [
        'context' => 'No credible redundancy plan is on record.',
        'priority' => 'blocking',
        'sort_order' => 20,
    ]);
    $upsertQuestion($entities['Urithiru'], 'Can Urithiru absorb long-term threshold instability without altering how the tower interfaces with Investiture?', [
        'context' => 'Research is strong but still provisional.',
        'priority' => 'high',
        'sort_order' => 30,
    ]);
    $upsertQuestion($entities['Shallan Davar'], 'At what point does strategic masking become identity damage instead of tactical resilience?', [
        'context' => 'This is a craft and character question, not just an in-world one.',
        'priority' => 'medium',
        'sort_order' => 40,
    ]);

    $upsertRelationship = static function (
        RelationshipService $service,
        Entity $from,
        Entity $to,
        array $data
    ): Relationship {
        $existing = Relationship::query()
            ->where('from_entity_id', $from->id)
            ->where('to_entity_id', $to->id)
            ->first();

        return $existing
            ? $service->update($existing, $data)
            : $service->create($from, $to, $data);
    };

    $harryHermione = $upsertRelationship($relationshipService, $entities['Harry Potter'], $entities['Hermione Granger'], [
        'relationship_type' => 'allies',
        'direction' => 'mutual_equal',
        'perspective_a' => rich(['Harry trusts Hermione to build the system he cannot think his way into.']),
        'perspective_b' => rich(['Hermione trusts Harry to act when systems fail.']),
        'current_tension_charge' => 'positive',
        'strength_from_a' => 9,
        'strength_from_b' => 10,
        'time_period_start' => '1991-09-01',
        'is_active' => true,
        'notes' => rich(['Long-term alliance reinforced rather than weakened by the crossover crisis.']),
        'visibility' => VisibilityLevel::PRIVATE,
        'content_classification' => ContentClassification::RESTRICTED,
    ]);

    $harryKaladin = $upsertRelationship($relationshipService, $entities['Harry Potter'], $entities['Kaladin Stormblessed'], [
        'relationship_type' => 'comrades',
        'direction' => 'mutual_equal',
        'perspective_a' => rich(['Harry sees another man held together by duty and damage.']),
        'perspective_b' => rich(['Kaladin sees someone who survived a war by refusing to stop being merciful.']),
        'current_tension_charge' => 'complex',
        'strength_from_a' => 8,
        'strength_from_b' => 8,
        'time_period_start' => '2003-10-14',
        'is_active' => true,
        'notes' => rich(['They align quickly in the field but disagree about secrecy and acceptable collateral.']),
        'visibility' => VisibilityLevel::PRIVATE,
        'content_classification' => ContentClassification::RESTRICTED,
    ]);

    $shallanSeraphine = $upsertRelationship($relationshipService, $entities['Shallan Davar'], $entities['Seraphine Vale'], [
        'relationship_type' => 'wary_alliance',
        'direction' => 'mutual_unequal',
        'perspective_a' => rich(['Shallan recognizes a professional liar built out of duty rather than vanity.']),
        'perspective_b' => rich(['Seraphine finds Shallan unsettling because she can survive truths the Archive usually has to ration.']),
        'current_tension_charge' => 'volatile',
        'strength_from_a' => 6,
        'strength_from_b' => 7,
        'time_period_start' => '2003-10-18',
        'is_active' => true,
        'perceived_type' => 'professional collaboration',
        'true_type' => 'mutual threat assessment',
        'perception_divergence' => 'high',
        'notes' => rich(['Excellent scene chemistry, bad sleep, worse transparency.']),
        'visibility' => VisibilityLevel::PRIVATE,
        'content_classification' => ContentClassification::SECRET,
    ]);

    $dalinarKaladin = $upsertRelationship($relationshipService, $entities['Dalinar Kholin'], $entities['Kaladin Stormblessed'], [
        'relationship_type' => 'command_bond',
        'direction' => 'mutual_unequal',
        'perspective_a' => rich(['Dalinar trusts Kaladin as a moral instrument even when strategy grows ugly.']),
        'perspective_b' => rich(['Kaladin trusts Dalinar conditionally, which is another way of saying honestly.']),
        'current_tension_charge' => 'positive',
        'strength_from_a' => 9,
        'strength_from_b' => 7,
        'time_period_start' => '1170-01-01',
        'is_active' => true,
        'notes' => rich(['Their command relationship is the Rosharan backbone of the expedition.']),
        'visibility' => VisibilityLevel::PRIVATE,
        'content_classification' => ContentClassification::RESTRICTED,
    ]);

    $archiveOrder = $upsertRelationship($relationshipService, $entities['Grey Line Archive'], $entities['Order Remnant'], [
        'relationship_type' => 'tense_cooperation',
        'direction' => 'mutual_unequal',
        'perspective_a' => rich(['The Remnant is useful but too sentimental for pure containment logic.']),
        'perspective_b' => rich(['The Archive is necessary but morally alarming.']),
        'current_tension_charge' => 'volatile',
        'strength_from_a' => 6,
        'strength_from_b' => 5,
        'time_period_start' => '2003-09-02',
        'is_active' => true,
        'perceived_type' => 'allied institutions',
        'true_type' => 'mutual leverage',
        'perception_divergence' => 'moderate',
        'notes' => rich(['Both groups are correct about each other in ways that make cooperation exhausting.']),
        'visibility' => VisibilityLevel::PRIVATE,
        'content_classification' => ContentClassification::SECRET,
    ]);

    $wandStormlight = $upsertRelationship($relationshipService, $entities['British Wandcraft'], $entities['Surgebinding'], [
        'relationship_type' => 'system_interaction',
        'direction' => 'mutual_equal',
        'perspective_a' => rich(['Wandcraft treats Surgebinding like a foreign but legible syntax.']),
        'perspective_b' => rich(['Surgebinding treats wandcraft like a local legal framework with power-routing habits.']),
        'current_tension_charge' => 'complex',
        'strength_from_a' => 7,
        'strength_from_b' => 7,
        'time_period_start' => '2003-10-14',
        'is_active' => true,
        'notes' => rich(['Their interaction is one of the main scientific engines of the site.']),
        'visibility' => VisibilityLevel::PRIVATE,
        'content_classification' => ContentClassification::RESTRICTED,
    ]);

    $fieldTeam = GroupRelationship::query()->firstOrNew(['name' => 'Grey Accord Field Team']);
    $fieldTeam->fill([
        'relationship_type' => 'operational_unit',
        'dynamic_description' => rich([
            'Small mixed-world team built around Harry, Kaladin, and Seraphine for threshold emergencies where trust must move faster than bureaucracy.',
        ]),
        'current_tension_charge' => 'complex',
        'group_history' => [['phase' => 'formation', 'notes' => 'Assembled after the Weeping Crossing proved joint field work was possible.']],
        'is_active' => true,
        'notes' => rich(['The team works because each member compensates for one ethical weakness in the others.']),
        'visibility' => VisibilityLevel::PRIVATE,
        'content_classification' => ContentClassification::RESTRICTED,
    ]);
    $fieldTeam->save();

    $studyCircle = GroupRelationship::query()->firstOrNew(['name' => 'Fractured Light Study Circle']);
    $studyCircle->fill([
        'relationship_type' => 'research_circle',
        'dynamic_description' => rich([
            'Research cluster focused on identity strain, light resonance, and what crossing does to narrative continuity.',
        ]),
        'current_tension_charge' => 'neutral',
        'group_history' => [['phase' => 'active', 'notes' => 'Meets irregularly because everyone involved keeps getting pulled into crises.']],
        'is_active' => true,
        'notes' => rich(['Their best ideas often emerge from arguments no one enjoys while they are happening.']),
        'visibility' => VisibilityLevel::PRIVATE,
        'content_classification' => ContentClassification::RESTRICTED,
    ]);
    $studyCircle->save();

    $oversight = GroupRelationship::query()->firstOrNew(['name' => 'Archive Oversight Tangle']);
    $oversight->fill([
        'relationship_type' => 'oversight_body',
        'dynamic_description' => rich([
            'Informal and deeply unstable oversight knot joining the Archive, the Order Remnant, and the Radiant cell.',
        ]),
        'current_tension_charge' => 'volatile',
        'group_history' => [['phase' => 'fragile', 'notes' => 'Exists because no single faction trusts the others to act alone.']],
        'is_active' => true,
        'perceived_type' => 'oversight',
        'true_type' => 'three-way hostage negotiation with stationery',
        'perception_divergence' => 'complete',
        'notes' => rich(['Good for accountability, bad for clean chain-of-command.']),
        'visibility' => VisibilityLevel::PRIVATE,
        'content_classification' => ContentClassification::SECRET,
    ]);
    $oversight->save();

    foreach ([
        [$fieldTeam, $entities['Harry Potter'], 'lead anchor'],
        [$fieldTeam, $entities['Kaladin Stormblessed'], 'field command'],
        [$fieldTeam, $entities['Seraphine Vale'], 'threshold operator'],
        [$studyCircle, $entities['Hermione Granger'], 'theory lead'],
        [$studyCircle, $entities['Shallan Davar'], 'identity mapper'],
        [$studyCircle, $entities['Seraphine Vale'], 'containment case study'],
        [$oversight, $entities['Grey Line Archive'], 'institutional seat'],
        [$oversight, $entities['Order Remnant'], 'field ethics seat'],
        [$oversight, $entities['Knights Radiant Expeditionary Cell'], 'foreign operations seat'],
    ] as [$group, $member, $role]) {
        GroupRelationshipEntity::query()->updateOrCreate(
            [
                'group_relationship_id' => $group->id,
                'entity_id' => $member->id,
            ],
            [
                'role_in_group' => $role,
                'participation_notes' => rich(["{$member->name} serves as {$role} in {$group->name}."]),
                'is_active_member' => true,
                'joined_era' => 'First Convergence Season',
                'left_era' => null,
                'departure_notes' => null,
            ]
        );
    }

    $upsertMembership = static function (RelationshipService $service, Entity $faction, Entity $member, array $data): FactionMembership {
        $existing = FactionMembership::query()
            ->where('faction_entity_id', $faction->id)
            ->where('member_entity_id', $member->id)
            ->first();
        $payload = filterColumns('faction_memberships', $data);

        return $existing
            ? $service->updateFactionMembership($existing, $payload)
            : $service->createFactionMembership($faction, $member, $payload);
    };

    $upsertMembership($relationshipService, $entities['Order Remnant'], $entities['Harry Potter'], [
        'rank_or_role' => 'field anchor',
        'membership_status' => 'active',
        'joined_era' => 'Post-War Britain',
        'notes' => rich(['Harry is not a bureaucratic leader, but everyone treats him as the person the room should listen to when triage gets ugly.']),
        'visibility' => VisibilityLevel::PRIVATE,
        'content_classification' => ContentClassification::RESTRICTED,
        'public_membership_known' => true,
    ]);
    $upsertMembership($relationshipService, $entities['Order Remnant'], $entities['Hermione Granger'], [
        'rank_or_role' => 'policy architect',
        'membership_status' => 'active',
        'joined_era' => 'Post-War Britain',
        'notes' => rich(['Hermione’s legitimacy inside the Remnant comes from competence rather than nostalgia.']),
        'visibility' => VisibilityLevel::PRIVATE,
        'content_classification' => ContentClassification::RESTRICTED,
        'public_membership_known' => true,
    ]);
    $upsertMembership($relationshipService, $entities['Grey Line Archive'], $entities['Seraphine Vale'], [
        'rank_or_role' => 'director',
        'membership_status' => 'active',
        'joined_era' => 'Pre-Convergence',
        'notes' => rich(['Seraphine is both the institution’s backbone and the secret it is most built to hide.']),
        'visibility' => VisibilityLevel::PRIVATE,
        'content_classification' => ContentClassification::SECRET,
        'public_membership_known' => false,
    ]);
    $upsertMembership($relationshipService, $entities['Grey Line Archive'], $entities['Hermione Granger'], [
        'rank_or_role' => 'external consultant',
        'membership_status' => 'active',
        'joined_era' => 'First Convergence Season',
        'notes' => rich(['Her role remains deliberately narrow because she refuses the Archive’s appetite for unreviewable discretion.']),
        'visibility' => VisibilityLevel::PRIVATE,
        'content_classification' => ContentClassification::RESTRICTED,
        'public_membership_known' => false,
    ]);
    $upsertMembership($relationshipService, $entities['Knights Radiant Expeditionary Cell'], $entities['Kaladin Stormblessed'], [
        'rank_or_role' => 'field lead',
        'membership_status' => 'active',
        'joined_era' => 'First Convergence Season',
        'notes' => rich(['Kaladin is the operational heart of the cell.']),
        'visibility' => VisibilityLevel::PRIVATE,
        'content_classification' => ContentClassification::RESTRICTED,
        'public_membership_known' => true,
    ]);
    $upsertMembership($relationshipService, $entities['Knights Radiant Expeditionary Cell'], $entities['Shallan Davar'], [
        'rank_or_role' => 'identity and infiltration analyst',
        'membership_status' => 'active',
        'joined_era' => 'First Convergence Season',
        'notes' => rich(['Shallan maps lies, masks, and institutional self-editing.']),
        'visibility' => VisibilityLevel::PRIVATE,
        'content_classification' => ContentClassification::RESTRICTED,
        'public_membership_known' => true,
    ]);
    $upsertMembership($relationshipService, $entities['Knights Radiant Expeditionary Cell'], $entities['Dalinar Kholin'], [
        'rank_or_role' => 'strategic sponsor',
        'membership_status' => 'active',
        'joined_era' => 'First Convergence Season',
        'notes' => rich(['Dalinar provides authority without micromanaging ground truth.']),
        'visibility' => VisibilityLevel::PRIVATE,
        'content_classification' => ContentClassification::RESTRICTED,
        'public_membership_known' => true,
    ]);

    $documents = [];

    $documentData = [
        'Grey Line Dossier: Seraphine Vale' => [
            'document_type' => 'intelligence_report',
            'owner_entity_id' => $entities['Grey Line Archive']->id,
            'official_author_entity_id' => $entities['Grey Line Archive']->id,
            'true_author_entity_id' => $entities['Seraphine Vale']->id,
            'document_status' => 'classified',
            'document_authenticity' => 'redacted',
            'official_narrative' => rich([
                'Seraphine Vale is a high-value civilian archivist with unusual tolerance for threshold exposure and above-average field judgment.',
            ]),
            'true_content' => rich([
                'Seraphine is the threshold stabilizer around whom most of the Archive’s continuity plans quietly revolve. If she collapses, the Stair probably changes faster than the institution can survive.',
            ]),
            'authorship_divergence_notes' => rich([
                'Official authorship is institutional to preserve plausible deniability. The actual document voice is Seraphine writing about herself in the third person because no one else had the nerve to do it accurately.',
            ]),
            'era_created' => 'First Convergence Season',
            'access_level' => 'directorate_only',
            'known_by_entity_ids' => [$entities['Harry Potter']->id, $entities['Hermione Granger']->id, $entities['Grey Line Archive']->id],
            'visibility' => VisibilityLevel::PRIVATE,
            'content_classification' => ContentClassification::SECRET,
        ],
        'Oathgate Transit Memorandum' => [
            'document_type' => 'technical_document',
            'owner_entity_id' => $entities['Knights Radiant Expeditionary Cell']->id,
            'official_author_entity_id' => $entities['Dalinar Kholin']->id,
            'true_author_entity_id' => $entities['Shallan Davar']->id,
            'document_status' => 'extant',
            'document_authenticity' => 'authentic',
            'official_narrative' => rich([
                'Preliminary technical account of the Oathgate fracture that displaced the first Rosharan arrivals into the threshold corridor.',
            ]),
            'true_content' => rich([
                'The memorandum argues, politely but unmistakably, that the fracture answered a matching structure on the other side and therefore cannot be treated as isolated Rosharan failure.',
            ]),
            'authorship_divergence_notes' => rich([
                'Dalinar sponsors the document. Shallan actually writes the sharpest parts because she is better at documenting contradiction without sounding hysterical.',
            ]),
            'era_created' => 'First Convergence Season',
            'access_level' => 'expeditionary_restricted',
            'known_by_entity_ids' => [$entities['Kaladin Stormblessed']->id, $entities['Shallan Davar']->id, $entities['Hermione Granger']->id],
            'visibility' => VisibilityLevel::PRIVATE,
            'content_classification' => ContentClassification::RESTRICTED,
        ],
        'Joint Protocol for Displaced Rosharan Arrivals' => [
            'document_type' => 'contract',
            'owner_entity_id' => $entities['Order Remnant']->id,
            'official_author_entity_id' => $entities['Hermione Granger']->id,
            'true_author_entity_id' => $entities['Hermione Granger']->id,
            'document_status' => 'public',
            'document_authenticity' => 'authentic',
            'official_narrative' => rich([
                'Working framework for intake, warding, witness protection, translation, and consent standards for displaced Rosharan arrivals.',
            ]),
            'true_content' => rich([
                'The real achievement of the protocol is not logistics. It is that Hermione forces every faction to write down where emergency ends and coercion begins.',
            ]),
            'era_created' => 'First Convergence Season',
            'access_level' => 'cross-faction',
            'known_by_entity_ids' => [],
            'visibility' => VisibilityLevel::PRIVATE,
            'content_classification' => ContentClassification::PUBLIC,
        ],
        'Fractured Light Research Notes' => [
            'document_type' => 'research_notes',
            'owner_entity_id' => $entities['Grey Line Archive']->id,
            'official_author_entity_id' => $entities['Hermione Granger']->id,
            'true_author_entity_id' => $entities['Shallan Davar']->id,
            'document_status' => 'extant',
            'document_authenticity' => 'translated',
            'official_narrative' => rich([
                'Cross-disciplinary notes on wandcraft and Surgebinding interactions near the Mirror Stair.',
            ]),
            'true_content' => rich([
                'The notes slowly become a mutual confession that both magic systems behave more ethically than several of the people studying them.',
            ]),
            'authorship_divergence_notes' => rich([
                'The file alternates between Hermione’s legal precision and Shallan’s metaphor-driven diagnostics, which is why it works.',
            ]),
            'era_created' => 'First Convergence Season',
            'access_level' => 'research_circle',
            'known_by_entity_ids' => [$entities['Hermione Granger']->id, $entities['Shallan Davar']->id, $entities['Seraphine Vale']->id],
            'visibility' => VisibilityLevel::PRIVATE,
            'content_classification' => ContentClassification::RESTRICTED,
        ],
    ];

    foreach ($documentData as $title => $data) {
        $document = Document::query()->firstOrNew(['title' => $title]);
        $document->fill(filterColumns('documents', $data));
        $document->title = $title;
        $document->version_number = 1;
        $document->save();
        $documents[$title] = $document;
    }

    $documentLinks = [
        ['Grey Line Dossier: Seraphine Vale', 'Seraphine Vale', 'subject'],
        ['Grey Line Dossier: Seraphine Vale', 'Grey Line Archive', 'author'],
        ['Oathgate Transit Memorandum', 'Kaladin Stormblessed', 'subject'],
        ['Oathgate Transit Memorandum', 'Urithiru', 'referenced'],
        ['Joint Protocol for Displaced Rosharan Arrivals', 'Hermione Granger', 'author'],
        ['Joint Protocol for Displaced Rosharan Arrivals', 'Harry Potter', 'witness'],
        ['Joint Protocol for Displaced Rosharan Arrivals', 'Knights Radiant Expeditionary Cell', 'signatory'],
        ['Fractured Light Research Notes', 'British Wandcraft', 'subject'],
        ['Fractured Light Research Notes', 'Surgebinding', 'subject'],
        ['Fractured Light Research Notes', 'The Mirror Stair', 'referenced'],
    ];

    foreach ($documentLinks as [$documentTitle, $entityName, $relationshipType]) {
        DocumentEntity::query()->updateOrCreate(
            [
                'document_id' => $documents[$documentTitle]->id,
                'entity_id' => $entities[$entityName]->id,
                'relationship_type' => $relationshipType,
            ],
            [
                'notes' => rich(["{$entityName} is linked to {$documentTitle} as {$relationshipType}."]),
            ]
        );
    }

    $glossaryData = [
        'Grey Line' => [
            'usage_context' => 'both',
            'definition' => rich(['Archive term for the liminal pressure band where worlds can begin to negotiate contact.']),
            'extended_notes' => rich(['Senior operators use the phrase as if it were merely technical language, but it also hides the fact that the threshold behaves more like appetite than geometry.']),
            'origin_universe' => SourceUniverse::ORIGINAL,
            'era_introduced' => 'Pre-Convergence',
            'term_status' => 'active',
            'first_appearance_entity_id' => $entities['Grey Line Archive']->id,
            'related_term_ids' => [],
            'visibility' => VisibilityLevel::PRIVATE,
            'content_classification' => ContentClassification::RESTRICTED,
        ],
        'Stormlight Bleed' => [
            'usage_context' => 'both',
            'definition' => rich(['Localized light saturation effect produced when Rosharan Investiture leaks into wand-bound environments.']),
            'extended_notes' => rich(['Often beautiful, occasionally lethal, always a sign that local wards are translating foreign metaphysics instead of simply blocking them.']),
            'origin_universe' => SourceUniverse::STORMLIGHT,
            'era_introduced' => 'First Convergence Season',
            'term_status' => 'active',
            'first_appearance_entity_id' => $entities['Surgebinding']->id,
            'related_term_ids' => [],
            'visibility' => VisibilityLevel::PRIVATE,
            'content_classification' => ContentClassification::RESTRICTED,
        ],
        'Resonant Wandcraft' => [
            'usage_context' => 'meta',
            'definition' => rich(['Working term for British spellwork after it has adapted to oath-bound light pressure.']),
            'extended_notes' => rich(['Not a separate school of magic so much as a changed operating condition for the existing one.']),
            'origin_universe' => SourceUniverse::HARRY_POTTER,
            'era_introduced' => 'First Convergence Season',
            'term_status' => 'active',
            'first_appearance_entity_id' => $entities['British Wandcraft']->id,
            'related_term_ids' => [],
            'visibility' => VisibilityLevel::PRIVATE,
            'content_classification' => ContentClassification::RESTRICTED,
        ],
        'Oathgate Shear' => [
            'usage_context' => 'both',
            'definition' => rich(['The tearing effect produced when a transit structure is forced to answer incompatible spatial logics.']),
            'extended_notes' => rich(['The fracture over Urithiru is the canonical example on record.']),
            'origin_universe' => SourceUniverse::STORMLIGHT,
            'era_introduced' => 'First Convergence Season',
            'term_status' => 'active',
            'first_appearance_entity_id' => $entities['Oathgate Fracture Over Urithiru']->id,
            'related_term_ids' => [],
            'visibility' => VisibilityLevel::PRIVATE,
            'content_classification' => ContentClassification::RESTRICTED,
        ],
        'Mirror-Burn' => [
            'usage_context' => 'in_world',
            'definition' => rich(['Field slang for the memory abrasion and emotional rawness left by repeated threshold exposure.']),
            'extended_notes' => rich(['Everyone jokes about it until it happens to them.']),
            'origin_universe' => SourceUniverse::ORIGINAL,
            'era_introduced' => 'First Convergence Season',
            'term_status' => 'active',
            'first_appearance_entity_id' => $entities['Seraphine Vale']->id,
            'related_term_ids' => [],
            'visibility' => VisibilityLevel::PRIVATE,
            'content_classification' => ContentClassification::RESTRICTED,
        ],
    ];

    foreach ($glossaryData as $term => $data) {
        $glossary = Glossary::query()->firstOrNew(['term' => $term]);
        $glossary->fill(filterColumns('glossary', $data));
        $glossary->term = $term;
        $glossary->save();
    }

    $convergenceGroup = ConcurrencyGroup::query()->firstOrNew(['name' => 'First Stable Crossing Window']);
    $convergenceGroup->fill(filterColumns('concurrency_groups', [
        'au_date' => '2003-10-14',
        'description' => rich([
            'Clusters the event chain in which the Oathgate fracture, Harry’s resonance response, and the first stable crossing all lock into the same operational window.',
        ]),
        'narrative_significance' => 'pivotal',
    ]));
    $convergenceGroup->save();

    $upsertTimelineEntry = static function (array $where, array $data): Timeline {
        $entry = Timeline::query()->firstOrNew($where);
        $entry->fill(filterColumns('timeline', $data));
        $entry->save();

        return $entry;
    };

    $battleEchoEntry = $upsertTimelineEntry(
        ['timeline_id' => $entities['Harry Potter Original Timeline']->id, 'event_entity_id' => $entities['Battle of Hogwarts Echo']->id],
        [
            'entry_label' => 'Residual Harmonics of the Final Battle',
            'au_date' => '1998-05-02',
            'source_date' => '1998-05-02',
            'source_date_universe' => SourceUniverse::HARRY_POTTER,
            'timeline_position' => 10,
            'temporal_certainty' => 'documented',
            'era_entity_id' => $entities['Post-War Britain']->id,
            'is_atemporal' => false,
            'public_narrative' => rich(['The battle leaves behind abnormal but survivable magical residue.']),
            'true_narrative' => rich(['That residue later becomes a permission signal for the Mirror Stair.']),
            'narrative_divergence' => 'complete',
            'truth_known_by' => [$entities['Harry Potter']->id, $entities['Seraphine Vale']->id],
            'event_significance' => 'pivotal',
            'visibility' => VisibilityLevel::PRIVATE,
            'content_classification' => ContentClassification::RESTRICTED,
        ]
    );

    $fractureEntry = $upsertTimelineEntry(
        ['timeline_id' => $entities['Roshar Refugee Timeline']->id, 'event_entity_id' => $entities['Oathgate Fracture Over Urithiru']->id],
        [
            'entry_label' => 'Urithiru Oathgate Shear',
            'au_date' => '2003-10-14',
            'source_date' => '1174-08-11',
            'source_date_universe' => SourceUniverse::STORMLIGHT,
            'timeline_position' => 10,
            'temporal_certainty' => 'documented',
            'era_entity_id' => $entities['First Convergence Season']->id,
            'concurrency_group_id' => $convergenceGroup->id,
            'is_atemporal' => false,
            'public_narrative' => rich(['A catastrophic transit failure near Urithiru displaces a Rosharan cell into unknown conditions.']),
            'true_narrative' => rich(['The failure is co-authored by a matching threshold architecture on the British side.']),
            'narrative_divergence' => 'partial',
            'truth_known_by' => [$entities['Dalinar Kholin']->id, $entities['Shallan Davar']->id, $entities['Hermione Granger']->id],
            'event_significance' => 'world_altering',
            'visibility' => VisibilityLevel::PRIVATE,
            'content_classification' => ContentClassification::RESTRICTED,
        ]
    );

    $weepingEntry = $upsertTimelineEntry(
        ['timeline_id' => $entities['Convergence Timeline']->id, 'event_entity_id' => $entities['The Weeping Crossing']->id],
        [
            'entry_label' => 'First Stable Crossing',
            'au_date' => '2003-10-14',
            'source_date' => 'crossworld',
            'source_date_universe' => 'Crossworld',
            'timeline_position' => 20,
            'temporal_certainty' => 'documented',
            'era_entity_id' => $entities['First Convergence Season']->id,
            'concurrency_group_id' => $convergenceGroup->id,
            'is_atemporal' => false,
            'caused_by_event_ids' => [$battleEchoEntry->id, $fractureEntry->id],
            'causality_type' => 'catalytic',
            'public_narrative' => rich(['A carefully managed passage succeeds and proves that cooperative transit is possible.']),
            'true_narrative' => rich(['The passage works because three traumatized anchors unknowingly satisfy the Stair’s preferred emotional geometry.']),
            'narrative_divergence' => 'complete',
            'truth_known_by' => [$entities['Harry Potter']->id, $entities['Kaladin Stormblessed']->id, $entities['Seraphine Vale']->id],
            'event_significance' => 'world_altering',
            'visibility' => VisibilityLevel::PRIVATE,
            'content_classification' => ContentClassification::RESTRICTED,
        ]
    );

    $breachEntry = $upsertTimelineEntry(
        ['timeline_id' => $entities['Convergence Timeline']->id, 'event_entity_id' => $entities['Archive Break-In at the Mirror Stair']->id],
        [
            'entry_label' => 'Archive Internal Breach',
            'au_date' => '2003-11-02',
            'source_date' => 'crossworld',
            'source_date_universe' => 'Crossworld',
            'timeline_position' => 30,
            'temporal_certainty' => 'documented',
            'era_entity_id' => $entities['First Convergence Season']->id,
            'is_atemporal' => false,
            'caused_by_event_ids' => [$weepingEntry->id],
            'causality_type' => 'direct',
            'public_narrative' => rich(['A breach compromises records and almost destabilizes the threshold chamber.']),
            'true_narrative' => rich(['The breach is motivated by internal moral panic over how much the Archive is hiding about Seraphine and the Stair.']),
            'narrative_divergence' => 'complete',
            'truth_known_by' => [$entities['Seraphine Vale']->id, $entities['Harry Potter']->id],
            'event_significance' => 'major',
            'visibility' => VisibilityLevel::PRIVATE,
            'content_classification' => ContentClassification::SECRET,
        ]
    );

    TimelineEntity::query()->updateOrCreate(
        [
            'timeline_id' => $entities['Convergence Timeline']->id,
            'event_entity_id' => $entities['Battle of Hogwarts Echo']->id,
        ],
        [
            'position' => 5,
            'perspective_label' => 'threshold precursor',
            'perspective_notes' => rich(['Placed on the convergence timeline because its residue is causally active in the AU.']),
        ]
    );

    TimelineEntity::query()->updateOrCreate(
        [
            'timeline_id' => $entities['Convergence Timeline']->id,
            'event_entity_id' => $entities['Oathgate Fracture Over Urithiru']->id,
        ],
        [
            'position' => 15,
            'perspective_label' => 'source-side fracture',
            'perspective_notes' => rich(['Secondary placement so the master timeline can show source-side causality directly.']),
        ]
    );

    $stateData = [
        [
            'entity' => 'Harry Potter',
            'snapshot_label' => 'After the Weeping Crossing',
            'timeline_id' => $entities['Convergence Timeline']->id,
            'era_entity_id' => $entities['First Convergence Season']->id,
            'au_date' => '2003-10-14',
            'timeline_position' => 20,
            'snapshot_significance' => 'transformative',
            'current_trauma_profile' => rich(['War survivor carrying renewed death-contact and the return of hypervigilance.']),
            'active_psychological_patterns' => rich(['Protect first. Sleep later. Hide the cost until collapse is near.']),
            'current_stability_level' => 'strained',
            'self_perception' => 'Useful if moving, dangerous if still.',
            'current_desire' => 'Keep everyone alive long enough to choose something better than emergency.',
            'current_fear' => 'Becoming a weaponized threshold instead of a person.',
            'mask_integrity' => 'compromised',
            'available_abilities' => ['wandcraft', 'threshold resonance sensitivity', 'field leadership'],
            'key_relationships_summary' => ['Hermione as systems spine', 'Kaladin as moral peer', 'Seraphine as necessary risk'],
            'relationship_states' => [
                ['relationship_id' => $harryHermione->id, 'state_notes' => rich(['Trust remains absolute, patience variable.'])],
                ['relationship_id' => $harryKaladin->id, 'state_notes' => rich(['Rapid respect under operational strain.'])],
            ],
            'notes' => rich(['Harry is functional, not okay. The difference matters.']),
        ],
        [
            'entity' => 'Kaladin Stormblessed',
            'snapshot_label' => 'Threshold Orientation Week',
            'timeline_id' => $entities['Roshar Refugee Timeline']->id,
            'era_entity_id' => $entities['First Convergence Season']->id,
            'au_date' => '2003-10-18',
            'timeline_position' => 20,
            'snapshot_significance' => 'major',
            'current_trauma_profile' => rich(['Acute displacement stress layered over existing depression and command burden.']),
            'active_psychological_patterns' => rich(['Scans for the abandoned person in every room.']),
            'current_stability_level' => 'strained',
            'self_perception' => 'Protector outside the map.',
            'current_desire' => 'Get his people safe without becoming another occupying force.',
            'current_fear' => 'Failing civilians because he misreads the new world.',
            'mask_integrity' => 'intact',
            'available_abilities' => ['lashings', 'combat triage', 'command'],
            'key_relationships_summary' => ['Harry as peer', 'Dalinar as distant stabilizer'],
            'relationship_states' => [
                ['relationship_id' => $harryKaladin->id, 'state_notes' => rich(['Trust earned quickly in motion, less quickly in policy rooms.'])],
                ['relationship_id' => $dalinarKaladin->id, 'state_notes' => rich(['Command bond remains sturdy across distance.'])],
            ],
            'notes' => rich(['Kaladin is adapting faster than anyone expected and forgiving the system much less than it expected.']),
        ],
        [
            'entity' => 'Seraphine Vale',
            'snapshot_label' => 'Post-Breach Director State',
            'timeline_id' => $entities['Convergence Timeline']->id,
            'era_entity_id' => $entities['First Convergence Season']->id,
            'au_date' => '2003-11-03',
            'timeline_position' => 31,
            'snapshot_significance' => 'transformative',
            'current_trauma_profile' => rich(['Severe threshold fatigue masked by control rituals and administrative overfunctioning.']),
            'active_psychological_patterns' => rich(['Compartmentalize, redirect, redact, continue.']),
            'current_stability_level' => 'breaking',
            'self_perception' => 'Useful container with diminishing shelf life.',
            'current_desire' => 'Keep the Archive from becoming crueler than the crisis.',
            'current_fear' => 'Being seen clearly enough that someone else decides she is too compromised to keep choosing.',
            'mask_integrity' => 'cracking',
            'available_abilities' => ['threshold stabilization', 'redaction discipline', 'field containment geometry'],
            'key_relationships_summary' => ['Harry as dangerous conscience', 'Shallan as mirror she did not ask for'],
            'relationship_states' => [
                ['relationship_id' => $shallanSeraphine->id, 'state_notes' => rich(['Mutual fascination, low procedural trust.'])],
            ],
            'notes' => rich(['This is the clearest snapshot that the Archive’s hidden cost is currently embodied rather than abstract.']),
        ],
    ];

    foreach ($stateData as $state) {
        $entity = $entities[$state['entity']];

        $existing = CharacterStateTracker::query()
            ->where('entity_id', $entity->id)
            ->where('snapshot_label', $state['snapshot_label'])
            ->first();

        $payload = $state;
        unset($payload['entity']);
        $relationshipStates = $payload['relationship_states'] ?? [];
        $payload = filterColumns('character_state_tracker', $payload);

        if ($existing) {
            $snapshot = $temporalService->updateStateSnapshot($existing, $payload);
        } else {
            $snapshot = $temporalService->createStateSnapshot($entity, array_merge($payload, [
                'relationship_states' => $relationshipStates,
            ]));
        }

        $snapshot->refresh();
    }

    $secretSeraphine = Secret::query()->firstOrNew(['title' => 'Seraphine is a stabilized failed crossing']);
    $secretSeraphine->fill(filterColumns('secrets', [
        'secret_content' => rich([
            'Seraphine survived an early failed crossing and was permanently altered by it. The Archive uses her body as the unspoken model for threshold survivability.',
        ]),
        'secret_type' => 'identity',
        'subject_entity_ids' => [$entities['Seraphine Vale']->id],
        'holder_entity_ids' => [$entities['Seraphine Vale']->id, $entities['Grey Line Archive']->id],
        'known_by_entity_ids' => [$entities['Seraphine Vale']->id, $entities['Grey Line Archive']->id, $entities['Harry Potter']->id, $entities['Hermione Granger']->id],
        'exposure_risk' => 'critical',
        'exposure_consequences' => rich([
            'If exposed publicly, Seraphine becomes either a martyr, a specimen, or a policy excuse for forced threshold experimentation.',
        ]),
        'revelation_trigger' => 'archive medical files become correlated with breach chamber logs',
        'status' => 'active',
        'related_knowledge_state_ids' => [],
        'related_perception_state_ids' => [],
        'visibility' => VisibilityLevel::PRIVATE,
        'content_classification' => ContentClassification::SECRET,
    ]));
    $secretSeraphine->save();

    $secretHarry = Secret::query()->firstOrNew(['title' => 'Harry’s death resonance stabilizes the Stair']);
    $secretHarry->fill(filterColumns('secrets', [
        'secret_content' => rich([
            'Harry’s history with death magic is one of the threshold’s key permissions. He is not just useful at the Stair; he is part of why it answers.',
        ]),
        'secret_type' => 'power',
        'subject_entity_ids' => [$entities['Harry Potter']->id, $entities['The Mirror Stair']->id],
        'holder_entity_ids' => [$entities['Grey Line Archive']->id, $entities['Seraphine Vale']->id],
        'known_by_entity_ids' => [$entities['Grey Line Archive']->id, $entities['Seraphine Vale']->id, $entities['Harry Potter']->id],
        'exposure_risk' => 'high',
        'exposure_consequences' => rich([
            'Public exposure would turn Harry into a strategic resource to be managed instead of a person to be consulted.',
        ]),
        'revelation_trigger' => 'third-party reproduction of resonance readings',
        'status' => 'active',
        'related_knowledge_state_ids' => [],
        'related_perception_state_ids' => [],
        'visibility' => VisibilityLevel::PRIVATE,
        'content_classification' => ContentClassification::SECRET,
    ]));
    $secretHarry->save();

    $secretMirror = Secret::query()->firstOrNew(['title' => 'The Mirror Stair selects for grief and oath pressure']);
    $secretMirror->fill(filterColumns('secrets', [
        'secret_content' => rich([
            'The Stair is not neutral machinery. It preferentially answers people carrying unresolved grief, oath pressure, or death resonance.',
        ]),
        'secret_type' => 'cosmological',
        'subject_entity_ids' => [$entities['The Mirror Stair']->id],
        'holder_entity_ids' => [$entities['Grey Line Archive']->id],
        'known_by_entity_ids' => [$entities['Grey Line Archive']->id, $entities['Seraphine Vale']->id, $entities['Shallan Davar']->id],
        'exposure_risk' => 'critical',
        'exposure_consequences' => rich([
            'Revealing this would immediately reshape who volunteers, who is pressured, and what counts as acceptable transit risk.',
        ]),
        'revelation_trigger' => 'pattern analysis across failed threshold attempts',
        'status' => 'active',
        'related_knowledge_state_ids' => [],
        'related_perception_state_ids' => [],
        'visibility' => VisibilityLevel::PRIVATE,
        'content_classification' => ContentClassification::SECRET,
    ]));
    $secretMirror->save();

    $knowledgeItems = [
        [
            'knower' => 'Hermione Granger',
            'data' => [
                'subject_secret_id' => $secretSeraphine->id,
                'knowledge_type' => 'secret',
                'knowledge_content' => rich(['Hermione knows Seraphine is the human cost center of the Archive’s stability model.']),
                'accuracy' => 'true',
                'acquired_at_era' => 'First Convergence Season',
                'acquired_through' => 'document',
                'current_belief_state' => 'compartmentalizing',
                'acted_on' => false,
                'valid_from_era' => 'First Convergence Season',
                'visibility' => VisibilityLevel::PRIVATE,
                'content_classification' => ContentClassification::SECRET,
            ],
        ],
        [
            'knower' => 'Shallan Davar',
            'data' => [
                'subject_entity_id' => $entities['Seraphine Vale']->id,
                'knowledge_type' => 'true_nature',
                'knowledge_content' => rich(['Shallan correctly reads that Seraphine is performing composure rather than living inside it.']),
                'accuracy' => 'partial',
                'acquired_at_era' => 'First Convergence Season',
                'acquired_through' => 'observation',
                'current_belief_state' => 'suspects',
                'acted_on' => false,
                'valid_from_era' => 'First Convergence Season',
                'visibility' => VisibilityLevel::PRIVATE,
                'content_classification' => ContentClassification::RESTRICTED,
            ],
        ],
        [
            'knower' => 'Kaladin Stormblessed',
            'data' => [
                'subject_secret_id' => $secretMirror->id,
                'knowledge_type' => 'suspicion',
                'knowledge_content' => rich(['Kaladin suspects the threshold is selecting people, not merely routing them.']),
                'accuracy' => 'true',
                'acquired_at_era' => 'First Convergence Season',
                'acquired_through' => 'observation',
                'current_belief_state' => 'suspects',
                'acted_on' => false,
                'valid_from_era' => 'First Convergence Season',
                'visibility' => VisibilityLevel::PRIVATE,
                'content_classification' => ContentClassification::RESTRICTED,
            ],
        ],
        [
            'knower' => 'Harry Potter',
            'data' => [
                'subject_entity_id' => $entities['The Mirror Stair']->id,
                'knowledge_type' => 'public_fact',
                'knowledge_content' => rich(['Harry knows the Stair responds differently when he is present, even before he knows why.']),
                'accuracy' => 'true',
                'acquired_at_era' => 'First Convergence Season',
                'acquired_through' => 'observation',
                'current_belief_state' => 'believes',
                'acted_on' => true,
                'action_notes' => rich(['He starts refusing to approach the Stair alone and insists on witness protocols.']),
                'valid_from_era' => 'First Convergence Season',
                'visibility' => VisibilityLevel::PRIVATE,
                'content_classification' => ContentClassification::RESTRICTED,
            ],
        ],
    ];

    $knowledgeIds = [];

    foreach ($knowledgeItems as $item) {
        $state = $intelligenceService->recordKnowledge($entities[$item['knower']], $item['data']);
        $knowledgeIds[] = $state->id;
    }

    $secretSeraphine->update(['related_knowledge_state_ids' => [$knowledgeIds[0]]]);
    $secretMirror->update(['related_knowledge_state_ids' => [$knowledgeIds[2]]]);

    $perceptionSeraphine = PerceptionState::query()->firstOrNew([
        'subject_type' => 'entity',
        'subject_id' => $entities['Seraphine Vale']->id,
    ]);
    $perceptionSeraphine->fill(filterColumns('perception_states', [
        'true_state' => rich(['Seraphine is an altered threshold stabilizer whose usefulness is inseparable from the harm she is absorbing.']),
        'perceived_state' => rich(['Seraphine is simply an unusually calm archive director with field experience.']),
        'divergence_level' => 'complete',
        'maintained_by_entity_ids' => [$entities['Grey Line Archive']->id],
        'maintenance_method' => 'strategic_information_control',
        'maintenance_effort' => 'critical',
        'perceiving_entity_ids' => [],
        'immune_entity_ids' => [$entities['Harry Potter']->id, $entities['Hermione Granger']->id, $entities['Shallan Davar']->id],
        'revelation_condition' => rich(['Reveal becomes likely if medical logs, breach records, and early threshold casualty files are read together.']),
        'revelation_consequence' => rich(['The Archive loses moral authority overnight and Seraphine loses all chance of anonymity.']),
        'revelation_risk' => 'critical',
        'revealed_at_era' => null,
        'is_current' => true,
        'related_secret_id' => $secretSeraphine->id,
        'related_knowledge_state_ids' => array_values(array_filter([$knowledgeIds[0], $knowledgeIds[1] ?? null])),
        'visibility' => VisibilityLevel::PRIVATE,
        'content_classification' => ContentClassification::SECRET,
    ]));
    $perceptionSeraphine->save();

    $perceptionArchive = PerceptionState::query()->firstOrNew([
        'subject_type' => 'faction',
        'subject_id' => $entities['Grey Line Archive']->id,
    ]);
    $perceptionArchive->fill(filterColumns('perception_states', [
        'true_state' => rich(['The Archive is a covert containment state with redaction powers its own members barely understand.']),
        'perceived_state' => rich(['The Archive is a private research institution with excessive confidentiality norms.']),
        'divergence_level' => 'complete',
        'maintained_by_entity_ids' => [$entities['Grey Line Archive']->id, $entities['Seraphine Vale']->id],
        'maintenance_method' => 'propaganda',
        'maintenance_effort' => 'active',
        'perceiving_entity_ids' => [],
        'immune_entity_ids' => [$entities['Harry Potter']->id, $entities['Hermione Granger']->id],
        'revelation_condition' => rich(['A second breach combined with public casualty pressure would make the cover story collapse.']),
        'revelation_consequence' => rich(['Every allied faction would demand oversight and half of them would mean control.']),
        'revelation_risk' => 'high',
        'revealed_at_era' => null,
        'is_current' => true,
        'related_secret_id' => $secretMirror->id,
        'related_knowledge_state_ids' => [$knowledgeIds[2]],
        'visibility' => VisibilityLevel::PRIVATE,
        'content_classification' => ContentClassification::SECRET,
    ]));
    $perceptionArchive->save();

    $secretSeraphine->update(['related_perception_state_ids' => [$perceptionSeraphine->id]]);
    $secretMirror->update(['related_perception_state_ids' => [$perceptionArchive->id]]);

    $wandStormlightInteraction = PowerInteraction::query()->firstOrNew(['interaction_name' => 'Wandcraft / Surgebinding Resonance']);
    $wandStormlightInteraction->fill(filterColumns('power_interactions', [
        'system_a_entity_id' => min($entities['British Wandcraft']->id, $entities['Surgebinding']->id),
        'system_b_entity_id' => max($entities['British Wandcraft']->id, $entities['Surgebinding']->id),
        'description' => rich([
            'Wandcraft and Surgebinding do not cancel each other out. They translate, stress, and occasionally amplify each other through shared intent structures.',
        ]),
        'directionality' => 'contextual',
        'dominant_system_entity_id' => null,
        'effects' => [
            [
                'effect_type' => 'transforms',
                'affected_aspect' => 'raw_power',
                'magnitude' => 'significant',
                'notes' => 'Stormlight saturation makes certain wand channels behave like oath-reactive conductors.',
            ],
            [
                'effect_type' => 'destabilizes',
                'affected_aspect' => 'reality_anchor',
                'magnitude' => 'moderate',
                'notes' => 'Poorly shielded thresholds widen under simultaneous use.',
            ],
        ],
        'proximity_required' => true,
        'location_conditions' => ['best_observed_near_thresholds' => true],
        'practitioner_conditions' => ['requires_intent_alignment' => true],
        'trigger_type' => 'co-casting',
        'trigger_description' => rich(['Most visible when a Radiant and a wand-user act toward the same protective goal.']),
        'trigger_frequency' => 'intermittent',
        'interaction_scale' => 'regional',
        'scale_variance' => 'transforms_with_scale',
        'knowledge_state' => 'theorized',
        'danger_rating' => 'high',
        'unresolved_flag' => true,
        'resolution_notes' => rich(['Operationally useful, scientifically unfinished, politically terrifying.']),
        'source_universe_a' => SourceUniverse::HARRY_POTTER,
        'source_universe_b' => SourceUniverse::STORMLIGHT,
        'visibility' => VisibilityLevel::PRIVATE,
        'content_classification' => ContentClassification::RESTRICTED,
    ]));
    $wandStormlightInteraction->save();

    $mirrorInteraction = PowerInteraction::query()->firstOrNew(['interaction_name' => 'Death Resonance / Threshold Selection']);
    $mirrorInteraction->fill(filterColumns('power_interactions', [
        'system_a_entity_id' => min($entities['Harry Potter']->id, $entities['The Mirror Stair']->id),
        'system_b_entity_id' => max($entities['Harry Potter']->id, $entities['The Mirror Stair']->id),
        'description' => rich([
            'Harry’s death-marked magical profile is one of the variables the Mirror Stair preferentially answers. This is less a rule than a terrible pattern.',
        ]),
        'directionality' => 'asymmetrical',
        'dominant_system_entity_id' => $entities['The Mirror Stair']->id,
        'effects' => [
            [
                'effect_type' => 'catalyzes',
                'affected_aspect' => 'reality_anchor',
                'magnitude' => 'catastrophic',
                'notes' => 'Threshold openings become easier when Harry is emotionally engaged.',
            ],
        ],
        'proximity_required' => true,
        'location_conditions' => ['mirror_stair_only' => true],
        'trigger_type' => 'presence_under_stress',
        'trigger_description' => rich(['The effect intensifies when Harry is acting to protect someone specific.']),
        'trigger_frequency' => 'recurrent',
        'interaction_scale' => 'local',
        'scale_variance' => 'intensifies_with_scale',
        'knowledge_state' => 'rumored',
        'danger_rating' => 'existential_risk',
        'unresolved_flag' => true,
        'resolution_notes' => rich(['Everyone hates that this is probably real.']),
        'source_universe_a' => SourceUniverse::HARRY_POTTER,
        'source_universe_b' => SourceUniverse::ORIGINAL,
        'visibility' => VisibilityLevel::PRIVATE,
        'content_classification' => ContentClassification::SECRET,
    ]));
    $mirrorInteraction->save();

    PowerInteractionInstance::query()->updateOrCreate(
        [
            'power_interaction_id' => $wandStormlightInteraction->id,
            'event_entity_id' => $entities['The Weeping Crossing']->id,
        ],
        filterColumns('power_interaction_instances', [
            'involved_entity_ids' => [$entities['Harry Potter']->id, $entities['Kaladin Stormblessed']->id],
            'outcome_match' => 'partial',
            'outcome_notes' => rich(['The crossing succeeded, but the ward geometry changed in ways neither side predicted.']),
            'observed_at_era' => 'First Convergence Season',
            'observed_at_position' => 20,
        ])
    );

    PowerInteractionInstance::query()->updateOrCreate(
        [
            'power_interaction_id' => $mirrorInteraction->id,
            'event_entity_id' => $entities['Archive Break-In at the Mirror Stair']->id,
        ],
        filterColumns('power_interaction_instances', [
            'involved_entity_ids' => [$entities['Harry Potter']->id, $entities['Seraphine Vale']->id],
            'outcome_match' => 'confirmed',
            'outcome_notes' => rich(['The threshold calmed only after Harry physically re-entered the chamber and re-established the protective intent loop.']),
            'observed_at_era' => 'First Convergence Season',
            'observed_at_position' => 30,
        ])
    );

    $worldService->contain($entities['Hogwarts Castle'], $entities['Wizarding Britain'], 'physical', filterColumns('location_containment', [
        'era_start' => 'Founding',
        'notes' => rich(['Hogwarts remains the most symbolically loaded secure site in Wizarding Britain.']),
    ]));
    $worldService->contain($entities['Forbidden Forest'], $entities['Wizarding Britain'], 'physical', filterColumns('location_containment', [
        'era_start' => 'Ancient',
        'notes' => rich(['The Forest absorbs crossover runoff more than anyone is comfortable admitting.']),
    ]));
    $worldService->contain($entities['Urithiru'], $entities['Roshar'], 'physical', filterColumns('location_containment', [
        'era_start' => 'Ancient',
        'notes' => rich(['Urithiru anchors the Rosharan side of the expedition.']),
    ]));
    $worldService->contain($entities['The Mirror Stair'], $entities['Wizarding Britain'], 'dimensional', filterColumns('location_containment', [
        'era_start' => 'Pre-Convergence',
        'notes' => rich(['Officially local, operationally not.']),
    ]));

    $worldService->recordControlChange($entities['Hogwarts Castle'], $entities['Order Remnant'], 'protected', filterColumns('location_control_history', [
        'control_start_era' => 'First Convergence Season',
        'how_control_was_established' => rich(['Protection status emerged through emergency mutual agreement rather than formal state decree.']),
        'resistance_level' => 'minor',
        'notes' => rich(['Hogwarts resists being treated like anyone’s fortress, including its protectors.']),
        'visibility' => VisibilityLevel::PRIVATE,
        'content_classification' => ContentClassification::RESTRICTED,
    ]));
    $worldService->recordControlChange($entities['Urithiru'], $entities['Knights Radiant Expeditionary Cell'], 'sovereign', filterColumns('location_control_history', [
        'control_start_era' => 'First Convergence Season',
        'how_control_was_established' => rich(['Expeditionary cell operates with Radiant legitimacy and Urithiru support.']),
        'resistance_level' => 'none',
        'notes' => rich(['Control is stable but resource-strained.']),
        'visibility' => VisibilityLevel::PRIVATE,
        'content_classification' => ContentClassification::RESTRICTED,
    ]));
    $worldService->recordControlChange($entities['The Mirror Stair'], $entities['Grey Line Archive'], 'puppet', filterColumns('location_control_history', [
        'control_start_era' => 'Pre-Convergence',
        'how_control_was_established' => rich(['The Archive never truly controlled the Stair, but it built enough ritual infrastructure to simulate jurisdiction.']),
        'resistance_level' => 'active_conflict',
        'resistance_entity_id' => $entities['Order Remnant']->id,
        'notes' => rich(['Control is mostly the story the Archive tells to itself.']),
        'visibility' => VisibilityLevel::PRIVATE,
        'content_classification' => ContentClassification::SECRET,
    ]));

    $worldService->createRoute($entities['Hogwarts Castle'], $entities['Forbidden Forest'], 'overland', filterColumns('travel_routes', [
        'standard_duration' => '15 minutes on foot from the edge path',
        'method_variants' => [
            ['method' => 'walking', 'duration' => '15 minutes', 'conditions' => 'clear weather', 'notes' => 'Preferred for students with escort.'],
        ],
        'hazards' => ['creature movement', 'resonance fog'],
        'is_active' => true,
        'notes' => rich(['Routine route turned operational after the threshold blooms began.']),
        'visibility' => VisibilityLevel::PRIVATE,
        'content_classification' => ContentClassification::RESTRICTED,
    ]));
    $worldService->createBidirectionalRoute($entities['Hogwarts Castle'], $entities['The Mirror Stair'], 'magical', filterColumns('travel_routes', [
        'standard_duration' => 'variable; usually under 90 seconds with authorization',
        'method_variants' => [
            ['method' => 'warded descent', 'required_ability_or_artifact' => 'Archive sigil or escort', 'duration' => 'under 90 seconds', 'conditions' => 'threshold calm', 'notes' => 'Unpleasant but reliable.'],
        ],
        'hazards' => ['memory abrasion', 'emotional mirroring'],
        'is_active' => true,
        'controlled_by_entity_id' => $entities['Grey Line Archive']->id,
        'notes' => rich(['Most controlled route in the dataset.']),
        'visibility' => VisibilityLevel::PRIVATE,
        'content_classification' => ContentClassification::SECRET,
    ]));
    $worldService->createBidirectionalRoute($entities['The Mirror Stair'], $entities['Urithiru'], 'planar', filterColumns('travel_routes', [
        'standard_duration' => 'unstable; depends on threshold appetite and stormlight pressure',
        'method_variants' => [
            ['method' => 'threshold passage', 'required_ability_or_artifact' => 'anchor team', 'duration' => 'variable', 'conditions' => 'two-side synchronization', 'notes' => 'Not safe for solo passage.'],
        ],
        'hazards' => ['identity bleed', 'transit shear', 'light starvation'],
        'is_active' => true,
        'controlled_by_entity_id' => $entities['Grey Line Archive']->id,
        'notes' => rich(['This route is the whole project in miniature: useful, unstable, ethically radioactive.']),
        'visibility' => VisibilityLevel::PRIVATE,
        'content_classification' => ContentClassification::SECRET,
    ]));

    $region = GalacticRegion::query()->firstOrNew(['region_name' => 'Subastral Transit Lattice']);
    $region->fill(filterColumns('galactic_regions', [
        'region_type' => 'cluster',
        'approximate_scale' => 'cross-threshold corridor web',
        'notable_features' => rich([
            'A map abstraction used by the Archive to describe how major thresholds appear to relate to one another outside normal geography.',
        ]),
        'known_inhabited_systems' => 2,
        'strategic_significance' => rich([
            'Whoever maps the lattice first gets to define whether crossings remain emergencies, borders, or imperial roads.',
        ]),
        'controlling_entity_id' => $entities['Grey Line Archive']->id,
        'control_era_start' => 'First Convergence Season',
        'is_fully_mapped' => false,
        'mapping_notes' => rich([
            'The lattice is more inference than chart, but it is already useful enough to fight over.',
        ]),
        'connected_location_entity_ids' => [$entities['The Mirror Stair']->id, $entities['Urithiru']->id],
        'source_universe' => SourceUniverse::ORIGINAL,
        'visibility' => VisibilityLevel::PRIVATE,
        'content_classification' => ContentClassification::SECRET,
    ]));
    $region->save();

    $crossingRuleMeta = Meta::query()->find(1) ?? new Meta();
    $crossingRuleMeta->fill(filterColumns('meta', [
        'title' => 'Cross-World Rendering Rules',
        'category' => 'design_notes_and_author_intent',
        'meta_note_type' => 'decision',
        'content' => rich([
            'The crossover should feel administratively real rather than cosmetically mash-up. Every magical interaction needs social, moral, and logistical consequences instead of existing only as spectacle.',
        ]),
        'priority' => 'high',
        'action_status' => 'in_progress',
        'visibility' => VisibilityLevel::PRIVATE,
        'content_classification' => ContentClassification::AUTHOR_ONLY,
    ]));
    $crossingRuleMeta->save();

    $mirrorPaletteMeta = Meta::query()->firstOrNew(['title' => 'Mirror Stair Sensory Palette']);
    $mirrorPaletteMeta->fill(filterColumns('meta', [
        'category' => 'sensory_palettes',
        'meta_note_type' => 'passive',
        'content' => rich([
            'Cold silver, damp stone, breath caught in the throat, reflected light that feels more remembered than seen.',
        ]),
        'sense_sight' => 'silver-blue reflections with wet black seams',
        'sense_sound' => 'soft glass singing under pressure',
        'sense_smell' => 'rainwater, old paper, trace ozone',
        'sense_touch' => 'cool air that thickens before contact',
        'sense_magical' => 'grief and oath pressure braided together',
        'emotional_register' => 'reverent dread',
        'priority' => 'medium',
        'action_status' => 'pending',
        'visibility' => VisibilityLevel::PRIVATE,
        'content_classification' => ContentClassification::AUTHOR_ONLY,
    ]));
    $mirrorPaletteMeta->save();

    $characterContrastMeta = Meta::query()->firstOrNew(['title' => 'Harry / Kaladin Contrast Engine']);
    $characterContrastMeta->fill(filterColumns('meta', [
        'category' => 'themes_and_motifs',
        'meta_note_type' => 'active_task',
        'content' => rich([
            'Use Harry and Kaladin to explore two flavors of protection: improvisational mercy versus disciplined burden-bearing. They should agree on the vulnerable and disagree on institutions.',
        ]),
        'priority' => 'blocking',
        'action_status' => 'pending',
        'visibility' => VisibilityLevel::PRIVATE,
        'content_classification' => ContentClassification::AUTHOR_ONLY,
    ]));
    $characterContrastMeta->save();

    $archiveQuestionMeta = Meta::query()->firstOrNew(['title' => 'How long can the Archive stay kind?']);
    $archiveQuestionMeta->fill(filterColumns('meta', [
        'category' => 'moral_dilemmas',
        'meta_note_type' => 'question',
        'content' => rich([
            'If containment logic keeps winning, at what point does the Grey Line Archive become the very kind of institution the heroes would normally fight?',
        ]),
        'priority' => 'high',
        'action_status' => 'pending',
        'visibility' => VisibilityLevel::PRIVATE,
        'content_classification' => ContentClassification::AUTHOR_ONLY,
    ]));
    $archiveQuestionMeta->save();

    $crossingRuleMeta->entities()->syncWithoutDetaching([
        $entities['Harry Potter']->id,
        $entities['Kaladin Stormblessed']->id,
        $entities['The Mirror Stair']->id,
    ]);
    $mirrorPaletteMeta->entities()->syncWithoutDetaching([$entities['The Mirror Stair']->id]);
    $characterContrastMeta->entities()->syncWithoutDetaching([
        $entities['Harry Potter']->id,
        $entities['Kaladin Stormblessed']->id,
    ]);
    $archiveQuestionMeta->entities()->syncWithoutDetaching([
        $entities['Grey Line Archive']->id,
        $entities['Seraphine Vale']->id,
    ]);

    $characterContrastMeta->groupRelationships()->syncWithoutDetaching([$fieldTeam->id => ['connection_notes' => 'The field team is where the contrast plays out under stress.']]);
    $archiveQuestionMeta->groupRelationships()->syncWithoutDetaching([$oversight->id => ['connection_notes' => 'Oversight scenes should keep forcing the kindness/control question into the open.']]);

    $pipelineData = [
        [
            'title' => 'Chapter 03: The Weeping Crossing',
            'pipeline_type' => 'chapter',
            'pipeline_stage' => 'outlined',
            'sort_order' => 1,
            'content' => 'Full chapter following the first stable crossing, ending with the realization that success has made future crossings inevitable.',
            'word_count' => 4200,
            'reading_time_minutes' => 18,
            'timeline_entry_id' => $weepingEntry->id,
            'timeline_position' => 20,
            'pov_character_entity_id' => $entities['Harry Potter']->id,
            'location_entity_id' => $entities['The Mirror Stair']->id,
            'emotional_beat' => 'dread turning into responsibility',
            'narrative_purpose' => 'Establish the crossover as operational reality rather than rumor.',
            'tracked_entity_id' => $entities['Harry Potter']->id,
            'arc_stage' => 'threshold opening',
            'arc_notes' => 'Harry and Kaladin should respect each other before they understand each other.',
            'notes' => 'Needs a stronger final image tying tears on the mirrors to the name of the event.',
            'visibility' => VisibilityLevel::PRIVATE,
            'content_classification' => ContentClassification::AUTHOR_ONLY,
        ],
        [
            'title' => 'Scene Card: Kaladin in the Hogwarts Infirmary',
            'pipeline_type' => 'scene',
            'pipeline_stage' => 'drafted',
            'sort_order' => 2,
            'content' => 'Kaladin wakes in a place that feels too soft to trust and immediately counts exits, windows, and who looks most likely to be kind.',
            'word_count' => 1650,
            'reading_time_minutes' => 7,
            'pov_character_entity_id' => $entities['Kaladin Stormblessed']->id,
            'location_entity_id' => $entities['Hogwarts Castle']->id,
            'emotional_beat' => 'suspicion yielding to exhausted gratitude',
            'narrative_purpose' => 'Show Rosharan displacement at human scale.',
            'tracked_entity_id' => $entities['Kaladin Stormblessed']->id,
            'arc_stage' => 'arrival shock',
            'arc_notes' => 'No melodrama. The emotional force should come from restraint.',
            'notes' => 'Hermione should be the first person who talks to him like a witness rather than a problem.',
            'visibility' => VisibilityLevel::PRIVATE,
            'content_classification' => ContentClassification::AUTHOR_ONLY,
        ],
        [
            'title' => 'Outline: Archive Breach Fallout',
            'pipeline_type' => 'outline',
            'pipeline_stage' => 'concept',
            'sort_order' => 3,
            'content' => 'Map the chain of trust failures after the Archive breach and decide who learns which secret in what order.',
            'word_count' => 900,
            'reading_time_minutes' => 4,
            'timeline_entry_id' => $breachEntry->id,
            'timeline_position' => 30,
            'tracked_entity_id' => $entities['Grey Line Archive']->id,
            'arc_stage' => 'trust fracture',
            'arc_notes' => 'This should feel like moral shrapnel, not action-movie aftermath.',
            'notes' => 'Need a clean distinction between what Harry learns, what Hermione proves, and what Shallan intuits.',
            'visibility' => VisibilityLevel::PRIVATE,
            'content_classification' => ContentClassification::AUTHOR_ONLY,
        ],
        [
            'title' => 'Inspiration: Wandcraft Meets Stormlight',
            'pipeline_type' => 'inspiration',
            'pipeline_stage' => 'revised',
            'sort_order' => 4,
            'content' => 'Visual and tonal study for scenes where local spell geometry and Rosharan light patterns begin to harmonize.',
            'word_count' => 600,
            'reading_time_minutes' => 3,
            'tracked_entity_id' => $entities['British Wandcraft']->id,
            'inspiration_source_universe' => SourceUniverse::HARRY_POTTER,
            'inspiration_source_element' => 'wand dueling forms under altered light',
            'how_used' => 'Feeds threshold choreography and magical lab scenes.',
            'how_changed' => 'Leans colder, wetter, and more emotionally loaded than source canon visuals.',
            'deviation_level' => 'significant',
            'why_it_fits' => 'Both systems are elegant under pressure, which makes their overlap visually rich.',
            'notes' => 'Use this to keep the crossover aesthetic deliberate rather than generic fantasy-blue-glow.',
            'visibility' => VisibilityLevel::PRIVATE,
            'content_classification' => ContentClassification::AUTHOR_ONLY,
        ],
    ];

    foreach ($pipelineData as $data) {
        $item = PipelineItem::query()->firstOrNew(['title' => $data['title']]);
        $item->fill($data);
        $item->save();
    }

    $sessionOne = SessionLog::query()->firstOrNew(['title' => 'Cross-world architecture pass']);
    $sessionOne->fill([
        'session_date' => '2026-06-23',
        'external_tool' => 'chatgpt',
        'focus_entity_ids' => [
            $entities['Harry Potter']->id,
            $entities['Kaladin Stormblessed']->id,
            $entities['The Mirror Stair']->id,
        ],
        'focus_group_relationship_ids' => [$fieldTeam->id],
        'focus_collection_ids' => [],
        'focus_description' => 'Locked the crossover around operational stakes, threshold ethics, and mutual magical consequence.',
        'decisions_made' => [
            'Harry and Kaladin operate as moral peers, not mirrors.',
            'Seraphine carries the heaviest original-setting burden.',
            'The Mirror Stair behaves like a selecting threshold, not a neutral door.',
        ],
        'changes_applied' => [
            'Elevated Hogwarts into active refuge space.',
            'Shifted the Archive toward shadow-state tension.',
            'Made cross-system magic materially consequential.',
        ],
        'open_threads' => [
            'How public can Rosharan presence become before Britain fractures politically?',
            'What is the cleanest reveal path for Seraphine’s condition?',
        ],
        'follow_up_question_ids' => [],
        'session_significance' => 'foundational',
        'notes' => rich(['Foundational architecture session for the crossover dataset.']),
    ]);
    $sessionOne->save();

    $sessionTwo = SessionLog::query()->firstOrNew(['title' => 'Archive breach consequence pass']);
    $sessionTwo->fill([
        'session_date' => '2026-06-23',
        'external_tool' => 'claude',
        'focus_entity_ids' => [
            $entities['Grey Line Archive']->id,
            $entities['Seraphine Vale']->id,
            $entities['Hermione Granger']->id,
        ],
        'focus_group_relationship_ids' => [$oversight->id],
        'focus_collection_ids' => [],
        'focus_description' => 'Pressure-tested how secrecy, kindness, and institutional control collide once the Archive is breached.',
        'decisions_made' => [
            'The breach is internal, not external.',
            'Hermione becomes the clearest advocate for accountable containment.',
        ],
        'changes_applied' => [
            'Marked Archive perception gap as complete divergence.',
            'Added stronger consequences for secret exposure.',
        ],
        'open_threads' => [
            'Whether the oversight body becomes legitimate or parasitic.',
        ],
        'follow_up_question_ids' => [],
        'session_significance' => 'major',
        'notes' => rich(['Focused on moral fallout rather than new spectacle.']),
    ]);
    $sessionTwo->save();

    $collections = [];

    $collections['Core Crossover Cast'] = Collection::query()->firstOrNew(['name' => 'Core Crossover Cast']);
    $collections['Core Crossover Cast']->fill(filterColumns('collections', [
        'description' => rich(['Primary character roster carrying the emotional and operational spine of the crossover.']),
        'collection_type' => 'character_roster',
        'collection_mode' => 'manual',
        'completion_state' => 'complete',
        'sort_order' => 1,
        'visibility' => VisibilityLevel::PRIVATE,
        'content_classification' => ContentClassification::RESTRICTED,
    ]));
    $collections['Core Crossover Cast']->save();

    $collections['Crossing Events'] = Collection::query()->firstOrNew(['name' => 'Crossing Events']);
    $collections['Crossing Events']->fill(filterColumns('collections', [
        'description' => rich(['Event sequence for the fracture, first crossing, and subsequent breach cascade.']),
        'collection_type' => 'event_sequence',
        'collection_mode' => 'manual',
        'completion_state' => 'in_progress',
        'sort_order' => 2,
        'visibility' => VisibilityLevel::PRIVATE,
        'content_classification' => ContentClassification::RESTRICTED,
    ]));
    $collections['Crossing Events']->save();

    $collections['Stormlight Imports'] = Collection::query()->firstOrNew(['name' => 'Stormlight Imports']);
    $collections['Stormlight Imports']->fill(filterColumns('collections', [
        'description' => rich(['Smart collection of Rosharan-sourced entities and concepts presently active in the AU.']),
        'collection_type' => 'smart',
        'collection_mode' => 'smart',
        'rules' => [
            ['field' => 'source_universes', 'operator' => 'contains', 'value' => SourceUniverse::STORMLIGHT],
        ],
        'completion_state' => 'complete',
        'sort_order' => 3,
        'visibility' => VisibilityLevel::PRIVATE,
        'content_classification' => ContentClassification::RESTRICTED,
    ]));
    $collections['Stormlight Imports']->save();
    $collectionService->syncSmartMembers($collections['Stormlight Imports']);

    $collections['Archive Materials'] = Collection::query()->firstOrNew(['name' => 'Archive Materials']);
    $collections['Archive Materials']->fill(filterColumns('collections', [
        'description' => rich(['Collections of documents, notes, and threshold-facing records held by or about the Grey Line Archive.']),
        'collection_type' => 'research_set',
        'collection_mode' => 'manual',
        'completion_state' => 'in_progress',
        'sort_order' => 4,
        'visibility' => VisibilityLevel::PRIVATE,
        'content_classification' => ContentClassification::SECRET,
    ]));
    $collections['Archive Materials']->save();

    foreach ([
        ['Core Crossover Cast', 'Harry Potter', 'anchor'],
        ['Core Crossover Cast', 'Hermione Granger', 'systems spine'],
        ['Core Crossover Cast', 'Kaladin Stormblessed', 'field counterpart'],
        ['Core Crossover Cast', 'Shallan Davar', 'identity lens'],
        ['Core Crossover Cast', 'Seraphine Vale', 'original anchor'],
        ['Crossing Events', 'Battle of Hogwarts Echo', 'precursor'],
        ['Crossing Events', 'Oathgate Fracture Over Urithiru', 'source fracture'],
        ['Crossing Events', 'The Weeping Crossing', 'breakthrough'],
        ['Crossing Events', 'Archive Break-In at the Mirror Stair', 'fallout'],
    ] as [$collectionName, $entityName, $role]) {
        $collectionService->addEntity($collections[$collectionName], $entities[$entityName], [
            'role_in_collection' => $role,
            'sort_order' => null,
            'notes' => "{$entityName} is included as {$role}.",
        ]);
    }

    foreach ([
        ['Archive Materials', 'Grey Line Dossier: Seraphine Vale', 'dossier'],
        ['Archive Materials', 'Fractured Light Research Notes', 'research core'],
        ['Archive Materials', 'Oathgate Transit Memorandum', 'external technical source'],
    ] as [$collectionName, $documentTitle, $role]) {
        $collectionService->addDocument($collections[$collectionName], $documents[$documentTitle], [
            'role_in_collection' => $role,
            'sort_order' => null,
            'notes' => "{$documentTitle} is included as {$role}.",
        ]);
    }

    $entryPointHp = CrossoverEntryPoint::query()->firstOrNew(['source_universe' => SourceUniverse::HARRY_POTTER]);
    $entryPointHp->fill(filterColumns('crossover_entry_points', [
        'entry_mechanism' => rich(['Threshold activation through death resonance, ward geometry, and grief-bearing anchors.']),
        'power_transition_rules' => rich(['British spellwork remains functional but begins translating foreign light pressure instead of merely resisting it.']),
        'physical_transition_rules' => rich(['Crossers arrive physically intact but often with memory lag, nausea, and temporary sensory distortion.']),
        'memory_and_identity_rules' => rich(['Repeated passage increases the risk of selective memory abrasion around emotionally charged transit moments.']),
        'psychological_transition_rules' => rich(['The threshold favors people already carrying unresolved burdens, which changes who becomes mobile.']),
        'canon_deviation_notes' => rich(['Entry rules are built to honor British magical logic while making crossover materially costly.']),
        'known_examples' => [$entities['Harry Potter']->id, $entities['Seraphine Vale']->id],
        'known_entry_points' => [$entities['The Mirror Stair']->id],
        'status' => 'documented',
        'restrictions' => rich(['Do not permit solo civilian passage without anchor-team oversight.']),
        'return_rules' => rich(['Return is safer when initiated from the calmer side of the threshold pair.']),
        'first_documented_crossing_event_id' => $entities['The Weeping Crossing']->id,
        'visibility' => VisibilityLevel::PRIVATE,
        'content_classification' => ContentClassification::RESTRICTED,
    ]));
    $entryPointHp->save();

    $entryPointStormlight = CrossoverEntryPoint::query()->firstOrNew(['source_universe' => SourceUniverse::STORMLIGHT]);
    $entryPointStormlight->fill(filterColumns('crossover_entry_points', [
        'entry_mechanism' => rich(['Transit shear generated by Oathgate stress answering a compatible threshold architecture elsewhere.']),
        'power_transition_rules' => rich(['Stormlight remains usable, but efficiency changes under foreign metaphysical conditions and local wards.']),
        'physical_transition_rules' => rich(['Rosharan arrivals show pressure trauma, dehydration risk, and altered equilibrium in low-storm environments.']),
        'memory_and_identity_rules' => rich(['Identity remains intact, but lightweaving and oath-stress can echo strangely in reflective threshold spaces.']),
        'psychological_transition_rules' => rich(['Displacement intensifies existing protective compulsions and fractures any already unstable mask-work.']),
        'canon_deviation_notes' => rich(['Rules preserve Rosharan spiritual logic while acknowledging the threshold as a third actor.']),
        'known_examples' => [$entities['Kaladin Stormblessed']->id, $entities['Shallan Davar']->id],
        'known_entry_points' => [$entities['Urithiru']->id, $entities['The Mirror Stair']->id],
        'status' => 'documented',
        'restrictions' => rich(['Do not assume British-side atmosphere, food, or magical law will be harmless by default.']),
        'return_rules' => rich(['Return transit requires stable two-side coordination and is safest after local resonance has cooled.']),
        'first_documented_crossing_event_id' => $entities['Oathgate Fracture Over Urithiru']->id,
        'visibility' => VisibilityLevel::PRIVATE,
        'content_classification' => ContentClassification::RESTRICTED,
    ]));
    $entryPointStormlight->save();

    $canonRefs = [];

    $canonRefs['Harry Potter Universe'] = firstOrNewCanonReference('Harry Potter Universe', [
        'universe' => SourceUniverse::HARRY_POTTER,
        'level' => 'universe',
        'content' => rich(['Reference scaffold for what the AU borrows from Harry Potter and where it deliberately diverges.']),
        'universe_overview' => rich(['Late-series British wizarding canon emphasizing war aftermath, institutional fragility, and inherited magical infrastructure.']),
        'universe_priority' => 'primary',
        'universe_depth_rating' => 'comprehensive',
        'overall_divergence_summary' => rich(['The AU keeps emotional and magical logic while expanding the post-war political and logistical consequences.']),
        'primary_elements_borrowed' => ['wandcraft', 'Hogwarts', 'post-war Britain', 'Harry and Hermione as survivors'],
        'primary_divergences' => ['threshold bureaucracy', 'public refugee logic', 'death resonance as crossover permission'],
        'crossover_entry_point_id' => $entryPointHp->id,
        'research_status' => 'comprehensive',
        'research_notes' => rich(['Treat canon as emotionally binding but administratively underexplored.']),
        'research_confidence' => 'verified',
        'visibility' => VisibilityLevel::PRIVATE,
        'content_classification' => ContentClassification::AUTHOR_ONLY,
    ]);

    $canonRefs['Stormlight Archive Universe'] = firstOrNewCanonReference('Stormlight Archive Universe', [
        'universe' => SourceUniverse::STORMLIGHT,
        'level' => 'universe',
        'content' => rich(['Reference scaffold for what the AU borrows from Stormlight and how its ethics travel across worlds.']),
        'universe_overview' => rich(['Rosharan canon centered on oaths, war trauma, Investiture, and the social cost of leadership.']),
        'universe_priority' => 'primary',
        'universe_depth_rating' => 'solid',
        'overall_divergence_summary' => rich(['The AU preserves Rosharan moral and magical structures while displacing a select field cell into British crisis conditions.']),
        'primary_elements_borrowed' => ['Surgebinding', 'Urithiru', 'Radiant ethics', 'Kaladin and Shallan'],
        'primary_divergences' => ['threshold displacement', 'wandcraft interaction', 'cross-world refugee governance'],
        'crossover_entry_point_id' => $entryPointStormlight->id,
        'research_status' => 'solid',
        'research_notes' => rich(['Do not flatten Rosharan spirituality into generic magic-system coolness.']),
        'research_confidence' => 'solid',
        'visibility' => VisibilityLevel::PRIVATE,
        'content_classification' => ContentClassification::AUTHOR_ONLY,
    ]);

    $canonRefs['Harry Potter Characters'] = firstOrNewCanonReference('Harry Potter Characters', [
        'universe' => SourceUniverse::HARRY_POTTER,
        'level' => 'category',
        'parent_reference_id' => $canonRefs['Harry Potter Universe']->id,
        'title' => 'Harry Potter Characters',
        'content' => rich(['Character-level canon notes for the British cast.']),
        'category_type' => 'characters',
        'category_overview' => rich(['Focus on post-war survivorship, competence, and the emotional costs of public legend.']),
        'research_status' => 'solid',
        'research_confidence' => 'solid',
        'visibility' => VisibilityLevel::PRIVATE,
        'content_classification' => ContentClassification::AUTHOR_ONLY,
    ]);

    $canonRefs['Stormlight Characters'] = firstOrNewCanonReference('Stormlight Characters', [
        'universe' => SourceUniverse::STORMLIGHT,
        'level' => 'category',
        'parent_reference_id' => $canonRefs['Stormlight Archive Universe']->id,
        'title' => 'Stormlight Characters',
        'content' => rich(['Character-level canon notes for the Rosharan field cast.']),
        'category_type' => 'characters',
        'category_overview' => rich(['Keep oath psychology and trauma logic intact even under crossover pressure.']),
        'research_status' => 'solid',
        'research_confidence' => 'solid',
        'visibility' => VisibilityLevel::PRIVATE,
        'content_classification' => ContentClassification::AUTHOR_ONLY,
    ]);

    $canonRefs['Harry Potter (Element)'] = firstOrNewCanonReference('Harry Potter (Element)', [
        'universe' => SourceUniverse::HARRY_POTTER,
        'level' => 'element',
        'parent_reference_id' => $canonRefs['Harry Potter Characters']->id,
        'content' => rich(['Element reference for Harry as source character.']),
        'element_type' => 'character',
        'canonical_properties' => ['survivor hero', 'mercy under pressure', 'death-linked magical history'],
        'first_appearance' => 'Harry Potter and the Philosopher’s Stone',
        'source_material_references' => ['Book 1-7', 'Battle of Hogwarts'],
        'au_entity_id' => $entities['Harry Potter']->id,
        'research_status' => 'comprehensive',
        'research_confidence' => 'verified',
        'visibility' => VisibilityLevel::PRIVATE,
        'content_classification' => ContentClassification::AUTHOR_ONLY,
    ]);

    $canonRefs['Kaladin Stormblessed (Element)'] = firstOrNewCanonReference('Kaladin Stormblessed (Element)', [
        'universe' => SourceUniverse::STORMLIGHT,
        'level' => 'element',
        'parent_reference_id' => $canonRefs['Stormlight Characters']->id,
        'content' => rich(['Element reference for Kaladin as source character.']),
        'element_type' => 'character',
        'canonical_properties' => ['protection compulsion', 'leadership under depression', 'Windrunner ethics'],
        'first_appearance' => 'The Way of Kings',
        'source_material_references' => ['Bridge Four arc', 'Windrunner oaths'],
        'au_entity_id' => $entities['Kaladin Stormblessed']->id,
        'research_status' => 'solid',
        'research_confidence' => 'solid',
        'visibility' => VisibilityLevel::PRIVATE,
        'content_classification' => ContentClassification::AUTHOR_ONLY,
    ]);

    $canonRefs['Urithiru (Element)'] = firstOrNewCanonReference('Urithiru (Element)', [
        'universe' => SourceUniverse::STORMLIGHT,
        'level' => 'element',
        'parent_reference_id' => $canonRefs['Stormlight Archive Universe']->id,
        'content' => rich(['Element reference for Urithiru as a functioning command-city in the crossover AU.']),
        'element_type' => 'location',
        'canonical_properties' => ['ancient tower city', 'Radiant hub', 'strategic vertical fortress'],
        'first_appearance' => 'Words of Radiance',
        'source_material_references' => ['Urithiru occupation and recovery arcs'],
        'au_entity_id' => $entities['Urithiru']->id,
        'research_status' => 'solid',
        'research_confidence' => 'solid',
        'visibility' => VisibilityLevel::PRIVATE,
        'content_classification' => ContentClassification::AUTHOR_ONLY,
    ]);

    foreach ($canonRefs as $reference) {
        $reference->save();
    }

    CanonReferenceEntity::query()->updateOrCreate(
        [
            'canon_reference_id' => $canonRefs['Harry Potter (Element)']->id,
            'entity_id' => $entities['Harry Potter']->id,
        ],
        [
            'divergence_level' => 'moderate',
            'relationship_type' => 'au_version',
            'divergence_notes' => rich(['Keeps Harry’s compassion and exhaustion, then intensifies the institutional consequences around him.']),
        ]
    );

    CanonReferenceEntity::query()->updateOrCreate(
        [
            'canon_reference_id' => $canonRefs['Kaladin Stormblessed (Element)']->id,
            'entity_id' => $entities['Kaladin Stormblessed']->id,
        ],
        [
            'divergence_level' => 'moderate',
            'relationship_type' => 'au_version',
            'divergence_notes' => rich(['Preserves Kaladin’s emotional core while relocating the field of moral comparison.']),
        ]
    );

    CanonReferenceEntity::query()->updateOrCreate(
        [
            'canon_reference_id' => $canonRefs['Urithiru (Element)']->id,
            'entity_id' => $entities['Urithiru']->id,
        ],
        [
            'divergence_level' => 'minimal',
            'relationship_type' => 'au_version',
            'divergence_notes' => rich(['Urithiru remains mostly itself while taking on a new expeditionary function.']),
        ]
    );

    $mediaRows = [
        [
            'entity_id' => $entities['Harry Potter']->id,
            'title' => 'Harry Potter reference page',
            'description' => 'Quick external reference link for visual and canon grounding.',
            'media_type' => 'link',
            'purpose' => 'reference',
            'url' => 'https://harrypotter.fandom.com/wiki/Harry_Potter',
            'sort_order' => 1,
            'is_primary' => true,
            'visibility' => VisibilityLevel::PRIVATE,
            'content_classification' => ContentClassification::RESTRICTED,
        ],
        [
            'entity_id' => $entities['Kaladin Stormblessed']->id,
            'title' => 'Kaladin reference page',
            'description' => 'External reference link for Kaladin.',
            'media_type' => 'link',
            'purpose' => 'reference',
            'url' => 'https://coppermind.net/wiki/Kaladin',
            'sort_order' => 1,
            'is_primary' => true,
            'visibility' => VisibilityLevel::PRIVATE,
            'content_classification' => ContentClassification::RESTRICTED,
        ],
        [
            'entity_id' => $entities['Seraphine Vale']->id,
            'title' => 'Seraphine portrait brief',
            'description' => 'Placeholder portrait brief for the original anchor character.',
            'media_type' => 'link',
            'purpose' => 'portrait',
            'url' => 'https://example.com/seraphine-vale-portrait-brief',
            'sort_order' => 1,
            'is_primary' => true,
            'visibility' => VisibilityLevel::PRIVATE,
            'content_classification' => ContentClassification::SECRET,
        ],
        [
            'entity_id' => $entities['Hogwarts Castle']->id,
            'title' => 'Hogwarts visual reference',
            'description' => 'External map and visual reference for Hogwarts.',
            'media_type' => 'link',
            'purpose' => 'map',
            'url' => 'https://harrypotter.fandom.com/wiki/Hogwarts_Castle',
            'sort_order' => 1,
            'is_primary' => true,
            'visibility' => VisibilityLevel::PRIVATE,
            'content_classification' => ContentClassification::RESTRICTED,
        ],
        [
            'entity_id' => $entities['Urithiru']->id,
            'title' => 'Urithiru visual reference',
            'description' => 'External reference for Urithiru.',
            'media_type' => 'link',
            'purpose' => 'map',
            'url' => 'https://coppermind.net/wiki/Urithiru',
            'sort_order' => 1,
            'is_primary' => true,
            'visibility' => VisibilityLevel::PRIVATE,
            'content_classification' => ContentClassification::RESTRICTED,
        ],
        [
            'entity_id' => $entities['The Mirror Stair']->id,
            'title' => 'Mirror Stair mood board brief',
            'description' => 'Placeholder mood board brief for the threshold chamber.',
            'media_type' => 'link',
            'purpose' => 'mood',
            'url' => 'https://example.com/mirror-stair-mood-board',
            'sort_order' => 1,
            'is_primary' => true,
            'visibility' => VisibilityLevel::PRIVATE,
            'content_classification' => ContentClassification::SECRET,
        ],
        [
            'meta_id' => $mirrorPaletteMeta->id,
            'title' => 'Mirror Stair palette board',
            'description' => 'Palette reference linked directly to the sensory meta note.',
            'media_type' => 'link',
            'purpose' => 'mood',
            'url' => 'https://example.com/mirror-stair-palette-board',
            'sort_order' => 1,
            'is_primary' => true,
            'visibility' => VisibilityLevel::PRIVATE,
            'content_classification' => ContentClassification::AUTHOR_ONLY,
        ],
    ];

    foreach ($mediaRows as $row) {
        MediaReference::query()->updateOrCreate(
            [
                'title' => $row['title'],
            ],
            filterColumns('media_references', $row)
        );
    }

    foreach ([
        $entities['Harry Potter'],
        $entities['Kaladin Stormblessed'],
        $entities['Seraphine Vale'],
    ] as $versionedEntity) {
        if ($versionedEntity->versionZero()->count() === 0) {
            $entityService->saveVersionZero($versionedEntity, [
                'version_label' => "Version Zero — {$versionedEntity->name}",
                'version_zero_confidence' => 'solid',
                'version_zero_notes' => 'Captured as source baseline before crossover-specific mutation accumulates.',
            ]);
        }
    }

    $harry = $entities['Harry Potter']->fresh();
    if ($harry->versions()->count() < 3) {
        $entityService->saveManualCanonState($harry, [
            'version_label' => 'Threshold-Aware Harry',
            'what_changed' => rich(['Harry accepts that he is part of the threshold’s operating logic rather than merely near it.']),
            'why_changed' => rich(['Needed because this is the point where the AU version becomes meaningfully different from the baseline survivor.']),
            'valid_from_era' => 'First Convergence Season',
        ]);
    }

    $seraphine = $entities['Seraphine Vale']->fresh();
    if ($seraphine->versions()->count() < 3) {
        $entityService->saveManualCanonState($seraphine, [
            'version_label' => 'Post-Breach Seraphine',
            'what_changed' => rich(['The breach forces Seraphine from pure administrator posture into visible strategic actor posture.']),
            'why_changed' => rich(['Her secrecy stops feeling procedural and starts feeling tragic in a public way.']),
            'valid_from_era' => 'First Convergence Season',
        ]);
    }

    foreach (Entity::query()->get() as $entity) {
        $flagFlipper->flipAll($entity);
        $scoreUpdater->recalculate($entity->fresh());
    }

    foreach ([
        'Harry Potter',
        'Hermione Granger',
        'Kaladin Stormblessed',
        'Shallan Davar',
        'Hogwarts Castle',
        'Urithiru',
        'The Weeping Crossing',
    ] as $publishedName) {
        $entity = $entities[$publishedName]->fresh();

        if ($entity->completion_score >= 50 && !$entity->isPublished()) {
            $entityService->publish($entity);
        }
    }

    $summary = [
        'entities' => Entity::query()->count(),
        'documents' => Document::query()->count(),
        'relationships' => Relationship::query()->count(),
        'group_relationships' => GroupRelationship::query()->count(),
        'timeline_entries' => Timeline::query()->count(),
        'timeline_placements' => TimelineEntity::query()->count(),
        'state_snapshots' => CharacterStateTracker::query()->count(),
        'knowledge_states' => KnowledgeState::query()->count(),
        'secrets' => Secret::query()->count(),
        'perception_states' => PerceptionState::query()->count(),
        'power_interactions' => PowerInteraction::query()->count(),
        'power_interaction_instances' => PowerInteractionInstance::query()->count(),
        'collections' => Collection::query()->count(),
        'glossary' => Glossary::query()->count(),
        'meta' => Meta::query()->count(),
        'pipeline' => PipelineItem::query()->count(),
        'sessions' => SessionLog::query()->count(),
    ];

    foreach ($summary as $key => $count) {
        echo sprintf("%s: %s\n", $key, $count);
    }
});

function rich(array $paragraphs): array
{
    return [
        'type' => 'doc',
        'content' => array_values(array_map(static function (string $paragraph): array {
            return [
                'type' => 'paragraph',
                'content' => [[
                    'type' => 'text',
                    'text' => trim($paragraph),
                ]],
            ];
        }, array_values(array_filter(array_map('trim', $paragraphs))))),
    ];
}

function firstOrNewCanonReference(string $title, array $data): SourceCanonReference
{
    $reference = SourceCanonReference::query()->firstOrNew(['title' => $title]);
    $reference->fill(filterColumns('source_canon_reference', $data));
    $reference->title = $title;

    return $reference;
}

function filterColumns(string $table, array $data): array
{
    static $cache = [];

    if (!array_key_exists($table, $cache)) {
        $cache[$table] = collect(DB::select(
            "select column_name from information_schema.columns where table_schema = 'public' and table_name = ?",
            [$table]
        ))->pluck('column_name')->flip()->all();
    }

    return array_intersect_key($data, $cache[$table]);
}
