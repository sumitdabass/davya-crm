<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Not allowed — Davya CRM</title>
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
        <div class="code">403</div>
        <h1>You're not allowed here</h1>
        <p>This section isn't part of your access. If you think you should be able to open it, ask an admin to grant you the right role.</p>
        <a href="/admin" class="btn">Back to a page you can access</a>
        @if(request()->path() !== '/')
            <div class="path">{{ request()->path() }}</div>
        @endif
    </div>
</body>
</html>
