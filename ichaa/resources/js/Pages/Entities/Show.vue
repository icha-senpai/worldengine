<template>
    <ScaffoldShowPage
        :title="entity.name"
        :subtitle="entitySubtitle"
        back-label="Entities"
        :back-href="route('entities.index')"
        :edit-href="route('entities.edit', entity.id)"
        :edit-preserve-scroll="true"
        :edit-preserve-state="true"
        :edit-drawer-open="Boolean(editDrawer)"
        :edit-close-href="route('entities.show', entity.id)"
        :destroy-href="route('entities.destroy', entity.id)"
        :destroy-confirm="entityDestroyConfirm"
        :badge="formatLabel(entity.entity_type)"
        :hero-meta="entityHeroMeta"
        :sections="[]"
    >
        <template #hero-actions>
            <AppButton
                :href="route('entities.versions.index', entity.id)"
                variant="ghost"
            >
                Versions
            </AppButton>
        </template>

        <!-- TABS -->
        <div class="border-b border-border mb-6">
            <nav class="flex items-end gap-0">
                <button
                    v-for="tab in tabs"
                    :key="tab.id"
                    @click="activeTab = tab.id"
                    class="tab-btn"
                    :class="{ 'tab-btn--active': activeTab === tab.id }"
                >
                    {{ tab.label }}
                </button>
            </nav>
        </div>

        <!-- TAB: IDENTITY -->
        <div v-if="activeTab === 'identity'" class="space-y-5">

            <!-- Completion bar -->
            <div class="flex items-center gap-3 p-3 bg-surface-2 border border-border rounded-md">
                <span class="text-muted-3 text-xs font-ui uppercase tracking-widest whitespace-nowrap">
                    Completion
                </span>
                <div class="flex-1 h-1.5 bg-surface rounded-full overflow-hidden">
                    <div
                        class="h-full rounded-full transition-all duration-500"
                        :class="completionBarClass(entity.completion_score)"
                        :style="{ width: entity.completion_score + '%' }"
                    />
                </div>
                <span class="text-muted-2 text-sm font-ui w-9 text-right">
                    {{ entity.completion_score }}%
                </span>
                <div class="flex items-center gap-2 ml-2">
                    <span v-if="entity.has_attributes"       class="accent-tag">attr</span>
                    <span v-if="entity.has_relationships"    class="accent-tag">rel</span>
                    <span v-if="entity.has_timeline_entries" class="accent-tag">time</span>
                    <span v-if="entity.has_aliases"          class="accent-tag">alias</span>
                    <span v-if="entity.has_media"            class="accent-tag">media</span>
                </div>
            </div>

            <!-- Core fields grid -->
            <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">

                <!-- Summary -->
                <div v-if="entity.summary" class="panel lg:col-span-2">
                    <h3 class="panel-label">Summary</h3>
                    <RichDocumentValue
                        v-if="isRichDocument(entity.summary)"
                        :content="entity.summary"
                    />
                    <p v-else class="prose-wrap text-muted-2 text-sm leading-relaxed">{{ entity.summary }}</p>
                </div>

                <!-- Public summary -->
                <div v-if="entity.public_summary" class="panel lg:col-span-2">
                    <h3 class="panel-label">Public Summary <span class="text-muted-3 normal-case font-normal">(visible persona)</span></h3>
                    <RichDocumentValue
                        v-if="isRichDocument(entity.public_summary)"
                        :content="entity.public_summary"
                    />
                    <p v-else class="prose-wrap text-muted-2 text-sm leading-relaxed">{{ entity.public_summary }}</p>
                </div>

                <!-- Classification -->
                <div class="panel">
                    <h3 class="panel-label">Classification</h3>
                    <div class="space-y-2">
                        <div class="flex items-start gap-2"><span class="field-label field-label--fixed">Type</span><span class="text-muted-2 text-sm">{{ formatLabel(entity.entity_type) }}</span></div>
                        <div v-if="entity.entity_sub_type" class="flex items-start gap-2"><span class="field-label field-label--fixed">Subtype</span><span class="text-muted-2 text-sm">{{ formatLabel(entity.entity_sub_type) }}</span></div>
                        <div class="flex items-start gap-2">
                            <span class="field-label field-label--fixed">Status</span>
                            <span class="status-badge status-badge--sm" :class="statusBadgeClass(entity.status)">
                                {{ formatLabel(entity.status) }}
                            </span>
                        </div>
                        <div v-if="entity.type_status" class="flex items-start gap-2"><span class="field-label field-label--fixed">Type Status</span><span class="text-muted-2 text-sm">{{ formatLabel(entity.type_status) }}</span></div>
                    </div>
                </div>

                <!-- Visibility & Access -->
                <div class="panel">
                    <h3 class="panel-label">Access</h3>
                    <div class="space-y-2">
                        <div class="flex items-start gap-2"><span class="field-label field-label--fixed">Visibility</span><span class="text-muted-2 text-sm">{{ formatLabel(entity.visibility) }}</span></div>
                        <div class="flex items-start gap-2"><span class="field-label field-label--fixed">Classification</span><span class="text-muted-2 text-sm">{{ formatLabel(entity.content_classification) }}</span></div>
                        <div v-if="entity.published_at" class="flex items-start gap-2"><span class="field-label field-label--fixed">Published</span><span class="text-muted-2 text-sm">{{ formatDate(entity.published_at) }}</span></div>
                    </div>
                </div>

                <!-- Origin -->
                <div class="panel">
                    <h3 class="panel-label">Origin</h3>
                    <div class="space-y-2">
                        <div class="flex items-start gap-2"><span class="field-label field-label--fixed">Origin Type</span><span class="text-muted-2 text-sm">{{ formatLabel(entity.origin_type) }}</span></div>
                        <div v-if="entity.canon_deviation" class="flex items-start gap-2"><span class="field-label field-label--fixed">Canon Deviation</span><span class="text-muted-2 text-sm">{{ formatLabel(entity.canon_deviation) }}</span></div>
                        <div v-if="entity.origin_notes" class="flex items-start gap-2">
                            <span class="field-label field-label--fixed">Notes</span>
                            <div class="min-w-0 flex-1">
                                <RichDocumentValue
                                    v-if="isRichDocument(entity.origin_notes)"
                                    :content="entity.origin_notes"
                                />
                                <span v-else class="text-muted-2 text-sm">{{ entity.origin_notes }}</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Source Universes -->
                <div class="panel">
                    <h3 class="panel-label">Source Universes</h3>
                    <div v-if="entity.source_universes && entity.source_universes.length" class="flex flex-wrap gap-1.5 mt-1">
                        <span
                            v-for="u in entity.source_universes"
                            :key="u"
                            class="universe-chip"
                        >
                            {{ u }}
                        </span>
                    </div>
                    <p v-else class="text-muted-3 text-sm font-ui">Native / Original</p>
                </div>

                <!-- Power Tiers — only if any are set -->
                <div v-if="hasPowerTiers" class="panel lg:col-span-2">
                    <h3 class="panel-label">Power Tiers</h3>
                    <div class="grid grid-cols-1 gap-4 mt-2 md:grid-cols-3">
                        <div v-if="entity.power_tier_ceiling" class="info-box">
                            <span class="info-box-label">Ceiling</span>
                            <span class="info-box-value">{{ formatLabel(entity.power_tier_ceiling) }}</span>
                        </div>
                        <div v-if="entity.power_tier_operating" class="info-box">
                            <span class="info-box-label">Operating</span>
                            <span class="info-box-value">{{ formatLabel(entity.power_tier_operating) }}</span>
                        </div>
                        <div v-if="entity.power_tier_influence" class="info-box">
                            <span class="info-box-label">Influence</span>
                            <span class="info-box-value">{{ formatLabel(entity.power_tier_influence) }}</span>
                        </div>
                    </div>
                </div>

                <!-- Control State — only if set -->
                <div v-if="entity.control_state" class="panel">
                    <h3 class="panel-label">Control</h3>
                    <div class="space-y-2">
                        <div class="flex items-start gap-2">
                            <span class="field-label field-label--fixed">Control State</span>
                            <span class="text-warn text-sm font-ui">{{ formatLabel(entity.control_state) }}</span>
                        </div>
                    </div>
                </div>

                <!-- Persona divergence — only if relevant -->
                <div v-if="entity.persona_divergence" class="panel">
                    <h3 class="panel-label">Persona Divergence</h3>
                    <p class="text-muted-2 text-sm font-ui">{{ formatLabel(entity.persona_divergence) }}</p>
                </div>

            </div>
        </div>

        <!-- TAB: ALIASES -->
        <div v-if="activeTab === 'aliases'" class="space-y-4">
            <div class="panel space-y-4">
                <div class="flex items-center justify-between gap-3">
                    <div>
                        <h3 class="panel-label mb-0!">Aliases</h3>
                        <p class="text-muted-3 text-sm font-ui mt-1">
                            Alternate names, titles, codenames, and identity layers tied to {{ entity.name }}.
                        </p>
                    </div>
                    <AppButton
                        :href="route('entities.aliases.create', { entity: entity.id, tab: 'aliases' })"
                        :preserve-scroll="true"
                        :preserve-state="true"
                        opens-drawer
                        variant="primary"
                    >
                        Add Alias
                    </AppButton>
                </div>

                <div v-if="entity.aliases && entity.aliases.length" class="space-y-3">
                <div v-for="a in entity.aliases" :key="a.id" class="record-card">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <div class="flex items-center gap-2">
                                <span class="text-primary text-sm">{{ a.alias }}</span>
                                <span class="alias-type-chip">{{ formatEntityAliasType(a.alias_type) }}</span>
                                <span v-if="!a.is_active" class="text-muted-3 text-xs font-ui">(inactive)</span>
                            </div>
                            <p v-if="a.context" class="text-muted-3 text-sm mt-1">{{ a.context }}</p>
                            <div class="mt-2 flex flex-wrap gap-1.5">
                                <span class="accent-tag">{{ formatLabel(a.visibility || 'private') }}</span>
                                <span class="accent-tag">{{ formatLabel(a.content_classification || 'restricted') }}</span>
                                <span class="accent-tag">{{ a.known_by_entities_display?.length ? 'restricted audience' : 'publicly known' }}</span>
                            </div>
                            <p v-if="a.era_start || a.era_end" class="text-muted-3 text-xs font-ui mt-1">
                                {{ a.era_start || '?' }} → {{ a.era_end || 'present' }}
                            </p>
                            <div v-if="a.known_by_entities_display?.length" class="mt-2 flex flex-wrap items-center gap-2">
                                <span class="field-label field-label--fixed">Known By</span>
                                <Link
                                    v-for="knownBy in a.known_by_entities_display"
                                    :key="knownBy.id"
                                    :href="route('entities.show', knownBy.id)"
                                    class="accent-tag accent-tag--interactive"
                                >
                                    {{ knownBy.name }}
                                </Link>
                            </div>
                            <NotionNotePanel :note="a.notion_note" class="mt-3" />
                        </div>
                        <div class="flex shrink-0 items-center gap-2">
                            <AppButton
                                :href="route('entities.aliases.edit', { entity: entity.id, alias: a.id, tab: 'aliases' })"
                                :preserve-scroll="true"
                                :preserve-state="true"
                                opens-drawer
                                variant="ghost"
                                size="sm"
                            >
                                Edit
                            </AppButton>
                        </div>
                    </div>
                </div>
                </div>
                <div v-else class="empty-state">No aliases recorded.</div>
            </div>

        </div>

        <!-- TAB: NOTES -->
        <div v-if="activeTab === 'notes'" class="space-y-4">
            <div class="panel space-y-4">
                <div class="flex items-center justify-between gap-3">
                    <div>
                        <h3 class="panel-label mb-0!">Notes</h3>
                        <p class="text-muted-3 text-sm font-ui mt-1">
                            Private author notes, reminders, and structural guidance for {{ entity.name }}.
                        </p>
                    </div>
                    <AppButton
                        :href="route('entities.notes.create', { entity: entity.id, tab: 'notes' })"
                        :preserve-scroll="true"
                        :preserve-state="true"
                        opens-drawer
                        variant="primary"
                    >
                        Add Note
                    </AppButton>
                </div>

                <div v-if="entity.notes && entity.notes.length" class="space-y-3">
                <div v-for="n in entity.notes" :key="n.id" class="record-card">
                    <div class="flex items-start justify-between gap-3 mb-2">
                        <span v-if="n.note_label" class="note-label">{{ n.note_label }}</span>
                        <span v-else class="note-label note-label--empty">unlabeled</span>
                        <div class="flex shrink-0 items-center gap-2">
                            <span class="text-muted-3 text-xs font-ui">{{ formatDate(n.created_at) }}</span>
                            <AppButton
                                :href="route('entities.notes.edit', { entity: entity.id, note: n.id, tab: 'notes' })"
                                :preserve-scroll="true"
                                :preserve-state="true"
                                opens-drawer
                                variant="ghost"
                                size="sm"
                            >
                                Edit
                            </AppButton>
                        </div>
                    </div>
                    <p class="prose-wrap text-muted-2 text-sm leading-relaxed">{{ n.content }}</p>
                    <NotionNotePanel :note="n.notion_note" class="mt-3" />
                </div>
                </div>
                <div v-else class="empty-state">No notes recorded.</div>
            </div>

        </div>

        <!-- TAB: QUESTIONS -->
        <div v-if="activeTab === 'questions'" class="space-y-4">
            <div class="panel space-y-4">
                <div class="flex items-center justify-between gap-3">
                    <div>
                        <h3 class="panel-label mb-0!">Questions</h3>
                        <p class="text-muted-3 text-sm font-ui mt-1">
                            Open issues, unresolved lore, and blockers still attached to {{ entity.name }}.
                        </p>
                    </div>
                    <AppButton
                        :href="route('entities.questions.create', { entity: entity.id, tab: 'questions' })"
                        :preserve-scroll="true"
                        :preserve-state="true"
                        opens-drawer
                        variant="primary"
                    >
                        Add Question
                    </AppButton>
                </div>

                <div v-if="entity.questions && entity.questions.length" class="space-y-3">
                <div
                    v-for="q in entity.questions"
                    :key="q.id"
                    class="record-card"
                    :class="{ 'is-blocking': q.priority === 'blocking', 'is-dimmed': q.status === 'resolved' }"
                >
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-2 mb-1">
                                <span class="priority-chip" :class="'priority--' + q.priority">{{ q.priority }}</span>
                                <span class="status-q-chip" :class="'qstatus--' + q.status">{{ formatLabel(q.status) }}</span>
                            </div>
                            <p class="text-primary text-sm leading-snug">{{ q.question }}</p>
                            <p v-if="q.context" class="prose-wrap text-muted-3 text-sm mt-1">{{ q.context }}</p>
                            <p v-if="q.resolution" class="prose-wrap text-muted-2 text-sm mt-2 italic">
                                ↳ {{ q.resolution }}
                            </p>
                            <div
                                v-if="q.linked_entities_display?.length || q.linked_group_relationships_display?.length"
                                class="mt-3 space-y-2"
                            >
                                <div v-if="q.linked_entities_display?.length" class="flex flex-wrap items-center gap-2">
                                    <span class="field-label field-label--fixed">Entities</span>
                                    <Link
                                        v-for="linkedEntity in q.linked_entities_display"
                                        :key="`question-entity-${q.id}-${linkedEntity.id}`"
                                        :href="route('entities.show', linkedEntity.id)"
                                        class="accent-tag accent-tag--interactive"
                                    >
                                        {{ linkedEntity.name }}
                                    </Link>
                                </div>
                                <div v-if="q.linked_group_relationships_display?.length" class="flex flex-wrap items-center gap-2">
                                    <span class="field-label field-label--fixed">Groups</span>
                                    <Link
                                        v-for="linkedGroup in q.linked_group_relationships_display"
                                        :key="`question-group-${q.id}-${linkedGroup.id}`"
                                        :href="route('group-relationships.show', linkedGroup.id)"
                                        class="accent-tag accent-tag--interactive"
                                    >
                                        {{ linkedGroup.name }}
                                    </Link>
                                </div>
                            </div>
                            <NotionNotePanel :note="q.notion_note" class="mt-3" />
                        </div>
                        <div class="flex shrink-0 flex-col items-end gap-1.5">
                            <AppButton
                                :href="route('entities.questions.edit', { entity: entity.id, question: q.id, tab: 'questions' })"
                                :preserve-scroll="true"
                                :preserve-state="true"
                                opens-drawer
                                variant="ghost"
                                size="sm"
                            >
                                Edit
                            </AppButton>
                            <AppButton
                                v-if="q.status !== 'resolved'"
                                type="button"
                                @click="resolveQuestion(q.id)"
                                variant="success"
                                size="sm"
                            >Resolve</AppButton>
                        </div>
                    </div>
                </div>
                </div>
                <div v-else class="empty-state">No questions recorded.</div>
            </div>

        </div>

        <!-- TAB: MEMBERSHIPS -->
        <div v-if="activeTab === 'memberships'" class="space-y-4">
            <div v-if="isFactionEntity" class="panel space-y-4">
                <div class="flex items-center justify-between gap-3">
                    <div>
                        <h3 class="panel-label mb-0!">Faction Roster</h3>
                        <p class="text-muted-3 text-sm font-ui mt-1">
                            Current and former members tied to {{ entity.name }}.
                        </p>
                    </div>
                    <AppButton
                        :href="route('faction-memberships.create', { faction_entity_id: entity.id, return_context: 'faction', return_entity_id: entity.id, tab: 'memberships' })"
                        :preserve-scroll="true"
                        :preserve-state="true"
                        opens-drawer
                        variant="primary"
                    >
                        Add Member
                    </AppButton>
                </div>

                <div v-if="factionRoster.length" class="space-y-3">
                    <div v-for="membership in factionRoster" :key="membership.id" class="record-card">
                        <div class="flex items-start justify-between gap-4">
                            <div class="min-w-0 flex-1 space-y-2">
                                <div class="flex items-center gap-2 flex-wrap">
                                    <Link
                                        v-if="membership.member"
                                        :href="route('entities.show', membership.member.id)"
                                        class="text-primary text-sm hover:text-cyan transition-colors"
                                    >
                                        {{ membership.member.name }}
                                    </Link>
                                    <span v-else class="text-primary text-sm">Unknown member</span>
                                    <span class="alias-type-chip">{{ formatLabel(membership.membership_status || 'unknown') }}</span>
                                    <span v-if="membership.rank_or_role" class="accent-tag">{{ membership.rank_or_role }}</span>
                                    <span v-if="membership.is_undercover" class="accent-tag">undercover</span>
                                    <span v-if="!membership.public_membership_known" class="accent-tag">hidden</span>
                                    <span v-if="membership.true_loyalty_entity_id && membership.true_loyalty_entity_id !== entity.id" class="accent-tag">split loyalty</span>
                                </div>

                                <p v-if="membership.member?.public_title" class="text-muted-3 text-sm italic">
                                    "{{ membership.member.public_title }}"
                                </p>

                                <div class="grid gap-2 md:grid-cols-2">
                                    <div v-if="membership.joined_era" class="text-muted-2 text-sm">
                                        <span class="field-label field-label--fixed">Joined</span>
                                        <span>{{ membership.joined_era }}</span>
                                    </div>
                                    <div v-if="membership.left_era" class="text-muted-2 text-sm">
                                        <span class="field-label field-label--fixed">Left</span>
                                        <span>{{ membership.left_era }}</span>
                                    </div>
                                    <div v-if="membership.recruited_by?.name" class="text-muted-2 text-sm">
                                        <span class="field-label field-label--fixed">Recruited By</span>
                                        <span>{{ membership.recruited_by.name }}</span>
                                    </div>
                                    <div v-if="membership.true_loyalty?.name" class="text-muted-2 text-sm md:col-span-2">
                                        <span class="field-label field-label--fixed">True Loyalty</span>
                                        <span>{{ membership.true_loyalty.name }}</span>
                                    </div>
                                </div>

                                <div v-if="membership.departure_reason || membership.notes" class="space-y-2">
                                    <div v-if="membership.departure_reason" class="rounded-md border border-border bg-surface-2 p-3">
                                        <span class="field-label">Departure Reason</span>
                                        <RichDocumentValue
                                            v-if="isRichDocument(membership.departure_reason)"
                                            :content="membership.departure_reason"
                                        />
                                    </div>
                                    <div v-if="membership.notes" class="rounded-md border border-border bg-surface-2 p-3">
                                        <span class="field-label">Notes</span>
                                        <RichDocumentValue
                                            v-if="isRichDocument(membership.notes)"
                                            :content="membership.notes"
                                        />
                                    </div>
                                </div>
                            </div>

                            <div class="flex shrink-0 items-center gap-2">
                                <AppButton
                                    :href="route('faction-memberships.edit', { faction_membership: membership.id, return_context: 'faction', return_entity_id: entity.id, tab: 'memberships' })"
                                    :preserve-scroll="true"
                                    :preserve-state="true"
                                    opens-drawer
                                    variant="ghost"
                                    size="sm"
                                >
                                    Edit
                                </AppButton>
                            </div>
                        </div>
                    </div>
                </div>
                <div v-else class="empty-state">No members recorded yet.</div>
            </div>

            <div class="panel space-y-4">
                <div class="flex items-center justify-between gap-3">
                    <div>
                        <h3 class="panel-label mb-0!">Affiliations</h3>
                        <p class="text-muted-3 text-sm font-ui mt-1">
                            Factions, organizations, and movements this entity belongs to.
                        </p>
                    </div>
                    <AppButton
                        :href="route('faction-memberships.create', { member_entity_id: entity.id, return_context: 'member', return_entity_id: entity.id, tab: 'memberships' })"
                        :preserve-scroll="true"
                        :preserve-state="true"
                        opens-drawer
                        variant="primary"
                    >
                        Add Affiliation
                    </AppButton>
                </div>

                <div v-if="memberMemberships.length" class="space-y-3">
                    <div v-for="membership in memberMemberships" :key="membership.id" class="record-card">
                        <div class="flex items-start justify-between gap-4">
                            <div class="min-w-0 flex-1 space-y-2">
                                <div class="flex items-center gap-2 flex-wrap">
                                    <Link
                                        v-if="membership.faction"
                                        :href="route('entities.show', membership.faction.id)"
                                        class="text-primary text-sm hover:text-cyan transition-colors"
                                    >
                                        {{ membership.faction.name }}
                                    </Link>
                                    <span v-else class="text-primary text-sm">Unknown faction</span>
                                    <span class="alias-type-chip">{{ formatLabel(membership.membership_status || 'unknown') }}</span>
                                    <span v-if="membership.rank_or_role" class="accent-tag">{{ membership.rank_or_role }}</span>
                                    <span v-if="membership.is_undercover" class="accent-tag">undercover</span>
                                    <span v-if="!membership.public_membership_known" class="accent-tag">hidden</span>
                                </div>

                                <div class="grid gap-2 md:grid-cols-2">
                                    <div v-if="membership.joined_era" class="text-muted-2 text-sm">
                                        <span class="field-label field-label--fixed">Joined</span>
                                        <span>{{ membership.joined_era }}</span>
                                    </div>
                                    <div v-if="membership.left_era" class="text-muted-2 text-sm">
                                        <span class="field-label field-label--fixed">Left</span>
                                        <span>{{ membership.left_era }}</span>
                                    </div>
                                    <div v-if="membership.recruited_by?.name" class="text-muted-2 text-sm">
                                        <span class="field-label field-label--fixed">Recruited By</span>
                                        <span>{{ membership.recruited_by.name }}</span>
                                    </div>
                                    <div v-if="membership.true_loyalty?.name" class="text-muted-2 text-sm md:col-span-2">
                                        <span class="field-label field-label--fixed">True Loyalty</span>
                                        <span>{{ membership.true_loyalty.name }}</span>
                                    </div>
                                </div>

                                <div v-if="membership.departure_reason || membership.notes" class="space-y-2">
                                    <div v-if="membership.departure_reason" class="rounded-md border border-border bg-surface-2 p-3">
                                        <span class="field-label">Departure Reason</span>
                                        <RichDocumentValue
                                            v-if="isRichDocument(membership.departure_reason)"
                                            :content="membership.departure_reason"
                                        />
                                    </div>
                                    <div v-if="membership.notes" class="rounded-md border border-border bg-surface-2 p-3">
                                        <span class="field-label">Notes</span>
                                        <RichDocumentValue
                                            v-if="isRichDocument(membership.notes)"
                                            :content="membership.notes"
                                        />
                                    </div>
                                </div>
                            </div>

                            <div v-if="membership.faction?.id" class="flex shrink-0 items-center gap-2">
                                <AppButton
                                    :href="route('faction-memberships.edit', { faction_membership: membership.id, return_context: 'member', return_entity_id: entity.id, tab: 'memberships' })"
                                    :preserve-scroll="true"
                                    :preserve-state="true"
                                    opens-drawer
                                    variant="ghost"
                                    size="sm"
                                >
                                    Edit
                                </AppButton>
                            </div>
                        </div>
                    </div>
                </div>
                <div v-else class="empty-state">No affiliations recorded yet.</div>
            </div>
        </div>

        <!-- TAB: INTELLIGENCE -->
        <div v-if="activeTab === 'intelligence'" class="space-y-4">
            <div class="panel">
                <h3 class="panel-label">Intelligence</h3>
                <p class="text-muted-3 text-sm font-ui">
                    Knowledge states and secrets involving this entity are managed from the
                    <Link :href="route('knowledge-states.index')" class="text-cyan hover:underline">Knowledge States</Link>
                    and
                    <Link :href="route('secrets.index')" class="text-cyan hover:underline">Secrets</Link>
                    domains. This tab will display a read-only summary once those pages are built.
                </p>
            </div>
        </div>

        <DrawerRouteShell
            v-if="showFactionMembershipEditDrawer"
            :open="showFactionMembershipEditDrawer"
            :ready="Boolean(factionMembershipEditDrawer)"
            title="Edit Faction Membership"
            :close-href="membershipsCloseHref"
            back-label="Entity"
            :back-href="route('entities.show', entity.id)"
        >
            <EditFactionMembership
                v-if="factionMembershipEditDrawer"
                embedded
                v-bind="factionMembershipEditDrawer"
            />
        </DrawerRouteShell>

        <DrawerRouteShell
            v-if="showFactionMembershipCreateDrawer"
            :open="showFactionMembershipCreateDrawer"
            :ready="Boolean(factionMembershipCreateDrawer)"
            title="New Faction Membership"
            :close-href="membershipsCloseHref"
            back-label="Entity"
            :back-href="route('entities.show', entity.id)"
        >
            <CreateFactionMembership
                v-if="factionMembershipCreateDrawer"
                embedded
                v-bind="factionMembershipCreateDrawer"
            />
        </DrawerRouteShell>

        <DrawerRouteShell
            v-if="showAliasEditDrawer"
            :open="showAliasEditDrawer"
            :ready="Boolean(aliasEditDrawer)"
            title="Edit Alias"
            :close-href="aliasesCloseHref"
            back-label="Entity"
            :back-href="route('entities.show', entity.id)"
        >
            <EditAlias
                v-if="aliasEditDrawer"
                embedded
                :entity="entity"
                :alias="aliasEditDrawer.alias"
                :entities="entities"
            />
        </DrawerRouteShell>

        <DrawerRouteShell
            v-if="showAliasCreateDrawer"
            :open="showAliasCreateDrawer"
            :ready="Boolean(aliasCreateDrawer)"
            title="New Alias"
            :close-href="aliasesCloseHref"
            back-label="Entity"
            :back-href="route('entities.show', entity.id)"
        >
            <CreateAlias
                v-if="aliasCreateDrawer"
                embedded
                :entity="entity"
                :entities="entities"
            />
        </DrawerRouteShell>

        <DrawerRouteShell
            v-if="showNoteEditDrawer"
            :open="showNoteEditDrawer"
            :ready="Boolean(noteEditDrawer)"
            title="Edit Note"
            :close-href="notesCloseHref"
            back-label="Entity"
            :back-href="route('entities.show', entity.id)"
        >
            <EditNote
                v-if="noteEditDrawer"
                embedded
                :entity="entity"
                :note="noteEditDrawer.note"
            />
        </DrawerRouteShell>

        <DrawerRouteShell
            v-if="showNoteCreateDrawer"
            :open="showNoteCreateDrawer"
            :ready="Boolean(noteCreateDrawer)"
            title="New Note"
            :close-href="notesCloseHref"
            back-label="Entity"
            :back-href="route('entities.show', entity.id)"
        >
            <CreateNote
                v-if="noteCreateDrawer"
                embedded
                :entity="entity"
            />
        </DrawerRouteShell>

        <DrawerRouteShell
            v-if="showQuestionEditDrawer"
            :open="showQuestionEditDrawer"
            :ready="Boolean(questionEditDrawer)"
            title="Edit Question"
            :close-href="questionsCloseHref"
            back-label="Entity"
            :back-href="route('entities.show', entity.id)"
        >
            <EditQuestion
                v-if="questionEditDrawer"
                embedded
                :entity="entity"
                :question="questionEditDrawer.question"
                :entities="entities"
                :group-relationships="groupRelationships"
            />
        </DrawerRouteShell>

        <DrawerRouteShell
            v-if="showQuestionCreateDrawer"
            :open="showQuestionCreateDrawer"
            :ready="Boolean(questionCreateDrawer)"
            title="New Question"
            :close-href="questionsCloseHref"
            back-label="Entity"
            :back-href="route('entities.show', entity.id)"
        >
            <CreateQuestion
                v-if="questionCreateDrawer"
                embedded
                :entity="entity"
                :entities="entities"
                :group-relationships="groupRelationships"
            />
        </DrawerRouteShell>

        <template #edit-drawer>
            <EditEntity
                v-if="editDrawer"
                embedded
                :entity="entity"
                :entity-types="editDrawer.entityTypes"
            />
        </template>
    </ScaffoldShowPage>
