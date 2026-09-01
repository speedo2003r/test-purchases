<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login - Purchase Management</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            background: #f1f5f9;
            color: #0f172a;
            display: flex;
            min-height: 100vh;
            align-items: center;
            justify-content: center;
            padding: 1rem;
        }
        .login-card {
            background: #fff;
            border-radius: 8px;
            box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1), 0 2px 4px -2px rgba(0,0,0,0.1);
            width: 100%;
            max-width: 400px;
            padding: 2rem;
            border: 1px solid #e2e8f0;
        }
        .login-header { text-align: center; margin-bottom: 1.5rem; }
        .login-title { font-size: 1.5rem; font-weight: 700; color: #1e293b; }
        .login-subtitle { font-size: 0.875rem; color: #64748b; margin-top: 0.25rem; }
        .form-group { margin-bottom: 1.25rem; }
        .form-label { display: block; font-size: 0.875rem; font-weight: 600; color: #334155; margin-bottom: 0.35rem; }
        .form-control { width: 100%; padding: 0.625rem 0.75rem; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 0.875rem; }
        .form-control:focus { outline: none; border-color: #2563eb; box-shadow: 0 0 0 3px rgba(37,99,235,0.15); }
        .btn-submit { width: 100%; background: #2563eb; color: #fff; padding: 0.625rem; border: none; border-radius: 6px; font-size: 0.875rem; font-weight: 600; cursor: pointer; margin-top: 0.5rem; }
        .btn-submit:hover { background: #1d4ed8; }
        .alert-error { background: #fee2e2; border: 1px solid #fecaca; color: #991b1b; padding: 0.75rem; border-radius: 6px; margin-bottom: 1.25rem; font-size: 0.875rem; }
    </style>
</head>
<body>
    <div class="login-card">
        <div class="login-header">
            <h1 class="login-title">Admin Login</h1>
            <p class="login-subtitle">Sign in to access the purchase management dashboard</p>
        </div>

        @if ($errors->any())
            <div class="alert-error">
                @foreach ($errors->all() as $error)
                    <div>{{ $error }}</div>
                @endforeach
            </div>
        @endif

        <form method="POST" action="{{ route('login') }}">
            @csrf
            <div class="form-group">
                <label for="email" class="form-label">Email Address</label>
                <input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus class="form-control" placeholder="admin@example.com">
            </div>

            <div class="form-group">
                <label for="password" class="form-label">Password</label>
                <input id="password" type="password" name="password" required class="form-control" placeholder="••••••••">
            </div>

            <button type="submit" class="btn-submit">Sign In</button>
        </form>
    </div>
</body>
</html>
