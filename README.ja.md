# Weline MCP

[简体中文](README.md) · [English](README.en.md) · **日本語**

**Codex のファイル単位の試行を、タスク単位の一括コーディングへ。**

**Token を節約し、取得を高速化し、プロジェクトを自動分離し、複数セッションの上書きを防ぎ、検証済み結果を再利用可能な Skill に変換します。**

Weline MCP は Codex 向けのローカル優先プロジェクトインテリジェンス、トランザクション編集、証拠ベース学習エンジンです。

1. 所属アーキテクチャや呼び出し経路が不明な場合、完全なタスクと関連する symbol/module/kind を使って `get_edit_bundle` の発見バッチを実行し、未知の関連ファイルを探す場合だけ paths を省略します。
2. 候補が判明したら、関連パスと影響シンボルを 1 つの物化バッチにまとめ、MCP が Hash を更新確認して必要な領域、影響、文書、ルール、Skill を返します。
3. 各 bundle の後で累積コンテキストを判断し、不足していれば必要なパス、シンボル、検索目標をまとめて次の広いバッチを要求します。ファイルを 1 つずつ読みません。
4. コンテキストが十分になった時点で、Codex は全変更を含む 1 つの `edit-plan.v1` を送ります。
5. `apply_compact_edit` がロック、Hash 再確認、原子的コミット、検証、再インデックス、安全なロールバックを行います。

実行には PHP 8.2+ と SQLite 拡張を使います。起動スクリプトは配布や VCS 検証のため Git も準備しますが、プロジェクト識別、索引、トランザクション編集は現在のディレクトリが Git リポジトリであることを要求しません。Git プロジェクトは HEAD guard を維持し、非 Git プロジェクトは正規化ディレクトリの安定基線を使いながら revision、file Hash、target digest、lock、Journal、検証、rollback を必須とします。Composer と Node.js は任意の配布入口です。npm は依存関係のないプロセスラッパーで、同じ PHP STDIO Server がプロトコルを処理します。

## 主な機能

- コード、文書、シンボル、関係、ルール、Skill の永続増分インデックス
- 精密パス／シンボル検索、全文・trigram・ローカル疎検索、影響分析
- リポジトリ全体ではなく Token Budget 内の有界領域を返却
- プロセス間ロック、固定ロック順、Hash、Journal、原子的置換、検証、ロールバック
- 有界な kernel `flock` 待機と owner 診断。永続 `.lock` ファイル自体は所有権を示さず、次回の編集／状態照会で中断トランザクションを sealed pre/postimage Hash により復旧
- 正規化された絶対ディレクトリごとに独立した `project.sqlite`。同じ Git リポジトリ内でも異なるディレクトリは統合しません
- 証拠、作用域、重複、競合、信頼度、成熟度、期限、コードドリフトを確認して `SKILL.md` を生成

「プロジェクトごとにゼロ設定」は、一度 Codex にグローバル登録した後の意味です。初回だけインストーラーまたは `codex mcp add` が必要です。

## 必要環境

PHP 8.2+、`pdo_sqlite`、`json`、`mbstring`、`openssl`、Git。既定の検証済み経験分類には Codex CLI、短い非同期 worker には任意で `pcntl`/`posix` を使います。

## ワンコマンド導入（推奨）

インストーラーは Weline MCP をダウンロードし、PHP/Git などの依存環境を確認・導入して、Codex プラグインと MCP 登録を自動生成します。完了後は新しい Codex タスクを開始するだけで利用でき、手動 clone、TOML 編集、常駐サービスは不要です。

### macOS / Linux

GitHub：

```bash
tmp=/tmp/weline-mcp-install.sh; curl -fsSL https://raw.githubusercontent.com/Aiweline/Weline-Codex-Mcp/main/install.sh -o "$tmp" && sh "$tmp" install --source=github
```

Gitee：

```bash
tmp=/tmp/weline-mcp-install.sh; curl -fsSL https://gitee.com/aiweline/weline-codex-mcp/raw/main/install.sh -o "$tmp" && sh "$tmp" install --source=gitee
```

### Windows PowerShell

GitHub：

```powershell
$p=Join-Path $env:TEMP 'weline-mcp-start.bat'; Invoke-WebRequest https://raw.githubusercontent.com/Aiweline/Weline-Codex-Mcp/main/start.bat -OutFile $p; & $p install github
```

Gitee：

```powershell
$p=Join-Path $env:TEMP 'weline-mcp-start.bat'; Invoke-WebRequest https://gitee.com/aiweline/weline-codex-mcp/raw/main/start.bat -OutFile $p; & $p install gitee
```

macOS/Linux は Homebrew、APT、DNF/YUM、Pacman、Zypper、Windows は WinGet または Chocolatey に対応します。sudo/UAC が必要な場合は通常のターミナルで一度実行してください。

## ワンコマンド削除

既定では管理プログラム、Codex プラグイン、MCP 登録だけを削除し、設定、インデックス、Journal、学習データは保持します。完全消去には全プラットフォームで `--purge-data` を追加します。

macOS / Linux：

