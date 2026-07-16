<?php

declare(strict_types=1);

use LearningMcp\Analyzer;
use LearningMcp\Config;
use LearningMcp\ExecutionRunService;
use LearningMcp\IntelligenceService;
use LearningMcp\ProcessRunner;
use LearningMcp\ProjectIndex;
use LearningMcp\ProjectIndexer;
use LearningMcp\ProjectRetriever;
use LearningMcp\ProjectResolver;
use LearningMcp\SparseVectorizer;
use LearningMcp\Store;
use LearningMcp\ToolException;
use LearningMcp\ToolService;

require dirname(__DIR__) . '/src/bootstrap.php';

$root = dirname(__DIR__, 4);
$mode = in_array('--full', $argv, true) ? 'full' : 'quick';
$temporary = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'weline-mcp-tests-' . bin2hex(random_bytes(5));
$checks = [];
$failed = false;

function check(bool $condition, string $label): void
{
    global $checks, $failed;
    $checks[] = ['label' => $label, 'passed' => $condition];
    if (!$condition) {
        $failed = true;
        fwrite(STDERR, "[FAIL] $label
");
    } else {
        fwrite(STDOUT, "[PASS] $label
");
    }
}

function removeTree(string $path): void
{
    if (!is_dir($path)) {
        if (is_file($path) || is_link($path)) {
            @unlink($path);
        }
        return;
    }
    $items = scandir($path);
    if (!is_array($items)) {
        return;
    }
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        removeTree($path . DIRECTORY_SEPARATOR . $item);
    }
    @rmdir($path);
}

/** @return array<string,mixed> */
function editableBundle(string $bundleId, int $revision = 7): array
{
    return [
        'bundle_id' => $bundleId,
        'index_revision' => $revision,
        'ready_for_edit' => true,
        'candidate_files' => [[
            'path' => 'src/Example.php',
            'materialized' => true,
            'selected' => true,
            'reasons' => ['explicit path', 'definition'],
        ], [
            'path' => 'docs/unused.md',
            'materialized' => false,
            'excluded_reason' => 'not required by final edit context',
        ]],
        'exact_regions' => [[
            'path' => 'src/Example.php',
            'start_line' => 10,
            'end_line' => 18,
            'symbol' => 'Example::run',
            'expected_file_sha256' => 'sha256:' . str_repeat('a', 64),
            'expected_digest' => 'sha256:' . str_repeat('b', 64),
        ]],
        'context_completeness' => [
            'status' => 'complete',
            'score' => 100,
            'covered' => ['architecture', 'definitions', 'tests'],
            'missing' => [],
        ],
        'architecture_summary' => ['phase' => 'materialization', 'missing_roles' => []],
        'impact_summary' => ['risk' => 'low', 'dependency_edge_count' => 1],
        'validation_plan' => ['fixed_checks_in_apply' => ['syntax', 'regression', 'diff_check']],
        'impacts' => [['symbol' => 'Example::run', 'upstream_files' => ['src/Caller.php']]],
    ];
}

/** @return array<string,mixed> */
function editPlan(string $runId, string $bundleId, string $reason = 'NORMAL'): array
{
    return [
        'schema_version' => 'edit-plan.v1',
        'metadata' => [
            'run_id' => $runId,
            'bundle_id' => $bundleId,
            'recursion_reason' => $reason,
        ],
        'operations' => [[
            'kind' => 'replace_symbol',
            'path' => 'src/Example.php',
            'target_ref' => 'Example::run',
            'replacement' => "public function run(): bool
{
    return true;
}",
        ]],
    ];
}

/** @return array<string,mixed> */
function applyResult(string $editId, bool $validationPassed = true, bool $impactExpansion = false): array
{
    return [
        'edit_id' => $editId,
        'state' => $validationPassed ? 'validated' : 'rolled_back',
        'index_revision' => 8,
        'validation' => [
            'status' => $validationPassed ? 'passed' : 'failed',
            'checks' => [[
                'check' => 'php_lint',
                'path' => 'src/Example.php',
                'status' => $validationPassed ? 'passed' : 'failed',
            ]],
        ],
        'regression_validation' => [
            'status' => $validationPassed ? 'passed' : 'skipped',
            'profile' => 'fixture',
        ],
        'rolled_back' => !$validationPassed,
        'change_report' => [
            'files' => [[
                'path' => 'src/Example.php',
                'before_sha256' => 'sha256:' . str_repeat('a', 64),
                'after_sha256' => 'sha256:' . str_repeat('c', 64),
                'diff' => "--- a/src/Example.php
+++ b/src/Example.php
@@ -10 +10 @@
-false
+true
",
                'diff_truncated' => false,
            ]],
        ],
        'impact_delta' => [
            'requires_followup' => $impactExpansion,
            'new_affected_paths' => $impactExpansion ? ['src/NewConsumer.php'] : [],
            'new_affected_symbols' => $impactExpansion ? ['NewConsumer::call'] : [],
            'status' => 'complete',
        ],
        'timing_ms' => ['total' => 12],
    ];
}

try {
    mkdir($temporary, 0700, true);
    $configPath = $temporary . '/config.json';
    file_put_contents($configPath, json_encode([
        'data_dir' => $temporary . '/data',
        'analysis' => ['provider' => 'none'],
    ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
    $config = Config::load($configPath);
    $store = new Store($config);
    check($store->schemaVersion() === Store::SCHEMA_VERSION, 'global migrations reach schema version 2');

    $fixtureRoot = $temporary . '/explicit-path-project';
    foreach (['src', 'tests', 'ui', 'docs', 'scripts'] as $directory) {
        mkdir($fixtureRoot . '/' . $directory, 0700, true);
    }
    file_put_contents($fixtureRoot . '/src/Example.php', "<?php\nfinal class Example {}\n");
    $rankedMethods = '';
    $rankedPayload = var_export(str_repeat(
        'MCP UI plugin documentation validation retrieval service context bundle ',
        120,
    ), true);
    for ($method = 0; $method < 8; ++$method) {
        $rankedMethods .= ' public function context' . $method
            . '(): string { return ' . $rankedPayload . "; }\n";
    }
    file_put_contents(
        $fixtureRoot . '/src/ToolService.php',
        "<?php\nfinal class ToolService {\n"
            . " public function definitions(): array { return []; }\n"
            . $rankedMethods
            . "}\n",
    );
    file_put_contents(
        $fixtureRoot . '/src/ProjectRetriever.php',
        "<?php\nfinal class ProjectRetriever { public function search(): array { return []; } }\n",
    );
    file_put_contents(
        $fixtureRoot . '/src/McpServer.php',
        "<?php\nfinal class McpServer { public function run(): void {} }\n",
    );
    file_put_contents($fixtureRoot . '/ui/panel.html', "<!doctype html><main>Execution panel</main>\n");
    file_put_contents($fixtureRoot . '/docs/README.md', "# MCP architecture and usage\n");
    file_put_contents($fixtureRoot . '/scripts/install.php', "<?php\nreturn true;\n");
    file_put_contents(
        $fixtureRoot . '/tests/ExplicitTest.php',
        "<?php\nfinal class ExplicitTest { public function testIt(): bool { return true; } }\n",
    );
    file_put_contents(
        $fixtureRoot . '/tests/ContextClosureTest.php',
        "<?php\nfinal class ContextClosureTest { public function testBundle(): bool { return true; } }\n",
    );
    $fixtureResolved = ProjectResolver::resolve($fixtureRoot);
    $fixtureIndex = new ProjectIndex($config, $fixtureResolved);
    $fixtureIndexer = new ProjectIndexer($fixtureIndex, $config);
    $fixtureFull = $fixtureIndexer->index(['mode' => 'full']);
    check(
        !in_array('tests/ExplicitTest.php', $fixtureFull['changed_paths'] ?? [], true),
        'ordinary full indexing excludes test directories',
    );
    $anchorSearch = (new ProjectRetriever(
        $fixtureIndex,
        new SparseVectorizer($config),
        $config,
    ))->search(
        'MCP UI plugin documentation validation retrieval service context bundle',
        [
            'paths' => ['src/ToolService.php', 'src/McpServer.php'],
            'limit' => 2,
            'max_chunks_per_file' => 2,
            'token_budget' => 1_200,
            'per_result_token_budget' => 500,
        ],
    );
    $anchorPaths = array_values(array_unique(array_map(
        static fn (array $result): string => (string) ($result['relative_path'] ?? ''),
        $anchorSearch['results'] ?? [],
    )));
    check(
        in_array('src/ToolService.php', $anchorPaths, true)
            && in_array('src/McpServer.php', $anchorPaths, true),
        'path-scoped retrieval anchors one region per exact path before repeat chunks',
    );
    $fixtureExplicit = $fixtureIndexer->indexPaths(['tests/ExplicitTest.php']);
    check(
        in_array('tests/ExplicitTest.php', $fixtureExplicit['changed_paths'] ?? [], true),
        'explicit test path is materialized in one bounded batch',
    );
    $fixtureIndex->close();

    $fixtureIntelligence = new IntelligenceService($store, $config);
    $closureBundle = $fixtureIntelligence->call('get_edit_bundle', [
        'repository' => $fixtureRoot,
        'task' => 'MCP UI plugin documentation validation retrieval service one-call context closure',
        'paths' => ['src/ToolService.php', 'ui/panel.html'],
        'task_contract' => [
            'goal' => 'Close all expected context roles inside one bundle call',
            'known_paths' => ['src/ToolService.php', 'ui/panel.html'],
            'requirements' => ['discover and materialize architecture roles server-side'],
            'acceptance_criteria' => ['ready_for_edit is true', 'continuation is not needed'],
            'allowed_scope' => ['current_project'],
            'authorized_actions' => ['read indexed context'],
            'forbidden_scope' => ['writes', 'external systems'],
        ],
        'include_skills' => false,
    ]);
    check($closureBundle['ready_for_edit'] === true, 'one bundle closes server-discovered architecture roles');
    check($closureBundle['continuation_needed'] === false, 'closed bundle requires no external continuation');
    check(
        count($closureBundle['selected_files'] ?? []) > 2,
        'one bundle materializes multiple related files beyond explicit paths',
    );
    check(
        ($closureBundle['context_completeness']['missing_roles'] ?? ['missing']) === [],
        'role discovery covers entrypoint, view, plugin, retrieval, docs and tests',
    );
    check(
        in_array(
            'tests/ContextClosureTest.php',
            $closureBundle['on_demand_index']['changed_paths'] ?? [],
            true,
        ),
        'cold test context is discovered and indexed inside the same MCP call',
    );
    check(
        ($closureBundle['execution_run']['counters']['get_edit_bundle_calls'] ?? 0) === 1,
        'server-side role closure remains one audited bundle call',
    );
    $originalCwd = getcwd();
    if (!is_string($originalCwd) || !chdir($fixtureRoot)) {
        throw new RuntimeException('Unable to enter repository inference fixture');
    }
    try {
        $inferredBundle = $fixtureIntelligence->call('get_edit_bundle', [
            'task' => 'MCP UI plugin documentation validation retrieval service inferred repository',
            'paths' => ['src/ToolService.php', 'ui/panel.html'],
            'include_skills' => false,
        ]);
    } finally {
        chdir($originalCwd);
    }
    check(
        ($inferredBundle['repository_resolution']['source'] ?? '')
            === 'process_cwd_validated_by_paths',
        'omitted repository is safely inferred only after all known paths validate',
    );
    check(
        ($inferredBundle['repository_resolution']['repository'] ?? '') === realpath($fixtureRoot),
        'inferred repository preserves canonical directory project identity',
    );
    unset($fixtureIntelligence);

    $resolved = ProjectResolver::resolve($root);
    $store->upsertProject($resolved['project']);
    $projectId = (string) $resolved['project']['id'];
    $runs = new ExecutionRunService($store, $config);
    $contract = [
        'goal' => 'Change one fixture safely',
        'known_paths' => ['src/Example.php'],
        'known_symbols' => ['Example::run'],
        'acceptance_criteria' => ['all checks pass'],
        'active_skills' => null,
        'active_skills_display' => '宿主未提供',
        'instruction_sources' => ['AGENTS.md'],
        'validation_expectations' => ['syntax', 'regression'],
    ];

    $normal = $runs->begin(
        $projectId,
        'Update fixture; api_key=sk-test-12345678901234567890',
        $contract,
        7,
    );
    $normal = $runs->completeBundle(
        (string) $normal['run_id'],
        $projectId,
        editableBundle('bundle-normal'),
    );
    check($normal['status'] === 'waiting_for_plan', 'complete bundle waits for one edit plan');
    $runs->beginApply((string) $normal['run_id'], $projectId, editPlan((string) $normal['run_id'], 'bundle-normal'));
    $normal = $runs->completeApply(
        (string) $normal['run_id'],
        $projectId,
        applyResult('edit-normal'),
    );
    check($normal['status'] === 'completed' && $normal['terminal'] === true, 'normal run completes atomically');
    check(($normal['counters']['apply_compact_edit_calls'] ?? 0) === 1, 'normal run records one apply call');
    check(($normal['counters']['successful_apply_compact_edit_calls'] ?? 0) === 1, 'normal run records one successful apply');
    $trace = $runs->trace($projectId, (string) $normal['run_id'], [
        'include_files' => true,
        'include_diffs' => true,
    ]);
    check(count($trace['events']) >= 8, 'timeline persists all major phases');
    check(count($trace['files']) === 2, 'candidate and excluded files persist');
    check(str_contains((string) $trace['files'][0]['diff'], '+true'), 'bounded file diff is reviewable');
    check(!str_contains(json_encode($trace, JSON_THROW_ON_ERROR), 'sk-test-12345678901234567890'), 'trace redacts credentials');
    $parseEvent = array_values(array_filter(
        $trace['events'],
        static fn (array $event): bool => ($event['operation_name'] ?? '') === 'parse_task_contract',
    ))[0] ?? [];
    check(
        ($parseEvent['input_summary']['active_skills_declared'] ?? true) === false,
        'null active skills are reported as host not supplied',
    );
    $conflict = $runs->begin($projectId, 'Conflict fixture', $contract, 8);
    $conflict = $runs->completeBundle((string) $conflict['run_id'], $projectId, editableBundle('bundle-conflict', 8));
    $runs->beginApply((string) $conflict['run_id'], $projectId, editPlan((string) $conflict['run_id'], 'bundle-conflict'));
    $runs->fail((string) $conflict['run_id'], $projectId, new ToolException(
        'EDIT_REPLAN_REQUIRED',
        'fixture hash changed',
        true,
        ['latest_regions' => editableBundle('latest')['exact_regions']],
    ));
    $conflict = $runs->status($projectId, (string) $conflict['run_id']);
    check($conflict['workflow_state'] === 'CONFLICT_REPLAN' && !$conflict['terminal'], 'conflict enters typed bounded replan');

    $validation = $runs->begin($projectId, 'Validation fixture', $contract, 8);
    $validation = $runs->completeBundle((string) $validation['run_id'], $projectId, editableBundle('bundle-validation', 8));
    $runs->beginApply((string) $validation['run_id'], $projectId, editPlan((string) $validation['run_id'], 'bundle-validation'));
    $validation = $runs->completeApply(
        (string) $validation['run_id'],
        $projectId,
        applyResult('edit-validation', false),
    );
    check($validation['workflow_state'] === 'VALIDATION_REPAIR', 'validation failure enters typed repair');
    check(($validation['counters']['automatic_rollback_count'] ?? 0) === 1, 'validation failure records automatic rollback');

    $impact = $runs->begin($projectId, 'Impact fixture', $contract, 8);
    $impact = $runs->completeBundle((string) $impact['run_id'], $projectId, editableBundle('bundle-impact', 8));
    $runs->beginApply((string) $impact['run_id'], $projectId, editPlan((string) $impact['run_id'], 'bundle-impact'));
    $impact = $runs->completeApply(
        (string) $impact['run_id'],
        $projectId,
        applyResult('edit-impact', true, true),
    );
    check($impact['workflow_state'] === 'IMPACT_EXPANSION' && !$impact['terminal'], 'new consumer enters bounded impact expansion');

    $scope = $runs->begin(
        $projectId,
        'Expanded user scope',
        $contract,
        8,
        (string) $normal['run_id'],
    );
    $old = $runs->status($projectId, (string) $normal['run_id']);
    check($old['status'] === 'superseded' && $scope['task_id'] === $old['task_id'], 'user scope change supersedes while preserving task identity');

    $tools = new ToolService($store, $config, new Analyzer($store, $config));
    $definitions = $tools->definitions();
    $names = array_column($definitions, 'name');
    foreach (['get_edit_bundle', 'apply_compact_edit', 'get_run_status', 'get_run_trace', 'validate_change'] as $name) {
        check(in_array($name, $names, true), "compact tool surface exposes $name");
    }
    $getBundleDefinition = array_values(array_filter(
        $definitions,
        static fn (array $definition): bool => ($definition['name'] ?? '') === 'get_edit_bundle',
    ))[0] ?? [];
    $getBundleRequired = $getBundleDefinition['inputSchema']['required'] ?? [];
    check(
        in_array('task', $getBundleRequired, true) && !in_array('repository', $getBundleRequired, true),
        'get_edit_bundle schema permits guarded current-directory inference',
    );
    check(ToolService::VERSION === '0.11.0', 'tool service version is 0.11.0');
    check(str_contains(substr(ToolService::INSTRUCTIONS, 0, 512), 'get_edit_bundle once'), 'first 512 instruction characters contain one-bundle rule');
    check(str_contains(substr(ToolService::INSTRUCTIONS, 0, 512), 'Never omit repository'), 'first 512 instruction characters require repository');
    check(str_contains(substr(ToolService::INSTRUCTIONS, 0, 512), 'run_id and bundle_id'), 'first 512 instruction characters contain run binding');

    $ui = file_get_contents(dirname(__DIR__) . '/ui/execution-run-v1.html');
    check(is_string($ui) && str_contains($ui, 'window.openai.callTool'), 'MCP App performs host tool refresh');
    check(str_contains((string) $ui, 'data-theme') && str_contains((string) $ui, 'prefers-color-scheme'), 'MCP App supports host and system themes');
    check(str_contains((string) $ui, 'aria-live') && str_contains((string) $ui, 'focus-visible'), 'MCP App includes accessibility states');
    check(str_contains((string) $ui, 'get_run_trace') && str_contains((string) $ui, 'include_diffs'), 'MCP App retrieves live trace and terminal diffs');

    $runner = new ProcessRunner();
    $installConfig = $temporary . '/install/config.yaml';
    $marketplace = $temporary . '/marketplace';
    $dryRun = $runner->run(
        [
            PHP_BINARY,
            dirname(__DIR__) . '/scripts/install.php',
            'install',
            '--dry-run',
            '--config=' . $installConfig,
            '--marketplace-dir=' . $marketplace,
        ],
        $root,
        '',
        30,
    );
    check($dryRun['exit_code'] === 0, 'installer dry-run succeeds without changing Codex');
    $manifest = json_decode((string) file_get_contents(
        $marketplace . '/plugins/weline-project-intelligence/.codex-plugin/plugin.json'
    ), true, 512, JSON_THROW_ON_ERROR);
    $prompts = $manifest['interface']['defaultPrompt'] ?? [];
    check(count($prompts) <= 3, 'plugin defaultPrompt has at most three entries');
    check(array_reduce($prompts, static fn (bool $ok, string $prompt): bool => $ok && mb_strlen($prompt) <= 128, true), 'every defaultPrompt entry is at most 128 characters');
    $mcpConfig = json_decode((string) file_get_contents(
        $marketplace . '/plugins/weline-project-intelligence/.mcp.json'
    ), true, 512, JSON_THROW_ON_ERROR);
    $enabled = $mcpConfig['mcpServers']['weline-project-intelligence']['enabled_tools'] ?? [];
    check(in_array('get_run_status', $enabled, true) && in_array('get_run_trace', $enabled, true), 'generated plugin enables execution-run tools');

    $protocolInput = implode("
", [
        json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => '2025-06-18',
                'capabilities' => [],
                'clientInfo' => ['name' => 'weline-tests', 'version' => '1'],
            ],
        ], JSON_THROW_ON_ERROR),
        json_encode(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list', 'params' => []], JSON_THROW_ON_ERROR),
        json_encode(['jsonrpc' => '2.0', 'id' => 3, 'method' => 'resources/list', 'params' => []], JSON_THROW_ON_ERROR),
    ]) . "
";
    $protocol = $runner->run(
        [PHP_BINARY, dirname(__DIR__) . '/bin/learning-mcp', '--config', $configPath],
        $root,
        $protocolInput,
        30,
        ['WELINE_MCP_TOOL_PROFILE' => 'compact'],
    );
    check($protocol['exit_code'] === 0, 'stdio MCP protocol smoke exits cleanly');
    $responses = [];
    foreach (explode(chr(10), trim($protocol['stdout'])) as $line) {
        $decoded = json_decode($line, true);
        if (is_array($decoded) && isset($decoded['id'])) {
            $responses[(int) $decoded['id']] = $decoded;
        }
    }
    check(str_contains((string) ($responses[1]['result']['instructions'] ?? ''), 'get_edit_bundle once'), 'initialize exposes closed-loop instructions');
    $protocolTools = array_column($responses[2]['result']['tools'] ?? [], 'name');
    check(in_array('get_run_trace', $protocolTools, true), 'protocol tools/list exposes run trace');
    $resourceUris = array_column($responses[3]['result']['resources'] ?? [], 'uri');
    check(in_array(ToolService::EXECUTION_RUN_RESOURCE_URI, $resourceUris, true), 'protocol resources/list exposes execution panel');

    if ($mode === 'full') {
        $acceptance = $runner->run(
            [PHP_BINARY, __DIR__ . '/acceptance.php'],
            $root,
            '',
            60,
        );
        check($acceptance['exit_code'] === 0, 'three-directory acceptance succeeds');
        if ($acceptance['stdout'] !== '') {
            fwrite(STDOUT, $acceptance['stdout']);
        }
        if ($acceptance['stderr'] !== '') {
            fwrite(STDERR, $acceptance['stderr']);
        }
    }
} catch (Throwable $exception) {
    $failed = true;
    fwrite(STDERR, '[ERROR] ' . $exception::class . ': ' . $exception->getMessage() . "
");
} finally {
    removeTree($temporary);
}

$passed = count(array_filter($checks, static fn (array $check): bool => $check['passed']));
fwrite(STDOUT, sprintf("Weline MCP %s tests: %d/%d passed
", $mode, $passed, count($checks)));
exit($failed ? 1 : 0);
