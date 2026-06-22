<?php

namespace App\Domain\System\Services;

use App\Domain\Identity\Models\Entity;
use App\Domain\Identity\Models\EntityAlias;
use App\Domain\Identity\Models\EntityNote;
use App\Domain\Identity\Models\EntityQuestion;
use App\Domain\Identity\Services\EntityService;
use App\Domain\Identity\Listeners\FlipEntityCompletionFlags;
use App\Domain\Identity\Listeners\UpdateCompletionScore;
use App\Domain\Identity\ValueObjects\ContentClassification;
use App\Domain\Identity\ValueObjects\VisibilityLevel;
use App\Domain\System\Models\NotionSyncMapping;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;
use Throwable;

class NotionIdentitySyncService
{
    public const RESOURCE_IDENTITY = 'identity';
    public const RESOURCE_ENTITIES = 'entities';
    public const RESOURCE_ENTITY_ALIASES = 'entity_aliases';
    public const RESOURCE_ENTITY_NOTES = 'entity_notes';
    public const RESOURCE_ENTITY_QUESTIONS = 'entity_questions';

    public function __construct(
        private readonly NotionClient $client,
        private readonly NotionPropertyMapper $mapper,
        private readonly NotionNoteSyncService $notionNoteSync,
        private readonly EntityService $entityService,
        private readonly FlipEntityCompletionFlags $flagFlipper,
        private readonly UpdateCompletionScore $completionScoreUpdater,
    ) {}

    public static function supportedResources(): array
    {
        return [
            self::RESOURCE_IDENTITY,
            self::RESOURCE_ENTITIES,
            self::RESOURCE_ENTITY_ALIASES,
            self::RESOURCE_ENTITY_NOTES,
            self::RESOURCE_ENTITY_QUESTIONS,
        ];
    }

    public function sync(string $resource = self::RESOURCE_IDENTITY, bool $includeDrafts = false, bool $dryRun = false): array
    {
        if (! in_array($resource, self::supportedResources(), true)) {
            throw new RuntimeException("Unsupported resource [{$resource}].");
        }

        if (! $this->client->isConfigured()) {
            throw new RuntimeException('NOTION_API_TOKEN is not configured.');
        }

        return match ($resource) {
            self::RESOURCE_ENTITIES => $this->syncEntities($includeDrafts, $dryRun),
            self::RESOURCE_ENTITY_ALIASES => $this->syncAliases($includeDrafts, $dryRun),
            self::RESOURCE_ENTITY_NOTES => $this->syncNotes($includeDrafts, $dryRun),
            self::RESOURCE_ENTITY_QUESTIONS => $this->syncQuestions($includeDrafts, $dryRun),
            default => $this->syncIdentity($includeDrafts, $dryRun),
        };
    }

    private function syncIdentity(bool $includeDrafts, bool $dryRun): array
    {
        $overall = $this->emptyStats();

        foreach ([
            self::RESOURCE_ENTITIES,
            self::RESOURCE_ENTITY_ALIASES,
            self::RESOURCE_ENTITY_NOTES,
            self::RESOURCE_ENTITY_QUESTIONS,
        ] as $resource) {
            $stats = $this->sync($resource, $includeDrafts, $dryRun);
            $overall['resources'][$resource] = $stats;
            $overall['created'] += $stats['created'];
            $overall['updated'] += $stats['updated'];
            $overall['skipped'] += $stats['skipped'];
            $overall['warnings'] = array_merge($overall['warnings'], $stats['warnings']);
        }

        return $overall;
    }

