<script setup lang="ts">
import { computed, nextTick, ref, watch } from "vue";
import { discordLinkConfigured, discordLinkUrl } from "../../auth";
import { evergather, evergatherConnected, revision } from "../../evergather";
import { autoRepeat } from "../../evergather/playSession";

const busy = ref(false),
  error = ref("");
const form = ref<HTMLFormElement>(),
  choiceDialog = ref<HTMLDialogElement>();
const account = computed(() => {
  revision.value;
  return [...(evergather.value?.db.myDiscordAccount.iter() ?? [])][0];
});
const challenge = computed(() => {
  revision.value;
  return [...(evergather.value?.db.myDiscordChallenge.iter() ?? [])][0];
});
watch(
  () => {
    revision.value;
    return [
      ...(evergather.value?.db.myPlayer.iter() ?? []),
    ][0]?.owner.toHexString();
  },
  () => {
    autoRepeat.value = false;
  },
);
watch(
  () => challenge.value?.status,
  async (status) => {
    await nextTick();
    if (status === "choose" && !choiceDialog.value?.open)
      choiceDialog.value?.showModal();
    else if (status !== "choose") choiceDialog.value?.close();
  },
  { immediate: true },
);
async function run(action: () => Promise<void>) {
  if (busy.value || !evergatherConnected.value) return;
  busy.value = true;
  error.value = "";
  autoRepeat.value = false;
  try {
    await action();
  } catch (reason) {
    error.value =
      reason instanceof Error
        ? reason.message
        : "Account linking could not complete. Try again.";
  } finally {
    busy.value = false;
  }
}
async function signIn() {
  await run(async () => {
    const proof = [...crypto.getRandomValues(new Uint8Array(32))]
      .map((b) => b.toString(16).padStart(2, "0"))
      .join("");
    await evergather.value!.reducers.beginDiscordLink({ proof });
    await nextTick();
    if (!challenge.value || challenge.value.proof !== proof)
      throw new Error("The sign-in request could not load. Try again.");
    form.value?.requestSubmit();
  });
}
async function choose(useDiscordCharacter: boolean) {
  await run(async () => {
    if (!challenge.value) return;
    await evergather.value!.reducers.confirmDiscordCharacter({
      challengeId: challenge.value.challengeId,
      useDiscordCharacter,
    });
  });
}
</script>

<template>
  <section
    v-if="discordLinkConfigured"
    class="surface ui-panel account-panel"
    aria-label="Evergather account"
  >
    <div>
      <strong>{{
        account?.linked
          ? "Discord account linked"
          : "Take your character with you"
      }}</strong>
      <p>
        {{
          account?.linked
            ? "Sign in with the same Discord account on your phone or another browser to continue this character."
            : account?.needsSignIn
              ? "Sign in with Discord to open your saved character."
              : "Link this character to Discord to keep playing on another device. Guild membership is not required."
        }}
      </p>
    </div>
    <button
      v-if="account?.linked"
      class="app-btn app-btn--ghost"
      :disabled="busy || !evergatherConnected"
      @click="run(() => evergather!.reducers.signOutDiscord({}))"
    >
      Sign out
    </button>
    <button
      v-else
      class="app-btn app-btn--primary"
      :disabled="busy || !evergatherConnected"
      @click="signIn"
    >
      {{
        busy
          ? "Opening Discord…"
          : account?.needsSignIn
            ? "Sign in with Discord"
            : "Link or sign in with Discord"
      }}
    </button>
    <p v-if="error" class="notice" role="alert">{{ error }}</p>
    <form
      ref="form"
      :action="discordLinkUrl + '/auth/discord/start'"
      method="post"
      hidden
    >
      <input type="hidden" name="application" value="evergather" />
      <input
        type="hidden"
        name="challenge_id"
        :value="challenge?.challengeId.toString() ?? ''"
      />
      <input type="hidden" name="proof" :value="challenge?.proof ?? ''" />
    </form>
    <dialog
      ref="choiceDialog"
      aria-labelledby="discord-character-choice"
      @cancel.prevent="choose(false)"
    >
      <h2 id="discord-character-choice">Choose which character to play</h2>
      <p>
        This browser has a character, and your Discord account already has a
        different character. Your characters will stay separate and both keep
        their progress.
      </p>
      <p>
        Use your Discord character here, or keep playing this browser's
        character without linking it to that account.
      </p>
      <div class="account-actions">
        <button
          class="app-btn app-btn--primary"
          :disabled="busy"
          @click="choose(true)"
        >
          Use my Discord character
        </button>
        <button
          class="app-btn app-btn--ghost"
          :disabled="busy"
          @click="choose(false)"
        >
          Keep this browser character
        </button>
      </div>
      <p v-if="error" role="alert">{{ error }}</p>
    </dialog>
  </section>
</template>

<style scoped>
.account-panel {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 1rem;
  margin-bottom: 1rem;
}
.account-panel > div:first-child {
  flex: 1;
  min-width: min(100%, 16rem);
}
.account-panel p {
  margin: 0.4rem 0 0;
  color: var(--text-muted-2);
}
dialog {
  margin: auto;
  max-width: min(34rem, calc(100vw - 2rem));
  max-height: calc(100dvh - 2rem);
  overflow: auto;
  padding: 1.5rem;
  border: 1px solid var(--border-color);
  border-radius: 0.75rem;
  background: var(--bg-surface);
  color: var(--text-primary);
}
dialog::backdrop {
  background: rgb(0 0 0 / 0.65);
}
.account-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 0.75rem;
  margin-top: 1.25rem;
}
</style>
