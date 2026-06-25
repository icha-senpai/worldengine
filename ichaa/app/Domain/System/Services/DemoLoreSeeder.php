<?php

namespace App\Domain\System\Services;

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
use App\Domain\Organization\Models\CollectionDocument;
use App\Domain\Organization\Models\CollectionEntity;
use App\Domain\Organization\Models\Glossary;
use App\Domain\Organization\Services\CollectionService;
use App\Domain\Production\Models\Meta;
use App\Domain\Production\Models\PipelineItem;
use App\Domain\Production\Models\SessionLog;
use App\Domain\Temporal\Models\CharacterStateTracker;
use App\Domain\Temporal\Models\ConcurrencyGroup;
use App\Domain\Temporal\Models\Timeline;
use App\Domain\Temporal\Services\TemporalService;
use App\Domain\World\Models\LocationContainment;
use App\Domain\World\Models\LocationControlHistory;
use App\Domain\World\Models\PowerInteraction;
use App\Domain\World\Models\TravelRoute;
use App\Domain\World\Services\WorldService;
use Illuminate\Support\Facades\DB;

class DemoLoreSeeder
{
    private array $stats = [];

    public function __construct(
        private readonly EntityService $entityService,
        private readonly RelationshipService $relationshipService,
        private readonly TemporalService $temporalService,
        private readonly WorldService $worldService,
        private readonly IntelligenceService $intelligenceService,
        private readonly CollectionService $collectionService,
        private readonly FlipEntityCompletionFlags $flagFlipper,
        private readonly UpdateCompletionScore $completionUpdater,
    ) {}

    public function seed(): array
    {
        $this->stats = [];

        DB::transaction(function () {
            $entities = $this->seedEntities();
            $relationships = $this->seedRelationships($entities);
            $groups = $this->seedGroupRelationships($entities);
            $this->seedFactionMemberships($entities);
            $documents = $this->seedDocuments($entities);
            $entryPoints = $this->seedEntryPoints($entities);
            $canonReferences = $this->seedCanonReferences($entities, $entryPoints);
            $this->seedGlossary($entities);
            $timelineData = $this->seedTimelines($entities);
            $this->seedStates($entities, $relationships, $timelineData);
            $this->seedWorld($entities);
            $this->seedIntelligence($entities, $relationships, $groups, $timelineData);
            $this->seedCollections($entities, $documents);
            $this->seedMetaAndPipeline($entities);
            $this->seedSessionLogs($entities);
            $this->seedEntitySubresources($entities);
            $this->seedMedia($entities, $documents, $canonReferences);
            $this->seedEntityVersions($entities);
            $this->finalizeEntities($entities);
        });

        ksort($this->stats);

        return [
            'resources' => $this->stats,
        ];
    }

