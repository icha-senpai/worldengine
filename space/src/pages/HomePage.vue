<script setup lang="ts">
import { user, localPlay, signOut } from "../auth";
</script>
<template>
  <div class="welcome-page">
    <div class="welcome-container">
      <header class="welcome-header">
        <RouterLink to="/" class="welcome-wordmark">Dataverse</RouterLink>
        <div v-if="user && !localPlay" class="welcome-account">
          <span>{{ user.profile.name }}</span
          ><button class="button quiet" @click="signOut">Log Out</button>
        </div>
      </header>
      <main class="welcome-main">
        <div class="welcome-content">
          <h1>Dataverse</h1>
          <nav aria-label="Workspaces" class="welcome-links">
            <RouterLink to="/bitcraft/market" class="button quiet"
              >Bitcraft</RouterLink
            >
            <RouterLink to="/evergather" class="button quiet"
              >Evergather</RouterLink
            >
          </nav>
        </div>
      </main>
    </div>
  </div>
</template>
