# Operations

## Process model

- `bin/learningctl hook`：由 Codex 短进程调用，默认非阻断。
- `bin/learning-mcp`：由 Codex Host 按需启动和关闭的 STDIO 进程。
- Index sidecar：第一个索引 Hook 自动启动的本地 PHP/Unix-socket 进程，合并刷新、复用 SQLite 连接，空闲 15 分钟自动退出。
- `bin/learningd drain`：扫描空闲 Session 并清空当前可领取队列后退出，适合周期调度。
- `bin/learningd run`：持续轮询的前台 worker，适合进程管理器。
- Stop Hook：默认 fork 一个短生命周期 PHP worker；`pcntl` 不可用时仍会保留 Job，等待后续 worker。

运行时不需要 WLS、HTTP 端口、Go、Node.js、Composer、Redis、PostgreSQL 或外部消息队列。Composer 包和 Node/npm 壳只是可选发行入口；Node 最终仍启动同一 `bin/learning-mcp` PHP STDIO Server。

Project Intelligence 初次索引和普通查询也不需要 WLS。默认开启的已验证经验分类需要可用的 `codex` CLI；MCP 会依次检查配置绝对路径、`CODEX_CLI_PATH`、PATH 和 ChatGPT.app bundle。文档自动同步仍关闭。

## Bootstrap scripts

- `start.sh`：支持 macOS/Homebrew，以及 APT、DNF/YUM、Pacman、Zypper 系 Linux；检查或安装 PHP 8.2+、必需扩展与 Git，复制缺失的用户配置后 `exec` STDIO MCP。
- `start.bat`：优先 WinGet（`PHP.PHP.8.4`、`Git.Git`），回退 Chocolatey；若 Windows PHP 扩展 DLL 已安装但未启用，会在 `~/.learning-mcp/php-conf.d` 生成进程专用扫描配置，并让 MCP 子进程继承。
- 所有安装与初始化消息都重定向到 stderr。首次安装可能需要 sudo/UAC，所以建议先在交互终端运行一次，再把同一脚本配置给 MCP Host。
- `LEARNING_MCP_CONFIG` 选择配置文件，`LEARNING_MCP_SKILL_OUTPUT_DIR` 覆盖自动学习技能输出目录。

## Automatic Codex startup

当前工作站的 `weline-project-intelligence@personal` 插件已安装、启用并信任 Hook Hash。新建 Codex 任务时会自动：

1. 启动 PHP STDIO MCP；
2. 将 Hook stdin 的 `cwd` 规范化绝对目录直接作为项目边界并选择独立 project ID/index DB；绝不向上替换为 Git root；
3. 注入不含代码正文的索引元数据与 `get_edit_bundle → apply_compact_edit` 精简路由契约；
4. Hook 响应返后自动启动/复用 PHP sidecar，按 canonical project 合并 `index_project incremental`；空索引会自然完成首建；
5. 采集后续 Prompt/Tool/Compact/Stop 事件；`PostToolUse` 跳过严格只读调用，`apply_patch` 仅刷新标记的路径，自索引 MCP 写工具不再二次刷新，未知写入保守走整仓 incremental；Stop 仍默认排队并异步分析。
6. `SessionStart` 和每次 `UserPromptSubmit` 检查已验证经验/技能投影指纹，需要时非阻断启动 worker；当前 Prompt 同时从索引中注入已有的命中生成技能。

无需复制 YAML、编辑 TOML 或手动合并 Hook JSON。已运行任务不动态重载新插件；安装或更新插件后新建任务。

只读核验：

```bash
codex plugin list
codex mcp list
```

`plugin list` 应显示 `weline-project-intelligence@personal  installed, enabled`，`mcp list` 应显示 `weline-project-intelligence  enabled`。如果 Hook 源内容更改，Codex 会将新 Hash 标记为待审核；只能用 `/hooks` 审查并持久化信任，不应使用 `--dangerously-bypass-hook-trust`。

## Health check

```bash
./bin/learningctl doctor --config ~/.learning-mcp/config.yaml
```

输出包括 PHP/扩展检查、SQLite Migration/FTS 能力和数据量、Project Intelligence/Edit 能力、Experience/Job 状态、开放冲突、分析器元数据、LaunchAgent 状态，以及 `learning_skills` 的启用状态、置信度门禁、Prompt 注入、生成器版本、Codex 二进制和两份输出 Schema。MCP `health.project_intelligence.index_sidecar` 另外返回 sidecar 支持度、socket、PID、请求/批次计数和最后刷新模式。

