<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Horizon Login</title>
    <style>
        :root {
            color-scheme: dark;
            font-family: ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        }

        * {
            box-sizing: border-box;
        }

        body {
            min-height: 100vh;
            margin: 0;
            display: grid;
            place-items: center;
            background: #111827;
            color: #e5e7eb;
        }

        main {
            width: min(100% - 32px, 420px);
            border: 1px solid #334155;
            border-radius: 8px;
            background: #1f2937;
            box-shadow: 0 24px 80px rgba(0, 0, 0, 0.32);
            overflow: hidden;
        }

        header {
            padding: 28px 28px 20px;
            border-bottom: 1px solid #334155;
            background: #182130;
        }

        h1 {
            margin: 0;
            font-size: 24px;
            line-height: 1.2;
            font-weight: 700;
        }

        p {
            margin: 8px 0 0;
            color: #94a3b8;
            font-size: 14px;
        }

        form {
            padding: 28px;
            display: grid;
            gap: 18px;
        }

        label {
            display: grid;
            gap: 8px;
            color: #cbd5e1;
            font-size: 13px;
            font-weight: 600;
        }

        input {
            width: 100%;
            border: 1px solid #475569;
            border-radius: 6px;
            background: #111827;
            color: #f8fafc;
            padding: 12px 14px;
            font-size: 15px;
            outline: none;
        }

        input:focus {
            border-color: #38bdf8;
            box-shadow: 0 0 0 3px rgba(56, 189, 248, 0.16);
        }

        button {
            border: 0;
            border-radius: 6px;
            background: #0ea5e9;
            color: #082f49;
            padding: 12px 16px;
            font-weight: 700;
            cursor: pointer;
        }

        button:hover {
            background: #38bdf8;
        }

        .error {
            border: 1px solid #7f1d1d;
            border-radius: 6px;
            background: #3f1d20;
            color: #fecaca;
            padding: 12px 14px;
            font-size: 14px;
        }

        .meta {
            color: #64748b;
            font-size: 12px;
            text-align: center;
        }
    </style>
</head>
<body>
    <main>
        <header>
            <h1>Horizon</h1>
            <p>Masuk untuk membuka dashboard queue.</p>
        </header>

        <form method="POST" action="{{ $loginAction }}">
            @csrf

            @if ($errors->any())
                <div class="error">{{ $errors->first() }}</div>
            @endif

            <label>
                Username
                <input
                    type="text"
                    name="username"
                    value="{{ old('username') }}"
                    autocomplete="username"
                    autofocus
                    required
                >
            </label>

            <label>
                Password
                <input
                    type="password"
                    name="password"
                    autocomplete="current-password"
                    required
                >
            </label>

            <button type="submit">Masuk</button>

            <div class="meta">/{{ $horizonPath }}</div>
        </form>
    </main>
</body>
</html>
