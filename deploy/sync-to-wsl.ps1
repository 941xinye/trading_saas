# 一键同步 Windows 工作树到 WSL 开发 clone
# ========================================================
#
# 背景：项目同时维护 Windows 主仓（IDE 开发）与 WSL Ubuntu-22.04 clone（PHP 8.4 + Swoole 实跑）。
#       git push 偶发 TCP 层不可达（坑 #23 / 项目记忆 2026-10-20），需手动 tar 打包同步。
#       本脚本替代手动 tar + wsl cp + tr -d \r 串联，一键完成。
#
# 用法：
#   .\sync-to-wsl.ps1                  # 同步 app/ config/ database/ resources/ routes/ (默认)
#   .\sync-to-wsl.ps1 -Paths app,tests # 只同步指定目录
#   .\sync-to-wsl.ps1 -DryRun          # 只打印 tar 内容，不解
#
# 依赖：Windows 侧 tar.exe（Windows 10+ 自带）；WSL clone 位置由 $wslRepoPath 决定。

param(
    [string[]]$Paths = @('app', 'config', 'database', 'resources', 'routes'),
    [string]$WslDistro = 'Ubuntu-22.04',
    [string]$WslUser = 'lenovo',
    [string]$WslRepoPath = '/home/lenovo/trading_saas',
    [switch]$DryRun
)

$ErrorActionPreference = 'Stop'
# 兼容 -File 传参被当成单个逗号串的情况
$Paths = @($Paths | ForEach-Object { $_ -split ',' } | ForEach-Object { $_.Trim() } | Where-Object { $_ -ne '' })
$repoRoot = Split-Path -Parent $PSScriptRoot
$tarFile = Join-Path $env:TEMP "crypto-sync-$(Get-Date -Format 'yyyyMMdd-HHmmss').tar"

Write-Host "==> Repo root: $repoRoot" -ForegroundColor Cyan
Write-Host "==> Sync paths: $($Paths -join ', ')" -ForegroundColor Cyan
Write-Host "==> Tarball:    $tarFile" -ForegroundColor Cyan

# ---- 1. 打包 tar（Windows 侧） ----
$tarArgs = @('-cf', $tarFile)
foreach ($p in $Paths) {
    $full = Join-Path $repoRoot $p
    if (-not (Test-Path $full)) {
        Write-Warning "Skip missing path: $full"
        continue
    }
    $tarArgs += $p
}

Push-Location $repoRoot
try {
    & tar @tarArgs
    if ($LASTEXITCODE -ne 0) { throw "tar failed with exit $LASTEXITCODE" }
} finally {
    Pop-Location
}

$size = [math]::Round((Get-Item $tarFile).Length / 1MB, 2)
Write-Host "==> Tarball created ($size MB)" -ForegroundColor Green

if ($DryRun) {
    Write-Host "==> DryRun: listing contents..." -ForegroundColor Yellow
    & tar -tf $tarFile | Select-Object -First 30
    Write-Host "... total:" (& tar -tf $tarFile | Measure-Object -Line).Lines "entries"
    Remove-Item $tarFile -Force
    exit 0
}

# ---- 2. 生成 WSL 侧脚本（LF）并执行（多行脚本直接传给 bash -c 会被 wsl.exe 拆行） ----
$wslTarPath = '/mnt/' + $tarFile.Substring(0, 1).ToLower() + ($tarFile.Substring(2) -replace '\\', '/')
$wslScript = Join-Path $env:TEMP "crypto-sync-$(Get-Date -Format 'yyyyMMdd-HHmmss').sh"
$wslScriptPath = '/mnt/' + $wslScript.Substring(0, 1).ToLower() + ($wslScript.Substring(2) -replace '\\', '/')

$scriptBody = @"
set -e
cd $WslRepoPath
# 先删旧目录避免残留
for p in $($Paths -join ' '); do
  [ -d "`$p" ] && rm -rf "`$p"
done
tar -xf $wslTarPath
# 统一转 LF（坑：脚本从 Windows 过去带 CRLF 会让 bash shebang 炸）
find $($Paths -join ' ') -type f \( -name '*.php' -o -name '*.sh' -o -name '*.json' -o -name '*.yml' -o -name '*.md' \) -print0 | xargs -0 -r sed -i 's/\r$//'
rm -f $wslTarPath
echo OK
"@
$scriptBody = $scriptBody -replace "`r`n", "`n"
[System.IO.File]::WriteAllText($wslScript, $scriptBody, (New-Object System.Text.UTF8Encoding($false)))

Write-Host "==> Extracting into WSL clone $WslRepoPath ..." -ForegroundColor Cyan
wsl -d $WslDistro -u $WslUser -- bash $wslScriptPath
if ($LASTEXITCODE -ne 0) { throw "WSL extract failed" }

Remove-Item $wslScript -Force -ErrorAction SilentlyContinue
Remove-Item $tarFile -Force -ErrorAction SilentlyContinue
Write-Host "==> Done." -ForegroundColor Green
Write-Host "    Next step in WSL: cd $WslRepoPath && composer dump-autoload -o && php artisan test" -ForegroundColor Yellow
