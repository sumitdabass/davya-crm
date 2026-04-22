<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Page not found — Davya CRM</title>
    <link rel="icon" href="/apple-touch-icon.png" type="image/png">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #0f3a2e;
            color: #ecfdf5;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem;
        }
        .card {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 1rem;
            padding: 2.5rem;
            max-width: 28rem;
            text-align: center;
            backdrop-filter: blur(10px);
        }
        img {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            margin-bottom: 1.25rem;
        }
        .code {
            font-size: 3.5rem;
            font-weight: 700;
            line-height: 1;
            color: #6ee7b7;
            margin-bottom: 0.5rem;
        }
        h1 {
            font-size: 1.25rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
            color: #ecfdf5;
        }
        p {
            font-size: 0.95rem;
            color: #a7f3d0;
            margin-bottom: 1.75rem;
            line-height: 1.5;
        }
        a.btn {
            display: inline-block;
            background: #10b981;
            color: #042f2e;
            padding: 0.65rem 1.4rem;
            border-radius: 0.5rem;
            text-decoration: none;
            font-weight: 600;
            transition: background 0.15s;
        }
        a.btn:hover { background: #34d399; }
        .path {
            font-family: 'SF Mono', Menlo, Consolas, monospace;
            font-size: 0.8rem;
            color: #6ee7b7;
            opacity: 0.7;
            margin-top: 1rem;
            word-break: break-all;
        }
    </style>
</head>
<body>
    <div class="card">
        <img src="/davyas-icon-192.png" alt="Davya CRM">
        <div class="code">404</div>
        <h1>Page not found</h1>
        <p>The page you were looking for doesn't exist — might have been moved, or the URL is mistyped.</p>
        <a href="/admin" class="btn">Back to Dashboard</a>
        @if(request()->path() !== '/')
            <div class="path">{{ request()->path() }}</div>
        @endif
    </div>
</body>
</html>
