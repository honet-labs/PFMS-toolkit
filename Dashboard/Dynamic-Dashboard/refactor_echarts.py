import re

file_path = "c:/Users/yusha/Documents/HOME_DATA/HONET/github-repo/PFMS-Panel-Custom/Dashboard/Dynamic-Dashboard/dynamic-dashboard-template.php"
with open(file_path, "r", encoding="utf-8") as f:
    content = f.read()

# Replace CDN
content = content.replace(
    '<script src="/pandora_console/custom/panel/vendor/chartjs/chart.js"></script>',
    '<script src="https://cdn.jsdelivr.net/npm/echarts@5.5.0/dist/echarts.min.js"></script>'
)

# Replace canvases with divs
content = re.sub(r'<canvas\s+id="([^"]+)"\s*(.*?)></canvas>', r'<div id="\1" \2 style="width:100%; height:100%; min-height:200px;"></div>', content)

with open(file_path, "w", encoding="utf-8") as f:
    f.write(content)
