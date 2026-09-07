{{-- Minimal Bangla-safe HTML shell for App\Domain\Notifications\Drivers\Mail\MailerDriver. The body is already
     HTML-escaped by TemplateRenderer, so it is echoed unescaped and newlines become <br>. --}}
<!DOCTYPE html>
<html lang="{{ $locale }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $subject }}</title>
</head>
<body style="margin:0;padding:24px;background:#f5f5f5;font-family:'Noto Sans Bengali','Noto Sans',Arial,sans-serif;color:#1a1a1a;">
<table role="presentation" cellpadding="0" cellspacing="0" style="max-width:560px;margin:0 auto;background:#ffffff;border-radius:8px;">
    <tr>
        <td style="padding:24px;">
            <h1 style="margin:0 0 16px;font-size:18px;line-height:1.4;">{{ $subject }}</h1>
            <div style="font-size:15px;line-height:1.7;">{!! nl2br($body) !!}</div>
            @if ($link)
                <p style="margin:24px 0 0;">
                    <a href="{{ $link }}" style="display:inline-block;padding:10px 18px;background:#0f766e;color:#ffffff;border-radius:6px;text-decoration:none;">{{ $link }}</a>
                </p>
            @endif
        </td>
    </tr>
</table>
</body>
</html>
