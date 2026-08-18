<?php
// SPDX-License-Identifier: MIT
// Usage: php scripts/extract-decoration-paths.php <builder5-path> <ModuleName>
//   or: php scripts/extract-decoration-paths.php <builder5-path> --shared
// Clean-room provenance helper: prints canonical module.decoration.* dot-paths
// from Divi's own PresetAttrsMap.php. Reads Divi source only; never Pro.

function strip_comments($src) {
    $tokens = token_get_all($src);
    $result = '';
    foreach ($tokens as $token) {
        if (is_array($token)) {
            if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                continue;
            }
            $result .= $token[1];
        } else {
            $result .= $token;
        }
    }
    return $result;
}

list(, $b5, $moduleOrFlag) = array_pad($argv, 3, null);
if (!$b5 || !$moduleOrFlag) { fwrite(STDERR, "usage: <builder5-path> <ModuleName>\n   or: <builder5-path> --shared\n"); exit(2); }

$families = ['boxShadow','filters','transform','sticky','transition','scroll','animation'];
$familyDirs = ['BoxShadow','Filters','Transform','Sticky','Transition','Scroll','Animation'];
$byFam = array_fill_keys($families, []);

if ($moduleOrFlag === '--shared') {
    // Extract from Module/Options/<Group>/<Group>PresetAttrsMap.php
    foreach ($families as $i => $fam) {
        $dir = $familyDirs[$i];
        $file = rtrim($b5,'/')."/server/Packages/Module/Options/$dir/{$dir}PresetAttrsMap.php";
        if (!is_file($file)) { fwrite(STDERR, "not found: $file\n"); exit(2); }
        $src = strip_comments(file_get_contents($file));
        if (preg_match_all('/"\\{\\$attr_name\\}__([A-Za-z0-9_.]+)"/', $src, $m)) {
            foreach (array_unique($m[1]) as $subField) {
                $byFam[$fam][] = "module.decoration.$fam"."__$subField";
            }
        }
        // Per-family guard: fail if this family yielded zero subfields
        if (count($byFam[$fam]) === 0) { fwrite(STDERR, "ERROR: family $fam yielded zero subfields in $file — refusing silent empty result\n"); exit(1); }
    }
} else {
    // Extract from ModuleLibrary/<Module>/<Module>PresetAttrsMap.php (per-module mode)
    $module = $moduleOrFlag;
    $file = rtrim($b5,'/')."/server/Packages/ModuleLibrary/$module/{$module}PresetAttrsMap.php";
    if (!is_file($file)) { fwrite(STDERR, "not found: $file\n"); exit(2); }
    $src = strip_comments(file_get_contents($file));
    if (preg_match_all("/'(module\\.decoration\\.[A-Za-z0-9_.]+)'/", $src, $m)) {
        foreach (array_unique($m[1]) as $path) {
            foreach ($families as $f) {
                if (strpos($path, "module.decoration.$f") === 0) { $byFam[$f][] = $path; break; }
            }
        }
    }
}

$total = 0;
foreach ($families as $f) {
    sort($byFam[$f]); $total += count($byFam[$f]);
}
if ($total === 0) { fwrite(STDERR, "ERROR: zero decoration paths matched — refusing silent empty result\n"); exit(1); }

foreach ($families as $f) {
    echo "## $f (".count($byFam[$f]).")\n";
    foreach ($byFam[$f] as $p) echo "  $p\n";
}
fwrite(STDERR, "extracted $total decoration paths\n");
