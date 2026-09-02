<!DOCTYPE html>
<html>
<head><meta charset="utf-8"><style>
  body { font-family: Georgia, serif; color:#111; background:#fff; margin:0; padding:32px; }
  table { border-collapse: collapse; width: 100%; max-width: 600px; }
  th, td { text-align: left; padding: 10px 12px; border-bottom: 1px solid #e5e5e5; }
  th { font-size: 12px; text-transform: uppercase; letter-spacing: .08em; color:#666; }
  .total td { font-weight: bold; border-top: 2px solid #111; }
</style></head>
<body>
  <h1>Invoice #{{ $number }}</h1>
  <p>Hi there — here is your invoice for August. Amount due: <strong>{{ $amount }}</strong>.</p>
  <table>
    <tr><th>Item</th><th>Qty</th><th>Amount</th></tr>
    <tr><td>Pro subscription</td><td>1</td><td>{{ $amount }}</td></tr>
    <tr class="total"><td>Total</td><td></td><td>{{ $amount }}</td></tr>
  </table>
  <p>Pay online: <a href="https://acme.test/pay/{{ $number }}">acme.test/pay/{{ $number }}</a></p>
</body>
</html>
