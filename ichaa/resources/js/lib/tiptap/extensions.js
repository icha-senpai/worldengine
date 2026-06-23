import CharacterCount from '@tiptap/extension-character-count'
import CodeBlockLowlight from '@tiptap/extension-code-block-lowlight'
import Color from '@tiptap/extension-color'
import Highlight from '@tiptap/extension-highlight'
import Link from '@tiptap/extension-link'
import Placeholder from '@tiptap/extension-placeholder'
import Subscript from '@tiptap/extension-subscript'
import Superscript from '@tiptap/extension-superscript'
import { Table, TableCell, TableHeader, TableRow } from '@tiptap/extension-table'
import TaskItem from '@tiptap/extension-task-item'
import TaskList from '@tiptap/extension-task-list'
import TextAlign from '@tiptap/extension-text-align'
import { FontFamily, FontSize, TextStyle } from '@tiptap/extension-text-style'
import Typography from '@tiptap/extension-typography'
import Underline from '@tiptap/extension-underline'
import { createLowlight } from 'lowlight'
import StarterKit from '@tiptap/starter-kit'
import { DataverseImage } from '@/lib/tiptap/dataverseImage'
import { FieldColor } from '@/lib/tiptap/fieldColor'
import { SwapEnterBehavior } from '@/lib/tiptap/swapEnterBehavior'

const lowlight = createLowlight()

export const richTextRenderExtensions = [
    StarterKit,
    Underline,
    TextStyle,
    FontFamily,
    FontSize,
    Color,
    Highlight.configure({ multicolor: true }),
    FieldColor,
    Subscript,
    Superscript,
    Typography,
    Link.configure({
        autolink: false,
        openOnClick: false,
        linkOnPaste: false,
    }),
    TextAlign.configure({
        types: ['heading', 'paragraph'],
    }),
    TaskList,
    TaskItem.configure({
        nested: true,
    }),
    DataverseImage,
    Table,
    TableRow,
    TableHeader,
    TableCell,
    CodeBlockLowlight.configure({
        lowlight,
    }),
]

export const buildRichTextEditorExtensions = (placeholder = 'Start writing...') => [
    ...richTextRenderExtensions,
    SwapEnterBehavior,
    Placeholder.configure({
        placeholder,
    }),
    CharacterCount,
]

export const richTextEditorExtensions = buildRichTextEditorExtensions()
