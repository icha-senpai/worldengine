export function buildCanonReferenceSections({
    levels = [],
    universePriorities = [],
    researchStatuses = [],
    researchConfidences = [],
    categoryTypes = [],
    elementTypes = [],
    parentReferenceOptions = [],
    entityOptions = [],
}) {
    return [
        {
            title: 'Reference',
            fields: [
                { key: 'universe', label: 'Universe', required: true },
                { key: 'level', label: 'Level', type: 'select', required: true, options: levels },
                { key: 'title', label: 'Title', required: true },
                {
                    key: 'parent_reference_id',
                    label: 'Parent Reference',
                    type: 'select',
                    options: parentReferenceOptions,
                    placeholder: 'Optional parent canon reference...',
                },
                { key: 'universe_priority', label: 'Universe Priority', type: 'select', options: universePriorities },
                { key: 'research_status', label: 'Research Status', type: 'select', options: researchStatuses },
                { key: 'research_confidence', label: 'Research Confidence', type: 'select', options: researchConfidences },
                { key: 'category_type', label: 'Category Type', type: 'select', options: categoryTypes },
                { key: 'element_type', label: 'Element Type', type: 'select', options: elementTypes },
                { key: 'canon_disputed', label: 'Canon Disputed', type: 'checkbox' },
                {
                    key: 'au_entity_id',
                    label: 'AU Entity',
                    type: 'select',
                    options: entityOptions,
                    placeholder: 'Optional AU counterpart...',
                },
            ],
        },
        {
            title: 'Content',
            fields: [
                { key: 'content', label: 'Content JSON', type: 'json', jsonMode: 'document', rows: 8 },
            ],
        },
    ]
}
