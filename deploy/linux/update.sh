#!/usr/bin/env bash
set -euo pipefail

script=$(readlink -f "${BASH_SOURCE[0]}")
checkout=${DATAVERSE_CHECKOUT:-$(cd "$(dirname "$script")/../.." && pwd)}
state="$HOME/.local/state/dataverse-update"
umask 077
mkdir -p "$state"
exec 9>"$state/update.lock"
flock -n 9 || { echo 'Another server update is already running.' >&2; exit 1; }
cd "$checkout"

if [[ " ${*} " != *" --no-pull "* ]]; then
    if [[ -n "$(git status --porcelain --untracked-files=no)" ]]; then
        echo 'Server source has local changes; leaving them intact. Review git status before updating.' >&2
        exit 1
    fi
    if [[ "$(git config --bool core.sparseCheckout || true)" == true ]]; then
        git sparse-checkout add deploy/linux shared
    fi
    git pull --ff-only
fi

if ! command -v node >/dev/null || [[ "$(node -p 'process.versions.node.split(".")[0]')" != 24 ]]; then
    export NVM_DIR="$HOME/.nvm"
    [[ -s "$NVM_DIR/nvm.sh" ]] || { echo 'Node 24 is required; NVM was not found.' >&2; exit 1; }
    # NVM uses optional, unset shell variables internally.
    set +u
    source "$NVM_DIR/nvm.sh"
    nvm use --silent 24
    set -u
fi
export PATH="$(dirname "$(command -v node)"):$HOME/.cargo/bin:$PATH"
export DATAVERSE_UPDATE_LOCKED=1
export DATAVERSE_CHECKOUT="$checkout"
runner="$checkout/deploy/linux/update.mjs"
[[ -f "$runner" ]] || runner="$HOME/.local/share/dataverse-update/update.mjs"
exec node "$runner" "$@"
