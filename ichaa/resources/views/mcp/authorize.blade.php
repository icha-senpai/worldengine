<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Authorize Application - {{ config('app.name', 'MCP Server') }}</title>

    <link rel="icon" type="image/png" href="/favicon-96x96.png" sizes="96x96" />
    <link rel="icon" type="image/svg+xml" href="/favicon.svg" />
    <link rel="shortcut icon" href="/favicon.ico" />
    <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png" />
    <meta name="apple-mobile-web-app-title" content="Authorize MCP" />
    <link rel="manifest" href="/site.webmanifest" />

    @vite(['resources/css/app.css'])

    <style>
        body {
            margin: 0;
            min-height: 100vh;
            background:
                radial-gradient(circle at top, rgb(var(--accent-cyan-rgb) / 0.12), transparent 32rem),
                linear-gradient(180deg, rgb(var(--bg-canvas-2-rgb) / 0.98), rgb(var(--bg-canvas-rgb) / 1));
            color: var(--text-primary);
            font-family: var(--font-ui);
        }

        .mcp-authorize-shell {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
        }

        .mcp-authorize-card {
            width: min(100%, 560px);
            border: 1px solid rgb(var(--border-color-2-rgb) / 0.42);
            border-radius: 20px;
            background:
                linear-gradient(180deg, rgb(var(--bg-surface-3-rgb) / 0.34), rgb(var(--bg-surface-rgb) / 0.96)),
                var(--bg-surface-2);
            box-shadow:
                0 28px 64px rgb(0 0 0 / 0.34),
                inset 0 1px 0 rgb(var(--text-primary-rgb) / 0.05);
            overflow: hidden;
        }

        .mcp-authorize-card__hero {
            padding: 28px 28px 22px;
            border-bottom: 1px solid rgb(var(--border-color-rgb) / 0.34);
        }

        .mcp-authorize-card__eyebrow {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 14px;
            font-size: 11px;
            letter-spacing: 0.18em;
            text-transform: uppercase;
            color: var(--text-muted-2);
        }

        .mcp-authorize-card__eyebrow::before {
            content: "";
            width: 8px;
            height: 8px;
            border-radius: 999px;
            background: var(--accent-cyan);
            box-shadow: 0 0 0 6px rgb(var(--accent-cyan-rgb) / 0.12);
        }

        .mcp-authorize-card__title {
            margin: 0 0 10px;
            font-size: clamp(28px, 4vw, 36px);
            line-height: 1.05;
            font-weight: 300;
            letter-spacing: 0.02em;
            color: var(--text-primary-2);
        }

        .mcp-authorize-card__copy {
            margin: 0;
            font-size: 14px;
            line-height: 1.7;
            color: var(--text-muted);
        }

        .mcp-authorize-card__body {
            display: grid;
            gap: 18px;
            padding: 24px 28px 28px;
        }

        .mcp-authorize-panel {
            border: 1px solid rgb(var(--border-color-rgb) / 0.36);
            border-radius: 16px;
            background: linear-gradient(180deg, rgb(var(--bg-surface-3-rgb) / 0.22), rgb(var(--bg-surface-rgb) / 0.94));
            padding: 16px 18px;
        }

        .mcp-authorize-panel__label {
            margin: 0 0 8px;
            font-size: 11px;
            letter-spacing: 0.16em;
            text-transform: uppercase;
            color: var(--text-muted-3);
        }

        .mcp-authorize-panel__value {
            margin: 0;
            font-size: 15px;
            line-height: 1.6;
            color: var(--text-primary);
            word-break: break-word;
        }

        .mcp-authorize-scope-list {
            display: grid;
            gap: 10px;
            margin: 0;
            padding: 0;
            list-style: none;
        }

        .mcp-authorize-scope {
            display: flex;
            gap: 12px;
            align-items: flex-start;
        }

        .mcp-authorize-scope__dot {
            width: 10px;
            height: 10px;
            margin-top: 7px;
            border-radius: 999px;
            flex: none;
            background: var(--accent-cyan);
            box-shadow: 0 0 0 6px rgb(var(--accent-cyan-rgb) / 0.1);
        }

        .mcp-authorize-scope__text {
            margin: 0;
            font-size: 14px;
            line-height: 1.7;
            color: var(--text-muted);
        }

        .mcp-authorize-actions {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 12px;
        }

        .mcp-authorize-actions form {
            margin: 0;
        }

        .mcp-authorize-button {
            width: 100%;
        }

        .mcp-authorize-button__spinner {
            width: 14px;
            height: 14px;
            border: 2px solid rgb(var(--accent-cyan-rgb) / 0.2);
            border-top-color: currentColor;
            border-radius: 999px;
            animation: mcp-authorize-spin 0.7s linear infinite;
            display: none;
        }

        .mcp-authorize-button.is-loading .mcp-authorize-button__spinner {
            display: inline-block;
        }

        .mcp-authorize-button.is-loading .mcp-authorize-button__label {
            opacity: 0.82;
        }

        @keyframes mcp-authorize-spin {
            to {
                transform: rotate(360deg);
            }
        }

        @media (max-width: 640px) {
            .mcp-authorize-shell {
                padding: 14px;
            }

            .mcp-authorize-card__hero,
            .mcp-authorize-card__body {
                padding-left: 18px;
                padding-right: 18px;
            }

            .mcp-authorize-actions {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <main class="mcp-authorize-shell">
        <section class="mcp-authorize-card">
            <header class="mcp-authorize-card__hero">
                <div class="mcp-authorize-card__eyebrow">MCP connection approval</div>
                <h1 class="mcp-authorize-card__title">Authorize {{ $client->name }}</h1>
                <p class="mcp-authorize-card__copy">
                    This lets the client use Dataverse MCP tools through your account. Review the connection below, then approve or deny it.
                </p>
            </header>

            <div class="mcp-authorize-card__body">
                <section class="mcp-authorize-panel" aria-labelledby="mcp-authorize-client">
                    <p class="mcp-authorize-panel__label" id="mcp-authorize-client">Client</p>
                    <p class="mcp-authorize-panel__value">{{ $client->name }}</p>
                </section>

                <section class="mcp-authorize-panel" aria-labelledby="mcp-authorize-user">
                    <p class="mcp-authorize-panel__label" id="mcp-authorize-user">Signed in as</p>
                    <p class="mcp-authorize-panel__value">{{ $user->email }}</p>
                </section>

                <section class="mcp-authorize-panel" aria-labelledby="mcp-authorize-access">
                    <p class="mcp-authorize-panel__label" id="mcp-authorize-access">Requested access</p>

                    @if (count($scopes) > 0)
                        <ul class="mcp-authorize-scope-list">
                            @foreach ($scopes as $scope)
                                <li class="mcp-authorize-scope">
                                    <span class="mcp-authorize-scope__dot" aria-hidden="true"></span>
                                    <p class="mcp-authorize-scope__text">{{ $scope->description }}</p>
                                </li>
                            @endforeach
                        </ul>
                    @else
                        <p class="mcp-authorize-scope__text">Use the available MCP functionality exposed by this Dataverse workspace.</p>
                    @endif
                </section>

                <div class="mcp-authorize-actions">
                    <form method="POST" action="{{ route('passport.authorizations.deny') }}" id="denyForm">
                        @csrf
                        @method('DELETE')
                        <input type="hidden" name="state" value="{{ request()->input('state', '') }}">
                        <input type="hidden" name="client_id" value="{{ $client->id }}">
                        <input type="hidden" name="auth_token" value="{{ $authToken }}">

                        <button type="submit" class="app-btn app-btn--md app-btn--ghost mcp-authorize-button" id="denyButton">
                            <span class="mcp-authorize-button__label">Deny access</span>
                        </button>
                    </form>

                    <form method="POST" action="{{ route('passport.authorizations.approve') }}" id="authorizeForm">
                        @csrf
                        <input type="hidden" name="state" value="{{ request()->input('state', '') }}">
                        <input type="hidden" name="client_id" value="{{ $client->id }}">
                        <input type="hidden" name="auth_token" value="{{ $authToken }}">

                        <button type="submit" class="app-btn app-btn--md app-btn--primary mcp-authorize-button" id="authorizeButton">
                            <span class="mcp-authorize-button__label" id="authorizeLabel">Authorize connection</span>
                            <span class="mcp-authorize-button__spinner" id="authorizeSpinner" aria-hidden="true"></span>
                        </button>
                    </form>
                </div>
            </div>
        </section>
    </main>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const authorizeForm = document.getElementById('authorizeForm');
            const authorizeButton = document.getElementById('authorizeButton');
            const authorizeLabel = document.getElementById('authorizeLabel');
            const denyForm = document.getElementById('denyForm');
            const denyButton = document.getElementById('denyButton');

            const closeIfPopup = function () {
                if (window.opener && !window.opener.closed) {
                    window.close();
                }
            };

            authorizeForm.addEventListener('submit', function () {
                authorizeButton.disabled = true;
                authorizeButton.classList.add('is-loading');
                authorizeLabel.textContent = 'Authorizing...';

                window.setTimeout(closeIfPopup, 1200);
            });

            denyForm.addEventListener('submit', function () {
                denyButton.disabled = true;
                window.setTimeout(closeIfPopup, 1200);
            });
        });
    </script>
</body>
</html>