    private function syncEntities(bool $includeDrafts, bool $dryRun): array
    {
        $stats = $this->emptyStats();
        $databaseId = $this->databaseIdFor(self::RESOURCE_ENTITIES);

        foreach ($this->client->queryDatabase($databaseId) as $page) {
            $pageId = $this->mapper->pageId($page);
            $syncState = $this->normalizeSyncState($page);

            if (! $this->shouldImportState($syncState, $includeDrafts)) {
                $stats['skipped']++;
                continue;
            }

            $mapping = $this->mappingFor(self::RESOURCE_ENTITIES, $pageId);
            $entity = $this->resolveEntity($mapping, $page);
            $data = $this->entityPayload($page, $entity);
            $hash = $this->payloadHash($this->entityHashPayload($data));
            $shouldSyncNote = ! $dryRun && $entity && $this->notionNoteSync->shouldSyncPageBody($page, $mapping);

            if ($entity && $mapping?->last_payload_hash === $hash) {
                $noteChanged = false;

                if (! $dryRun) {
                    if ($shouldSyncNote) {
                        $noteChanged = $this->syncNotionNote(self::RESOURCE_ENTITIES, $page, $entity, $stats);
                    }

                    $this->writeBack($page, (string) $entity->id);
                    $this->touchMapping($mapping, $databaseId, $entity::class, $entity->id, $page, $hash);
                }

                $stats[$noteChanged ? 'updated' : 'skipped']++;

                continue;
            }

            try {
                if ($dryRun) {
                    $stats[$entity ? 'updated' : 'created']++;
                    continue;
                }

                $entity = $entity
                    ? $this->entityService->update($entity, $data)
                    : $this->entityService->create($data);

                $this->storeMapping(self::RESOURCE_ENTITIES, $pageId, $databaseId, $entity::class, $entity->id, $page, $hash);
                $this->writeBack($page, (string) $entity->id);
                $this->syncNotionNote(self::RESOURCE_ENTITIES, $page, $entity, $stats);

                $stats[$mapping ? 'updated' : 'created']++;
            } catch (Throwable $e) {
                $stats['warnings'][] = "Entity sync failed for page {$pageId}: {$e->getMessage()}";
            }
        }

        return $stats;
    }

    private function syncAliases(bool $includeDrafts, bool $dryRun): array
    {
        $stats = $this->emptyStats();
        $databaseId = $this->databaseIdFor(self::RESOURCE_ENTITY_ALIASES);
        $touchedEntityIds = [];

        foreach ($this->client->queryDatabase($databaseId) as $index => $page) {
            $pageId = $this->mapper->pageId($page);
            $syncState = $this->normalizeSyncState($page);

            if (! $this->shouldImportState($syncState, $includeDrafts)) {
                $stats['skipped']++;
                continue;
            }

            $mapping = $this->mappingFor(self::RESOURCE_ENTITY_ALIASES, $pageId);
            $alias = $this->resolveAlias($mapping, $page);
            $shouldSyncNote = ! $dryRun && $alias && $this->notionNoteSync->shouldSyncPageBody($page, $mapping);

            $entity = $this->resolveMappedEntityFromRelation(
                $page,
                'Entity',
                $alias?->entity_id
            );

            if (! $entity) {
                $stats['warnings'][] = "Entity Alias page {$pageId} could not resolve its parent entity.";
                continue;
            }

            $data = [
                'entity_id' => $entity->id,
                'alias' => $this->mapper->title($page, 'Alias'),
                'alias_type' => $this->mapper->normalizeKey($this->mapper->selectOrRichText($page, 'Alias Type')) ?? 'name',
                'context' => $this->mapper->richText($page, 'Summary') ?? $this->mapper->richText($page, 'Notes'),
                'is_active' => true,
                'known_by_entity_ids' => [],
                'visibility' => VisibilityLevel::PRIVATE,
                'content_classification' => ContentClassification::RESTRICTED,
            ];

            if (blank($data['alias'])) {
                $stats['warnings'][] = "Entity Alias page {$pageId} is missing an alias title.";
                continue;
            }

            $hash = $this->payloadHash($data);

            if ($alias && $mapping?->last_payload_hash === $hash) {
                $noteChanged = false;

                if (! $dryRun) {
                    if ($shouldSyncNote) {
                        $noteChanged = $this->syncNotionNote(self::RESOURCE_ENTITY_ALIASES, $page, $alias, $stats);
                    }

                    $this->writeBack($page, (string) $alias->id);
                    $this->touchMapping($mapping, $databaseId, $alias::class, $alias->id, $page, $hash);
                }

                $stats[$noteChanged ? 'updated' : 'skipped']++;

                continue;
            }

            if ($dryRun) {
                $stats[$alias ? 'updated' : 'created']++;
                continue;
            }

            $alias = $alias
                ? tap($alias)->update($data)
                : EntityAlias::create($data);

            $this->storeMapping(self::RESOURCE_ENTITY_ALIASES, $pageId, $databaseId, $alias::class, $alias->id, $page, $hash);
            $this->writeBack($page, (string) $alias->id);
            $this->syncNotionNote(self::RESOURCE_ENTITY_ALIASES, $page, $alias, $stats);

            $touchedEntityIds[$entity->id] = $entity->id;
            $stats[$mapping ? 'updated' : 'created']++;
        }

        if (! $dryRun) {
            foreach ($touchedEntityIds as $entityId) {
                $entity = Entity::find($entityId);

                if ($entity) {
                    $this->flagFlipper->flipAliases($entity);
                    $this->completionScoreUpdater->recalculate($entity);
                }
            }
        }

        return $stats;
    }

