<?php
$files = [
    "c:/Users/yusha/Documents/HOME_DATA/HONET/github-repo/PFMS-Panel-Custom/Dashboard/Metrics-Dashboard/metrics-dashboard.php",
    "c:/Users/yusha/Documents/HOME_DATA/HONET/github-repo/PFMS-Panel-Custom/Network-Mapping/Traffic-Interface/traffic-interface.php"
];

foreach ($files as $file) {
    if (!file_exists($file)) continue;
    $c = file_get_contents($file);
    
    // 1. CDN
    $c = str_replace('<script src="/pandora_console/custom/panel/vendor/chartjs/chart.js"></script>', '<script src="https://cdn.jsdelivr.net/npm/echarts@5.5.0/dist/echarts.min.js"></script>', $c);
    
    // 2. Canvases
    $c = preg_replace('/<canvas\s+id="([^"]+)"\s*(.*?)><\/canvas>/', '<div id="$1" $2 style="width:100%; height:100%; min-height:200px;"></div>', $c);
    
    // 3. Destroy
    $c = preg_replace('/(\w+)\.destroy\(\);/', 'if (typeof $1.dispose === "function") $1.dispose();', $c);
    
    file_put_contents($file, $c);
}
echo "Done.";
?>