MCP 客户端也可调用 `health`；该工具不会返回 API Key。

通过 MCP 协议调用时，成功和失败的工具结果都应包含 `_weline_mcp.used=true`、唯一 `receipt_id` 和 `response_prefix="Weline："`。收到回执后，本轮后续用户可见汇报必须使用该前缀。`initialize` 或 SessionStart 本身没有回执；使用 `learningctl` 直调业务服务也不会生成 MCP 协议回执。

## Cross-platform install, status, and uninstall

`install.sh` (macOS/Linux) and `start.bat` (Windows) download GitHub or Gitee archives into a marker-protected user directory, then call the platform start script. The PHP installer creates `~/.learning-mcp/codex-marketplace`, registers `weline-project-intelligence@weline-local`, and removes superseded registrations only after replacement succeeds.

Normal uninstall removes plugin variants, the compatibility `weline` MCP entry, generated marketplace, and managed source. Data is retained unless `--purge-data` is explicit. `status` reports source, config, marketplace, Codex CLI, and plugin IDs. Start a new Codex task after install/update.

Windows packages PowerShell source as `scripts/bootstrap-windows.ps1.txt` and copies it to a temporary `.ps1` only at execution time; the public entry remains `start.bat`.

## Project index operations

建立或完整重建一个项目的派生索引：

```bash
./bin/learningctl intelligence index_project \
  --repository /absolute/repository \
  --input '{"mode":"full"}' \
  --config ~/.learning-mcp/config.yaml
```

增量刷新或定点刷新：

```bash
./bin/learningctl intelligence index_project \
  --repository /absolute/repository \
  --input '{"mode":"incremental","paths":["app/code/Weline/Foo/Service.php"]}' \
  --config ~/.learning-mcp/config.yaml
```

状态和真实数据库位置：

```bash
./bin/learningctl intelligence project_index_status \
  --repository /absolute/repository \
  --config ~/.learning-mcp/config.yaml
```

索引新鲜度以当前项目目录内的文件 Hash 为准，不依赖 Git commit；Git 工作区信息仅作为可选诊断元数据。每次 `SessionStart` 会由 sidecar 后台增量校验当前项目；MCP 自己应用的变化会同步定点重索引；`apply_patch` 路径由 `PostToolUse` 立即定点投递；每次 `get_edit_bundle(paths[])` 还会在读取前对全部显式路径做内容 Hash 核对。默认 60 秒 `index.refresh_interval` 只兜底绕过 Hook/MCP 的外部编辑。

索引查询示例：

```bash
./bin/learningctl intelligence get_edit_bundle \
  --repository /absolute/repository \
  --input '{"task":"定位模块配置读取及影响文档","paths":["app/code/Weline/Foo/Config.php"],"symbols":["Weline\\Foo\\Config"],"token_budget":1800}' \
  --config ~/.learning-mcp/config.yaml
```

CLI 的 `--input -` 从 stdin 读取 JSON，适合大型 Edit Plan；内容仍经过与 MCP 完全相同的 Schema、路径和事务门禁。

### 单次调用返回可编辑上下文

AI 不应对候选文件逐个读取，也不应把服务端可以完成的 continuation 重新变成模型工具轮次。无论目标是否已知，都把完整任务、全部已知路径和符号一次交给 `get_edit_bundle`；所属架构未知时可省略 `paths[]`，由同一次调用完成发现与物化：

```bash
./bin/learningctl intelligence get_edit_bundle \
  --repository /absolute/repository \
  --input '{"task":"修改配置读取并同步文档","paths":["app/code/Weline/Foo/A.php","app/code/Weline/Foo/doc/README.md"],"symbols":["Weline\\Foo\\A::read"],"max_regions":8,"token_budget":1800}' \
  --config ~/.learning-mcp/config.yaml
```

调用方同时提交 TaskContract：`goal`、`requirements`、`known_paths`、`known_symbols`、`acceptance_criteria`、`allowed_scope`、`forbidden_scope`、`authorized_actions`、`assumptions`。服务端在同一次调用内闭合架构、定义、调用方、被调用方、接口契约、配置、测试、文档、消费者、continuation paths 与语义目标；大文件按符号、方法、调用链或相关行块物化。响应稳定提供 `task_id`、`bundle_id`、`project_revision`、`ready_for_edit`、`coverage_status`、`validation_plan`、`missing_paths` 和 `missing_symbols`。当 `ready_for_edit=true` 时，AI 直接生成完整 `edit-plan.v1` 并只调用一次 `apply_compact_edit`，不得再次读取 Bundle、逐文件读取或要求普通本地修改的中途确认。

