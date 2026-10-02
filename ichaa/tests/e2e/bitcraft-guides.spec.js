import { expect, test } from '@playwright/test'
import { login } from './support/auth'

const guidesPath = '/datacrypt/bitcraft/guides'

for (const viewport of [
    { name: 'desktop', width: 1440, height: 1000 },
    { name: 'mobile', width: 390, height: 844 },
]) {
    test(`admin can write, preview, publish, and edit guides on ${viewport.name}`, async ({ page }, testInfo) => {
        await page.setViewportSize(viewport)
        const errors = []
        page.on('pageerror', (error) => errors.push(error.message))
        await login(page)
        await page.goto(guidesPath)
        await expect(page.getByRole('heading', { name: 'BitCraft Guides', exact: true })).toBeVisible()

        if (viewport.name === 'mobile') {
            await page.getByRole('button', { name: 'Toggle navigation' }).click()
        }
        const nav = page.getByRole('navigation', { name: viewport.name === 'mobile' ? 'Primary mobile' : 'Primary', exact: true })
        const guidesLink = nav.getByRole('link', { name: 'Guides', exact: true })
        if (!await guidesLink.isVisible()) {
            await nav.getByRole('button', { name: 'Bitcraft Tools', exact: true }).click()
        }
        await expect(guidesLink).toBeVisible()
        await guidesLink.click()

        await page.getByRole('link', { name: 'New guide', exact: true }).first().click()
        const title = `First crafting project ${viewport.name}`
        await page.getByLabel('Title', { exact: true }).fill(title)
        await page.getByLabel('Summary', { exact: true }).fill('Materials and crafting steps for a new settlement.')
        await page.getByLabel('Category', { exact: true }).fill('Crafting')
        const content = page.getByLabel('Guide content', { exact: true })
        await expect(content).toBeVisible()
        await content.fill('Gather your materials, then start crafting at the workshop.')
        await page.route('**/guides/items*', async (route) => {
            await route.fulfill({ json: { available: true, items: [{
                id: 123, kind: 'item', name: 'Crushed Copper Ore', category: 'Ore',
                tier: 2, rarity: 'Common', iconAssetName: 'Items/CrushedCopperOre',
            }] } })
        })
        await content.press('End')
        await page.getByRole('button', { name: 'Insert item card', exact: true }).click()
        await page.getByLabel('Search items', { exact: true }).fill('copper')
        await assertPickerTextColors(page, 'Insert item card', 'Insert Crushed Copper Ore, item, tier 2')
        await page.screenshot({ path: testInfo.outputPath(`guide-item-picker-${viewport.name}.png`) })
        await page.getByRole('button', { name: 'Insert Crushed Copper Ore, item, tier 2', exact: true }).click()
        await expect(content.locator('.guide-item-card')).toContainText('Crushed Copper Ore')
        await expect(page.locator('dialog[open]')).toHaveCount(0)
        await setCardLayout(page, content.locator('.guide-item-card'), 'left', 25)
        await setCardLayout(page, content.locator('.guide-item-card'), 'center', 60)
        await page.getByRole('tab', { name: 'Preview', exact: true }).click()
        await expect(page.getByRole('tabpanel', { name: 'Preview' })).toContainText('Gather your materials')
        await expect(page.getByRole('tabpanel', { name: 'Preview' }).locator('.guide-item-card')).toContainText('Tier 2')
        await assertCardLayout(page.getByRole('tabpanel', { name: 'Preview' }).locator('.guide-item-card'), 'center', 60)
        await page.getByRole('tab', { name: 'Write', exact: true }).click()
        await assertNoPageOverflow(page)
        if (viewport.name === 'mobile') {
            expect((await page.locator('.editor-toolbar').boundingBox()).height).toBeLessThan(240)
        }
        await page.evaluate(() => window.scrollTo(0, 0))
        await page.screenshot({ path: testInfo.outputPath(`guide-editor-${viewport.name}.png`), fullPage: true })

        await page.getByRole('button', { name: 'Save draft', exact: true }).click()
        await expect(page).toHaveURL(/\/guides\/\d+$/)
        await expect(page.getByRole('heading', { name: title, exact: true })).toBeVisible()
        await expect(page.getByText('Draft', { exact: true })).toBeVisible()
        await expect(page.locator('.rich-document-value')).toContainText('Gather your materials')
        await expect(page.locator('.rich-document-value .guide-item-card')).toContainText('Crushed Copper Ore')
        await assertCardLayout(page.locator('.rich-document-value .guide-item-card'), 'center', 60)
        await expect(page.locator('.guide-item-card img')).toBeVisible()
        expect(await page.locator('.guide-item-card img').evaluate((image) => image.complete && image.naturalWidth > 0)).toBe(true)
        await page.screenshot({ path: testInfo.outputPath(`guide-items-${viewport.name}.png`), fullPage: true })

        await page.getByRole('link', { name: 'Edit guide', exact: true }).click()
        await expect(content).toContainText('Gather your materials')
        await expect(content.locator('.guide-item-card')).toContainText('Crushed Copper Ore')
        await assertCardLayout(content.locator('.guide-item-card'), 'center', 60)
        await page.getByRole('checkbox', { name: 'Published', exact: true }).check()
        await page.getByRole('button', { name: 'Publish guide', exact: true }).click()
        await expect(page).toHaveURL(/\/guides\/\d+$/)
        await expect(page.getByText('Draft', { exact: true })).toHaveCount(0)
        await assertReaderCardEdges(page.locator('.rich-document-value .guide-item-card'))

        await page.getByRole('link', { name: 'Edit guide', exact: true }).click()
        const editedTitle = `${title} updated`
        await page.getByLabel('Title', { exact: true }).fill(editedTitle)
        await content.fill('Updated steps: gather materials and craft your first tool.')
        await page.getByRole('button', { name: 'Save changes', exact: true }).click()
        await expect(page.getByRole('heading', { name: editedTitle, exact: true })).toBeVisible()
        await expect(page.locator('.rich-document-value')).toContainText('Updated steps')
        await assertNoPageOverflow(page)
        await page.screenshot({ path: testInfo.outputPath(`guide-reader-${viewport.name}.png`), fullPage: true })

        await page.getByRole('link', { name: 'Back to guides', exact: true }).click()
        await page.getByLabel('Search guides', { exact: true }).fill(editedTitle)
        await page.getByRole('button', { name: 'Search', exact: true }).click()
        await expect(page.locator('.index-record')).toHaveCount(1)
        await expect(page.locator('.index-record')).toContainText(editedTitle)
        await page.screenshot({ path: testInfo.outputPath(`guide-list-${viewport.name}.png`), fullPage: true })
        expect(errors).toEqual([])
    })

    test(`crafting and gathering cards retain defaults and allow reader calculations on ${viewport.name}`, async ({ page }, testInfo) => {
        await page.setViewportSize(viewport)
        const errors = []
        page.on('pageerror', (error) => errors.push(error.message))
        const output = { id: 123, kind: 'item', name: 'Crushed Copper Ore', quantity: 2, iconAssetName: 'Items/CrushedCopperOre' }
        const input = { id: 456, kind: 'item', name: 'Raw Ore', quantity: 3, iconAssetName: 'Items/CrushedCopperOre' }
        const recipes = [
            { id: 7, recipeName: 'Crush ore', craftingStation: 'Workshop', timeRequirement: 4, outputQuantity: 2, craftedItems: [output], consumedItems: [input] },
            { id: 17, recipeName: 'Crush ore in bulk', craftingStation: 'Workshop', timeRequirement: 6, outputQuantity: 4, craftedItems: [{ ...output, quantity: 4 }], consumedItems: [{ ...input, quantity: 5 }] },
        ]
        const snapshot = { generatedAt: '2026-10-02T12:00:00Z' }
        await page.route('**/guides/card-options*', async (route) => {
            const activity = new URL(route.request().url()).searchParams.get('activity')
            await route.fulfill({ json: { available: true, items: activity === 'crafting'
                ? [{ ...output, category: 'Ore' }]
                : [{ id: 8, name: 'Mine Copper Vein', category: 'Mining', iconAssetName: output.iconAssetName }] } })
        })
        await page.route('**/guides/card-data*', async (route) => {
            const activity = new URL(route.request().url()).searchParams.get('activity')
            await route.fulfill({ json: activity === 'crafting' ? { item: output, recipes, snapshot } : {
                entry: {
                    id: 8, name: 'Mine Copper Vein', timeRequirement: 10,
                    resource: { name: 'Copper Vein', maxHealth: 15, iconAssetName: output.iconAssetName },
                    tool: { name: 'Pickaxe', level: 1, power: 5 }, skill: { name: 'Mining' }, levelRequirement: 1,
                    outputs: [{ ...output, quantity: 1, probability: 0.5 }], consumedItems: [], experiencePerProgress: { quantity: 2 },
                }, snapshot,
            } })
        })
        let marketRequests = 0
        await page.route('**/market/order-book*', async (route) => {
            marketRequests += 1
            await route.fulfill({ json: {
                orderBook: { stats: { lowestSell: 10, highestBuy: 8, sellOrderCount: 2, buyOrderCount: 3 } },
                refresh: { delayed: true, updatedAt: '2026-10-02T12:00:00Z' },
            } })
        })
        await login(page)
        await page.goto(`${guidesPath}/create`)
        const title = `Activity cards ${viewport.name}`
        await page.getByLabel('Title', { exact: true }).fill(title)
        const content = page.getByLabel('Guide content', { exact: true })
        await content.fill('Plan your crafting and gathering.')
        await content.press('Control+End')
        await page.getByRole('button', { name: 'Insert crafting card', exact: true }).click()
        await page.getByLabel('Search recipe outputs', { exact: true }).fill('ore')
        await assertPickerTextColors(page, 'Crafting card', 'Choose Crushed Copper Ore')
        await page.getByRole('button', { name: 'Choose Crushed Copper Ore', exact: true }).click()
        await assertPickerTextColors(page, 'Crafting card', 'Insert recipe Crush ore')
        await page.screenshot({ path: testInfo.outputPath(`guide-crafting-picker-${viewport.name}.png`) })
        await page.getByRole('button', { name: 'Insert recipe Crush ore', exact: true }).click()
        await expect(page.locator('dialog[open]')).toHaveCount(0)
        const craft = page.locator('#guide-write-panel .guide-activity-card').first()
        await craft.getByLabel('Desired quantity', { exact: true }).fill('5')
        await expect(craft).toContainText('3 batches')
        await setCardLayout(page, craft, 'left', 25)
        await setCardLayout(page, craft, 'left', 100)
        await setCardLayout(page, craft, 'right', 50)
        await craft.getByLabel('Market prices', { exact: true }).check()
        expect(marketRequests).toBe(0)
        await craft.getByRole('button', { name: 'Load prices', exact: true }).click()
        await expect(craft).toContainText('Refresh delayed')
        await expect(craft.locator('time')).toHaveAttribute('datetime', '2026-10-02T12:00:00Z')
        expect(marketRequests).toBe(1)
        await content.press('Control+End')
        await page.getByRole('button', { name: 'Insert gathering card', exact: true }).click()
        await page.getByLabel('Search gathering actions', { exact: true }).fill('copper')
        await assertPickerTextColors(page, 'Gathering card', 'Choose Mine Copper Vein')
        await page.screenshot({ path: testInfo.outputPath(`guide-gathering-picker-${viewport.name}.png`) })
        await page.getByRole('button', { name: 'Choose Mine Copper Vein', exact: true }).click()
        await expect(page.locator('dialog[open]')).toHaveCount(0)
        const gather = page.locator('#guide-write-panel .guide-activity-card').last()
        await gather.getByLabel('Tool power', { exact: true }).fill('10')
        await gather.getByLabel('Minutes', { exact: true }).fill('1')
        await gather.getByRole('combobox', { name: 'Target', exact: true }).selectOption('single')
        await expect(gather).toContainText('30 estimated XP')
        await setCardLayout(page, gather, 'center', 75)
        await page.getByRole('tab', { name: 'Preview', exact: true }).click()
        await expect(page.locator('#guide-preview-panel .guide-activity-card')).toHaveCount(2)
        await expect(page.locator('#guide-preview-panel .guide-activity-card').last()).toContainText('7.5')
        await assertCardLayout(page.locator('#guide-preview-panel .guide-activity-card').first(), 'right', 50)
        await assertCardLayout(page.locator('#guide-preview-panel .guide-activity-card').last(), 'center', 75)
        await assertNoPageOverflow(page)
        await page.getByRole('button', { name: 'Save draft', exact: true }).click()
        await expect(page).toHaveURL(/\/guides\/\d+$/)
        const readerCraft = page.locator('.rich-document-value .guide-activity-card').first()
        await assertCardLayout(readerCraft, 'right', 50)
        await assertReaderCardEdges(readerCraft)
        await assertCardLayout(page.locator('.rich-document-value .guide-activity-card').last(), 'center', 75)
        await expect(readerCraft.getByLabel('Desired quantity', { exact: true })).toHaveValue('5')
        await readerCraft.getByLabel('Desired quantity', { exact: true }).fill('9')
        await expect(readerCraft).toContainText('5 batches')
        await readerCraft.getByLabel('Owned Raw Ore', { exact: true }).fill('4')
        await expect(readerCraft).toContainText('Need 11')
        await readerCraft.getByRole('combobox', { name: 'Recipe', exact: true }).selectOption('17')
        await expect(readerCraft).toContainText('3 batches')
        expect(marketRequests).toBe(1)
        await readerCraft.getByRole('button', { name: 'Load prices', exact: true }).click()
        await expect(readerCraft).toContainText('Refresh delayed')
        await readerCraft.getByLabel('Region', { exact: true }).fill('Another region')
        await expect(readerCraft.locator('dl')).toHaveCount(0)
        await assertNoPageOverflow(page)
        await page.screenshot({ path: testInfo.outputPath(`guide-activities-${viewport.name}.png`), fullPage: true })
        await page.getByRole('link', { name: 'Edit guide', exact: true }).click()
        const editorCraft = page.locator('#guide-write-panel .guide-activity-card').first()
        await assertCardLayout(editorCraft, 'right', 50)
        await expect(editorCraft.getByLabel('Desired quantity', { exact: true })).toHaveValue('5')
        await expect(editorCraft.getByRole('combobox', { name: 'Recipe', exact: true })).toHaveValue('7')
        await expect(page.locator('#guide-write-panel .guide-activity-card').last().getByLabel('Tool power', { exact: true })).toHaveValue('10')
        expect(errors).toEqual([])
    })

    test(`cards and images wrap text and preserve their flow on ${viewport.name}`, async ({ page }, testInfo) => {
        await page.setViewportSize(viewport)
        const errors = []
        page.on('pageerror', (error) => errors.push(error.message))
        const item = { id: 123, kind: 'item', name: 'Crushed Copper Ore', quantity: 1, iconAssetName: 'Items/CrushedCopperOre' }
        await page.route('**/guides/card-data*', async (route) => {
            const activity = new URL(route.request().url()).searchParams.get('activity')
            await route.fulfill({ json: activity === 'crafting' ? {
                item, recipes: [{ id: 7, recipeName: 'Crush Ore', craftedItems: [item], consumedItems: [item], outputQuantity: 1 }],
            } : {
                entry: { id: 8, name: 'Mine Ore', timeRequirement: 10, resource: { name: 'Ore vein', maxHealth: 15 },
                    tool: { name: 'Pickaxe', level: 1, power: 5 }, skill: { name: 'Mining' }, levelRequirement: 1,
                    outputs: [{ ...item, probability: 1 }], consumedItems: [], experiencePerProgress: { quantity: 2 } },
            } })
        })
        await login(page)
        await page.goto(`${guidesPath}/create`)
        await page.getByLabel('Title', { exact: true }).fill(`Wrapped guide ${viewport.name}`)
        const content = page.getByLabel('Guide content', { exact: true })
        const notes = (name) => `<p>${name} notes. ${'Gather materials near your workshop and keep extra supplies for your next project. '.repeat(name === 'Image' ? 3 : 12)}</p>`
        const html = `<p>Plans for the next project.</p>
            <div data-bitcraft-item data-item-id="123" data-item-kind="item" data-item-name="Crushed Copper Ore" data-item-iconassetname="Items/CrushedCopperOre" data-card-align="left" data-card-width="35"></div>
            ${notes('Item')}<hr>
            <div data-guide-activity="crafting" data-guide-recipeid="7" data-guide-itemid="123" data-guide-kind="item" data-guide-name="Crush Ore" data-guide-settings="{&quot;quantity&quot;:1}" data-card-align="right" data-card-width="40"></div>
            ${notes('Crafting')}<hr>
            <div data-guide-activity="gathering" data-guide-recipeid="8" data-guide-kind="item" data-guide-name="Mine Ore" data-guide-settings="{&quot;power&quot;:10}" data-card-align="left" data-card-width="40"></div>
            ${notes('Gathering')}<hr>
            <img src="/bitcraft-assets/sprites/Items/CrushedCopperOre.webp" alt="Wrapped ore image" data-align="right" data-width="35%">
            ${notes('Image')}`
        await content.focus()
        await content.evaluate((element, value) => {
            const clipboard = new DataTransfer()
            clipboard.setData('text/html', value)
            element.dispatchEvent(new ClipboardEvent('paste', { clipboardData: clipboard, bubbles: true, cancelable: true }))
        }, html)
        const editorMedia = [
            content.locator('.guide-item-card'), content.locator('.guide-activity-card').first(),
            content.locator('.guide-activity-card').last(), content.locator('.tiptap-editor__image-node'),
        ]
        const labels = ['Item', 'Crafting', 'Gathering', 'Image']
        for (let index = 0; index < editorMedia.length; index += 1) {
            const media = editorMedia[index]
            const paragraph = content.locator('p').filter({ hasText: `${labels[index]} notes.` })
            await assertTextFlow(media, paragraph, false)
            if (await media.locator('h3').count()) await media.locator('h3').click()
            else await media.click()
            const tools = page.getByRole('group', { name: index === 3 ? 'Image layout' : 'Card layout', exact: true })
            await tools.getByRole('checkbox', { name: 'Wrap text', exact: true }).check()
            await assertTextFlow(media, paragraph, viewport.name === 'desktop')
        }
        const imageTools = page.getByRole('group', { name: 'Image layout', exact: true })
        if (viewport.name === 'desktop') {
            const handle = editorMedia[3].getByRole('button', { name: 'Resize image from right edge', exact: true })
            await handle.scrollIntoViewIfNeeded()
            const startWidth = (await editorMedia[3].boundingBox()).width
            const handleRect = await handle.boundingBox()
            const x = handleRect.x + handleRect.width / 2
            const y = handleRect.y + handleRect.height / 2
            await page.mouse.move(x, y)
            await page.mouse.down()
            await page.mouse.move(x + 40, y, { steps: 4 })
            await page.mouse.up()
            expect((await editorMedia[3].boundingBox()).width).toBeGreaterThan(startWidth + 20)
            await expect(editorMedia[3]).toHaveAttribute('data-wrap', 'true')
        }
        await imageTools.getByRole('checkbox', { name: 'Wrap text', exact: true }).uncheck()
        await assertTextFlow(editorMedia[3], content.locator('p').filter({ hasText: 'Image notes.' }), false)
        await imageTools.getByRole('checkbox', { name: 'Wrap text', exact: true }).check()
        await imageTools.getByRole('combobox', { name: 'Image alignment', exact: true }).selectOption('center')
        await expect(imageTools.getByRole('checkbox', { name: 'Wrap text', exact: true })).toBeDisabled()
        await assertTextFlow(editorMedia[3], content.locator('p').filter({ hasText: 'Image notes.' }), false)
        await imageTools.getByRole('combobox', { name: 'Image alignment', exact: true }).selectOption('right')
        await imageTools.getByRole('checkbox', { name: 'Wrap text', exact: true }).check()
        await assertNoPageOverflow(page)
        await page.screenshot({ path: testInfo.outputPath(`guide-wrapping-editor-${viewport.name}.png`), fullPage: true })
        await page.getByRole('tab', { name: 'Preview', exact: true }).click()
        await expect(page.locator('#guide-preview-panel img[alt="Wrapped ore image"]')).toBeVisible()
        await page.getByRole('button', { name: 'Save draft', exact: true }).click()
        await expect(page).toHaveURL(/\/guides\/\d+$/)
        const reader = page.locator('.guide-content .ProseMirror')
        const readerMedia = [
            reader.locator('.guide-item-card'), reader.locator('.guide-activity-card').first(),
            reader.locator('.guide-activity-card').last(), reader.locator('img[alt="Wrapped ore image"]'),
        ]
        for (let index = 0; index < readerMedia.length; index += 1) {
            const media = readerMedia[index]
            await expect(media).toHaveAttribute(index === 3 ? 'data-wrap' : 'data-card-wrap', 'true')
            await assertTextFlow(media, reader.locator('p').filter({ hasText: `${labels[index]} notes.` }), viewport.name === 'desktop')
            if (index !== 3) await assertReaderCardEdges(media)
        }
        expect(await readerMedia[3].evaluate((image) => image.complete && image.naturalWidth > 0)).toBe(true)
        const readerRect = await reader.boundingBox()
        const imageRect = await readerMedia[3].boundingBox()
        expect(readerRect.y + readerRect.height).toBeGreaterThanOrEqual(imageRect.y + imageRect.height)
        await assertNoPageOverflow(page)
        await page.screenshot({ path: testInfo.outputPath(`guide-wrapping-reader-${viewport.name}.png`), fullPage: true })
        await page.getByRole('link', { name: 'Edit guide', exact: true }).click()
        await expect(content.locator('[data-card-wrap="true"]')).toHaveCount(3)
        await expect(content.locator('.tiptap-editor__image-node')).toHaveAttribute('data-wrap', 'true')
        expect(errors).toEqual([])
    })
}

