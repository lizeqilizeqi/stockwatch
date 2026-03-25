param(
  [string]$Version = "",
  [ValidateSet("major","minor","patch")]
  [string]$Bump = "patch",
  [switch]$Commit,
  [switch]$Tag,
  [switch]$Push
)

$ErrorActionPreference = "Stop"

function Read-TextFile {
  param([string]$Path)
  if (!(Test-Path $Path)) { throw "File not found: $Path" }
  return [System.IO.File]::ReadAllText($Path, [System.Text.Encoding]::UTF8)
}

function Write-TextFile {
  param([string]$Path, [string]$Content)
  [System.IO.File]::WriteAllText($Path, $Content, [System.Text.Encoding]::UTF8)
}

function Parse-Semver {
  param([string]$v)
  if ($v -notmatch "^(\d+)\.(\d+)\.(\d+)$") {
    throw "Invalid version format: $v (expected x.y.z)"
  }
  return @{ Major=[int]$matches[1]; Minor=[int]$matches[2]; Patch=[int]$matches[3] }
}

function New-VersionFromBump {
  param([string]$Current, [string]$BumpType)
  $p = Parse-Semver $Current
  if ($BumpType -eq "major") {
    return ("{0}.0.0" -f ($p.Major + 1))
  }
  if ($BumpType -eq "minor") {
    return ("{0}.{1}.0" -f $p.Major, ($p.Minor + 1))
  }
  return ("{0}.{1}.{2}" -f $p.Major, $p.Minor, ($p.Patch + 1))
}

function Ensure-UnreleasedSection {
  param([string]$Text)
  if ($Text -match "(?m)^## \[Unreleased\]") { return $Text }
  $header = "## [Unreleased]`r`n`r`n### Added`r`n`r`n- `r`n`r`n### Changed`r`n`r`n- `r`n`r`n### Fixed`r`n`r`n- `r`n`r`n"
  if ($Text -match "(?m)^# Changelog\s*$") {
    return ($Text -replace "(?m)^# Changelog\s*$", "# Changelog`r`n`r`n$header")
  }
  return "# Changelog`r`n`r`n$header`r`n$Text"
}

function Release-Changelog {
  param([string]$Text, [string]$Version, [string]$Date)
  $Text = Ensure-UnreleasedSection $Text
  $pattern = "(?s)## \[Unreleased\]\s*(.*?)\r?\n(?=## \[)"
  if ($Text -match $pattern) {
    $body = $matches[1].Trim()
    if ($body -eq "" -or $body -eq "### Added`r`n`r`n-`r`n`r`n### Changed`r`n`r`n-`r`n`r`n### Fixed`r`n`r`n-") {
      $body = "### Added`r`n`r`n- Maintenance release.`r`n"
    }
    $replacement = "## [Unreleased]`r`n`r`n### Added`r`n`r`n- `r`n`r`n### Changed`r`n`r`n- `r`n`r`n### Fixed`r`n`r`n- `r`n`r`n## [$Version] - $Date`r`n`r`n$body`r`n"
    return [System.Text.RegularExpressions.Regex]::Replace($Text, $pattern, $replacement, 1)
  }
  return $Text
}

$repoRoot = Split-Path -Parent $PSScriptRoot
$versionFile = Join-Path $repoRoot "VERSION"
$changelogFile = Join-Path $repoRoot "CHANGELOG.md"

$currentVersion = (Read-TextFile $versionFile).Trim()
if ($Version -eq "") {
  $newVersion = New-VersionFromBump -Current $currentVersion -BumpType $Bump
} else {
  Parse-Semver $Version | Out-Null
  $newVersion = $Version
}

if ($newVersion -eq $currentVersion) {
  throw "New version equals current version: $newVersion"
}

$today = Get-Date -Format "yyyy-MM-dd"
Write-Host "Current version: $currentVersion"
Write-Host "New version:     $newVersion"

Write-TextFile -Path $versionFile -Content ($newVersion + "`r`n")

$changelog = Read-TextFile $changelogFile
$changelog = Release-Changelog -Text $changelog -Version $newVersion -Date $today
Write-TextFile -Path $changelogFile -Content $changelog

git add VERSION CHANGELOG.md

if ($Commit) {
  git commit -m "Release v$newVersion"
}
if ($Tag) {
  git tag "v$newVersion"
}
if ($Push) {
  git push
  if ($Tag) { git push origin "v$newVersion" }
}

Write-Host "Release preparation complete."
Write-Host "Updated files: VERSION, CHANGELOG.md"
Write-Host "Next step: review changelog details and push."

