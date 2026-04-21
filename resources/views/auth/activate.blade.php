<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Aktivasi Akun — Sales Agent WA</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: Arial, sans-serif; background: #f0f2f5; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; }
        .card { background: #fff; border-radius: 8px; padding: 40px; width: 100%; max-width: 420px; box-shadow: 0 2px 16px rgba(0,0,0,.1); }
        h1 { margin: 0 0 8px; font-size: 22px; color: #1a1a2e; }
        p { color: #555; margin: 0 0 24px; font-size: 14px; }
        label { display: block; margin-bottom: 6px; font-size: 14px; color: #333; }
        input { width: 100%; padding: 10px 14px; border: 1px solid #ddd; border-radius: 6px; font-size: 14px; margin-bottom: 16px; }
        button { width: 100%; padding: 12px; background: #6c63ff; color: #fff; border: none; border-radius: 6px; font-size: 16px; cursor: pointer; }
        .error { background: #fee; border: 1px solid #fcc; color: #c00; padding: 10px 14px; border-radius: 6px; font-size: 13px; margin-bottom: 16px; }
    </style>
</head>
<body>
    <div class="card">
        <h1>Aktivasi Akun</h1>
        <p>Buat password untuk akun Anda.</p>

        @if (($errors ?? null)?->any())
            <div class="error">{{ $errors->first() }}</div>
        @endif

        <form method="POST" action="{{ route('auth.activate.submit') }}">
            @csrf
            <input type="hidden" name="token" value="{{ $token }}">
            <label>Password Baru</label>
            <input type="password" name="password" required minlength="8">
            <label>Konfirmasi Password</label>
            <input type="password" name="password_confirmation" required minlength="8">
            <button type="submit">Aktifkan Akun</button>
        </form>
    </div>
</body>
</html>
