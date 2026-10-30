# Архив проекта для загрузки на сервер: код без зависимостей, сборок и секретов.
# node_modules, vendor и dist не нужны — сервер собирает всё в Docker сам.
#
#   powershell -ExecutionPolicy Bypass -File tools\make-release.ps1
#
# Результат: release\food-guru-ГГГГММДД-ЧЧММ.tar.gz

$ErrorActionPreference = 'Stop'

$root = Split-Path -Parent $PSScriptRoot
$outDir = Join-Path $root 'release'
New-Item -ItemType Directory -Force $outDir | Out-Null
$archive = Join-Path $outDir ("food-guru-{0}.tar.gz" -f (Get-Date -Format 'yyyyMMdd-HHmm'))

# Пути относительно корня, через «/». Папка из списка не обходится вовсе.
$skip = @(
  # Секреты: .env с паролями создаётся на сервере, примеры едут.
  '^\.env$', '^backend/\.env$', '\.env\.local$',
  # Зависимости и сборки.
  '(^|/)(node_modules|__pycache__|\.git)$',
  '^backend/vendor$', '^frontend/(dist|\.angular)$', '^backend/\.phpunit\.cache$',
  # Симлинка от storage:link: на сервере её создаёт deploy.sh.
  '^backend/public/storage$',
  '^backend/storage/framework/phpstan$',
  # Готовые рилсы — тяжёлые и серверу не нужны.
  '^tools/reels/out$',
  '^release$', '^vendor\.zip$'
)
# Локальные загрузки, логи и кэши Laravel: папки нужны, файлы в них — нет.
$emptyDirs = '^backend/storage/(app|logs|framework/(cache|sessions|views|testing))/'

$entries = New-Object System.Collections.Generic.List[string]

function Add-Tree([string]$dir, [string]$rel) {
  foreach ($item in Get-ChildItem -LiteralPath $dir -Force) {
    $path = if ($rel) { "$rel/$($item.Name)" } else { $item.Name }
    if ($skip | Where-Object { $path -match $_ }) { continue }
    # Ссылки из контейнеров на Windows часто битые, и сервер их всё равно пересоздаст.
    if ($item.Attributes -band [IO.FileAttributes]::ReparsePoint) { continue }

    if ($item.PSIsContainer) {
      $entries.Add($path)
      Add-Tree $item.FullName $path
    } elseif ("$path" -notmatch $emptyDirs -or $item.Name -eq '.gitignore') {
      # С CRLF из Windows sh на сервере падает на первой же строке.
      if ($item.Extension -eq '.sh' -and [IO.File]::ReadAllText($item.FullName).Contains("`r")) {
        throw "${path}: переводы строк CRLF, сохраните файл с LF"
      }
      $entries.Add($path)
    }
  }
}

Add-Tree $root ''

$list = [IO.Path]::GetTempFileName()
try {
  [IO.File]::WriteAllLines($list, $entries, (New-Object Text.UTF8Encoding $false))
  # -n: папки из списка не раскрываются, файлы в них перечислены сами.
  & "$env:SystemRoot\System32\tar.exe" -czf $archive -n -C $root -T $list
  if ($LASTEXITCODE -ne 0) { throw "tar завершился с кодом $LASTEXITCODE" }
} finally {
  Remove-Item $list
}

# Последняя страховка: секреты в архив попасть не должны.
$leaked = & "$env:SystemRoot\System32\tar.exe" -tzf $archive | Where-Object { $_ -match '(^|/)\.env$' }
if ($leaked) {
  Remove-Item $archive
  throw "в архив попали файлы .env: $($leaked -join ', ')"
}

$sizeMb = [math]::Round((Get-Item $archive).Length / 1MB, 1)
Write-Host "Готово: $archive ($($entries.Count) записей, $sizeMb МБ)"