```bash
# GitHub
tmp=/tmp/weline-mcp-install.sh; curl -fsSL https://raw.githubusercontent.com/Aiweline/Weline-Codex-Mcp/main/install.sh -o "$tmp" && sh "$tmp" uninstall --source=github
# Gitee
tmp=/tmp/weline-mcp-install.sh; curl -fsSL https://gitee.com/aiweline/weline-codex-mcp/raw/main/install.sh -o "$tmp" && sh "$tmp" uninstall --source=gitee
```

Windows PowerShell：

```powershell
# GitHub
$p=Join-Path $env:TEMP 'weline-mcp-start.bat'; Invoke-WebRequest https://raw.githubusercontent.com/Aiweline/Weline-Codex-Mcp/main/start.bat -OutFile $p; & $p uninstall github
# Gitee
$p=Join-Path $env:TEMP 'weline-mcp-start.bat'; Invoke-WebRequest https://gitee.com/aiweline/weline-codex-mcp/raw/main/start.bat -OutFile $p; & $p uninstall gitee
```

## その他の導入方法

### ソースから導入

```bash
git clone https://github.com/Aiweline/Weline-Codex-Mcp.git
cd Weline-Codex-Mcp
./start.sh install
```

Gitee は `https://gitee.com/aiweline/weline-codex-mcp.git`、Windows は `start.bat install` を使用します。削除は `./start.sh uninstall` または `start.bat uninstall`、完全消去は `--purge-data` を追加します。

## Composer

```bash
composer global config repositories.weline-mcp vcs https://github.com/Aiweline/Weline-Codex-Mcp
composer global require aiweline/weline-codex-mcp:dev-main
composer global exec -- weline-mcp-install install
```

必要なら VCS URL を Gitee に変更します。Packagist 公開後は `composer global require aiweline/weline-codex-mcp`。削除は次のとおりです。

```bash
composer global exec -- weline-mcp-install uninstall
composer global remove aiweline/weline-codex-mcp
```

完全消去する場合は最初のコマンドを `uninstall --purge-data` にします。

## Node/npm ラッパー

```bash
npm install -g git+https://github.com/Aiweline/Weline-Codex-Mcp.git
codex mcp add weline -- weline-mcp
```

Gitee の Git URL も利用できます。npm Registry 公開後は `npm install -g weline-codex-mcp`。ラッパーは stdio、引数、環境、終了状態、シグナルを PHP に渡します。PHP は `WELINE_MCP_PHP` または `PHP_BINARY` で選択できます。

削除：

```bash
codex mcp remove weline
npm uninstall -g weline-codex-mcp
```

## Codex に接続

```bash
codex mcp add weline -- /absolute/path/to/Weline-Codex-Mcp/bin/learning-mcp --config /absolute/path/to/config.yaml
codex mcp list
```

Codex Desktop、CLI、IDE Extension は同じ Host の MCP 設定を共有します。Desktop/IDE の **Settings → MCP servers** から STDIO Server を追加することもできます。

```toml
[mcp_servers.weline]
command = "/absolute/path/to/Weline-Codex-Mcp/bin/learning-mcp"
args = ["--config", "/absolute/path/to/config.yaml"]
startup_timeout_sec = 20
tool_timeout_sec = 120
```

## Skill 出力先

```yaml
knowledge:
  learning_skills:
    output_directory: ".codex/skills"
```

既定設定は `~/.learning-mcp/config.yaml`、Windows は `%USERPROFILE%\.learning-mcp\config.yaml`。相対パスは各プロジェクトディレクトリ基準、絶対パスも利用できます。環境変数は `LEARNING_MCP_CONFIG` と `LEARNING_MCP_SKILL_OUTPUT_DIR` です。

## MCP App 実行パネル

`apply_compact_edit` と `get_edit_status` は、変更ファイル、追加/削除行、検証、注意事項、各ファイル専用の限定 diff と hunk 行番号を表示する MCP App レポートを返します。全変更ファイルのレビュー契約により、Codex は一度の読み取り専用パスで全 hunk を監査でき、テキストと structuredContent のフォールバックも維持されます。

## 推奨 Codex フロー

```markdown
- Treat the normalized absolute directory supplied by the task as the full project boundary; never replace it with an enclosing Git root.
- Use get_edit_bundle first for architecture discovery when targets are unknown, then submit all known paths and affected symbols in broad materialization batches; request another batch only when accumulated context is insufficient.
- Use returned regions, hashes, impacts, docs, and Skills instead of repository-wide scans.
- Submit one edit-plan.v1 and call apply_compact_edit once.
- After apply, review every changed file from change_report.files[].diff and hunk line numbers in one read-only pass; report severity plus path/new-line references, or explicitly report no findings and residual gaps.
- Use get_edit_status only when the apply result is unavailable. Batch truncated/unavailable paths into one follow-up read, batch all fixes into one new edit-plan.v1, and keep rollback_edit for recovery.
```

主要ツール：`get_edit_bundle`、`apply_compact_edit`、`get_edit_status`、`rollback_edit`、`health`。

詳細：[運用](docs/OPERATIONS.md)・[アーキテクチャ](docs/ARCHITECTURE.md)・[セキュリティ](docs/SECURITY.md)・[実装境界](docs/IMPLEMENTATION-STATUS.md)

リポジトリ：[GitHub](https://github.com/Aiweline/Weline-Codex-Mcp) · [Gitee](https://gitee.com/aiweline/weline-codex-mcp) · Apache-2.0
