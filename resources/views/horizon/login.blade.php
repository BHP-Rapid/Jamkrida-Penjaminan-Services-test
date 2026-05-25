<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Horizon Login</title>
    <style>
        :root {
            font-family: ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            color-scheme: light;
            --ink: #0f172a;
            --muted: #64748b;
            --line: #d9e2ec;
            --surface: #ffffff;
            --soft: #f8fafc;
            --horizon: #ff2d20;
            --horizon-dark: #b91c1c;
            --teal: #0f766e;
        }

        * {
            box-sizing: border-box;
        }

        body {
            min-height: 100vh;
            margin: 0;
            display: grid;
            place-items: center;
            padding: 32px 16px;
            background:
                linear-gradient(90deg, rgba(15, 23, 42, 0.05) 1px, transparent 1px),
                linear-gradient(180deg, rgba(15, 23, 42, 0.05) 1px, transparent 1px),
                linear-gradient(135deg, #f8fafc 0%, #eef2f7 48%, #ffe8e2 100%);
            background-size: 32px 32px, 32px 32px, cover;
            color: var(--ink);
        }

        main {
            width: min(100%, 960px);
            min-height: 560px;
            display: grid;
            grid-template-columns: minmax(0, 1.05fr) minmax(360px, 0.95fr);
            border: 1px solid rgba(15, 23, 42, 0.12);
            border-radius: 8px;
            background: var(--surface);
            box-shadow: 0 28px 90px rgba(15, 23, 42, 0.16);
            overflow: hidden;
        }

        .brand-panel {
            position: relative;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            gap: 36px;
            padding: 40px;
            background:
                linear-gradient(135deg, rgba(255, 45, 32, 0.86), rgba(15, 23, 42, 0.94) 54%, rgba(15, 118, 110, 0.88)),
                repeating-linear-gradient(135deg, rgba(255, 255, 255, 0.12) 0 1px, transparent 1px 16px);
            color: #ffffff;
        }

        .brand-panel::after {
            content: "";
            position: absolute;
            inset: 0;
            background: linear-gradient(180deg, transparent 0%, rgba(2, 6, 23, 0.28) 100%);
            pointer-events: none;
        }

        .brand-panel > * {
            position: relative;
            z-index: 1;
        }

        .logo-row {
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .logo-mark {
            width: 52px;
            height: 52px;
            display: grid;
            place-items: center;
            border: 1px solid rgba(255, 255, 255, 0.34);
            border-radius: 8px;
            background: rgba(255, 255, 255, 0.14);
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.2);
        }

        .logo-mark svg {
            width: 30px;
            height: 30px;
        }

        .brand-kicker,
        .eyebrow,
        .signal span,
        .meta {
            letter-spacing: 0;
        }

        .brand-kicker {
            color: rgba(255, 255, 255, 0.72);
            font-size: 13px;
            font-weight: 700;
        }

        .brand-name {
            margin-top: 4px;
            font-size: 16px;
            font-weight: 700;
        }

        h1 {
            max-width: 420px;
            margin: 48px 0 0;
            font-size: clamp(38px, 6vw, 64px);
            line-height: 1.2;
            font-weight: 800;
        }

        p {
            margin: 8px 0 0;
            color: var(--muted);
            font-size: 15px;
            line-height: 1.6;
        }

        .brand-panel p {
            max-width: 360px;
            color: rgba(255, 255, 255, 0.76);
        }

        .signals {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 12px;
        }

        .signal {
            min-width: 0;
            border: 1px solid rgba(255, 255, 255, 0.18);
            border-radius: 8px;
            background: rgba(15, 23, 42, 0.28);
            padding: 14px;
            backdrop-filter: blur(12px);
        }

        .signal span {
            display: block;
            color: rgba(255, 255, 255, 0.62);
            font-size: 12px;
            font-weight: 700;
        }

        .signal strong {
            display: block;
            margin-top: 6px;
            overflow: hidden;
            color: #ffffff;
            font-size: 15px;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .form-panel {
            display: flex;
            flex-direction: column;
            justify-content: center;
            padding: 44px;
            background:
                linear-gradient(180deg, rgba(248, 250, 252, 0.8), rgba(255, 255, 255, 0.94)),
                var(--surface);
        }

        .eyebrow {
            margin: 0 0 8px;
            color: var(--horizon-dark);
            font-size: 12px;
            font-weight: 800;
        }

        h2 {
            margin: 0;
            color: var(--ink);
            font-size: 30px;
            line-height: 1.2;
            font-weight: 800;
        }

        form {
            margin-top: 32px;
            display: grid;
            gap: 16px;
        }

        label {
            display: grid;
            gap: 8px;
            color: #334155;
            font-size: 13px;
            font-weight: 700;
        }

        .field {
            position: relative;
        }

        .field svg {
            position: absolute;
            top: 50%;
            left: 14px;
            width: 18px;
            height: 18px;
            color: #94a3b8;
            transform: translateY(-50%);
        }

        input {
            width: 100%;
            border: 1px solid var(--line);
            border-radius: 6px;
            background: #ffffff;
            color: var(--ink);
            padding: 13px 14px 13px 44px;
            font-size: 15px;
            outline: none;
            transition: border-color 150ms ease, box-shadow 150ms ease, background 150ms ease;
        }

        input:focus {
            border-color: var(--teal);
            background: #fbfeff;
            box-shadow: 0 0 0 4px rgba(15, 118, 110, 0.12);
        }

        button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            min-height: 48px;
            border: 1px solid transparent;
            border-radius: 6px;
            background: var(--horizon);
            color: #ffffff;
            padding: 13px 18px;
            font-weight: 800;
            cursor: pointer;
            transition: background 150ms ease, transform 150ms ease, box-shadow 150ms ease;
        }

        button:hover {
            background: var(--horizon-dark);
            box-shadow: 0 12px 24px rgba(185, 28, 28, 0.18);
            transform: translateY(-1px);
        }

        button:focus-visible {
            outline: none;
            box-shadow: 0 0 0 4px rgba(255, 45, 32, 0.2);
        }

        button svg {
            width: 18px;
            height: 18px;
        }

        .error {
            border: 1px solid #fecaca;
            border-radius: 6px;
            background: #fff1f2;
            color: #991b1b;
            padding: 12px 14px;
            font-size: 14px;
        }

        .meta {
            color: #94a3b8;
            font-size: 12px;
            text-align: center;
        }

        @media (max-width: 840px) {
            main {
                grid-template-columns: 1fr;
                min-height: 0;
            }

            .brand-panel {
                padding: 32px;
            }

            h1 {
                margin-top: 32px;
                font-size: 42px;
            }
        }

        @media (max-width: 520px) {
            body {
                padding: 16px;
            }

            .brand-panel,
            .form-panel {
                padding: 24px;
            }

            .signals {
                grid-template-columns: 1fr;
            }

            h1 {
                font-size: 36px;
            }

            h2 {
                font-size: 26px;
            }
        }
    </style>
</head>
<body>
    <main>
        @php
            $displayPath = $horizonPath !== '' ? '/'.$horizonPath : '/';
        @endphp

        <section class="brand-panel" aria-labelledby="horizon-title">
            <div>
                <div class="logo-row">
                    <div class="logo-mark" aria-hidden="true">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 30 30" fill="none">
                            <path fill="currentColor" d="M5.26 26.41C2.04 23.66 0 19.57 0 15c0-4.14 1.68-7.89 4.39-10.61C7.11 1.68 10.86 0 15 0c8.28 0 15 6.72 15 15s-6.72 15-15 15c-3.72 0-7.12-1.35-9.74-3.59ZM4.04 15.92C5.7 14.46 6.87 12.5 10 12.5c5 0 5 5 10 5 3.13 0 4.3-1.96 5.96-3.42C25.49 8.43 20.76 4 15 4 8.92 4 4 8.92 4 15c0 .31.01.62.04.92Z"/>
                        </svg>
                    </div>
                    <div>
                        <div class="brand-kicker">Laravel Horizon</div>
                        <div class="brand-name">Jamkrida Penjaminan Services</div>
                    </div>
                </div>

                <h1 id="horizon-title">Horizon</h1>
                <p>Dashboard antrian untuk memantau worker, batch, dan job yang berjalan.</p>
            </div>

            <div class="signals" aria-label="Horizon context">
                <div class="signal">
                    <span>Path</span>
                    <strong>{{ $displayPath }}</strong>
                </div>
                <div class="signal">
                    <span>Access</span>
                    <strong>Session login</strong>
                </div>
            </div>
        </section>

        <section class="form-panel" aria-label="Horizon login form">
            <div>
                <p class="eyebrow">OPERATOR ACCESS</p>
                <h2>Masuk ke Horizon</h2>
                <p>Gunakan kredensial Horizon yang sudah dikonfigurasi untuk aplikasi ini.</p>
            </div>

            <form method="POST" action="{{ $loginAction }}">
                @csrf

                @if ($errors->any())
                    <div class="error" role="alert">{{ $errors->first() }}</div>
                @endif

                <label>
                    Username
                    <span class="field">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                            <path d="M10 8a3 3 0 1 0 0-6 3 3 0 0 0 0 6ZM3.47 17.31A8.97 8.97 0 0 1 10 14.5c2.56 0 4.86 1.07 6.5 2.79.33.35.09.96-.4.96H3.88c-.48 0-.73-.6-.41-.94Z"/>
                        </svg>
                        <input
                            type="text"
                            name="username"
                            value="{{ old('username') }}"
                            autocomplete="username"
                            autofocus
                            required
                        >
                    </span>
                </label>

                <label>
                    Password
                    <span class="field">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                            <path fill-rule="evenodd" d="M8 7a4 4 0 1 1 7.45 2.03l-1.74 1.74a1 1 0 0 1-.7.29H11.5v1.5a1 1 0 0 1-1 1H9v1.5a1 1 0 0 1-1 1H6.5v1.5a1 1 0 0 1-1 1H2.75A.75.75 0 0 1 2 17.81v-2.44a1 1 0 0 1 .29-.7l5.32-5.32A4 4 0 0 1 8 7Zm4-2.25a2.25 2.25 0 1 0 0 4.5 2.25 2.25 0 0 0 0-4.5Z" clip-rule="evenodd"/>
                        </svg>
                        <input
                            type="password"
                            name="password"
                            autocomplete="current-password"
                            required
                        >
                    </span>
                </label>

                <button type="submit">
                    <span>Masuk</span>
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                        <path fill-rule="evenodd" d="M3 10a.75.75 0 0 1 .75-.75h9.69l-3.22-3.22a.75.75 0 1 1 1.06-1.06l4.5 4.5a.75.75 0 0 1 0 1.06l-4.5 4.5a.75.75 0 1 1-1.06-1.06l3.22-3.22H3.75A.75.75 0 0 1 3 10Z" clip-rule="evenodd"/>
                    </svg>
                </button>

                <div class="meta">{{ $displayPath }}</div>
            </form>
        </section>
    </main>
</body>
</html>
