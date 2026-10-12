<?php
// Bộ kiểm tra tối giản, không cần cài PHPUnit.
$GLOBALS['__pass'] = 0;
$GLOBALS['__fail'] = 0;

function check(string $label, $actual, $expected): void
{
    if ($actual === $expected) {
        $GLOBALS['__pass']++;
        echo "  [OK]   $label\n";
    } else {
        $GLOBALS['__fail']++;
        echo "  [FAIL] $label\n         mong đợi: " . var_export($expected, true) . "\n         thực tế : " . var_export($actual, true) . "\n";
    }
}

function finish(string $name): void
{
    echo "$name: {$GLOBALS['__pass']} đạt, {$GLOBALS['__fail']} lỗi\n";
    exit($GLOBALS['__fail'] > 0 ? 1 : 0);
}
