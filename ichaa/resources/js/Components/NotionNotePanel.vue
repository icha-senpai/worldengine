<template>
    <section v-if="note?.content" class="panel notion-note-panel">
        <div class="flex items-center justify-between gap-3 mb-3">
            <h3 class="panel-label !mb-0">{{ note.label || 'Notion Notes' }}</h3>
            <span v-if="note.lastSyncedAt" class="notion-note-meta">
                synced {{ formatTimestamp(note.lastSyncedAt) }}
            </span>
        </div>

        <div class="notion-note-content" v-html="renderedContent" />
    </section>
</template>

<script setup>
import { computed } from 'vue'

const props = defineProps({
    note: { type: Object, default: null },
})

const renderedContent = computed(() => renderMarkdown(props.note?.content ?? ''))

const formatTimestamp = (value) => {
    if (!value) {
        return ''
    }

    const date = new Date(value)

    if (Number.isNaN(date.getTime())) {
        return ''
    }

    return date.toLocaleString()
}

const renderMarkdown = (source) => {
    if (!source) {
        return ''
    }

    const lines = source.replace(/\r\n/g, '\n').split('\n')
    const html = []
    let i = 0

    while (i < lines.length) {
        const line = lines[i]

        if (/^```/.test(line.trim())) {
            const fence = line.trim()
            const codeLines = []
            i += 1

            while (i < lines.length && lines[i].trim() !== fence) {
                codeLines.push(lines[i])
                i += 1
            }

            html.push(`<pre class="notion-code-block"><code>${escapeHtml(codeLines.join('\n'))}</code></pre>`)
            i += 1
            continue
        }

        if (/^\s*---+\s*$/.test(line)) {
            html.push('<hr class="notion-rule">')
            i += 1
            continue
        }

        const headingMatch = line.match(/^\s*(#{1,3})\s+(.+)$/)

        if (headingMatch) {
            const level = headingMatch[1].length
            html.push(`<h${level} class="notion-heading notion-heading-${level}">${renderInlineMarkdown(headingMatch[2])}</h${level}>`)
            i += 1
            continue
        }

        const quoteMatch = line.match(/^\s*>\s?(.*)$/)

        if (quoteMatch) {
            const quoteLines = []

            while (i < lines.length) {
                const currentQuote = lines[i].match(/^\s*>\s?(.*)$/)

                if (!currentQuote) {
                    break
                }

                quoteLines.push(renderInlineMarkdown(currentQuote[1]))
                i += 1
            }

            html.push(`<blockquote class="notion-quote">${quoteLines.join('<br>')}</blockquote>`)
            continue
        }

        const taskMatch = line.match(/^\s*\[( |x)\]\s+(.*)$/i)

        if (taskMatch) {
            const items = []

            while (i < lines.length) {
                const currentTask = lines[i].match(/^\s*\[( |x)\]\s+(.*)$/i)

                if (!currentTask) {
                    break
                }

                const checked = currentTask[1].toLowerCase() === 'x'
                items.push(
                    `<li class="notion-task-item"><span class="notion-task-box">${checked ? 'x' : ''}</span><span>${renderInlineMarkdown(currentTask[2])}</span></li>`,
                )
                i += 1
            }

            html.push(`<ul class="notion-task-list">${items.join('')}</ul>`)
            continue
        }

        const bulletMatch = line.match(/^\s*-\s+(.*)$/)

        if (bulletMatch) {
            const items = []

            while (i < lines.length) {
                const currentBullet = lines[i].match(/^\s*-\s+(.*)$/)

                if (!currentBullet) {
                    break
                }

                items.push(`<li>${renderInlineMarkdown(currentBullet[1])}</li>`)
                i += 1
            }

            html.push(`<ul class="notion-list">${items.join('')}</ul>`)
            continue
        }

        const orderedMatch = line.match(/^\s*\d+\.\s+(.*)$/)

        if (orderedMatch) {
            const items = []

            while (i < lines.length) {
                const currentOrdered = lines[i].match(/^\s*\d+\.\s+(.*)$/)

                if (!currentOrdered) {
                    break
                }

                items.push(`<li>${renderInlineMarkdown(currentOrdered[1])}</li>`)
                i += 1
            }

            html.push(`<ol class="notion-list notion-list--ordered">${items.join('')}</ol>`)
            continue
        }

        if (line.trim() === '') {
            i += 1
            continue
        }

        const paragraphLines = [line]
        i += 1

        while (i < lines.length && lines[i].trim() !== '') {
            if (
                /^```/.test(lines[i].trim())
                || /^\s*---+\s*$/.test(lines[i])
                || /^\s*(#{1,3})\s+/.test(lines[i])
                || /^\s*>\s?/.test(lines[i])
                || /^\s*\[( |x)\]\s+/i.test(lines[i])
                || /^\s*-\s+/.test(lines[i])
                || /^\s*\d+\.\s+/.test(lines[i])
            ) {
                break
            }

            paragraphLines.push(lines[i])
            i += 1
        }

        html.push(`<p class="notion-paragraph">${renderInlineMarkdown(paragraphLines.join('\n'))}</p>`)
    }

    return html.join('')
}

const renderInlineMarkdown = (source) => {
    if (!source) {
        return ''
    }

    const placeholders = []
    let output = escapeHtml(source)

    output = output.replace(emojiPattern, (value) =>
        stash(placeholders, emojiImageTag(value)),
    )

    output = output.replace(/`([^`]+)`/g, (_, value) => stash(placeholders, `<code class="notion-inline-code">${escapeHtml(value)}</code>`))
    output = output.replace(/\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/g, (_, label, href) => {
        const safeHref = escapeAttribute(href)

        return stash(
            placeholders,
            `<a href="${safeHref}" target="_blank" rel="noreferrer noopener" class="notion-link">${renderInlineMarkdown(label)}</a>`,
        )
    })
    output = output.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>')
    output = output.replace(/\*([^*]+)\*/g, '<em>$1</em>')
    output = output.replace(/~~([^~]+)~~/g, '<s>$1</s>')
    output = output.replace(/\n/g, '<br>')

    return restorePlaceholders(output, placeholders)
}

const stash = (placeholders, value) => {
    const token = `__NOTE_TOKEN_${placeholders.length}__`
    placeholders.push(value)
    return token
}

const restorePlaceholders = (value, placeholders) =>
    placeholders.reduce((result, html, index) => result.replaceAll(`__NOTE_TOKEN_${index}__`, html), value)

const escapeHtml = (value) =>
    value
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#39;')

const escapeAttribute = (value) => escapeHtml(value)

const emojiPattern = /\p{Extended_Pictographic}(?:\uFE0F|\uFE0E)?(?:\p{Emoji_Modifier})?(?:\u200D\p{Extended_Pictographic}(?:\uFE0F|\uFE0E)?(?:\p{Emoji_Modifier})?)*/gu

const emojiImageTag = (emoji) => {
    const codepoints = Array.from(emoji)
        .map((char) => char.codePointAt(0)?.toString(16))
        .filter(Boolean)
        .join('-')

    const src = `https://cdn.jsdelivr.net/gh/jdecked/twemoji@latest/assets/svg/${codepoints}.svg`

    return `<img class="notion-emoji" src="${src}" alt="${escapeAttribute(emoji)}" draggable="false" loading="lazy" decoding="async">`
}
</script>