冷索引会先进行显式路径即时刷新和有界等待；仍不可用时返回可重试的 `INDEX_NOT_READY`、`retry_after_ms` 与当前 revision，不再返回 `region_count=0` 的普通 partial。调用方自动重试，不让用户处理索引，也不退化到 native 读取。

每个工具响应带 `workflow_audit`，记录 receipt、项目、索引 revision、Bundle/Apply/集中验证调用次数、native fallback、中途询问、自动回滚、递归分类和单写者模式。标准预算是 Bundle 1 次、成功 Apply 1 次、Apply 内固定验证、集中回归最多 1 次、运行入口最多 1 次、零 Shell 源码读取、零直接写入、零中途询问。
### 本仓库容量与延迟基线

2026-07-14 的紧凑协议验收：默认工具从 25 个缩到 5 个，`tools/list` 从 21,827 降到 5,242 bytes，instructions 从 2,659 降到 676 bytes；同一任务响应从 24,826 降到 5,593 bytes，兼容文本仅 260 bytes。P0 复测：已知路径 bundle 热中位 222.730ms → 74.455ms（-66.6%）；索引新鲜时新 PHP 进程端到端 121.41ms，内部 72.755ms；21 个突发定点 Hook 请求被 sidecar 合并为 1 个 batch；历史 346 个纯 Browser Node 编排事件回放全部跳过索引刷新；socket/lock/state 权限实测均为 0600。这些是本机样本，不是 SLA。

这些是本机实测值，不是 SLA。常规使用应让 Codex Host 复用 STDIO MCP 进程和连接缓存；反复调用 `learningctl intelligence` 会重复承担 PHP、SQLite 和冷页缓存启动成本。大仓库空间主要受 Chunk 数量影响；默认每 Chunk 只保留 24 个稀疏词项，trigram 只索引 doc/rule/skill。

## Edit recovery

- 常规写入优先 `apply_compact_edit`；Draft 的 `metadata.task` 应保存本轮原始需求，验证失败默认安全回滚。
- 文件锁以操作系统 `flock` 为唯一所有权事实；`.lock` 文件和 owner JSON 可长期存在。冲突使用 `editing.lock_timeout_ms`（默认 30000）和 `editing.lock_poll_interval_ms`（默认 50）有界等待，超时返回 `EDIT_LOCK_TIMEOUT`、`wait_ms` 与 owner PID/Host/operation，不要删除锁文件解锁。
- `apply_compact_edit` 先恢复上次进程中断的事务，再获取按路径排序的跨会话文件锁并对所有 target path 做内容 Hash 定点刷新；`timing_ms.preflight_index`、`target_refresh` 与 `interrupted_edit_recovery` 可审计这些步骤。
- 恢复器在文件锁与项目锁内处理 `applying`、`rolling_back`、`recovery_required`、`rollback_blocked`：`applying` 且全 postimage 时继续固定验证与索引；全 preimage 或纯 pre/post 混合时恢复 Journal preimage；未知 Hash 只标记 `recovery_required`，绝不覆盖。单批最多处理 20 笔；若仍有积压则返回 `has_more`/`remaining` 并拒绝开始新写入，下一次调用继续恢复。
- 刷新后唯一锚点或 digest 仍成立时可安全重定位；片段匹配不上、变歧义、symbol/section/range 守卫失效时绝不写入旧计划。
- 目标失配会在锁内再次定点重索引，并返回 `EDIT_REPLAN_REQUIRED`、`failed_operations`、`latest_regions`、`semantic_diff_from_bundle`、`unchanged_operations`、`original_task` 与最新版 `project_revision`。状态进入 `CONFLICT_REPLAN`：保留未冲突 operations，只替换失败项；最多重规划 2 次，同一冲突第 3 次停止，不询问普通重试。
- `prepare_edit` 只写私有数据库/Journals metadata，不写项目；`apply_edit` 获得项目锁后复核工作区基线/revision/read-set，Token 已使用或过期会被拒绝。Git 项目的基线是 HEAD；非 Git 项目是规范化目录路径的稳定 Hash，文件内容安全仍由 revision、preimage Hash 和目标 digest 保证。
- 紧凑链路在 Apply 后运行固定验证；成功只索引 postimage 一次，验证失败回滚后只索引 preimage 一次。普通异常和恢复事务都会尝试逆序恢复并保留 redacted error/status。
- `apply_compact_edit` 的成功响应直接附带最终 `files`、`apply_pipeline` 和 `change_report`；其中 `change_report.files[]` 保留每个文件自己的有界 diff、hunk 新行号和截断状态，并提供 `review_contract`。AI 应在写入成功后一次审核全部变更文件，按严重级别、路径和新行号报告发现；没有发现时也要明确说明并列出剩余验证空白。原始 Apply 载荷不可用时，`get_edit_status` 作为只读审核/恢复入口返回同一报告；若有截断或不可用项，先汇总全部受影响路径再做一次有界补读，禁止逐文件往返。需要修复时汇总为下一笔 `edit-plan.v1`。MCP App v2 默认只显示紧凑摘要与可点击文件行，点击后展开该文件独立 diff；旧结果会从根级 `files`/`paths` 回退显示文件，不再伪装为零文件。`rollback_edit` 仍受 postimage guard 保护，`ROLLBACK_STALE` 或未知 Hash 必须人工检查。
- Apply/Rollback 后索引失败不会伪装成完全成功；状态会指出 index pending/stale，并允许定点重试 `index_project`。