    private function seedEntities(): array
    {
        $published = now()->subDays(3);

        return [
            'harry' => $this->upsertEntity('Harry Potter', [
                'public_title' => 'The Boy Who Lived, now operating as a field bridge between Hogwarts and Urithiru.',
                'entity_type' => 'character',
                'entity_sub_type' => 'crossover protagonist',
                'summary' => $this->rich("Harry is written here as an adult veteran rather than a school-era icon. He works from instinct, responsibility, and an increasingly deliberate refusal to let other people carry apocalyptic burdens alone.\n\nIn the Grey Line material he becomes the emotional hinge between the Hogwarts survivors and the Rosharan arrivals. He is good at crisis triage, bad at resting, and quietly dangerous whenever institutional secrecy starts sounding like mercy."),
                'public_summary' => $this->rich("A battle-scarred auror-adjacent operative balancing protection, guilt, and impossible diplomacy across worlds."),
                'source_universes' => [SourceUniverse::HARRY_POTTER],
                'origin_type' => 'canon transplant',
                'canon_deviation' => 'Post-war Harry survives long enough to become a systems thinker and field coordinator instead of settling into a purely domestic epilogue.',
                'origin_notes' => $this->rich("This version preserves Harry’s reflexive protectiveness, impatience with bureaucracy, and ability to act under pressure. The major divergence is scale: he is asked to carry inter-world responsibility rather than just national reconstruction.\n\nHis magic remains wand-rooted, but exposure to Rosharan oaths makes him more conscious of intent, spoken promises, and the moral cost of improvisation."),
                'status' => 'active',
                'type_status' => 'main cast',
                'visibility' => VisibilityLevel::PUBLIC_KNOWLEDGE,
                'content_classification' => ContentClassification::PUBLIC,
                'published_at' => $published,
                'power_tier_ceiling' => 'continental',
                'power_tier_operating' => 'regional',
                'power_tier_influence' => 'global',
                'public_persona' => [
                    'surface' => 'Calm under pressure, wry when exhausted, visibly more patient than he was as a teenager.',
                    'reputation' => 'Seen as the person who will step into danger first and argue about the cost later.',
                ],
                'true_nature' => [
                    'core_truth' => 'Harry still measures every victory against who was not saved.',
                    'private_conflicts' => [
                        'Treats competence as a moral obligation.',
                        'Struggles to believe shared burden can stay shared.',
                    ],
                    'crossworld_pressure' => 'Rosharan oath culture makes his old promise-keeping reflex feel almost supernatural in its consequences.',
                ],
                'persona_divergence' => 'His public steadiness hides how quickly guilt turns into overreach.',
                'attributes' => [
                    'skills' => ['defensive magic', 'field leadership', 'improvised diplomacy', 'survival under magical stress'],
                    'signature_items' => ['holly wand', 'field notebook keyed to Grey Line incidents'],
                    'drives' => ['protect civilians', 'keep crossings from becoming exploitation', 'prevent secret wars'],
                    'constraints' => ['trauma-triggered self-sacrifice', 'limited trust in committees'],
                ],
            ]),
            'hermione' => $this->upsertEntity('Hermione Granger', [
                'public_title' => 'Scholar-operator overseeing translation, policy, and magical systems comparison.',
                'entity_type' => 'character',
                'entity_sub_type' => 'research lead',
                'summary' => $this->rich("Hermione functions as the most reliable bridge between theory and emergency action. She is the person who turns a bizarre incident into a framework, then turns that framework into something the rest of the team can actually use.\n\nIn this continuity she is less interested in being right than in making sure no one builds policy on bad assumptions. That makes her invaluable and occasionally impossible to live with."),
                'public_summary' => $this->rich("A rigorous crossover researcher who translates impossible phenomena into policy, procedure, and actionable warnings."),
                'source_universes' => [SourceUniverse::HARRY_POTTER],
                'origin_type' => 'canon transplant',
                'canon_deviation' => 'Her post-war career drifts toward magical governance and inter-system law instead of staying entirely national.',
                'origin_notes' => $this->rich("Hermione remains book-fast, argumentative, and ethically stubborn. The divergence is professional scale: she now writes protocols for contact with non-native metaphysics and treats every untested interaction as a potential human-rights problem.\n\nShe also becomes one of the first people willing to say that the Grey Line is not just a route but a pressure ecology."),
                'status' => 'active',
                'type_status' => 'main cast',
                'visibility' => VisibilityLevel::PUBLIC_KNOWLEDGE,
                'content_classification' => ContentClassification::PUBLIC,
                'published_at' => $published,
                'power_tier_ceiling' => 'regional',
                'power_tier_operating' => 'regional',
                'power_tier_influence' => 'global',
                'public_persona' => [
                    'surface' => 'Controlled, articulate, unfailingly prepared.',
                    'reputation' => 'The person you want in the room before you sign anything magical.',
                ],
                'true_nature' => [
                    'core_truth' => 'She is angriest when avoidable ignorance gets framed as mystery.',
                    'private_conflicts' => [
                        'Knows analysis can become a shield against grief.',
                        'Finds herself drawn to systems she does not trust because she wants them legible.',
                    ],
                ],
                'persona_divergence' => 'Her precision often reads as coldness long before people understand it is protective.',
                'attributes' => [
                    'skills' => ['comparative magical theory', 'policy drafting', 'forensic spellwork', 'translation and notation systems'],
                    'signature_items' => ['annotated resonance charts', 'stacked translation folios'],
                    'drives' => ['make magic accountable', 'document every crossing rule', 'protect civilians from expert arrogance'],
                    'constraints' => ['overwork', 'difficulty delegating incomplete analysis'],
                ],
            ]),
            'kaladin' => $this->upsertEntity('Kaladin Stormblessed', [
                'public_title' => 'Windrunner surgeon-soldier operating as the site’s most dependable crisis responder.',
                'entity_type' => 'character',
                'entity_sub_type' => 'Rosharan field lead',
                'summary' => $this->rich("Kaladin arrives carrying exhaustion, competence, and a near-reflexive talent for protecting people who have already decided they are expendable. He reads institutions with the same suspicion he reads battlefields: where are the weak points, who gets left behind, and who is calling neglect strategy?\n\nAcross the crossover material he becomes an anchor for Harry because he recognizes the same self-destructive promise-making under a different vocabulary."),
                'public_summary' => $this->rich("A Windrunner whose instinct to protect becomes both the site’s greatest stabilizer and one of its largest risks."),
                'source_universes' => [SourceUniverse::STORMLIGHT],
                'origin_type' => 'canon transplant',
                'canon_deviation' => 'Kaladin engages long-term in crossworld containment rather than remaining exclusively Roshar-focused.',
                'origin_notes' => $this->rich("This portrayal keeps Kaladin’s severe empathy, tactical discipline, and recurring depression intact. The divergence lies in context: he is forced to evaluate non-Rosharan magic through the ethics of oathbound service.\n\nHe is one of the first people to notice that the Grey Line seems to respond to emotional compression and unkept vows."),
                'status' => 'active',
                'type_status' => 'main cast',
                'visibility' => VisibilityLevel::PUBLIC_KNOWLEDGE,
                'content_classification' => ContentClassification::PUBLIC,
                'published_at' => $published,
                'power_tier_ceiling' => 'continental',
                'power_tier_operating' => 'continental',
                'power_tier_influence' => 'global',
                'public_persona' => [
                    'surface' => 'Quiet, sharp-eyed, difficult to rattle.',
                    'reputation' => 'The man who notices danger before anyone else has named it.',
                ],
                'true_nature' => [
                    'core_truth' => 'Kaladin’s mercy is inseparable from anger at systems that sort people into acceptable losses.',
                    'private_conflicts' => [
                        'Needs usefulness too much.',
                        'Sees his own collapse coming sooner than other people do and rarely says so.',
                    ],
                ],
                'persona_divergence' => 'His calm field presence conceals how hard every rescue choice hits him afterward.',
                'attributes' => [
                    'skills' => ['surgebinding', 'battlefield medicine', 'small-unit leadership', 'rapid tactical assessment'],
                    'signature_items' => ['spear', 'bridge scars', 'Windrunner oaths'],
                    'drives' => ['protect the vulnerable', 'strip glamour off abusive authority', 'keep other protectors alive'],
                    'constraints' => ['depression cycles', 'martyr reflex', 'difficulty with ceremonial politics'],
                ],
            ]),
            'shallan' => $this->upsertEntity('Shallan Davar', [
                'public_title' => 'Scholar-artist-spy whose layered identity makes her ideal and dangerous in a crossover archive.',
                'entity_type' => 'character',
                'entity_sub_type' => 'field researcher and infiltrator',
                'summary' => $this->rich("Shallan excels wherever knowledge, presentation, and self-invention overlap. The same talent that lets her map impossible phenomena also lets her disappear into roles other people underestimate.\n\nInside this setting she becomes one of the first people to understand that the Grey Line is partly narrative: it privileges what can be named, masked, or sincerely performed long enough to become structural."),
                'public_summary' => $this->rich("A brilliant pattern-breaker who treats identity, art, and espionage as adjacent disciplines."),
                'source_universes' => [SourceUniverse::STORMLIGHT],
                'origin_type' => 'canon transplant',
                'canon_deviation' => 'Her research and persona work get redirected into crossover cartography and archive infiltration.',
                'origin_notes' => $this->rich("Shallan’s humor, instability, and creativity remain central here. The divergence is that she is given a bureaucratic labyrinth weird enough to reward her in exactly the wrong ways.\n\nShe is often the first to understand a symbolic problem and the last to admit what it cost her to solve."),
                'status' => 'active',
                'type_status' => 'main cast',
                'visibility' => VisibilityLevel::PUBLIC_KNOWLEDGE,
                'content_classification' => ContentClassification::PUBLIC,
                'published_at' => $published,
                'power_tier_ceiling' => 'continental',
                'power_tier_operating' => 'regional',
                'power_tier_influence' => 'global',
                'public_persona' => [
                    'surface' => 'Charming, quick, bright, often disarming people before they realize it.',
                    'reputation' => 'The one who can sketch a problem fast enough to expose what everyone else missed.',
                ],
                'true_nature' => [
                    'core_truth' => 'She survives by arranging truth into tolerable shapes.',
                    'private_conflicts' => [
                        'Uses performance both as tool and anesthesia.',
                        'Is drawn to dangerous symbolic systems because they feel like home.',
                    ],
                ],
                'persona_divergence' => 'Her wit often hides active fragmentation rather than simple confidence.',
                'attributes' => [
                    'skills' => ['illusion work', 'pattern recognition', 'artistic memory', 'social infiltration'],
                    'signature_items' => ['sketchbook', 'self-authored cover identities'],
                    'drives' => ['make the impossible legible', 'stay useful enough to stay included', 'control the terms of revelation'],
                    'constraints' => ['identity strain', 'avoidance through performance'],
                ],
            ]),
            'seraphine' => $this->upsertEntity('Seraphine Morbraith', [
                'public_title' => 'Grey Line handler, institutional conscience, and original-world pressure point.',
                'entity_type' => 'character',
                'entity_sub_type' => 'original archive operative',
                'summary' => $this->rich("Seraphine is the original-world character most shaped by the Grey Line itself. She is precise, eerily kind in crisis, and frighteningly willing to compartmentalize her own damage if it keeps a system functioning one more day.\n\nShe is not the villain of the archive material, but she is absolutely one of the people most likely to choose a survivable wrong answer before risking an uncontrollable right one."),
                'public_summary' => $this->rich("A disciplined Grey Line operative whose competence and secrecy are almost impossible to disentangle."),
                'source_universes' => [SourceUniverse::ORIGINAL, SourceUniverse::HARRY_POTTER],
                'origin_type' => 'original crossover native',
                'canon_deviation' => 'Original to the AU; designed to absorb the moral weight canon protagonists would rather refuse.',
                'origin_notes' => $this->rich("Seraphine is built to feel like someone who could plausibly emerge from the debris field between British magical bureaucracy and crossworld necessity. She is a systems person forced to impersonate a caretaker while becoming one for real.\n\nHer best scenes come from the tension between tenderness and professional cruelty."),
                'status' => 'active',
                'type_status' => 'main cast',
                'visibility' => VisibilityLevel::SECRET,
                'content_classification' => ContentClassification::RESTRICTED,
                'power_tier_ceiling' => 'regional',
                'power_tier_operating' => 'regional',
                'power_tier_influence' => 'global',
                'public_persona' => [
                    'surface' => 'Measured, humane, never louder than necessary.',
                    'reputation' => 'The person who can end an argument by asking the right small question.',
                ],
                'true_nature' => [
                    'core_truth' => 'She trusts procedure most when she is closest to breaking.',
                    'private_conflicts' => [
                        'Believes transparency can become a weapon in the wrong room.',
                        'Has normalized carrying morally corrosive tasks alone.',
                    ],
                ],
                'persona_divergence' => 'Her patience is partly real and partly a containment mechanism.',
                'attributes' => [
                    'skills' => ['containment policy', 'ritualized secrecy', 'archive triage', 'interpersonal de-escalation'],
                    'signature_items' => ['lantern-marked field seals', 'black ledger tabs'],
                    'drives' => ['keep crossings stable', 'minimize civilian damage', 'avoid making martyrs of the heroic'],
                    'constraints' => ['secrecy dependence', 'chronic self-erasure'],
                ],
            ]),
            'hoid' => $this->upsertEntity('Hoid', [
                'public_title' => 'Persistent cosmere nuisance, observer, and unnervingly helpful destabilizer.',
                'entity_type' => 'character',
                'entity_sub_type' => 'interworld agent',
                'summary' => $this->rich("Hoid appears wherever a story is about to become too simple. In this site data he is less a mentor than a catalytic irritant: useful, informed, and impossible to trust cleanly.\n\nHe takes immediate interest in the Grey Line because it behaves like a wound in narrative infrastructure, which in turn makes him curious, amused, and perhaps a little worried."),
                'public_summary' => $this->rich("An interworld operator who treats sealed systems like invitations."),
                'source_universes' => [SourceUniverse::COSMERE, SourceUniverse::STORMLIGHT],
                'origin_type' => 'canon transplant',
                'canon_deviation' => 'Hoid becomes materially involved in containment work rather than only orbiting it.',
                'origin_notes' => $this->rich("He remains evasive, verbally theatrical, and selectively sincere. The main divergence is proximity: instead of being glimpsed at the edge of events, he keeps showing up in meetings where everyone wishes he would stop being right sideways."),
                'status' => 'active',
                'type_status' => 'support cast',
                'visibility' => VisibilityLevel::SECRET,
                'content_classification' => ContentClassification::RESTRICTED,
                'power_tier_ceiling' => 'cosmic',
                'power_tier_operating' => 'regional',
                'power_tier_influence' => 'universal',
                'public_persona' => [
                    'surface' => 'Flippant storyteller with inexplicable access.',
                    'reputation' => 'Never where he should be, always where it matters.',
                ],
                'true_nature' => [
                    'core_truth' => 'He curates outcomes more often than he admits.',
                    'private_conflicts' => [
                        'Interested in people and abstractions at the same time.',
                        'Rarely says the whole truth when the partial truth will move the board faster.',
                    ],
                ],
                'persona_divergence' => 'The comedy is a delivery system for selective intervention.',
                'attributes' => [
                    'skills' => ['worldhopping', 'story pressure manipulation', 'social misdirection'],
                    'signature_items' => ['impossible timing', 'weaponized metaphor'],
                    'drives' => ['observe structural anomalies', 'nudge key people', 'avoid direct explanations'],
                    'constraints' => ['withholds clarity on principle'],
                ],
            ]),
            'syl' => $this->upsertEntity('Sylphrena', [
                'public_title' => 'Wind-spren companion who reacts to Hogwarts magic with bright suspicion and immediate curiosity.',
                'entity_type' => 'spirit',
                'entity_sub_type' => 'honorspren',
                'summary' => $this->rich("Syl gives the archive setting motion, conscience, and a refusal to let solemn people hide behind solemnity. She notices emotional truth faster than most humans notice the room.\n\nHer interactions with British magic are especially useful because she can tell when a ritual is formally correct but spiritually rotten."),
                'public_summary' => $this->rich("A sharp-hearted honorspren whose moral instincts make hidden rot hard to ignore."),
                'source_universes' => [SourceUniverse::STORMLIGHT],
                'origin_type' => 'canon transplant',
                'canon_deviation' => 'She gains prolonged exposure to wand-work and archive ritual culture.',
                'origin_notes' => $this->rich("Syl remains funny, affectionate, and ethically blunt. The crossover value is that she reads symbolic systems almost like weather and immediately distrusts anything that asks people to become less themselves in order to stay safe."),
                'status' => 'active',
                'type_status' => 'support cast',
                'visibility' => VisibilityLevel::PUBLIC_KNOWLEDGE,
                'content_classification' => ContentClassification::PUBLIC,
                'published_at' => $published,
                'power_tier_ceiling' => 'regional',
                'power_tier_operating' => 'regional',
                'power_tier_influence' => 'local',
                'public_persona' => [
                    'surface' => 'Playful, needling, emotionally direct.',
                    'reputation' => 'The spirit who ruins bad arguments by refusing their premise.',
                ],
                'true_nature' => [
                    'core_truth' => 'Syl is a better moral diagnostic than most formal ethics systems.',
                    'private_conflicts' => [
                        'Feels archive secrecy as an atmospheric pressure change.',
                    ],
                ],
                'persona_divergence' => 'Very little. Her openness is one of the few reliable constants in the setting.',
                'attributes' => [
                    'skills' => ['spren perception', 'moral intuition', 'Windrunner support'],
                    'signature_items' => ['shape-shifting spear form', 'incisive interruptions'],
                    'drives' => ['keep Kaladin honest', 'protect living bonds', 'mock needless pomp'],
                    'constraints' => ['cannot be fully understood by institutions built for paperwork'],
                ],
            ]),
            'hogwarts' => $this->upsertEntity('Hogwarts Castle', [
                'public_title' => 'A magical fortress rebuilt after war and reinterpreted as a living threshold site.',
                'entity_type' => 'location',
                'entity_sub_type' => 'school fortress',
                'summary' => $this->rich("Hogwarts is treated here as both institution and engine. Its wards, habits, and accumulated symbolic weight make it one of the few places capable of surviving repeated Grey Line contact without collapsing into pure anomaly.\n\nThat does not mean it is safe. It means the danger expresses itself in architecture, memory pressure, and selective permission."),
                'public_summary' => $this->rich("A ward-heavy magical fortress whose history makes it unusually resilient to crossover stress."),
                'source_universes' => [SourceUniverse::HARRY_POTTER],
                'origin_type' => 'canon location',
                'canon_deviation' => 'The castle becomes an active threshold ecology rather than remaining solely a school.',
                'origin_notes' => $this->rich("The building is still old, opinionated, and quietly theatrical. The divergence is metaphysical emphasis: repeated crossings make the castle act more like a participant in containment than a passive backdrop."),
                'status' => 'active',
                'type_status' => 'major setting',
                'visibility' => VisibilityLevel::PUBLIC_KNOWLEDGE,
                'content_classification' => ContentClassification::PUBLIC,
                'published_at' => $published,
                'space_type' => 'terrestrial',
                'existence_conditions' => ['Scottish Highlands ward lattice remains intact', 'Castle must remain symbolically inhabited'],
                'control_state' => 'protected_academic_site',
                'true_nature' => [
                    'core_truth' => 'The castle rewards truthful purpose more consistently than formal authority.',
                    'crossworld_role' => 'Acts as a stabilizer when ritual and intention align.',
                ],
                'attributes' => [
                    'features' => ['moving architecture', 'deep ward lattice', 'residual battle trauma'],
                    'crossover_traits' => ['accepts oath-like resonance', 'amplifies symbolic breaches'],
                    'anchor_region' => 'Scottish Highlands',
                ],
            ]),
            'forest' => $this->upsertEntity('Forbidden Forest', [
                'public_title' => 'A liminal woodland where old magic remains less interested in human governance than human maps suggest.',
                'entity_type' => 'location',
                'entity_sub_type' => 'liminal forest',
                'summary' => $this->rich("The Forest is one of the easiest places for the Grey Line to snag because it already tolerates contradictory rule sets. It belongs to Hogwarts only in the way a storm belongs to the valley it keeps revisiting.\n\nCharacters use it for rituals, meetings, concealment, and bad decisions made in good faith."),
                'public_summary' => $this->rich("A liminal magical woodland that absorbs crossover strain better than most civilized ground."),
                'source_universes' => [SourceUniverse::HARRY_POTTER],
                'origin_type' => 'canon location',
                'canon_deviation' => 'Its liminality becomes structurally relevant rather than atmospheric.',
                'origin_notes' => $this->rich("The forest stays ancient, difficult, and morally uninterested in school politics. The crossover shift is that it becomes one of the first places where Rosharan and British magical logic can coexist without immediate rupture."),
                'status' => 'active',
                'type_status' => 'major setting',
                'visibility' => VisibilityLevel::PUBLIC_KNOWLEDGE,
                'content_classification' => ContentClassification::PUBLIC,
                'published_at' => $published,
                'space_type' => 'terrestrial',
                'existence_conditions' => ['Maintains liminal tolerance while Hogwarts wards hold'],
                'true_nature' => [
                    'core_truth' => 'The forest is less a place of prohibition than a place of honest consequence.',
                ],
                'attributes' => [
                    'features' => ['ritual clearings', 'creature territories', 'threshold pockets'],
                    'anchor_region' => 'Hogwarts grounds',
                ],
            ]),
            'urithiru' => $this->upsertEntity('Urithiru', [
                'public_title' => 'Tower city, oath-heavy command node, and Rosharan counterpart to Hogwarts as living infrastructure.',
                'entity_type' => 'location',
                'entity_sub_type' => 'tower city',
                'summary' => $this->rich("Urithiru matters in the crossover because it is another place where stone, purpose, and long-term intention have accumulated into a kind of civic metaphysics. It does not feel like Hogwarts, but it rhymes with it.\n\nWhen the archive material moves to Urithiru, the tone shifts from haunted institution to pressured command center."),
                'public_summary' => $this->rich("A tower city whose oath-saturated infrastructure makes it a natural counterpart to Hogwarts in crossover work."),
                'source_universes' => [SourceUniverse::STORMLIGHT],
                'origin_type' => 'canon location',
                'canon_deviation' => 'Its strategic role expands into interworld diplomacy and containment.',
                'origin_notes' => $this->rich("Urithiru remains large, strategic, and spiritually loaded. In the crossover it becomes the only non-Hogwarts site with enough metaphysical backbone to host prolonged Grey Line adjudication."),
                'status' => 'active',
                'type_status' => 'major setting',
                'visibility' => VisibilityLevel::PUBLIC_KNOWLEDGE,
                'content_classification' => ContentClassification::PUBLIC,
                'published_at' => $published,
                'space_type' => 'tower_city',
                'existence_conditions' => ['Tower systems remain supplied', 'Oathgate nexus remains stable'],
                'true_nature' => [
                    'core_truth' => 'The city favors declared purpose and punishes quiet bad faith.',
                ],
                'attributes' => [
                    'features' => ['Oathgate nexus', 'command terraces', 'spren-sensitive corridors'],
                    'anchor_region' => 'Roshar mountain tower nexus',
                ],
            ]),
            'shattered_plains' => $this->upsertEntity('Shattered Plains', [
                'public_title' => 'Fractured war landscape used as a comparative model for Grey Line terrain instability.',
                'entity_type' => 'location',
                'entity_sub_type' => 'broken plateau region',
                'summary' => $this->rich("The Plains are less central as a present-time location than as a vocabulary source. Archive researchers keep returning to them whenever they need to describe spaces that are navigable only through repetition, cost, and incomplete maps.\n\nThey also matter because Kaladin and Shallan read every new fracture against them."),
                'public_summary' => $this->rich("A broken plateau region that becomes the archive’s default analogy for unstable crossover terrain."),
                'source_universes' => [SourceUniverse::STORMLIGHT],
                'origin_type' => 'canon location',
                'canon_deviation' => 'Used more heavily as conceptual and tactical reference material than in canon.',
                'origin_notes' => $this->rich("The Plains remain a ruin organized around old violence and logistics. Their archive role is comparative: every impossible crossing is measured against what war taught these characters about broken geography."),
                'status' => 'active',
                'type_status' => 'support setting',
                'visibility' => VisibilityLevel::PUBLIC_KNOWLEDGE,
                'content_classification' => ContentClassification::PUBLIC,
                'published_at' => $published,
                'space_type' => 'plateau_field',
                'existence_conditions' => ['Plateau routes remain navigable', 'Storm cycles do not erase current markers'],
                'true_nature' => [
                    'core_truth' => 'The place teaches characters to think in routes, losses, and partial bridges.',
                ],
                'attributes' => [
                    'features' => ['plateau runs', 'chasm routes', 'weather-scarred sightlines'],
                    'anchor_region' => 'Roshar warcamp fracture basin',
                ],
            ]),
            'mirrorbranch' => $this->upsertEntity('Mirrorbranch Corridor', [
                'public_title' => 'An original convergence point where oath pressure, ward memory, and narrative symmetry all overlap.',
                'entity_type' => 'convergence_point',
                'entity_sub_type' => 'crossworld threshold',
                'summary' => $this->rich("Mirrorbranch is where the site stops pretending crossovers are just transit. It is a built threshold that behaves like a wound learning how to scar.\n\nIt is stable enough for planned use and unstable enough that every planned use changes it."),
                'public_summary' => $this->rich("A purpose-built threshold corridor whose stability depends on emotional and symbolic conditions, not just ritual technique."),
                'source_universes' => [SourceUniverse::ORIGINAL, SourceUniverse::HARRY_POTTER, SourceUniverse::STORMLIGHT],
                'origin_type' => 'original crossover infrastructure',
                'canon_deviation' => 'Original to the AU.',
                'origin_notes' => $this->rich("This location exists to give the crossover a place that feels designed, dangerous, and morally expensive. It should never feel like easy portal fiction."),
                'status' => 'active',
                'type_status' => 'critical infrastructure',
                'visibility' => VisibilityLevel::SECRET,
                'content_classification' => ContentClassification::RESTRICTED,
                'space_type' => 'threshold_architecture',
                'existence_conditions' => ['Anchor rings remain mirrored', 'Route witnesses remain emotionally coherent'],
                'true_nature' => [
                    'core_truth' => 'The corridor remembers promise, grief, and repetition more strongly than it remembers maps.',
                ],
                'attributes' => [
                    'features' => ['reflective lattice', 'oath echo seams', 'ritual anchor rings'],
                    'anchor_region' => 'Grey Line subspace',
                ],
            ]),
            'order' => $this->upsertEntity('Order of the Phoenix', [
                'public_title' => 'A war-forged resistance network repurposed for threshold security and survivor-centered intervention.',
                'entity_type' => 'faction',
                'entity_sub_type' => 'resistance network',
                'summary' => $this->rich("The Order survives into the crossover material as a lean, stubborn network that distrusts empire and paperwork in equal measure. It is useful because it remembers how quickly emergency structures can become abusive when no one audits them from the inside."),
                'public_summary' => $this->rich("A survivor-led resistance network now serving as one of the site’s ethical counterweights."),
                'source_universes' => [SourceUniverse::HARRY_POTTER],
                'origin_type' => 'canon faction',
                'canon_deviation' => 'It remains active in post-war crossover containment work.',
                'status' => 'active',
                'type_status' => 'major faction',
                'visibility' => VisibilityLevel::PUBLIC_KNOWLEDGE,
                'content_classification' => ContentClassification::PUBLIC,
                'published_at' => $published,
                'control_state' => 'distributed network',
                'true_nature' => ['core_truth' => 'The Order’s biggest strength is moral memory.'],
                'attributes' => ['focus' => ['civilian protection', 'counter-secrecy oversight', 'field response']],
            ]),
            'radiants' => $this->upsertEntity('Knights Radiant', [
                'public_title' => 'Oathbound coalition bringing Rosharan ethical structure into crossover crisis response.',
                'entity_type' => 'faction',
                'entity_sub_type' => 'oathbound coalition',
                'summary' => $this->rich("The Radiants enter the archive material carrying power, symbolism, and dangerously high expectations. They are often the only people in the room equipped to handle large-scale metaphysical failure, and therefore at constant risk of being used as a moral shortcut by everyone else."),
                'public_summary' => $this->rich("An oathbound coalition whose power and ethics reshape how the archive understands responsibility."),
                'source_universes' => [SourceUniverse::STORMLIGHT],
                'origin_type' => 'canon faction',
                'canon_deviation' => 'Their remit expands into interworld crisis response and adjudication.',
                'status' => 'active',
                'type_status' => 'major faction',
                'visibility' => VisibilityLevel::PUBLIC_KNOWLEDGE,
                'content_classification' => ContentClassification::PUBLIC,
                'published_at' => $published,
                'control_state' => 'oathbound coalition',
                'true_nature' => ['core_truth' => 'Their ideals make them powerful and manipulable in equal measure.'],
                'attributes' => ['focus' => ['containment', 'defense', 'ethical force projection']],
            ]),
            'grey_line_accord' => $this->upsertEntity('Grey Line Accord', [
                'public_title' => 'The standing agreement governing sanctioned crossings, disclosure, and use-of-force across the threshold.',
                'entity_type' => 'organization',
                'entity_sub_type' => 'crossworld treaty regime',
                'summary' => $this->rich("The Accord is part bureaucracy, part promise-engine. It exists because everybody involved understands that without explicit rules the strongest available magic will quietly become the law.\n\nIt is also fragile, because many of its signatories were shaped by worlds where institutional trust has already failed them."),
                'public_summary' => $this->rich("A treaty-backed governance body trying to keep threshold work from becoming conquest by another name."),
                'source_universes' => [SourceUniverse::ORIGINAL, SourceUniverse::HARRY_POTTER, SourceUniverse::STORMLIGHT],
                'origin_type' => 'original crossover institution',
                'canon_deviation' => 'Original to the AU.',
                'status' => 'active',
                'type_status' => 'governing body',
                'visibility' => VisibilityLevel::SECRET,
                'content_classification' => ContentClassification::RESTRICTED,
                'control_state' => 'treaty regime',
                'true_nature' => ['core_truth' => 'It is only as ethical as the people willing to slow it down.'],
                'attributes' => ['focus' => ['contact law', 'crossing permissions', 'dispute mediation']],
            ]),
            'mirror_archive' => $this->upsertEntity('Mirror Archive', [
                'public_title' => 'The operational archive tasked with documenting, containing, and ethically surviving Grey Line traffic.',
                'entity_type' => 'organization',
                'entity_sub_type' => 'containment archive',
                'summary' => $this->rich("The Mirror Archive is where ideals hit logistics. Its people write procedures, bury mistakes, save lives, and sometimes do all three in the same afternoon.\n\nThe organization is most interesting when it is trying very hard to remain humane while running on triage logic."),
                'public_summary' => $this->rich("A containment archive whose daily work sits uneasily between care, secrecy, and survival."),
                'source_universes' => [SourceUniverse::ORIGINAL],
                'origin_type' => 'original institution',
                'canon_deviation' => 'Original to the AU.',
                'status' => 'active',
                'type_status' => 'operational arm',
                'visibility' => VisibilityLevel::SECRET,
                'content_classification' => ContentClassification::RESTRICTED,
                'control_state' => 'archive command',
                'true_nature' => ['core_truth' => 'Its greatest danger is learning to justify every compromise as temporary.'],
                'attributes' => ['focus' => ['containment', 'record-keeping', 'field intervention', 'damage audit']],
            ]),
            'grey_line_weaving' => $this->upsertEntity('Grey Line Weaving', [
                'public_title' => 'The original threshold practice that turns ward logic, oath pressure, and mirrored intent into stable transit.',
                'entity_type' => 'power_system',
                'entity_sub_type' => 'crossover ritual system',
                'summary' => $this->rich("Grey Line Weaving is not native magic from either canon. It is a stitched discipline that only exists because multiple systems are rubbing against one another hard enough to produce repeatable behavior.\n\nIt rewards precision, intent, and emotional compartmentalization. That last trait is why everyone sensible distrusts it."),
                'public_summary' => $this->rich("A stitched crossover ritual system built from ward logic, oath pressure, and mirrored symbolic anchors."),
                'source_universes' => [SourceUniverse::ORIGINAL, SourceUniverse::HARRY_POTTER, SourceUniverse::STORMLIGHT],
                'origin_type' => 'original hybrid system',
                'canon_deviation' => 'Original to the AU.',
                'status' => 'active',
                'type_status' => 'system core',
                'visibility' => VisibilityLevel::SECRET,
                'content_classification' => ContentClassification::RESTRICTED,
                'true_nature' => ['core_truth' => 'It works best when its practitioners are least healthy about what they are suppressing.'],
                'attributes' => ['principles' => ['mirrored anchors', 'promise resonance', 'controlled narrative symmetry']],
            ]),
            'surgebinding' => $this->upsertEntity('Surgebinding', [
                'public_title' => 'Rosharan Invested practice interpreted through the archive as oath-anchored force expression.',
                'entity_type' => 'power_system',
                'entity_sub_type' => 'invested art',
                'summary' => $this->rich("Surgebinding enters the archive records as a system that resists purely instrumental reading. It is powerful, but it is also relational, ideal-driven, and unusually sensitive to the moral condition of its users."),
                'public_summary' => $this->rich("An oath-anchored power system that makes archive pragmatists deeply uncomfortable."),
                'source_universes' => [SourceUniverse::STORMLIGHT],
                'origin_type' => 'canon system',
                'status' => 'active',
                'type_status' => 'reference system',
                'visibility' => VisibilityLevel::PUBLIC_KNOWLEDGE,
                'content_classification' => ContentClassification::PUBLIC,
                'published_at' => $published,
                'true_nature' => ['core_truth' => 'The archive keeps trying to reduce it to mechanics and keeps failing.'],
                'attributes' => ['principles' => ['oaths', 'spren bonds', 'stormlight mediation']],
            ]),
            'wand_magic' => $this->upsertEntity('Wand Magic', [
                'public_title' => 'The British magical tradition as formalized through tools, spell forms, and social inheritance.',
                'entity_type' => 'magic_system',
                'entity_sub_type' => 'wand-focused spellcraft',
                'summary' => $this->rich("The archive treats wand magic as both elegant and dangerously normalized. Its users often forget how many assumptions are embedded in what they call ordinary spellwork.\n\nCrossworld comparison exposes which parts are physics, which are pedagogy, and which are political habit."),
                'public_summary' => $this->rich("A highly formalized magical tradition whose ordinary status hides a great deal of cultural bias."),
                'source_universes' => [SourceUniverse::HARRY_POTTER],
                'origin_type' => 'canon system',
                'status' => 'active',
                'type_status' => 'reference system',
                'visibility' => VisibilityLevel::PUBLIC_KNOWLEDGE,
                'content_classification' => ContentClassification::PUBLIC,
                'published_at' => $published,
                'true_nature' => ['core_truth' => 'Its convenience can obscure how much social power it quietly concentrates.'],
                'attributes' => ['principles' => ['wand focus', 'incantation memory', 'tradition-shaped pedagogy']],
            ]),
            'lantern_compass' => $this->upsertEntity('Lantern Compass', [
                'public_title' => 'A custom archive artifact that translates emotional drift into navigational warning.',
                'entity_type' => 'artifact',
                'entity_sub_type' => 'threshold instrument',
                'summary' => $this->rich("The Lantern Compass is one of the first original tools the archive builds that does not just survive threshold pressure but interprets it. It glows warmer as a room gets more emotionally dishonest about what it is trying to do."),
                'public_summary' => $this->rich("An original threshold instrument that reads emotional and symbolic drift as navigational data."),
                'source_universes' => [SourceUniverse::ORIGINAL],
                'origin_type' => 'original artifact',
                'status' => 'active',
                'type_status' => 'support tool',
                'visibility' => VisibilityLevel::SECRET,
                'content_classification' => ContentClassification::RESTRICTED,
                'true_nature' => ['core_truth' => 'It is trusted because it is harder to bully than people are.'],
                'attributes' => ['capabilities' => ['resonance warning', 'route verification', 'emotional drift readout']],
            ]),
            'timeline_main' => $this->upsertEntity('Convergence: Hogwarts-Roshar', [
                'public_title' => 'Primary crossover timeline tracking stable contact between Hogwarts, Urithiru, and the Grey Line.',
                'entity_type' => 'timeline',
                'entity_sub_type' => 'primary continuity',
                'summary' => $this->rich("The main convergence timeline follows the transition from accidental contact to managed interworld diplomacy. It is the most useful timeline for readers who want the site’s baseline story logic without diving into every pressure fracture."),
                'public_summary' => $this->rich("Primary continuity for the Hogwarts-Roshar convergence arc."),
                'source_universes' => [SourceUniverse::HARRY_POTTER, SourceUniverse::STORMLIGHT, SourceUniverse::ORIGINAL],
                'origin_type' => 'authorial continuity spine',
                'status' => 'active',
                'type_status' => 'primary timeline',
                'visibility' => VisibilityLevel::PUBLIC_KNOWLEDGE,
                'content_classification' => ContentClassification::PUBLIC,
                'published_at' => $published,
            ]),
            'timeline_pressure' => $this->upsertEntity('Pressure Sequence: Grey Line Tribunal', [
                'public_title' => 'A narrower timeline isolating the moral and political buildup to the Urithiru tribunal.',
                'entity_type' => 'timeline',
                'entity_sub_type' => 'focused continuity',
                'summary' => $this->rich("This timeline exists because the tribunal arc is not just an event but a pressure chamber. It tracks how secrets, theory, and emotional debt pile up until procedure is no longer neutral."),
                'public_summary' => $this->rich("Focused continuity for the Grey Line Tribunal buildup and fallout."),
                'source_universes' => [SourceUniverse::STORMLIGHT, SourceUniverse::ORIGINAL],
                'origin_type' => 'authorial pressure track',
                'status' => 'active',
                'type_status' => 'focused timeline',
                'visibility' => VisibilityLevel::PUBLIC_KNOWLEDGE,
                'content_classification' => ContentClassification::PUBLIC,
                'published_at' => $published,
            ]),
            'event_crossing' => $this->upsertEntity('The First Stable Crossing', [
                'public_title' => 'The first repeatable passage that did not shear memory, identity, or magical coherence apart.',
                'entity_type' => 'event',
                'entity_sub_type' => 'threshold breakthrough',
                'summary' => $this->rich("The crossing succeeds because the people involved finally stop treating transit like extraction and start treating it like consent under impossible conditions.\n\nIt is a technical success with immediate ethical consequences."),
                'public_summary' => $this->rich("The first controlled crossing that proved stable contact was possible."),
                'source_universes' => [SourceUniverse::ORIGINAL, SourceUniverse::HARRY_POTTER, SourceUniverse::STORMLIGHT],
                'origin_type' => 'original crossover event',
                'status' => 'recorded',
                'type_status' => 'pivotal event',
                'visibility' => VisibilityLevel::PUBLIC_KNOWLEDGE,
                'content_classification' => ContentClassification::PUBLIC,
                'published_at' => $published,
                'true_nature' => ['core_truth' => 'The method worked because the participants told the truth about the risk.'],
                'attributes' => ['stakes' => ['identity integrity', 'return path viability', 'crossworld disclosure']],
            ]),
            'event_exchange' => $this->upsertEntity('The Spanreed Owl Exchange', [
                'public_title' => 'The first successful long-range communication chain translating Rosharan spanreeds through Hogwarts logistics.',
                'entity_type' => 'event',
                'entity_sub_type' => 'communications milestone',
                'summary' => $this->rich("Half diplomatic breakthrough, half absurd logistics triumph. The exchange matters because it proves regular contact can exist without immediate physical transit, which in turn lowers the moral cost of every future decision."),
                'public_summary' => $this->rich("The first stable communications bridge between Hogwarts and Urithiru."),
                'source_universes' => [SourceUniverse::HARRY_POTTER, SourceUniverse::STORMLIGHT],
                'origin_type' => 'original crossover event',
                'status' => 'recorded',
                'type_status' => 'major event',
                'visibility' => VisibilityLevel::PUBLIC_KNOWLEDGE,
                'content_classification' => ContentClassification::PUBLIC,
                'published_at' => $published,
                'true_nature' => ['core_truth' => 'A silly-looking success that quietly prevents wars.'],
                'attributes' => ['stakes' => ['reliable coordination', 'translation protocol', 'distance-safe trust building']],
            ]),
            'event_tribunal' => $this->upsertEntity('Grey Line Tribunal at Urithiru', [
                'public_title' => 'The adjudication that forces the archive to decide whether procedure serves ethics or replaces it.',
                'entity_type' => 'conflict',
                'entity_sub_type' => 'political tribunal',
                'summary' => $this->rich("The tribunal is not a courtroom scene so much as a systems stress test. Everybody arrives with good reasons, bad incentives, and incompatible understandings of what responsible power looks like.\n\nIt is the moment the setting stops being about contact and becomes about governance."),
                'public_summary' => $this->rich("A high-pressure political conflict over secrecy, responsibility, and control of the Grey Line."),
                'source_universes' => [SourceUniverse::STORMLIGHT, SourceUniverse::ORIGINAL],
                'origin_type' => 'original crossover conflict',
                'status' => 'recorded',
                'type_status' => 'pivotal conflict',
                'visibility' => VisibilityLevel::PUBLIC_KNOWLEDGE,
                'content_classification' => ContentClassification::PUBLIC,
                'published_at' => $published,
                'true_nature' => ['core_truth' => 'The real argument is about who gets to define acceptable sacrifice.'],
                'attributes' => ['stakes' => ['archive legitimacy', 'shared rule-making', 'exposure of buried compromises']],
            ]),
            'event_duel' => $this->upsertEntity('Duel Under the Broken Canopy', [
                'public_title' => 'A contained but symbolically explosive confrontation proving the threshold now reacts to interpersonal truth.',
                'entity_type' => 'conflict',
                'entity_sub_type' => 'ritualized confrontation',
                'summary' => $this->rich("The duel is memorable because nobody involved is primarily trying to win by force. They are trying to force the threshold to stop accepting a lie.\n\nIt is intimate, ugly, and more useful than any committee memo."),
                'public_summary' => $this->rich("A symbolic confrontation that proves the threshold now responds to emotional truth as much as ritual design."),
                'source_universes' => [SourceUniverse::ORIGINAL, SourceUniverse::STORMLIGHT],
                'origin_type' => 'original crossover conflict',
                'status' => 'recorded',
                'type_status' => 'major conflict',
                'visibility' => VisibilityLevel::PUBLIC_KNOWLEDGE,
                'content_classification' => ContentClassification::PUBLIC,
                'published_at' => $published,
                'true_nature' => ['core_truth' => 'The Grey Line starts behaving more honestly than the institutions around it.'],
                'attributes' => ['stakes' => ['truth admission', 'ritual control', 'team cohesion']],
            ]),
        ];
    }

