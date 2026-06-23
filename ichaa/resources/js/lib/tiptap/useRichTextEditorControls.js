import { computed, ref } from 'vue'
import {
    richTextAlignmentOptions,
    richTextColorPresets,
    richTextFontFamilyOptions,
    richTextFontSizeOptions,
    richTextImageAlignmentOptions,
    richTextImageWidths,
} from '@/lib/tiptap/editorConfig'

export function useRichTextEditorControls(editor, imageUploadInput, options = {}) {
    const showAdvanced = ref(false)
    const showTypeMenu = ref(false)
    const showAlignmentMenu = ref(false)
    const showFontFamilyMenu = ref(false)
    const showFontSizeMenu = ref(false)
    const showImageAlignmentMenu = ref(false)
    const showImageWidthMenu = ref(false)
    const activeColorMode = ref('')
    const savedSelection = ref(null)

    const canUndo = computed(() => editor.value?.can().chain().focus().undo().run() ?? false)
    const canRedo = computed(() => editor.value?.can().chain().focus().redo().run() ?? false)
    const isImageActive = computed(() => isActive('image'))
    const characterCount = computed(() => editor.value?.storage?.characterCount?.characters() ?? 0)
    const wordCount = computed(() => editor.value?.storage?.characterCount?.words() ?? 0)

    const currentTypeLabel = computed(() => {
        if (isActive('heading', { level: 1 })) return 'Heading 1'
        if (isActive('heading', { level: 2 })) return 'Heading 2'
        if (isActive('heading', { level: 3 })) return 'Heading 3'
        if (isActive('heading', { level: 4 })) return 'Heading 4'
        if (isActive('heading', { level: 5 })) return 'Heading 5'
        if (isActive('heading', { level: 6 })) return 'Heading 6'
        return 'Normal Text'
    })

    const currentAlignment = computed(() =>
        editor.value?.getAttributes('paragraph').textAlign
        || editor.value?.getAttributes('heading').textAlign
        || 'left'
    )

    const currentAlignmentLabel = computed(() =>
        richTextAlignmentOptions.find((option) => option.value === currentAlignment.value)?.label ?? 'Align Left'
    )

    const currentFontFamily = computed(() => editor.value?.getAttributes('textStyle').fontFamily ?? null)
    const currentFontFamilyLabel = computed(() =>
        richTextFontFamilyOptions.find((option) => option.value === currentFontFamily.value)?.label ?? 'Font: Default'
    )

    const currentFontSize = computed(() => editor.value?.getAttributes('textStyle').fontSize ?? null)
    const currentFontSizeLabel = computed(() =>
        richTextFontSizeOptions.find((option) => option.value === currentFontSize.value)?.label ?? 'Size: Default'
    )

    const currentImageAlignment = computed(() => editor.value?.getAttributes('image').align ?? 'left')
    const currentImageAlignmentLabel = computed(() =>
        richTextImageAlignmentOptions.find((option) => option.value === currentImageAlignment.value)?.label ?? 'Image Left'
    )

    const currentImageWidth = computed(() => editor.value?.getAttributes('image').width ?? '100%')
    const currentImageWidthLabel = computed(() => `Image Size: ${currentImageWidth.value === '100%' ? 'Default' : currentImageWidth.value}`)

    const currentTextColor = computed(() => editor.value?.getAttributes('textStyle').color || '#E7F9FF')
    const currentHighlightColor = computed(() => editor.value?.getAttributes('highlight').color || '#FCD34D')
    const currentFieldColor = computed(() => editor.value?.getAttributes('textStyle').fieldColor || '#1E4DF2')

    const typeOptions = computed(() => [
        { short: 'Aa', label: 'Normal Text', active: currentTypeLabel.value === 'Normal Text', action: setParagraph },
        { short: 'H1', label: 'Heading 1', active: isActive('heading', { level: 1 }), action: () => setHeading(1) },
        { short: 'H2', label: 'Heading 2', active: isActive('heading', { level: 2 }), action: () => setHeading(2) },
        { short: 'H3', label: 'Heading 3', active: isActive('heading', { level: 3 }), action: () => setHeading(3) },
        { short: 'H4', label: 'Heading 4', active: isActive('heading', { level: 4 }), action: () => setHeading(4) },
        { short: 'H5', label: 'Heading 5', active: isActive('heading', { level: 5 }), action: () => setHeading(5) },
        { short: 'H6', label: 'Heading 6', active: isActive('heading', { level: 6 }), action: () => setHeading(6) },
    ])

    const alignmentOptions = computed(() =>
        richTextAlignmentOptions.map((option) => ({
            ...option,
            active: currentAlignment.value === option.value,
            action: () => setAlignment(option.value),
        }))
    )

    const fontFamilyOptions = computed(() =>
        richTextFontFamilyOptions.map((option) => ({
            ...option,
            short: option.value ? 'F' : 'Aa',
            active: currentFontFamily.value === option.value,
            action: () => setFontFamily(option.value),
        }))
    )

    const fontSizeOptions = computed(() =>
        richTextFontSizeOptions.map((option) => ({
            ...option,
            short: option.value ? option.value.replace('px', '') : 'Df',
            active: currentFontSize.value === option.value,
            action: () => setFontSize(option.value),
        }))
    )

    const imageAlignmentOptions = computed(() =>
        richTextImageAlignmentOptions.map((option) => ({
            ...option,
            short: option.value.slice(0, 1).toUpperCase(),
            active: currentImageAlignment.value === option.value,
            disabled: !isImageActive.value,
            action: () => setImageAlign(option.value),
        }))
    )

    const imageWidthOptions = computed(() =>
        richTextImageWidths.map((value) => ({
            label: `Image Size: ${value === '100%' ? 'Default' : value}`,
            short: value === '100%' ? 'Df' : value.replace('%', ''),
            active: currentImageWidth.value === value,
            disabled: !isImageActive.value,
            action: () => setImageWidth(value),
        }))
    )

    const rememberSelection = () => {
        const selection = editor.value?.state.selection

        if (!selection) {
            savedSelection.value = null
            return
        }

        savedSelection.value = {
            from: selection.from,
            to: selection.to,
        }
    }

    const restoreSelection = (chain) => {
        if (savedSelection.value) {
            return chain.setTextSelection(savedSelection.value)
        }

        return chain
    }

    const closeMenus = () => {
        showTypeMenu.value = false
        showAlignmentMenu.value = false
        showFontFamilyMenu.value = false
        showFontSizeMenu.value = false
        showImageAlignmentMenu.value = false
        showImageWidthMenu.value = false
    }

    const toggleMenu = (menuRef) => {
        const nextState = !menuRef.value

        closeMenus()

        if (nextState) {
            rememberSelection()
            menuRef.value = true
        }
    }

    const runChain = (callback, { preserveSelection = false } = {}) => {
        const instance = editor.value

        if (!instance) {
            return
        }

        let chain = instance.chain().focus()

        if (preserveSelection) {
            chain = restoreSelection(chain)
        }

        callback(chain).run()
    }

    function isActive(nameOrAttributes, attributes = undefined) {
        const instance = editor.value

        if (!instance) {
            return false
        }

        if (typeof nameOrAttributes === 'string') {
            return instance.isActive(nameOrAttributes, attributes)
        }

        return instance.isActive(nameOrAttributes)
    }

    const toggleMark = (mark) => runChain((chain) => chain.toggleMark(mark))
    const toggleList = (list) => runChain((chain) => list === 'bulletList' ? chain.toggleBulletList() : chain.toggleOrderedList())
    const toggleTaskList = () => runChain((chain) => chain.toggleTaskList())

    const toggleAdvanced = () => {
        showAdvanced.value = !showAdvanced.value

        if (!showAdvanced.value) {
            closeMenus()
            activeColorMode.value = ''
        }
    }

    const toggleTypeMenu = () => toggleMenu(showTypeMenu)
    const toggleAlignmentMenu = () => toggleMenu(showAlignmentMenu)
    const toggleFontFamilyMenu = () => toggleMenu(showFontFamilyMenu)
    const toggleFontSizeMenu = () => toggleMenu(showFontSizeMenu)
    const toggleImageAlignmentMenu = () => toggleMenu(showImageAlignmentMenu)
    const toggleImageWidthMenu = () => toggleMenu(showImageWidthMenu)

    const setParagraph = () => {
        runChain((chain) => chain.setParagraph(), { preserveSelection: true })
        closeMenus()
    }

    const setHeading = (level) => {
        runChain((chain) => chain.toggleHeading({ level }), { preserveSelection: true })
        closeMenus()
    }

    const setAlignment = (alignment) => {
        runChain((chain) => chain.setTextAlign(alignment), { preserveSelection: true })
        showAlignmentMenu.value = false
    }

    const setFontFamily = (family) => {
        if (!family) {
            runChain((chain) => chain.unsetFontFamily(), { preserveSelection: true })
        } else {
            runChain((chain) => chain.setFontFamily(family), { preserveSelection: true })
        }

        showFontFamilyMenu.value = false
    }

    const setFontSize = (size) => {
        if (!size) {
            runChain((chain) => chain.unsetFontSize(), { preserveSelection: true })
        } else {
            runChain((chain) => chain.setFontSize(size), { preserveSelection: true })
        }

        showFontSizeMenu.value = false
    }

    const setImageWidth = (width) => {
        runChain((chain) => chain.updateAttributes('image', { width }), { preserveSelection: true })
        showImageWidthMenu.value = false
    }

    const toggleBlockquote = () => runChain((chain) => chain.toggleBlockquote())
    const insertDivider = () => runChain((chain) => chain.setHorizontalRule())
    const toggleCodeBlock = () => runChain((chain) => chain.toggleCodeBlock())
    const toggleSubscript = () => runChain((chain) => chain.toggleSubscript())
    const toggleSuperscript = () => runChain((chain) => chain.toggleSuperscript())

    const setLink = () => {
        const instance = editor.value

        if (!instance) {
            return
        }

        rememberSelection()

        const currentHref = instance.getAttributes('link').href ?? ''
        const href = window.prompt('Link URL', currentHref)

        if (href === null) {
            return
        }

        if (href.trim() === '') {
            runChain((chain) => chain.unsetLink(), { preserveSelection: true })
            return
        }

        runChain(
            (chain) => chain.extendMarkRange('link').setLink({
                href: href.trim(),
                target: '_blank',
                rel: 'noopener noreferrer',
            }),
            { preserveSelection: true },
        )
    }

    const insertImageUrl = () => {
        const src = window.prompt('Image URL')

        if (!src || !src.trim()) {
            return
        }

        runChain((chain) => chain.setImage({ src: src.trim(), alt: '', title: '', align: 'left', width: '50%' }))
    }

    const chooseMedia = () => {
        if (typeof options.onChooseMedia === 'function') {
            options.onChooseMedia()
            return
        }

        const src = window.prompt('Paste an existing media URL or asset path')

        if (!src || !src.trim()) {
            return
        }

        runChain((chain) => chain.setImage({ src: src.trim(), alt: '', title: '', align: 'left', width: '50%' }))
    }

    const openImageUpload = () => {
        imageUploadInput.value?.click()
    }

    const handleImageUpload = async (event) => {
        const [file] = Array.from(event.target.files ?? [])

        if (!file) {
            return
        }

        const src = await fileToDataUrl(file)
        runChain((chain) => chain.setImage({ src, alt: file.name, title: file.name, align: 'left', width: '50%' }))
        event.target.value = ''
    }

    const setImageAlign = (align) => {
        runChain((chain) => chain.updateAttributes('image', { align }), { preserveSelection: true })
        showImageAlignmentMenu.value = false
    }

    const toggleColorMode = (mode) => {
        if (activeColorMode.value === mode) {
            activeColorMode.value = ''
            return
        }

        rememberSelection()
        activeColorMode.value = mode
    }

    const openColorMode = (mode) => {
        rememberSelection()
        activeColorMode.value = mode
    }

    const currentColorForMode = (mode) => {
        switch (mode) {
            case 'highlight':
                return currentHighlightColor.value
            case 'field':
                return currentFieldColor.value
            default:
                return currentTextColor.value
        }
    }

    const applyColorSelection = (color) => {
        switch (activeColorMode.value) {
            case 'highlight':
                runChain((chain) => chain.setHighlight({ color }), { preserveSelection: true })
                break
            case 'field':
                runChain((chain) => chain.setFieldColor(color), { preserveSelection: true })
                break
            default:
                runChain((chain) => chain.setColor(color), { preserveSelection: true })
                break
        }
    }

    const applyHighlightColor = (color) => {
        runChain((chain) => chain.setHighlight({ color }), { preserveSelection: true })
    }

    const clearTextColor = () => runChain((chain) => chain.unsetColor(), { preserveSelection: true })
    const clearHighlight = () => runChain((chain) => chain.unsetHighlight(), { preserveSelection: true })
    const clearFieldColor = () => runChain((chain) => chain.unsetFieldColor(), { preserveSelection: true })
    const clearFormatting = () => runChain((chain) => chain.clearNodes().unsetAllMarks(), { preserveSelection: true })
    const insertTable = () => runChain((chain) => chain.insertTable({ rows: 3, cols: 3, withHeaderRow: true }))

    return {
        activeColorMode,
        alignmentOptions,
        applyColorSelection,
        applyHighlightColor,
        canRedo,
        canUndo,
        characterCount,
        chooseMedia,
        clearFieldColor,
        clearFormatting,
        clearHighlight,
        clearTextColor,
        colorPresets: richTextColorPresets,
        currentAlignmentLabel,
        currentColorForMode,
        currentFieldColor,
        currentFontFamilyLabel,
        currentFontSizeLabel,
        currentHighlightColor,
        currentImageAlignmentLabel,
        currentImageWidthLabel,
        currentTextColor,
        currentTypeLabel,
        fontFamilyOptions,
        fontSizeOptions,
        handleImageUpload,
        imageAlignmentOptions,
        imageWidthOptions,
        insertDivider,
        insertImageUrl,
        insertTable,
        isActive,
        openImageUpload,
        openColorMode,
        runChain,
        setHeading,
        setImageAlign,
        setLink,
        setParagraph,
        showAdvanced,
        showAlignmentMenu,
        showFontFamilyMenu,
        showFontSizeMenu,
        showImageAlignmentMenu,
        showImageWidthMenu,
        showTypeMenu,
        toggleAdvanced,
        toggleAlignmentMenu,
        toggleBlockquote,
        toggleCodeBlock,
        toggleColorMode,
        toggleFontFamilyMenu,
        toggleFontSizeMenu,
        toggleImageAlignmentMenu,
        toggleImageWidthMenu,
        toggleList,
        toggleMark,
        toggleSubscript,
        toggleSuperscript,
        toggleTaskList,
        toggleTypeMenu,
        typeOptions,
        wordCount,
    }
}

function fileToDataUrl(file) {
    return new Promise((resolve, reject) => {
        const reader = new FileReader()
        reader.onload = () => resolve(String(reader.result))
        reader.onerror = () => reject(reader.error)
        reader.readAsDataURL(file)
    })
}