验证只接受 `php_lint`、`json`、`diff_check`、`weline_safe` 等固定 Profile。`diff_check` 逐文件比较本次事务 Journal 中已校验 Hash 的密封 preimage/postimage，并以固定 `git diff --no-index --check` 执行；因此非 Git 项目目录可用，也不会纳入工作区中的无关差异。不要向 MCP 提交 shell command。

## Module knowledge operations

只读检查：

```bash
./bin/learningctl intelligence check_document_drift \
  --repository /absolute/repository \
  --input '{"module":"Weline_Foo"}' \
  --config ~/.learning-mcp/config.yaml
```

默认先预览：

```bash
./bin/learningctl intelligence sync_module_knowledge \
  --repository /absolute/repository \
  --input '{"module":"Weline_Foo","mode":"preview","include_skill":true}' \
  --config ~/.learning-mcp/config.yaml
```

`mode=apply` 还要求 `confirm=true`，并走 EditService 的 sealed transaction。生成器只覆盖 Marker-owned `doc/ai` 文件。Nested Codex 还要求 `knowledge.codex.enabled=true` 和请求 `use_codex=true`；默认不产生模型费用。

成功 Apply 会把当前代码、文档与生成 Skill digest 封存为 `fresh` baseline。再次运行相同同步应返回 `applied=false`、`already_current=true` 和零 operations；若仍重复改写，应检查生成内容是否包含时间、revision 等自引用易变字段。

## Automatic learned skills

当前默认配置已开启项目级学习技能：

```yaml
analysis:
  provider: codex
  automatic_learning:
    enabled: true
    auto_validate: true
    minimum_validation_confidence: 0.9

knowledge:
  learning_skills:
    enabled: true
    # Empty preserves dev/ai/skills plus Weline module projections.
    # Relative paths resolve from each repository; absolute paths are accepted.
    output_directory: ""
    minimum_confidence: 0.9
    max_experiences: 100
    max_skills: 12
    inject_on_prompt: true
    prompt_skill_limit: 3
    prompt_token_budget: 2400
  codex:
    enabled: true
```

- `SessionStart`、`UserPromptSubmit` 和 worker 轮询会从 Experience 版本/状态、分类策略和当前投影指纹构造 `sync_learning_skills` 幂等任务。
- Stop/idle 分析会先用 `session-learning.v1` 从明确用户纠正或成功高信号结果中提取候选，再查询 Experience 与 Project SQLite。结果会标记为 duplicate、known project knowledge、enrichment、conflict 或 new，并写入审计。
- 强用户意图，或带 test/build/lint/browser/runtime/user-confirmation/CI 成功 Evidence 的非冲突候选，可自动进入 `validated`；Worker 在同一轮结束后立即排入技能同步。弱证据和冲突仍通过 `list_candidates`/`explain_experience`/`mark_experience` 人工处理。
- 没有符合门禁的经验时不创建空 manifest 或空技能。经验被废弃/降级后，后续快照会只清理旧 manifest 记录中仍带 Marker 的生成技能。
- 生成文件、`MCP-LEARNING-INDEX.json` 和 `_index.md` 区块随后立即定点重索引。任何手写文件/所有权冲突都拒绝覆盖，索引区块单独漂移则可无模型修复。
- `output_directory` 留空时，`UserPromptSubmit` 继续只从 Project SQLite 取当前 Prompt 命中的 marker-owned `MCP学习-*`，不扫描整个 `dev/ai/skills`。配置仓库内目录时，该目录会作为保留知识路径定点索引并参与同一技能路由；配置仓库外目录时只完成安全原子输出，宿主必须显式加载该技能目录。
- 配置输出模式只生成项目级技能、`_index.md` 与 `MCP-LEARNING-INDEX.json`，不再生成 Weline 专用的 `app/code/{Vendor}/{Module}/doc/ai/skills` 投影。一个配置目录的 Manifest 只允许归属一个 project ID。