    private function seedRelationships(array $entities): array
    {
        return [
            'harry_hermione' => $this->upsertRelationship($entities['harry'], $entities['hermione'], 'knowledge', [
                'direction' => 'mutual_equal',
                'perspective_a' => $this->rich('Harry relies on Hermione to name what he already knows is wrong.'),
                'perspective_b' => $this->rich('Hermione trusts Harry’s instincts most when institutions start sounding tidy.'),
                'current_tension_charge' => 'positive',
                'strength_from_a' => 9,
                'strength_from_b' => 10,
                'relationship_history' => [['era' => 'post-war', 'shift' => 'friendship hardens into operational trust']],
                'notes' => $this->rich('This relationship is the archive’s most stable human decision-making pair.'),
                'visibility' => VisibilityLevel::PUBLIC_KNOWLEDGE,
                'content_classification' => ContentClassification::PUBLIC,
            ]),
            'harry_kaladin' => $this->upsertRelationship($entities['harry'], $entities['kaladin'], 'crossover', [
                'direction' => 'mutual_equal',
                'perspective_a' => $this->rich('Harry sees in Kaladin a protector who understands the cost of never standing down.'),
                'perspective_b' => $this->rich('Kaladin recognizes Harry’s leadership instincts immediately and distrusts how much they hurt him.'),
                'current_tension_charge' => 'complex',
                'strength_from_a' => 8,
                'strength_from_b' => 8,
                'relationship_history' => [['era' => 'first stable crossing', 'shift' => 'rapid battlefield trust']],
                'notes' => $this->rich('Mutual respect with a dangerous tendency toward self-sacrificial escalation.'),
                'visibility' => VisibilityLevel::PUBLIC_KNOWLEDGE,
                'content_classification' => ContentClassification::PUBLIC,
            ]),
            'kaladin_shallan' => $this->upsertRelationship($entities['kaladin'], $entities['shallan'], 'narrative', [
                'direction' => 'mutual_unequal',
                'perspective_a' => $this->rich('Kaladin reads Shallan as brilliant, brittle, and harder to guard than she appears.'),
                'perspective_b' => $this->rich('Shallan sees Kaladin as both comfort and accusation.'),
                'current_tension_charge' => 'complex',
                'strength_from_a' => 7,
                'strength_from_b' => 7,
                'relationship_history' => [['era' => 'Urithiru exchange', 'shift' => 'cooperative strain under archive scrutiny']],
                'notes' => $this->rich('The archive environment sharpens their ability to notice each other’s coping patterns.'),
                'visibility' => VisibilityLevel::PUBLIC_KNOWLEDGE,
                'content_classification' => ContentClassification::PUBLIC,
            ]),
            'seraphine_harry' => $this->upsertRelationship($entities['seraphine'], $entities['harry'], 'organizational', [
                'direction' => 'one_way',
                'perspective_a' => $this->rich('Seraphine believes Harry is morally indispensable and administratively terrifying.'),
                'perspective_b' => $this->rich('Harry trusts Seraphine’s care but suspects the costs of her process.'),
                'current_tension_charge' => 'volatile',
                'strength_from_a' => 8,
                'strength_from_b' => 6,
                'perceived_type' => 'professional trust',
                'true_type' => 'containment negotiation',
                'perception_divergence' => 'Each thinks the other is choosing the wrong kind of necessary compromise.',
                'notes' => $this->rich('One of the site’s best engines for procedural vs ethical conflict.'),
                'visibility' => VisibilityLevel::SECRET,
                'content_classification' => ContentClassification::RESTRICTED,
            ]),
            'seraphine_kaladin' => $this->upsertRelationship($entities['seraphine'], $entities['kaladin'], 'conflict', [
                'direction' => 'mutual_equal',
                'perspective_a' => $this->rich('Seraphine finds Kaladin impossible to brief around euphemism.'),
                'perspective_b' => $this->rich('Kaladin sees Seraphine as humane in the small and compromised in the large.'),
                'current_tension_charge' => 'volatile',
                'strength_from_a' => 7,
                'strength_from_b' => 8,
                'notes' => $this->rich('Both are right about the other, which is why the friction never stays simple.'),
                'visibility' => VisibilityLevel::SECRET,
                'content_classification' => ContentClassification::RESTRICTED,
            ]),
            'shallan_seraphine' => $this->upsertRelationship($entities['shallan'], $entities['seraphine'], 'knowledge', [
                'direction' => 'mutual_equal',
                'perspective_a' => $this->rich('Shallan knows Seraphine is doing identity work even when Seraphine calls it administration.'),
                'perspective_b' => $this->rich('Seraphine recognizes Shallan as both analytic asset and symbolic hazard.'),
                'current_tension_charge' => 'complex',
                'strength_from_a' => 7,
                'strength_from_b' => 7,
                'notes' => $this->rich('A relationship built on seeing too much and saying too little.'),
                'visibility' => VisibilityLevel::SECRET,
                'content_classification' => ContentClassification::RESTRICTED,
            ]),
        ];
    }

    private function seedGroupRelationships(array $entities): array
    {
        $archiveCell = $this->upsertGroupRelationship('Urithiru Exchange Cell', [
            'relationship_type' => 'crossworld taskforce',
            'dynamic_description' => $this->rich('A working cell formed to manage living contact between Hogwarts, Urithiru, and the Grey Line without surrendering ethical oversight to any one system.'),
            'current_tension_charge' => 'complex',
            'group_history' => [['phase' => 'initial contact', 'shift' => 'moved from ad hoc response to standing coordination']],
            'notes' => $this->rich('High competence, high emotional compression, very poor odds of staying simple.'),
            'visibility' => VisibilityLevel::SECRET,
            'content_classification' => ContentClassification::RESTRICTED,
        ]);

        $this->upsertGroupMember($archiveCell, $entities['harry'], 'field liaison', 'first stable crossing');
        $this->upsertGroupMember($archiveCell, $entities['hermione'], 'research and policy lead', 'first stable crossing');
        $this->upsertGroupMember($archiveCell, $entities['kaladin'], 'protective response lead', 'Urithiru exchange');
        $this->upsertGroupMember($archiveCell, $entities['shallan'], 'symbolic analysis and infiltration', 'Urithiru exchange');
        $this->upsertGroupMember($archiveCell, $entities['seraphine'], 'archive handler', 'archive phase');

        $mirrorTeam = $this->upsertGroupRelationship('Mirror Archive Night Desk', [
            'relationship_type' => 'containment team',
            'dynamic_description' => $this->rich('The small, overworked team that handles after-hours threshold incidents, disclosure failures, and morally unpleasant cleanup work.'),
            'current_tension_charge' => 'volatile',
            'group_history' => [['phase' => 'containment growth', 'shift' => 'became permanent after repeated crossings']],
            'notes' => $this->rich('A team held together by competence, black humor, and the knowledge that somebody has to stay up.'),
            'visibility' => VisibilityLevel::SECRET,
            'content_classification' => ContentClassification::RESTRICTED,
        ]);

        $this->upsertGroupMember($mirrorTeam, $entities['seraphine'], 'desk lead', 'foundational phase');
        $this->upsertGroupMember($mirrorTeam, $entities['hoid'], 'unofficial consultant', 'after tribunal');
        $this->upsertGroupMember($mirrorTeam, $entities['syl'], 'anomaly witness', 'Urithiru exchange');

        return [
            'archive_cell' => $archiveCell,
            'mirror_team' => $mirrorTeam,
        ];
    }

