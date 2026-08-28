<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
  body { margin:0; background:#f4f4f5; font-family: -apple-system, Segoe UI, Roboto, sans-serif; color:#18181b; }
  .wrap { max-width: 560px; margin: 32px auto; background:#fff; border-radius: 16px; padding: 40px; }
  h1 { font-size: 24px; margin: 0 0 12px; }
  p { line-height: 1.6; }
  .btn { display:inline-block; background:#18181b; color:#fff !important; padding: 12px 22px; border-radius: 999px; text-decoration:none; font-weight:600; }
  .muted { color:#71717a; font-size: 13px; }
  .card > p { margin: 0; }
</style>
</head>
<body>
  <div class="wrap">
    <img src="{{ $message->embedData(base64_decode('iVBORw0KGgoAAAANSUhEUgAAAEAAAABACAYAAACqaXHeAAAAgklEQVR4nO3aMQ0AIBAEQUD4V4wHcJAtKMgU5HLLPZ2ZOmxl2wcAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAADwYQEuJwABbGz0VQAAAABJRU5ErkJggg=='), 'logo.png', 'image/png') }}" width="64" height="64" alt="Acme">
    <h1>Welcome aboard, {{ $name }} 👋</h1>
    <p>We're thrilled to have you. Your workspace is ready and your teammates are waiting.</p>
    <p><a class="btn" href="https://acme.test/onboarding?token=abc123">Open your workspace</a></p>
    <div class="card"><p class="muted">Need help? Reply to this email or visit <a href="https://acme.test/help">the help centre</a>.</p></div>
  </div>
</body>
</html>