`learningctl doctor` 的 `learning_skills.codex.available` 应为 `true`。需要手工排空任务时：

```bash
./bin/learningd drain --config ~/.learning-mcp/config.yaml
```

需要完全离线时，将 `analysis.provider` 设为 `none`，并把 `knowledge.learning_skills.enabled` 和 `knowledge.codex.enabled` 同时设为 `false`；仅关闭 Codex 而保留 Codex 分析或自动技能会被配置校验拒绝。

## Periodic worker on macOS

只读检查：

```bash
./bin/learningctl scheduler print --config ~/.learning-mcp/config.yaml
./bin/learningctl scheduler status --config ~/.learning-mcp/config.yaml
```

显式安装并立即触发：

```bash
./bin/learningctl scheduler install --config ~/.learning-mcp/config.yaml
./bin/learningctl scheduler kickstart --config ~/.learning-mcp/config.yaml
```

手工执行一次相同的 drain 逻辑：

```bash
./bin/learningctl scheduler run-now --config ~/.learning-mcp/config.yaml
```

卸载：

```bash
./bin/learningctl scheduler uninstall --config ~/.learning-mcp/config.yaml
```

LaunchAgent 标签根据数据目录派生，因此不同数据目录不会互相覆盖。plist 只保存 PHP、worker、配置与数据目录路径，不保存 API Key。`StartInterval` 最小为 60 秒。

## Queue behavior

- Job 使用事件检查点派生的唯一 `idempotency_key`。
- `sync_learning_skills` 额外绑定 Experience 快照、分类策略和投影指纹；无变化不重复调用 Codex，外部漂移会产生新任务。
- Claim 使用 SQLite 写锁并设置 lease；进程崩溃后过期 Job 可被重新领取。
- 失败按指数退避重试，达到 `scheduler.max_attempts` 后进入 `dead_letter`。
- `review_feedback` 是复审信号；当前版本不会自动改写 Experience。
- 空闲 Session 保持 `active`，新事件到来后可再次按新检查点分析。

## Backup

受控备份前停止前台 `learningd` 和 MCP Client：

```bash
sqlite3 ~/.learning-mcp/learning.db ".backup '/secure/path/learning-backup.db'"
```

项目索引是缓存，通常无需备份；若要保留查询/编辑审计，可分别备份 `~/.learning-mcp/indexes/{project-hash}/project.sqlite` 和 `~/.learning-mcp/edit-journal/**`。Journal 可能包含源码 preimage，应按源代码同等敏感度保护。

备份包含项目经验和脱敏后的会话片段，权限与保留策略应至少和原数据库相同。

## Recovery

1. 停止 `learningd`，必要时卸载或 bootout LaunchAgent。
2. 保存当前数据库、`-wal` 和 `-shm` 文件用于取证。
3. 用受信任备份恢复 `learning.db`。
4. 运行 `learningctl doctor`。
5. 运行 `learningd once` 或 `learningd drain`，确认队列可领取。
6. 通过 MCP `health` 和 `search_experiences` 验证协议层。
7. 对目标仓库调用 `project_index_status`；项目索引损坏时移走对应 project-hash 目录并执行 full index，不要删除 `learning.db`。

## Hook troubleshooting