</template>

<script setup>
import { ref, computed, watch, defineAsyncComponent } from 'vue'
import { Link, router, usePage } from '@inertiajs/vue3'
import NotionNotePanel from '@/Components/NotionNotePanel.vue'
import AppButton from '@/Components/ui/AppButton.vue'
import DrawerRouteShell from '@/Components/ui/DrawerRouteShell.vue'
import CreateAlias from '@/Pages/Entities/Aliases/Create.vue'
import EditAlias from '@/Pages/Entities/Aliases/Edit.vue'
import CreateFactionMembership from '@/Pages/FactionMemberships/Create.vue'
import CreateNote from '@/Pages/Entities/Notes/Create.vue'
import CreateQuestion from '@/Pages/Entities/Questions/Create.vue'
import EditEntity from '@/Pages/Entities/Edit.vue'
import EditFactionMembership from '@/Pages/FactionMemberships/Edit.vue'
import EditNote from '@/Pages/Entities/Notes/Edit.vue'
import EditQuestion from '@/Pages/Entities/Questions/Edit.vue'
import ScaffoldShowPage from '@/Components/scaffold/ScaffoldShowPage.vue'
import { formatLabel, isRichDocument } from '@/Components/scaffold/formatters'
import { formatEntityAliasType } from '@/Pages/Entities/aliasTypes'
import { matchesPendingDrawerHref } from '@/lib/drawerNavigation'

