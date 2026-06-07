<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$token = loadToken($config['token_file']);
$apiResult = $_SESSION['api_result'] ?? null;
unset($_SESSION['api_result']);
$connectionLogs = readConnectionLogs(80);

$initialStep = 1;
if ($token) {
    $initialStep = 2;
}
if ($apiResult !== null || !empty($flash)) {
    $initialStep = 3;
}

$formDefaults = [
    'exact_client_id' => $config['client_id'],
    'exact_client_secret' => $config['client_secret'],
    'exact_redirect_uri' => $config['redirect_uri'],
    'exact_base_url' => $config['base_url'],
    'exact_scope' => $config['scope'],
    'webhook_secret' => $config['webhook_secret'],
];

$tokenStatus = 'Not connected';
if ($token) {
    $tokenStatus = isTokenExpired($token) ? 'Connected (token expired)' : 'Connected (token active)';
}

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Exact Online Connection Tester</title>
    <style>
        :root {
            --bg: #f2f4f7;
            --card: #ffffff;
            --text: #1c2b36;
            --muted: #6b7785;
            --accent: #0d6e6e;
            --error: #8b1e1e;
            --ok: #1f6f3e;
            --border: #d9e1e7;
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            background: radial-gradient(circle at top right, #d5e8f0, var(--bg));
            color: var(--text);
            font-family: "Trebuchet MS", "Segoe UI", sans-serif;
        }

        .container {
            max-width: 980px;
            margin: 28px auto;
            padding: 0 16px;
        }

        .card {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 18px;
            box-shadow: 0 8px 18px rgba(8, 34, 58, 0.06);
            margin-bottom: 16px;
        }

        h1 {
            margin: 0 0 10px;
            letter-spacing: 0.2px;
        }

        .grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 14px;
        }

        .row {
            margin: 6px 0;
            font-size: 14px;
        }

        .label {
            color: var(--muted);
            margin-right: 6px;
        }

        .btns {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 10px;
        }

        .btn {
            text-decoration: none;
            border: 1px solid transparent;
            border-radius: 9px;
            padding: 10px 14px;
            font-weight: 700;
            font-size: 14px;
            transition: transform 0.15s ease, opacity 0.15s ease;
            cursor: pointer;
        }

        .btn:hover {
            transform: translateY(-1px);
            opacity: 0.95;
        }

        .btn-primary {
            background: var(--accent);
            color: #fff;
        }

        .btn-ghost {
            border-color: var(--accent);
            color: var(--accent);
            background: #fff;
        }

        .alert {
            padding: 11px 12px;
            border-radius: 8px;
            font-weight: 600;
        }

        .alert.ok {
            background: #edf8f1;
            color: var(--ok);
            border: 1px solid #cce8d5;
        }

        .alert.error {
            background: #fff1f1;
            color: var(--error);
            border: 1px solid #f2cccc;
        }

        pre {
            background: #0e1b29;
            color: #d8e5f1;
            border-radius: 10px;
            padding: 12px;
            overflow: auto;
            line-height: 1.45;
        }

        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }

        .wizard-nav {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 10px;
            margin-top: 14px;
        }

        .wizard-step {
            border: 1px solid var(--border);
            border-radius: 10px;
            background: #f7fafc;
            padding: 10px;
            font-size: 13px;
            font-weight: 700;
            color: var(--muted);
            text-align: center;
        }

        .wizard-step.active {
            border-color: var(--accent);
            color: var(--accent);
            background: #e8f4f4;
        }

        .wizard-panel {
            display: none;
        }

        .wizard-panel.active {
            display: block;
        }

        .mini {
            font-size: 12px;
            color: var(--muted);
        }

        .field {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .field label {
            font-size: 13px;
            color: var(--muted);
            font-weight: 700;
        }

        .field input {
            width: 100%;
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 10px;
            font-size: 14px;
            color: var(--text);
            background: #fff;
        }

        @media (max-width: 760px) {
            .grid {
                grid-template-columns: 1fr;
            }

            .form-grid {
                grid-template-columns: 1fr;
            }

            .wizard-nav {
                grid-template-columns: 1fr;
            }

            .btn {
                width: 100%;
                text-align: center;
            }
        }
    </style>
</head>
<body>
<div class="container" id="wizardRoot" data-initial-step="<?= (int)$initialStep ?>">
    <div class="card">
        <h1>Exact Online API Tester</h1>
        <p>Follow the guided steps to configure credentials, authorize, and test your Exact connection.</p>
        <?php if ($flash): ?>
            <div class="alert <?= h($flash['type']) ?>"><?= h($flash['message']) ?></div>
        <?php endif; ?>
        <div class="wizard-nav">
            <div class="wizard-step" data-step-indicator="1">Step 1: Settings</div>
            <div class="wizard-step" data-step-indicator="2">Step 2: OAuth Connect</div>
            <div class="wizard-step" data-step-indicator="3">Step 3: Test and Logs</div>
        </div>
    </div>

    <div class="card grid">
        <div>
            <h3>Connection</h3>
            <div class="row"><span class="label">Status:</span> <?= h($tokenStatus) ?></div>
            <div class="row"><span class="label">Client ID:</span> <?= h($config['client_id'] ?: 'Not configured') ?></div>
            <div class="row"><span class="label">Redirect URI:</span> <?= h($config['redirect_uri']) ?></div>
            <div class="row"><span class="label">Scope:</span> <?= h($config['scope']) ?></div>
        </div>
        <div>
            <h3>Endpoints</h3>
            <div class="row"><span class="label">OAuth start:</span> /oauth_start.php</div>
            <div class="row"><span class="label">OAuth callback:</span> /oauth_callback.php</div>
            <div class="row"><span class="label">Webhook:</span> /webhook.php</div>
        </div>
    </div>

    <section class="wizard-panel" data-step-panel="1">
        <div class="card">
            <h3>Step 1: Connection Settings</h3>
            <p>Enter Exact values and save. This writes values into your local .env file.</p>
            <form method="post" action="/save_settings.php" id="settingsForm">
                <div class="form-grid">
                    <div class="field">
                        <label for="exact_client_id">Exact Client ID</label>
                        <input id="exact_client_id" name="exact_client_id" type="text" value="<?= h($formDefaults['exact_client_id']) ?>" required>
                    </div>
                    <div class="field">
                        <label for="exact_client_secret">Exact Client Secret</label>
                        <input id="exact_client_secret" name="exact_client_secret" type="password" value="<?= h($formDefaults['exact_client_secret']) ?>" required>
                    </div>
                    <div class="field">
                        <label for="exact_redirect_uri">Exact Redirect URI</label>
                        <input id="exact_redirect_uri" name="exact_redirect_uri" type="url" value="<?= h($formDefaults['exact_redirect_uri']) ?>" required>
                    </div>
                    <div class="field">
                        <label for="exact_base_url">Exact Base URL</label>
                        <input id="exact_base_url" name="exact_base_url" type="url" value="<?= h($formDefaults['exact_base_url']) ?>" required>
                    </div>
                    <div class="field">
                        <label for="exact_scope">Exact Scope</label>
                        <input id="exact_scope" name="exact_scope" type="text" value="<?= h($formDefaults['exact_scope']) ?>" required>
                    </div>
                    <div class="field">
                        <label for="webhook_secret">Webhook Secret</label>
                        <input id="webhook_secret" name="webhook_secret" type="text" value="<?= h($formDefaults['webhook_secret']) ?>">
                    </div>
                </div>
                <div class="btns">
                    <button class="btn btn-primary" type="submit" name="action" value="save">Save Settings</button>
                    <button class="btn btn-ghost" type="button" id="toStep2">Continue to OAuth Step</button>
                </div>
            </form>
        </div>
    </section>

    <section class="wizard-panel" data-step-panel="2">
        <div class="card">
            <h3>Step 2: Authorize with Exact OAuth</h3>
            <p>Click connect, finish consent in Exact, then return here.</p>
            <div class="btns">
                <a class="btn btn-primary" href="/oauth_start.php">Connect to Exact Online</a>
                <button class="btn btn-ghost" type="button" id="backToStep1">Back to Settings</button>
                <button class="btn btn-ghost" type="button" id="toStep3">Continue to Test Step</button>
            </div>
            <p class="mini">If token exists, status above should show connected after OAuth callback.</p>
        </div>
    </section>

    <section class="wizard-panel" data-step-panel="3">
        <div class="card">
            <h3>Step 3: Test Connection and Review Logs</h3>
            <p>Run connection test. If it fails, detailed messages appear below and in storage/connection.log.</p>
            <div class="btns">
                <a class="btn btn-primary" href="/test_connection.php">Run Exact Connection Test</a>
                <a class="btn btn-ghost" href="/refresh_token.php">Refresh Access Token</a>
                <button class="btn btn-ghost" type="button" id="backToStep2">Back to OAuth Step</button>
            </div>
        </div>

        <?php if ($token): ?>
            <div class="card">
                <h3>Saved Token (Masked)</h3>
                <?php
                $masked = $token;
                foreach (['access_token', 'refresh_token'] as $field) {
                    if (!empty($masked[$field])) {
                        $masked[$field] = substr((string)$masked[$field], 0, 8) . '...';
                    }
                }
                ?>
                <pre><?= h((string)json_encode($masked, JSON_PRETTY_PRINT)) ?></pre>
            </div>
        <?php endif; ?>

        <?php if ($apiResult !== null): ?>
            <div class="card">
                <h3>Last API Result</h3>
                <pre><?= h((string)json_encode($apiResult, JSON_PRETTY_PRINT)) ?></pre>
            </div>
        <?php endif; ?>

        <?php if (!empty($connectionLogs)): ?>
            <div class="card">
                <h3>Connection Logs</h3>
                <pre><?= h(implode(PHP_EOL, $connectionLogs)) ?></pre>
            </div>
        <?php endif; ?>
    </section>

    <div class="card">
        <h3>Quick Actions</h3>
        <div class="btns">
            <button class="btn btn-ghost" type="button" data-jump-step="1">Go to Step 1</button>
            <button class="btn btn-ghost" type="button" data-jump-step="2">Go to Step 2</button>
            <button class="btn btn-ghost" type="button" data-jump-step="3">Go to Step 3</button>
        </div>
    </div>

    <script>
        window.EXACT_WIZARD_CONFIG = {
            initialStep: <?= (int)$initialStep ?>
        };
    </script>
    <script src="/assets/connection-wizard.js"></script>
</div>
</body>
</html>
