param(
	[string]$OutputDir = "dist"
)

$ErrorActionPreference = "Stop"

$root = Split-Path -Parent $PSScriptRoot

$main = Get-Content (Join-Path $root "immens-forecast.php") -Raw
if ($main -notmatch "Version:\s*([0-9]+\.[0-9]+\.[0-9]+)") {
	throw "Version header not found in immens-forecast.php"
}
$version = $Matches[1]

$stage  = Join-Path ([System.IO.Path]::GetTempPath()) ("immens-forecast-build-" + [guid]::NewGuid().ToString("N"))
$target = Join-Path $stage "immens-forecast"
New-Item -ItemType Directory -Path $target -Force | Out-Null

$exclude = @(
	".git", ".superpowers", ".github", ".gitignore", ".gitattributes", ".distignore", ".phpunit.result.cache",
	"composer.json", "composer.lock", "phpcs.xml.dist", "phpunit.xml.dist",
	"tests", "docs", "tools", "vendor", "node_modules", "build", "dist",
	"CHANGELOG.md", "README.md"
)

Get-ChildItem -Path $root -Force | Where-Object { $exclude -notcontains $_.Name } | ForEach-Object {
	Copy-Item -Path $_.FullName -Destination $target -Recurse -Force
}

New-Item -ItemType Directory -Path (Join-Path $root $OutputDir) -Force | Out-Null
$zip = Join-Path $root ($OutputDir + "\immens-forecast-" + $version + ".zip")
if (Test-Path $zip) {
	Remove-Item $zip
}

Compress-Archive -Path $target -DestinationPath $zip

Remove-Item $stage -Recurse -Force
Write-Host ("Built " + $zip)
