{{-- The MCP consent (docs/mcp.md): standalone, never framed. The client's name is its own claim; the return host is what counts. --}}
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Authorize MCP access · {{ config('app.name') }}</title>
    <style>
        :root { color-scheme: light dark; --bg: #f6f7f9; --card: #fff; --text: #1d2330; --muted: #5d6675; --line: #dfe3ea; --accent: #2557d6; --danger: #b42318; }
        @media (prefers-color-scheme: dark) { :root { --bg: #11141a; --card: #1a1f27; --text: #e8ebf0; --muted: #9aa3b2; --line: #2b323d; --accent: #6f95ff; --danger: #ff7b6e; } }
        body { margin: 0; background: var(--bg); color: var(--text); font: 15px/1.5 system-ui, sans-serif; }
        main { max-width: 32rem; margin: 3rem auto; padding: 0 1rem; }
        .card { background: var(--card); border: 1px solid var(--line); border-radius: 12px; padding: 1.5rem; }
        h1 { font-size: 1.25rem; margin: 0 0 .75rem; }
        .muted { color: var(--muted); font-size: .9rem; }
        code { font-size: .9rem; }
        ul { padding-left: 1.2rem; }
        .row { display: flex; gap: .5rem; flex-wrap: wrap; margin-top: 1rem; }
        button { font: inherit; border-radius: 8px; padding: .55rem 1rem; border: 1px solid var(--line); background: var(--card); color: var(--text); cursor: pointer; }
        button.primary { background: var(--accent); border-color: var(--accent); color: #fff; }
        input[type=password] { width: 100%; box-sizing: border-box; font: inherit; padding: .5rem; border-radius: 8px; border: 1px solid var(--line); background: var(--bg); color: var(--text); }
        .error { color: var(--danger); margin: .5rem 0 0; }
        hr { border: 0; border-top: 1px solid var(--line); margin: 1.25rem 0; }
    </style>
</head>
<body>
<main>
    <div class="card">
        <h1>Authorize MCP access</h1>
        <p><strong>{{ $authorize->client->name }}</strong> asks to use {{ config('app.name') }}'s MCP server as you.</p>
        <p class="muted">After you approve, it goes back to <code>{{ $returnHost }}</code>. Approve only if you started this from there.</p>
        <ul class="muted">
            <li>Read-only tools: health, services, accounts, invoices, mails, logs, license checks — what your role may see.</li>
            <li>Revoke it any time: Admin → Other → MCP Authorizations.</li>
        </ul>

        @if($keyError)
            <p class="error">{{ $keyError }}</p>
        @endif

        @if($staff && $staffMay)
            <form method="post" action="{{ url('/mcp/oauth/authorize') }}">
                @csrf
                @foreach($authorize->fields() as $name => $value)
                    <input type="hidden" name="{{ $name }}" value="{{ $value }}">
                @endforeach
                <p>Logged in as <strong>{{ $staff->name }}</strong>.</p>
                <div class="row">
                    <button class="primary" name="decision" value="approve">Approve</button>
                    <button name="decision" value="deny">Deny</button>
                </div>
            </form>
        @else
            @if($staff)
                <p class="error">You are logged in as {{ $staff->name }}, whose role may not use the MCP server.</p>
            @endif
            @if($withoutTwoFactor)
                <p class="error">You are logged in as {{ $withoutTwoFactor->name }}, without 2FA: approving an MCP connector needs it.</p>
                <div class="row"><a href="{{ $profileUrl }}"><button type="button" class="primary">Set up 2FA in your profile</button></a></div>
            @else
                <div class="row"><a href="{{ $loginUrl }}"><button type="button" class="primary">Log in to the admin to approve</button></a></div>
            @endif
            <hr>
            <form method="post" action="{{ url('/mcp/oauth/authorize') }}">
                @csrf
                @foreach($authorize->fields() as $name => $value)
                    <input type="hidden" name="{{ $name }}" value="{{ $value }}">
                @endforeach
                <label class="muted" for="api_key">Or paste an admin API key with «Use the MCP Server»</label>
                <input id="api_key" type="password" name="api_key" autocomplete="off" required>
                <div class="row">
                    <button class="primary" name="decision" value="approve">Approve with the key</button>
                    <button type="submit" name="decision" value="deny" formnovalidate>Deny</button>
                </div>
            </form>
        @endif
    </div>
</main>
</body>
</html>