async function assertNoPageOverflow(page) {
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true)
}

async function assertPickerTextColors(page, heading, option) {
    const primaryColor = await page.locator('body').evaluate((element) => getComputedStyle(element).color)
    expect(primaryColor).not.toBe('rgb(0, 0, 0)')
    const picker = page.locator('dialog[open]')
    await expect(picker.getByRole('heading', { name: heading, exact: true })).toHaveCSS('color', primaryColor)
    await expect(picker.getByRole('button', { name: option, exact: true }).locator('span.font-semibold')).toHaveCSS('color', primaryColor)
}

async function setCardLayout(page, card, align, width) {
    if (await card.locator('h3').count()) await card.locator('h3').click()
    else await card.click()
    await expect(card).toHaveCSS('outline-style', 'solid')
    const tools = page.getByRole('group', { name: 'Card layout', exact: true })
    await tools.getByRole('combobox', { name: 'Card alignment', exact: true }).selectOption(align)
    const slider = tools.getByRole('slider', { name: 'Card width', exact: true })
    await slider.press('Home')
    for (let value = 25; value < width; value += 5) await slider.press('ArrowRight')
    await assertCardLayout(card, align, width)
    await assertNoPageOverflow(page)
}

async function assertCardLayout(card, align, width) {
    await expect(card).toHaveAttribute('data-card-align', align)
    await expect(card).toHaveAttribute('data-card-width', String(width))
    const layout = await card.evaluate((element) => {
        const styles = getComputedStyle(element)
        const parentStyles = getComputedStyle(element.parentElement)
        const available = element.parentElement.clientWidth - parseFloat(parentStyles.paddingLeft) - parseFloat(parentStyles.paddingRight)
        return { width: element.getBoundingClientRect().width, available, left: parseFloat(styles.marginLeft), right: parseFloat(styles.marginRight) }
    })
    expect(layout.width).toBeLessThanOrEqual(layout.available + 1)
    if (align === 'center') expect(Math.abs(layout.left - layout.right)).toBeLessThan(1)
    if (align === 'left') expect(layout.left).toBe(0)
    if (align === 'right') expect(layout.right).toBe(0)
    const minimum = await card.evaluate((element) => element.classList.contains('guide-item-card') ? 240 : 320)
    expect(Math.abs(layout.width - Math.min(layout.available, Math.max(minimum, layout.available * width / 100)))).toBeLessThan(1)
}

