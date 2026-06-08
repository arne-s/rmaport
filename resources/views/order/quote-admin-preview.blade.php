{{-- Full page for iframe in admin document modal: PDF + approval panel --}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $quote->getUidFormatted() }}</title>
    <style>
        html, body { height: 100%; margin: 0; }
        body {
            display: flex;
            flex-direction: column;
            min-height: 0;
            background: #f3f4f6;
            font-family: ui-sans-serif, system-ui, sans-serif;
            color: #030712;
        }
        .quote-admin-preview-root {
            flex: 1;
            display: flex;
            flex-direction: column;
            min-height: 0;
            width: 100%;
            background: #fff;
        }
        .quote-admin-preview-inner {
            flex: 1;
            display: flex;
            flex-direction: column;
            gap: 12px;
            padding: 0;
            min-height: 0;
        }
        .quote-admin-preview-iframe-wrap {
            flex: 1;
            display: flex;
            flex-direction: column;
            min-height: 0;
            min-width: 0;
        }
        .quote-admin-preview-iframe {
            flex: 1;
            min-height: 0;
            width: 100%;
            border: 0;
            background: #f9fafb;
        }
        .quote-admin-preview-approval {
            flex-shrink: 0;
            max-height: 180px;
            overflow-y: auto;
            padding: 12px;
            border-radius: 8px;
            border: 1px solid #e5e7eb;
            background: #f9fafb;
            font-size: 12px;
            line-height: 1.4;
        }
        .quote-admin-preview-approval h4 {
            margin: 0 0 8px;
            font-size: 12px;
            font-weight: 600;
        }
        .quote-admin-preview-approval p { margin: 0 0 4px; }
        .quote-admin-preview-approval .quote-approval-sig-label {
            font-size: 10px;
            font-weight: 500;
            color: #6b7280;
            margin-bottom: 4px;
        }
        .quote-admin-preview-approval img.quote-approval-sig {
            max-height: 64px;
            max-width: 100%;
            border: 1px solid #e5e7eb;
            border-radius: 4px;
            background: #fff;
        }
        .quote-admin-preview-approval .quote-approval-internal {
            margin: 0;
            padding: 0;
        }
        .quote-admin-preview-approval .font-medium { font-weight: 500; }
    </style>
</head>
<body>
@include('filament.resources.quote-resource.quote-pdf-approval', ['quote' => $quote])
</body>
</html>
