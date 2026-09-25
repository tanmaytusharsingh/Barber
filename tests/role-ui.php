<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli') exit;
putenv('BARBER_DB_NAME=barber_company_test');
require __DIR__.'/../includes/booking-service.php';
$fixtures=json_decode(file_get_contents(__DIR__.'/fixtures.json'),true); $checks=0; $failures=0;
function check(bool $ok,string $label): void { global $checks,$failures; $checks++; if(!$ok) $failures++; echo ($ok?'PASS ':'FAIL ').$label.PHP_EOL; }
function client() { $c=curl_init(); curl_setopt($c,CURLOPT_COOKIEFILE,''); return $c; }
function request($c,string $path,?array $post=null): array {
    curl_setopt_array($c,[CURLOPT_URL=>'http://127.0.0.1:8091/Barber/'.$path,CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_TIMEOUT=>15,CURLOPT_POST=>$post!==null]);
    if($post!==null) curl_setopt($c,CURLOPT_POSTFIELDS,http_build_query($post));
    $body=curl_exec($c); if($body===false) throw new RuntimeException(curl_error($c));
    return ['body'=>$body,'status'=>curl_getinfo($c,CURLINFO_RESPONSE_CODE),'redirect'=>curl_getinfo($c,CURLINFO_REDIRECT_URL)];
}
function token($c,string $path): string { preg_match('/name="csrf_token" value="([^"]+)"/',request($c,$path)['body'],$m); return $m[1]??''; }
function login($c,string $name): void { check(request($c,'auth/login.php',['csrf_token'=>token($c,'auth/login.php'),'identity'=>$name.'@example.test','password'=>'TestPass123!'])['status']===302,'Login '.$name); }
function xpath(string $body): DOMXPath { $doc=new DOMDocument(); libxml_use_internal_errors(true); $doc->loadHTML('<?xml encoding="UTF-8">'.$body); libxml_clear_errors(); return new DOMXPath($doc); }
function ui_booking(string $code,string $date,string $time,string $status,string $method,string $payment,int $salonId=0): int {
    global $fixtures;
    query('INSERT INTO appointments(booking_code,customer_id,salon_id,service_id,staff_id,appointment_date,start_time,end_time,amount,payment_method,status,payment_status,service_name,staff_name,salon_name,duration_minutes) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',[$code,$fixtures['customer'],$salonId?:$fixtures['salonId'],$fixtures['serviceId'],$fixtures['staffId'],$date,$time,date('H:i:s',strtotime($time)+1800),'321.50',$method,$status,$payment,'UI Test Cut','UI Specialist','Test Studio',30]);
    $id=(int)db()->lastInsertId(); $owner=query('SELECT vendor_id FROM salons WHERE id=?',[$salonId?:$fixtures['salonId']])->fetchColumn();
    query('INSERT INTO payments(appointment_id,customer_id,vendor_id,amount,method,status) VALUES (?,?,?,?,?,?)',[$id,$fixtures['customer'],$owner,'321.50',$method,$payment]);
    return $id;
}
// Reserved UI fixture prefix: safe to refresh only these records in the isolated database.
query("DELETE FROM appointments WHERE booking_code LIKE 'UI-%'");
$future=date('Y-m-d',strtotime('+10 days')); $past=date('Y-m-d',strtotime('-2 days'));
$paid=ui_booking('UI-PREPAID',$future,'09:00','confirmed','card','paid');
$cash=ui_booking('UI-CASH',$future,'10:00','confirmed','cash','pending');
$cancelled=ui_booking('UI-CANCELLED',$future,'11:00','cancelled','cash','pending');
$unpaid=ui_booking('UI-UNPAID',$future,'12:00','confirmed','upi','pending');
$completed=ui_booking('UI-COMPLETED',$past,'09:00','completed','card','paid');
$foreignSalon=(int)query('SELECT id FROM salons WHERE vendor_id=?',[$fixtures['vendor2']])->fetchColumn();
$foreign=ui_booking('UI-FOREIGN',$future,'14:00','confirmed','card','paid',$foreignSalon);
foreach([[$completed,'visible','A careful cut and a welcoming salon.'],[$paid,'hidden','HIDDEN REVIEW SENTINEL'],[$foreign,'visible','FOREIGN REVIEW SENTINEL']] as [$id,$visibility,$review]) {
    $a=query('SELECT * FROM appointments WHERE id=?',[$id])->fetch();
    query('INSERT INTO reviews(appointment_id,customer_id,salon_id,staff_id,rating,service_rating,staff_rating,review,status) VALUES (?,?,?,?,5,5,5,?,?)',[$id,$a['customer_id'],$a['salon_id'],$a['staff_id'],$review,$visibility]);
}
$guest=client(); check(request($guest,'notifications-count.php')['status']===401,'Notification count requires sign-in'); check(request($guest,'profile.php')['status']===302,'Profile requires sign-in');
$customer=client(); login($customer,'customer');
$body=request($customer,'customer/dashboard.php')['body']; $xp=xpath($body);
$nav=$xp->query('//nav[@aria-label="Main navigation"]')->item(0)->textContent;
check(!str_contains($nav,'Our story'),'Customer header omits Our story');
check($xp->query('//details[contains(@class,"profile-menu")]//a[contains(@href,"notifications.php")]')->length===1,'Notifications live inside profile dropdown');
check($xp->query('//details[contains(@class,"profile-menu")]//a[contains(@href,"profile.php")]')->length===1,'Edit profile is in dropdown');
check($xp->query('//div[@class="account-actions"]/details/following-sibling::form')->length===1,'Profile menu precedes sign out');
$expected=(int)query('SELECT COUNT(*) FROM notifications WHERE user_id=? AND is_read=0',[$fixtures['customer']])->fetchColumn();
check(json_decode(request($customer,'notifications-count.php')['body'],true)['count']===$expected,'Unread count matches authenticated customer');
query('UPDATE notifications SET is_read=1 WHERE user_id=?',[$fixtures['customer']]);
check(json_decode(request($customer,'notifications-count.php')['body'],true)['count']===0,'Read notifications disappear from count');
check(xpath(request($customer,'customer/dashboard.php')['body'])->query('//span[@data-unread-count and @hidden]')->length===1,'Zero-count badge is hidden');
query('INSERT INTO notifications(user_id,title,message) VALUES (?,?,?)',[$fixtures['customer'],'UI notification','Profile badge test']);
check(json_decode(request($customer,'notifications-count.php')['body'],true)['count']===1,'New notification appears in count');
check(request($customer,'notifications-count.php',[])['status']===405,'Count endpoint is read-only');
$profileToken=token($customer,'profile.php'); $original=query('SELECT name,email,phone FROM users WHERE id=?',[$fixtures['customer']])->fetch();
check(request($customer,'profile.php',['csrf_token'=>'','name'=>'Changed'])['status']===403,'Profile requires CSRF');
$response=request($customer,'profile.php',['csrf_token'=>$profileToken,'name'=>'Profile Test User','email'=>'profile-edit@example.test','phone'=>'+91 9001234567','id'=>$fixtures['other'],'role'=>'admin']);
check($response['status']===302,'Profile save redirects');
$updated=query('SELECT name,email,phone,role FROM users WHERE id=?',[$fixtures['customer']])->fetch();
check($updated['name']==='Profile Test User' && $updated['role']==='customer','Profile edits own fields without role escalation');
check(query('SELECT name FROM users WHERE id=?',[$fixtures['other']])->fetchColumn()!=='Profile Test User','Posted user ID cannot edit another account');
check(str_contains(request($customer,'profile.php')['body'],'Profile Test User'),'Updated name appears in profile UI');
$response=request($customer,'profile.php',['csrf_token'=>$profileToken,'name'=>'Duplicate','email'=>'vendor@example.test','phone'=>'9001234567']); check(str_contains($response['body'],'already used'),'Duplicate email rejected');
$response=request($customer,'profile.php',['csrf_token'=>$profileToken,'name'=>'Invalid phone','email'=>'profile-edit@example.test','phone'=>'not-a-phone']); check(str_contains($response['body'],'valid phone'),'Invalid phone rejected');
query('UPDATE users SET name=?,email=?,phone=? WHERE id=?',[$original['name'],$original['email'],$original['phone'],$fixtures['customer']]);
check(request($customer,'vendor/appointments.php')['status']===302 && request($customer,'admin/salons.php')['status']===302,'Customer cannot access role workspaces');
$vendor=client(); login($vendor,'vendor');
check(str_ends_with(request($vendor,'index.php')['redirect'],'vendor/dashboard.php'),'Vendor home routes to dashboard');
$body=request($vendor,'vendor/dashboard.php')['body']; $xp=xpath($body);
check(str_contains($body,'Total appointments') && str_contains($body,'Completed appointments') && str_contains($body,'Customer reviews'),'Vendor dashboard has requested metrics');
check(str_contains($body,'A careful cut and a welcoming salon.') && !str_contains($body,'HIDDEN REVIEW SENTINEL') && !str_contains($body,'FOREIGN REVIEW SENTINEL'),'Reviews scoped to salon and visible status');
check($xp->query('//nav[@aria-label="Main navigation"]//a[contains(@href,"salons.php")]')->length===0,'Vendor navigation omits customer discovery');
$body=request($vendor,'vendor/appointments.php')['body']; $xp=xpath($body);
check($xp->query('//section[@id="upcoming"]//tr[contains(.,"UI-PREPAID")]')->length===1,'Future confirmed bookings are in next table');
check($xp->query('//section[@id="previous"]//tr[contains(.,"UI-CANCELLED")]')->length===1,'Future cancelled booking is in previous table');
check($xp->query('//section[@id="previous"]//tr[contains(.,"UI-COMPLETED")]')->length===1,'Completed booking is in previous table');
check($xp->query('//tr[contains(@class,"appointment-cancelled") and contains(.,"UI-CANCELLED")]')->length===1,'Cancelled cash booking has red priority');
check($xp->query('//tr[contains(@class,"appointment-prepaid") and contains(.,"UI-PREPAID")]')->length===1,'Paid card booking has green colour');
check($xp->query('//tr[contains(@class,"appointment-cash") and contains(.,"UI-CASH")]')->length===1,'Cash booking has yellow colour');
check($xp->query('//tr[contains(@class,"appointment-unpaid") and contains(.,"UI-UNPAID")]')->length===1,'Unpaid online booking remains neutral');
check(!str_contains($body,'UI-FOREIGN'),'Vendor cannot see another salon bookings');
check(!str_contains(request($vendor,'vendor/appointments.php?status=cancelled')['body'],'UI-PREPAID'),'Status filter applies to both tables');
check(str_contains(request($vendor,'vendor/appointments.php?date=not-a-date')['body'],'valid filter date'),'Malformed date filter handled');
$admin=client(); login($admin,'admin'); check(str_ends_with(request($admin,'index.php')['redirect'],'admin/dashboard.php'),'Admin home routes to dashboard');
$body=request($admin,'admin/dashboard.php')['body']; check(str_contains($body,'Waiting for review') && str_contains($body,'Manage salons'),'Admin retains approval dashboard and salon navigation');
$body=request($admin,'admin/salons.php')['body']; check(str_contains($body,'Test Studio') && str_contains($body,'Other Studio'),'Admin directory covers all salons');
$body=request($admin,'admin/salons.php?id='.$fixtures['salonId'])['body']; check(str_contains($body,'Owner account') && str_contains($body,'vendor@example.test') && str_contains($body,'Precision Cut'),'Salon detail includes owner and services');
check(request($admin,'admin/salons.php?id=9999999')['status']===404,'Unknown salon detail returns 404');
check(!str_contains(request($admin,'admin/salons.php?q=Other+Studio')['body'],'<strong>Test Studio</strong>'),'Admin salon search filters results');
check(request($vendor,'admin/salons.php')['status']===302,'Vendor cannot access admin salon directory');
echo "$checks UI checks, $failures failures. Fixtures retained only in isolated database.\n"; exit($failures?1:0);
