export const formatLabel = (value) => value
    ? String(value).replace(/_/g, ' ').replace(/\b\w/g, (char) => char.toUpperCase())
    : '—'

export const isPlainObject = (value) =>
    value !== null && typeof value === 'object' && !Array.isArray(value)

export const isRichDocument = (value) =>
    isPlainObject(value) && value.type === 'doc' && Array.isArray(value.content)

const selfContainedRichNodes = new Set([
    'hardBreak',
    'horizontalRule',
    'image',
])

const nodeHasMeaningfulContent = (node) => {
    if (!isPlainObject(node)) {
        return false
    }

    if (typeof node.text === 'string' && node.text.trim() !== '') {
        return true
    }

    if (selfContainedRichNodes.has(node.type)) {
        return true
    }

    return Array.isArray(node.content) && node.content.some(nodeHasMeaningfulContent)
}

export const hasRichDocumentContent = (value) =>
    isRichDocument(value) && value.content.some(nodeHasMeaningfulContent)

const richDocumentNodeText = (node) => {
    if (!isPlainObject(node)) {
        return ''
    }

    if (typeof node.text === 'string') {
        return node.text
    }

    if (node.type === 'hardBreak') {
        return '\n'
    }

    if (!Array.isArray(node.content)) {
        return ''
    }

    return node.content.map(richDocumentNodeText).join('')
}

export const richDocumentToPlainText = (value) => {
    if (!isRichDocument(value)) {
        return ''
    }

    return value.content
        .map((node) => richDocumentNodeText(node).trim())
        .filter(Boolean)
        .join('\n\n')
}

export const summarizeValue = (value) => {
    if (value === null || value === undefined || value === '') {
        return '—'
    }

    if (Array.isArray(value)) {
        if (value.length === 0) {
            return '—'
        }

        return value
            .map((item) => summarizeValue(item))
            .join(', ')
    }

    if (isPlainObject(value)) {
        if (isRichDocument(value)) {
            return richDocumentToPlainText(value) || '—'
        }

        if ('name' in value && value.name) {
            return value.name
        }

        if ('title' in value && value.title) {
            return value.title
        }

        return JSON.stringify(value)
    }

    if (typeof value === 'boolean') {
        return value ? 'Yes' : 'No'
    }

    return String(value)
}

export const prettyJson = (value) => {
    if (value === null || value === undefined || value === '') {
        return ''
    }

    if (typeof value === 'string') {
        return value
    }

    return JSON.stringify(value, null, 2)
}

export const formatEntityOptionLabel = (entity) => {
    if (!entity) {
        return 'Unknown entity'
    }

    const name = entity.name || `Entity #${entity.id}`
    const type = entity.entity_type ? ` · ${formatLabel(entity.entity_type)}` : ''

    return `${name} (#${entity.id}${type})`
}

export const toEntityOptions = (entities = []) =>
    entities.map((entity) => ({
        value: entity.id,
        label: formatEntityOptionLabel(entity),
    }))

export const toSecretOptions = (secrets = []) =>
    secrets.map((secret) => ({
        value: secret.id,
        label: `${secret.title || `Secret #${secret.id}`} (#${secret.id}${secret.secret_type ? ` · ${formatLabel(secret.secret_type)}` : ''})`,
    }))

export const toRelationshipOptions = (relationships = []) =>
    relationships.map((relationship) => {
        const fromName = relationship.from_entity?.name || relationship.fromEntity?.name || `#${relationship.from_entity_id}`
        const toName = relationship.to_entity?.name || relationship.toEntity?.name || `#${relationship.to_entity_id}`
        const type = relationship.relationship_type ? ` · ${formatLabel(relationship.relationship_type)}` : ''

        return {
            value: relationship.id,
            label: `${fromName} -> ${toName} (#${relationship.id}${type})`,
        }
    })

export const toGroupRelationshipOptions = (groups = []) =>
    groups.map((group) => ({
        value: group.id,
        label: `${group.name || `Group Relationship #${group.id}`} (#${group.id}${group.relationship_type ? ` · ${formatLabel(group.relationship_type)}` : ''})`,
    }))

export const toTimelineEntryOptions = (entries = []) =>
    entries.map((entry) => {
        const label = entry.entry_label || entry.event_entity?.name || entry.eventEntity?.name || `Timeline Entry #${entry.id}`
        const timelineName = entry.timeline?.name ? ` · ${entry.timeline.name}` : ''
        const date = entry.au_date ? ` · ${entry.au_date}` : ''

        return {
            value: entry.id,
            label: `${label} (#${entry.id}${timelineName}${date})`,
        }
    })

export const toDocumentOptions = (documents = []) =>
    documents.map((document) => ({
        value: document.id,
        label: `${document.title || `Document #${document.id}`} (#${document.id}${document.document_type ? ` · ${formatLabel(document.document_type)}` : ''})`,
    }))

export const toCollectionOptions = (collections = []) =>
    collections.map((collection) => ({
        value: collection.id,
        label: `${collection.name || `Collection #${collection.id}`} (#${collection.id}${collection.collection_type ? ` · ${formatLabel(collection.collection_type)}` : ''})`,
    }))

export const toMetaOptions = (items = []) =>
    items.map((item) => ({
        value: item.id,
        label: `${item.title || `Meta #${item.id}`} (#${item.id}${item.category ? ` · ${formatLabel(item.category)}` : ''})`,
    }))

export const toConcurrencyGroupOptions = (groups = []) =>
    groups.map((group) => ({
        value: group.id,
        label: `${group.name || `Concurrency Group #${group.id}`} (#${group.id}${group.au_date ? ` · ${group.au_date}` : ''})`,
    }))

export const toCanonReferenceOptions = (references = []) =>
    references.map((reference) => ({
        value: reference.id,
        label: `${reference.title || `Canon Reference #${reference.id}`} (#${reference.id}${reference.universe ? ` · ${reference.universe}` : ''})`,
    }))

export const toPipelineItemOptions = (items = []) =>
    items.map((item) => ({
        value: item.id,
        label: `${item.title || `Pipeline Item #${item.id}`} (#${item.id}${item.pipeline_type ? ` · ${formatLabel(item.pipeline_type)}` : ''})`,
    }))