    private function seedFactionMemberships(array $entities): void
    {
        $this->upsertFactionMembership($entities['order'], $entities['harry'], [
            'rank_or_role' => 'field coordinator',
            'membership_status' => 'active',
            'joined_era' => 'Second War aftermath',
            'public_membership_known' => true,
            'notes' => $this->rich('Harry remains one of the Order’s most trusted operational voices.'),
            'visibility' => VisibilityLevel::PUBLIC_KNOWLEDGE,
            'content_classification' => ContentClassification::PUBLIC,
        ]);

        $this->upsertFactionMembership($entities['order'], $entities['hermione'], [
            'rank_or_role' => 'policy and research anchor',
            'membership_status' => 'active',
            'joined_era' => 'Second War aftermath',
            'public_membership_known' => true,
            'notes' => $this->rich('Hermione functions as both scholar and internal corrective.'),
            'visibility' => VisibilityLevel::PUBLIC_KNOWLEDGE,
            'content_classification' => ContentClassification::PUBLIC,
        ]);

        $this->upsertFactionMembership($entities['radiants'], $entities['kaladin'], [
            'rank_or_role' => 'Windrunner captain',
            'membership_status' => 'active',
            'joined_era' => 'Urithiru war phase',
            'public_membership_known' => true,
            'notes' => $this->rich('Kaladin represents the protective ethic of the Radiants in archive negotiations.'),
            'visibility' => VisibilityLevel::PUBLIC_KNOWLEDGE,
            'content_classification' => ContentClassification::PUBLIC,
        ]);

        $this->upsertFactionMembership($entities['radiants'], $entities['shallan'], [
            'rank_or_role' => 'Lightweaver researcher',
            'membership_status' => 'active',
            'joined_era' => 'Urithiru war phase',
            'public_membership_known' => true,
            'notes' => $this->rich('Shallan links Radiant insight to archive symbolic work.'),
            'visibility' => VisibilityLevel::PUBLIC_KNOWLEDGE,
            'content_classification' => ContentClassification::PUBLIC,
        ]);

        $this->upsertFactionMembership($entities['grey_line_accord'], $entities['harry'], [
            'rank_or_role' => 'Hogwarts liaison',
            'membership_status' => 'active',
            'joined_era' => 'first stable crossing',
            'public_membership_known' => false,
            'notes' => $this->rich('Harry signs on because somebody trustworthy needs veto power.'),
            'visibility' => VisibilityLevel::SECRET,
            'content_classification' => ContentClassification::RESTRICTED,
        ]);

        $this->upsertFactionMembership($entities['grey_line_accord'], $entities['kaladin'], [
            'rank_or_role' => 'Urithiru tactical liaison',
            'membership_status' => 'active',
            'joined_era' => 'Urithiru exchange',
            'public_membership_known' => false,
            'notes' => $this->rich('Kaladin participates mostly to keep the Accord from normalizing harm.'),
            'visibility' => VisibilityLevel::SECRET,
            'content_classification' => ContentClassification::RESTRICTED,
        ]);

        $this->upsertFactionMembership($entities['mirror_archive'], $entities['seraphine'], [
            'rank_or_role' => 'containment handler',
            'membership_status' => 'active',
            'joined_era' => 'foundational phase',
            'public_membership_known' => false,
            'notes' => $this->rich('Seraphine is one of the people the Archive most depends on and most damages.'),
            'visibility' => VisibilityLevel::SECRET,
            'content_classification' => ContentClassification::RESTRICTED,
        ]);
    }

    private function seedDocuments(array $entities): array
    {
        $documents = [];

        $documents['harry_dossier'] = $this->upsertDocument('Grey Line Dossier: Harry Potter', [
            'document_type' => 'intelligence_report',
            'document_status' => 'classified',
            'document_authenticity' => 'authentic',
            'official_narrative' => $this->rich("Officially, Harry is logged as a high-trust allied operative with unusually stable threshold survivability and strong ethical objections to coercive containment.\n\nThe dossier recommends involving him early whenever a procedural answer risks becoming a moral blind spot."),
            'true_content' => $this->rich("The real reason this file matters is that Harry changes the behavior of rooms. People become more honest around him or more defensive, and either result is useful.\n\nHe is also one of the people most likely to burn himself out before admitting a structure is too much for one person."),
            'owner_entity_id' => $entities['mirror_archive']->id,
            'official_author_entity_id' => $entities['seraphine']->id,
            'true_author_entity_id' => $entities['hermione']->id,
            'era_created' => 'first stable crossing',
            'access_level' => 'restricted',
            'known_by_entity_ids' => [$entities['seraphine']->id, $entities['hermione']->id],
            'visibility' => VisibilityLevel::SECRET,
            'content_classification' => ContentClassification::RESTRICTED,
        ]);

        $documents['kaladin_dossier'] = $this->upsertDocument('Grey Line Dossier: Kaladin Stormblessed', [
            'document_type' => 'intelligence_report',
            'document_status' => 'classified',
            'document_authenticity' => 'authentic',
            'official_narrative' => $this->rich("Kaladin is categorized as a high-trust allied protector with battlefield authority, medical value, and dangerous intolerance for euphemistic harm.\n\nThe archive advises never presenting avoidable cruelty as a technical necessity in his presence."),
            'true_content' => $this->rich("Kaladin is most valuable when he believes people are telling him the truth about stakes and least manageable when they are not. He is also quietly one of the best readers of institutional panic the Archive has ever encountered."),
            'owner_entity_id' => $entities['mirror_archive']->id,
            'official_author_entity_id' => $entities['seraphine']->id,
            'true_author_entity_id' => $entities['shallan']->id,
            'era_created' => 'Urithiru exchange',
            'access_level' => 'restricted',
            'known_by_entity_ids' => [$entities['seraphine']->id, $entities['shallan']->id],
            'visibility' => VisibilityLevel::SECRET,
            'content_classification' => ContentClassification::RESTRICTED,
        ]);

        $documents['charter'] = $this->upsertDocument('Urithiru Exchange Charter', [
            'document_type' => 'treaty',
            'document_status' => 'extant',
            'document_authenticity' => 'authentic',
            'official_narrative' => $this->rich("The Charter establishes minimum standards for crossing consent, emergency disclosure, injury response, and multi-party review of threshold activity.\n\nIt exists because everybody involved realized too late that good intentions do not scale without procedure."),
            'true_content' => $this->rich("The hidden text of the Charter is a compromise: it gives the Archive enough legitimacy to function while binding it tightly enough that Harry and Kaladin will not walk away from it immediately."),
            'owner_entity_id' => $entities['grey_line_accord']->id,
            'official_author_entity_id' => $entities['hermione']->id,
            'true_author_entity_id' => $entities['seraphine']->id,
            'era_created' => 'Urithiru exchange',
            'access_level' => 'need_to_know',
            'visibility' => VisibilityLevel::SECRET,
            'content_classification' => ContentClassification::RESTRICTED,
        ]);

        $documents['resonance'] = $this->upsertDocument('Memorandum on Investiture-Wand Resonance', [
            'document_type' => 'research_notes',
            'document_status' => 'extant',
            'document_authenticity' => 'authentic',
            'official_narrative' => $this->rich("Working memorandum documenting how wand channels and Stormlight-fed abilities interfere, amplify, or destabilize each other under controlled conditions.\n\nFindings strongly suggest intent alignment matters more than raw output when the systems overlap."),
            'true_content' => $this->rich("The dangerous conclusion is not just that the systems can harmonize. It is that emotionally compressed practitioners produce more stable results than healthy ones, which means every institution involved will be tempted to exploit distress as technique."),
            'owner_entity_id' => $entities['mirror_archive']->id,
            'official_author_entity_id' => $entities['hermione']->id,
            'true_author_entity_id' => $entities['shallan']->id,
            'era_created' => 'after first stable crossing',
            'access_level' => 'restricted',
            'visibility' => VisibilityLevel::SECRET,
            'content_classification' => ContentClassification::RESTRICTED,
        ]);

        $documents['ledger'] = $this->upsertDocument('Mirror Archive Contingency Ledger', [
            'document_type' => 'technical_document',
            'document_status' => 'classified',
            'document_authenticity' => 'authentic',
            'official_narrative' => $this->rich("A running ledger of emergency fallback routes, seal failures, personnel substitutions, and quarantine triggers."),
            'true_content' => $this->rich("The ledger is effectively a moral scar map. Every contingency represents a previous failure someone had to survive long enough to name."),
            'owner_entity_id' => $entities['mirror_archive']->id,
            'official_author_entity_id' => $entities['seraphine']->id,
            'era_created' => 'archive phase',
            'access_level' => 'handler_only',
            'visibility' => VisibilityLevel::SECRET,
            'content_classification' => ContentClassification::AUTHOR_ONLY,
        ]);

        $documents['notes_canopy'] = $this->upsertDocument('Notes from the Broken Canopy', [
            'document_type' => 'personal_journal',
            'document_status' => 'extant',
            'document_authenticity' => 'translated',
            'official_narrative' => $this->rich("A stitched account of the duel beneath the fractured threshold canopy, combining tactical notes, sketch annotations, and emotional observations."),
            'true_content' => $this->rich("The journal matters because it records the moment the Grey Line stopped behaving like a route and started behaving like a witness."),
            'owner_entity_id' => $entities['shallan']->id,
            'official_author_entity_id' => $entities['shallan']->id,
            'true_author_entity_id' => $entities['shallan']->id,
            'era_created' => 'broken canopy duel',
            'access_level' => 'trusted circle',
            'visibility' => VisibilityLevel::SECRET,
            'content_classification' => ContentClassification::RESTRICTED,
        ]);

        $this->linkDocumentEntity($documents['harry_dossier'], $entities['harry'], 'subject');
        $this->linkDocumentEntity($documents['harry_dossier'], $entities['seraphine'], 'author');
        $this->linkDocumentEntity($documents['harry_dossier'], $entities['hermione'], 'true_author');
        $this->linkDocumentEntity($documents['kaladin_dossier'], $entities['kaladin'], 'subject');
        $this->linkDocumentEntity($documents['kaladin_dossier'], $entities['shallan'], 'true_author');
        $this->linkDocumentEntity($documents['charter'], $entities['grey_line_accord'], 'subject');
        $this->linkDocumentEntity($documents['charter'], $entities['harry'], 'signatory');
        $this->linkDocumentEntity($documents['charter'], $entities['kaladin'], 'signatory');
        $this->linkDocumentEntity($documents['resonance'], $entities['grey_line_weaving'], 'subject');
        $this->linkDocumentEntity($documents['resonance'], $entities['surgebinding'], 'referenced');
        $this->linkDocumentEntity($documents['resonance'], $entities['wand_magic'], 'referenced');
        $this->linkDocumentEntity($documents['ledger'], $entities['mirror_archive'], 'subject');
        $this->linkDocumentEntity($documents['notes_canopy'], $entities['event_duel'], 'subject');

        return $documents;
    }

    private function seedEntryPoints(array $entities): array
    {
        return [
            'hp' => $this->upsertEntryPoint('Harry Potter', [
                'entry_mechanism' => $this->rich("British crossings tend to key off ward memory, ritual permission, and places already carrying narrative weight. Hogwarts is unusually permissive when intention is honest and unusually hostile when it is not."),
                'power_transition_rules' => $this->rich("Wand magic remains intact, but acts unpredictably when paired with emotionally compressed threshold work."),
                'physical_transition_rules' => $this->rich("Transit preserves body integrity if the crossing is consented to and symbolically anchored. Forced transit increases fragmentation risk sharply."),
                'memory_and_identity_rules' => $this->rich("Memory loss is less common than memory editing by context. People remember, but they remember through whatever symbolic frame the crossing privileged."),
                'psychological_transition_rules' => $this->rich("Unprocessed guilt stabilizes some crossings better than resolved peace, which is one of the archive’s central ethical alarms."),
                'canon_deviation_notes' => $this->rich("This is where Harry Potter logic becomes threshold-active rather than just local magic infrastructure."),
                'known_examples' => [$entities['harry']->id, $entities['hermione']->id],
                'known_entry_points' => [$entities['hogwarts']->id, $entities['forest']->id, $entities['mirrorbranch']->id],
                'status' => 'documented',
                'restrictions' => $this->rich("No unsupervised child crossings. No forced extraction across active grief spikes."),
                'return_rules' => $this->rich("Return is safest within seventy-two hours unless a stable anchor is established on both sides."),
                'first_documented_crossing_event_id' => $entities['event_crossing']->id,
                'visibility' => VisibilityLevel::SECRET,
                'content_classification' => ContentClassification::RESTRICTED,
            ]),
            'stormlight' => $this->upsertEntryPoint('Stormlight Archive', [
                'entry_mechanism' => $this->rich("Rosharan crossings favor oathful intent, invested thresholds, and spaces where identity is already being renegotiated."),
                'power_transition_rules' => $this->rich("Stormlight behaves better than expected across the threshold, but only while practitioners remain legible to themselves."),
                'physical_transition_rules' => $this->rich("Highstorm-conditioned bodies adapt better to threshold turbulence than baseline British casters predicted."),
                'memory_and_identity_rules' => $this->rich("Rosharan entrants tend to preserve self-continuity better than British entrants, likely because oath structures externalize identity commitments."),
                'psychological_transition_rules' => $this->rich("Broken-but-acknowledged identity travels better than polished denial."),
                'canon_deviation_notes' => $this->rich("The archive’s biggest surprise is how often Rosharan metaphysics refuses procedural bad faith."),
                'known_examples' => [$entities['kaladin']->id, $entities['shallan']->id, $entities['syl']->id],
                'known_entry_points' => [$entities['urithiru']->id, $entities['mirrorbranch']->id],
                'status' => 'documented',
                'restrictions' => $this->rich("Do not attempt crossings during large-scale oath failure events."),
                'return_rules' => $this->rich("Return paths remain viable if a counterpart anchor is maintained on the Rosharan side."),
                'first_documented_crossing_event_id' => $entities['event_crossing']->id,
                'visibility' => VisibilityLevel::SECRET,
                'content_classification' => ContentClassification::RESTRICTED,
            ]),
        ];
    }

    private function seedCanonReferences(array $entities, array $entryPoints): array
    {
        $references = [];

        $references['hp_universe'] = $this->upsertCanonReference('Harry Potter Universe', [
            'universe' => SourceUniverse::HARRY_POTTER,
            'level' => 'universe',
            'content' => $this->rich('Reference spine for British magical society, wand-based casting, ward culture, and post-war reconstruction assumptions.'),
            'universe_overview' => $this->rich("The archive treats Harry Potter canon as a world where informal moral courage repeatedly outperforms official power structures, but where those structures still shape every available tool.\n\nThat tension is exactly why it crosses so well with Grey Line material."),
            'universe_priority' => 'primary',
            'universe_depth_rating' => 'comprehensive',
            'overall_divergence_summary' => $this->rich("The AU leans older, more procedural, and more interested in institutional afterlives than canon’s epilogue framing."),
            'primary_elements_borrowed' => ['wand magic', 'ward logic', 'war aftermath', 'Hogwarts as symbolic site'],
            'primary_divergences' => ['extended post-war governance', 'threshold-active castle ecology', 'crossworld diplomacy'],
            'crossover_entry_point_id' => $entryPoints['hp']->id,
            'research_status' => 'comprehensive',
            'research_confidence' => 'verified',
            'visibility' => VisibilityLevel::SECRET,
            'content_classification' => ContentClassification::RESTRICTED,
        ]);

        $references['stormlight_universe'] = $this->upsertCanonReference('Stormlight Archive Universe', [
            'universe' => SourceUniverse::STORMLIGHT,
            'level' => 'universe',
            'content' => $this->rich('Reference spine for Rosharan oath cultures, Investiture behavior, spren ethics, and wartime command structures.'),
            'universe_overview' => $this->rich("Stormlight Archive canon enters the site as a world where power is inseparable from moral articulation and self-concept. That makes it extraordinarily useful and extraordinarily destabilizing in an archive built on secrecy."),
            'universe_priority' => 'primary',
            'universe_depth_rating' => 'solid',
            'overall_divergence_summary' => $this->rich("The AU narrows focus onto how Rosharan ethics and magic pressure archive bureaucracy rather than retelling the war whole."),
            'primary_elements_borrowed' => ['oaths', 'surgebinding', 'Urithiru', 'spren relationality'],
            'primary_divergences' => ['long-term archive presence', 'threshold diplomacy', 'comparative governance arc'],
            'crossover_entry_point_id' => $entryPoints['stormlight']->id,
            'research_status' => 'solid',
            'research_confidence' => 'solid',
            'visibility' => VisibilityLevel::SECRET,
            'content_classification' => ContentClassification::RESTRICTED,
        ]);

        $references['wand_magic'] = $this->upsertCanonReference('Wand Magic Practice', [
            'universe' => SourceUniverse::HARRY_POTTER,
            'level' => 'element',
            'title' => 'Wand Magic Practice',
            'content' => $this->rich('Element-level note on formal spellcasting through wand channels and learned incantatory structure.'),
            'element_type' => 'magic_ability',
            'canonical_properties' => [
                'focus' => 'tool-mediated casting',
                'strengths' => ['repeatability', 'breadth', 'institutional legibility'],
                'weaknesses' => ['cultural rigidity', 'assumption of normativity'],
            ],
            'first_appearance' => 'British magical education baseline',
            'source_material_references' => ['series-wide spell practice'],
            'au_entity_id' => $entities['wand_magic']->id,
            'research_status' => 'comprehensive',
            'research_confidence' => 'verified',
            'visibility' => VisibilityLevel::SECRET,
            'content_classification' => ContentClassification::RESTRICTED,
        ]);

        $references['surgebinding'] = $this->upsertCanonReference('Surgebinding Practice', [
            'universe' => SourceUniverse::STORMLIGHT,
            'level' => 'element',
            'title' => 'Surgebinding Practice',
            'content' => $this->rich('Element-level note on oath-bound invested abilities mediated through spren relationships and Stormlight.'),
            'element_type' => 'magic_ability',
            'canonical_properties' => [
                'focus' => 'bond-mediated invested expression',
                'strengths' => ['ethical anchoring', 'high mobility', 'identity coherence'],
                'weaknesses' => ['oath strain', 'dependence on self-concept'],
            ],
            'first_appearance' => 'Radiant resurgence',
            'source_material_references' => ['Rosharan surge practice'],
            'au_entity_id' => $entities['surgebinding']->id,
            'research_status' => 'solid',
            'research_confidence' => 'solid',
            'visibility' => VisibilityLevel::SECRET,
            'content_classification' => ContentClassification::RESTRICTED,
        ]);

        $references['hogwarts'] = $this->upsertCanonReference('Hogwarts as Threshold Site', [
            'universe' => SourceUniverse::HARRY_POTTER,
            'level' => 'element',
            'title' => 'Hogwarts as Threshold Site',
            'content' => $this->rich('AU-specific element note explaining why Hogwarts becomes structurally important to stable crossing work.'),
            'element_type' => 'location',
            'canonical_properties' => [
                'canon_role' => 'magical school and fortress',
                'au_role' => 'threshold stabilizer',
                'reason' => 'symbolic weight plus ward resilience',
            ],
            'first_appearance' => 'post-war crossover phase',
            'source_material_references' => ['Battle of Hogwarts aftermath', 'castle ward culture'],
            'au_entity_id' => $entities['hogwarts']->id,
            'research_status' => 'developing',
            'research_confidence' => 'solid',
            'visibility' => VisibilityLevel::SECRET,
            'content_classification' => ContentClassification::RESTRICTED,
        ]);

        $this->linkCanonReferenceEntity($references['hp_universe'], $entities['harry'], 'moderate', 'references', 'Harry remains the main ethical bridge into the British side of the crossover.');
        $this->linkCanonReferenceEntity($references['stormlight_universe'], $entities['kaladin'], 'moderate', 'references', 'Kaladin carries the most legible Radiant pressure into archive material.');
        $this->linkCanonReferenceEntity($references['wand_magic'], $entities['wand_magic'], 'minimal', 'au_version', 'Mostly preserved, interpreted through comparative systems language.');
        $this->linkCanonReferenceEntity($references['surgebinding'], $entities['surgebinding'], 'minimal', 'au_version', 'Mostly preserved, but stressed against threshold ethics.');
        $this->linkCanonReferenceEntity($references['hogwarts'], $entities['hogwarts'], 'significant', 'au_version', 'The site escalates Hogwarts from setting to active threshold participant.');

        return $references;
    }

