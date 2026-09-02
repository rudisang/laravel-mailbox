<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width">
<link rel="stylesheet" href="https://fonts.example.com/inter.css">
<style>
  @import url("https://evil.example.com/tracker.css");
  body { margin:0; background:#0b0b0c; color:#f4f4f5; font-family: Inter, -apple-system, sans-serif; }
  .hero { padding: 48px 24px; text-align:center; background: url("https://cdn.example.com/hero.jpg") center/cover; }
  .hero h1 { font-size: 32px; margin: 0 0 8px; letter-spacing: -0.02em; }
  .grid { display:flex; gap:16px; padding: 24px; }
  .col { flex:1; background:#151517; border-radius:12px; padding:20px; }
  a { color:#fff; }
  @media (max-width: 600px) { .grid { display:block; } .col { margin-bottom: 12px; } }
</style>
</head>
<body>
  <div class="hero">
    <img src="https://cdn.example.com/logo-dark.png" width="120" alt="Acme">
    <h1>August product update</h1>
    <p>Dark mode. Faster search. Keyboard everything.</p>
  </div>
  <div class="grid">
    <div class="col"><h3>Dark mode</h3><p>Flip the switch in settings. Your eyes will thank you.</p><a href="https://acme.test/changelog#dark">Read more</a></div>
    <div class="col"><h3>Search</h3><p>Now 4× faster with instant results.</p><a href="https://acme.test/changelog#search">Read more</a></div>
    <div class="col"><h3>Shortcuts</h3><p>Press <kbd>?</kbd> anywhere to see them.</p><a href="https://acme.test/changelog#keys">Read more</a></div>
  </div>
  <p style="text-align:center;color:#71717a;font-size:12px">You are receiving this because you opted in. <a href="https://acme.test/unsubscribe?u=42">Unsubscribe</a></p>
  <img src="https://track.example.com/open.gif?u=42" width="1" height="1" alt="">
</body>
</html>
