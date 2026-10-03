<script setup>
import { ref } from "vue";
import { connectBitcraft } from "../../bitcraft";
import Modal from "../shared/Modal.vue";
const show = ref(false),
  command = ref(""),
  copied = ref(false),
  error = ref("");
async function open() {
  try {
    const conn = await connectBitcraft();
    command.value = `npm run bitcraft:admin -- ${conn.identity.toHexString()} Admin`;
    show.value = true;
  } catch {
    error.value = "The database connection is unavailable.";
  }
}
async function copy() {
  try {
    await navigator.clipboard.writeText(command.value);
    copied.value = true;
  } catch {
    error.value = "Select and copy the command below.";
  }
}
</script>
<template>
  <button type="button" class="app-btn app-btn--ghost" @click="open">
    Editor access
  </button>
  <span v-if="error" role="alert">{{ error }}</span>
  <Modal :show="show" @close="show = false">
    <section class="space-y-4 p-5" aria-labelledby="editor-access-title">
      <h2 id="editor-access-title" class="font-ui text-lg font-semibold">
        Enable guide editing
      </h2>
      <p>
        The site owner can approve this browser to create, edit and publish
        guides. Run this command from the Space folder, then refresh Guides.
      </p>
      <pre
        class="overflow-auto whitespace-pre-wrap break-all rounded border border-border p-3 text-sm"
        >{{ command }}</pre>
      <p class="text-sm text-muted-2">
        This access stays with this browser. Clearing its site data removes your
        saved identity.
      </p>
      <div class="flex gap-2">
        <button type="button" class="app-btn" @click="copy">
          {{ copied ? "Copied" : "Copy command" }}</button
        ><button
          type="button"
          class="app-btn app-btn--ghost"
          @click="show = false"
        >
          Close
        </button>
      </div>
    </section>
  </Modal>
</template>
