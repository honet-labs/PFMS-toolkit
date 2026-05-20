<?php
/**
 * Local config for NetFlow Explorer.
 * Place this file next to netflow-explorer.php
 * (or set env NFX_LOCAL_CONFIG to point to it).
 */
return [
  // If true: require a valid Pandora console session (best-effort check)
  'require_auth' => false,

  // Where Pandora (or your collector) stores NetFlow files
  'netflow_dir' => '/var/spool/pandora/data_in/netflow',

  // nfdump binary path
  'nfdump_bin' => '/usr/bin/nfdump',

  // How long one nfcapd file covers (seconds). Default collector rotation is commonly 300s.
  'rotation_seconds' => 300,

  // Performance
  'cache_ttl' => 10,
  'max_files_list' => 600,
  'default_window_minutes' => 30,
  'default_top_n' => 20,
  'default_flow_n' => 200,

  // Cache dir (optional)
  // 'cache_dir' => __DIR__ . '/cache',
];