    private function syncNotes(bool $includeDrafts, bool $dryRun): array
    {
        $stats = $this->emptyStats();
        $databaseId = $this->databaseIdFor(self::RESOURCE_ENTITY_NOTES);

        foreach ($this->client->queryDatabase($databaseId) as $index => $page) {
            $pageId = $this->mapper->pageId($page);
            $syncState = $this->normalizeSyncState($page);

            if (! $this->shouldImportState($syncState, $includeDrafts)) {
                $stats['skipped']++;
                continue;
            }

            $mapping = $this->mappingFor(self::RESOURCE_ENTITY_NOTES, $pageId);
            $note = $this->resolveNote($mapping, $page);
            $shouldSyncNote = ! $dryRun && $note && $this->notionNoteSync->shouldSyncPageBody($page, $mapping);
            $entity = $this->resolveMappedEntityFromRelation(
                $page,
                'Entity',
                $note?->entity_id
            );

            if (! $entity) {
                $stats['warnings'][] = "Entity Note page {$pageId} could not resolve its parent entity.";
                continue;
            }

            $data = [
                'entity_id' => $entity->id,
                'note_label' => $this->mapper->title($page, 'Note Title'),
                'content' => $this->mapper->richText($page, 'Content') ?? '',
                'sort_order' => $note?->sort_order ?? $index,
            ];

            $hash = $this->payloadHash($data);

            if ($note && $mapping?->last_payload_hash === $hash) {
                $noteChanged = false;

                if (! $dryRun) {
                    if ($shouldSyncNote) {
                        $noteChanged = $this->syncNotionNote(self::RESOURCE_ENTITY_NOTES, $page, $note, $stats);
                    }

                    $this->writeBack($page, (string) $note->id);
                    $this->touchMapping($mapping, $databaseId, $note::class, $note->id, $page, $hash);
                }

                $stats[$noteChanged ? 'updated' : 'skipped']++;

                continue;
            }

            if ($dryRun) {
                $stats[$note ? 'updated' : 'created']++;
                continue;
            }

            $note = $note
                ? tap($note)->update($data)
                : EntityNote::create($data);

            $this->storeMapping(self::RESOURCE_ENTITY_NOTES, $pageId, $databaseId, $note::class, $note->id, $page, $hash);
            $this->writeBack($page, (string) $note->id);
            $this->syncNotionNote(self::RESOURCE_ENTITY_NOTES, $page, $note, $stats);

            $stats[$mapping ? 'updated' : 'created']++;
        }

        return $stats;
    }