    private function seedGlossary(array $entities): void
    {
        $this->upsertGlossary('Grey Line', [
            'usage_context' => 'both',
            'definition' => $this->rich("The Grey Line is the archive term for the threshold condition where two or more worlds become stably reachable through mirrored symbolic pressure rather than brute portal mechanics."),
            'extended_notes' => $this->rich("People often misuse Grey Line as if it means a place. It is more accurate to think of it as a regime of conditions, routes, scars, and habits that together make crossing possible."),
            'origin_universe' => SourceUniverse::ORIGINAL,
            'era_introduced' => 'foundational phase',
            'term_status' => 'active',
            'first_appearance_entity_id' => $entities['mirrorbranch']->id,
            'visibility' => VisibilityLevel::SECRET,
            'content_classification' => ContentClassification::RESTRICTED,
        ]);

        $this->upsertGlossary('Spanreed Owl', [
            'usage_context' => 'both',
            'definition' => $this->rich("Archive slang for hybrid message traffic translated between spanreed logic and Hogwarts courier habits."),
            'extended_notes' => $this->rich("The phrase starts as a joke and survives because everyone immediately knows what kind of absurdity it describes."),
            'origin_universe' => SourceUniverse::STORMLIGHT,
            'era_introduced' => 'Urithiru exchange',
            'term_status' => 'active',
            'first_appearance_entity_id' => $entities['event_exchange']->id,
            'visibility' => VisibilityLevel::PUBLIC_KNOWLEDGE,
            'content_classification' => ContentClassification::PUBLIC,
        ]);

        $this->upsertGlossary('Mirrorbranch', [
            'usage_context' => 'in_world',
            'definition' => $this->rich("Handler shorthand for the most stable engineered threshold corridor currently maintained by the Archive."),
            'extended_notes' => $this->rich("In technical use it refers to the corridor itself. In emotional use it often means any crossing that feels too intimate to be safely procedural."),
            'origin_universe' => SourceUniverse::ORIGINAL,
            'era_introduced' => 'archive phase',
            'term_status' => 'active',
            'first_appearance_entity_id' => $entities['mirrorbranch']->id,
            'visibility' => VisibilityLevel::SECRET,
            'content_classification' => ContentClassification::RESTRICTED,
        ]);

        $this->upsertGlossary('Oath Pressure', [
            'usage_context' => 'meta',
            'definition' => $this->rich("Author-side term for the way spoken or internalized commitments alter threshold behavior even outside formal Rosharan systems."),
            'extended_notes' => $this->rich("Used heavily in notes whenever a scene becomes less about mechanics and more about what a character refuses to say plainly."),
            'origin_universe' => SourceUniverse::STORMLIGHT,
            'era_introduced' => 'research phase',
            'term_status' => 'active',
            'first_appearance_entity_id' => $entities['grey_line_weaving']->id,
            'visibility' => VisibilityLevel::SECRET,
            'content_classification' => ContentClassification::RESTRICTED,
        ]);
    }

    private function seedTimelines(array $entities): array
    {
        $mainCrossing = $this->upsertTimelineEntry($entities['timeline_main'], $entities['event_crossing'], [
            'entry_label' => 'The First Stable Crossing',
            'au_date' => 'Year 0 / Late Summer',
            'source_date' => 'post-war / post-Radiant mobilization',
            'source_date_universe' => 'Grey Line synthesis',
            'timeline_position' => 10,
            'temporal_certainty' => 'documented',
            'public_narrative' => $this->rich("A controlled crossing succeeds without catastrophic memory shear, proving that sustainable contact is possible."),
            'true_narrative' => $this->rich("The success hinges on a level of mutual truth-telling the archive cannot easily replicate under institutional pressure."),
            'narrative_divergence' => 'partial',
            'truth_known_by' => [$entities['harry']->id, $entities['hermione']->id, $entities['seraphine']->id],
            'event_significance' => 'pivotal',
            'visibility' => VisibilityLevel::PUBLIC_KNOWLEDGE,
            'content_classification' => ContentClassification::PUBLIC,
        ]);

        $exchange = $this->upsertTimelineEntry($entities['timeline_main'], $entities['event_exchange'], [
            'entry_label' => 'The Spanreed Owl Exchange',
            'au_date' => 'Year 0 / Early Autumn',
            'source_date' => 'contact week six',
            'source_date_universe' => 'Grey Line synthesis',
            'timeline_position' => 20,
            'temporal_certainty' => 'documented',
            'public_narrative' => $this->rich("A communication chain between Hogwarts and Urithiru finally stabilizes."),
            'true_narrative' => $this->rich("This is the first moment everybody realizes physical transit is no longer the only way to escalate a crisis."),
            'narrative_divergence' => 'partial',
            'truth_known_by' => [$entities['harry']->id, $entities['hermione']->id, $entities['kaladin']->id, $entities['shallan']->id],
            'event_significance' => 'major',
            'visibility' => VisibilityLevel::PUBLIC_KNOWLEDGE,
            'content_classification' => ContentClassification::PUBLIC,
        ]);

        $tribunal = $this->upsertTimelineEntry($entities['timeline_main'], $entities['event_tribunal'], [
            'entry_label' => 'Grey Line Tribunal at Urithiru',
            'au_date' => 'Year 1 / Midwinter',
            'source_date' => 'Urithiru command period',
            'source_date_universe' => 'Stormlight Archive',
            'timeline_position' => 30,
            'temporal_certainty' => 'documented',
            'public_narrative' => $this->rich("A formal review of Grey Line governance erupts into a deeper conflict over secrecy and acceptable sacrifice."),
            'true_narrative' => $this->rich("The tribunal is really about whether the Archive deserves to survive unchanged."),
            'narrative_divergence' => 'complete',
            'truth_known_by' => [$entities['seraphine']->id, $entities['harry']->id, $entities['kaladin']->id, $entities['shallan']->id],
            'event_significance' => 'pivotal',
            'visibility' => VisibilityLevel::PUBLIC_KNOWLEDGE,
            'content_classification' => ContentClassification::PUBLIC,
        ]);

        $duel = $this->upsertTimelineEntry($entities['timeline_pressure'], $entities['event_duel'], [
            'entry_label' => 'Duel Under the Broken Canopy',
            'au_date' => 'Year 1 / Deep Winter',
            'source_date' => 'tribunal fallout',
            'source_date_universe' => 'Grey Line synthesis',
            'timeline_position' => 15,
            'temporal_certainty' => 'documented',
            'public_narrative' => $this->rich("A contained confrontation at the threshold exposes how much the crossing reacts to emotional truth."),
            'true_narrative' => $this->rich("The duel functions as a forced confession conducted through ritual structure."),
            'narrative_divergence' => 'complete',
            'truth_known_by' => [$entities['seraphine']->id, $entities['shallan']->id, $entities['kaladin']->id],
            'event_significance' => 'major',
            'visibility' => VisibilityLevel::PUBLIC_KNOWLEDGE,
            'content_classification' => ContentClassification::PUBLIC,
        ]);

        $group = $this->upsertConcurrencyGroup('Tribunal / Canopy Split', [
            'au_date' => 'Year 1 / Midwinter',
            'description' => $this->rich('Tracks the tribunal and duel as simultaneous pressure events: one procedural, one symbolic.'),
            'narrative_significance' => 'pivotal',
        ]);

        $tribunal->update(['concurrency_group_id' => $group->id]);
        $duel->update(['concurrency_group_id' => $group->id]);

        return [
            'main_crossing' => $mainCrossing->fresh(),
            'exchange' => $exchange->fresh(),
            'tribunal' => $tribunal->fresh(),
            'duel' => $duel->fresh(),
            'group' => $group,
        ];
    }

    private function seedStates(array $entities, array $relationships, array $timelineData): void
    {
        $this->upsertCharacterState($entities['harry'], 'Crossing Aftermath', [
            'timeline_id' => $entities['timeline_main']->id,
            'au_date' => 'Year 0 / Late Summer',
            'timeline_position' => 12,
            'snapshot_label' => 'After the first stable crossing',
            'snapshot_significance' => 'major',
            'significance_reason' => 'Harry stops thinking of the anomaly as a one-off emergency and starts thinking in obligations.',
            'current_trauma_profile' => $this->rich('Acute vigilance layered over older war trauma; functioning through responsibility rather than recovery.'),
            'active_psychological_patterns' => $this->rich('Hyper-responsibility, tactical calm, reluctance to share burden until collapse is near.'),
            'current_stability_level' => 'strained',
            'current_desire' => 'Prevent contact from turning into extraction.',
            'current_fear' => 'That every new system will ask for the same dead children with better paperwork.',
            'true_self' => 'Deeply loving, still convinced love is best proven by standing in front of impact.',
            'mask_integrity' => 'compromised',
            'physical_state_notes' => 'Operationally capable, carrying fatigue as baseline.',
            'current_power_tier_operating' => 'regional',
            'current_power_tier_influence' => 'global',
            'available_abilities' => ['advanced wand magic', 'threshold survivability'],
            'key_relationships_summary' => $this->rich('Leaning hardest on Hermione while beginning a dangerous mutual recognition loop with Kaladin.'),
            'notes' => $this->rich('This is the version of Harry the Archive keeps mistaking for sustainable.'),
        ], [
            ['relationship_id' => $relationships['harry_hermione']->id, 'state_notes' => $this->rich('Trust remains immediate and functional.')],
            ['relationship_id' => $relationships['harry_kaladin']->id, 'state_notes' => $this->rich('Respect forms almost too quickly under crisis conditions.')],
        ]);

        $this->upsertCharacterState($entities['hermione'], 'Protocol Drafting Phase', [
            'timeline_id' => $entities['timeline_main']->id,
            'au_date' => 'Year 0 / Early Autumn',
            'timeline_position' => 21,
            'snapshot_label' => 'During communications stabilization',
            'snapshot_significance' => 'moderate',
            'significance_reason' => 'Hermione translates emergency contact into durable structure.',
            'current_trauma_profile' => $this->rich('Manages fear through method, documentation, and argument.'),
            'active_psychological_patterns' => $this->rich('Protective over-analysis, high accountability standard, rest deferred until systems lock.'),
            'current_stability_level' => 'stressed',
            'current_desire' => 'Make sure no future crossing depends on improvisation alone.',
            'current_fear' => 'That institutional convenience will outpace ethical caution.',
            'true_self' => 'Tender, furious, and relentlessly exacting.',
            'mask_integrity' => 'intact',
            'physical_state_notes' => 'Sleep debt hidden behind competence.',
            'current_power_tier_operating' => 'regional',
            'current_power_tier_influence' => 'global',
            'available_abilities' => ['advanced spellcraft', 'comparative theory'],
            'key_relationships_summary' => $this->rich('Grounding Harry, testing Seraphine, quietly fascinated by Rosharan ethical mechanics.'),
            'notes' => $this->rich('Most effective when allowed to challenge assumptions before they calcify.'),
        ], [
            ['relationship_id' => $relationships['harry_hermione']->id, 'state_notes' => $this->rich('Mutual trust functioning as command backbone.')],
        ]);

        $this->upsertCharacterState($entities['kaladin'], 'Tribunal Threshold', [
            'timeline_id' => $entities['timeline_main']->id,
            'au_date' => 'Year 1 / Midwinter',
            'timeline_position' => 31,
            'snapshot_label' => 'At the Grey Line Tribunal',
            'snapshot_significance' => 'transformative',
            'significance_reason' => 'Kaladin decides he will not let ethical speech be treated as procedural naivete.',
            'current_trauma_profile' => $this->rich('Protective rage sharpened by institutional distrust and accumulated survivor debt.'),
            'active_psychological_patterns' => $this->rich('Immediate threat assessment, moral clarity under pressure, self-erasure as duty.'),
            'current_stability_level' => 'breaking',
            'current_desire' => 'Keep the vulnerable from becoming acceptable losses inside process language.',
            'current_fear' => 'That he will once again arrive just in time to understand a failure.',
            'true_self' => 'Profoundly gentle under all the armor, but unable to pretend cruelty is normal.',
            'mask_integrity' => 'cracking',
            'physical_state_notes' => 'Operational and dangerous, carrying exhaustion into motion.',
            'current_power_tier_operating' => 'continental',
            'current_power_tier_influence' => 'global',
            'available_abilities' => ['gravitation', 'adhesion', 'combat medicine'],
            'key_relationships_summary' => $this->rich('Trusting Harry more, arguing with Seraphine harder, still orbiting Shallan through mutual recognition.'),
            'notes' => $this->rich('A major pressure point for the entire archive governance arc.'),
        ], [
            ['relationship_id' => $relationships['harry_kaladin']->id, 'state_notes' => $this->rich('Protectors recognizing each other’s bad habits too clearly.')],
            ['relationship_id' => $relationships['seraphine_kaladin']->id, 'state_notes' => $this->rich('Procedural conflict nearing open rupture.')],
        ]);

        $this->upsertCharacterState($entities['shallan'], 'Broken Canopy', [
            'timeline_id' => $entities['timeline_pressure']->id,
            'au_date' => 'Year 1 / Deep Winter',
            'timeline_position' => 16,
            'snapshot_label' => 'During the canopy duel',
            'snapshot_significance' => 'transformative',
            'significance_reason' => 'Shallan stops treating symbolic insight as observational distance and uses it as intervention.',
            'current_trauma_profile' => $this->rich('Identity strain intensified by the threshold rewarding performed truth almost as much as confessed truth.'),
            'active_psychological_patterns' => $this->rich('Performance layering, acute pattern recognition, selective honesty under duress.'),
            'current_stability_level' => 'breaking',
            'current_desire' => 'Force the structure to admit what the people inside it keep disguising.',
            'current_fear' => 'That clarity will require a self she cannot continue to inhabit afterward.',
            'true_self' => 'Brilliant and frightened and still moving toward the thing anyway.',
            'mask_integrity' => 'shattered',
            'physical_state_notes' => 'Shaken but mobile.',
            'current_power_tier_operating' => 'regional',
            'current_power_tier_influence' => 'global',
            'available_abilities' => ['Lightweaving', 'symbol analysis', 'rapid sketch recall'],
            'key_relationships_summary' => $this->rich('Sees too much in Seraphine, too much of herself in the archive, too much need in Kaladin.'),
            'notes' => $this->rich('One of the clearest snapshots of archive symbolism becoming personal cost.'),
        ], [
            ['relationship_id' => $relationships['kaladin_shallan']->id, 'state_notes' => $this->rich('Recognition verging on mutual exposure.')],
            ['relationship_id' => $relationships['shallan_seraphine']->id, 'state_notes' => $this->rich('Knowledge conflict turned into intervention.')],
        ]);

        $this->upsertCharacterState($entities['seraphine'], 'Tribunal Handler Collapse Threshold', [
            'timeline_id' => $entities['timeline_pressure']->id,
            'au_date' => 'Year 1 / Midwinter',
            'timeline_position' => 14,
            'snapshot_label' => 'Just before the canopy incident',
            'snapshot_significance' => 'major',
            'significance_reason' => 'Seraphine reaches the point where process alone can no longer contain what she knows.',
            'current_trauma_profile' => $this->rich('Long-term compartmentalization under active institutional strain.'),
            'active_psychological_patterns' => $this->rich('Precision as self-control, kindness as pressure release valve, secrecy as default stabilization method.'),
            'current_stability_level' => 'strained',
            'current_desire' => 'Hold the system together long enough to choose a less terrible future.',
            'current_fear' => 'That transparency will kill the wrong people first.',
            'true_self' => 'More exhausted and more loving than her procedures allow.',
            'mask_integrity' => 'compromised',
            'physical_state_notes' => 'Operating on discipline and minimal rest.',
            'current_power_tier_operating' => 'regional',
            'current_power_tier_influence' => 'global',
            'available_abilities' => ['Grey Line weaving', 'containment rituals', 'archive command protocols'],
            'key_relationships_summary' => $this->rich('Caught between Harry’s ethics, Kaladin’s refusal, and her own impossible maintenance burden.'),
            'notes' => $this->rich('She is closest to collapse when she looks most composed.'),
        ], [
            ['relationship_id' => $relationships['seraphine_harry']->id, 'state_notes' => $this->rich('Mutual trust fraying into confrontation.')],
            ['relationship_id' => $relationships['seraphine_kaladin']->id, 'state_notes' => $this->rich('Conflict sharpened by shared protectiveness.')],
        ]);
    }

