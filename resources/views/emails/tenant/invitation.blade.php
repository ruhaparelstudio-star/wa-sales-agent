<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Aktivasi Akun</title>
    <style>
        body { font-family: Arial, sans-serif; background: #f4f4f4; margin: 0; padding: 20px; }
        .container { max-width: 600px; margin: 0 auto; background: #fff; border-radius: 8px; padding: 40px; }
        .header { text-align: center; margin-bottom: 32px; }
        .header h1 { color: #1a1a2e; font-size: 24px; }
        .btn { display: inline-block; background: #6c63ff; color: #fff; text-decoration: none;
               padding: 14px 32px; border-radius: 6px; font-size: 16px; margin: 24px 0; }
        .footer { color: #888; font-size: 12px; margin-top: 32px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>{{ $tenantName }}</h1>
        </div>

        <p>Halo <strong>{{ $adminName }}</strong>,</p>

        <p>
            Anda telah terdaftar sebagai <strong>Vendor Admin</strong> di <strong>{{ $tenantName }}</strong>
            pada platform Sales Agent WhatsApp.
        </p>

        <p>Klik tombol di bawah untuk mengaktifkan akun dan membuat password Anda:</p>

        <div style="text-align: center;">
            <a href="{{ $activationUrl }}" class="btn">Aktivasi Akun Sekarang</a>
        </div>

        <p>Link ini berlaku hingga <strong>{{ $expiresAt }}</strong>.</p>

        <p>
            Jika tombol di atas tidak berfungsi, salin dan tempel URL berikut ke browser Anda:<br>
            <small style="color: #555; word-break: break-all;">{{ $activationUrl }}</small>
        </p>

        <div class="footer">
            <p>Jika Anda tidak merasa mendaftar, abaikan email ini.</p>
        </div>
    </div>
</body>
</html>