// --- Props ---

const props = defineProps({
    entity: { type: Object, required: true },
    entities: { type: Array, default: () => [] },
    groupRelationships: { type: Array, default: () => [] },
    editDrawer: { type: Object, default: null },
    aliasCreateDrawer: { type: [Boolean, Object], default: null },
    aliasEditDrawer: { type: Object, default: null },
    factionMembershipEditDrawer: { type: Object, default: null },
    factionMembershipCreateDrawer: { type: Object, default: null },
    factionRoster: { type: Array, default: () => [] },
    memberMemberships: { type: Array, default: () => [] },
    isFactionEntity: { type: Boolean, default: false },
    noteCreateDrawer: { type: [Boolean, Object], default: null },
    noteEditDrawer: { type: Object, default: null },
    questionCreateDrawer: { type: [Boolean, Object], default: null },
    questionEditDrawer: { type: Object, default: null },
})

const page = usePage()
const RichDocumentValue = defineAsyncComponent(() => import('@/Components/scaffold/RichDocumentValue.vue'))
const aliasesCloseHref = computed(() =>
    route('entities.show', { entity: props.entity.id, tab: 'aliases' })
)
const membershipsCloseHref = computed(() =>
    route('entities.show', { entity: props.entity.id, tab: 'memberships' })
)
const notesCloseHref = computed(() =>
    route('entities.show', { entity: props.entity.id, tab: 'notes' })
)
const questionsCloseHref = computed(() =>
    route('entities.show', { entity: props.entity.id, tab: 'questions' })
)
const aliasEditRoutes = computed(() =>
    (props.entity.aliases ?? []).map((alias) => route('entities.aliases.edit', {
        entity: props.entity.id,
        alias: alias.id,
        tab: 'aliases',
    }))
)
const aliasCreateRoute = computed(() =>
    route('entities.aliases.create', {
        entity: props.entity.id,
        tab: 'aliases',
    })
)
const factionMembershipEditRoutes = computed(() => [
    ...props.factionRoster.map((membership) => route('faction-memberships.edit', {
        faction_membership: membership.id,
        return_context: 'faction',
        return_entity_id: props.entity.id,
        tab: 'memberships',
    })),
    ...props.memberMemberships.map((membership) => route('faction-memberships.edit', {
        faction_membership: membership.id,
        return_context: 'member',
        return_entity_id: props.entity.id,
        tab: 'memberships',
    })),
])
const factionMembershipCreateRoutes = computed(() => [
    route('faction-memberships.create', {
        faction_entity_id: props.entity.id,
        return_context: 'faction',
        return_entity_id: props.entity.id,
        tab: 'memberships',
    }),
    route('faction-memberships.create', {
        member_entity_id: props.entity.id,
        return_context: 'member',
        return_entity_id: props.entity.id,
        tab: 'memberships',
    }),
])
const showFactionMembershipEditDrawer = computed(() =>
    Boolean(props.factionMembershipEditDrawer)
    || factionMembershipEditRoutes.value.some((href) => matchesPendingDrawerHref(href))
)
const showFactionMembershipCreateDrawer = computed(() =>
    Boolean(props.factionMembershipCreateDrawer)
    || factionMembershipCreateRoutes.value.some((href) => matchesPendingDrawerHref(href))
)
const noteEditRoutes = computed(() =>
    (props.entity.notes ?? []).map((note) => route('entities.notes.edit', {
        entity: props.entity.id,
        note: note.id,
        tab: 'notes',
    }))
)
const noteCreateRoute = computed(() =>
    route('entities.notes.create', {
        entity: props.entity.id,
        tab: 'notes',
    })
)
const questionEditRoutes = computed(() =>
    (props.entity.questions ?? []).map((question) => route('entities.questions.edit', {
        entity: props.entity.id,
        question: question.id,
        tab: 'questions',
    }))
)
const questionCreateRoute = computed(() =>
    route('entities.questions.create', {
        entity: props.entity.id,
        tab: 'questions',
    })
)
const showAliasEditDrawer = computed(() =>
    Boolean(props.aliasEditDrawer)
    || aliasEditRoutes.value.some((href) => matchesPendingDrawerHref(href))
)
const showAliasCreateDrawer = computed(() =>
    Boolean(props.aliasCreateDrawer)
    || matchesPendingDrawerHref(aliasCreateRoute.value)
)
const showNoteEditDrawer = computed(() =>
    Boolean(props.noteEditDrawer)
    || noteEditRoutes.value.some((href) => matchesPendingDrawerHref(href))
)
const showNoteCreateDrawer = computed(() =>
    Boolean(props.noteCreateDrawer)
    || matchesPendingDrawerHref(noteCreateRoute.value)
)
const showQuestionEditDrawer = computed(() =>
    Boolean(props.questionEditDrawer)
    || questionEditRoutes.value.some((href) => matchesPendingDrawerHref(href))
)
const showQuestionCreateDrawer = computed(() =>
    Boolean(props.questionCreateDrawer)
    || matchesPendingDrawerHref(questionCreateRoute.value)
)

