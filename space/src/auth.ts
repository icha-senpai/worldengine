import { shallowRef } from "vue";
import { UserManager, WebStorageStateStore, type User } from "oidc-client-ts";

export const user = shallowRef<User | null>(null);
export const authError = shallowRef("");
export const localPlay = import.meta.env.VITE_EVERGATHER_LOCAL_PLAY === "true";
export const authConfigured = Boolean(
  import.meta.env.VITE_AUTH_CLIENT_ID?.trim(),
);
const manager =
  authConfigured && !localPlay
    ? new UserManager({
        authority:
          import.meta.env.VITE_AUTH_AUTHORITY ||
          "https://auth.spacetimedb.com/oidc",
        client_id: import.meta.env.VITE_AUTH_CLIENT_ID,
        redirect_uri: `${location.origin}/auth/callback`,
        post_logout_redirect_uri: `${location.origin}/evergather`,
        response_type: "code",
        scope: "openid profile",
        userStore: new WebStorageStateStore({ store: window.sessionStorage }),
        automaticSilentRenew: false,
      })
    : null;

export async function initializeAuth() {
  if (!manager) return;
  try {
    if (location.pathname === "/auth/callback") {
      user.value = await manager.signinRedirectCallback();
      history.replaceState({}, "", "/evergather");
    } else {
      const existing = await manager.getUser();
      user.value = existing?.expired ? null : existing;
    }
    manager.events.addUserLoaded((value) => {
      user.value = value;
    });
    manager.events.addUserUnloaded(() => {
      user.value = null;
    });
    manager.events.addAccessTokenExpired(() => {
      user.value = null;
      authError.value = "Your session expired. Sign in again to continue.";
    });
  } catch {
    authError.value = "Sign-in could not be completed. Please try again.";
    history.replaceState({}, "", "/evergather");
  }
}
export async function signIn() {
  if (!manager) return;
  try {
    await manager.signinRedirect();
  } catch {
    authError.value = "The sign-in service could not be reached.";
  }
}
export async function signOut() {
  user.value = null;
  if (!manager) return;
  await manager.removeUser();
  try {
    await manager.signoutRedirect();
  } catch {
    authError.value =
      "Signed out locally. The sign-in service could not be reached.";
  }
}
