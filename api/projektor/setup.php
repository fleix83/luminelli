<?php
declare(strict_types=1);

/**
 * One-time setup (CLI only), safe to re-run:
 *   php api/projektor/setup.php
 *
 * - creates the sandbox environment (once)
 * - creates the agent, or updates it to a new version when prompt/model/
 *   effort changed (running sessions keep their version)
 * - uploads the 8 configurator fonts to the Files API (once)
 *
 * IDs are stored in api/storage/projektor/setup.json.
 */

require __DIR__ . '/lib/bootstrap.php';
pj_require_cli();

$pj = pj_config();
if (empty($pj['anthropic_api_key'])) {
    fwrite(STDERR, "Set projektor.anthropic_api_key in api/config.php first.\n");
    exit(1);
}
$client = pj_client($pj);
$setup = pj_setup();

// 1. Environment: Anthropic-hosted container, no open internet. Package
//    managers (pip/npm) are allowed so the agent can try to install a
//    headless browser for screenshots; web_fetch is not affected by this.
if (empty($setup['environment_id'])) {
    $env = $client->beta->environments->create(
        name: 'luminelli-projektor',
        config: [
            'type' => 'cloud',
            'networking' => [
                'type' => 'limited',
                'allow_package_managers' => true,
                'allowed_hosts' => ['playwright.azureedge.net', 'cdn.playwright.dev', 'playwright.download.prss.microsoft.com'],
            ],
        ],
        description: 'Sandbox for the Studio Luminelli Projektor (draft generator)',
    );
    $setup['environment_id'] = $env->id;
    pj_save_setup($setup);
    echo "Environment created: {$env->id}\n";
} else {
    echo "Environment: {$setup['environment_id']}\n";
}

// 2. Agent
$system = (string) file_get_contents(__DIR__ . '/prompt/system.md');
$model = ['id' => (string) $pj['model'], 'effort' => (string) $pj['effort']];
$tools = [[
    'type' => 'agent_toolset_20260401',
    'default_config' => ['enabled' => true, 'permission_policy' => ['type' => 'always_allow']],
    'configs' => [
        ['name' => 'web_search', 'enabled' => false],
        ['name' => 'web_fetch', 'enabled' => true, 'max_content_tokens' => 30000],
    ],
]];
$fingerprint = hash('sha256', json_encode([$system, $model, $tools]));

if (empty($setup['agent_id'])) {
    $agent = $client->beta->agents->create(
        model: $model,
        name: 'Luminelli Projektor',
        description: 'Builds website and web app drafts from configurator requests',
        system: $system,
        tools: $tools,
    );
    echo "Agent created: {$agent->id} (version {$agent->version})\n";
} elseif (($setup['agent_fingerprint'] ?? '') !== $fingerprint) {
    $agent = $client->beta->agents->update($setup['agent_id'], model: $model, system: $system, tools: $tools);
    echo "Agent updated: {$agent->id} → version {$agent->version}\n";
} else {
    echo "Agent unchanged: {$setup['agent_id']} (version {$setup['agent_version']})\n";
}
if (isset($agent)) {
    $setup['agent_id'] = $agent->id;
    $setup['agent_version'] = $agent->version;
    $setup['agent_fingerprint'] = $fingerprint;
    pj_save_setup($setup);
}

// 3. Fonts (permanent files, mounted read-only into each session)
$setup['font_files'] ??= [];
foreach (array_keys(PJ_FONTS) as $slug) {
    if (!empty($setup['font_files'][$slug])) {
        continue;
    }
    $meta = $client->beta->files->upload(
        file: Anthropic\Core\FileParam::fromString((string) file_get_contents(PJ_FONT_DIR . "/$slug.woff2"), "$slug.woff2", 'font/woff2'),
    );
    $setup['font_files'][$slug] = $meta->id;
    pj_save_setup($setup);
    echo "Font uploaded: $slug → {$meta->id}\n";
}

echo "Setup complete. Set projektor.enabled = true in api/config.php to open the Projektor.\n";