// --- Tabs ---

const tabs = computed(() => {
    const baseTabs = [
        { id: 'identity',     label: 'Identity'     },
        { id: 'aliases',      label: 'Aliases'      },
        { id: 'notes',        label: 'Notes'        },
        { id: 'questions',    label: 'Questions'    },
        { id: 'memberships',  label: 'Memberships'  },
    ]

    baseTabs.push({ id: 'intelligence', label: 'Intelligence' })

    return baseTabs
})

const activeTab = ref('identity')

const validTabs = computed(() => tabs.value.map((tab) => tab.id))

const urlParams = computed(() => {
    const [, queryString = ''] = page.url.split('?')

    return new URLSearchParams(queryString)
})

const resolveQuestion = (questionId) => {
    router.put(route('entities.questions.update', { entity: props.entity.id, question: questionId, tab: 'questions' }), {
        status: 'resolved',
    })
}

const applyRouteState = () => {
    const requestedTab = urlParams.value.get('tab')
    const nextTab = validTabs.value.includes(requestedTab) ? requestedTab : 'identity'

    activeTab.value = nextTab
}

watch(
    () => page.url,
    () => applyRouteState(),
    { immediate: true },
)

// --- Computed ---

const hasPowerTiers = computed(() =>
    props.entity.power_tier_ceiling ||
    props.entity.power_tier_operating ||
    props.entity.power_tier_influence
)
const entityHeroMeta = computed(() => [
    { label: 'Status', value: formatLabel(props.entity.status || 'concept') },
    { label: 'Visibility', value: formatLabel(props.entity.visibility || 'private') },
    { label: 'Completion', value: `${props.entity.completion_score ?? 0}%` },
    {
        label: 'Published',
        value: props.entity.published_at
            ? formatDate(props.entity.published_at)
            : 'Draft',
    },
])
const entitySubtitle = computed(() =>
    props.entity.public_title ? `"${props.entity.public_title}"` : ''
)
const entityDestroyConfirm = computed(() => `Move "${props.entity.name}" to trash?`)

