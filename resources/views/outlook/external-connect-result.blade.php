<!doctype html>
<html lang="nl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Outlook koppeling</title>
    <style>
        body {
            margin: 0;
            font-family: Inter, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            background: #f8fafc;
            color: #0f172a;
        }

        .container {
            max-width: 720px;
            margin: 48px auto;
            padding: 0 20px;
        }

        .card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 24px;
            box-shadow: 0 8px 30px rgba(15, 23, 42, 0.05);
        }

        .status {
            margin: 0 0 12px;
            font-size: 20px;
            font-weight: 700;
        }

        .status--success {
            color: #166534;
        }

        .status--error {
            color: #991b1b;
        }

        .message {
            margin: 0;
            line-height: 1.5;
            color: #334155;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="card">
            <h1 class="status {{ $success ? 'status--success' : 'status--error' }}">
                {{ $success ? 'Bedankt' : 'Koppeling mislukt' }}
            </h1>
            <p class="message">{{ $message }}</p>
        </div>
    </div>
</body>
</html>
