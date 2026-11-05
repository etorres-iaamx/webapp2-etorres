<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';

session_set_cookie_params([
    'httponly' => true,
    'samesite' => 'Lax',
    'secure' => false,
]);
session_start();

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

$message = null;
$messageType = 'info';
$old = [
    'name' => '', 'country_code' => '+1', 'phone' => '', 'email' => '',
    'platform' => 'telegram',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach (array_keys($old) as $key) {
        $old[$key] = trim((string)($_POST[$key] ?? ''));
    }
    $code = strtoupper(trim((string)($_POST['code'] ?? '')));
    $csrf = (string)($_POST['csrf'] ?? '');
    $errors = [];

    if (!hash_equals((string)$_SESSION['csrf'], $csrf)) $errors[] = 'La sesión expiró. Recarga el formulario.';
    if (!preg_match('/^[A-Za-z0-9]{6,12}$/', $code)) $errors[] = 'El código de emparejamiento no tiene un formato válido.';
    if ($old['name'] === '' || strlen($old['name']) > 160) $errors[] = 'Indica un nombre válido.';
    if (!preg_match('/^\+[1-9][0-9]{0,3}$/', $old['country_code'])) $errors[] = 'Selecciona un código de país válido.';
    if (!preg_match('/^[0-9][0-9 ()-]{5,30}$/', $old['phone'])) $errors[] = 'Indica un número telefónico válido.';
    if (!filter_var($old['email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'Indica un correo electrónico válido.';
    if (!in_array($old['platform'], ['telegram', 'whatsapp'], true)) $errors[] = 'La plataforma no es válida.';

    if (!$errors) {
        try {
            $pdo = db();
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM webapp2_pairing_requests WHERE client_ip = INET6_ATON(?) AND created_at >= (NOW() - INTERVAL 15 MINUTE)');
            $stmt->execute([$_SERVER['REMOTE_ADDR'] ?? '0.0.0.0']);
            if ((int)$stmt->fetchColumn() >= 5) {
                $errors[] = 'Se alcanzó el límite temporal de intentos. Intenta más tarde.';
            } else {
                $runtime = agent_runtime();
                $codeHash = hash('sha256', $code);
                $existing = $pdo->prepare('SELECT status FROM webapp2_pairing_requests WHERE code_hash = ? AND platform = ? AND status = ? ORDER BY id DESC LIMIT 1');
                $existing->execute([$codeHash, $old['platform'], 'approved']);
                if ($existing->fetchColumn() !== false) {
                    // OpenClaw removes an approved code from its pending queue.
                    // Treat browser retries as idempotent success instead of
                    // exposing the runtime's "no pending request" error.
                    $messageType = 'success';
                    $message = 'El emparejamiento ya había sido realizado correctamente.';
                    $_SESSION['csrf'] = bin2hex(random_bytes(32));
                } else {
                    $insert = $pdo->prepare('INSERT INTO webapp2_pairing_requests (name, country_code, phone, email, platform, runtime, code_hash, client_ip) VALUES (?, ?, ?, ?, ?, ?, ?, INET6_ATON(?))');
                    $insert->execute([$old['name'], $old['country_code'], $old['phone'], $old['email'], $old['platform'], $runtime, $codeHash, $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0']);
                    $requestId = (int)$pdo->lastInsertId();

                    $execution = run_pairing($old['platform'], $code);
                    $result = substr(trim($execution['stdout'] . ($execution['stderr'] !== '' ? "\n" . $execution['stderr'] : '')), 0, 4000);
                    $ok = $execution['exit_code'] === 0 && !$execution['timed_out'];
                    $update = $pdo->prepare('UPDATE webapp2_pairing_requests SET status = ?, command_output = ?, processed_at = NOW() WHERE id = ?');
                    $update->execute([$ok ? 'approved' : 'failed', $result, $requestId]);
                    $messageType = $ok ? 'success' : 'error';
                    $message = $ok ? 'El emparejamiento se realizó correctamente.' : 'No fue posible completar el emparejamiento. Verifica el código y la plataforma.';
                    $_SESSION['csrf'] = bin2hex(random_bytes(32));
                }
            }
        } catch (Throwable $e) {
            error_log('webapp2 pairing error: ' . $e->getMessage());
            $messageType = 'error';
            $message = 'El servicio no está disponible en este momento. Intenta más tarde.';
        }
    }
    if ($errors) {
        $messageType = 'error';
        $message = implode(' ', $errors);
    }
}
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Emparejamiento de webapp2</title>
<style>
body{font-family:system-ui,-apple-system,Segoe UI,sans-serif;background:#f4f7fb;color:#172033;margin:0;padding:24px}.card{max-width:680px;margin:auto;background:#fff;padding:28px;border-radius:14px;box-shadow:0 8px 28px #1720331a}h1{margin-top:0}label{display:block;font-weight:600;margin:16px 0 6px}input,select{width:100%;box-sizing:border-box;padding:11px;border:1px solid #bbc5d4;border-radius:8px;font:inherit}button{margin-top:24px;padding:12px 20px;border:0;border-radius:8px;background:#185adb;color:#fff;font-weight:700;cursor:pointer}.notice{padding:12px;border-radius:8px;margin:16px 0}.success{background:#e5f7eb;color:#155b2c}.error{background:#fde8e8;color:#8b1e1e}.hint{color:#5e6b7e;font-size:.93rem}.platforms{display:flex;gap:20px;margin-top:8px}.platforms label{font-weight:400;margin:0}.platforms input{width:auto}
</style>
</head>
<body>
<main class="card">
<h1>Hola!</h1>
<p>Bienvenido!</p>
<p>Coloca a continuación tus datos de contacto para que el Agente IA pueda responderte por Telegram o WhatsApp.</p>
<?php if ($message !== null): ?><div class="notice <?= e($messageType) ?>" role="alert"><?= e($message) ?></div><?php endif; ?>
<form method="post" action="/pa/index.php" autocomplete="on">
<input type="hidden" name="csrf" value="<?= e((string)$_SESSION['csrf']) ?>">
<label for="code">Código de emparejamiento</label>
<input id="code" name="code" required maxlength="12" pattern="[A-Za-z0-9]{6,12}" autocomplete="one-time-code" placeholder="Código">
<h2>Datos de contacto</h2>
<label for="name">Nombre</label>
<input id="name" name="name" required maxlength="160" value="<?= e($old['name']) ?>">
<label for="country_code">Código de país</label>
<select id="country_code" name="country_code"><option value="+1" <?= $old['country_code']==='+1'?'selected':'' ?>>+1 Estados Unidos/Canadá</option><option value="+52" <?= $old['country_code']==='+52'?'selected':'' ?>>+52 México</option><option value="+34" <?= $old['country_code']==='+34'?'selected':'' ?>>+34 España</option><option value="+57" <?= $old['country_code']==='+57'?'selected':'' ?>>+57 Colombia</option><option value="+54" <?= $old['country_code']==='+54'?'selected':'' ?>>+54 Argentina</option></select>
<label for="phone">Teléfono</label>
<input id="phone" name="phone" required maxlength="32" inputmode="tel" value="<?= e($old['phone']) ?>">
<label for="email">Email</label>
<input id="email" name="email" required type="email" maxlength="254" value="<?= e($old['email']) ?>">
<label>Plataforma de chat</label>
<p class="hint">Selecciona la plataforma por la cual estás contactando al Agente IA.</p>
<div class="platforms"><label><input type="radio" name="platform" value="telegram" <?= $old['platform']==='telegram'?'checked':'' ?>> Telegram</label><label><input type="radio" name="platform" value="whatsapp" <?= $old['platform']==='whatsapp'?'checked':'' ?>> WhatsApp</label></div>
<button type="submit">Continuar</button>
</form>
</main>
</body>
</html>
