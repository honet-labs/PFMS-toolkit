[mysqld]
# --- Basic ---
server_id=1
datadir=/pandoradb/mysql
socket=/pandoradb/mysql/mysql.sock
pid-file=/var/run/mysqld/mysqld.pid
log-error=/var/log/mysqld-master.log
port=3306
bind-address=0.0.0.0

# --- InnoDB / IO ---
innodb_io_capacity=800
innodb_io_capacity_max=2000
innodb_flush_neighbors=0
innodb_flush_method=O_DIRECT
innodb_file_per_table=1
innodb_log_file_size=2G

# Buffer pool
innodb_buffer_pool_size=40G
innodb_buffer_pool_instances=8

# Redo log (MySQL 8+ gunakan capacity, bukan log_file_size)
innodb_redo_log_capacity=8G
innodb_log_buffer_size=256M

# Flush & durability balance
innodb_flush_log_at_trx_commit=2
innodb_flush_log_at_timeout=1
innodb_lock_wait_timeout=90

# Temp tables (per-connection caps)
tmp_table_size=512M
max_heap_table_size=512M

# --- Connection / cache ---
max_connections=1000
thread_cache_size=200
table_open_cache=60000
table_definition_cache=20000
open_files_limit=524288

# MyISAM key cache (masih diperlukan untuk internal kecil)
key_buffer_size=16M

# Per-thread buffers (dibuat konservatif agar tidak meledak di puncak)
read_buffer_size=512K
read_rnd_buffer_size=512K
sort_buffer_size=2M
join_buffer_size=1M

# Mode SQL
sql_mode=""

# --- Binary logs / Replication ---
log_bin=/pandoradb/mysql/mysql-bin
max_binlog_size=100M
binlog-format=ROW
binlog_expire_logs_seconds=604800     # 7 hari (ubah sesuai kebijakan retention)
sync_binlog=1

gtid-mode=ON
enforce-gtid-consistency=ON
master-info-repository=TABLE
relay-log-info-repository=TABLE
log_slave_updates=1
replica_parallel_workers=14
replica_compressed_protocol=1
binlog_do_db=pandora
replicate_do_db=pandora
sync_source_info=1

# --- Misc ---
skip_name_resolve=1
long_query_time=2
slow_query_log=ON
slow_query_log_file=/var/log/mysql/slow-master.log
max_connect_errors=100000

[client]
socket=/pandoradb/mysql/mysql.sock
user=root
password=Pandor4!