    private function syncQuestions(bool $includeDrafts, bool $dryRun): array
    {
        $stats = $this->emptyStats();
        $databaseId = $this->databaseIdFor(self::RESOURCE_ENTITY_QUESTIONS);

        foreach ($this->client->queryDatabase($databaseId) as $index => $page) {
            $pageId = $this->mapper->pageId($page);
            $syncState = $this->normalizeSyncState($page);

            if (! $this->shouldImportState($syncState, $includeDrafts)) {
                $stats['skipped']++;
                continue;
            }

            $mapping = $this->mappingFor(self::RESOURCE_ENTITY_QUESTIONS, $pageId);
            $question = $this->resolveQuestion($mapping, $page);
            $shouldSyncNote = ! $dryRun && $question && $this->notionNoteSync->shouldSyncPageBody($page, $mapping);
            $entity = $this->resolveMappedEntityFromRelation(
                $page,
                'Entity',
                $question?->entity_id
            );

            if (! $entity) {
                $stats['warnings'][] = "Entity Question page {$pageId} could not resolve its parent entity.";
                continue;
            }

            $status = $this->mapper->normalizeKey($this->mapper->selectOrRichText($page, 'Question Status')) ?? 'unresolved';
            $status = match ($status) {
                'open' => 'unresolved',
                default => $status,
            };

            if (! in_array($status, ['unresolved', 'in_progress', 'resolved', 'deferred'], true)) {
                $status = 'unresolved';
            }

            $resolution = $this->mapper->richText($page, 'Answer');

            $data = [
                'entity_id' => $entity->id,
                'question' => $this->mapper->title($page, 'Question'),
                'context' => $this->mapper->richText($page, 'Notes'),
                'status' => $status,
                'resolution' => $resolution,
                'resolved_at' => $status === 'resolved' && filled($resolution)
                    ? ($question?->resolved_at ?? now())
                    : null,
                'priority' => $question?->priority ?? 'medium',
                'linked_entity_ids' => [],
                'linked_group_relationship_ids' => [],
                'source_session_log_id' => null,
                'sort_order' => $question?->sort_order ?? $index,
            ];

            if (blank($data['question'])) {
                $stats['warnings'][] = "Entity Question page {$pageId} is missing question text.";
                continue;
            }

            $hash = $this->payloadHash($data);

            if ($question && $mapping?->last_payload_hash === $hash) {
                $noteChanged = false;

                if (! $dryRun) {
                    if ($shouldSyncNote) {
                        $noteChanged = $this->syncNotionNote(self::RESOURCE_ENTITY_QUESTIONS, $page, $question, $stats);
                    }

                    $this->writeBack($page, (string) $question->id);
                    $this->touchMapping($mapping, $databaseId, $question::class, $question->id, $page, $hash);
                }

                $stats[$noteChanged ? 'updated' : 'skipped']++;

                continue;
            }

            if ($dryRun) {
                $stats[$question ? 'updated' : 'created']++;
                continue;
            }

            $question = $question
                ? tap($question)->update($data)
                : EntityQuestion::create($data);

            $this->storeMapping(self::RESOURCE_ENTITY_QUESTIONS, $pageId, $databaseId, $question::class, $question->id, $page, $hash);
            $this->writeBack($page, (string) $question->id);
            $this->syncNotionNote(self::RESOURCE_ENTITY_QUESTIONS, $page, $question, $stats);

            $stats[$mapping ? 'updated' : 'created']++;
        }

        return $stats;
    }

