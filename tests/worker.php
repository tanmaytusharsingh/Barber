<?php
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
require __DIR__.'/../includes/booking-service.php';
if (!str_ends_with(DB_NAME,'_test')) exit(2);
$job=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR);
while (!file_exists($job['gate'])) usleep(1000);
try {
    if ($job['action']==='book') $result=create_booking($job['actor'],$job['data']);
    elseif ($job['action']==='cancel') { change_appointment($job['actor'],$job['id'],'cancel'); $result=true; }
    elseif ($job['action']==='reschedule') { change_appointment($job['actor'],$job['id'],'reschedule',$job['data']); $result=true; }
    else { simulate_payment($job['actor'],$job['id'],'paid',$job['key']); $result=true; }
    echo json_encode(['ok'=>true,'result'=>$result]);
} catch (Throwable $error) { echo json_encode(['ok'=>false,'error'=>$error->getMessage()]); }
