<?php

namespace Database\Seeders;

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
use App\Domain\Production\Models\Meta;
use App\Domain\Production\Models\PipelineItem;
use App\Domain\Production\Models\SessionLog;
use App\Domain\Production\Services\ProductionService;
use App\Domain\Temporal\Models\CharacterStateTracker;
use App\Domain\Temporal\Models\ConcurrencyGroup;
use App\Domain\Temporal\Models\Timeline;
use App\Domain\Temporal\Services\TemporalService;
use App\Domain\World\Models\LocationContainment;
use App\Domain\World\Models\LocationControlHistory;
use App\Domain\World\Models\PowerInteraction;
use App\Domain\World\Models\PowerInteractionInstance;
use App\Domain\World\Models\TravelRoute;
use App\Domain\World\Services\WorldService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CuratedCrossoverSeeder extends Seeder
{
    private EntityService $entityService;

    private RelationshipService $relationshipService;

    private TemporalService $temporalService;

    private WorldService $worldService;

    private IntelligenceService $intelligenceService;

    private ProductionService $productionService;

    private FlipEntityCompletionFlags $flagFlipper;

    private UpdateCompletionScore $completionUpdater;

    public function run(): void
    {
        $this->entityService = app(EntityService::class);
        $this->relationshipService = app(RelationshipService::class);
        $this->temporalService = app(TemporalService::class);
        $this->worldService = app(WorldService::class);
        $this->intelligenceService = app(IntelligenceService::class);
        $this->productionService = app(ProductionService::class);
        $this->flagFlipper = app(FlipEntityCompletionFlags::class);
        $this->completionUpdater = app(UpdateCompletionScore::class);

        DB::transaction(function (): void {
            $entities = $this->seedEntities();
            $relationships = $this->seedRelationships($entities);
            $groups = $this->seedGroupRelationships($entities);
            $this->seedFactionMemberships($entities);

            $timelines = $this->seedTimelineEntries($entities);
            $documents = $this->seedDocuments($entities);
            $this->seedMedia($entities, $timelines);
            $collections = $this->seedCollections($entities, $documents);
            $this->seedGlossary($entities);
            $entryPoints = $this->seedCrossoverEntryPoints($entities);
            $this->seedCanonReferences($entities, $entryPoints);

            $secrets = $this->seedSecrets($entities);
            $knowledgeStates = $this->seedKnowledgeStates($entities, $relationships, $secrets);
            $this->seedPerceptionStates($entities, $knowledgeStates, $secrets);
            $this->seedPowerInteractions($entities);
            $this->seedSpatialRecords($entities);

            $meta = $this->seedMeta($entities, $groups);
            $sessions = $this->seedSessionLogs($entities, $collections);
            $this->seedPipeline($entities, $meta);
            $this->seedEntityNotesAndQuestions($entities, $groups, $sessions);

            $this->refreshCompletion($entities);
            $this->publishShowcaseEntities($entities);
        });

        $this->command?->info('Curated crossover content seeded into the live database.');
    }

    /**
     * @return array<string, Entity>
     */
    private function seedEntities(): array
    {
        $entities = [];

        $entities['harry'] = $this->upsertEntity('Harry Potter', [
            'entity_type' => 'character',
            'public_title' => 'The Surviving Anchor',
            'summary' => $this->doc(
                'A crossover-era Harry who has survived enough branch failures to recognize when reality is trying to smooth him back into a simpler story.',
                'In this continuity he functions less as a destined savior and more as a stubborn fixed point who keeps other people from being quietly edited out of the narrative.'
            ),
            'public_summary' => $this->doc(
                'Auror-trained, branch-scarred, and impossible to intimidate for long, Harry is one of the first people to realize the Roshar contact is real and dangerously usable.'
            ),
            'source_universes' => ['Harry Potter', 'Original'],
            'origin_type' => 'alternate',
            'origin_notes' => $this->doc(
                'This version keeps canon instincts but carries branch-memory residue from timelines where the war ended differently.',
                'Those residues make him unusually resistant to narrative smoothing, false consensus, and engineered certainty.'
            ),
            'status' => 'active',
            'type_status' => 'field-operational',
            'visibility' => 'private',
            'content_classification' => 'restricted',
            'power_tier_ceiling' => 'regional',
            'power_tier_operating' => 'street_level',
            'power_tier_influence' => 'regional',
            'public_persona' => $this->doc('Dry, controlled, and more professional than myth ever made room for.'),
            'true_nature' => $this->doc(
                'Harry has become an anchor rather than a hero-protagonist.',
                'Reality resists losing him because too many other contradictions have been stabilized around his survival.'
            ),
            'persona_divergence' => 'He presents as tired competence; underneath is a man constantly choosing mercy over strategic brutality.',
            'attributes' => [
                'core_skills' => ['defensive magic', 'field improvisation', 'cross-domain threat assessment'],
                'signature_items' => ['holly wand', 'old auror field notebook'],
                'current_role' => 'informal external stabilizer',
            ],
        ]);

        $entities['hermione'] = $this->upsertEntity('Hermione Granger', [
            'entity_type' => 'character',
            'public_title' => 'Resonance Auditor',
            'summary' => $this->doc(
                'Hermione becomes the first person in the British magical world to treat crossover contact as a systems problem instead of a miracle or a threat posture.',
                'Her role in the convergence is equal parts archivist, skeptic, translator, and quiet architect of sane procedure.'
            ),
            'public_summary' => $this->doc(
                'She is the mind most likely to turn impossible crossover mechanics into a reliable process without stripping the awe out of them.'
            ),
            'source_universes' => ['Harry Potter', 'Original'],
            'origin_type' => 'alternate',
            'origin_notes' => $this->doc(
                'This Hermione matures into a person willing to protect uncertainty until the evidence is good enough to trust.'
            ),
            'status' => 'active',
            'type_status' => 'research-active',
            'visibility' => 'private',
            'content_classification' => 'restricted',
            'power_tier_ceiling' => 'regional',
            'power_tier_operating' => 'street_level',
            'power_tier_influence' => 'regional',
            'public_persona' => $this->doc('Composed, exacting, and visibly calmer than the people trying to bluff her.'),
            'true_nature' => $this->doc(
                'Hermione is the primary interpreter of the convergence.',
                'What she fears most is not being wrong; it is being right too early and letting institutions weaponize an unfinished understanding.'
            ),
            'persona_divergence' => 'Her discipline reads as certainty, but much of it is ethical restraint under pressure.',
            'attributes' => [
                'core_skills' => ['structured research', 'ritual analysis', 'cross-cultural translation'],
                'signature_items' => ['annotated resonance ledger', 'ward-testing kit'],
                'current_role' => 'lead coherence researcher',
            ],
        ]);

        $entities['kaladin'] = $this->upsertEntity('Kaladin Stormblessed', [
            'entity_type' => 'character',
            'public_title' => 'Windrunner in Exile',
            'summary' => $this->doc(
                'Kaladin arrives in the crossover carrying the same impossible combination of tenderness, exhaustion, and battlefield instinct that defines him in Roshar.',
                'He becomes one of the few people who can read the moral shape of the Grey Line problem before he understands the mechanics.'
            ),
            'public_summary' => $this->doc(
                'He is drawn to broken systems because he knows what it costs when everyone learns to endure them instead of changing them.'
            ),
            'source_universes' => ['Stormlight Archive', 'Cosmere'],
            'origin_type' => 'canonical',
            'canon_deviation' => 'moderate',
            'origin_notes' => $this->doc(
                'Kaladin enters after enough oaths and enough grief that he no longer mistakes competence for health.',
                'The crossover pressures his duty reflexes in ways Roshar never quite managed to.'
            ),
            'status' => 'active',
            'type_status' => 'field-operational',
            'visibility' => 'private',
            'content_classification' => 'restricted',
            'power_tier_ceiling' => 'national',
            'power_tier_operating' => 'regional',
            'power_tier_influence' => 'factional',
            'public_persona' => $this->doc('Severe, observant, and unexpectedly gentle with frightened people.'),
            'true_nature' => $this->doc(
                'Kaladin is not the muscle of the crossover cast.',
                'He is its conscience whenever procedure starts to sound too much like sacrifice made convenient.'
            ),
            'persona_divergence' => 'Others read him as stoic; he is actually processing more pain than most rooms can hold.',
            'attributes' => [
                'core_skills' => ['surgebinding', 'triage leadership', 'tactical extraction'],
                'signature_items' => ['Bridge Four patch', 'Syl-silvered spear'],
                'current_role' => 'protective response lead',
            ],
        ]);

        $entities['shallan'] = $this->upsertEntity('Shallan Davar', [
            'entity_type' => 'character',
            'public_title' => 'Pattern-Bearing Infiltrator',
            'summary' => $this->doc(
                'Shallan recognizes faster than anyone else that the Grey Line runs on story management, selective revelation, and cultivated masks.',
                'Her usefulness to the crossover lies in her ability to notice which lies are structural and which are desperate.'
            ),
            'public_summary' => $this->doc(
                'She is both a gifted infiltrator and one of the people least able to pretend masks are harmless.'
            ),
            'source_universes' => ['Stormlight Archive', 'Cosmere'],
            'origin_type' => 'canonical',
            'canon_deviation' => 'moderate',
            'origin_notes' => $this->doc(
                'The crossover places Shallan into a setting where narrative performance is itself a weaponized technology.'
            ),
            'status' => 'active',
            'type_status' => 'embedded',
            'visibility' => 'private',
            'content_classification' => 'restricted',
            'power_tier_ceiling' => 'regional',
            'power_tier_operating' => 'regional',
            'power_tier_influence' => 'factional',
            'public_persona' => $this->doc('Witty, charming, mildly unserious, and therefore dangerously easy to underestimate.'),
            'true_nature' => $this->doc(
                'Shallan spots the emotional architecture of institutions the way other people notice weather.',
                'Her own fractures make her uniquely capable of reading systems built from compartmentalization.'
            ),
            'persona_divergence' => 'Her humor is both authentic and tactical; it keeps rooms open long enough to see what they are hiding.',
            'attributes' => [
                'core_skills' => ['lightweaving', 'social infiltration', 'symbol decoding'],
                'signature_items' => ['sketchbook', 'pattern-bound mnemonic deck'],
                'current_role' => 'counter-deception specialist',
            ],
        ]);

        $entities['seraphine'] = $this->upsertEntity('Seraphine Morbraith', [
            'entity_type' => 'character',
            'public_title' => 'Grey Line Liaison',
            'summary' => $this->doc(
                'Seraphine Morbraith is the most obviously composed person in any room and almost always the person under the most pressure.',
                'She keeps the Grey Line working, understands why it should probably be dismantled, and continues to hold both truths without collapsing.'
            ),
            'public_summary' => $this->doc(
                'A gifted strategist and social engineer whose calm depends on keeping three incompatible loyalties moving in the same direction.'
            ),
            'source_universes' => ['Original'],
            'origin_type' => 'original',
            'origin_notes' => $this->doc(
                'Seraphine is original to the AU and exists to embody the costs of building orderly systems around miraculous accidents.'
            ),
            'status' => 'active',
            'type_status' => 'compartmentalized',
            'visibility' => 'private',
            'content_classification' => 'secret',
            'power_tier_ceiling' => 'regional',
            'power_tier_operating' => 'street_level',
            'power_tier_influence' => 'factional',
            'public_persona' => $this->doc('Graceful, warm, impossible to rattle, and endlessly prepared.'),
            'true_nature' => $this->doc(
                'Seraphine is not the villain of the Grey Line crisis; she is the person who understands every reason it exists and every reason it should end.',
                'Her danger lies in how good she is at making coercion sound temporary and necessary.'
            ),
            'persona_divergence' => 'The polished liaison mask covers a woman running on sleep debt, guilt, and a private exit plan.',
            'attributes' => [
                'core_skills' => ['social leverage', 'threshold logistics', 'covert negotiation'],
                'signature_items' => ['lantern-ring sigil', 'sealed route index'],
                'current_role' => 'operations spine',
            ],
        ]);

        $entities['hogwarts'] = $this->upsertEntity('Hogwarts Castle', [
            'entity_type' => 'location',
            'public_title' => 'The School at the Edge of the Breach',
            'summary' => $this->doc(
                'Hogwarts remains a school, but in this branch it also becomes a staging ground for first contact logistics, magical ethics debates, and dangerous new traffic control.',
                'The castle absorbs impossible history with the same stubbornness it shows every other crisis.'
            ),
            'public_summary' => $this->doc(
                'A place built for learning and secrets that is now forced to become a border institution.'
            ),
            'source_universes' => ['Harry Potter'],
            'origin_type' => 'canonical',
            'canon_deviation' => 'minor',
            'origin_notes' => $this->doc('Canon Hogwarts, but pressed into service as a convergence edge instead of remaining insulated from multiversal spillover.'),
            'status' => 'active',
            'type_status' => 'anchored',
            'visibility' => 'private',
            'content_classification' => 'restricted',
            'space_type' => 'anchored castle complex',
            'attributes' => [
                'narrative_role' => 'border campus',
                'ward_behavior' => 'absorbs low-grade crossover static',
                'key_spaces' => ['north tower observatory', 'stone bridge threshold yard'],
            ],
            'true_nature' => $this->doc(
                'Hogwarts is not merely a backdrop in the crossover; it becomes an active stabilizer because its wards know how to hold contradictory magical rules in tension without immediate collapse.'
            ),
        ]);

        $entities['forest'] = $this->upsertEntity('Forbidden Forest', [
            'entity_type' => 'location',
            'summary' => $this->doc(
                'The forest becomes the first place where British magic and Rosharan resonance can overlap without instantly tearing themselves apart.'
            ),
            'source_universes' => ['Harry Potter', 'Original'],
            'origin_type' => 'alternate',
            'status' => 'active',
            'type_status' => 'threshold-adjacent',
            'visibility' => 'private',
            'content_classification' => 'restricted',
            'space_type' => 'wild warded forest',
            'attributes' => ['hazards' => ['crosswind static', 'misleading paths', 'echoed voices']],
            'true_nature' => $this->doc('The forest is the first landscape to learn the rhythm of the convergence and survive it.'),
        ]);

        $entities['urithiru'] = $this->upsertEntity('Urithiru', [
            'entity_type' => 'location',
            'public_title' => 'Tower of the Second Horizon',
            'summary' => $this->doc(
                'Urithiru provides the cleanest large-scale Rosharan environment for controlled study of the crossing because the tower can register changes in resonance almost before people do.'
            ),
            'source_universes' => ['Stormlight Archive', 'Cosmere'],
            'origin_type' => 'canonical',
            'canon_deviation' => 'minor',
            'status' => 'active',
            'type_status' => 'observatory',
            'visibility' => 'private',
            'content_classification' => 'restricted',
            'space_type' => 'ancient living tower',
            'attributes' => [
                'narrative_role' => 'counterpart research seat',
                'key_spaces' => ['map room', 'upper atrium', 'relay vault annex'],
            ],
            'true_nature' => $this->doc(
                'Urithiru is both sanctuary and diagnostic instrument.',
                'The tower notices moral stress in a room almost as fast as it notices Investiture turbulence.'
            ),
        ]);

        $entities['veilfracture'] = $this->upsertEntity('Veilfracture Crossing', [
            'entity_type' => 'convergence_point',
            'public_title' => 'The First Stable Breach',
            'summary' => $this->doc(
                'A managed crossing site hidden under ritualized ruin language so that fewer people realize it is still active.'
            ),
            'source_universes' => ['Harry Potter', 'Stormlight Archive', 'Original'],
            'origin_type' => 'original',
            'status' => 'active',
            'type_status' => 'engineered-threshold',
            'visibility' => 'secret',
            'content_classification' => 'secret',
            'space_type' => 'dimensional seam',
            'attributes' => [
                'stability_class' => 'managed but fragile',
                'required_conditions' => ['towerlight-adjacent resonance', 'wand focus', 'human witness'],
            ],
            'true_nature' => $this->doc(
                'The site is less a doorway than a negotiated truce between incompatible systems.',
                'It remains stable only because too many people have now treated it as real.'
            ),
        ]);

        $entities['relay_vault'] = $this->upsertEntity('Grey Line Relay Vault', [
            'entity_type' => 'location',
            'summary' => $this->doc(
                'A hidden operational room inside Urithiru used to stage messages, caches, and route maps tied to the Grey Line crossing network.'
            ),
            'source_universes' => ['Original', 'Stormlight Archive'],
            'origin_type' => 'original',
            'status' => 'active',
            'type_status' => 'operational',
            'visibility' => 'author_only',
            'content_classification' => 'secret',
            'space_type' => 'hidden annex',
            'attributes' => ['warded_storage' => true, 'route_index_copies' => 3],
            'true_nature' => $this->doc('The vault is where logistics turns into narrative power.'),
        ]);

        $entities['order'] = $this->upsertEntity('Order of the Phoenix', [
            'entity_type' => 'faction',
            'summary' => $this->doc(
                'The Order survives into the crossover era as a smaller, more procedural organization trying very hard not to become the sort of secret authority it once resisted.'
            ),
            'source_universes' => ['Harry Potter'],
            'origin_type' => 'canonical',
            'status' => 'active',
            'type_status' => 'reconstituted',
            'visibility' => 'private',
            'content_classification' => 'restricted',
            'control_state' => 'distributed resistance network',
            'attributes' => ['focus' => ['containment ethics', 'protective intelligence', 'witness security']],
            'true_nature' => $this->doc('The Order functions as Hogwarts-side conscience and emergency brake.'),
        ]);

        $entities['radiants'] = $this->upsertEntity('Knights Radiant', [
            'entity_type' => 'faction',
            'summary' => $this->doc(
                'In the convergence they act as the Rosharan moral center, bringing oaths, practical force, and a deeply uncomfortable relationship with necessary secrecy.'
            ),
            'source_universes' => ['Stormlight Archive', 'Cosmere'],
            'origin_type' => 'canonical',
            'status' => 'active',
            'type_status' => 'mobilized',
            'visibility' => 'private',
            'content_classification' => 'restricted',
            'control_state' => 'plural oathbound command',
            'attributes' => ['focus' => ['civilian protection', 'tower response', 'cross-domain discipline']],
            'true_nature' => $this->doc('They are the faction most likely to ask whether a solution deserves to exist.'),
        ]);

        $entities['grey_line'] = $this->upsertEntity('Grey Line Accord', [
            'entity_type' => 'organization',
            'summary' => $this->doc(
                'A secretive cross-domain logistics apparatus formed to keep the first stable breaches from becoming public disasters.'
            ),
            'source_universes' => ['Original'],
            'origin_type' => 'original',
            'status' => 'active',
            'type_status' => 'overextended',
            'visibility' => 'secret',
            'content_classification' => 'secret',
            'attributes' => ['focus' => ['route management', 'containment doctrine', 'memory-safe reporting']],
            'true_nature' => $this->doc(
                'The Grey Line is a triage institution that keeps inventing reasons not to sunset itself.'
            ),
        ]);

        $entities['mirror_archive'] = $this->upsertEntity('Mirror Archive', [
            'entity_type' => 'organization',
            'summary' => $this->doc(
                'A rival archival network that believes the Grey Line is too centralized and too willing to decide what counts as survivable truth.'
            ),
            'source_universes' => ['Original'],
            'origin_type' => 'original',
            'status' => 'active',
            'type_status' => 'covert',
            'visibility' => 'author_only',
            'content_classification' => 'secret',
            'attributes' => ['focus' => ['redundant archives', 'counter-leverage', 'escape routes']],
            'true_nature' => $this->doc('They are less noble than they think and less wrong than the Grey Line admits.'),
        ]);

        $entities['silken_court'] = $this->upsertEntity('Silken Court', [
            'entity_type' => 'faction',
            'summary' => $this->doc(
                'A glamour-trained pressure group with its own designs on convergence infrastructure and a talent for turning emotional weakness into leverage.'
            ),
            'source_universes' => ['Original'],
            'origin_type' => 'original',
            'status' => 'active',
            'type_status' => 'predatory',
            'visibility' => 'secret',
            'content_classification' => 'secret',
            'control_state' => 'distributed handlers and clients',
            'attributes' => ['focus' => ['glamour operations', 'proxy influence', 'emotional compartmentalization']],
            'true_nature' => $this->doc('Their real interest is not transit but the people who can be shaped by transit.'),
        ]);

        $entities['wandcraft'] = $this->upsertEntity('British Wandcraft', [
            'entity_type' => 'magic_system',
            'summary' => $this->doc(
                'An intent-responsive, tool-mediated system built around wand focus, verbal shaping, and inherited pedagogy.'
            ),
            'source_universes' => ['Harry Potter'],
            'origin_type' => 'canonical',
            'status' => 'active',
            'type_status' => 'mapped',
            'visibility' => 'private',
            'content_classification' => 'restricted',
            'attributes' => ['traits' => ['focus-bound', 'gesture-assisted', 'institutionally taught']],
            'true_nature' => $this->doc('Under crossover pressure, wandcraft behaves more like a linguistic steering system than a finite spell list.'),
        ]);

        $entities['surgebinding'] = $this->upsertEntity('Surgebinding', [
            'entity_type' => 'power_system',
            'summary' => $this->doc(
                'Rosharan Investiture practice shaped by oaths, spren bonds, and environmental fuel states.'
            ),
            'source_universes' => ['Stormlight Archive', 'Cosmere'],
            'origin_type' => 'canonical',
            'status' => 'active',
            'type_status' => 'mapped',
            'visibility' => 'private',
            'content_classification' => 'restricted',
            'attributes' => ['traits' => ['oath-gated', 'bond-mediated', 'light-fueled']],
            'true_nature' => $this->doc('Across the crossing, Surgebinding acts like a moral grammar exerting pressure on any system trying to fake stability.'),
        ]);

        $entities['grey_line_weaving'] = $this->upsertEntity('Grey Line Weaving', [
            'entity_type' => 'power_system',
            'summary' => $this->doc(
                'A practical original system of threshold management, resonance smoothing, and ritualized misdirection developed by the Grey Line.'
            ),
            'source_universes' => ['Original'],
            'origin_type' => 'original',
            'status' => 'active',
            'type_status' => 'underdefined',
            'visibility' => 'secret',
            'content_classification' => 'secret',
            'attributes' => ['traits' => ['threshold-focused', 'collective maintenance', 'ethically compromised']],
            'true_nature' => $this->doc('It is half magical technique and half organizational habit.'),
        ]);

        $entities['timeline_convergence'] = $this->upsertEntity('Convergence: Hogwarts-Roshar', [
            'entity_type' => 'timeline',
            'summary' => $this->doc('The main crossover sequence tracking first contact, study, and accelerating institutional strain.'),
            'source_universes' => ['Harry Potter', 'Stormlight Archive', 'Original'],
            'origin_type' => 'original',
            'status' => 'active',
            'type_status' => 'primary',
            'visibility' => 'private',
            'content_classification' => 'restricted',
            'true_nature' => $this->doc('This timeline measures when improvisation stops being enough.'),
        ]);

        $entities['timeline_tribunal'] = $this->upsertEntity('Pressure Sequence: Grey Line Tribunal', [
            'entity_type' => 'timeline',
            'summary' => $this->doc('A tighter sequence focused on the political unspooling around the Grey Line response apparatus.'),
            'source_universes' => ['Original', 'Stormlight Archive'],
            'origin_type' => 'original',
            'status' => 'active',
            'type_status' => 'secondary',
            'visibility' => 'private',
            'content_classification' => 'restricted',
            'true_nature' => $this->doc('This timeline exists to measure institutional pressure rather than chronology alone.'),
        ]);

        $entities['event_crossing'] = $this->upsertEntity('The First Stable Crossing', [
            'entity_type' => 'event',
            'summary' => $this->doc(
                'The first intentionally repeated transit event that proved the Hogwarts and Urithiru endpoints could be stabilized.'
            ),
            'source_universes' => ['Harry Potter', 'Stormlight Archive', 'Original'],
            'origin_type' => 'original',
            'status' => 'active',
            'type_status' => 'pivotal',
            'visibility' => 'private',
            'content_classification' => 'restricted',
            'attributes' => ['participants' => ['Harry Potter', 'Hermione Granger', 'Kaladin Stormblessed']],
            'true_nature' => $this->doc('This was the moment the crossover stopped being theory and started demanding policy.'),
        ]);

        $entities['event_spanreed'] = $this->upsertEntity('The Spanreed Owl Exchange', [
            'entity_type' => 'event',
            'summary' => $this->doc(
                'The first successful exchange of coherent messages across the crossing using both magical and Rosharan communication logic.'
            ),
            'source_universes' => ['Harry Potter', 'Stormlight Archive', 'Original'],
            'origin_type' => 'original',
            'status' => 'active',
            'type_status' => 'major',
            'visibility' => 'private',
            'content_classification' => 'restricted',
            'attributes' => ['participants' => ['Hermione Granger', 'Shallan Davar']],
            'true_nature' => $this->doc('It matters because language survived translation before institutions did.'),
        ]);

        $entities['event_tribunal'] = $this->upsertEntity('Grey Line Tribunal at Urithiru', [
            'entity_type' => 'conflict',
            'summary' => $this->doc(
                'An attempted internal reckoning that turned into a jurisdictional fight over who gets to decide what the convergence is allowed to become.'
            ),
            'source_universes' => ['Original', 'Stormlight Archive'],
            'origin_type' => 'original',
            'status' => 'active',
            'type_status' => 'major',
            'visibility' => 'secret',
            'content_classification' => 'secret',
            'attributes' => ['participants' => ['Seraphine Morbraith', 'Grey Line Accord', 'Knights Radiant']],
            'true_nature' => $this->doc('The tribunal is where the Grey Line finally loses the ability to pretend it is only a technical body.'),
        ]);

        $entities['event_duel'] = $this->upsertEntity('Duel Under the Broken Canopy', [
            'entity_type' => 'conflict',
            'summary' => $this->doc(
                'A running confrontation in and around the forest threshold after the tribunal failure pushes bad actors into open motion.'
            ),
            'source_universes' => ['Harry Potter', 'Original'],
            'origin_type' => 'original',
            'status' => 'active',
            'type_status' => 'major',
            'visibility' => 'secret',
            'content_classification' => 'secret',
            'attributes' => ['participants' => ['Harry Potter', 'Seraphine Morbraith', 'Silken Court']],
            'true_nature' => $this->doc('It is the first time the convergence becomes visibly violent on the Hogwarts side.'),
        ]);

        return $entities;
    }

    /**
     * @param  array<string, Entity>  $entities
     * @return array<string, Relationship>
     */
    private function seedRelationships(array $entities): array
    {
        $relationships = [];

        $relationships['harry_hermione'] = $this->upsertRelationship(
            $entities['harry'],
            $entities['hermione'],
            [
                'relationship_type' => 'knowledge',
                'direction' => 'mutual_equal',
                'current_tension_charge' => 'positive',
                'perspective_a' => $this->doc('Harry trusts Hermione to tell him the truth even when the truth is strategically inconvenient.'),
                'perspective_b' => $this->doc('Hermione trusts Harry to keep a human center when the research becomes frighteningly useful.'),
                'strength_from_a' => 'high',
                'strength_from_b' => 'high',
                'perceived_type' => 'colleagues',
                'true_type' => 'anchor-partnership',
                'perception_divergence' => 'Observers see professionalism and miss how much emotional calibration happens between them.',
                'notes' => $this->doc('This relationship stabilizes several other moving parts in the crossover cast.'),
                'visibility' => 'private',
                'content_classification' => 'restricted',
            ]
        );

        $relationships['harry_kaladin'] = $this->upsertRelationship(
            $entities['harry'],
            $entities['kaladin'],
            [
                'relationship_type' => 'crossover',
                'direction' => 'mutual_equal',
                'current_tension_charge' => 'complex',
                'perspective_a' => $this->doc('Harry sees Kaladin as the sort of protector institutions rarely deserve but often need.'),
                'perspective_b' => $this->doc('Kaladin trusts Harry in the field but worries about the degree to which he is already normalized to emergency thinking.'),
                'strength_from_a' => 'moderate',
                'strength_from_b' => 'moderate',
                'perceived_type' => 'allied responders',
                'true_type' => 'mutual-recognition',
                'perception_divergence' => 'The visible bond is tactical; the real bond is moral identification.',
                'notes' => $this->doc('They understand each other too quickly for either of them to find it comfortable.'),
                'visibility' => 'private',
                'content_classification' => 'restricted',
            ]
        );

        $relationships['kaladin_shallan'] = $this->upsertRelationship(
            $entities['kaladin'],
            $entities['shallan'],
            [
                'relationship_type' => 'organizational',
                'direction' => 'mutual_unequal',
                'current_tension_charge' => 'complex',
                'perspective_a' => $this->doc('Kaladin trusts Shallan when she is honest and worries about how much deception the mission keeps rewarding.'),
                'perspective_b' => $this->doc('Shallan trusts Kaladin to move when things become real and resents how accurately he reads her evasions.'),
                'strength_from_a' => 'moderate',
                'strength_from_b' => 'moderate',
                'perceived_type' => 'Radiant allies',
                'true_type' => 'protective friction',
                'perception_divergence' => 'Their visible banter hides an intense mutual pressure toward integrity.',
                'notes' => $this->doc('Still grounded in canon texture, but angled toward crossover operational strain.'),
                'visibility' => 'private',
                'content_classification' => 'restricted',
            ]
        );

        $relationships['hermione_seraphine'] = $this->upsertRelationship(
            $entities['hermione'],
            $entities['seraphine'],
            [
                'relationship_type' => 'conflict',
                'direction' => 'mutual_equal',
                'current_tension_charge' => 'volatile',
                'perspective_a' => $this->doc('Hermione sees Seraphine as proof that elegant procedure can still become a moral failure.'),
                'perspective_b' => $this->doc('Seraphine sees Hermione as the person most likely to destroy the Grey Line for reasons that are ethically excellent and operationally terrifying.'),
                'strength_from_a' => 'high',
                'strength_from_b' => 'high',
                'perceived_type' => 'tense collaborators',
                'true_type' => 'ethical adversaries',
                'perception_divergence' => 'They are too respectful of each other to simplify the conflict.',
                'notes' => $this->doc('This is one of the primary engines of narrative heat in the production material.'),
                'visibility' => 'secret',
                'content_classification' => 'secret',
            ]
        );

        $relationships['seraphine_greyline'] = $this->upsertRelationship(
            $entities['seraphine'],
            $entities['grey_line'],
            [
                'relationship_type' => 'power',
                'direction' => 'mutual_unequal',
                'current_tension_charge' => 'complex',
                'perspective_a' => $this->doc('Seraphine believes she can still steer the institution toward an exit it did not design for itself.'),
                'perspective_b' => $this->doc('The Grey Line treats Seraphine as indispensable infrastructure and therefore as something it can continue to spend.'),
                'strength_from_a' => 'high',
                'strength_from_b' => 'high',
                'perceived_type' => 'trusted leadership',
                'true_type' => 'mutual capture',
                'perception_divergence' => 'Most people see authority; the truth is dependency on both sides.',
                'notes' => $this->doc('This relationship explains why Seraphine cannot just walk away.'),
                'visibility' => 'secret',
                'content_classification' => 'secret',
            ]
        );

        $relationships['hogwarts_forest'] = $this->upsertRelationship(
            $entities['hogwarts'],
            $entities['forest'],
            [
                'relationship_type' => 'possession',
                'direction' => 'one_way',
                'current_tension_charge' => 'complex',
                'perspective_a' => $this->doc('The castle cannot control the forest, only frame the terms under which people enter it.'),
                'perspective_b' => $this->doc('The forest increasingly behaves as though it remembers being left alone.'),
                'strength_from_a' => 'moderate',
                'strength_from_b' => 'moderate',
                'perceived_type' => 'school grounds',
                'true_type' => 'semi-independent threshold ecology',
                'perception_divergence' => 'Visitors think jurisdiction and containment are the same thing.',
                'notes' => $this->doc('Useful for location pages and threshold logic.'),
                'visibility' => 'private',
                'content_classification' => 'restricted',
            ]
        );

        return $relationships;
    }

    /**
     * @param  array<string, Entity>  $entities
     * @return array<string, GroupRelationship>
     */
    private function seedGroupRelationships(array $entities): array
    {
        $groups = [];

        $groups['field_team'] = $this->upsertGroupRelationship('Convergence Field Team', [
            'relationship_type' => 'organizational',
            'dynamic_description' => $this->doc(
                'An operational cluster formed around first-contact response, ethics triage, and keeping the breach from being captured by any one institution.'
            ),
            'current_tension_charge' => 'complex',
            'perceived_type' => 'joint task group',
            'true_type' => 'mutual safeguard network',
            'perception_divergence' => 'The visible frame is procedure; the real frame is people informally deciding who they refuse to sacrifice.',
            'notes' => $this->doc('The field team is the heart of the crossover cast.'),
            'visibility' => 'private',
            'content_classification' => 'restricted',
        ]);

        $this->upsertGroupMembership($groups['field_team'], $entities['harry'], [
            'role_in_group' => 'field anchor',
            'participation_notes' => $this->doc('Often the person who calls the stop when escalation begins to sound normal.'),
            'joined_era' => 'Convergence Year 1',
        ]);
        $this->upsertGroupMembership($groups['field_team'], $entities['hermione'], [
            'role_in_group' => 'lead researcher',
            'participation_notes' => $this->doc('Translates theory into operating rules without pretending the rules are permanent.'),
            'joined_era' => 'Convergence Year 1',
        ]);
        $this->upsertGroupMembership($groups['field_team'], $entities['kaladin'], [
            'role_in_group' => 'protective response',
            'participation_notes' => $this->doc('Primary evacuation and civilian-protection lead.'),
            'joined_era' => 'Convergence Year 1',
        ]);
        $this->upsertGroupMembership($groups['field_team'], $entities['shallan'], [
            'role_in_group' => 'deception countermeasures',
            'participation_notes' => $this->doc('Tracks glamour, narrative manipulation, and institutional misdirection.'),
            'joined_era' => 'Convergence Year 1',
        ]);

        $groups['crisis_board'] = $this->upsertGroupRelationship('Grey Line Crisis Board', [
            'relationship_type' => 'power',
            'dynamic_description' => $this->doc(
                'A pressure-chamber of factions, proxies, and overworked operators all trying to define the future of the crossing before somebody else does.'
            ),
            'current_tension_charge' => 'volatile',
            'perceived_type' => 'oversight body',
            'true_type' => 'contested command structure',
            'perception_divergence' => 'Its official purpose is governance; its real function is leverage management.',
            'notes' => $this->doc('This group is where good intentions become machinery.'),
            'visibility' => 'secret',
            'content_classification' => 'secret',
        ]);

        $this->upsertGroupMembership($groups['crisis_board'], $entities['seraphine'], [
            'role_in_group' => 'liaison and logistics spine',
            'participation_notes' => $this->doc('Holds the board together by never letting anyone see the entire panic at once.'),
            'joined_era' => 'Convergence Year 1',
        ]);
        $this->upsertGroupMembership($groups['crisis_board'], $entities['grey_line'], [
            'role_in_group' => 'institutional owner',
            'participation_notes' => $this->doc('Claims custodianship over the active crossing network.'),
            'joined_era' => 'Convergence Year 1',
        ]);
        $this->upsertGroupMembership($groups['crisis_board'], $entities['mirror_archive'], [
            'role_in_group' => 'shadow archive bloc',
            'participation_notes' => $this->doc('Present as critics, absent as clean alternatives.'),
            'joined_era' => 'Convergence Year 1',
        ]);
        $this->upsertGroupMembership($groups['crisis_board'], $entities['silken_court'], [
            'role_in_group' => 'unwanted stakeholder',
            'participation_notes' => $this->doc('Keeps trying to convert instability into access.'),
            'joined_era' => 'Convergence Year 1',
        ]);

        return $groups;
    }

    /**
     * @param  array<string, Entity>  $entities
     */
    private function seedFactionMemberships(array $entities): void
    {
        $this->upsertFactionMembership($entities['order'], $entities['harry'], [
            'rank_or_role' => 'field lead',
            'membership_status' => 'active',
            'joined_era' => 'Post-War',
            'public_membership_known' => false,
            'notes' => $this->doc('Remains active largely because someone has to decide when not to weaponize what they learn.'),
        ]);

        $this->upsertFactionMembership($entities['order'], $entities['hermione'], [
            'rank_or_role' => 'research coordinator',
            'membership_status' => 'active',
            'joined_era' => 'Post-War',
            'public_membership_known' => false,
            'notes' => $this->doc('Acts as the Order contact least likely to accept vague authority claims.'),
        ]);

        $this->upsertFactionMembership($entities['radiants'], $entities['kaladin'], [
            'rank_or_role' => 'Windrunner captain',
            'membership_status' => 'active',
            'joined_era' => 'Current',
            'public_membership_known' => true,
            'notes' => $this->doc('Cross-domain operations pull him into a more explicitly diplomatic version of protection.'),
        ]);

        $this->upsertFactionMembership($entities['radiants'], $entities['shallan'], [
            'rank_or_role' => 'Lightweaver operative',
            'membership_status' => 'active',
            'joined_era' => 'Current',
            'public_membership_known' => true,
            'notes' => $this->doc('Operationally essential whenever the problem is both social and surreal.'),
        ]);

        $this->upsertFactionMembership($entities['grey_line'], $entities['seraphine'], [
            'rank_or_role' => 'liaison director',
            'membership_status' => 'active',
            'joined_era' => 'Convergence Year 0',
            'public_membership_known' => false,
            'notes' => $this->doc('Central operator, increasingly unwilling believer in the institution she built around.'),
        ]);

        $this->upsertFactionMembership($entities['mirror_archive'], $entities['seraphine'], [
            'rank_or_role' => 'redundancy asset',
            'membership_status' => 'active',
            'joined_era' => 'Convergence Year 0',
            'true_loyalty_entity_id' => $entities['mirror_archive']->id,
            'is_undercover' => true,
            'public_membership_known' => false,
            'recruited_by_entity_id' => $entities['mirror_archive']->id,
            'notes' => $this->doc('A buried contingency loyality that predates the current crisis board.'),
        ]);
    }

    /**
     * @param  array<string, Entity>  $entities
     * @return array<string, mixed>
     */
    private function seedTimelineEntries(array $entities): array
    {
        $concurrency = $this->upsertConcurrencyGroup('Urithiru Hearing Spiral', [
            'au_date' => 'Convergence Year 1 / Deepwinter',
            'description' => $this->doc(
                'A cluster of simultaneous events where political, magical, and personal pressure all spike fast enough to distort ordinary chronology.'
            ),
            'narrative_significance' => 'pivotal',
        ]);

        $crossing = $this->upsertTimelineEntry($entities['timeline_convergence'], $entities['event_crossing'], [
            'entry_label' => 'Bridge becomes repeatable',
            'au_date' => 'Convergence Year 1 / Early Winter',
            'timeline_position' => 10,
            'temporal_certainty' => 'documented',
            'public_narrative' => $this->doc('A carefully managed transit test proves the endpoints can be held without immediate collapse.'),
            'true_narrative' => $this->doc('The test only works because multiple systems quietly compensate for each other beyond what the operators understand.'),
            'narrative_divergence' => 'partial',
            'visibility' => 'private',
            'content_classification' => 'restricted',
        ]);

        $spanreed = $this->upsertTimelineEntry($entities['timeline_convergence'], $entities['event_spanreed'], [
            'entry_label' => 'Language survives transit',
            'au_date' => 'Convergence Year 1 / Midwinter',
            'timeline_position' => 20,
            'temporal_certainty' => 'documented',
            'public_narrative' => $this->doc('Hermione and Shallan establish the first reliable hybrid message exchange protocol.'),
            'true_narrative' => $this->doc('The protocol is less a method than a fragile habit that only works because both women refuse to simplify what the other means.'),
            'narrative_divergence' => 'partial',
            'visibility' => 'private',
            'content_classification' => 'restricted',
        ]);

        $tribunal = $this->upsertTimelineEntry($entities['timeline_tribunal'], $entities['event_tribunal'], [
            'entry_label' => 'Oversight attempt fails cleanly and then fails morally',
            'au_date' => 'Convergence Year 1 / Late Winter',
            'timeline_position' => 10,
            'temporal_certainty' => 'documented',
            'concurrency_group_id' => $concurrency->id,
            'public_narrative' => $this->doc('A formal review of Grey Line authority breaks down into accusations, suppressed records, and jurisdictional panic.'),
            'true_narrative' => $this->doc('Several people arrive intending reform and leave understanding the institution cannot be reformed without first losing control.'),
            'narrative_divergence' => 'complete',
            'visibility' => 'secret',
            'content_classification' => 'secret',
        ]);

        $duel = $this->upsertTimelineEntry($entities['timeline_convergence'], $entities['event_duel'], [
            'entry_label' => 'Threshold violence becomes visible',
            'au_date' => 'Convergence Year 1 / Late Winter',
            'timeline_position' => 30,
            'temporal_certainty' => 'documented',
            'concurrency_group_id' => $concurrency->id,
            'public_narrative' => $this->doc('A pursuit through the forest breach exposes just how contested the site has become.'),
            'true_narrative' => $this->doc('The duel matters less for the injuries than for the fact that every side stops believing secrecy alone can hold the line.'),
            'narrative_divergence' => 'complete',
            'visibility' => 'secret',
            'content_classification' => 'secret',
        ]);

        $this->upsertCharacterState($entities['harry'], [
            'timeline_id' => $entities['timeline_convergence']->id,
            'timeline_position' => 12,
            'au_date' => 'Convergence Year 1 / Early Winter',
            'snapshot_label' => 'After the first stable return',
            'snapshot_significance' => 'major',
            'significance_reason' => 'Harry realizes the breach is survivable and therefore politically dangerous.',
            'current_trauma_profile' => $this->doc('War reflexes surge back the moment success becomes repeatable.'),
            'active_psychological_patterns' => $this->doc('Hypervigilant, sarcastic, and unusually calm while everyone else is still calling it impossible.'),
            'current_stability_level' => 'strained',
            'self_perception' => 'useful if careful',
            'current_desire' => 'keep the crossing human-sized',
            'current_fear' => 'becoming the sort of man who pre-accepts collateral',
            'mask_integrity' => 'cracking',
            'current_power_tier_operating' => 'street_level',
            'current_power_tier_influence' => 'regional',
            'available_abilities' => ['defensive spellwork', 'patronus', 'threshold tolerance'],
            'notes' => $this->doc('Functionally strong, emotionally carrying more than he lets anyone name.'),
        ]);

        $this->upsertCharacterState($entities['hermione'], [
            'timeline_id' => $entities['timeline_convergence']->id,
            'timeline_position' => 21,
            'au_date' => 'Convergence Year 1 / Midwinter',
            'snapshot_label' => 'After the exchange protocol holds',
            'snapshot_significance' => 'major',
            'significance_reason' => 'Research becomes governance the moment communication is stable enough to scale.',
            'current_trauma_profile' => $this->doc('Carrying ethical exhaustion rather than acute panic.'),
            'active_psychological_patterns' => $this->doc('Overpreparing because good answers now have direct policy consequences.'),
            'current_stability_level' => 'stressed',
            'self_perception' => 'translator under pressure',
            'current_desire' => 'slow the institutions down',
            'current_fear' => 'proving the crossing works for people who should never control it',
            'mask_integrity' => 'intact',
            'current_power_tier_operating' => 'street_level',
            'current_power_tier_influence' => 'regional',
            'available_abilities' => ['ward analysis', 'ritual adaptation', 'structured memory'],
            'notes' => $this->doc('Still steady, but she is beginning to understand that success is its own threat multiplier.'),
        ]);

        $this->upsertCharacterState($entities['kaladin'], [
            'timeline_id' => $entities['timeline_tribunal']->id,
            'timeline_position' => 11,
            'au_date' => 'Convergence Year 1 / Late Winter',
            'snapshot_label' => 'Waiting through the tribunal',
            'snapshot_significance' => 'major',
            'significance_reason' => 'Kaladin’s protective role expands from bodies to entire institutional outcomes.',
            'current_trauma_profile' => $this->doc('The room feels too much like command failure before a battlefield break.'),
            'active_psychological_patterns' => $this->doc('Tracking exits, civilians, and moral fracture lines all at once.'),
            'current_stability_level' => 'strained',
            'self_perception' => 'protector without jurisdiction',
            'current_desire' => 'pull people out before the board hardens into harm',
            'current_fear' => 'watching another system turn survival into justification',
            'mask_integrity' => 'intact',
            'current_power_tier_operating' => 'regional',
            'current_power_tier_influence' => 'factional',
            'available_abilities' => ['lashings', 'combat leadership', 'instinctive triage'],
            'notes' => $this->doc('Still visibly composed, but the warning signs are there for anyone who knows how to read him.'),
        ]);

        $this->upsertCharacterState($entities['shallan'], [
            'timeline_id' => $entities['timeline_convergence']->id,
            'timeline_position' => 28,
            'au_date' => 'Convergence Year 1 / Late Winter',
            'snapshot_label' => 'In the hour before the canopy duel',
            'snapshot_significance' => 'major',
            'significance_reason' => 'Her read on the deception lattice becomes operationally decisive.',
            'current_trauma_profile' => $this->doc('Identity strain sharpens into clarity when the lies stop pretending to be protective.'),
            'active_psychological_patterns' => $this->doc('Layering personas tactically while trying not to let them become an excuse.'),
            'current_stability_level' => 'strained',
            'self_perception' => 'useful because fractured',
            'current_desire' => 'expose the architecture without destroying the people inside it',
            'current_fear' => 'becoming one more beautifully framed liar in the machine',
            'mask_integrity' => 'compromised',
            'current_power_tier_operating' => 'regional',
            'current_power_tier_influence' => 'factional',
            'available_abilities' => ['lightweaving', 'forensic sketch memory', 'persona shaping'],
            'notes' => $this->doc('Her insight is peaking at exactly the same time her internal strain is becoming impossible to ignore.'),
        ]);

        $this->upsertCharacterState($entities['seraphine'], [
            'timeline_id' => $entities['timeline_tribunal']->id,
            'timeline_position' => 12,
            'au_date' => 'Convergence Year 1 / Late Winter',
            'snapshot_label' => 'After the first procedural collapse',
            'snapshot_significance' => 'transformative',
            'significance_reason' => 'The moment she stops believing the Grey Line can remain intact and moral at the same time.',
            'current_trauma_profile' => $this->doc('Long-term compartmentalization is beginning to fail under sustained moral contradiction.'),
            'active_psychological_patterns' => $this->doc('Perfectionistic control, anticipatory translation, and desperate selective honesty.'),
            'current_stability_level' => 'breaking',
            'self_perception' => 'instrument that learned to object',
            'current_desire' => 'buy enough time to choose a less catastrophic collapse',
            'current_fear' => 'that every softer exit is already gone',
            'mask_integrity' => 'shattered',
            'current_power_tier_operating' => 'street_level',
            'current_power_tier_influence' => 'factional',
            'available_abilities' => ['threshold logistics', 'glamour-aware negotiation', 'procedural masking'],
            'notes' => $this->doc('This is the state snapshot that turns Seraphine from pressure manager into active unstable variable.'),
        ]);

        return [
            'concurrency' => $concurrency,
            'crossing' => $crossing,
            'spanreed' => $spanreed,
            'tribunal' => $tribunal,
            'duel' => $duel,
        ];
    }

    /**
     * @param  array<string, Entity>  $entities
     * @return array<string, Document>
     */
    private function seedDocuments(array $entities): array
    {
        $documents = [];

        $documents['dossier'] = $this->upsertDocument('Grey Line Dossier: Seraphine Morbraith', [
            'document_type' => 'intelligence_report',
            'document_authenticity' => 'authentic',
            'document_status' => 'classified',
            'official_author_entity_id' => $entities['hermione']->id,
            'true_author_entity_id' => $entities['hermione']->id,
            'owner_entity_id' => $entities['order']->id,
            'era_created' => 'Convergence Year 1',
            'official_narrative' => $this->doc(
                'Prepared as a working profile for allied review.',
                'Seraphine Morbraith presents as a stabilizing liaison with exceptional procedural discipline and broad route access.'
            ),
            'true_content' => $this->doc(
                'Seraphine is a key structural vulnerability in the Grey Line because she is both indispensable and morally exhausted.',
                'If the institution fractures, she is the most likely person to attempt a controlled collapse rather than a defense.'
            ),
            'visibility' => 'private',
            'content_classification' => 'restricted',
        ]);

        $documents['resonance'] = $this->upsertDocument('Memorandum on Investiture-Wand Resonance', [
            'document_type' => 'research_notes',
            'document_authenticity' => 'authentic',
            'document_status' => 'extant',
            'official_author_entity_id' => $entities['hermione']->id,
            'true_author_entity_id' => $entities['hermione']->id,
            'owner_entity_id' => $entities['hogwarts']->id,
            'era_created' => 'Convergence Year 1',
            'official_narrative' => $this->doc(
                'Preliminary synthesis of observed interaction patterns between British wandcasting and Rosharan Investiture expression.'
            ),
            'true_content' => $this->doc(
                'Most dangerous outcomes emerge when institutions confuse repeatability with understanding.',
                'The mechanics are less alarming than the speed at which logistics people start building doctrine on partial success.'
            ),
            'visibility' => 'private',
            'content_classification' => 'restricted',
        ]);

        $documents['charter'] = $this->upsertDocument('Urithiru Exchange Charter', [
            'document_type' => 'treaty',
            'document_authenticity' => 'authentic',
            'document_status' => 'classified',
            'official_author_entity_id' => $entities['radiants']->id,
            'true_author_entity_id' => $entities['radiants']->id,
            'owner_entity_id' => $entities['urithiru']->id,
            'era_created' => 'Convergence Year 1',
            'official_narrative' => $this->doc(
                'A compact governing limited movement, witness rules, and mutual duty of care across the active endpoints.'
            ),
            'true_content' => $this->doc(
                'The charter mostly exists because everybody involved realized a loophole race would kill trust faster than any hostile actor.'
            ),
            'visibility' => 'private',
            'content_classification' => 'restricted',
        ]);

        $documents['canopy'] = $this->upsertDocument('Notes from the Broken Canopy', [
            'document_type' => 'personal_journal',
            'document_authenticity' => 'authentic',
            'document_status' => 'extant',
            'official_author_entity_id' => $entities['shallan']->id,
            'true_author_entity_id' => $entities['shallan']->id,
            'owner_entity_id' => $entities['shallan']->id,
            'era_created' => 'Convergence Year 1 / Late Winter',
            'official_narrative' => $this->doc(
                'A field-account in sketch and prose form concerning the threshold pursuit after the tribunal failure.'
            ),
            'true_content' => $this->doc(
                'Shallan records not just the motion of the chase but the emotional geometry of the people in it.',
                'The notes are as much about who had already decided to lie as they are about who cast what.'
            ),
            'visibility' => 'private',
            'content_classification' => 'restricted',
        ]);

        $documents['ledger'] = $this->upsertDocument('Mirror Archive Contingency Ledger', [
            'document_type' => 'technical_document',
            'document_authenticity' => 'disputed',
            'document_status' => 'suppressed',
            'official_author_entity_id' => $entities['grey_line']->id,
            'true_author_entity_id' => $entities['mirror_archive']->id,
            'owner_entity_id' => $entities['mirror_archive']->id,
            'suppressed_by_entity_id' => $entities['grey_line']->id,
            'era_created' => 'Convergence Year 0',
            'official_narrative' => $this->doc('An obsolete routing document no longer reflective of active practice.'),
            'true_content' => $this->doc(
                'A live map of redundant exits, hidden witnesses, and fallback disclosure plans held outside Grey Line consensus.',
                'Its existence means the central network can be bypassed if the moral cost becomes intolerable.'
            ),
            'visibility' => 'author_only',
            'content_classification' => 'secret',
        ]);

        $this->upsertDocumentEntity($documents['dossier'], $entities['seraphine'], 'subject', 'Primary subject of the file.');
        $this->upsertDocumentEntity($documents['dossier'], $entities['grey_line'], 'referenced', 'Institutional context for Seraphine.');
        $this->upsertDocumentEntity($documents['resonance'], $entities['wandcraft'], 'subject', 'System under study.');
        $this->upsertDocumentEntity($documents['resonance'], $entities['surgebinding'], 'subject', 'System under study.');
        $this->upsertDocumentEntity($documents['charter'], $entities['harry'], 'signatory', 'Hogwarts-side signatory witness.');
        $this->upsertDocumentEntity($documents['charter'], $entities['kaladin'], 'signatory', 'Urithiru-side signatory witness.');
        $this->upsertDocumentEntity($documents['canopy'], $entities['shallan'], 'author', 'First-person observer.');
        $this->upsertDocumentEntity($documents['canopy'], $entities['event_duel'], 'subject', 'Primary event documented.');
        $this->upsertDocumentEntity($documents['ledger'], $entities['mirror_archive'], 'author', 'True maintenance body.');
        $this->upsertDocumentEntity($documents['ledger'], $entities['seraphine'], 'referenced', 'Named in multiple contingency branches.');

        return $documents;
    }

    /**
     * @param  array<string, Entity>  $entities
     * @param  array<string, mixed>  $timelines
     */
    private function seedMedia(array $entities, array $timelines): void
    {
        $this->upsertMedia([
            'entity_id' => $entities['harry']->id,
            'title' => 'Harry field portrait reference',
            'description' => 'Reference link used for the scarred, post-war visual direction.',
            'media_type' => 'link',
            'purpose' => 'portrait',
            'url' => 'https://example.com/references/harry-field-portrait',
            'is_primary' => true,
            'visibility' => 'private',
            'content_classification' => 'restricted',
        ]);

        $this->upsertMedia([
            'entity_id' => $entities['kaladin']->id,
            'title' => 'Kaladin towerlight palette board',
            'description' => 'Visual reference for the colder towerlight scenes and storm-battered armor.',
            'media_type' => 'link',
            'purpose' => 'portrait',
            'url' => 'https://example.com/references/kaladin-towerlight-palette',
            'is_primary' => true,
            'visibility' => 'private',
            'content_classification' => 'restricted',
        ]);

        $this->upsertMedia([
            'entity_id' => $entities['seraphine']->id,
            'title' => 'Seraphine dossier board',
            'description' => 'Mood and styling board for polished exterior versus operational fatigue.',
            'media_type' => 'link',
            'purpose' => 'portrait',
            'url' => 'https://example.com/references/seraphine-dossier-board',
            'is_primary' => true,
            'visibility' => 'secret',
            'content_classification' => 'secret',
        ]);

        $this->upsertMedia([
            'entity_id' => $entities['hogwarts']->id,
            'title' => 'Hogwarts threshold map',
            'description' => 'Site planning reference for the active forest-side crossing approaches.',
            'media_type' => 'link',
            'purpose' => 'map',
            'url' => 'https://example.com/references/hogwarts-threshold-map',
            'is_primary' => true,
            'visibility' => 'private',
            'content_classification' => 'restricted',
        ]);

        $this->upsertMedia([
            'timeline_entry_id' => $timelines['crossing']->id,
            'title' => 'First crossing route sketch',
            'description' => 'Annotated route sketch showing the first repeatable transit setup.',
            'media_type' => 'link',
            'purpose' => 'reference',
            'url' => 'https://example.com/references/first-crossing-route',
            'visibility' => 'private',
            'content_classification' => 'restricted',
        ]);
    }

    /**
     * @param  array<string, Entity>  $entities
     * @param  array<string, Document>  $documents
     * @return array<string, Collection>
     */
    private function seedCollections(array $entities, array $documents): array
    {
        $collections = [];

        $collections['principals'] = $this->upsertCollection('Convergence Principals', [
            'description' => $this->doc('Primary cast and institutional actors driving the crossover arc.'),
            'collection_type' => 'character_roster',
            'collection_mode' => 'manual',
            'completion_state' => 'complete',
            'visibility' => 'private',
            'content_classification' => 'restricted',
        ]);

        $this->upsertCollectionEntity($collections['principals'], $entities['harry'], 'anchor', 1, 'Holds the emotional and strategic center.');
        $this->upsertCollectionEntity($collections['principals'], $entities['hermione'], 'research lead', 2, 'Turns chaos into accountable knowledge.');
        $this->upsertCollectionEntity($collections['principals'], $entities['kaladin'], 'protective lead', 3, 'Carries the defensive moral frame.');
        $this->upsertCollectionEntity($collections['principals'], $entities['shallan'], 'counter-deception lead', 4, 'Reads the lies in the room.');
        $this->upsertCollectionEntity($collections['principals'], $entities['seraphine'], 'pressure nexus', 5, 'Key original pressure point.');

        $collections['sites'] = $this->upsertCollection('Sites of the First Crossing', [
            'description' => $this->doc('Spatial cluster covering the most important locations in the first convergence arc.'),
            'collection_type' => 'location_cluster',
            'collection_mode' => 'manual',
            'completion_state' => 'in_progress',
            'visibility' => 'private',
            'content_classification' => 'restricted',
        ]);

        $this->upsertCollectionEntity($collections['sites'], $entities['hogwarts'], 'primary endpoint', 1, 'Earth-side institutional anchor.');
        $this->upsertCollectionEntity($collections['sites'], $entities['forest'], 'approach zone', 2, 'Transition ecology.');
        $this->upsertCollectionEntity($collections['sites'], $entities['veilfracture'], 'breach core', 3, 'Actual engineered threshold.');
        $this->upsertCollectionEntity($collections['sites'], $entities['urithiru'], 'Roshar endpoint', 4, 'Roshar-side institutional anchor.');
        $this->upsertCollectionEntity($collections['sites'], $entities['relay_vault'], 'hidden logistics room', 5, 'Grey Line operational cache.');

        $collections['casefile'] = $this->upsertCollection('Grey Line Casefile', [
            'description' => $this->doc('Documents, entities, and leads relevant to the Grey Line crisis arc.'),
            'collection_type' => 'research_set',
            'collection_mode' => 'hybrid',
            'rules' => [
                'match_any' => [
                    ['field' => 'source_universes', 'contains' => 'Original'],
                    ['field' => 'entity_type', 'equals' => 'organization'],
                ],
            ],
            'completion_state' => 'in_progress',
            'visibility' => 'private',
            'content_classification' => 'restricted',
        ]);

        $this->upsertCollectionEntity($collections['casefile'], $entities['grey_line'], 'primary institution', 1, 'Core operational body.');
        $this->upsertCollectionEntity($collections['casefile'], $entities['seraphine'], 'principal subject', 2, 'Most important human fault line.');
        $this->upsertCollectionEntity($collections['casefile'], $entities['mirror_archive'], 'rival archive', 3, 'Shadow counterweight.');
        $this->upsertCollectionEntity($collections['casefile'], $entities['silken_court'], 'hostile stakeholder', 4, 'Pressure source.');

        $this->upsertCollectionDocument($collections['casefile'], $documents['dossier'], 'core file', 1, 'Best starting document for the Seraphine problem.');
        $this->upsertCollectionDocument($collections['casefile'], $documents['ledger'], 'suppressed evidence', 2, 'Explains why central control is unstable.');

        $collections['resonance'] = $this->upsertCollection('Resonance Working Set', [
            'description' => $this->doc('Power systems, notes, and reference materials tied to crossover mechanics.'),
            'collection_type' => 'power_system_group',
            'collection_mode' => 'manual',
            'completion_state' => 'in_progress',
            'visibility' => 'private',
            'content_classification' => 'restricted',
        ]);

        $this->upsertCollectionEntity($collections['resonance'], $entities['wandcraft'], 'system', 1, 'British baseline magic.');
        $this->upsertCollectionEntity($collections['resonance'], $entities['surgebinding'], 'system', 2, 'Rosharan Investiture baseline.');
        $this->upsertCollectionEntity($collections['resonance'], $entities['grey_line_weaving'], 'bridge discipline', 3, 'Original operational overlay.');
        $this->upsertCollectionDocument($collections['resonance'], $documents['resonance'], 'research memo', 1, 'Primary study note.');

        return $collections;
    }

    /**
     * @param  array<string, Entity>  $entities
     */
    private function seedGlossary(array $entities): void
    {
        $this->upsertGlossary('Grey Line', [
            'usage_context' => 'both',
            'definition' => $this->doc('The covert logistics network built to stabilize and control early crossover traffic.'),
            'origin_universe' => 'Original',
            'era_introduced' => 'Convergence Year 0',
            'term_status' => 'both',
            'first_appearance_entity_id' => $entities['grey_line']->id,
            'visibility' => 'private',
            'content_classification' => 'restricted',
        ]);

        $this->upsertGlossary('Resonance Bleed', [
            'usage_context' => 'both',
            'definition' => $this->doc('The spillover effect that occurs when two systems remain in contact long enough to begin borrowing each other’s assumptions.'),
            'origin_universe' => 'Original',
            'era_introduced' => 'Convergence Year 1',
            'term_status' => 'active',
            'first_appearance_entity_id' => $entities['event_spanreed']->id,
            'visibility' => 'private',
            'content_classification' => 'restricted',
        ]);

        $this->upsertGlossary('Towerlight Corridor', [
            'usage_context' => 'in_world',
            'definition' => $this->doc('A transit alignment stabilized by Rosharan towerlight conditions on the Urithiru side.'),
            'origin_universe' => 'Stormlight Archive',
            'era_introduced' => 'Convergence Year 1',
            'term_status' => 'active',
            'first_appearance_entity_id' => $entities['urithiru']->id,
            'visibility' => 'private',
            'content_classification' => 'restricted',
        ]);

        $this->upsertGlossary('Soft Oath Apparition', [
            'usage_context' => 'meta',
            'definition' => $this->doc('Shorthand for the way wandcraft transit attempts become more reliable when performed by people under binding moral commitments.'),
            'origin_universe' => 'Original',
            'era_introduced' => 'Convergence Year 1',
            'term_status' => 'meta_only',
            'first_appearance_entity_id' => $entities['wandcraft']->id,
            'visibility' => 'private',
            'content_classification' => 'restricted',
        ]);

        $this->upsertGlossary('Broken Canopy', [
            'usage_context' => 'in_world',
            'definition' => $this->doc('Colloquial name for the shattered tree-line region around the active forest-side threshold after the late winter conflict.'),
            'origin_universe' => 'Original',
            'era_introduced' => 'Convergence Year 1 / Late Winter',
            'term_status' => 'active',
            'first_appearance_entity_id' => $entities['event_duel']->id,
            'visibility' => 'private',
            'content_classification' => 'restricted',
        ]);
    }

    /**
     * @param  array<string, Entity>  $entities
     * @return array<string, CrossoverEntryPoint>
     */
    private function seedCrossoverEntryPoints(array $entities): array
    {
        $points = [];

        $points['stormlight'] = $this->upsertCrossoverEntryPoint('Stormlight Archive', [
            'entry_mechanism' => $this->doc('Towerlight-stabilized threshold contact anchored through a managed forest-side seam.'),
            'power_transition_rules' => $this->doc('Rosharan power expression remains oath- and light-sensitive, but becomes more predictable near structured wand focus.'),
            'physical_transition_rules' => $this->doc('Transit is safest with small groups, explicit destination intent, and pre-established witness protocols.'),
            'memory_and_identity_rules' => $this->doc('Identity drift is low but branch-memory residue spikes in people already carrying unstable narrative weight.'),
            'psychological_transition_rules' => $this->doc('People under unresolved moral pressure tend to experience the crossing as clarifying rather than disorienting.'),
            'canon_deviation_notes' => $this->doc('The entry point foregrounds systemic compatibility over cosmere secrecy.'),
            'known_examples' => [$entities['kaladin']->id, $entities['shallan']->id],
            'known_entry_points' => [$entities['veilfracture']->id],
            'status' => 'documented',
            'restrictions' => $this->doc('Unsupervised high-stress crossings are prohibited after the first winter failures.'),
            'return_rules' => $this->doc('Return remains possible but grows less predictable during periods of institutional conflict.'),
            'first_documented_crossing_event_id' => $entities['event_crossing']->id,
            'visibility' => 'private',
            'content_classification' => 'restricted',
        ]);

        $points['harry'] = $this->upsertCrossoverEntryPoint('Harry Potter', [
            'entry_mechanism' => $this->doc('Warded forest-side ritual corridor using anchored focus objects and witness-confirmed pathing.'),
            'power_transition_rules' => $this->doc('British wandcraft remains syntactically legible, but effects near the seam show increased intent sensitivity.'),
            'physical_transition_rules' => $this->doc('The forest endpoint behaves like a negotiated path rather than an open gate.'),
            'memory_and_identity_rules' => $this->doc('Crossers with prior branch trauma sometimes recall near-lives more vividly after transit.'),
            'psychological_transition_rules' => $this->doc('Suppressed fear tends to surface faster on the Hogwarts side than it does in Urithiru.'),
            'canon_deviation_notes' => $this->doc('The entry point treats Hogwarts wards as active participants in stabilization.'),
            'known_examples' => [$entities['harry']->id, $entities['hermione']->id],
            'known_entry_points' => [$entities['veilfracture']->id, $entities['forest']->id],
            'status' => 'documented',
            'restrictions' => $this->doc('No unsupervised school-side crossings; no high-conflict transit within one hour of dusk.'),
            'return_rules' => $this->doc('Return is safest when undertaken by people who can clearly name where they belong before and after the transit.'),
            'first_documented_crossing_event_id' => $entities['event_crossing']->id,
            'visibility' => 'private',
            'content_classification' => 'restricted',
        ]);

        return $points;
    }

    /**
     * @param  array<string, Entity>  $entities
     * @param  array<string, CrossoverEntryPoint>  $entryPoints
     */
    private function seedCanonReferences(array $entities, array $entryPoints): void
    {
        $hpUniverse = $this->upsertCanonReference('Harry Potter Source Frame', [
            'universe' => 'Harry Potter',
            'level' => 'universe',
            'content' => $this->doc('Primary canon reference frame for Hogwarts-side assumptions, institutions, and magical practice.'),
            'universe_overview' => $this->doc('A secretive magical society whose educational, bureaucratic, and wartime legacies shape how the convergence is first interpreted.'),
            'universe_priority' => 'primary',
            'universe_depth_rating' => 'solid',
            'overall_divergence_summary' => $this->doc('The AU diverges by extending post-war governance into a crossover contact scenario rather than resetting to peacetime normality.'),
            'primary_elements_borrowed' => ['Hogwarts', 'Order of the Phoenix', 'wandcraft', 'Harry Potter', 'Hermione Granger'],
            'primary_divergences' => ['active multiversal contact', 'proceduralized first contact', 'threshold ethics doctrine'],
            'crossover_entry_point_id' => $entryPoints['harry']->id,
            'research_status' => 'solid',
            'research_confidence' => 'solid',
            'visibility' => 'private',
            'content_classification' => 'restricted',
        ]);

        $stormlightUniverse = $this->upsertCanonReference('Stormlight Archive Source Frame', [
            'universe' => 'Stormlight Archive',
            'level' => 'universe',
            'content' => $this->doc('Primary canon reference frame for Rosharan institutions, oath logic, and Investiture behavior.'),
            'universe_overview' => $this->doc('A world where morality, power, and social role are entangled in overt ways that change how crossover governance feels from the Roshar side.'),
            'universe_priority' => 'primary',
            'universe_depth_rating' => 'solid',
            'overall_divergence_summary' => $this->doc('The AU diverges by placing Urithiru into sustained administrative contact with another magic-bearing society.'),
            'primary_elements_borrowed' => ['Urithiru', 'Knights Radiant', 'Surgebinding', 'Kaladin Stormblessed', 'Shallan Davar'],
            'primary_divergences' => ['administrative crossover', 'documented threshold theory', 'shared first-contact governance'],
            'crossover_entry_point_id' => $entryPoints['stormlight']->id,
            'research_status' => 'solid',
            'research_confidence' => 'solid',
            'visibility' => 'private',
            'content_classification' => 'restricted',
        ]);

        $hpElement = $this->upsertCanonReference('Hogwarts and Wandcraft Under Crossover Stress', [
            'universe' => 'Harry Potter',
            'level' => 'element',
            'title' => 'Hogwarts and Wandcraft Under Crossover Stress',
            'content' => $this->doc('Element-level note tracking how castle wards and wand practice behave under prolonged threshold conditions.'),
            'element_type' => 'concept',
            'canonical_properties' => [
                'warded educational setting',
                'focus-mediated spell expression',
                'institutional spell pedagogy',
            ],
            'first_appearance' => 'Convergence Year 1',
            'source_material_references' => ['Castle wards', 'wand discipline', 'post-war Order logistics'],
            'au_entity_id' => $entities['wandcraft']->id,
            'research_status' => 'developing',
            'research_confidence' => 'developing',
            'visibility' => 'private',
            'content_classification' => 'restricted',
        ]);

        $stormlightElement = $this->upsertCanonReference('Urithiru and Surgebinding Operational Notes', [
            'universe' => 'Stormlight Archive',
            'level' => 'element',
            'title' => 'Urithiru and Surgebinding Operational Notes',
            'content' => $this->doc('Element-level note tracking how towerlight and oath-bound powers behave when contact is prolonged and politically contested.'),
            'element_type' => 'concept',
            'canonical_properties' => [
                'towerlight stabilization',
                'oath-gated power expression',
                'bond-mediated moral pressure',
            ],
            'first_appearance' => 'Convergence Year 1',
            'source_material_references' => ['Urithiru tower systems', 'Radiant oaths', 'cross-domain transit recordings'],
            'au_entity_id' => $entities['surgebinding']->id,
            'research_status' => 'developing',
            'research_confidence' => 'developing',
            'visibility' => 'private',
            'content_classification' => 'restricted',
        ]);

        $this->upsertCanonReferenceEntity($hpUniverse, $entities['harry'], 'moderate', 'au_version', 'Branch-scarred but still recognizably Harry.');
        $this->upsertCanonReferenceEntity($hpUniverse, $entities['hermione'], 'moderate', 'au_version', 'More procedural and governance-facing than most canon readings.');
        $this->upsertCanonReferenceEntity($hpUniverse, $entities['hogwarts'], 'minimal', 'au_version', 'Still the castle, now burdened with border work.');
        $this->upsertCanonReferenceEntity($hpElement, $entities['wandcraft'], 'significant', 'references', 'Tracks the AU framing of British magic as a steering grammar under stress.');

        $this->upsertCanonReferenceEntity($stormlightUniverse, $entities['kaladin'], 'moderate', 'au_version', 'Canon moral frame placed in a crossover governance problem.');
        $this->upsertCanonReferenceEntity($stormlightUniverse, $entities['shallan'], 'moderate', 'au_version', 'Deception and selfhood sharpened by crossover institutional lies.');
        $this->upsertCanonReferenceEntity($stormlightUniverse, $entities['urithiru'], 'minimal', 'au_version', 'Canonical space given new administrative duties.');
        $this->upsertCanonReferenceEntity($stormlightElement, $entities['surgebinding'], 'significant', 'references', 'Captures the AU emphasis on oath pressure in mixed-system contact.');
    }

    /**
     * @param  array<string, Entity>  $entities
     * @return array<string, Secret>
     */
    private function seedSecrets(array $entities): array
    {
        $secrets = [];

        $secrets['seraphine_exit'] = $this->upsertSecret('Seraphine built a quiet shutdown plan for the Grey Line', [
            'secret_content' => $this->doc(
                'Seraphine maintains a private contingency meant to wind the Grey Line down by redistributing witnesses, routes, and leverage rather than defending the institution at all costs.'
            ),
            'secret_type' => 'plan',
            'subject_entity_ids' => [$entities['seraphine']->id, $entities['grey_line']->id],
            'holder_entity_ids' => [$entities['seraphine']->id],
            'known_by_entity_ids' => [$entities['seraphine']->id],
            'exposure_risk' => 'critical',
            'exposure_consequences' => $this->doc('If exposed too early, every faction moves at once and the crossing becomes a battlefield.'),
            'revelation_trigger' => 'Grey Line command deadlock or proof of active civilian sacrifice',
            'status' => 'active',
            'visibility' => 'secret',
            'content_classification' => 'secret',
        ]);

        $secrets['harry_anchor'] = $this->upsertSecret('Harry carries branch-anchor residue', [
            'secret_content' => $this->doc(
                'Harry retains unusual continuity pressure from failed or adjacent narrative branches, making him harder to misdirect and harder to erase from causal structures.'
            ),
            'secret_type' => 'origin',
            'subject_entity_ids' => [$entities['harry']->id],
            'holder_entity_ids' => [$entities['harry']->id, $entities['seraphine']->id],
            'known_by_entity_ids' => [$entities['harry']->id, $entities['seraphine']->id],
            'exposure_risk' => 'high',
            'exposure_consequences' => $this->doc('If institutionalized, Harry stops being treated as a person and starts being treated as infrastructure.'),
            'revelation_trigger' => 'Extended threshold diagnostics or forced memory audit',
            'status' => 'active',
            'visibility' => 'secret',
            'content_classification' => 'secret',
        ]);

        $secrets['towerlight'] = $this->upsertSecret('Towerlight can stabilize apparition-adjacent corridors', [
            'secret_content' => $this->doc(
                'Under specific conditions, towerlight signatures dramatically improve the repeatability of wand-driven transit structures.'
            ),
            'secret_type' => 'power',
            'subject_entity_ids' => [$entities['urithiru']->id, $entities['surgebinding']->id, $entities['wandcraft']->id],
            'holder_entity_ids' => [$entities['hermione']->id, $entities['kaladin']->id],
            'known_by_entity_ids' => [$entities['hermione']->id, $entities['kaladin']->id, $entities['shallan']->id],
            'exposure_risk' => 'high',
            'exposure_consequences' => $this->doc('This would accelerate unsafely scaled route experimentation almost immediately.'),
            'revelation_trigger' => 'Publication of the resonance memorandum without redaction',
            'status' => 'active',
            'visibility' => 'private',
            'content_classification' => 'restricted',
        ]);

        return $secrets;
    }

    /**
     * @param  array<string, Entity>  $entities
     * @param  array<string, Relationship>  $relationships
     * @param  array<string, Secret>  $secrets
     * @return array<string, KnowledgeState>
     */
    private function seedKnowledgeStates(array $entities, array $relationships, array $secrets): array
    {
        $states = [];

        $states['hermione_seraphine'] = $this->upsertKnowledgeState([
            'knower_entity_id' => $entities['hermione']->id,
            'subject_entity_id' => $entities['seraphine']->id,
            'knowledge_type' => 'true_nature',
            'knowledge_content' => $this->doc(
                'Hermione knows Seraphine is no longer trying to preserve the Grey Line unchanged, even if Seraphine refuses to say so directly.'
            ),
            'accuracy' => 'true',
            'acquired_at_era' => 'Convergence Year 1 / Late Winter',
            'acquired_through' => 'deduction',
            'acquired_from_entity_id' => $entities['seraphine']->id,
            'current_belief_state' => 'believes',
            'acted_on' => false,
            'valid_from_era' => 'Convergence Year 1 / Late Winter',
            'is_current' => true,
            'visibility' => 'secret',
            'content_classification' => 'secret',
        ]);

        $states['shallan_secret'] = $this->upsertKnowledgeState([
            'knower_entity_id' => $entities['shallan']->id,
            'subject_secret_id' => $secrets['seraphine_exit']->id,
            'knowledge_type' => 'secret',
            'knowledge_content' => $this->doc(
                'Shallan suspects there is a shutdown design hiding behind Seraphine’s procedural caution.'
            ),
            'accuracy' => 'partial',
            'acquired_at_era' => 'Convergence Year 1 / Late Winter',
            'acquired_through' => 'observation',
            'current_belief_state' => 'suspects',
            'acted_on' => false,
            'valid_from_era' => 'Convergence Year 1 / Late Winter',
            'is_current' => true,
            'visibility' => 'secret',
            'content_classification' => 'secret',
        ]);

        $states['seraphine_harry'] = $this->upsertKnowledgeState([
            'knower_entity_id' => $entities['seraphine']->id,
            'subject_secret_id' => $secrets['harry_anchor']->id,
            'knowledge_type' => 'secret',
            'knowledge_content' => $this->doc(
                'Seraphine knows Harry reacts to branch-pressure patterns in ways nobody else on the Hogwarts side does.'
            ),
            'accuracy' => 'true',
            'acquired_at_era' => 'Convergence Year 1 / Early Winter',
            'acquired_through' => 'observation',
            'current_belief_state' => 'compartmentalizing',
            'acted_on' => false,
            'valid_from_era' => 'Convergence Year 1 / Early Winter',
            'is_current' => true,
            'visibility' => 'secret',
            'content_classification' => 'secret',
        ]);

        $states['kaladin_relationship'] = $this->upsertKnowledgeState([
            'knower_entity_id' => $entities['kaladin']->id,
            'subject_relationship_id' => $relationships['hermione_seraphine']->id,
            'knowledge_type' => 'public_fact',
            'knowledge_content' => $this->doc(
                'Kaladin understands that the Hermione-Seraphine conflict is the moral hinge of the institution, not a personality clash.'
            ),
            'accuracy' => 'true',
            'acquired_at_era' => 'Convergence Year 1 / Late Winter',
            'acquired_through' => 'observation',
            'current_belief_state' => 'believes',
            'acted_on' => true,
            'action_notes' => $this->doc('He begins planning extraction paths that assume the argument will fail.'),
            'valid_from_era' => 'Convergence Year 1 / Late Winter',
            'is_current' => true,
            'visibility' => 'private',
            'content_classification' => 'restricted',
        ]);

        return $states;
    }

    /**
     * @param  array<string, Entity>  $entities
     * @param  array<string, KnowledgeState>  $knowledgeStates
     * @param  array<string, Secret>  $secrets
     */
    private function seedPerceptionStates(array $entities, array $knowledgeStates, array $secrets): void
    {
        $this->upsertPerceptionState('entity', $entities['seraphine']->id, [
            'true_state' => $this->doc('Seraphine is a morally overextended operator quietly preparing for controlled institutional collapse.'),
            'perceived_state' => $this->doc('Seraphine is the polished Grey Line liaison who can still keep everything running if given room.'),
            'divergence_level' => 'complete',
            'maintained_by_entity_ids' => [$entities['seraphine']->id, $entities['grey_line']->id],
            'maintenance_method' => 'deliberate_misdirection',
            'maintenance_effort' => 'critical',
            'perceiving_entity_ids' => [],
            'immune_entity_ids' => [$entities['hermione']->id, $entities['shallan']->id],
            'revelation_condition' => $this->doc('The facade fails if the shutdown contingencies or contingency witnesses surface in public sequence.'),
            'revelation_consequence' => $this->doc('Seraphine stops being readable as a loyal functionary and becomes the crisis itself.'),
            'revelation_risk' => 'critical',
            'is_current' => true,
            'related_secret_id' => $secrets['seraphine_exit']->id,
            'related_knowledge_state_ids' => [$knowledgeStates['hermione_seraphine']->id, $knowledgeStates['shallan_secret']->id],
            'visibility' => 'secret',
            'content_classification' => 'secret',
        ]);

        $this->upsertPerceptionState('location', $entities['veilfracture']->id, [
            'true_state' => $this->doc('The Veilfracture Crossing is still active, maintained, and politically contested.'),
            'perceived_state' => $this->doc('An unstable ruin-site that occasionally produces unusable anomalies.'),
            'divergence_level' => 'complete',
            'maintained_by_entity_ids' => [$entities['grey_line']->id],
            'maintenance_method' => 'strategic_information_control',
            'maintenance_effort' => 'active',
            'perceiving_entity_ids' => [],
            'immune_entity_ids' => [$entities['harry']->id, $entities['kaladin']->id, $entities['hermione']->id],
            'revelation_condition' => $this->doc('Public violent failure at the threshold or leak of the route index.'),
            'revelation_consequence' => $this->doc('The crossing becomes impossible to keep small or quiet.'),
            'revelation_risk' => 'high',
            'is_current' => true,
            'related_secret_id' => $secrets['towerlight']->id,
            'related_knowledge_state_ids' => [$knowledgeStates['seraphine_harry']->id],
            'visibility' => 'secret',
            'content_classification' => 'secret',
        ]);
    }

    /**
     * @param  array<string, Entity>  $entities
     */
    private function seedPowerInteractions(array $entities): void
    {
        $wandAndStorm = $this->upsertPowerInteraction('Surgebinding × British Wandcraft', [
            'system_a_entity_id' => $entities['surgebinding']->id,
            'system_b_entity_id' => $entities['wandcraft']->id,
            'description' => $this->doc(
                'The two systems do not merge cleanly, but they can reinforce each other when intent clarity and moral commitment align.'
            ),
            'directionality' => 'contextual',
            'dominant_system_entity_id' => $entities['surgebinding']->id,
            'effects' => [
                [
                    'effect_type' => 'catalyzes',
                    'affected_aspect' => 'reality_anchor',
                    'magnitude' => 'significant',
                    'notes' => 'Stable transit becomes much more plausible near oath-saturated intent.',
                ],
                [
                    'effect_type' => 'destabilizes',
                    'affected_aspect' => 'cognitive_function',
                    'magnitude' => 'minor',
                    'notes' => 'Overstress can produce brief narrative echo phenomena.',
                ],
            ],
            'proximity_required' => true,
            'location_conditions' => ['threshold site', 'ward support or towerlight support'],
            'practitioner_conditions' => ['clear intent', 'strong witness structure'],
            'temporal_conditions' => ['lower failure rate during calm weather and stable tower cycles'],
            'artifact_conditions' => ['wand focus recommended', 'spren bond present on Roshar side'],
            'trigger_type' => 'ritual alignment',
            'trigger_description' => $this->doc('Most stable when both systems are asked to do something ethically legible, not merely efficient.'),
            'trigger_frequency' => 'situational',
            'interaction_scale' => 'local',
            'scale_variance' => 'transforms_with_scale',
            'knowledge_state' => 'theorized',
            'danger_rating' => 'high',
            'resolution_notes' => $this->doc('Strongest evidence points toward complementary stabilization rather than direct power amplification.'),
            'source_universe_a' => 'Stormlight Archive',
            'source_universe_b' => 'Harry Potter',
            'visibility' => 'private',
            'content_classification' => 'restricted',
        ]);

        $greyAndWand = $this->upsertPowerInteraction('Grey Line Weaving × British Wandcraft', [
            'system_a_entity_id' => $entities['grey_line_weaving']->id,
            'system_b_entity_id' => $entities['wandcraft']->id,
            'description' => $this->doc(
                'Grey Line Weaving makes wand-based transit more administratively manageable while also making it easier to hide coercive assumptions inside procedure.'
            ),
            'directionality' => 'asymmetrical',
            'dominant_system_entity_id' => $entities['grey_line_weaving']->id,
            'effects' => [
                [
                    'effect_type' => 'suppresses',
                    'affected_aspect' => 'emotional_resonance',
                    'magnitude' => 'moderate',
                    'notes' => 'Useful for keeping corridors calm but psychologically flattening over time.',
                ],
                [
                    'effect_type' => 'catalyzes',
                    'affected_aspect' => 'reality_anchor',
                    'magnitude' => 'moderate',
                    'notes' => 'Raises repeatability of route behavior when paired with disciplined focus work.',
                ],
            ],
            'proximity_required' => true,
            'location_conditions' => ['managed threshold'],
            'practitioner_conditions' => ['trained operator', 'prepared focus object'],
            'temporal_conditions' => ['less stable during post-conflict emotional spikes'],
            'artifact_conditions' => ['route index or keyed anchor object'],
            'trigger_type' => 'procedural transit',
            'trigger_description' => $this->doc('Behaves best when everyone involved believes the process is safe, which is part of why it is ethically slippery.'),
            'trigger_frequency' => 'repeatable',
            'interaction_scale' => 'local',
            'scale_variance' => 'intensifies_with_scale',
            'knowledge_state' => 'established',
            'danger_rating' => 'moderate',
            'source_universe_a' => 'Original',
            'source_universe_b' => 'Harry Potter',
            'visibility' => 'secret',
            'content_classification' => 'secret',
        ]);

        $greyAndStorm = $this->upsertPowerInteraction('Grey Line Weaving × Surgebinding', [
            'system_a_entity_id' => $entities['grey_line_weaving']->id,
            'system_b_entity_id' => $entities['surgebinding']->id,
            'description' => $this->doc(
                'Grey Line routines can host Surgebinding-adjacent transit structures, but the moral demands of oath-bound power create friction with control-first doctrine.'
            ),
            'directionality' => 'contextual',
            'dominant_system_entity_id' => $entities['surgebinding']->id,
            'effects' => [
                [
                    'effect_type' => 'transforms',
                    'affected_aspect' => 'reality_anchor',
                    'magnitude' => 'significant',
                    'notes' => 'Structures become more stable but less obedient to cynical intent.',
                ],
            ],
            'proximity_required' => true,
            'location_conditions' => ['towerlight adjacency preferred'],
            'practitioner_conditions' => ['oathbound actor or equivalent moral clarity'],
            'trigger_type' => 'threshold defense',
            'trigger_description' => $this->doc('Most reliable when used protectively rather than exploitatively.'),
            'trigger_frequency' => 'situational',
            'interaction_scale' => 'regional',
            'scale_variance' => 'uniform',
            'knowledge_state' => 'rumored',
            'danger_rating' => 'existential_risk',
            'source_universe_a' => 'Original',
            'source_universe_b' => 'Stormlight Archive',
            'visibility' => 'secret',
            'content_classification' => 'secret',
        ]);

        $this->upsertPowerInteractionInstance($wandAndStorm, $entities['event_crossing'], [
            'involved_entity_ids' => [$entities['harry']->id, $entities['kaladin']->id],
            'outcome_match' => 'partial',
            'outcome_notes' => $this->doc('The crossing held, but the degree of mutual reinforcement exceeded prior estimates.'),
            'observed_at_era' => 'Convergence Year 1 / Early Winter',
        ]);

        $this->upsertPowerInteractionInstance($greyAndStorm, $entities['event_tribunal'], [
            'involved_entity_ids' => [$entities['seraphine']->id, $entities['kaladin']->id],
            'outcome_match' => 'new_discovery',
            'outcome_notes' => $this->doc('Institutional breakdown increased threshold responsiveness instead of degrading it, suggesting moral stress is part of the interaction logic.'),
            'observed_at_era' => 'Convergence Year 1 / Late Winter',
        ]);

        $this->upsertPowerInteractionInstance($greyAndWand, $entities['event_duel'], [
            'involved_entity_ids' => [$entities['harry']->id, $entities['seraphine']->id],
            'outcome_match' => 'contradicted',
            'outcome_notes' => $this->doc('The corridor remained usable even after the operator consensus broke, contradicting the expected dependency on procedural cohesion.'),
            'observed_at_era' => 'Convergence Year 1 / Late Winter',
        ]);
    }

    /**
     * @param  array<string, Entity>  $entities
     */
    private function seedSpatialRecords(array $entities): void
    {
        $this->upsertLocationContainment($entities['forest'], $entities['hogwarts'], [
            'containment_type' => 'physical',
            'era_start' => 'Founding era',
            'is_active' => true,
        ]);

        $this->upsertLocationContainment($entities['veilfracture'], $entities['forest'], [
            'containment_type' => 'dimensional',
            'era_start' => 'Convergence Year 0',
            'is_active' => true,
        ]);

        $this->upsertLocationContainment($entities['relay_vault'], $entities['urithiru'], [
            'containment_type' => 'physical',
            'era_start' => 'Convergence Year 0',
            'is_active' => true,
        ]);

        $this->upsertLocationControl($entities['hogwarts'], $entities['order'], [
            'control_type' => 'protected',
            'control_start_era' => 'Convergence Year 1',
            'is_current' => true,
            'how_control_was_established' => $this->doc('Protection claim asserted to keep the crossing from being folded into ministry command too quickly.'),
            'resistance_level' => 'minor',
            'notes' => $this->doc('Not sovereignty, but enough influence to set emergency terms.'),
        ]);

        $this->upsertLocationControl($entities['urithiru'], $entities['radiants'], [
            'control_type' => 'sovereign',
            'control_start_era' => 'Current',
            'is_current' => true,
            'how_control_was_established' => $this->doc('Canonical Radiant authority, now extended into crossover stewardship.'),
            'resistance_level' => 'minor',
            'notes' => $this->doc('The tower remains Radiant-held even while hosting outside pressure.'),
        ]);

        $this->upsertLocationControl($entities['veilfracture'], $entities['grey_line'], [
            'control_type' => 'contested',
            'control_start_era' => 'Convergence Year 0',
            'is_current' => true,
            'how_control_was_established' => $this->doc('Grey Line operators built the maintenance routines that keep the seam usable.'),
            'resistance_level' => 'active_conflict',
            'resistance_entity_id' => $entities['silken_court']->id,
            'notes' => $this->doc('Control is technical, not moral, and that difference is catching up to them.'),
        ]);

        $this->upsertLocationControl($entities['relay_vault'], $entities['grey_line'], [
            'control_type' => 'puppet',
            'control_start_era' => 'Convergence Year 0',
            'is_current' => true,
            'how_control_was_established' => $this->doc('Unofficial operational control masked as innocuous tower maintenance allocation.'),
            'resistance_level' => 'significant',
            'resistance_entity_id' => $entities['mirror_archive']->id,
            'notes' => $this->doc('The room belongs to whoever can read the route indexes first.'),
        ]);

        $this->upsertTravelRoute($entities['hogwarts'], $entities['veilfracture'], [
            'route_type' => 'magical',
            'standard_duration' => '9 minutes on foot plus anchor setup',
            'method_variants' => [
                ['method' => 'guided forest approach', 'duration' => '9 minutes', 'conditions' => 'stable weather', 'notes' => 'Safest standard approach.'],
            ],
            'hazards' => ['misleading path echoes', 'memory resonance spikes'],
            'visibility' => 'private',
            'content_classification' => 'restricted',
        ]);

        $this->upsertTravelRoute($entities['veilfracture'], $entities['urithiru'], [
            'route_type' => 'dimensional',
            'standard_duration' => 'instant transit with 2-4 minutes stabilization',
            'method_variants' => [
                ['method' => 'towerlight-assisted crossing', 'required_ability_or_artifact' => 'wand focus and anchor witness', 'duration' => 'instant', 'conditions' => 'towerlight alignment', 'notes' => 'Best-supported route.'],
            ],
            'hazards' => ['identity bleed', 'threshold recoil', 'procedural collapse under panic'],
            'known_by_entity_ids' => [$entities['harry']->id, $entities['hermione']->id, $entities['kaladin']->id, $entities['shallan']->id, $entities['seraphine']->id],
            'controlled_by_entity_id' => $entities['grey_line']->id,
            'visibility' => 'secret',
            'content_classification' => 'secret',
        ]);

        $this->upsertTravelRoute($entities['urithiru'], $entities['relay_vault'], [
            'route_type' => 'conceptual',
            'standard_duration' => '4 minutes if you know where the room wants to be',
            'method_variants' => [
                ['method' => 'maintenance path with keyed sigil', 'required_ability_or_artifact' => 'relay sigil ring', 'duration' => '4 minutes', 'conditions' => 'low traffic', 'notes' => 'Looks like routine movement to unbriefed observers.'],
            ],
            'hazards' => ['directional confusion', 'watcher detection'],
            'controlled_by_entity_id' => $entities['grey_line']->id,
            'visibility' => 'author_only',
            'content_classification' => 'secret',
        ]);
    }

    /**
     * @param  array<string, Entity>  $entities
     * @param  array<string, GroupRelationship>  $groups
     * @return array<string, Meta>
     */
    private function seedMeta(array $entities, array $groups): array
    {
        $meta = [];

        $meta['theme'] = $this->upsertMeta('Chosen Burdens and Professional Secrecy', [
            'category' => 'themes_and_motifs',
            'meta_note_type' => 'decision',
            'content' => $this->doc(
                'The crossover works best when competence is emotionally costly rather than cleanly empowering.',
                'Keep returning to the idea that systems built to protect people can become dangerous precisely because they are good at postponing visible harm.'
            ),
            'priority' => 'high',
            'action_status' => 'resolved',
            'visibility' => 'private',
            'content_classification' => 'restricted',
        ]);
        $meta['theme']->entities()->syncWithoutDetaching([$entities['harry']->id, $entities['hermione']->id, $entities['seraphine']->id]);

        $meta['palette'] = $this->upsertMeta('Veilfracture Sensory Palette', [
            'category' => 'sensory_palettes',
            'meta_note_type' => 'passive',
            'content' => $this->doc(
                'Cold blue-white light on wet bark, breath-fog caught in lantern gold, distant metallic ringing under soft owl calls.'
            ),
            'sense_sight' => 'Blue-white threshold glare cut by wet black branches and lantern gold.',
            'sense_sound' => 'Tower-hum harmonics braided with wind and owl calls.',
            'sense_smell' => 'Rainstone, cold sap, ozone, and old parchment.',
            'sense_touch' => 'Icy air over damp stone; static at the wrists.',
            'emotional_register' => 'Awe held tightly inside administrative dread.',
            'priority' => 'medium',
            'action_status' => 'pending',
            'visibility' => 'private',
            'content_classification' => 'restricted',
        ]);
        $meta['palette']->entities()->syncWithoutDetaching([$entities['veilfracture']->id, $entities['forest']->id]);

        $meta['task'] = $this->upsertMeta('Decide Seraphine Endgame Before Drafting Collapse Arc', [
            'category' => 'tensions_and_contradictions',
            'meta_note_type' => 'active_task',
            'content' => $this->doc(
                'Need to decide whether Seraphine survives the collapse arc, disappears into the archive war, or remains to help build the replacement structure.'
            ),
            'priority' => 'blocking',
            'action_status' => 'pending',
            'visibility' => 'private',
            'content_classification' => 'restricted',
        ]);
        $meta['task']->entities()->syncWithoutDetaching([$entities['seraphine']->id, $entities['grey_line']->id]);

        $meta['symbol'] = $this->upsertMeta('Lantern Ring Sigil', [
            'category' => 'symbols_and_iconography',
            'meta_note_type' => 'passive',
            'content' => $this->doc(
                'The lantern ring should read as reassurance and control at the same time.',
                'It symbolizes curated passage: safety offered, terms implied, exits obscured.'
            ),
            'symbol_name' => 'Lantern Ring',
            'symbol_origin_entity_id' => $entities['grey_line']->id,
            'symbol_usage_context' => 'Used on route ledgers, meeting seals, relay vault access plates, and private operator correspondence.',
            'symbol_associated_entity_ids' => [$entities['seraphine']->id, $entities['grey_line']->id, $entities['relay_vault']->id],
            'symbol_scope' => 'both',
            'priority' => 'medium',
            'action_status' => 'pending',
            'visibility' => 'private',
            'content_classification' => 'restricted',
        ]);
        $meta['symbol']->groupRelationships()->syncWithoutDetaching([
            $groups['crisis_board']->id => ['connection_notes' => 'Board-level authority marker and soft threat symbol.'],
        ]);

        return $meta;
    }

    /**
     * @param  array<string, Entity>  $entities
     * @param  array<string, Collection>  $collections
     * @return array<string, SessionLog>
     */
    private function seedSessionLogs(array $entities, array $collections): array
    {
        $sessions = [];

        $sessions['notion'] = $this->upsertSessionLog('Notion Sync: Convergence Skeleton', [
            'session_date' => '2026-06-23',
            'external_tool' => 'notion',
            'focus_entity_ids' => [$entities['harry']->id, $entities['hermione']->id, $entities['veilfracture']->id],
            'focus_collection_ids' => [$collections['principals']->id, $collections['sites']->id],
            'focus_description' => 'Build the foundational crossover cast, threshold locations, and first-contact beats.',
            'decisions_made' => [
                'Keep the crossover ethically procedural rather than pure spectacle.',
                'Make Seraphine a pressure nexus rather than a flat antagonist.',
            ],
            'changes_applied' => [
                'Established the main entity roster',
                'Set the crossover tone and location spine',
            ],
            'open_threads' => [
                'How public the Order becomes if the crossing leaks',
                'Whether Seraphine gets a survivable exit',
            ],
            'session_significance' => 'foundational',
            'notes' => $this->doc('This is the shaping session where the project stops being a vibes bundle and becomes a usable story architecture.'),
        ]);

        $sessions['claude'] = $this->upsertSessionLog('Claude Pass: Tribunal Structure Review', [
            'session_date' => '2026-06-23',
            'external_tool' => 'claude',
            'focus_entity_ids' => [$entities['seraphine']->id, $entities['grey_line']->id, $entities['kaladin']->id],
            'focus_description' => 'Stress-test the tribunal sequence and its moral escalation.',
            'decisions_made' => [
                'The tribunal should fail because everyone has different definitions of acceptable secrecy.',
                'Kaladin should read the collapse before the board does.',
            ],
            'changes_applied' => [
                'Defined the crisis board as its own pressure chamber',
                'Clarified the duel as a downstream consequence, not a detached action beat',
            ],
            'open_threads' => [
                'Whether the Mirror Archive should intervene directly',
            ],
            'session_significance' => 'major',
            'notes' => $this->doc('Useful structural pass for turning institutional disagreement into scene pressure.'),
        ]);

        return $sessions;
    }

    /**
     * @param  array<string, Entity>  $entities
     * @param  array<string, Meta>  $meta
     */
    private function seedPipeline(array $entities, array $meta): void
    {
        $chapter = $this->upsertPipelineItem('Chapter: Oaths in Ash', [
            'pipeline_type' => 'chapter',
            'pipeline_stage' => 'outlined',
            'content' => $this->doc(
                'A chapter built around the tribunal unraveling into the forest-side aftermath.'
            ),
            'tracked_entity_id' => $entities['seraphine']->id,
            'arc_stage' => 'collapse preparation',
            'arc_notes' => $this->doc('Needs to balance institutional scale with personal unraveling.'),
            'notes' => $this->doc('This should feel like a formal room failing before anyone raises a weapon.'),
            'sort_order' => 1,
            'visibility' => 'private',
            'content_classification' => 'restricted',
        ]);

        $this->upsertPipelineItem('Scene: The First Crossing Under Snow', [
            'pipeline_type' => 'scene',
            'parent_pipeline_item_id' => $chapter->id,
            'pipeline_stage' => 'drafted',
            'content' => $this->doc(
                'Harry, Hermione, and Kaladin complete the first stable return and immediately understand it cannot stay a private miracle for long.'
            ),
            'pov_character_entity_id' => $entities['harry']->id,
            'location_entity_id' => $entities['forest']->id,
            'timeline_position' => 10,
            'emotional_beat' => 'awed dread',
            'narrative_purpose' => $this->doc('Establish the crossing as emotionally real before it becomes institutionally contested.'),
            'scene_content_warnings' => ['panic spiral', 'war memory residue'],
            'sensory_palette_meta_id' => $meta['palette']->id,
            'tracked_entity_id' => $entities['harry']->id,
            'arc_stage' => 'contact',
            'notes' => $this->doc('Snow, breath, towerlight echo, and the sensation of success arriving too early.'),
            'sort_order' => 1,
            'visibility' => 'private',
            'content_classification' => 'restricted',
        ]);

        $this->upsertPipelineItem('Scene: Tribunal Chamber Breach', [
            'pipeline_type' => 'scene',
            'parent_pipeline_item_id' => $chapter->id,
            'pipeline_stage' => 'outlined',
            'content' => $this->doc(
                'The Grey Line board tries to turn ethics into procedure and discovers everyone has brought a different apocalypse threshold.'
            ),
            'pov_character_entity_id' => $entities['seraphine']->id,
            'location_entity_id' => $entities['urithiru']->id,
            'timeline_position' => 10,
            'emotional_beat' => 'formal panic',
            'narrative_purpose' => $this->doc('Break the authority frame before the physical confrontation.'),
            'scene_content_warnings' => ['coercive governance', 'institutional gaslighting'],
            'tracked_entity_id' => $entities['seraphine']->id,
            'arc_stage' => 'collapse',
            'notes' => $this->doc('No shouting until the room has already morally failed.'),
            'sort_order' => 2,
            'visibility' => 'private',
            'content_classification' => 'restricted',
        ]);

        $this->upsertPipelineItem('Character Study: Seraphine Under Pressure', [
            'pipeline_type' => 'character_study',
            'pipeline_stage' => 'revised',
            'content' => $this->doc(
                'Track what Seraphine sounds like when she is still lying for the system, when she starts telling partial truth, and when she finally stops treating collapse as failure.'
            ),
            'tracked_entity_id' => $entities['seraphine']->id,
            'influenced_entity_ids' => [$entities['hermione']->id, $entities['harry']->id],
            'notes' => $this->doc('Keep her intelligent, compromised, and sincere all at once.'),
            'sort_order' => 2,
            'visibility' => 'private',
            'content_classification' => 'restricted',
        ]);

        $this->upsertPipelineItem('Outline: Grey Line Collapse Options', [
            'pipeline_type' => 'outline',
            'pipeline_stage' => 'outlined',
            'content' => $this->doc(
                'List three viable endgames: controlled dissolution, hostile seizure, or public exposure with mutual fallback infrastructure.'
            ),
            'tracked_entity_id' => $entities['grey_line']->id,
            'notes' => $this->doc('This is where the political architecture of the arc lives.'),
            'sort_order' => 3,
            'visibility' => 'private',
            'content_classification' => 'restricted',
        ]);

        $this->upsertPipelineItem('Inspiration: Lantern Light on Wet Stone', [
            'pipeline_type' => 'inspiration',
            'pipeline_stage' => 'concept',
            'content' => $this->doc(
                'Visual anchor for the threshold scenes: wet black stone, gold lantern light, blue-white stress glow, and breath visibly frosting in the corridor.'
            ),
            'inspiration_source_universe' => 'Original',
            'inspiration_source_element' => 'threshold palette',
            'how_used' => 'Use whenever the crossing needs to feel beautiful and administratively terrifying at once.',
            'why_it_fits' => 'It keeps the site emotionally specific instead of generic fantasy portal blue.',
            'sort_order' => 4,
            'visibility' => 'private',
            'content_classification' => 'restricted',
        ]);
    }

    /**
     * @param  array<string, Entity>  $entities
     * @param  array<string, GroupRelationship>  $groups
     * @param  array<string, SessionLog>  $sessions
     */
    private function seedEntityNotesAndQuestions(array $entities, array $groups, array $sessions): void
    {
        $this->upsertEntityNote($entities['harry'], 'Voice', 'Keep Harry dry and concise. He should sound like someone who stopped needing to impress rooms years ago.', 1);
        $this->upsertEntityNote($entities['seraphine'], 'Pressure', 'Seraphine should always sound like she is translating danger into manageable sentences.', 1);

        $this->upsertEntityQuestion($entities['seraphine'], 'Does Seraphine survive the collapse arc?', [
            'context' => 'This answer determines whether the Grey Line becomes tragedy, reform narrative, or succession crisis.',
            'status' => 'open',
            'priority' => 'blocking',
            'linked_entity_ids' => [$entities['grey_line']->id, $entities['mirror_archive']->id],
            'linked_group_relationship_ids' => [$groups['crisis_board']->id],
            'source_session_log_id' => $sessions['claude']->id,
            'sort_order' => 1,
        ]);

        $this->upsertEntityQuestion($entities['harry'], 'How public can Harry become before he is treated as infrastructure?', [
            'context' => 'Important to the later exposure arc and to the ethics of using him as an anchor figure.',
            'status' => 'open',
            'priority' => 'high',
            'linked_entity_ids' => [$entities['hermione']->id, $entities['seraphine']->id],
            'source_session_log_id' => $sessions['notion']->id,
            'sort_order' => 1,
        ]);
    }

    /**
     * @param  array<string, Entity>  $entities
     */
    private function refreshCompletion(array $entities): void
    {
        foreach ($entities as $entity) {
            $this->flagFlipper->flipAll($entity->fresh());
        }

        foreach ($entities as $entity) {
            $this->completionUpdater->recalculate($entity->fresh());
        }
    }

    /**
     * @param  array<string, Entity>  $entities
     */
    private function publishShowcaseEntities(array $entities): void
    {
        foreach (['harry', 'hermione', 'kaladin', 'shallan', 'hogwarts', 'urithiru', 'order', 'radiants'] as $key) {
            $entity = $entities[$key]->fresh();

            if ($entity->completion_score >= 50 && $entity->visibility !== 'public_knowledge') {
                $this->entityService->publish($entity);
            }
        }
    }

    private function upsertEntity(string $name, array $data): Entity
    {
        $entity = Entity::withTrashed()->where('name', $name)->first();

        if (! $entity) {
            return $this->entityService->create(array_merge(['name' => $name], $data));
        }

        if (method_exists($entity, 'restore') && $entity->trashed()) {
            $entity->restore();
        }

        $this->entityService->update($entity, $data);

        return $entity->fresh();
    }

    private function upsertRelationship(Entity $from, Entity $to, array $data): Relationship
    {
        $query = Relationship::withTrashed()->where([
            'from_entity_id' => $from->id,
            'to_entity_id' => $to->id,
            'relationship_type' => $data['relationship_type'],
            'direction' => $data['direction'] ?? 'one_way',
        ]);

        $relationship = $query->first();

        if (! $relationship) {
            return $this->relationshipService->create($from, $to, $data);
        }

        if ($relationship->trashed()) {
            $relationship->restore();
        }

        return tap($relationship)->update($data)->fresh();
    }

    private function upsertGroupRelationship(string $name, array $data): GroupRelationship
    {
        /** @var GroupRelationship|null $group */
        $group = GroupRelationship::withTrashed()->where('name', $name)->first();

        if (! $group) {
            return $this->relationshipService->createGroup(array_merge(['name' => $name], $data));
        }

        if ($group->trashed()) {
            $group->restore();
        }

        $group->update($data);

        return $group->fresh();
    }

    private function upsertGroupMembership(GroupRelationship $group, Entity $entity, array $data): GroupRelationshipEntity
    {
        $membership = GroupRelationshipEntity::query()->firstOrNew([
            'group_relationship_id' => $group->id,
            'entity_id' => $entity->id,
        ]);

        $membership->fill(array_merge([
            'is_active_member' => true,
        ], $data));
        $membership->save();

        return $membership->fresh();
    }

    private function upsertFactionMembership(Entity $faction, Entity $member, array $data): FactionMembership
    {
        $membership = FactionMembership::withTrashed()->firstOrNew([
            'faction_entity_id' => $faction->id,
            'member_entity_id' => $member->id,
        ]);

        if ($membership->exists && $membership->trashed()) {
            $membership->restore();
        }

        $membership->fill($data);
        $membership->save();

        return $membership->fresh();
    }

    private function upsertConcurrencyGroup(string $name, array $data): ConcurrencyGroup
    {
        $group = ConcurrencyGroup::withTrashed()->firstOrNew(['name' => $name]);

        if ($group->exists && $group->trashed()) {
            $group->restore();
        }

        $group->fill($data);
        $group->save();

        return $group->fresh();
    }

    private function upsertTimelineEntry(Entity $timelineEntity, Entity $eventEntity, array $data): Timeline
    {
        $entry = Timeline::withTrashed()->firstOrNew([
            'timeline_id' => $timelineEntity->id,
            'event_entity_id' => $eventEntity->id,
        ]);

        if ($entry->exists && $entry->trashed()) {
            $entry->restore();
        }

        $entry->fill($data);
        $entry->save();

        return $entry->fresh();
    }

    private function upsertCharacterState(Entity $entity, array $data): CharacterStateTracker
    {
        $state = CharacterStateTracker::withTrashed()->firstOrNew([
            'entity_id' => $entity->id,
            'snapshot_label' => $data['snapshot_label'],
        ]);

        if ($state->exists && $state->trashed()) {
            $state->restore();
        }

        $state->fill($data);
        $state->save();

        return $state->fresh();
    }

    private function upsertDocument(string $title, array $data): Document
    {
        $document = Document::withTrashed()->firstOrNew(['title' => $title]);

        if ($document->exists && $document->trashed()) {
            $document->restore();
        }

        $document->fill($data);
        $document->save();

        return $document->fresh();
    }

    private function upsertDocumentEntity(Document $document, Entity $entity, string $relationshipType, string $notes): DocumentEntity
    {
        $pivot = DocumentEntity::query()->firstOrNew([
            'document_id' => $document->id,
            'entity_id' => $entity->id,
            'relationship_type' => $relationshipType,
        ]);

        $pivot->notes = $this->doc($notes);
        $pivot->save();

        return $pivot->fresh();
    }

    private function upsertMedia(array $data): MediaReference
    {
        $lookup = [
            'title' => $data['title'],
            'entity_id' => $data['entity_id'] ?? null,
            'timeline_entry_id' => $data['timeline_entry_id'] ?? null,
        ];

        $media = MediaReference::withTrashed()->firstOrNew($lookup);

        if ($media->exists && $media->trashed()) {
            $media->restore();
        }

        $media->fill($data);
        $media->save();

        return $media->fresh();
    }

    private function upsertCollection(string $name, array $data): Collection
    {
        $collection = Collection::withTrashed()->firstOrNew(['name' => $name]);

        if ($collection->exists && $collection->trashed()) {
            $collection->restore();
        }

        $collection->fill($data);
        $collection->save();

        return $collection->fresh();
    }

    private function upsertCollectionEntity(Collection $collection, Entity $entity, string $role, int $sortOrder, string $notes): CollectionEntity
    {
        $entry = CollectionEntity::query()->firstOrNew([
            'collection_id' => $collection->id,
            'entity_id' => $entity->id,
        ]);

        $entry->fill([
            'added_manually' => true,
            'added_by_rule' => false,
            'role_in_collection' => $role,
            'sort_order' => $sortOrder,
            'notes' => $notes,
        ]);
        $entry->save();

        return $entry->fresh();
    }

    private function upsertCollectionDocument(Collection $collection, Document $document, string $role, int $sortOrder, string $notes): CollectionDocument
    {
        $entry = CollectionDocument::query()->firstOrNew([
            'collection_id' => $collection->id,
            'document_id' => $document->id,
        ]);

        $entry->fill([
            'role_in_collection' => $role,
            'sort_order' => $sortOrder,
            'notes' => $notes,
        ]);
        $entry->save();

        return $entry->fresh();
    }

    private function upsertGlossary(string $term, array $data): Glossary
    {
        $glossary = Glossary::withTrashed()->firstOrNew(['term' => $term]);

        if ($glossary->exists && $glossary->trashed()) {
            $glossary->restore();
        }

        $glossary->fill($data);
        $glossary->save();

        return $glossary->fresh();
    }

    private function upsertCrossoverEntryPoint(string $sourceUniverse, array $data): CrossoverEntryPoint
    {
        $point = CrossoverEntryPoint::withTrashed()->firstOrNew(['source_universe' => $sourceUniverse]);

        if ($point->exists && $point->trashed()) {
            $point->restore();
        }

        $point->fill($data);
        $point->save();

        return $point->fresh();
    }

    private function upsertCanonReference(string $title, array $data): SourceCanonReference
    {
        $reference = SourceCanonReference::withTrashed()->firstOrNew(['title' => $title]);

        if ($reference->exists && $reference->trashed()) {
            $reference->restore();
        }

        $reference->fill(array_merge(['title' => $title], $data));
        $reference->save();

        return $reference->fresh();
    }

    private function upsertCanonReferenceEntity(SourceCanonReference $reference, Entity $entity, string $divergenceLevel, string $relationshipType, string $notes): CanonReferenceEntity
    {
        $pivot = CanonReferenceEntity::query()->firstOrNew([
            'canon_reference_id' => $reference->id,
            'entity_id' => $entity->id,
        ]);

        $pivot->fill([
            'divergence_level' => $divergenceLevel,
            'relationship_type' => $relationshipType,
            'divergence_notes' => $this->doc($notes),
        ]);
        $pivot->save();

        return $pivot->fresh();
    }

    private function upsertSecret(string $title, array $data): Secret
    {
        $secret = Secret::withTrashed()->firstOrNew(['title' => $title]);

        if ($secret->exists && $secret->trashed()) {
            $secret->restore();
        }

        $secret->fill($data);
        $secret->save();

        return $secret->fresh();
    }

    private function upsertKnowledgeState(array $data): KnowledgeState
    {
        $lookup = [
            'knower_entity_id' => $data['knower_entity_id'],
            'knowledge_type' => $data['knowledge_type'],
            'subject_entity_id' => $data['subject_entity_id'] ?? null,
            'subject_relationship_id' => $data['subject_relationship_id'] ?? null,
            'subject_secret_id' => $data['subject_secret_id'] ?? null,
        ];

        $state = KnowledgeState::withTrashed()->firstOrNew($lookup);

        if ($state->exists && $state->trashed()) {
            $state->restore();
        }

        $state->fill($data);
        $state->save();

        return $state->fresh();
    }

    private function upsertPerceptionState(string $subjectType, int $subjectId, array $data): PerceptionState
    {
        $state = PerceptionState::withTrashed()->firstOrNew([
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
        ]);

        if ($state->exists && $state->trashed()) {
            $state->restore();
        }

        $state->fill($data);
        $state->save();

        return $state->fresh();
    }

    private function upsertPowerInteraction(string $name, array $data): PowerInteraction
    {
        if (
            isset($data['system_a_entity_id'], $data['system_b_entity_id'])
            && $data['system_a_entity_id'] > $data['system_b_entity_id']
        ) {
            [$data['system_a_entity_id'], $data['system_b_entity_id']] = [
                $data['system_b_entity_id'],
                $data['system_a_entity_id'],
            ];
        }

        $lookup = isset($data['system_a_entity_id'], $data['system_b_entity_id'])
            ? [
                'system_a_entity_id' => $data['system_a_entity_id'],
                'system_b_entity_id' => $data['system_b_entity_id'],
            ]
            : ['interaction_name' => $name];

        $interaction = PowerInteraction::withTrashed()->firstOrNew($lookup);

        if ($interaction->exists && $interaction->trashed()) {
            $interaction->restore();
        }

        $interaction->fill(array_merge(['interaction_name' => $name], $data));
        $interaction->save();

        if ($interaction->shouldBeUnresolved() && ! $interaction->unresolved_flag) {
            $interaction->update(['unresolved_flag' => true]);
        }

        return $interaction->fresh();
    }

    private function upsertPowerInteractionInstance(PowerInteraction $interaction, Entity $eventEntity, array $data): PowerInteractionInstance
    {
        $instance = PowerInteractionInstance::query()->firstOrNew([
            'power_interaction_id' => $interaction->id,
            'event_entity_id' => $eventEntity->id,
        ]);

        $instance->fill($data);
        $instance->save();

        if ($instance->contradicts() && ! $interaction->fresh()->unresolved_flag) {
            $interaction->update(['unresolved_flag' => true]);
        }

        return $instance->fresh();
    }

    private function upsertLocationContainment(Entity $child, Entity $parent, array $data): LocationContainment
    {
        $containment = LocationContainment::withTrashed()->firstOrNew([
            'child_location_entity_id' => $child->id,
            'parent_location_entity_id' => $parent->id,
            'containment_type' => $data['containment_type'],
        ]);

        if ($containment->exists && $containment->trashed()) {
            $containment->restore();
        }

        $containment->fill($data);
        $containment->save();

        return $containment->fresh();
    }

    private function upsertLocationControl(Entity $location, Entity $controller, array $data): LocationControlHistory
    {
        $control = LocationControlHistory::withTrashed()->firstOrNew([
            'location_entity_id' => $location->id,
            'controlling_entity_id' => $controller->id,
            'control_start_era' => $data['control_start_era'],
        ]);

        if ($control->exists && $control->trashed()) {
            $control->restore();
        }

        $control->fill(array_merge(['control_type' => $data['control_type']], $data));
        $control->save();

        return $control->fresh();
    }

    private function upsertTravelRoute(Entity $origin, Entity $destination, array $data): TravelRoute
    {
        $route = TravelRoute::withTrashed()->firstOrNew([
            'origin_location_entity_id' => $origin->id,
            'destination_location_entity_id' => $destination->id,
            'route_type' => $data['route_type'],
        ]);

        if ($route->exists && $route->trashed()) {
            $route->restore();
        }

        $route->fill($data);
        $route->save();

        return $route->fresh();
    }

    private function upsertMeta(string $title, array $data): Meta
    {
        $meta = Meta::withTrashed()->firstOrNew(['title' => $title]);

        if ($meta->exists && $meta->trashed()) {
            $meta->restore();
        }

        $meta->fill($data);
        $meta->save();

        return $meta->fresh();
    }

    private function upsertSessionLog(string $title, array $data): SessionLog
    {
        $log = SessionLog::withTrashed()->firstOrNew(['title' => $title]);

        if ($log->exists && $log->trashed()) {
            $log->restore();
        }

        $log->fill($data);
        $log->save();

        return $log->fresh();
    }

    private function upsertPipelineItem(string $title, array $data): PipelineItem
    {
        $item = PipelineItem::withTrashed()->firstOrNew(['title' => $title]);

        if ($item->exists && $item->trashed()) {
            $item->restore();
        }

        $item->fill($data);
        $item->save();

        return $item->fresh();
    }

    private function upsertEntityNote(Entity $entity, string $label, string $content, int $sortOrder): EntityNote
    {
        $note = EntityNote::withTrashed()->firstOrNew([
            'entity_id' => $entity->id,
            'note_label' => $label,
        ]);

        if ($note->exists && $note->trashed()) {
            $note->restore();
        }

        $note->fill([
            'content' => $content,
            'sort_order' => $sortOrder,
        ]);
        $note->save();

        return $note->fresh();
    }

    private function upsertEntityQuestion(Entity $entity, string $question, array $data): EntityQuestion
    {
        $row = EntityQuestion::withTrashed()->firstOrNew([
            'entity_id' => $entity->id,
            'question' => $question,
        ]);

        if ($row->exists && $row->trashed()) {
            $row->restore();
        }

        $row->fill($data);
        $row->save();

        return $row->fresh();
    }

    private function doc(string ...$paragraphs): array
    {
        $content = [];

        foreach ($paragraphs as $paragraph) {
            $text = trim($paragraph);

            if ($text === '') {
                continue;
            }

            $content[] = [
                'type' => 'paragraph',
                'content' => [[
                    'type' => 'text',
                    'text' => $text,
                ]],
            ];
        }

        return [
            'type' => 'doc',
            'content' => $content,
        ];
    }
}
