# Weline MCP

[简体中文](README.md) · **English** · [日本語](README.ja.md)

**Upgrade Codex from file-by-file probing to task-level batch coding.**

**Use fewer tokens, retrieve context faster, isolate projects automatically, protect concurrent sessions, and turn verified outcomes into reusable Skills.**

Weline MCP is a local-first project intelligence, transactional editing, and evidence-backed learning engine for Codex.

1. When the owning architecture or call chain is unknown, call `get_edit_bundle` as a discovery batch with the full task and relevant symbols/module/kinds, omitting paths only to discover unknown related files.
2. Once candidates are known, submit every relevant path and affected symbol in one materialization batch; MCP refreshes explicit paths and returns bounded regions, impacts, docs, rules, and Skills.
3. After each bundle, reason over accumulated context. If it is incomplete, collect all missing paths, symbols, and semantic search goals, then request another broad batch instead of reading files one by one.
4. Once context is sufficient, Codex submits one complete `edit-plan.v1`.
5. `apply_compact_edit` locks targets, rechecks hashes, prepares non-overlapping changes, commits atomically, validates, reindexes, and rolls back safely when needed.

The runtime needs PHP 8.2+ and SQLite extensions. Startup scripts also prepare Git for distribution and VCS-aware validation, but project identity, indexing, and transactional edits do not require the current directory to be a Git repository. Git projects retain the HEAD guard; non-Git projects use a stable canonical-directory baseline while revision, file hashes, target digests, locks, journal, validation, and rollback remain mandatory. Composer and Node.js are optional distribution surfaces. The npm package is a dependency-free process wrapper; the same PHP STDIO server owns the protocol and data.

## Core capabilities

- Persistent incremental index for code, docs, symbols, relationships, rules, and Skills.
- Exact path/symbol lookup plus full-text, trigram, local sparse retrieval, and symbol impact analysis.
- Token-budgeted excerpts instead of repository dumps.
- Cross-process file locks, deterministic multi-file lock order, hashes, journal, atomic replacement, validation, and safe rollback.
- Bounded kernel `flock` waits with owner diagnostics; persistent `.lock` files do not represent ownership, and the next edit/status call reconciles crash-interrupted transactions by sealed pre/postimage hashes.
- One independent `project.sqlite` per canonical absolute directory; different directories never merge, even inside one Git repository.
- Evidence, scope, duplicate, conflict, confidence, maturity, expiration, and code-drift checks before generating `SKILL.md`.

“Zero configuration per project” starts after one global MCP registration. A local MCP cannot register itself before Codex discovers it.

## Requirements

- PHP 8.2+
- `pdo_sqlite`, `json`, `mbstring`, `openssl`
- Git
- Codex CLI for the default verified-experience classifier
- Optional `pcntl`/`posix` for short asynchronous workers

## Install from source

GitHub:

```bash
git clone https://github.com/Aiweline/Weline-Codex-Mcp.git
cd Weline-Codex-Mcp
./start.sh
```

Gitee:

```bash
git clone https://gitee.com/aiweline/weline-codex-mcp.git
cd weline-codex-mcp
./start.sh
```

Windows uses `start.bat`. Both scripts check and, where supported, install PHP, extensions, and Git; create the user config when missing; then start the STDIO server. Diagnostics use stderr so JSON-RPC stdout stays clean.

## Composer

Available immediately through the GitHub VCS repository:

```bash
composer global config repositories.weline-mcp vcs https://github.com/Aiweline/Weline-Codex-Mcp
composer global require aiweline/weline-codex-mcp:^0.9
composer global exec -- weline-mcp-install --register-codex
```

Use the Gitee URL if preferred. After Packagist publication:

```bash
composer global require aiweline/weline-codex-mcp
```

The explicit installer preserves existing configuration and changes Codex registration only with `--register-codex`. Composer is not a runtime dependency.

## Node/npm wrapper

Install from GitHub now:

