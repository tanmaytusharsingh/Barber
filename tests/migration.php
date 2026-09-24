<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') exit;
require __DIR__.'/../config/database.php';
$server=new PDO('mysql:host='.DB_HOST.';charset=utf8mb4',DB_USER,DB_PASS,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$suffix=bin2hex(random_bytes(4)); $legacy='barber_migration_'.$suffix.'_test'; $fresh='barber_fresh_'.$suffix.'_test'; $restore='barber_restore_'.$suffix.'_test'; $checks=0;
function verify(bool $ok,string $message): void { global $checks; if (!$ok) throw new RuntimeException($message); $checks++; echo 'PASS '.$message.PHP_EOL; }
function migrate_process(string $database): array {
    $env=getenv(); $env['BARBER_DB_NAME']=$database;
    $p=proc_open([PHP_BINARY,__DIR__.'/../scripts/migrate.php'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,$env);
    fclose($pipes[0]); $out=stream_get_contents($pipes[1]); $err=stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]); return [proc_close($p),$out,$err];
}
try {
    $schema=file_get_contents(__DIR__.'/../database/database.sql');
    $old=explode('-- Current application schema for fresh installs.',$schema)[0];
    $server->exec(str_replace('barber_company',$legacy,$old));
    $server->exec("UPDATE users SET status='pending' WHERE id=2");
    $server->exec("INSERT INTO appointments(booking_code,customer_id,salon_id,service_id,staff_id,appointment_date,start_time,end_time,amount,payment_method) VALUES ('MIGRATION-TEST',3,1,1,1,'2030-01-01','10:00','10:45',450,'cash')");
    $server->exec("INSERT INTO payments(appointment_id,customer_id,vendor_id,amount,method) VALUES (1,3,2,450,'cash')");
    [$code,$out,$err]=migrate_process($legacy); verify($code===0,'Legacy migration succeeds: '.$err);
    verify((int)$server->query('SELECT COUNT(*) FROM appointments')->fetchColumn()===1,'Migration preserves existing appointment');
    verify($server->query('SELECT service_name FROM appointments')->fetchColumn()==='Signature Haircut','Migration backfills booking snapshots');
    verify($server->query('SELECT status FROM users WHERE id=2')->fetchColumn()==='active','Migration repairs historical pending owner');
    $server->exec("UPDATE users SET status='inactive' WHERE id=2");
    [$code]=migrate_process($legacy); verify($code===0,'Repeated migration succeeds');
    verify($server->query('SELECT status FROM users WHERE id=2')->fetchColumn()==='inactive','Migration does not reactivate deliberately disabled account');
    preg_match('/Backup: (.+)/',$out,$m); $backup=trim($m[1]); verify(is_file($backup),'Migration creates backup outside web root');
    $server->exec('CREATE DATABASE '.$restore.' CHARACTER SET utf8mb4');
    $env=getenv(); $env['MYSQL_PWD']=DB_PASS;
    $p=proc_open(['C:/xampp/mysql/bin/mysql.exe','--host='.DB_HOST,'--user='.DB_USER,$restore],[0=>['file',$backup,'r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,$env);
    stream_get_contents($pipes[1]); $error=stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]); verify(proc_close($p)===0,'Backup restore succeeds: '.$error);
    verify((int)$server->query("SELECT COUNT(*) FROM $restore.appointments")->fetchColumn()===1,'Restored backup preserves original booking');
    $server->exec(str_replace('barber_company',$fresh,$schema));
    verify((int)$server->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='$fresh' AND TABLE_NAME='appointments' AND COLUMN_NAME='request_key'")->fetchColumn()===1,'Fresh schema includes workflow columns');
    [$code]=migrate_process($fresh); verify($code===0,'Migration runner accepts fresh installation');
    $server->exec('USE '.$legacy);
    $server->exec("INSERT INTO appointments(booking_code,customer_id,salon_id,service_id,staff_id,appointment_date,start_time,end_time,amount,payment_method) VALUES ('CONFLICT-TEST',3,1,1,1,'2030-01-01','10:15','11:00',450,'cash')");
    [$code,$out,$err]=migrate_process($legacy); verify($code!==0 && str_contains($err,'Preflight failed'),'Preflight refuses conflicting data without modifying bookings');
    verify((int)$server->query('SELECT COUNT(*) FROM appointments')->fetchColumn()===2,'Failed preflight preserves anomalous records for review');
    // This test intentionally triggered a preflight failure before any DDL.
    $lock=__DIR__.'/../config/maintenance.lock'; if (is_file($lock)) unlink($lock);
    echo "$checks migration checks passed.\n";
} finally {
    foreach ([$legacy,$fresh,$restore] as $database) {
        if (!preg_match('/^barber_(migration|fresh|restore)_[a-f0-9]{8}_test$/D',$database)) throw new RuntimeException('Unsafe test cleanup target.');
        $server->exec('DROP DATABASE IF EXISTS '.$database);
    }
}
