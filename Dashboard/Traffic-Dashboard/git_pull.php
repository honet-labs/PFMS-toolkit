<?php
header('Content-Type: text/plain');
echo "Running git pull...\n";
$out = [];
$status = 0;
exec("git pull 2>&1", $out, $status);
echo "Status: $status\n";
echo "Output:\n" . implode("\n", $out) . "\n";