// --- Formatters ---

const formatDate = (dt) => {
    if (!dt) return '—'
    return new Date(dt).toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' })
}

// --- Style helpers ---

const typeBadgeClass = (type) => {
    const map = {
        character:             'type--character',
        historical_figure:     'type--character',
        faction:               'type--faction',
        organization:          'type--faction',
        government:            'type--faction',
        movement:              'type--faction',
        location:              'type--location',
        dimension:             'type--location',
        plane:                 'type--location',
        realm:                 'type--location',
        event:                 'type--event',
        conflict:              'type--event',
        ritual:                'type--event',
        phenomenon:            'type--event',
        artifact:              'type--object',
        weapon:                'type--object',
        relic:                 'type--object',
        vehicle:               'type--object',
        constructed_intelligence: 'type--ci',
        deity:                 'type--power',
        cosmic_entity:         'type--power',
        cosmological_force:    'type--power',
    }
    return map[type] ?? 'type--default'
}

const statusBadgeClass = (status) => {
    const map = {
        active:    'status--active',
        concept:   'status--concept',
        recorded:  'status--default',
        archived:  'status--archived',
        deceased:  'status--deceased',
        destroyed: 'status--deceased',
        dormant:   'status--dormant',
    }
    return map[status] ?? 'status--default'
}

const completionBarClass = (score) => {
    if (score >= 80) return 'bg-success'
    if (score >= 50) return 'bg-focus'
    if (score >= 20) return 'bg-warn'
    return 'bg-danger'
}
</script>