- Hook 采集失败默认写 stderr 并返回成功，避免影响正常编码；调试时加 `--strict`。
- `SessionStart --inject-project-context` 只允许用于 SessionStart；它返回 `hookSpecificOutput.additionalContext` 并在 stdout 完成后 fork 后台索引。
- 后台刷新失败会脱敏写入 `~/.learning-mcp/auto-index.log`；不会污染 MCP/Hook stdout。
- Sidecar 状态位于 `~/.learning-mcp/index-sidecar-state.json`，私有 socket/lock 为 `index-sidecar.sock`/`index-sidecar.lock`。异常时可对 health 返回的 PID 发送 `SIGTERM`；下一个 Hook 会自动恢复，不需要安装 LaunchAgent。
- `--json` 输出入库结果，包括脱敏次数、隔离标志和 Stop Job ID。
- `UserPromptSubmit` 只在生成技能命中时输出 `hookSpecificOutput.additionalContext`；未命中时安静返回成功。技能路由异常只写 stderr，不阻断 Prompt。
- 已安装插件的 `UserPromptSubmit` 超时为 3 秒；稳态路由应从 Project SQLite 完成，不应在 Hook 中运行 Codex 分类。分类始终由 Hook 返回后的 worker 处理。
- Hook 命令必须使用 CLI 与配置的绝对路径。
- 重复 Event 应返回 `inserted: false`，而不是复制数据。
- `do_not_learn: true` 会返回 skipped，不写事件。

手工验证：

```bash
printf '%s\n' '{"session_id":"manual-1","cwd":"/repo","hook_event_name":"SessionStart"}' \
  | ./bin/learningctl hook session-start --inject-project-context --inject-project-rules --config ~/.learning-mcp/config.yaml
```

## MCP troubleshooting

- stdout 只允许 JSON-RPC；诊断写 stderr。
- Codex 配置使用 `bin/learning-mcp` 绝对路径，不需要 `serve --stdio` 参数。
- `get_relevant_guidance` 无结果时，用 `search_experiences` 检查 Project ID、状态、路径和成熟度。
- Candidate 必须经 `list_candidates`/`explain_experience` 审核，不能通过降低 `minimum_status` 强制注入。
- 已验证经验未生成技能时，先检查 `doctor.learning_skills.codex.available`、Experience 置信度/Evidence/有效期/冲突，再检查 `analysis_jobs` 的 `sync_learning_skills` 死信。
- 遇到 `SKILL_OWNERSHIP_CONFLICT` 时不要补 Marker 强制覆盖；先确认该文件是手写还是受损的 MCP 投影。只有 marker-owned `_index.md` 区块漂移可自动修复。
- `get_edit_bundle` 返回 stale 时先用 full-profile/CLI 调用 `index_project`；不要在明知索引过期时继续使用旧行号做写入。
- 把完整 TaskContract、全部精确 `paths[]/symbols[]` 一次提交。候选、continuation、依赖、测试、文档和消费者必须由该次服务端闭包聚合；普通路径不再产生第二次 Bundle。
- 中文召回差时检查 health 中 `fts5_trigram`；即使 trigram 不可用，unicode FTS + CJK sparse terms 仍可降级工作。
- `apply_compact_edit` 返回 `EDIT_REPLAN_REQUIRED` 时，确认 `failed_operations`、`unchanged_operations`、`semantic_diff_from_bundle`、`latest_regions`、`original_task` 和 `project_revision`；保留未冲突 operations，只按最新版区域替换失败项，并把递归标记为 `CONFLICT_REPLAN`。

协议烟测：

```bash
printf '%s\n' \
  '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-11-25","capabilities":{},"clientInfo":{"name":"smoke","version":"1"}}}' \
  '{"jsonrpc":"2.0","id":2,"method":"tools/list","params":{}}' \
  | ./bin/learning-mcp --config ~/.learning-mcp/config.yaml
```

## Upgrade

1. 备份数据库。
2. 停止前台 worker；若已安装 LaunchAgent，先卸载。
3. 更新本目录 PHP/SQL/Prompt/Schema 文件。
4. 运行所有 PHP 文件的 `php -l`。
5. 运行 `learningctl doctor`；启动时自动执行前向 Migration。
6. 执行 Hook → worker → MCP stdio smoke。
7. 如果变更了个人插件的 `.mcp.json` 或 Hooks，按 plugin cachebuster/reinstall 流程重新安装，审查新 Hook Hash，并新建 Codex 任务验证；只修改本目录 PHP 时插件的绝对命令会直接使用新代码。
8. 重新安装 LaunchAgent 或启动 `learningd run`。

Migration 只前进，不自动降级。回滚代码前必须确认旧版本理解当前 Schema。
