<?php
declare(strict_types=1);

/** Runtime adapters for the fixed, non-user-selectable Hermes/OpenClaw commands. */

function agent_runtime(): string
{
    $runtime = strtolower(app_env('WEBAPP2_RUNTIME', 'hermes'));
    if (!in_array($runtime, ['hermes', 'openclaw'], true)) {
        throw new RuntimeException('Unsupported agent runtime.');
    }
    return $runtime;
}

function runtime_user(): string
{
    $user = app_env('WEBAPP2_SSH_USER');
    if ($user === '' || !preg_match('/^[A-Za-z_][A-Za-z0-9_.-]*$/', $user)) {
        throw new RuntimeException('WEBAPP2_SSH_USER is not configured safely.');
    }
    return $user;
}

function runtime_session(): string
{
    $key = agent_runtime() === 'hermes' ? 'WEBAPP2_HERMES_SESSION' : 'WEBAPP2_OPENCLAW_SESSION_KEY';
    $session = app_env($key);
    if ($session === '' || strlen($session) > 240 || !preg_match('/^[A-Za-z0-9._:\/-]+$/', $session)) {
        throw new RuntimeException($key . ' is not configured.');
    }
    return $session;
}

function runtime_binary(): string
{
    $key = agent_runtime() === 'hermes' ? 'WEBAPP2_HERMES_BIN' : 'WEBAPP2_OPENCLAW_BIN';
    $binary = app_env($key, agent_runtime() === 'hermes' ? 'hermes' : 'openclaw');
    if (!preg_match('~^[A-Za-z0-9_./:-]+$~', $binary)) {
        throw new RuntimeException($key . ' is invalid.');
    }
    return $binary;
}

function ssh_binary(): string
{
    $binary = app_env('WEBAPP2_SSH_BIN', '/usr/bin/ssh');
    if (!preg_match('~^/[A-Za-z0-9_./-]+$~', $binary)) {
        throw new RuntimeException('WEBAPP2_SSH_BIN is invalid.');
    }
    return $binary;
}

function runtime_pairing_command(string $platform, string $code): array
{
    return [runtime_binary(), 'pairing', 'approve', $platform, $code];
}

function runtime_prompt_command(string $prompt): array
{
    if (agent_runtime() === 'hermes') {
        return [runtime_binary(), 'chat', '--resume', runtime_session(), '-q', $prompt];
    }
    return [runtime_binary(), 'system', 'event', '--mode', 'now', '--expect-final', '--session-key', runtime_session(), '--text', $prompt, '--json'];
}

function run_runtime(array $command, int $timeout = 90): array
{
    $host = app_env('WEBAPP2_SSH_HOST', '127.0.0.1');
    $port = app_env('WEBAPP2_SSH_PORT', '22');
    if (!filter_var($host, FILTER_VALIDATE_IP) && !preg_match('/^[A-Za-z0-9][A-Za-z0-9.-]*$/', $host)) {
        throw new RuntimeException('WEBAPP2_SSH_HOST is invalid.');
    }
    if (!ctype_digit($port) || (int)$port < 1 || (int)$port > 65535) {
        throw new RuntimeException('WEBAPP2_SSH_PORT is invalid.');
    }

    $ssh = [ssh_binary(), '-o', 'BatchMode=yes', '-o', 'ConnectTimeout=5', '-p', $port];
    $identity = app_env('WEBAPP2_SSH_IDENTITY_FILE');
    if ($identity !== '') {
        $ssh = array_merge($ssh, ['-i', $identity]);
    }
    $ssh[] = '--';
    $ssh[] = runtime_user() . '@' . $host;

    // OpenSSH joins all arguments after the host into a remote shell command.
    // Quote each fixed/runtime argument explicitly so prompts and JSON braces
    // cannot be reinterpreted by that shell or truncated by whitespace.
    $remoteCommand = implode(' ', array_map('escapeshellarg', $command));
    $command = array_merge($ssh, [$remoteCommand]);

    $pipes = [];
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, APP_ROOT, null, ['bypass_shell' => true]);
    if (!is_resource($process)) {
        throw new RuntimeException('Agent process could not start.');
    }
    foreach ([1, 2] as $pipe) stream_set_blocking($pipes[$pipe], false);
    $stdout = $stderr = '';
    $started = microtime(true);
    do {
        $stdout .= stream_get_contents($pipes[1]);
        $stderr .= stream_get_contents($pipes[2]);
        $status = proc_get_status($process);
        if (!$status['running']) break;
        usleep(100000);
    } while (microtime(true) - $started < $timeout);
    $timedOut = ($status['running'] ?? false) === true;
    if ($timedOut) proc_terminate($process);
    $stdout .= stream_get_contents($pipes[1]);
    $stderr .= stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    $exitCode = proc_close($process);
    return ['stdout' => substr(trim($stdout), 0, 4000), 'stderr' => substr(trim($stderr), 0, 2000), 'exit_code' => $exitCode, 'timed_out' => $timedOut];
}

function run_pairing(string $platform, string $code): array
{
    return run_runtime(runtime_pairing_command($platform, $code), 30);
}

function run_runtime_prompt(string $prompt, int $timeout = 90): array
{
    return run_runtime(runtime_prompt_command($prompt), $timeout);
}