    private function seedWorld(array $entities): void
    {
        $this->upsertPowerInteraction($entities['wand_magic'], $entities['surgebinding'], [
            'interaction_name' => 'Investiture-Wand Resonance',
            'description' => $this->rich('Wand structures and surgebinding do not cancel each other so much as expose where intention, oath, and technique disagree.'),
            'directionality' => 'contextual',
            'effects' => [
                ['effect_type' => 'amplifies', 'affected_aspect' => 'raw_power', 'magnitude' => 'moderate', 'notes' => 'Alignment of intent can spike output.'],
                ['effect_type' => 'destabilizes', 'affected_aspect' => 'emotional_resonance', 'magnitude' => 'significant', 'notes' => 'Suppressed emotional states produce volatile feedback.'],
            ],
            'proximity_required' => true,
            'practitioner_conditions' => ['shared intent', 'high symbolic stress', 'active investiture'],
            'trigger_type' => 'co-casting',
            'trigger_description' => $this->rich('Most unstable when both systems are used to solve the same moral problem at once.'),
            'interaction_scale' => 'local',
            'scale_variance' => 'intensifies_with_scale',
            'knowledge_state' => 'theorized',
            'danger_rating' => 'high',
            'source_universe_a' => SourceUniverse::HARRY_POTTER,
            'source_universe_b' => SourceUniverse::STORMLIGHT,
            'visibility' => VisibilityLevel::SECRET,
            'content_classification' => ContentClassification::RESTRICTED,
        ]);

        $this->upsertPowerInteraction($entities['grey_line_weaving'], $entities['surgebinding'], [
            'interaction_name' => 'Oath-Echo Threshold Binding',
            'description' => $this->rich('Grey Line Weaving and Surgebinding reinforce each other whenever a crossing is framed as a promise rather than a transport problem.'),
            'directionality' => 'asymmetrical',
            'dominant_system_entity_id' => $entities['grey_line_weaving']->id,
            'effects' => [
                ['effect_type' => 'catalyzes', 'affected_aspect' => 'reality_anchor', 'magnitude' => 'significant', 'notes' => 'Stable oaths improve route coherence.'],
                ['effect_type' => 'corrupts', 'affected_aspect' => 'cognitive_function', 'magnitude' => 'moderate', 'notes' => 'Bad-faith oaths can lock harmful routes in place.'],
            ],
            'proximity_required' => false,
            'artifact_conditions' => [$entities['lantern_compass']->name],
            'trigger_type' => 'threshold oath',
            'trigger_description' => $this->rich('Most active when a crossing is witnessed, named, and emotionally costly.'),
            'interaction_scale' => 'regional',
            'scale_variance' => 'transforms_with_scale',
            'knowledge_state' => 'unknown',
            'danger_rating' => 'existential_risk',
            'source_universe_a' => SourceUniverse::ORIGINAL,
            'source_universe_b' => SourceUniverse::STORMLIGHT,
            'visibility' => VisibilityLevel::SECRET,
            'content_classification' => ContentClassification::RESTRICTED,
        ]);

        $this->upsertContainment($entities['forest'], $entities['hogwarts'], 'physical', [
            'era_start' => 'canon baseline',
            'notes' => $this->rich('The Forest remains on Hogwarts grounds without ever feeling owned by them.'),
        ]);

        $this->upsertContainment($entities['mirrorbranch'], $entities['hogwarts'], 'dimensional', [
            'era_start' => 'archive phase',
            'notes' => $this->rich('One of the corridor’s most stable anchors is nested through the castle.'),
        ]);

        $this->upsertContainment($entities['urithiru'], $entities['shattered_plains'], 'cultural', [
            'era_start' => 'reference mapping phase',
            'notes' => $this->rich('Archive maps often group Urithiru and the Plains because the characters do.'),
        ]);

        $this->upsertTravelRoute($entities['hogwarts'], $entities['urithiru'], 'dimensional', [
            'standard_duration' => '11 to 17 minutes under supervised conditions',
            'method_variants' => [
                ['method_name' => 'Mirrorbranch escorted transit', 'required_ability_or_artifact' => 'Lantern Compass + handler seal', 'duration' => '11 minutes', 'conditions' => 'truthful declaration of intent', 'notes' => 'Safest documented method.'],
                ['method_name' => 'Emergency oath pull', 'required_ability_or_artifact' => 'Windrunner support', 'duration' => '2 to 4 minutes', 'conditions' => 'catastrophic need', 'notes' => 'Effective and psychologically expensive.'],
            ],
            'hazards' => ['identity bleed', 'emotional echo', 'symbolic route inversion'],
            'known_by_entity_ids' => [$entities['harry']->id, $entities['hermione']->id, $entities['kaladin']->id, $entities['seraphine']->id],
            'controlled_by_entity_id' => $entities['grey_line_accord']->id,
            'notes' => $this->rich('Primary sanctioned route between the two major operating sites.'),
            'visibility' => VisibilityLevel::SECRET,
            'content_classification' => ContentClassification::RESTRICTED,
        ]);

        $this->upsertTravelRoute($entities['hogwarts'], $entities['mirrorbranch'], 'magical', [
            'standard_duration' => '3 minutes on foot, variable on exit',
            'method_variants' => [
                ['method_name' => 'Handler-guided descent', 'required_ability_or_artifact' => 'threshold key', 'duration' => '3 minutes', 'conditions' => 'castle permission', 'notes' => 'Reliable if the route is not already under stress.'],
            ],
            'hazards' => ['hallway recursion', 'memory lag'],
            'controlled_by_entity_id' => $entities['mirror_archive']->id,
            'notes' => $this->rich('Short route with outsized symbolic risk.'),
            'visibility' => VisibilityLevel::SECRET,
            'content_classification' => ContentClassification::RESTRICTED,
        ]);

        $this->upsertLocationControl($entities['hogwarts'], $entities['order'], 'protected', [
            'control_start_era' => 'post-war',
            'how_control_was_established' => $this->rich('Protection emerged through stewardship rather than conquest.'),
            'resistance_level' => 'minor',
            'notes' => $this->rich('The Order protects without fully possessing. The castle still has opinions.'),
            'visibility' => VisibilityLevel::PUBLIC_KNOWLEDGE,
            'content_classification' => ContentClassification::PUBLIC,
        ]);

        $this->upsertLocationControl($entities['urithiru'], $entities['radiants'], 'sovereign', [
            'control_start_era' => 'Radiant mobilization',
            'how_control_was_established' => $this->rich('Secured through oathbound occupation and civic restoration.'),
            'resistance_level' => 'significant',
            'notes' => $this->rich('Urithiru is governed through active legitimacy work, not just power.'),
            'visibility' => VisibilityLevel::PUBLIC_KNOWLEDGE,
            'content_classification' => ContentClassification::PUBLIC,
        ]);

        $this->upsertLocationControl($entities['mirrorbranch'], $entities['mirror_archive'], 'contested', [
            'control_start_era' => 'archive phase',
            'how_control_was_established' => $this->rich('Constructed anchors and repeat handler use created de facto control.'),
            'resistance_level' => 'active_conflict',
            'resistance_entity_id' => $entities['grey_line_accord']->id,
            'notes' => $this->rich('The corridor never fully accepts exclusive ownership.'),
            'visibility' => VisibilityLevel::SECRET,
            'content_classification' => ContentClassification::RESTRICTED,
        ]);
    }

    private function seedIntelligence(array $entities, array $relationships, array $groups, array $timelineData): void
    {
        $secretGreyLine = $this->upsertSecret('The Grey Line feeds on unresolved vows', [
            'secret_content' => $this->rich("The threshold becomes easier to stabilize when participants are emotionally compressed around promises they cannot release cleanly. This is operationally useful and morally monstrous."),
            'secret_type' => 'cosmological',
            'subject_entity_ids' => [$entities['grey_line_weaving']->id, $entities['mirrorbranch']->id],
            'holder_entity_ids' => [$entities['seraphine']->id, $entities['hoid']->id],
            'known_by_entity_ids' => [$entities['seraphine']->id, $entities['hoid']->id, $entities['hermione']->id, $entities['shallan']->id],
            'exposure_risk' => 'critical',
            'exposure_consequences' => $this->rich('If widely operationalized, institutions will start incentivizing damage as technique.'),
            'revelation_trigger' => 'Independent confirmation from comparative research plus threshold incidents.',
            'status' => 'active',
            'visibility' => VisibilityLevel::SECRET,
            'content_classification' => ContentClassification::AUTHOR_ONLY,
        ]);

        $secretSeraphine = $this->upsertSecret('Seraphine was shaped by a failed early crossing', [
            'secret_content' => $this->rich("Seraphine is not simply a normal operator who learned threshold work. She is one of its surviving artifacts, altered by a much earlier instability event."),
            'secret_type' => 'identity',
            'subject_entity_ids' => [$entities['seraphine']->id],
            'holder_entity_ids' => [$entities['seraphine']->id, $entities['hoid']->id],
            'known_by_entity_ids' => [$entities['seraphine']->id, $entities['hoid']->id, $entities['shallan']->id],
            'exposure_risk' => 'high',
            'exposure_consequences' => $this->rich('Would reframe Seraphine from handler to evidence.'),
            'revelation_trigger' => 'Archive mirror records matching her resonance signature.',
            'status' => 'active',
            'visibility' => VisibilityLevel::SECRET,
            'content_classification' => ContentClassification::AUTHOR_ONLY,
        ]);

        $knowledgeHermione = $this->upsertKnowledgeState($entities['hermione'], [
            'subject_secret_id' => $secretGreyLine->id,
            'knowledge_type' => 'secret',
            'knowledge_content' => $this->rich('Hermione has enough evidence to suspect the threshold rewards unresolved vows and is horrified by the implication.'),
            'accuracy' => 'true',
            'acquired_at_era' => 'research phase',
            'acquired_through' => 'deduction',
            'current_belief_state' => 'compartmentalizing',
            'valid_from_era' => 'research phase',
            'visibility' => VisibilityLevel::SECRET,
            'content_classification' => ContentClassification::RESTRICTED,
        ]);

        $knowledgeKaladin = $this->upsertKnowledgeState($entities['kaladin'], [
            'subject_entity_id' => $entities['seraphine']->id,
            'knowledge_type' => 'true_nature',
            'knowledge_content' => $this->rich('Kaladin understands that Seraphine is carrying institutional damage in her body language long before anyone explains why.'),
            'accuracy' => 'partial',
            'acquired_at_era' => 'Urithiru exchange',
            'acquired_through' => 'observation',
            'current_belief_state' => 'suspects',
            'valid_from_era' => 'Urithiru exchange',
            'visibility' => VisibilityLevel::SECRET,
            'content_classification' => ContentClassification::RESTRICTED,
        ]);

        $knowledgeShallan = $this->upsertKnowledgeState($entities['shallan'], [
            'subject_secret_id' => $secretSeraphine->id,
            'knowledge_type' => 'secret',
            'knowledge_content' => $this->rich('Shallan recognizes that Seraphine’s relationship to the threshold is personal, not merely professional.'),
            'accuracy' => 'true',
            'acquired_at_era' => 'broken canopy duel',
            'acquired_through' => 'deduction',
            'current_belief_state' => 'believes',
            'valid_from_era' => 'broken canopy duel',
            'visibility' => VisibilityLevel::SECRET,
            'content_classification' => ContentClassification::RESTRICTED,
        ]);

        $this->upsertKnowledgeState($entities['harry'], [
            'subject_relationship_id' => $relationships['seraphine_harry']->id,
            'knowledge_type' => 'suspicion',
            'knowledge_content' => $this->rich('Harry knows the procedural terms of his relationship with Seraphine are no longer the real ones in play.'),
            'accuracy' => 'true',
            'acquired_at_era' => 'tribunal leadup',
            'acquired_through' => 'observation',
            'current_belief_state' => 'believes',
            'valid_from_era' => 'tribunal leadup',
            'visibility' => VisibilityLevel::SECRET,
            'content_classification' => ContentClassification::RESTRICTED,
        ]);

        $perceptionArchive = $this->upsertPerceptionState('organization', $entities['mirror_archive']->id, [
            'true_state' => $this->rich('The Archive is a containment organ carrying enough moral debt to be structurally unstable.'),
            'perceived_state' => $this->rich('A careful research office doing difficult but routine administrative work.'),
            'divergence_level' => 'significant',
            'maintained_by_entity_ids' => [$entities['seraphine']->id],
            'maintenance_method' => 'strategic_information_control',
            'maintenance_effort' => 'critical',
            'perceiving_entity_ids' => [],
            'immune_entity_ids' => [$entities['harry']->id, $entities['kaladin']->id, $entities['shallan']->id],
            'revelation_condition' => $this->rich('A public threshold failure with living witnesses from multiple worlds.'),
            'revelation_consequence' => $this->rich('Loss of archive narrative control and immediate demand for shared oversight.'),
            'revelation_risk' => 'critical',
            'related_secret_id' => $secretGreyLine->id,
            'related_knowledge_state_ids' => [$knowledgeHermione->id, $knowledgeKaladin->id, $knowledgeShallan->id],
            'visibility' => VisibilityLevel::SECRET,
            'content_classification' => ContentClassification::RESTRICTED,
        ]);

        $this->upsertPerceptionState('event', $timelineData['main_crossing']->id, [
            'true_state' => $this->rich('The first crossing succeeded because everyone involved stopped performing certainty.'),
            'perceived_state' => $this->rich('The first crossing succeeded because the ritual design was finally correct.'),
            'divergence_level' => 'surface',
            'maintained_by_entity_ids' => [$entities['mirror_archive']->id],
            'maintenance_method' => 'deliberate_misdirection',
            'maintenance_effort' => 'active',
            'perceiving_entity_ids' => [],
            'immune_entity_ids' => [$entities['harry']->id, $entities['hermione']->id, $entities['kaladin']->id],
            'revelation_condition' => $this->rich('Reconstruction of witness emotional signatures.'),
            'revelation_consequence' => $this->rich('Would force a rewrite of archive doctrine.'),
            'revelation_risk' => 'high',
            'related_secret_id' => $secretGreyLine->id,
            'related_knowledge_state_ids' => [$knowledgeHermione->id],
            'visibility' => VisibilityLevel::SECRET,
            'content_classification' => ContentClassification::RESTRICTED,
        ]);

        $this->touch('group-relationships');
        $this->touch('timeline-entries');
    }

    private function seedCollections(array $entities, array $documents): void
    {
        $cast = $this->upsertCollection('Grey Line Primary Cast', [
            'description' => $this->rich('Manual roster of the characters carrying the emotional and operational spine of the convergence arc.'),
            'collection_type' => 'character_roster',
            'collection_mode' => 'manual',
            'completion_state' => 'complete',
            'visibility' => VisibilityLevel::PUBLIC_KNOWLEDGE,
            'content_classification' => ContentClassification::PUBLIC,
        ]);

        foreach (['harry', 'hermione', 'kaladin', 'shallan', 'seraphine'] as $index => $key) {
            $this->collectionService->addEntity($cast, $entities[$key], [
                'role_in_collection' => 'primary cast',
                'sort_order' => $index + 1,
                'notes' => 'Core crossover character.',
            ]);
            CollectionEntity::query()
                ->where('collection_id', $cast->id)
                ->where('entity_id', $entities[$key]->id)
                ->update(['sort_order' => $index + 1, 'role_in_collection' => 'primary cast']);
        }

        $locations = $this->upsertCollection('Threshold Sites and Pressure Locations', [
            'description' => $this->rich('Crossworld-important locations organized for browsing, comparison, and route planning.'),
            'collection_type' => 'location_cluster',
            'collection_mode' => 'manual',
            'completion_state' => 'in_progress',
            'visibility' => VisibilityLevel::PUBLIC_KNOWLEDGE,
            'content_classification' => ContentClassification::PUBLIC,
        ]);

        foreach (['hogwarts', 'forest', 'urithiru', 'shattered_plains', 'mirrorbranch'] as $index => $key) {
            $this->collectionService->addEntity($locations, $entities[$key], [
                'role_in_collection' => 'site',
                'sort_order' => $index + 1,
            ]);
            CollectionEntity::query()
                ->where('collection_id', $locations->id)
                ->where('entity_id', $entities[$key]->id)
                ->update(['sort_order' => $index + 1, 'role_in_collection' => 'site']);
        }

        $archiveDocs = $this->upsertCollection('Archive Core Documents', [
            'description' => $this->rich('The documents most likely to orient someone quickly inside the Grey Line material.'),
            'collection_type' => 'research_set',
            'collection_mode' => 'manual',
            'completion_state' => 'complete',
            'visibility' => VisibilityLevel::SECRET,
            'content_classification' => ContentClassification::RESTRICTED,
        ]);

        foreach (['harry_dossier', 'kaladin_dossier', 'charter', 'resonance'] as $index => $key) {
            $this->collectionService->addDocument($archiveDocs, $documents[$key], [
                'role_in_collection' => 'core reading',
                'sort_order' => $index + 1,
                'notes' => 'High-value archive context document.',
            ]);
            CollectionDocument::query()
                ->where('collection_id', $archiveDocs->id)
                ->where('document_id', $documents[$key]->id)
                ->update(['sort_order' => $index + 1, 'role_in_collection' => 'core reading']);
        }
    }

