import { Node } from '@tiptap/core'
import { bitcraftAssetUrl, hasBitcraftTier } from '@/Pages/Bitcraft/bitjitaAssets'
import './guideItemCard.css'
import { BitcraftActivity } from './bitcraftActivity'
import { guideCardLayoutAttributes, guideCardLayoutHtml } from './guideCardLayout'

const attributeNames = ['id', 'kind', 'name', 'category', 'tier', 'rarity', 'iconAssetName']

export const BitcraftItem = Node.create({
    name: 'bitcraftItem',
    group: 'block',
    atom: true,
    draggable: true,

    addAttributes() {
        return {
            ...guideCardLayoutAttributes(),
            ...Object.fromEntries(attributeNames.map((name) => [name, {
                default: null,
                rendered: false,
                parseHTML: (element) => element.getAttribute(`data-item-${name.toLowerCase()}`),
            }])),
        }
    },

    parseHTML() {
        return [{ tag: 'div[data-bitcraft-item]' }]
    },

    renderHTML({ node }) {
        const item = node.attrs
        const name = String(item.name || 'Unknown item')
        const iconUrl = bitcraftAssetUrl(item.iconAssetName)
        const initials = name.split(/\s+/).slice(0, 2).map((word) => word[0]).join('')
        const metadata = [
            hasBitcraftTier(item.tier) ? `Tier ${Number(item.tier)}` : null,
            item.rarity,
            item.kind === 'cargo' ? 'Cargo' : 'Item',
        ].filter(Boolean).map(String)

        return ['div', {
            class: 'guide-item-card',
            'data-bitcraft-item': '',
            ...guideCardLayoutHtml(item),
            ...Object.fromEntries(attributeNames.filter((key) => item[key] !== null)
                .map((key) => [`data-item-${key.toLowerCase()}`, String(item[key])])),
        },
        ['span', { class: 'guide-item-card__icon', 'aria-hidden': 'true' },
            iconUrl?.startsWith('/bitcraft-assets/')
                ? ['img', { src: iconUrl, alt: '', loading: 'lazy' }]
                : ['span', {}, initials],
        ],
        ['div', { class: 'guide-item-card__copy' },
            ['strong', { class: 'guide-item-card__name' }, name],
            ...(item.category ? [['span', { class: 'guide-item-card__category' }, String(item.category)]] : []),
            ['div', { class: 'guide-item-card__metadata' },
                ...metadata.map((label) => ['span', {}, label]),
            ],
        ]]
    },
})

export const guideItemExtensions = [BitcraftItem, BitcraftActivity]
