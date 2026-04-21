<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login — Sales Agent WA</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: Arial, sans-serif; background: #f0f2f5; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; }
        .card { background: #fff; border-radius: 8px; padding: 40px; width: 100%; max-width: 420px; box-shadow: 0 2px 16px rgba(0,0,0,.1); }
        h1 { margin: 0 0 24px; font-size: 22px; color: #1a1a2e; }
        label { display: block; margin-bottom: 6px; font-size: 14px; color: #333; }
        input { width: 100%; padding: 10px 14px; border: 1px solid #ddd; border-radius: 6px; font-size: 14px; margin-bottom: 16px; }
        button { width: 100%; padding: 12px; background: #6c63ff; color: #fff; border: none; border-radius: 6px; font-size: 16px; cursor: pointer; }
        .error { background: #fee; border: 1px solid #fcc; color: #c00; padding: 10px 14px; border-radius: 6px; font-size: 13px; margin-bottom: 16px; }
    </style>
</head>
<body>
    <div class="card">
        <h1>Sales Agent WA</h1>

        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(($errors ?? null)?->any()): ?>
            <div class="error"><?php echo e($errors->first()); ?></div>
        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

        <form method="POST" action="<?php echo e(route('auth.login.submit')); ?>">
            <?php echo csrf_field(); ?>
            <label>Email</label>
            <input type="email" name="email" value="<?php echo e(old('email')); ?>" required autofocus>
            <label>Password</label>
            <input type="password" name="password" required>
            <button type="submit">Masuk</button>
        </form>
    </div>
</body>
</html>
<?php /**PATH /var/www/resources/views/auth/login.blade.php ENDPATH**/ ?>