```bash
npm install -g git+https://github.com/Aiweline/Weline-Codex-Mcp.git
codex mcp add weline -- weline-mcp
```

Gitee can be used with `git+https://gitee.com/aiweline/weline-codex-mcp.git`. After npm Registry publication:

```bash
npm install -g weline-codex-mcp
codex mcp add weline -- weline-mcp
```

The wrapper forwards stdio, arguments, environment, exit status, and signals to PHP. Set `WELINE_MCP_PHP` or `PHP_BINARY` to select PHP.

## Connect Codex

```bash
codex mcp add weline -- /absolute/path/to/Weline-Codex-Mcp/bin/learning-mcp --config /absolute/path/to/config.yaml
codex mcp list
```

Codex Desktop, CLI, and IDE Extension share MCP configuration on the same host. Desktop/IDE can also add an STDIO server from **Settings → MCP servers**.

Manual TOML:

```toml
[mcp_servers.weline]
command = "/absolute/path/to/Weline-Codex-Mcp/bin/learning-mcp"
args = ["--config", "/absolute/path/to/config.yaml"]
startup_timeout_sec = 20
tool_timeout_sec = 120
```

## Configure Skill output

```yaml
knowledge:
  learning_skills:
    output_directory: ".codex/skills"
```

The default config is `~/.learning-mcp/config.yaml`; Windows uses `%USERPROFILE%\.learning-mcp\config.yaml`. Relative output paths resolve under each target repository. Absolute paths work, but an external directory is not indexed with that project unless the Host loads it. Environment overrides: `LEARNING_MCP_CONFIG` and `LEARNING_MCP_SKILL_OUTPUT_DIR`.

## MCP App and one-command lifecycle

`apply_compact_edit` and `get_edit_status` attach an MCP App report with transaction state, changed files, insertions/deletions, validation, warnings, and a bounded diff for each file. The report includes an all-changed-files review contract so Codex can audit every hunk in one read-only pass; structured/text fallbacks remain available.

macOS/Linux:

```bash
tmp=/tmp/weline-mcp-install.sh; curl -fsSL https://raw.githubusercontent.com/Aiweline/Weline-Codex-Mcp/main/install.sh -o "$tmp" && sh "$tmp" install --source=github
tmp=/tmp/weline-mcp-install.sh; curl -fsSL https://gitee.com/aiweline/weline-codex-mcp/raw/main/install.sh -o "$tmp" && sh "$tmp" install --source=gitee
```

Windows PowerShell downloads `start.bat` from the matching GitHub/Gitee raw URL and runs `cmd /c ... install github|gitee`. Uninstall from the managed directory; data is preserved unless `--purge-data` is passed.

## Recommended agent workflow

```markdown
- Treat the normalized absolute directory supplied by the task as the full project boundary; never replace it with an enclosing Git root.
- Use get_edit_bundle first for architecture discovery when targets are unknown, then submit all known paths and affected symbols in broad materialization batches; request another batch only when accumulated context is insufficient.
- Use returned regions, hashes, impacts, docs, and Skills instead of repository-wide scans.
- Submit one edit-plan.v1 and call apply_compact_edit once.
- After apply, review every changed file from change_report.files[].diff and hunk line numbers in one read-only pass; report severity plus path/new-line references, or explicitly report no findings and residual gaps.
- Use get_edit_status only when the apply result is unavailable. Batch truncated/unavailable paths into one follow-up read, batch all fixes into one new edit-plan.v1, and keep rollback_edit for recovery.
```

Tools: `get_edit_bundle`, `apply_compact_edit`, `get_edit_status`, `rollback_edit`, and `health`.

See [operations](docs/OPERATIONS.md), [architecture](docs/ARCHITECTURE.md), [security](docs/SECURITY.md), and [implementation boundaries](docs/IMPLEMENTATION-STATUS.md).

Repositories: [GitHub](https://github.com/Aiweline/Weline-Codex-Mcp) · [Gitee](https://gitee.com/aiweline/weline-codex-mcp) · Apache-2.0