    private function seedMetaAndPipeline(array $entities): void
    {
        $metaTheme = $this->upsertMeta('Shared Burden Without Shared Permission', [
            'category' => 'themes_and_motifs',
            'meta_note_type' => 'decision',
            'content' => $this->rich("The archive material works best when responsibility is shared unevenly on purpose. Everyone is helping; nobody is helping from the same moral angle.\n\nThis keeps the crossover from flattening into competence porn."),
            'priority' => 'high',
            'action_status' => 'pending',
            'visibility' => VisibilityLevel::SECRET,
            'content_classification' => ContentClassification::RESTRICTED,
        ]);
        $metaTheme->entities()->syncWithoutDetaching([$entities['harry']->id, $entities['kaladin']->id, $entities['seraphine']->id]);

        $metaPalette = $this->upsertMeta('Threshold Weather Palette', [
            'category' => 'sensory_palettes',
            'meta_note_type' => 'passive',
            'content' => $this->rich("When a scene is threshold-hot it should feel like cold stone, lantern metal, damp fabric, breath held too long, and light behaving one beat late."),
            'sense_sight' => 'Lantern gold against blue-black stone and mirrored seams.',
            'sense_sound' => 'Soft architectural resonance, distant storm pressure, paper and fabric movement.',
            'sense_touch' => 'Cold rails, humid air, charged stillness at the skin.',
            'emotional_register' => 'Tender dread, professional exhaustion, moments of impossible kindness.',
            'priority' => 'medium',
            'action_status' => 'in_progress',
            'visibility' => VisibilityLevel::SECRET,
            'content_classification' => ContentClassification::RESTRICTED,
        ]);
        $metaPalette->entities()->syncWithoutDetaching([$entities['mirrorbranch']->id, $entities['hogwarts']->id, $entities['urithiru']->id]);

        $metaSymbol = $this->upsertMeta('Lantern Compass Sigil', [
            'category' => 'symbols_and_iconography',
            'meta_note_type' => 'decision',
            'content' => $this->rich("The Lantern Compass should symbolize truth-finding that does not rely on confession. It notices drift before people admit drift."),
            'symbol_name' => 'Lantern Compass',
            'symbol_origin_entity_id' => $entities['lantern_compass']->id,
            'symbol_usage_context' => 'Used in handler seals, document headers, and route warning cards.',
            'symbol_associated_entity_ids' => [$entities['mirror_archive']->id, $entities['grey_line_weaving']->id],
            'symbol_scope' => 'both',
            'priority' => 'medium',
            'action_status' => 'pending',
            'visibility' => VisibilityLevel::SECRET,
            'content_classification' => ContentClassification::RESTRICTED,
        ]);
        $metaSymbol->entities()->syncWithoutDetaching([$entities['lantern_compass']->id, $entities['mirror_archive']->id]);

        $this->upsertPipelineItem('Grey Line Tribunal scene ladder', [
            'pipeline_type' => 'outline',
            'pipeline_stage' => 'outlined',
            'content' => $this->rich("Break the tribunal into escalating beats: procedural calm, moral fracture, archive justification, outside interruption, symbolic spillover."),
            'tracked_entity_id' => $entities['event_tribunal']->id,
            'notes' => $this->rich('Keep the scene about governance under stress, not just accusation.'),
            'visibility' => VisibilityLevel::SECRET,
            'content_classification' => ContentClassification::RESTRICTED,
        ]);

        $this->upsertPipelineItem('Harry and Kaladin post-tribunal bridge scene', [
            'pipeline_type' => 'scene',
            'pipeline_stage' => 'drafted',
            'content' => $this->rich("Quiet scene after the tribunal where both men realize they keep trying to become shock absorbers for everyone else."),
            'pov_character_entity_id' => $entities['harry']->id,
            'location_entity_id' => $entities['urithiru']->id,
            'tracked_entity_id' => $entities['harry']->id,
            'emotional_beat' => 'recognition without relief',
            'narrative_purpose' => $this->rich('Lets the crossover breathe while deepening the protector mirror.'),
            'notes' => $this->rich('No speeches. Let silence and specific observation do the work.'),
            'visibility' => VisibilityLevel::SECRET,
            'content_classification' => ContentClassification::RESTRICTED,
        ]);

        $this->upsertPipelineItem('Seraphine character study', [
            'pipeline_type' => 'character_study',
            'pipeline_stage' => 'revised',
            'content' => $this->rich("Map Seraphine’s kindness, secrecy, and proceduralism as one structure rather than three separate traits."),
            'tracked_entity_id' => $entities['seraphine']->id,
            'arc_stage' => 'pre-collapse',
            'arc_notes' => $this->rich('Important that she never feels like a twist character. She should feel tragically legible in hindsight.'),
            'visibility' => VisibilityLevel::SECRET,
            'content_classification' => ContentClassification::RESTRICTED,
        ]);

        $this->upsertPipelineItem('Threshold weather inspiration board', [
            'pipeline_type' => 'inspiration',
            'pipeline_stage' => 'concept',
            'content' => $this->rich('Collect imagery and language for lantern light, storm residue, reflective corridors, and old school stone under impossible pressure.'),
            'sensory_palette_meta_id' => $metaPalette->id,
            'inspiration_source_universe' => SourceUniverse::ORIGINAL,
            'inspiration_source_element' => 'threshold atmosphere',
            'influenced_entity_ids' => [$entities['mirrorbranch']->id, $entities['event_duel']->id],
            'notes' => $this->rich('Useful for keeping the original components distinct from both borrowed canons.'),
            'visibility' => VisibilityLevel::SECRET,
            'content_classification' => ContentClassification::RESTRICTED,
        ]);
    }

    private function seedSessionLogs(array $entities): void
    {
        $this->upsertSessionLog('Grey Line Crossover Fill Pass', [
            'session_date' => now()->toDateString(),
            'external_tool' => 'chatgpt',
            'focus_entity_ids' => [$entities['harry']->id, $entities['kaladin']->id, $entities['seraphine']->id],
            'focus_description' => 'Populate the site with canonical crossover anchors and original connective tissue.',
            'decisions_made' => [
                'Use Harry Potter and Stormlight Archive as the primary canon pillars.',
                'Keep original Archive and Grey Line material as the connective framework instead of replacing it.',
            ],
            'changes_applied' => [
                'Expanded entities, documents, timelines, worldbuilding, intelligence, and production notes.',
                'Raised multiple major entities to full completion through linked content.',
            ],
            'open_threads' => [
                'Decide how public the Archive should become after the tribunal fallout.',
                'Clarify whether Hoid remains participant or destabilizing observer in later arcs.',
            ],
            'session_significance' => 'major',
            'notes' => $this->rich('Seed pass meant to make the site feel meaningfully inhabited rather than merely populated.'),
        ]);
    }

    private function seedEntitySubresources(array $entities): void
    {
        $this->upsertAlias($entities['harry'], 'The Boy Who Lived', 'reputation', 'Public war-era epithet.');
        $this->upsertAlias($entities['hermione'], 'Ministerial Menace', 'nickname', 'Half-joking label used by people who dislike being corrected by facts.');
        $this->upsertAlias($entities['kaladin'], 'Stormblessed', 'reputation', 'Battlefield title carried forward into archive records.');
        $this->upsertAlias($entities['shallan'], 'Veil', 'cover_identity', 'Still useful when discussing persona work and infiltration history.');
        $this->upsertAlias($entities['seraphine'], 'Grey Line Handler', 'role_title', 'Common archive designation rather than a true name replacement.');

        $this->upsertEntityNote($entities['harry'], 'Pressure note', 'Harry reads institutional euphemism as danger language almost instantly.');
        $this->upsertEntityNote($entities['seraphine'], 'Moral fault line', 'Best written when the reader can understand why she chose the wrong answer before deciding whether to forgive it.');
        $this->upsertEntityNote($entities['shallan'], 'Symbol note', 'Shallan should be allowed to notice the right thing too early and understand it too late.');

        $this->upsertEntityQuestion($entities['seraphine'], 'What does Seraphine refuse to ask for because asking would prove she needs it?', 'Core emotional question for later archive collapse scenes.', 'open', 'blocking');
        $this->upsertEntityQuestion($entities['harry'], 'At what point does Harry decide the Accord is worth preserving instead of merely supervising?', 'Useful hinge for later governance arcs.', 'open', 'high');
    }

    private function seedMedia(array $entities, array $documents, array $canonReferences): void
    {
        $this->upsertMediaReference(['entity_id' => $entities['harry']->id, 'title' => 'Harry Potter Reference Link'], [
            'description' => 'Reference page for quick canonical grounding.',
            'media_type' => 'link',
            'purpose' => 'reference',
            'url' => 'https://harrypotter.fandom.com/wiki/Harry_Potter',
            'is_primary' => true,
            'sort_order' => 1,
            'visibility' => VisibilityLevel::PUBLIC_KNOWLEDGE,
            'content_classification' => ContentClassification::PUBLIC,
        ]);

        $this->upsertMediaReference(['entity_id' => $entities['hermione']->id, 'title' => 'Hermione Granger Reference Link'], [
            'description' => 'Reference page for quick canonical grounding.',
            'media_type' => 'link',
            'purpose' => 'reference',
            'url' => 'https://harrypotter.fandom.com/wiki/Hermione_Granger',
            'is_primary' => true,
            'sort_order' => 1,
            'visibility' => VisibilityLevel::PUBLIC_KNOWLEDGE,
            'content_classification' => ContentClassification::PUBLIC,
        ]);

        $this->upsertMediaReference(['entity_id' => $entities['kaladin']->id, 'title' => 'Kaladin Stormblessed Reference Link'], [
            'description' => 'Reference page for quick canonical grounding.',
            'media_type' => 'link',
            'purpose' => 'reference',
            'url' => 'https://coppermind.net/wiki/Kaladin',
            'is_primary' => true,
            'sort_order' => 1,
            'visibility' => VisibilityLevel::PUBLIC_KNOWLEDGE,
            'content_classification' => ContentClassification::PUBLIC,
        ]);

        $this->upsertMediaReference(['entity_id' => $entities['shallan']->id, 'title' => 'Shallan Davar Reference Link'], [
            'description' => 'Reference page for quick canonical grounding.',
            'media_type' => 'link',
            'purpose' => 'reference',
            'url' => 'https://coppermind.net/wiki/Shallan_Davar',
            'is_primary' => true,
            'sort_order' => 1,
            'visibility' => VisibilityLevel::PUBLIC_KNOWLEDGE,
            'content_classification' => ContentClassification::PUBLIC,
        ]);

        $this->upsertMediaReference(['entity_id' => $entities['seraphine']->id, 'title' => 'Seraphine Morbraith Mood Reference'], [
            'description' => 'Archive-facing mood and presentation reference.',
            'media_type' => 'link',
            'purpose' => 'mood',
            'url' => 'https://example.com/reference/seraphine-morbraith',
            'is_primary' => true,
            'sort_order' => 1,
            'visibility' => VisibilityLevel::SECRET,
            'content_classification' => ContentClassification::RESTRICTED,
        ]);

        $this->upsertMediaReference(['entity_id' => $entities['hogwarts']->id, 'title' => 'Hogwarts Castle Reference Link'], [
            'description' => 'Reference page for the castle and grounds.',
            'media_type' => 'link',
            'purpose' => 'map',
            'url' => 'https://harrypotter.fandom.com/wiki/Hogwarts_Castle',
            'is_primary' => true,
            'sort_order' => 1,
            'visibility' => VisibilityLevel::PUBLIC_KNOWLEDGE,
            'content_classification' => ContentClassification::PUBLIC,
        ]);

        $this->upsertMediaReference(['entity_id' => $entities['urithiru']->id, 'title' => 'Urithiru Reference Link'], [
            'description' => 'Reference page for the tower city.',
            'media_type' => 'link',
            'purpose' => 'map',
            'url' => 'https://coppermind.net/wiki/Urithiru',
            'is_primary' => true,
            'sort_order' => 1,
            'visibility' => VisibilityLevel::PUBLIC_KNOWLEDGE,
            'content_classification' => ContentClassification::PUBLIC,
        ]);

        $this->upsertMediaReference(['source_canon_reference_id' => $canonReferences['hp_universe']->id, 'title' => 'Harry Potter Canon Hub'], [
            'description' => 'Primary canon browse reference.',
            'media_type' => 'link',
            'purpose' => 'reference',
            'url' => 'https://harrypotter.fandom.com',
            'sort_order' => 1,
            'visibility' => VisibilityLevel::SECRET,
            'content_classification' => ContentClassification::RESTRICTED,
        ]);

        $this->upsertMediaReference(['source_canon_reference_id' => $canonReferences['stormlight_universe']->id, 'title' => 'Stormlight Canon Hub'], [
            'description' => 'Primary canon browse reference.',
            'media_type' => 'link',
            'purpose' => 'reference',
            'url' => 'https://coppermind.net/wiki/The_Stormlight_Archive',
            'sort_order' => 1,
            'visibility' => VisibilityLevel::SECRET,
            'content_classification' => ContentClassification::RESTRICTED,
        ]);

        $this->touch('documents');
    }

    private function seedEntityVersions(array $entities): void
    {
        foreach (['harry', 'hermione', 'kaladin', 'shallan'] as $key) {
            $entity = $entities[$key];

            $hasVersionZero = $entity->versions()
                ->where('is_version_zero', true)
                ->exists();

            if (! $hasVersionZero) {
                $this->entityService->saveVersionZero($entity, [
                    'version_label' => 'Version Zero - '.$entity->name,
                    'version_zero_confidence' => 'solid',
                    'version_zero_notes' => 'Seeded to give the entity a clear source-canon snapshot.',
                ]);
                $this->touch('entity-versions');
            }
        }
    }

    private function finalizeEntities(array $entities): void
    {
        foreach ($entities as $entity) {
            $entity = $entity->fresh();
            $this->flagFlipper->flipAll($entity);
            $this->completionUpdater->recalculate($entity->fresh());
        }
    }

    private function upsertEntity(string $name, array $attributes): Entity
    {
        $existing = Entity::withTrashed()->where('name', $name)->first();
        $payload = array_merge(['name' => $name], $attributes);

        if ($existing) {
            if ($existing->trashed()) {
                $existing->restore();
            }

            $entity = $this->entityService->update($existing, $payload);
            $this->touch('entities', 'updated');

            return $entity;
        }

        $entity = $this->entityService->create($payload);
        $this->touch('entities', 'created');

        return $entity;
    }

    private function upsertRelationship(Entity $from, Entity $to, string $type, array $attributes): Relationship
    {
        $existing = Relationship::withTrashed()
            ->where('from_entity_id', $from->id)
            ->where('to_entity_id', $to->id)
            ->where('relationship_type', $type)
            ->first();

        $payload = array_merge($attributes, ['relationship_type' => $type]);

        if ($existing) {
            if ($existing->trashed()) {
                $existing->restore();
            }

            $relationship = $this->relationshipService->update($existing, $payload);
            $this->touch('relationships', 'updated');

            return $relationship;
        }

        $relationship = $this->relationshipService->create($from, $to, $payload);
        $this->touch('relationships', 'created');

        return $relationship;
    }

    private function upsertGroupRelationship(string $name, array $attributes): GroupRelationship
    {
        $group = GroupRelationship::withTrashed()->where('name', $name)->first();

        if ($group) {
            if ($group->trashed()) {
                $group->restore();
            }

            $group->update(array_merge(['name' => $name], $attributes));
            $this->touch('group-relationships', 'updated');

            return $group->fresh();
        }

        $group = $this->relationshipService->createGroup(array_merge(['name' => $name], $attributes));
        $this->touch('group-relationships', 'created');

        return $group;
    }

    private function upsertGroupMember(GroupRelationship $group, Entity $entity, string $role, string $joinedEra): GroupRelationshipEntity
    {
        $entry = GroupRelationshipEntity::query()->updateOrCreate(
            [
                'group_relationship_id' => $group->id,
                'entity_id' => $entity->id,
            ],
            [
                'role_in_group' => $role,
                'joined_era' => $joinedEra,
                'is_active_member' => true,
            ],
        );

        $this->touch('group-relationship-memberships');

        return $entry;
    }

    private function upsertFactionMembership(Entity $faction, Entity $member, array $attributes): FactionMembership
    {
        unset($attributes['visibility'], $attributes['content_classification']);

        $membership = FactionMembership::withTrashed()
            ->where('faction_entity_id', $faction->id)
            ->where('member_entity_id', $member->id)
            ->first();

        if ($membership) {
            if ($membership->trashed()) {
                $membership->restore();
            }

            $membership->update($attributes);
            $this->touch('faction-memberships', 'updated');

            return $membership->fresh();
        }

        $membership = $this->relationshipService->createFactionMembership($faction, $member, $attributes);
        $this->touch('faction-memberships', 'created');

        return $membership;
    }

    private function upsertDocument(string $title, array $attributes): Document
    {
        unset($attributes['access_level'], $attributes['known_by_entity_ids']);

        $document = Document::withTrashed()->where('title', $title)->first();

        if ($document) {
            if ($document->trashed()) {
                $document->restore();
            }

            $document->update(array_merge(['title' => $title], $attributes));
            $this->touch('documents', 'updated');

            return $document->fresh();
        }

        $document = Document::create(array_merge(['title' => $title], $attributes));
        $this->touch('documents', 'created');

        return $document;
    }

