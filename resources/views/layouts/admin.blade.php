<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'Admin Dashboard') - Purchase Management</title>
    <style>
        :root {
            --bg: #f8fafc;
            --card-bg: #ffffff;
            --text-main: #0f172a;
            --text-muted: #64748b;
            --border: #e2e8f0;
            --primary: #2563eb;
            --primary-hover: #1d4ed8;
            --success-bg: #dcfce7;
            --success-text: #166534;
            --warning-bg: #fef9c3;
            --warning-text: #854d0e;
            --danger-bg: #fee2e2;
            --danger-text: #991b1b;
            --neutral-bg: #f1f5f9;
            --neutral-text: #475569;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            background-color: var(--bg);
            color: var(--text-main);
            line-height: 1.5;
            padding-bottom: 3rem;
        }

        .navbar {
            background-color: #1e293b;
            color: #ffffff;
            padding: 0.875rem 1.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }

        .navbar-brand {
            font-weight: 700;
            font-size: 1.125rem;
            color: #ffffff;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .navbar-brand span {
            background: var(--primary);
            color: white;
            font-size: 0.75rem;
            padding: 0.15rem 0.5rem;
            border-radius: 4px;
            text-transform: uppercase;
            font-weight: 600;
        }

        .navbar-user {
            display: flex;
            align-items: center;
            gap: 1rem;
            font-size: 0.875rem;
        }

        .btn-logout {
            background: rgba(255,255,255,0.1);
            color: #fff;
            border: 1px solid rgba(255,255,255,0.2);
            padding: 0.35rem 0.75rem;
            border-radius: 4px;
            cursor: pointer;
            font-size: 0.8125rem;
        }

        .btn-logout:hover { background: rgba(255,255,255,0.2); }

        .container {
            max-width: 1200px;
            margin: 2rem auto;
            padding: 0 1.5rem;
        }

        .card {
            background: var(--card-bg);
            border: 1px solid var(--border);
            border-radius: 8px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
            margin-bottom: 1.5rem;
            overflow: hidden;
        }

        .card-header {
            padding: 1rem 1.5rem;
            border-bottom: 1px solid var(--border);
            background: #ffffff;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .card-title {
            font-size: 1.125rem;
            font-weight: 600;
            color: var(--text-main);
        }

        .card-body { padding: 1.5rem; }

        .badge {
            display: inline-block;
            padding: 0.25rem 0.6rem;
            font-size: 0.75rem;
            font-weight: 600;
            border-radius: 9999px;
            text-transform: uppercase;
            letter-spacing: 0.025em;
        }

        .badge-pending { background: var(--warning-bg); color: var(--warning-text); }
        .badge-confirmed { background: var(--success-bg); color: var(--success-text); }
        .badge-succeeded { background: var(--success-bg); color: var(--success-text); }
        .badge-failed { background: var(--danger-bg); color: var(--danger-text); }
        .badge-cancelled { background: var(--neutral-bg); color: var(--neutral-text); }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.875rem;
        }

        th {
            background: #f8fafc;
            color: var(--text-muted);
            font-weight: 600;
            text-align: left;
            padding: 0.75rem 1rem;
            border-bottom: 1px solid var(--border);
        }

        td {
            padding: 0.875rem 1rem;
            border-bottom: 1px solid var(--border);
            color: var(--text-main);
            vertical-align: middle;
        }

        tr:hover td { background-color: #f8fafc; }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 0.5rem 1rem;
            font-size: 0.875rem;
            font-weight: 500;
            border-radius: 6px;
            border: 1px solid transparent;
            cursor: pointer;
            text-decoration: none;
            transition: background-color 0.15s ease-in-out;
        }

        .btn-primary { background-color: var(--primary); color: white; }
        .btn-primary:hover { background-color: var(--primary-hover); }
        .btn-secondary { background-color: #fff; border-color: var(--border); color: var(--text-main); }
        .btn-secondary:hover { background-color: var(--neutral-bg); }
        .btn-sm { padding: 0.25rem 0.5rem; font-size: 0.75rem; }

        .filter-form {
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
            align-items: flex-end;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 0.25rem;
            flex: 1;
            min-width: 180px;
        }

        .form-label {
            font-size: 0.8125rem;
            font-weight: 600;
            color: var(--text-muted);
        }

        .form-control, .form-select {
            padding: 0.5rem 0.75rem;
            border: 1px solid var(--border);
            border-radius: 6px;
            font-size: 0.875rem;
            background: #fff;
            color: var(--text-main);
        }

        .form-control:focus, .form-select:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 2px rgba(37,99,235,0.15);
        }

        .grid-2 {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 1.5rem;
        }

        .dl-grid {
            display: grid;
            grid-template-columns: 140px 1fr;
            gap: 0.75rem 1rem;
            font-size: 0.875rem;
        }

        .dl-grid dt { font-weight: 600; color: var(--text-muted); }
        .dl-grid dd { color: var(--text-main); word-break: break-word; }

        pre code {
            display: block;
            background: #0f172a;
            color: #e2e8f0;
            padding: 1rem;
            border-radius: 6px;
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
            font-size: 0.8125rem;
            overflow-x: auto;
            max-height: 250px;
        }

        .breadcrumb {
            margin-bottom: 1.5rem;
            font-size: 0.875rem;
            display: flex;
            gap: 0.5rem;
            align-items: center;
        }

        .breadcrumb a { color: var(--primary); text-decoration: none; }
        .breadcrumb a:hover { text-decoration: underline; }
    </style>
</head>
<body>
    <header class="navbar">
        <a href="{{ route('admin.purchases.index') }}" class="navbar-brand">
            Purchase System <span>Admin</span>
        </a>
        <div class="navbar-user">
            @auth
                <span>{{ auth()->user()->name }} ({{ auth()->user()->email }})</span>
                <form action="{{ route('logout') }}" method="POST" style="display: inline;">
                    @csrf
                    <button type="submit" class="btn-logout">Log Out</button>
                </form>
            @endauth
        </div>
    </header>

    <main class="container">
        @yield('content')
    </main>
</body>
</html>
