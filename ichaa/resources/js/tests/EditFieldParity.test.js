import fs from 'node:fs'
import path from 'node:path'
import { describe, expect, it } from 'vitest'

const pagesRoot = path.resolve(process.cwd(), 'resources/js/Pages')

const parityResources = [
    'Collections',
    'Entities',
    'Entities/Aliases',
    'Entities/Questions',
    'FactionMemberships',
    'Glossary',
    'GroupRelationships',
    'Intelligence/KnowledgeStates',
    'Intelligence/PerceptionStates',
    'Intelligence/Secrets',
    'Lore/Documents',
    'Relationships',
    'Temporal/CharacterStates',
    'Temporal/Timelines',
    'World/LocationContainment',
    'World/LocationControl',
    'World/PowerInteractions',
    'World/TravelRoutes',
]

const extractFieldKeys = (content) => [
    ...content.matchAll(/key:\s*'([^']+)'/g),
].map((match) => match[1])

describe('edit field parity', () => {
    it.each(parityResources)('%s keeps create fields available in edit', (resourcePath) => {
        const createContent = fs.readFileSync(path.join(pagesRoot, resourcePath, 'Create.vue'), 'utf8')
        const editContent = fs.readFileSync(path.join(pagesRoot, resourcePath, 'Edit.vue'), 'utf8')

        const createKeys = [...new Set(extractFieldKeys(createContent))]
        const editKeys = new Set(extractFieldKeys(editContent))
        const missingInEdit = createKeys.filter((key) => !editKeys.has(key))

        expect(missingInEdit).toEqual([])
    })
})
