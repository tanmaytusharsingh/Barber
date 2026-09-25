<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__.'/../config/database.php';
$pdo=db();
$segmentsExist=(bool)$pdo->query("SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='appointment_services'")->fetchColumn();
$activeIntervals=$segmentsExist?"SELECT a.id,a.appointment_date,seg.staff_id,seg.start_time,seg.end_time FROM appointments a JOIN appointment_services seg ON seg.appointment_id=a.id WHERE a.status IN ('pending','confirmed') UNION ALL SELECT a.id,a.appointment_date,a.staff_id,a.start_time,a.end_time FROM appointments a WHERE a.status IN ('pending','confirmed') AND NOT EXISTS (SELECT 1 FROM appointment_services seg WHERE seg.appointment_id=a.id)":"SELECT id,appointment_date,staff_id,start_time,end_time FROM appointments WHERE status IN ('pending','confirmed')";
$checks=[
 'overlapping active appointments'=>"SELECT COUNT(*) FROM ($activeIntervals) a JOIN ($activeIntervals) b ON a.staff_id=b.staff_id AND a.appointment_date=b.appointment_date AND a.id<b.id AND a.start_time<b.end_time AND b.start_time<a.end_time",
 'invalid appointment intervals'=>"SELECT COUNT(*) FROM appointments WHERE start_time>=end_time OR appointment_date='0000-00-00'",
 'missing or inconsistent payments'=>"SELECT COUNT(*) FROM appointments a LEFT JOIN payments p ON p.appointment_id=a.id JOIN salons s ON s.id=a.salon_id WHERE p.id IS NULL OR p.customer_id<>a.customer_id OR p.vendor_id<>s.vendor_id OR p.amount<>a.amount OR p.method<>a.payment_method OR p.status<>a.payment_status"
];
$lock=__DIR__.'/../config/maintenance.lock';
if (file_exists($lock)) { fwrite(STDERR,"Maintenance lock already exists. Investigate before retrying.\n"); exit(1); }
file_put_contents($lock,date(DATE_ATOM));
try {
    $guard=fopen(__DIR__.'/../config/maintenance.guard','c');
    if (!$guard || !flock($guard,LOCK_EX)) throw new RuntimeException('Unable to drain active requests.');
    foreach ($checks as $label=>$sql) {
        $count=(int)$pdo->query($sql)->fetchColumn();
        echo "$label: $count\n";
        if ($count) throw new RuntimeException('Preflight failed; data was not changed.');
    }
    $backup=sys_get_temp_dir().'/barber-backup-'.DB_NAME.'-'.date('Ymd-His').'-'.bin2hex(random_bytes(3)).'.sql';
    $dump=getenv('BARBER_MYSQLDUMP') ?: 'C:/xampp/mysql/bin/mysqldump.exe';
    $env=getenv(); $env['MYSQL_PWD']=DB_PASS;
    $process=proc_open([$dump,'--host='.DB_HOST,'--user='.DB_USER,'--single-transaction','--routines',DB_NAME],[0=>['pipe','r'],1=>['file',$backup,'w'],2=>['pipe','w']],$pipes,null,$env);
    if (!is_resource($process)) throw new RuntimeException('Cannot start database backup.');
    fclose($pipes[0]); $error=stream_get_contents($pipes[2]); fclose($pipes[2]);
    if (proc_close($process)!==0 || filesize($backup)<100) throw new RuntimeException('Backup failed: '.$error);
    echo 'Backup: '.$backup."\n";
    $pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations(version VARCHAR(100) PRIMARY KEY, applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)');
    foreach (['002_booking_workflows','003_multiple_services','004_service_segments'] as $version) {
    $done=$pdo->prepare('SELECT 1 FROM schema_migrations WHERE version=?'); $done->execute([$version]);
    if (!$done->fetchColumn()) {
        foreach (explode(';',file_get_contents(__DIR__.'/../database/migrations/'.$version.'.sql')) as $sql) if (trim($sql)!=='') $pdo->exec($sql);
        $pdo->prepare('INSERT INTO schema_migrations(version) VALUES (?)')->execute([$version]);
    }
    }
    echo "Migration complete (safe to rerun).\n";
    unlink($lock);
} catch (Throwable $error) {
    fwrite(STDERR,$error->getMessage()."\nMaintenance remains enabled. Review the backup and resolve the error before removing config/maintenance.lock.\n");
    exit(1);
}
