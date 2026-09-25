<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') exit;
putenv('BARBER_DB_NAME=barber_company_test');
require __DIR__.'/../includes/vendor-service.php';
if (DB_NAME!=='barber_company_test') exit(2);
$checks=0; $failures=0;
function check(bool $ok,string $label): void { global $checks,$failures; $checks++; if (!$ok) $failures++; echo ($ok?'PASS ':'FAIL ').$label.PHP_EOL; }
function reject(callable $fn,string $label): void { try { $fn(); check(false,$label); } catch (InvalidArgumentException) { check(true,$label); } }
function fetch_booking(int $id): array { return query('SELECT * FROM appointments WHERE id=?',[$id])->fetch(); }
function count_table(string $table): int { return (int)query('SELECT COUNT(*) FROM '.$table)->fetchColumn(); }
function race(array $jobs): array {
    $gate=tempnam(sys_get_temp_dir(),'barbergate'); unlink($gate); $workers=[];
    foreach ($jobs as $job) {
        $job['gate']=$gate; $file=tempnam(sys_get_temp_dir(),'barberjob'); file_put_contents($file,json_encode($job));
        $process=proc_open([PHP_BINARY,__DIR__.'/worker.php',$file],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        fclose($pipes[0]); $workers[]=[$process,$pipes,$file];
    }
    file_put_contents($gate,'go'); $results=[];
    foreach ($workers as [$process,$pipes,$file]) { $out=stream_get_contents($pipes[1]); $err=stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]); proc_close($process); unlink($file); $results[]=json_decode($out,true)??['ok'=>false,'error'=>$out.$err]; }
    unlink($gate); return $results;
}
// Dedicated test database only: reset fixture content while retaining the migrated schema.
db()->exec('SET FOREIGN_KEY_CHECKS=0');
foreach (['payment_events','appointment_events','payments','appointment_services','appointments','notifications','reviews','favorites','salon_gallery','staff_services','staff','services','salons','users'] as $table) db()->exec('TRUNCATE TABLE '.$table);
db()->exec('SET FOREIGN_KEY_CHECKS=1');
function user_fixture(string $role,string $suffix,string $status='active'): int {
    query('INSERT INTO users(name,email,phone,password_hash,role,status) VALUES (?,?,?,?,?,?)',['Test '.$suffix,$suffix.'@example.test','9000000000',password_hash('TestPass123!',PASSWORD_DEFAULT),$role,$status]); return (int)db()->lastInsertId();
}
$admin=user_fixture('admin','admin'); $vendor=user_fixture('vendor','vendor','pending'); $customer=user_fixture('customer','customer'); $other=user_fixture('customer','other'); $vendor2=user_fixture('vendor','vendor2');
$emptyVendor=user_fixture('vendor','empty'); query("INSERT INTO salons(vendor_id,name,slug,address,city,status) VALUES (?,'Empty Studio','empty-studio','Test address','Mumbai','approved')",[$emptyVendor]);
query("INSERT INTO salons(vendor_id,name,slug,address,city,status) VALUES (?,'Test Studio','test-studio','Test address','Mumbai','pending')",[$vendor]); $salonId=(int)db()->lastInsertId();
query("INSERT INTO salons(vendor_id,name,slug,address,city,status) VALUES (?,'Other Studio','other-studio','Test address','Delhi','approved')",[$vendor2]); $salon2=(int)db()->lastInsertId();
review_vendor($admin,$salonId,'approved'); review_vendor($admin,$salonId,'approved');
check(query('SELECT status FROM users WHERE id=?',[$vendor])->fetchColumn()==='active','Approval activates vendor and repeated approval is safe');
check(query('SELECT status FROM salons WHERE id=?',[$salonId])->fetchColumn()==='approved','Approval updates salon');
$rejected=user_fixture('vendor','rejected','pending'); query("INSERT INTO salons(vendor_id,name,slug,address,city) VALUES (?,'Reject','reject','Test','Mumbai')",[$rejected]); $rejectSalon=(int)db()->lastInsertId(); review_vendor($admin,$rejectSalon,'rejected');
check(query('SELECT status FROM users WHERE id=?',[$rejected])->fetchColumn()==='rejected','Rejection updates vendor account');
reject(fn()=>review_vendor($customer,$salonId,'approved'),'Customer cannot review applications');
$serviceData=['name'=>'Precision Cut','price'=>'321.50','duration_minutes'=>'45','category_id'=>'1','status'=>'active','description'=>'A test service'];
save_vendor($vendor,'services',$serviceData); $serviceId=(int)query('SELECT id FROM services WHERE salon_id=?',[$salonId])->fetchColumn();
save_vendor($vendor2,'services',$serviceData); $foreignService=(int)query('SELECT id FROM services WHERE salon_id=?',[$salon2])->fetchColumn();
$staffData=['name'=>'Test Specialist','phone'=>'9001234567','specialization'=>'Hair','experience_years'=>'4','status'=>'active','service_ids'=>[(string)$serviceId]];
save_vendor($vendor,'staff',$staffData); $staffId=(int)query('SELECT id FROM staff WHERE salon_id=?',[$salonId])->fetchColumn();
check((bool)query('SELECT 1 FROM staff_services WHERE staff_id=? AND service_id=?',[$staffId,$serviceId])->fetchColumn(),'Staff assigned to service');
reject(fn()=>save_vendor($vendor,'staff',[...$staffData,'id'=>$staffId,'service_ids'=>[(string)$foreignService]]),'Cross-salon service assignment rejected');
reject(fn()=>save_vendor($vendor2,'services',[...$serviceData,'id'=>$serviceId]),'Cross-salon service edit rejected');
reject(fn()=>save_vendor($customer,'services',$serviceData),'Customer cannot manage salon');
reject(fn()=>save_vendor($vendor,'services',[...$serviceData,'price'=>'-1']),'Negative service price rejected');
reject(fn()=>save_vendor($vendor,'services',[...$serviceData,'duration_minutes'=>'0']),'Zero duration rejected');
$salon=query('SELECT * FROM salons WHERE id=?',[$salonId])->fetch();
reject(fn()=>save_vendor($vendor,'profile',[...$salon,'opening_time'=>'20:00','closing_time'=>'09:00']),'Overnight opening hours rejected');
// Pure boundary tests use a fixed clock; HTTP/service operations always use the real server clock.
$now=new DateTimeImmutable('2030-01-01 10:00:00');
foreach (['10:30:01'=>true,'10:30:00'=>true,'10:29:59'=>false] as $time=>$expected) check(change_allowed(['status'=>'confirmed','appointment_date'=>'2030-01-01','start_time'=>$time],$now)===$expected,'30-minute boundary '.$time);
check(!change_allowed(['status'=>'completed','appointment_date'=>'2030-01-01','start_time'=>'11:00:00'],$now),'Completed appointments cannot change');
$boundary=['opening_time'=>'09:00:00','closing_time'=>'20:00:00'];
validate_interval($boundary,'2030-01-01','10:30',45,1800,$now); check(true,'Reschedule at exactly 30 minutes accepted');
reject(fn()=>validate_interval($boundary,'2030-01-01','10:29',45,1800,$now),'Reschedule destination inside deadline rejected');
reject(fn()=>validate_interval($boundary,'2030-02-30','10:30',45,0,$now),'Impossible date rejected');
reject(fn()=>validate_interval($boundary,'2030-01-01','10:00',45,0,$now),'Appointment at current time rejected');
validate_interval($boundary,'2030-01-01','19:15',45,0,$now); check(true,'Appointment ending at closing time accepted');
reject(fn()=>validate_interval($boundary,'2030-01-01','19:30',45,0,$now),'Appointment ending after closing rejected');
$tomorrow=date('Y-m-d',strtotime('+2 days'));
function input_booking(string $time='10:00',string $method='card'): array { global $salonId,$serviceId,$staffId,$tomorrow; return ['salon_id'=>$salonId,'service_id'=>$serviceId,'staff_id'=>$staffId,'appointment_date'=>$tomorrow,'start_time'=>$time,'payment_method'=>$method,'request_key'=>bin2hex(random_bytes(24))]; }
$input=input_booking(); $id=create_booking($customer,$input); $a=fetch_booking($id);
check($a['status']==='confirmed' && $a['payment_status']==='pending','Booking confirms before payment');
check(create_booking($customer,$input)===$id && count_table('appointments')===1,'Retry returns original booking');
check(count_table('payments')===1 && count_table('appointment_events')===1,'One payment and booking event per request');
check((int)query("SELECT COUNT(*) FROM notifications WHERE title='Appointment confirmed'")->fetchColumn()===2,'Customer and vendor notified');
reject(fn()=>create_booking($other,input_booking('10:15')),'Overlapping booking rejected');
reject(fn()=>create_booking($other,input_booking('08:00')),'Before-opening booking rejected');
reject(fn()=>create_booking($other,input_booking('19:45')),'Booking extending past close rejected');
reject(fn()=>create_booking($other,[...input_booking('10:00'),'appointment_date'=>date('Y-m-d',strtotime('-1 day'))]),'Past booking rejected');
check(count_table('appointments')===1 && count_table('payments')===1,'Failed bookings leave no partial data');
$before=[count_table('appointments'),count_table('payments'),count_table('appointment_events')];
db()->exec("CREATE TRIGGER test_notification_failure BEFORE INSERT ON notifications FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Intentional isolated test failure'");
try { create_booking($customer,input_booking('14:00')); check(false,'Storage failure must abort booking'); }
catch (PDOException) { check($before===[count_table('appointments'),count_table('payments'),count_table('appointment_events')],'Storage failure rolls back appointment, payment and history'); }
finally { db()->exec('DROP TRIGGER test_notification_failure'); }
$adjacent=create_booking($other,input_booking('10:45')); check($adjacent>0,'Adjacent appointment accepted');
$bundleServiceId=0;
query("INSERT INTO services(salon_id,name,price,duration_minutes,status) VALUES (?, 'Bundle Service', 150, 30, 'active')",[$salonId]);
$bundleServiceId=(int)db()->lastInsertId();
query('INSERT INTO staff_services(staff_id,service_id) VALUES (?,?)',[$staffId,$bundleServiceId]);
$bundleKey=bin2hex(random_bytes(24));
$bundle=create_booking($other,['salon_id'=>$salonId,'service_ids'=>[$serviceId,$bundleServiceId],'appointment_date'=>$tomorrow,'start_time'=>'13:00','payment_method'=>'card','request_key'=>$bundleKey]);
$bundleAppointment=fetch_booking($bundle);
check((int)$bundleAppointment['staff_id']===$staffId && (int)$bundleAppointment['duration_minutes']===75 && $bundleAppointment['service_items']!==null,'Multi-service booking assigns a qualified specialist automatically');
check(booking_plan($salon,[$serviceId,$bundleServiceId],$staffId)===[$serviceId=>$staffId,$bundleServiceId=>$staffId],'Customer can explicitly choose a specialist qualified for every service');
$secondStaffData=[...$staffData,'name'=>'Second Specialist','service_ids'=>[]];
save_vendor($vendor,'staff',$secondStaffData); $secondStaff=(int)query("SELECT id FROM staff WHERE salon_id=? AND name='Second Specialist'",[$salonId])->fetchColumn();
query("INSERT INTO services(salon_id,name,price,duration_minutes,status) VALUES (?, 'Specialty Finish', 90, 30, 'active')",[$salonId]);
$splitService=(int)db()->lastInsertId();
query('INSERT INTO staff_services(staff_id,service_id) VALUES (?,?)',[$secondStaff,$splitService]);
check(!booking_staff_candidates($salonId,[$serviceId,$splitService]),'No single specialist offers both split services');
reject(fn()=>booking_plan($salon,[$serviceId,$splitService],$staffId),'Cannot force one specialist who lacks an assignment');
$splitPlan=booking_plan($salon,[$serviceId,$splitService],0);
check($splitPlan[$serviceId]===$staffId && $splitPlan[$splitService]===$secondStaff,'Random fallback assigns a qualified specialist to each service');
$splitData=['salon_id'=>$salonId,'service_ids'=>[$serviceId,$splitService],'staff_plan'=>$splitPlan,'appointment_date'=>$tomorrow,'start_time'=>'16:00','payment_method'=>'cash','request_key'=>bin2hex(random_bytes(24))];
$split=create_booking($customer,$splitData); $splitAppointment=fetch_booking($split);
$splitRows=query('SELECT staff_id,start_time,end_time FROM appointment_services WHERE appointment_id=? ORDER BY position',[$split])->fetchAll();
$splitItems=[['id'=>$serviceId,'staff_id'=>$staffId,'duration_minutes'=>45],['id'=>$splitService,'staff_id'=>$secondStaff,'duration_minutes'=>30]];
check(!in_array('16:00',available_plan_slots($salon,$splitItems,$tomorrow),true),'Availability hides a time occupied by the second specialist');
check(count($splitRows)===2 && $splitRows[0]['staff_id']==$staffId && $splitRows[0]['start_time']==='16:00:00' && $splitRows[1]['staff_id']==$secondStaff && $splitRows[1]['start_time']==='16:45:00' && $splitAppointment['end_time']==='17:15:00','Split booking reserves consecutive segments under one appointment');
check((float)$splitAppointment['amount']===411.5 && (int)$splitAppointment['duration_minutes']===75 && (int)query('SELECT COUNT(*) FROM payments WHERE appointment_id=?',[$split])->fetchColumn()===1,'Split booking sums price and duration with one payment');
reject(fn()=>create_booking($other,['salon_id'=>$salonId,'service_id'=>$splitService,'staff_id'=>$secondStaff,'appointment_date'=>$tomorrow,'start_time'=>'16:45','payment_method'=>'cash','request_key'=>bin2hex(random_bytes(24))]),'Second segment blocks a conflicting booking');
$splitNewDate=date('Y-m-d',strtotime('+3 days'));
change_appointment($customer,$split,'reschedule',['appointment_date'=>$splitNewDate,'start_time'=>'18:00']);
check(query('SELECT start_time FROM appointment_services WHERE appointment_id=? AND position=1',[$split])->fetchColumn()==='18:45:00','Rescheduling moves both specialists atomically');
$reused=create_booking($other,['salon_id'=>$salonId,'service_id'=>$splitService,'staff_id'=>$secondStaff,'appointment_date'=>$tomorrow,'start_time'=>'16:45','payment_method'=>'cash','request_key'=>bin2hex(random_bytes(24))]);
check($reused>0,'Old second-specialist slot is available after rescheduling');
change_appointment($customer,$split,'cancel');
check(fetch_booking($split)['status']==='cancelled' && in_array('18:00',available_plan_slots($salon,$splitItems,$splitNewDate),true),'Cancelling split booking releases both specialist segments');
$slots=available_slots($salon,$staffId,$tomorrow,45); check(!in_array('10:15',$slots,true) && in_array('11:30',$slots,true),'Availability excludes conflicts and includes adjacent time');
reject(fn()=>change_appointment($other,$id,'cancel'),'Other customer cannot cancel');
reject(fn()=>change_appointment($vendor,$id,'cancel'),'Vendor cannot cancel');
reject(fn()=>change_appointment($vendor,$id,'reschedule',['appointment_date'=>$tomorrow,'start_time'=>'15:00']),'Vendor cannot reschedule');
reject(fn()=>change_appointment($customer,$id,'reschedule',['appointment_date'=>$tomorrow,'start_time'=>'10:45']),'Conflicting reschedule rejected');
check(fetch_booking($id)['start_time']==='10:00:00','Failed reschedule retains original appointment');
change_appointment($customer,$id,'reschedule',['appointment_date'=>$tomorrow,'start_time'=>'12:00']);
check(fetch_booking($id)['start_time']==='12:00:00' && fetch_booking($id)['booking_code']===$a['booking_code'],'Reschedule preserves booking identity');
$released=create_booking($other,input_booking('10:00')); check($released>0,'Old rescheduled slot immediately reusable');
$failKey=bin2hex(random_bytes(24)); simulate_payment($customer,$id,'failed',$failKey); simulate_payment($customer,$id,'failed',$failKey);
check(fetch_booking($id)['status']==='confirmed' && fetch_booking($id)['payment_status']==='failed' && count_table('payment_events')===1,'Failed demo payment preserves booking and is idempotent');
$paidKey=bin2hex(random_bytes(24)); simulate_payment($customer,$id,'paid',$paidKey); simulate_payment($customer,$id,'paid',$paidKey);
check(fetch_booking($id)['payment_status']==='paid' && count_table('payment_events')===2,'Payment retry succeeds once');
reject(fn()=>simulate_payment($other,$id,'paid',bin2hex(random_bytes(24))),'Other customer cannot pay booking');
change_appointment($customer,$id,'cancel'); change_appointment($customer,$id,'cancel');
check(fetch_booking($id)['status']==='cancelled' && fetch_booking($id)['payment_status']==='refunded','Cancellation refunds paid demo payment');
check(count_table('payment_events')===3,'Repeated cancellation does not duplicate refund');
reject(fn()=>simulate_payment($customer,$id,'paid',bin2hex(random_bytes(24))),'Cancelled appointment cannot be paid');
check(create_booking($other,input_booking('12:00'))>0,'Cancelled slot immediately reusable');
save_vendor($vendor,'services',[...$serviceData,'id'=>$serviceId,'name'=>'New Name','price'=>'999','duration_minutes'=>'30']);
check(fetch_booking($id)['service_name']==='Precision Cut' && fetch_booking($id)['amount']==='321.50' && (int)fetch_booking($id)['duration_minutes']===45,'Service edits preserve appointment snapshots');
save_vendor($vendor,'staff',[...$staffData,'id'=>$staffId,'status'=>'inactive']); reject(fn()=>create_booking($customer,input_booking('15:00')),'Inactive specialist cannot be booked');
save_vendor($vendor,'staff',[...$staffData,'id'=>$staffId]); save_vendor($vendor,'services',[...$serviceData,'id'=>$serviceId,'status'=>'inactive']); reject(fn()=>create_booking($customer,input_booking('15:00')),'Inactive service cannot be booked');
save_vendor($vendor,'services',[...$serviceData,'id'=>$serviceId]);
query("UPDATE users SET status='inactive' WHERE id=?",[$vendor]); reject(fn()=>create_booking($customer,input_booking('15:00')),'Inactive salon owner blocks booking'); query("UPDATE users SET status='active' WHERE id=?",[$vendor]);
// Real transaction concurrency using independent PHP processes/connections.
$raceA=input_booking('15:00'); $raceB=input_booking('15:15');
$results=race([['action'=>'book','actor'=>$customer,'data'=>$raceA],['action'=>'book','actor'=>$other,'data'=>$raceB]]);
check(count(array_filter($results,fn($r)=>$r['ok']))===1,'Concurrent overlapping requests: exactly one succeeds');
$same=input_booking('16:00'); $results=race([['action'=>'book','actor'=>$customer,'data'=>$same],['action'=>'book','actor'=>$customer,'data'=>$same]]);
check($results[0]['ok'] && $results[1]['ok'] && $results[0]['result']===$results[1]['result'],'Concurrent duplicate request returns one appointment');
$raceId=create_booking($customer,input_booking('17:00'));
$results=race([['action'=>'pay','actor'=>$customer,'id'=>$raceId,'key'=>bin2hex(random_bytes(24))],['action'=>'cancel','actor'=>$customer,'id'=>$raceId]]);
$raceBooking=fetch_booking($raceId); $racePayment=query('SELECT status FROM payments WHERE appointment_id=?',[$raceId])->fetchColumn();
check($raceBooking['status']==='cancelled' && in_array($racePayment,['pending','refunded'],true) && $raceBooking['payment_status']===$racePayment,'Payment/cancellation race keeps statuses consistent');
$cash=create_booking($customer,input_booking('18:00','cash')); reject(fn()=>simulate_payment($vendor,$cash,'paid',bin2hex(random_bytes(24)),true),'Cash cannot be collected before visit');
reject(fn()=>complete_appointment($vendor,$cash),'Cannot complete future appointment');
query('UPDATE appointments SET appointment_date=?,start_time=?,end_time=? WHERE id=?',[date('Y-m-d',strtotime('-1 day')),'10:00:00','10:45:00',$cash]);
reject(fn()=>simulate_payment($vendor2,$cash,'paid',bin2hex(random_bytes(24)),true),'Other vendor cannot collect cash');
simulate_payment($vendor,$cash,'paid',bin2hex(random_bytes(24)),true); complete_appointment($vendor,$cash); complete_appointment($vendor,$cash);
check(fetch_booking($cash)['status']==='completed' && fetch_booking($cash)['payment_status']==='paid','Vendor records demo cash and completes ended appointment');
// Simulate a stale form by changing the stored appointment after it was read.
$stale=create_booking($customer,input_booking('19:00')); $form=fetch_booking($stale); check(change_allowed($form),'Form initially eligible');
$soon=new DateTimeImmutable('+29 minutes'); query('UPDATE appointments SET appointment_date=?,start_time=?,end_time=? WHERE id=?',[$soon->format('Y-m-d'),$soon->format('H:i:s'),$soon->modify('+45 minutes')->format('H:i:s'),$stale]);
reject(fn()=>change_appointment($customer,$stale,'cancel'),'Stale form cannot bypass transaction deadline');
reject(fn()=>change_appointment($customer,$stale,'reschedule',['appointment_date'=>$tomorrow,'start_time'=>'14:00']),'Stale reschedule form cannot bypass deadline');
query('UPDATE appointments SET appointment_date=?,start_time=?,end_time=? WHERE id=?',[$tomorrow,'19:00:00','19:45:00',$stale]);
$_SESSION['csrf_token']='good'; foreach ([null,'',[],['x'],'bad'] as $token) check(!verify_csrf($token),'Invalid CSRF shape rejected'); check(verify_csrf('good'),'Valid CSRF accepted'); unset($_SESSION['csrf_token']); check(!verify_csrf(''),'Fresh-session empty CSRF rejected');
check(price_label(null)==='No services available','Empty salon has explicit price label');
check(date_default_timezone_get()==='Asia/Kolkata' && query('SELECT @@session.time_zone')->fetchColumn()==='+05:30','PHP and SQL business timezones agree');
file_put_contents(__DIR__.'/fixtures.json',json_encode(compact('admin','vendor','vendor2','customer','other','salonId','serviceId','staffId','id','tomorrow'),JSON_PRETTY_PRINT));
echo "$checks checks, $failures failures. Dedicated test fixtures retained for browser verification.\n";
exit($failures?1:0);
