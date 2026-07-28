<?php
// Usage: php scripts/extract-decoration-paths.php <builder5-path> <ModuleName>
// Clean-room provenance helper: prints canonical module.decoration.* dot-paths
// from Divi's own PresetAttrsMap.php. Reads Divi source only; never Pro.
list(, $b5, $module) = array_pad($argv, 3, null);
if (!$b5 || !$module) { fwrite(STDERR, "usage: <builder5-path> <ModuleName>\n"); exit(2); }
$file = rtrim($b5,'/')."/server/Packages/ModuleLibrary/$module/{$module}PresetAttrsMap.php";
if (!is_file($file)) { fwrite(STDERR, "not found: $file\n"); exit(2); }
$src = file_get_contents($file);
$families = ['boxShadow','filters','transform','sticky','transition','scroll','animation'];
$byFam = array_fill_keys($families, []);
if (preg_match_all("/'(module\\.decoration\\.[A-Za-z0-9_.]+)'/", $src, $m)) {
    foreach (array_unique($m[1]) as $path) {
        foreach ($families as $f) {
            if (strpos($path, "module.decoration.$f") === 0) { $byFam[$f][] = $path; break; }
        }
    }
}
$total = 0;
foreach ($families as $f) {
    sort($byFam[$f]); $total += count($byFam[$f]);
    echo "## $f (".count($byFam[$f]).")\n";
    foreach ($byFam[$f] as $p) echo "  $p\n";
}
if ($total === 0) { fwrite(STDERR, "ERROR: zero decoration paths matched in $file — refusing silent empty result\n"); exit(1); }
fwrite(STDERR, "extracted $total decoration paths from $module\n");
