<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $filename }}</title>
    <style>
        body { font-family: Inter, system-ui, -apple-system, Segoe UI, Roboto, sans-serif; margin: 0; color: #111827; background: #ffffff; }
        .wrap { padding: 0; max-width: 900px; margin: 0 auto; }
        .title { font-size: 18px; font-weight: 600; margin: 0 0 16px; word-break: break-word; }
        .grid { display: grid; grid-template-columns: 140px 1fr; gap: 8px 12px; margin-bottom: 16px; font-size: 14px; }
        .label { color: #4b5563; font-weight: 600; }
        .value { color: #111827; word-break: break-word; }
        .body { border: 1px solid #e5e7eb; border-radius: 8px; padding: 12px; white-space: pre-wrap; line-height: 1.45; font-size: 14px; background: #f9fafb; overflow-x: scroll; }
        .html-body { border: 1px solid #e5e7eb; border-radius: 8px; padding: 12px; line-height: 1.45; font-size: 14px; background: #f9fafb; overflow-x: auto; }
        .html-body img { max-width: 100%; height: auto; }
        .section-title { margin: 18px 0 8px; font-size: 14px; color: #374151; }
        .images-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 10px; }
        .image-card { border: 1px solid #e5e7eb; border-radius: 8px; padding: 8px; background: #fff; }
        .image-card img { display: block; max-width: 100%; height: auto; border-radius: 6px; }
        .image-name { margin-top: 6px; font-size: 12px; color: #4b5563; word-break: break-word; }
        .muted { color: #6b7280; }
        .error { border: 1px solid #fecaca; background: #fef2f2; color: #991b1b; padding: 10px 12px; border-radius: 8px; margin-bottom: 12px; }
    </style>
</head>
<body>
<div class="wrap">
    <h1 class="title">{{ $preview['subject'] !== '' ? $preview['subject'] : $filename }}</h1>

    @if($preview['parse_error'] !== null)
        <div class="error">{{ $preview['parse_error'] }}</div>
    @endif
    @if(($preview['is_partial'] ?? false) === true)
        <div class="error" style="border-color:#bfdbfe;background:#eff6ff;color:#1e3a8a;">
            De uitgebreide preview (HTML en afbeeldingen) wordt op de achtergrond opgebouwd.
            Open dit document opnieuw voor de volledige weergave.
        </div>
    @endif

    <div class="grid">
        <div class="label">Van</div>
        <div class="value">{{ $preview['from'] !== '' ? $preview['from'] : '—' }}</div>

        <div class="label">Aan</div>
        <div class="value">{{ $preview['to'] !== '' ? $preview['to'] : '—' }}</div>

        <div class="label">Cc</div>
        <div class="value">{{ $preview['cc'] !== '' ? $preview['cc'] : '—' }}</div>

        <div class="label">Datum</div>
        <div class="value">{{ $preview['sent_at'] !== '' ? $preview['sent_at'] : '—' }}</div>
    </div>

    @if(($preview['body_html'] ?? '') !== '')
        <h2 class="section-title">HTML</h2>
        <div class="html-body">{!! $preview['body_html'] !!}</div>
    @endif

    <h2 class="section-title">Tekst</h2>
    <div class="body">{{ $preview['body_text'] !== '' ? $preview['body_text'] : 'Geen berichttekst gevonden.' }}</div>

    @if(!empty($preview['inline_images']))
        <h2 class="section-title">Afbeeldingen</h2>
        <div class="images-grid">
            @foreach($preview['inline_images'] as $image)
                <figure class="image-card">
                    <img src="{{ $image['data_uri'] }}" alt="{{ $image['filename'] }}">
                    <figcaption class="image-name">{{ $image['filename'] }}</figcaption>
                </figure>
            @endforeach
        </div>
    @endif

    <p class="muted">Preview van Outlook .msg</p>
</div>
</body>
</html>