async function assertTextFlow(media, paragraph, wrapped) {
    await expect(media).toBeVisible()
    await expect(paragraph).toBeVisible()
    const rectangle = await media.boundingBox()
    const text = await paragraph.evaluate((element) => {
        const range = document.createRange()
        range.setStart(element.firstChild, 0)
        range.setEnd(element.firstChild, 10)
        const rect = range.getBoundingClientRect()
        return { top: rect.top, left: rect.left, right: rect.right }
    })
    if (!wrapped) {
        await expect(media).toHaveCSS('float', 'none')
        expect(text.top).toBeGreaterThanOrEqual(rectangle.y + rectangle.height)
        return
    }
    const align = await media.getAttribute('data-card-align') || await media.getAttribute('data-align')
    await expect(media).toHaveCSS('float', align)
    expect(text.top).toBeLessThan(rectangle.y + rectangle.height)
    expect(text.top).toBeGreaterThanOrEqual(rectangle.y - 2)
    if (align === 'left') expect(text.left).toBeGreaterThanOrEqual(rectangle.x + rectangle.width + 20)
    else expect(text.right).toBeLessThanOrEqual(rectangle.x - 20)
}

async function assertReaderCardEdges(card) {
    if (await card.locator('h3').count()) await card.locator('h3').click()
    else await card.click()
    await expect(card).toHaveCSS('outline-style', 'none')
    const edges = await card.evaluate((element) => {
        const bounds = element.getBoundingClientRect()
        const viewport = element.closest('.guide-content').getBoundingClientRect()
        const styles = getComputedStyle(element)
        return { left: bounds.left, right: bounds.right, viewportLeft: viewport.left, viewportRight: viewport.right,
            leftBorder: styles.borderLeftWidth, rightBorder: styles.borderRightWidth }
    })
    expect(edges.left).toBeGreaterThanOrEqual(edges.viewportLeft)
    expect(edges.right).toBeLessThanOrEqual(edges.viewportRight)
    expect(edges.leftBorder).toBe('1px')
    expect(edges.rightBorder).toBe('1px')
}
