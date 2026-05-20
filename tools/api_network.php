<?php
// Beritahu browser bahwa output file ini adalah JSON murni
header('Content-Type: application/json');

// Mengambil kredensial DB langsung dari inti Pandora FMS
// SESUAIKAN PATH INI JIKA LETAK config.php PANDORA ANDA BERBEDA
$config_file = '/var/www/html/pandora_console/include/config.php';

if (!file_exists($config_file)) {
    die(json_encode(["error" => "File config.php Pandora tidak ditemukan di: " . $config_file]));
}
require_once($config_file);

// Buka koneksi MySQL
$conn = new mysqli($config['dbhost'], $config['dbuser'], $config['dbpass'], $config['dbname']);
if ($conn->connect_error) {
    die(json_encode(["error" => "Gagal koneksi database: " . $conn->connect_error]));
}

// Tarik data Traffic dan Speed dari tabel estado (current value)
$query = "SELECT a.nombre AS agent, m.nombre AS module, e.datos AS val 
          FROM tagente_estado e 
          JOIN tagente_modulo m ON e.id_agente_modulo = m.id_agente_modulo 
          JOIN tagente a ON m.id_agente = a.id_agente 
          WHERE m.nombre LIKE '%_if%Octets' OR m.nombre LIKE '%_if%Speed'";

$result = $conn->query($query);
$network_data = array();

if ($result->num_rows > 0) {
    while($row = $result->fetch_assoc()) {
        $agent = $row['agent'];
        $mod = $row['module'];
        $val = floatval($row['val']);
        
        // Ekstrak nama interface (misal: eth0 dari eth0_ifInOctets)
        $parts = explode("_if", $mod);
        $interface = $parts[0];

        // Buat keranjang jika agent belum ada
        if (!isset($network_data[$agent])) {
            $network_data[$agent] = array();
        }
        if (!isset($network_data[$agent][$interface])) {
            $network_data[$agent][$interface] = array("traffic" => 0, "speed" => 0);
        }

        // Pilah data ke tempatnya masing-masing
        if (strpos($mod, 'Speed') !== false) {
            // Jika HighSpeed, jadikan ke bps
            if (strpos($mod, 'HighSpeed') !== false) {
                $network_data[$agent][$interface]["speed"] = $val * 1000000;
            } else {
                $network_data[$agent][$interface]["speed"] = $val;
            }
        } elseif (strpos($mod, 'Octets') !== false) {
            $network_data[$agent][$interface]["traffic"] = $val;
        }
    }
}

$conn->close();

// Kembalikan data dalam format JSON yang bisa dibaca Javascript
echo json_encode($network_data);
?>