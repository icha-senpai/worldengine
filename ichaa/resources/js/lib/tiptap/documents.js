import { hasRichDocumentContent, isRichDocument } from '@/Components/scaffold/formatters'

export const emptyRichDocument = () => ({
    type: 'doc',
    content: [],
})

export const documentFromPlainText = (value) => ({
    type: 'doc',
    content: String(value ?? '')
        .split(/\r?\n{2,}/)
        .map((paragraph) => paragraph.trim())
        .filter(Boolean)
        .map((paragraph) => ({
            type: 'paragraph',
            content: [{ type: 'text', text: paragraph }],
        })),
})

export const normalizeRichDocument = (value) => {
    if (isRichDocument(value)) {
        return value
    }

    if (typeof value === 'string') {
        const trimmed = value.trim()

        if (trimmed === '') {
            return emptyRichDocument()
        }

        if ((trimmed.startsWith('{') && trimmed.endsWith('}')) || (trimmed.startsWith('[') && trimmed.endsWith(']'))) {
            try {
                const parsed = JSON.parse(trimmed)

                if (isRichDocument(parsed)) {
                    return parsed
                }
            } catch {
                return documentFromPlainText(value)
            }
        }

        return documentFromPlainText(value)
    }

    return emptyRichDocument()
}

export const prepareRichDocumentForSubmit = (value, emptyValue = null) => {
    const normalized = normalizeRichDocument(value)

    return hasRichDocumentContent(normalized) ? normalized : emptyValue
}
