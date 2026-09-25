<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
putenv('BARBER_DB_NAME=barber_company_test');
require __DIR__.'/../config/database.php';
$base='http://127.0.0.1:8091/Barber/'; $checks=0; $failures=0;
$fixtures=json_decode(file_get_contents(__DIR__.'/fixtures.json'),true);
function check(bool $ok,string $label): void { global $checks,$failures; $checks++; if (!$ok) $failures++; echo ($ok?'PASS ':'FAIL ').$label.PHP_EOL; }
function client() { $c=curl_init(); curl_setopt($c,CURLOPT_COOKIEFILE,''); return $c; }
function request($c,string $path,?array $post=null,?string $root=null): array {
    global $base;
    curl_setopt_array($c,[CURLOPT_URL=>($root??$base).$path,CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_TIMEOUT=>15,CURLOPT_POST=>$post!==null]);
    if ($post!==null) curl_setopt($c,CURLOPT_POSTFIELDS,http_build_query($post));
    $body=curl_exec($c); if ($body===false) throw new RuntimeException(curl_error($c));
    return ['body'=>$body,'status'=>curl_getinfo($c,CURLINFO_RESPONSE_CODE),'redirect'=>curl_getinfo($c,CURLINFO_REDIRECT_URL)];
}
function token($c,string $path): string {
    $r=request($c,$path);
    if (!preg_match('/name="csrf_token" value="([^"]+)"/',$r['body'],$m)) throw new RuntimeException('Missing token on '.$path.': '.$r['body']);
    return $m[1];
}
function login($c,string $email): void {
    $r=request($c,'auth/login.php',['csrf_token'=>token($c,'auth/login.php'),'identity'=>$email,'password'=>'TestPass123!']);
    check($r['status']===302,'Login '.$email);
}
function scalar(string $sql,array $params=[]): mixed { $s=db()->prepare($sql); $s->execute($params); return $s->fetchColumn(); }
$guest=client();
foreach (['','salons.php','salons.php?q=cut&sort=price','services.php','salon.php?slug=test-studio','about.php','auth/login.php','auth/register.php','assets/css/app.css','assets/salon-placeholder.svg'] as $path) {
    $r=request($guest,$path); check($r['status']===200 && !str_contains($r['body'],'Fatal error'),'Public route '.$path);
}
check(str_contains(request($guest,'salons.php')['body'],'No services available'),'Empty approved salon renders explicit state');
check(str_contains(request($guest,'')['body'],'No services available'),'Homepage handles empty salon');
foreach (['database/database.sql','.git/HEAD','README.md','AUDIT.md','tests/fixtures.json','config/database.php','includes/functions.php'] as $path) {
    $r=request($guest,$path,null,'http://localhost/Barber/'); check(in_array($r['status'],[403,404],true),'XAMPP denies private file '.$path);
}
check(request($guest,'salons.php?q%5B%5D=x')['status']===400,'Malformed array search returns controlled 400');
check(request($guest,'salon.php?slug=does-not-exist')['status']===404,'Unknown salon returns 404');
foreach (['customer/dashboard.php','customer/appointments.php','vendor/dashboard.php','vendor/manage.php','admin/dashboard.php','notifications.php'] as $path) check(request($guest,$path)['status']===302,'Guest blocked '.$path);
$fresh=client(); check(request($fresh,'auth/login.php',['csrf_token'=>'','identity'=>'customer@example.test','password'=>'TestPass123!'])['status']===403,'Fresh blank CSRF login rejected');
check(request($guest,'auth/register.php',['csrf_token'=>['bad']])['status']===400,'Array CSRF rejected without PHP error');
$customer=client(); login($customer,'customer@example.test');
foreach (['customer/dashboard.php','customer/appointments.php','notifications.php'] as $path) check(request($customer,$path)['status']===200,'Customer route '.$path);
check(request($customer,'vendor/manage.php')['status']===302,'Customer cannot access vendor tools');
$path='customer/booking.php?salon_id='.$fixtures['salonId'].'&service_id='.$fixtures['serviceId'].'&staff_id='.$fixtures['staffId'].'&appointment_date='.date('Y-m-d',strtotime('+4 days'));
$r=request($customer,$path); check($r['status']===200 && str_contains($r['body'],'Available time'),'Available booking times render');
preg_match('/name="request_key" value="([^"]+)"/',$r['body'],$m);
$booking=['csrf_token'=>token($customer,$path),'request_key'=>$m[1],'salon_id'=>$fixtures['salonId'],'service_id'=>$fixtures['serviceId'],'staff_id'=>$fixtures['staffId'],'appointment_date'=>date('Y-m-d',strtotime('+4 days')),'start_time'=>'10:00','payment_method'=>'upi'];
$r=request($customer,$path,$booking); check($r['status']===302 && str_contains($r['redirect'],'appointment.php?id='),'HTTP booking auto-confirms and opens details');
preg_match('/id=(\d+)/',$r['redirect'],$m); $id=(int)($m[1]??0); $details='customer/appointment.php?id='.$id;
check($id>0 && scalar('SELECT status FROM appointments WHERE id=?',[$id])==='confirmed','Saved HTTP booking is confirmed');
$r=request($customer,$details); check(str_contains($r['body'],'Change deadline:') && str_contains($r['body'],'Simulate success'),'Deadline and demo checkout visible');
$csrf=token($customer,$details);
$near=new DateTimeImmutable('+29 minutes');
$s=db()->prepare('UPDATE appointments SET appointment_date=?,start_time=?,end_time=? WHERE id=?'); $s->execute([$near->format('Y-m-d'),$near->format('H:i:s'),$near->modify('+45 minutes')->format('H:i:s'),$id]);
$late=request($customer,$details); check(str_contains($late['body'],'The change deadline has passed') && !str_contains($late['body'],'value="cancel"'),'Expired deadline hides customer change controls');
$late=request($customer,$details,['csrf_token'=>$csrf,'action'=>'cancel']); check(str_contains($late['body'],'Changes require at least 30 minutes') && scalar('SELECT status FROM appointments WHERE id=?',[$id])==='confirmed','Forged late cancellation rejected by server');
$s->execute([$booking['appointment_date'],'10:00:00','10:45:00',$id]);
$r=request($customer,$details,['csrf_token'=>$csrf,'action'=>'pay','outcome'=>'failed','request_key'=>bin2hex(random_bytes(24))]); check($r['status']===302 && scalar('SELECT payment_status FROM appointments WHERE id=?',[$id])==='failed','HTTP simulated failure');
$r=request($customer,$details,['csrf_token'=>$csrf,'action'=>'pay','outcome'=>'paid','request_key'=>bin2hex(random_bytes(24))]); check($r['status']===302 && scalar('SELECT payment_status FROM appointments WHERE id=?',[$id])==='paid','HTTP simulated retry success');
$r=request($customer,$details,['csrf_token'=>$csrf,'action'=>'reschedule','appointment_date'=>$booking['appointment_date'],'start_time'=>'11:00']); check($r['status']===302 && scalar('SELECT start_time FROM appointments WHERE id=?',[$id])==='11:00:00','HTTP customer reschedule');
$r=request($customer,$details,['csrf_token'=>$csrf,'action'=>'cancel']); check($r['status']===302 && scalar('SELECT payment_status FROM appointments WHERE id=?',[$id])==='refunded','HTTP cancellation refunds payment');
$other=client(); login($other,'other@example.test'); check(request($other,$details)['status']===404,'Cross-customer booking detail denied');
$vendor=client(); login($vendor,'vendor@example.test');
foreach (['vendor/dashboard.php','vendor/appointments.php','vendor/manage.php','vendor/manage.php?section=services','vendor/manage.php?section=staff','notifications.php'] as $path) check(request($vendor,$path)['status']===200,'Vendor route '.$path);
$r=request($vendor,'vendor/appointments.php'); check(str_contains($r['body'],'customer@example.test') && str_contains($r['body'],'Next appointments'),'Vendor sees customer details');
$r=request($vendor,'vendor/appointments.php',['csrf_token'=>token($vendor,'vendor/appointments.php'),'action'=>'cancel','appointment_id'=>$id]); check(str_contains($r['body'],'Vendors cannot cancel or reschedule'),'Vendor cancel action rejected');
$manage='vendor/manage.php?section=services';
$r=request($vendor,$manage,['csrf_token'=>token($vendor,$manage),'name'=>'HTTP Service','price'=>'123.45','duration_minutes'=>'30','category_id'=>'1','status'=>'active','description'=>'HTTP created']);
check($r['status']===302 && (int)scalar("SELECT COUNT(*) FROM services WHERE salon_id=? AND name='HTTP Service'",[$fixtures['salonId']])===1,'Vendor creates service through HTTP');
$serviceId=(int)scalar("SELECT id FROM services WHERE salon_id=? AND name='HTTP Service'",[$fixtures['salonId']]);
$r=request($vendor,'vendor/manage.php?section=staff',['csrf_token'=>token($vendor,'vendor/manage.php?section=staff'),'name'=>'HTTP Specialist','phone'=>'9001234567','specialization'=>'Hair','experience_years'=>'2','status'=>'active','service_ids'=>[(string)$serviceId],'bio'=>'Test']);
check($r['status']===302 && (int)scalar('SELECT COUNT(*) FROM staff_services WHERE service_id=?',[$serviceId])===1,'Vendor creates and assigns specialist through HTTP');
$multiPath='customer/booking.php?'.http_build_query(['salon_id'=>$fixtures['salonId'],'service_ids'=>[$fixtures['serviceId'],$serviceId],'appointment_date'=>date('Y-m-d',strtotime('+4 days'))]);
$multiPage=request($customer,$multiPath);
check($multiPage['status']===200 && str_contains($multiPage['body'],'No one specialist performs every selected service.') && str_contains($multiPage['body'],'Available time'),'Split specialist booking form shows assigned people and times');
preg_match_all('/name="staff_plan\[(\d+)\]" value="(\d+)"/',$multiPage['body'],$planMatches,PREG_SET_ORDER);
$httpPlan=[]; foreach($planMatches as $match) $httpPlan[$match[1]]=$match[2];
preg_match('/name="request_key" value="([^"]+)"/',$multiPage['body'],$keyMatch);
preg_match('/name="csrf_token" value="([^"]+)"/',$multiPage['body'],$tokenMatch);
$multiPost=['csrf_token'=>$tokenMatch[1]??'','request_key'=>$keyMatch[1]??'','salon_id'=>$fixtures['salonId'],'service_ids'=>[$fixtures['serviceId'],$serviceId],'staff_plan'=>$httpPlan,'staff_id'=>'0','appointment_date'=>date('Y-m-d',strtotime('+4 days')),'start_time'=>'13:00','payment_method'=>'cash'];
$multiResponse=request($customer,'customer/booking.php',$multiPost);
preg_match('/id=(\d+)/',(string)$multiResponse['redirect'],$multiIdMatch); $multiId=(int)($multiIdMatch[1]??0);
if (!$multiId) fwrite(STDERR,'Multi booking HTTP '.(string)$multiResponse['status'].' '.strip_tags(substr($multiResponse['body'],0,1000)).PHP_EOL);
check($multiResponse['status']===302 && $multiId>0 && (int)scalar('SELECT COUNT(*) FROM appointment_services WHERE appointment_id=?',[$multiId])===2,'HTTP multi-service booking reserves both specialist segments');
$note=(int)scalar('SELECT id FROM notifications WHERE user_id=? ORDER BY id DESC LIMIT 1',[$fixtures['customer']]);
$r=request($customer,'notifications.php',['csrf_token'=>token($customer,'notifications.php'),'id'=>$note]); check($r['status']===302 && (int)scalar('SELECT is_read FROM notifications WHERE id=?',[$note])===1,'Customer can mark own notification read');
$foreignNote=(int)scalar('SELECT id FROM notifications WHERE user_id=? ORDER BY id DESC LIMIT 1',[$fixtures['vendor']]);
request($customer,'notifications.php',['csrf_token'=>$csrf,'id'=>$foreignNote]); check((int)scalar('SELECT is_read FROM notifications WHERE id=?',[$foreignNote])===0,'Customer cannot mark vendor notification read');
check(request($customer,'auth/logout.php')['status']===405 && request($customer,'customer/dashboard.php')['status']===200,'GET logout does not change session');
check(request($customer,'auth/logout.php',['csrf_token'=>''])['status']===403,'POST logout requires CSRF');
$r=request($customer,'auth/logout.php',['csrf_token'=>token($customer,'customer/dashboard.php')]); check($r['status']===302 && request($customer,'customer/dashboard.php')['status']===302,'Valid POST logout clears session');
scalar("UPDATE users SET status='inactive' WHERE id=?",[$fixtures['vendor']]); check(request($vendor,'vendor/dashboard.php')['status']===302,'Disabled account loses existing session access'); scalar("UPDATE users SET status='active' WHERE id=?",[$fixtures['vendor']]);
$admin=client(); login($admin,'admin@example.test'); check(request($admin,'admin/dashboard.php')['status']===200,'Admin dashboard renders');
$signup=client(); $tag='http'.bin2hex(random_bytes(4));
$data=['csrf_token'=>token($signup,'auth/register.php'),'name'=>'HTTP Partner','email'=>$tag.'@example.test','phone'=>'9001112223','password'=>'TestPass123!','confirm_password'=>'TestPass123!','role'=>'vendor','salon_name'=>'HTTP Partner Salon','city'=>'Mumbai','address'=>'Test address'];
$r=request($signup,'auth/register.php',$data); check($r['status']===302,'Vendor registration through HTTP');
$owner=(int)scalar('SELECT id FROM users WHERE email=?',[$data['email']]); $newSalon=(int)scalar('SELECT id FROM salons WHERE vendor_id=?',[$owner]);
$r=request($admin,'admin/dashboard.php',['csrf_token'=>token($admin,'admin/dashboard.php'),'salon_id'=>$newSalon,'decision'=>'approved']);
check(scalar('SELECT status FROM users WHERE id=?',[$owner])==='active','HTTP admin approval activates owner');
login($signup,$data['email']); check(request($signup,'vendor/dashboard.php')['status']===200,'Newly approved vendor can use dashboard');
check(request($guest,'salons.php')['status']===200,'Directory survives approval of service-less salon');
$r=request($admin,'admin/dashboard.php',['csrf_token'=>'','salon_id'=>$newSalon,'decision'=>'rejected']); check($r['status']===403,'Admin invalid CSRF rejected visibly');
echo "$checks checks, $failures failures.\n"; exit($failures?1:0);