    private function entityPayload(array $page, ?Entity $existingEntity = null): array
    {
        [$legacySourceUniverses, $legacyOriginType] = $this->mapper->parseUniverseOrigin(
            $this->mapper->richText($page, 'Universe / Origin')
        );

        $published = $this->mapper->checkbox($page, 'Published');
        $visibility = $this->mapper->normalizeKey($this->mapper->selectOrRichText($page, 'Visibility'));

        if (! in_array($visibility, [
            VisibilityLevel::PRIVATE,
            VisibilityLevel::AUTHOR_ONLY,
            VisibilityLevel::PUBLIC_KNOWLEDGE,
            VisibilityLevel::SECRET,
        ], true)) {
            $visibility = null;
        }

        $sourceUniverses = $this->mapper->multiSelect($page, 'Source Universes');
        $originType = $this->mapper->normalizeKey($this->mapper->selectOrRichText($page, 'Origin Type')) ?? $legacyOriginType;
        $isPublic = ($visibility ?? ($published ? VisibilityLevel::PUBLIC_KNOWLEDGE : VisibilityLevel::PRIVATE)) === VisibilityLevel::PUBLIC_KNOWLEDGE;

        return [
            'name' => $this->mapper->title($page, 'Entity Name'),
            'entity_type' => $this->mapper->normalizeKey($this->mapper->selectOrRichText($page, 'Entity Type')),
            'summary' => $this->mapper->richText($page, 'Summary'),
            'source_universes' => $sourceUniverses !== [] ? $sourceUniverses : $legacySourceUniverses,
            'origin_type' => $originType,
            'visibility' => $visibility ?? ($published ? VisibilityLevel::PUBLIC_KNOWLEDGE : VisibilityLevel::PRIVATE),
            'published_at' => $isPublic
                ? ($existingEntity?->published_at ?? now())
                : null,
            'content_classification' => ContentClassification::RESTRICTED,
        ];
    }

    private function entityHashPayload(array $data): array
    {
        return [
            ...$data,
            'published_at' => ! empty($data['published_at']),
        ];
    }

    private function resolveEntity(?NotionSyncMapping $mapping, array $page): ?Entity
    {
        if ($mapping && $mapping->local_model_type === Entity::class) {
            $entity = Entity::find($mapping->local_model_id);

            if ($entity) {
                return $entity;
            }
        }

        $siteRecordId = $this->siteRecordId($page);

        return $siteRecordId ? Entity::find($siteRecordId) : null;
    }

    private function resolveAlias(?NotionSyncMapping $mapping, array $page): ?EntityAlias
    {
        if ($mapping && $mapping->local_model_type === EntityAlias::class) {
            $alias = EntityAlias::find($mapping->local_model_id);

            if ($alias) {
                return $alias;
            }
        }

        $siteRecordId = $this->siteRecordId($page);

        return $siteRecordId ? EntityAlias::find($siteRecordId) : null;
    }

    private function resolveNote(?NotionSyncMapping $mapping, array $page): ?EntityNote
    {
        if ($mapping && $mapping->local_model_type === EntityNote::class) {
            $note = EntityNote::find($mapping->local_model_id);

            if ($note) {
                return $note;
            }
        }

        $siteRecordId = $this->siteRecordId($page);

        return $siteRecordId ? EntityNote::find($siteRecordId) : null;
    }

    private function resolveQuestion(?NotionSyncMapping $mapping, array $page): ?EntityQuestion
    {
        if ($mapping && $mapping->local_model_type === EntityQuestion::class) {
            $question = EntityQuestion::find($mapping->local_model_id);

            if ($question) {
                return $question;
            }
        }

        $siteRecordId = $this->siteRecordId($page);

        return $siteRecordId ? EntityQuestion::find($siteRecordId) : null;
    }

    private function resolveMappedEntityFromRelation(array $page, string $property, ?int $fallbackEntityId = null): ?Entity
    {
        $relationIds = $this->mapper->relationIds($page, $property);
        $notionEntityId = $relationIds[0] ?? null;

        if ($notionEntityId) {
            $mapping = $this->mappingFor(self::RESOURCE_ENTITIES, $notionEntityId);

            if ($mapping?->local_model_type === Entity::class) {
                return Entity::find($mapping->local_model_id);
            }
        }

        return $fallbackEntityId ? Entity::find($fallbackEntityId) : null;
    }

