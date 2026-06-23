<template>
    <div v-if="controls.showAdvanced.value" class="editor-toolbar__advanced">
        <div class="editor-tool-column">
            <RichTextSelectMenu
                :label="controls.currentAlignmentLabel.value"
                :open="controls.showAlignmentMenu.value"
                :options="controls.alignmentOptions.value"
                tooltip="Text alignment"
                @toggle="controls.toggleAlignmentMenu"
            />

            <RichTextSelectMenu
                :label="controls.currentFontFamilyLabel.value"
                :open="controls.showFontFamilyMenu.value"
                :options="controls.fontFamilyOptions.value"
                tooltip="Font family"
                @toggle="controls.toggleFontFamilyMenu"
            />

            <RichTextSelectMenu
                :label="controls.currentFontSizeLabel.value"
                :open="controls.showFontSizeMenu.value"
                :options="controls.fontSizeOptions.value"
                tooltip="Font size"
                @toggle="controls.toggleFontSizeMenu"
            />
        </div>

        <div class="editor-tool-column">
            <button type="button" class="editor-tool editor-tool--color editor-tooltip-target editor-tooltip-target--bottom" data-tooltip="Text color" aria-label="Text color" :class="{ 'is-active': controls.activeColorMode.value === 'text' }" @mousedown.prevent @click="controls.toggleColorMode('text')">
                <span class="editor-tool__dot" :style="{ backgroundColor: controls.currentTextColor.value }" />
                <span>Text Color</span>
            </button>
            <button type="button" class="editor-tool editor-tool--color editor-tooltip-target editor-tooltip-target--bottom" data-tooltip="Highlight color" aria-label="Highlight color" :class="{ 'is-active': controls.activeColorMode.value === 'highlight' }" @mousedown.prevent @click="controls.toggleColorMode('highlight')">
                <span class="editor-tool__dot" :style="{ backgroundColor: controls.currentHighlightColor.value }" />
                <span>Highlight Color</span>
            </button>
            <button type="button" class="editor-tool editor-tool--color editor-tooltip-target editor-tooltip-target--bottom" data-tooltip="Field color" aria-label="Field color" :class="{ 'is-active': controls.activeColorMode.value === 'field' }" @mousedown.prevent @click="controls.toggleColorMode('field')">
                <span class="editor-tool__dot" :style="{ backgroundColor: controls.currentFieldColor.value }" />
                <span>Field Color</span>
            </button>
        </div>

        <div class="editor-tool-column">
            <button type="button" class="editor-tool editor-tooltip-target editor-tooltip-target--bottom" data-tooltip="Clear text color" aria-label="Clear text color" @mousedown.prevent @click="controls.clearTextColor">Clear Text</button>
            <button type="button" class="editor-tool editor-tooltip-target editor-tooltip-target--bottom" data-tooltip="Clear highlight color" aria-label="Clear highlight color" @mousedown.prevent @click="controls.clearHighlight">Clear Highlight</button>
            <button type="button" class="editor-tool editor-tooltip-target editor-tooltip-target--bottom" data-tooltip="Clear field color" aria-label="Clear field color" @mousedown.prevent @click="controls.clearFieldColor">Clear Field</button>
        </div>

        <div class="editor-tool-column">
            <button type="button" class="editor-tool editor-tooltip-target editor-tooltip-target--bottom" data-tooltip="Clear formatting" aria-label="Clear formatting" @mousedown.prevent @click="controls.clearFormatting">Clear Type</button>
            <button type="button" class="editor-tool editor-tooltip-target editor-tooltip-target--bottom" data-tooltip="Block quote" aria-label="Block quote" :class="{ 'is-active': controls.isActive('blockquote') }" @mousedown.prevent @click="controls.toggleBlockquote">Quote</button>
            <button type="button" class="editor-tool editor-tooltip-target editor-tooltip-target--bottom" data-tooltip="Insert divider" aria-label="Insert divider" @mousedown.prevent @click="controls.insertDivider">Divider</button>
            <button type="button" class="editor-tool editor-tooltip-target editor-tooltip-target--bottom" data-tooltip="Code block" aria-label="Code block" :class="{ 'is-active': controls.isActive('codeBlock') }" @mousedown.prevent @click="controls.toggleCodeBlock">Code</button>
            <button type="button" class="editor-tool editor-tooltip-target editor-tooltip-target--bottom" data-tooltip="Subscript" aria-label="Subscript" :class="{ 'is-active': controls.isActive('subscript') }" @mousedown.prevent @click="controls.toggleSubscript">Sub</button>
            <button type="button" class="editor-tool editor-tooltip-target editor-tooltip-target--bottom" data-tooltip="Superscript" aria-label="Superscript" :class="{ 'is-active': controls.isActive('superscript') }" @mousedown.prevent @click="controls.toggleSuperscript">Sup</button>
        </div>

        <div class="editor-tool-column editor-tool-column--wide">
            <div class="editor-tool-grid">
                <button type="button" class="editor-tool editor-tooltip-target editor-tooltip-target--bottom" data-tooltip="Insert image from URL" aria-label="Insert image from URL" @mousedown.prevent @click="controls.insertImageUrl">Image URL</button>
                <button type="button" class="editor-tool editor-tooltip-target editor-tooltip-target--bottom" data-tooltip="Upload an image" aria-label="Upload an image" @mousedown.prevent @click="controls.openImageUpload">Upload Image</button>
                <button type="button" class="editor-tool editor-tooltip-target editor-tooltip-target--bottom" data-tooltip="Choose existing media" aria-label="Choose existing media" @mousedown.prevent @click="controls.chooseMedia">Choose Media</button>
            </div>

            <RichTextSelectMenu
                :label="controls.currentImageAlignmentLabel.value"
                :open="controls.showImageAlignmentMenu.value"
                :options="controls.imageAlignmentOptions.value"
                tooltip="Image alignment"
                @toggle="controls.toggleImageAlignmentMenu"
            />

            <RichTextSelectMenu
                :label="controls.currentImageWidthLabel.value"
                :open="controls.showImageWidthMenu.value"
                :options="controls.imageWidthOptions.value"
                tooltip="Image width"
                @toggle="controls.toggleImageWidthMenu"
            />
        </div>

        <div class="editor-tool-column">
            <button type="button" class="editor-tool editor-tooltip-target editor-tooltip-target--bottom" data-tooltip="Insert table" aria-label="Insert table" @mousedown.prevent @click="controls.insertTable">Table</button>
            <button type="button" class="editor-tool editor-tooltip-target editor-tooltip-target--bottom" data-tooltip="Add table row" aria-label="Add table row" :disabled="!controls.isActive('table')" @mousedown.prevent @click="controls.runChain((chain) => chain.addRowAfter())">+Row</button>
            <button type="button" class="editor-tool editor-tooltip-target editor-tooltip-target--bottom" data-tooltip="Add table column" aria-label="Add table column" :disabled="!controls.isActive('table')" @mousedown.prevent @click="controls.runChain((chain) => chain.addColumnAfter())">+Col</button>
            <button type="button" class="editor-tool editor-tooltip-target editor-tooltip-target--bottom" data-tooltip="Delete table" aria-label="Delete table" :disabled="!controls.isActive('table')" @mousedown.prevent @click="controls.runChain((chain) => chain.deleteTable())">Del Tbl</button>
        </div>

        <div class="editor-tool-column">
            <div class="editor-tool editor-tool--static">
                {{ controls.characterCount.value }} chars
            </div>
            <div class="editor-tool editor-tool--static">
                {{ controls.wordCount.value }} words
            </div>
        </div>
    </div>
</template>

<script setup>
import RichTextSelectMenu from '@/Components/scaffold/RichTextSelectMenu.vue'

defineProps({
    controls: { type: Object, required: true },
})
</script>
