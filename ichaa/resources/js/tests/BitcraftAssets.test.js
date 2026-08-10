import {
    bitcraftAssetUrl,
    bitcraftRarityStyle,
    bitcraftTierBadgeContainerUrl,
    bitcraftTierBadgeStyle,
    bitcraftTierBadgeUrl,
    bitjitaAssetUrl,
    hasBitcraftTier,
} from '@/Pages/Bitcraft/bitjitaAssets'

describe('Bitcraft asset URLs', () => {
    it('resolves generated item icons through the local Brico sprite mirror', () => {
        expect(bitcraftAssetUrl('GeneratedIcons/Other/GeneratedIcons/Items/Tools/AstralitePickaxe'))
            .toBe('/bitcraft-assets/sprites/GeneratedIcons/Other/GeneratedIcons/Items/Tools/AstralitePickaxe.webp')
    })

    it('resolves non-generated game asset paths through the local Brico sprite mirror', () => {
        expect(bitcraftAssetUrl('Items/HexCoin[,3,10,500]'))
            .toBe('/bitcraft-assets/sprites/Items/HexCoin.webp')

        expect(bitcraftAssetUrl('Items/HexCoin[,3,10,500]', 12))
            .toBe('/bitcraft-assets/sprites/Items/HexCoin10.webp')
    })

    it('preserves explicit local and remote URLs', () => {
        expect(bitcraftAssetUrl('/assets/Unknown.webp')).toBe('/assets/Unknown.webp')
        expect(bitcraftAssetUrl('https://example.test/icon.webp')).toBe('https://example.test/icon.webp')
    })

    it('keeps the old helper name as a compatibility alias', () => {
        expect(bitjitaAssetUrl('GeneratedIcons/Items/Hammer'))
            .toBe(bitcraftAssetUrl('GeneratedIcons/Items/Hammer'))
    })

    it('resolves Brico tier badge sprites', () => {
        expect(bitcraftTierBadgeUrl(5)).toBe('/bitcraft-assets/UI/Badges/badge-tier-number-5.webp')
        expect(bitcraftTierBadgeContainerUrl()).toBe('/bitcraft-assets/UI/Badges/badge-tier-container.webp')
        expect(bitcraftTierBadgeUrl(0)).toBeNull()
        expect(bitcraftTierBadgeUrl(-1)).toBeNull()
        expect(bitcraftTierBadgeUrl(11)).toBeNull()
    })

    it('uses Brico tier and rarity colors, including tier zero and untiered', () => {
        expect(hasBitcraftTier(0)).toBe(true)
        expect(hasBitcraftTier(-1)).toBe(true)
        expect(hasBitcraftTier('')).toBe(false)
        expect(bitcraftTierBadgeStyle(0)['--bitcraft-tier-accent']).toBe('#413A64')
        expect(bitcraftTierBadgeStyle(-1)['--bitcraft-tier-accent']).toBe('#413A64')
        expect(bitcraftTierBadgeStyle(10)['--bitcraft-tier-accent']).toBe('#97AFBE')
        expect(bitcraftRarityStyle('Default')['--bitcraft-rarity-border']).toBe('#636363')
        expect(bitcraftRarityStyle('Rare')['--bitcraft-rarity-border']).toBe('#495774')
        expect(bitcraftRarityStyle(6)['--bitcraft-rarity-border']).toBe('#3c32dc')
    })
})