    private function shouldImportState(?string $syncState, bool $includeDrafts): bool
    {
        if ($syncState === 'archived') {
            return false;
        }

        if ($includeDrafts) {
            return true;
        }

        return in_array(
            $syncState ?? 'draft',
            config('notion.dataverse.syncable_states', ['ready', 'synced']),
            true
        );
    }

    private function normalizeSyncState(array $page): ?string
    {
        return $this->mapper->normalizeKey($this->mapper->select($page, 'Sync State'));
    }

    private function writeBack(array $page, string $localId): void
    {
        try {
            $pageId = $this->mapper->pageId($page);
            $siteRecordIdProperty = $this->mapper->propertyKey($page, 'Site Record ID') ?? 'Site Record ID';
            $syncStateProperty = $this->mapper->propertyKey($page, 'Sync State') ?? 'Sync State';
            $lastSyncedProperty = $this->mapper->propertyKey($page, 'Last Synced') ?? 'Last Synced';

            $this->client->updatePageProperties($pageId, [
                $siteRecordIdProperty => $this->client->richTextProperty($localId),
                $syncStateProperty => $this->client->selectProperty('synced'),
                $lastSyncedProperty => $this->client->dateProperty(now()),
            ]);
        } catch (Throwable) {
            // Local sync should still succeed if the integration can read but
            // cannot write back to the Notion page.
        }
    }

    private function storeMapping(
        string $resource,
        string $pageId,
        string $databaseId,
        string $localModelType,
        int $localModelId,
        array $page,
        string $payloadHash,
    ): NotionSyncMapping {
        return NotionSyncMapping::updateOrCreate(
            [
                'sync_resource' => $resource,
                'notion_page_id' => $pageId,
            ],
            [
                'notion_parent_database_id' => $databaseId,
                'local_model_type' => $localModelType,
                'local_model_id' => $localModelId,
                'notion_last_edited_at' => $this->mapper->lastEditedAt($page),
                'last_synced_at' => now(),
                'last_payload_hash' => $payloadHash,
            ]
        );
    }

    private function touchMapping(
        ?NotionSyncMapping $mapping,
        string $databaseId,
        string $localModelType,
        int $localModelId,
        array $page,
        string $payloadHash,
    ): void {
        if (! $mapping) {
            return;
        }

        $mapping->update([
            'notion_parent_database_id' => $databaseId,
            'local_model_type' => $localModelType,
            'local_model_id' => $localModelId,
            'notion_last_edited_at' => $this->mapper->lastEditedAt($page),
            'last_synced_at' => now(),
            'last_payload_hash' => $payloadHash,
        ]);
    }

    private function mappingFor(string $resource, string $pageId): ?NotionSyncMapping
    {
        return NotionSyncMapping::query()
            ->forResource($resource)
            ->forNotionPage($pageId)
            ->first();
    }

    private function siteRecordId(array $page): ?int
    {
        $raw = $this->mapper->richText($page, 'Site Record ID');

        return is_numeric($raw) ? (int) $raw : null;
    }

    private function databaseIdFor(string $resource): string
    {
        $databaseId = config("notion.dataverse.resources.{$resource}");

        if (blank($databaseId)) {
            throw new RuntimeException("Missing Notion database id for [{$resource}].");
        }

        return (string) $databaseId;
    }

    private function payloadHash(array $data): string
    {
        return hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
    }

    private function syncNotionNote(string $resource, array $page, Model $model, array &$stats): bool
    {
        try {
            return $this->notionNoteSync->syncPageBody($resource, $page, $model);
        } catch (Throwable $e) {
            $stats['warnings'][] = "{$this->resourceLabel($resource)} page {$this->mapper->pageId($page)} notion notes sync failed: {$e->getMessage()}";

            return false;
        }
    }

    private function resourceLabel(string $resource): string
    {
        return str_replace('_', ' ', ucfirst($resource));
    }

    private function emptyStats(): array
    {
        return [
            'created' => 0,
            'updated' => 0,
            'skipped' => 0,
            'warnings' => [],
            'resources' => [],
        ];
    }
}
