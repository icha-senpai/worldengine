import { formatLabel } from '@/Components/scaffold/formatters'

const aliasTypeLabels = {
    name: 'Name',
    nickname: 'Nickname',
    title: 'Formal Title',
    role_title: 'Role Title',
    public_title: 'Public Title',
    hidden_title: 'Hidden Title',
    cover_identity: 'Cover Identity',
    codename: 'Codename',
    epithet: 'Epithet',
    birth_name: 'Birth Name',
    alias: 'Alias',
    honorific: 'Honorific',
    posthumous: 'Posthumous',
    reputation: 'Reputation',
    common: 'Common Name',
    classified: 'Classified Alias',
    other: 'Other',
}

export const entityAliasTypeOptions = Object.entries(aliasTypeLabels).map(([value, label]) => ({
    value,
    label,
}))

export const formatEntityAliasType = (value) => aliasTypeLabels[value] ?? formatLabel(value)