    private function linkDocumentEntity(Document $document, Entity $entity, string $relationshipType): void
    {
        DocumentEntity::query()->updateOrCreate(
            [
                'document_id' => $document->id,
                'entity_id' => $entity->id,
            ],
            [
                'relationship_type' => $relationshipType,
                'notes' => $this->rich('Seeded document linkage for richer browse and search context.'),
            ],
        );

        $this->flagFlipper->flipDocuments($entity);
        $this->completionUpdater->recalculate($entity->fresh());
    }

    private function upsertEntryPoint(string $sourceUniverse, array $attributes): CrossoverEntryPoint
    {
        $entryPoint = CrossoverEntryPoint::withTrashed()->where('source_universe', $sourceUniverse)->first();

        if ($entryPoint) {
            if ($entryPoint->trashed()) {
                $entryPoint->restore();
            }

            $entryPoint->update(array_merge(['source_universe' => $sourceUniverse], $attributes));
            $this->touch('crossover-entry-points', 'updated');

            return $entryPoint->fresh();
        }

        $entryPoint = CrossoverEntryPoint::create(array_merge(['source_universe' => $sourceUniverse], $attributes));
        $this->touch('crossover-entry-points', 'created');

        return $entryPoint;
    }

    private function upsertCanonReference(string $title, array $attributes): SourceCanonReference
    {
        $reference = SourceCanonReference::withTrashed()->where('title', $title)->first();

        if ($reference) {
            if ($reference->trashed()) {
                $reference->restore();
            }

            $reference->update(array_merge(['title' => $title], $attributes));
            $this->touch('canon-references', 'updated');

            return $reference->fresh();
        }

        $reference = SourceCanonReference::create(array_merge(['title' => $title], $attributes));
        $this->touch('canon-references', 'created');

        return $reference;
    }

    private function linkCanonReferenceEntity(SourceCanonReference $reference, Entity $entity, string $divergenceLevel, string $relationshipType, string $notes): void
    {
        CanonReferenceEntity::query()->updateOrCreate(
            [
                'canon_reference_id' => $reference->id,
                'entity_id' => $entity->id,
            ],
            [
                'divergence_level' => $divergenceLevel,
                'relationship_type' => $relationshipType,
                'divergence_notes' => $this->rich($notes),
            ],
        );
    }

    private function upsertGlossary(string $term, array $attributes): Glossary
    {
        if (isset($attributes['extended_notes'])) {
            $attributes['definition'] = $this->mergeRichDocuments(
                $attributes['definition'] ?? $this->rich(''),
                $attributes['extended_notes'],
            );
            unset($attributes['extended_notes']);
        }

        $glossary = Glossary::withTrashed()->where('term', $term)->first();

        if ($glossary) {
            if ($glossary->trashed()) {
                $glossary->restore();
            }

            $glossary->update(array_merge(['term' => $term], $attributes));
            $this->touch('glossary', 'updated');

            return $glossary->fresh();
        }

        $glossary = Glossary::create(array_merge(['term' => $term], $attributes));
        $this->touch('glossary', 'created');

        return $glossary;
    }

    private function upsertTimelineEntry(Entity $timelineEntity, Entity $eventEntity, array $attributes): Timeline
    {
        unset($attributes['event_significance']);

        $entry = Timeline::withTrashed()
            ->where('timeline_id', $timelineEntity->id)
            ->where('event_entity_id', $eventEntity->id)
            ->first();

        if ($entry) {
            if ($entry->trashed()) {
                $entry->restore();
            }

            $entry->update(array_merge([
                'timeline_id' => $timelineEntity->id,
                'event_entity_id' => $eventEntity->id,
            ], $attributes));
            $this->touch('timeline-entries', 'updated');
        } else {
            $entry = Timeline::create(array_merge([
                'timeline_id' => $timelineEntity->id,
                'event_entity_id' => $eventEntity->id,
            ], $attributes));
            $this->touch('timeline-entries', 'created');
        }

        $this->flagFlipper->flipTimelineEntries($eventEntity);
        $this->completionUpdater->recalculate($eventEntity->fresh());

        return $entry->fresh();
    }

    private function upsertConcurrencyGroup(string $name, array $attributes): ConcurrencyGroup
    {
        $group = ConcurrencyGroup::withTrashed()->where('name', $name)->first();

        if ($group) {
            if ($group->trashed()) {
                $group->restore();
            }

            $group->update(array_merge(['name' => $name], $attributes));
            $this->touch('concurrency-groups', 'updated');

            return $group->fresh();
        }

        $group = ConcurrencyGroup::create(array_merge(['name' => $name], $attributes));
        $this->touch('concurrency-groups', 'created');

        return $group;
    }

    private function upsertCharacterState(Entity $entity, string $label, array $attributes, array $relationshipStates = []): CharacterStateTracker
    {
        $state = CharacterStateTracker::withTrashed()
            ->where('entity_id', $entity->id)
            ->where('snapshot_label', $label)
            ->first();

        $payload = array_merge(['snapshot_label' => $label], $attributes);

        if ($state) {
            if ($state->trashed()) {
                $state->restore();
            }

            $state->update($payload);
            $this->touch('character-states', 'updated');
        } else {
            $state = $this->temporalService->createStateSnapshot($entity, array_merge($payload, [
                'relationship_states' => array_map(
                    fn (array $relation) => [
                        'relationship_id' => $relation['relationship_id'],
                        'state_notes' => $relation['state_notes'],
                    ],
                    $relationshipStates,
                ),
            ]));
            $this->touch('character-states', 'created');
        }

        if ($state->exists && $state->stateRelationships()->exists()) {
            $state->stateRelationships()->delete();
        }

        foreach ($relationshipStates as $relationshipState) {
            $state->stateRelationships()->updateOrCreate(
                [
                    'relationship_id' => $relationshipState['relationship_id'],
                ],
                [
                    'is_active_at_snapshot' => true,
                    'relationship_state_at_snapshot' => $relationshipState['state_notes'],
                ],
            );
        }

        $this->flagFlipper->flipStateSnapshots($entity);
        $this->completionUpdater->recalculate($entity->fresh());

        return $state->fresh();
    }

    private function upsertPowerInteraction(Entity $systemA, Entity $systemB, array $attributes): PowerInteraction
    {
        $existing = PowerInteraction::withTrashed()
            ->where(function ($query) use ($systemA, $systemB) {
                $query->where('system_a_entity_id', $systemA->id)
                    ->where('system_b_entity_id', $systemB->id);
            })->orWhere(function ($query) use ($systemA, $systemB) {
                $query->where('system_a_entity_id', $systemB->id)
                    ->where('system_b_entity_id', $systemA->id);
            })
            ->first();

        if ($existing) {
            if ($existing->trashed()) {
                $existing->restore();
            }

            $interaction = $this->worldService->updatePowerInteraction($existing, array_merge([
                'system_a_entity_id' => $systemA->id,
                'system_b_entity_id' => $systemB->id,
            ], $attributes));
            $this->touch('power-interactions', 'updated');

            return $interaction;
        }

        $interaction = $this->worldService->createPowerInteraction(array_merge([
            'system_a_entity_id' => $systemA->id,
            'system_b_entity_id' => $systemB->id,
        ], $attributes));
        $this->touch('power-interactions', 'created');

        return $interaction;
    }

    private function upsertContainment(Entity $child, Entity $parent, string $type, array $attributes): LocationContainment
    {
        unset($attributes['notes']);

        $containment = LocationContainment::withTrashed()
            ->where('child_location_entity_id', $child->id)
            ->where('parent_location_entity_id', $parent->id)
            ->where('containment_type', $type)
            ->first();

        if ($containment) {
            if ($containment->trashed()) {
                $containment->restore();
            }

            $containment->update(array_merge([
                'child_location_entity_id' => $child->id,
                'parent_location_entity_id' => $parent->id,
                'containment_type' => $type,
                'is_active' => true,
            ], $attributes));
            $this->touch('location-containment', 'updated');

            return $containment->fresh();
        }

        $containment = $this->worldService->contain($child, $parent, $type, $attributes);
        $this->touch('location-containment', 'created');

        return $containment;
    }

    private function upsertTravelRoute(Entity $origin, Entity $destination, string $type, array $attributes): TravelRoute
    {
        if (isset($attributes['notes']) && ! isset($attributes['duration_notes'])) {
            $attributes['duration_notes'] = $attributes['notes'];
        }

        unset($attributes['notes']);

        $route = TravelRoute::withTrashed()
            ->where('origin_location_entity_id', $origin->id)
            ->where('destination_location_entity_id', $destination->id)
            ->where('route_type', $type)
            ->first();

        $payload = array_merge([
            'origin_location_entity_id' => $origin->id,
            'destination_location_entity_id' => $destination->id,
            'route_type' => $type,
            'is_active' => true,
            'method_variants' => $attributes['method_variants'] ?? [],
            'hazards' => $attributes['hazards'] ?? [],
        ], $attributes);

        if ($route) {
            if ($route->trashed()) {
                $route->restore();
            }

            $route->update($payload);
            $this->touch('travel-routes', 'updated');

            return $route->fresh();
        }

        $route = $this->worldService->createRoute($origin, $destination, $type, $payload);
        $this->touch('travel-routes', 'created');

        return $route;
    }

    private function upsertLocationControl(Entity $location, Entity $controller, string $controlType, array $attributes): LocationControlHistory
    {
        unset($attributes['visibility'], $attributes['content_classification']);

        $record = LocationControlHistory::withTrashed()
            ->where('location_entity_id', $location->id)
            ->where('controlling_entity_id', $controller->id)
            ->where('control_type', $controlType)
            ->where('is_current', true)
            ->first();

        if ($record) {
            if ($record->trashed()) {
                $record->restore();
            }

            $record = $this->worldService->updateControlHistory($record, array_merge([
                'location_entity_id' => $location->id,
                'controlling_entity_id' => $controller->id,
                'control_type' => $controlType,
                'is_current' => true,
            ], $attributes));
            $this->touch('location-control-records', 'updated');

            return $record;
        }

        $record = $this->worldService->recordControlChange($location, $controller, $controlType, $attributes);
        $this->touch('location-control-records', 'created');

        return $record;
    }

    private function upsertSecret(string $title, array $attributes): Secret
    {
        $secret = Secret::withTrashed()->where('title', $title)->first();

        if ($secret) {
            if ($secret->trashed()) {
                $secret->restore();
            }

            $secret = $this->intelligenceService->updateSecret($secret, array_merge(['title' => $title], $attributes));
            $this->touch('secrets', 'updated');

            return $secret;
        }

        $secret = $this->intelligenceService->createSecret(array_merge(['title' => $title], $attributes));
        $this->touch('secrets', 'created');

        return $secret;
    }

    private function upsertKnowledgeState(Entity $knower, array $attributes): KnowledgeState
    {
        $subjectField = collect([
            'subject_entity_id',
            'subject_relationship_id',
            'subject_group_relationship_id',
            'subject_event_id',
            'subject_secret_id',
        ])->first(fn (string $field) => array_key_exists($field, $attributes) && $attributes[$field] !== null);

        $knowledge = null;

        if ($subjectField !== null) {
            $knowledge = KnowledgeState::withTrashed()
                ->where('knower_entity_id', $knower->id)
                ->where($subjectField, $attributes[$subjectField])
                ->where('knowledge_type', $attributes['knowledge_type'] ?? 'secret')
                ->first();
        }

        if ($knowledge) {
            if ($knowledge->trashed()) {
                $knowledge->restore();
            }

            $knowledge->update(array_merge(['knower_entity_id' => $knower->id], $attributes, ['is_current' => true]));
            $this->touch('knowledge-states', 'updated');

            return $knowledge->fresh();
        }

        $knowledge = $this->intelligenceService->recordKnowledge($knower, $attributes);
        $this->touch('knowledge-states', 'created');

        return $knowledge;
    }

    private function upsertPerceptionState(string $subjectType, int $subjectId, array $attributes): PerceptionState
    {
        $state = PerceptionState::withTrashed()
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId)
            ->where('is_current', true)
            ->first();

        $payload = array_merge([
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'is_current' => true,
        ], $attributes);

        if ($state) {
            if ($state->trashed()) {
                $state->restore();
            }

            $state->update($payload);
            $this->touch('perception-states', 'updated');

            return $state->fresh();
        }

        $state = $this->intelligenceService->createPerceptionGap($payload);
        $this->touch('perception-states', 'created');

        return $state;
    }

    private function upsertCollection(string $name, array $attributes): Collection
    {
        $collection = Collection::withTrashed()->where('name', $name)->first();

        if ($collection) {
            if ($collection->trashed()) {
                $collection->restore();
            }

            $collection = $this->collectionService->update($collection, array_merge(['name' => $name], $attributes));
            $this->touch('collections', 'updated');

            return $collection;
        }

        $collection = $this->collectionService->create(array_merge(['name' => $name], $attributes));
        $this->touch('collections', 'created');

        return $collection;
    }

    private function upsertMeta(string $title, array $attributes): Meta
    {
        $meta = Meta::withTrashed()->where('title', $title)->first();

        if ($meta) {
            if ($meta->trashed()) {
                $meta->restore();
            }

            $meta->update(array_merge(['title' => $title], $attributes));
            $this->touch('meta', 'updated');

            return $meta->fresh();
        }

        $meta = Meta::create(array_merge(['title' => $title], $attributes));
        $this->touch('meta', 'created');

        return $meta;
    }

    private function upsertPipelineItem(string $title, array $attributes): PipelineItem
    {
        $item = PipelineItem::withTrashed()->where('title', $title)->first();

        if ($item) {
            if ($item->trashed()) {
                $item->restore();
            }

            $item->update(array_merge(['title' => $title], $attributes));
            $this->touch('pipeline-items', 'updated');

            return $item->fresh();
        }

        $sortOrder = (PipelineItem::query()->where('parent_pipeline_item_id', $attributes['parent_pipeline_item_id'] ?? null)->max('sort_order') ?? 0) + 1;
        $item = PipelineItem::create(array_merge(['title' => $title, 'sort_order' => $sortOrder], $attributes));
        $this->touch('pipeline-items', 'created');

        return $item;
    }

    private function upsertSessionLog(string $title, array $attributes): SessionLog
    {
        $log = SessionLog::withTrashed()->where('title', $title)->first();

        if ($log) {
            if ($log->trashed()) {
                $log->restore();
            }

            $log->update(array_merge(['title' => $title], $attributes));
            $this->touch('session-logs', 'updated');

            return $log->fresh();
        }

        $log = SessionLog::create(array_merge(['title' => $title], $attributes));
        $this->touch('session-logs', 'created');

        return $log;
    }

    private function upsertAlias(Entity $entity, string $alias, string $aliasType, string $context): void
    {
        EntityAlias::query()->updateOrCreate(
            ['entity_id' => $entity->id, 'alias' => $alias],
            [
                'alias_type' => $aliasType,
                'context' => $context,
                'is_active' => true,
                'known_by_entity_ids' => [],
                'visibility' => VisibilityLevel::PUBLIC_KNOWLEDGE,
                'content_classification' => ContentClassification::PUBLIC,
            ],
        );

        $this->flagFlipper->flipAliases($entity);
        $this->completionUpdater->recalculate($entity->fresh());
    }

    private function upsertEntityNote(Entity $entity, string $label, string $content): void
    {
        EntityNote::query()->updateOrCreate(
            ['entity_id' => $entity->id, 'note_label' => $label],
            [
                'content' => $content,
                'sort_order' => 10,
            ],
        );
    }

    private function upsertEntityQuestion(Entity $entity, string $question, string $context, string $status, string $priority): void
    {
        EntityQuestion::query()->updateOrCreate(
            ['entity_id' => $entity->id, 'question' => $question],
            [
                'context' => $context,
                'status' => $status,
                'priority' => $priority,
                'sort_order' => 10,
            ],
        );
    }

    private function upsertMediaReference(array $identity, array $attributes): MediaReference
    {
        $media = MediaReference::withTrashed()->where($identity)->first();

        if ($media) {
            if ($media->trashed()) {
                $media->restore();
            }

            $media->update(array_merge($identity, $attributes));
            $this->touch('media-references', 'updated');
        } else {
            $media = MediaReference::create(array_merge($identity, $attributes));
            $this->touch('media-references', 'created');
        }

        if (isset($identity['entity_id'])) {
            $entity = Entity::find($identity['entity_id']);

            if ($entity) {
                $this->flagFlipper->flipMedia($entity);
                $this->completionUpdater->recalculate($entity->fresh());
            }
        }

        return $media->fresh();
    }

    private function rich(string $text): array
    {
        $paragraphs = collect(preg_split('/\R{2,}/u', trim($text)) ?: [])
            ->map(static fn (string $paragraph) => trim($paragraph))
            ->filter()
            ->map(static fn (string $paragraph) => [
                'type' => 'paragraph',
                'content' => [[
                    'type' => 'text',
                    'text' => $paragraph,
                ]],
            ])
            ->values()
            ->all();

        return [
            'type' => 'doc',
            'content' => $paragraphs,
        ];
    }

    private function mergeRichDocuments(array $left, array $right): array
    {
        return [
            'type' => 'doc',
            'content' => array_values(array_merge($left['content'] ?? [], $right['content'] ?? [])),
        ];
    }

    private function touch(string $resource, string $operation = 'touched'): void
    {
        if (! isset($this->stats[$resource])) {
            $this->stats[$resource] = [];
        }

        $this->stats[$resource][$operation] = ($this->stats[$resource][$operation] ?? 0) + 1;
    }
}
