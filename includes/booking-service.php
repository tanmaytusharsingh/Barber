<?php
declare(strict_types=1);
require_once __DIR__.'/functions.php';

function query(string $sql, array $params=[]): PDOStatement {
    $stmt=db()->prepare($sql); $stmt->execute($params); return $stmt;
}
function transaction(callable $operation): mixed {
    $pdo=db(); $pdo->beginTransaction();
    try { $result=$operation(); $pdo->commit(); return $result; }
    catch (Throwable $error) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $error; }
}
function actor(int $id, string $role): array {
    $user=query('SELECT * FROM users WHERE id=?',[$id])->fetch();
    if (!$user || $user['status']!=='active' || $user['role']!==$role) throw new InvalidArgumentException('This action is not available for your account.');
    return $user;
}
function salon_lock(int $id): array {
    $salon=query('SELECT * FROM salons WHERE id=? FOR UPDATE',[$id])->fetch();
    if (!$salon) throw new InvalidArgumentException('Salon not found.');
    return $salon;
}
function appointment_lock(int $id): array {
    $hint=query('SELECT salon_id,staff_id FROM appointments WHERE id=?',[$id])->fetch();
    if (!$hint) throw new InvalidArgumentException('Appointment not found.');
    $salon=salon_lock((int)$hint['salon_id']);
    query('SELECT id FROM staff WHERE id=? FOR UPDATE',[$hint['staff_id']])->fetch();
    $appointment=query('SELECT * FROM appointments WHERE id=? FOR UPDATE',[$id])->fetch();
    return [$salon,$appointment];
}
function appointment_start(array $appointment): DateTimeImmutable {
    return new DateTimeImmutable($appointment['appointment_date'].' '.$appointment['start_time']);
}
function change_allowed(array $appointment, ?DateTimeImmutable $now=null): bool {
    return $appointment['status']==='confirmed' && appointment_start($appointment)->getTimestamp()-($now ?? new DateTimeImmutable())->getTimestamp()>=1800;
}
function validate_interval(array $salon, string $date, string $time, int $duration, int $lead=0, ?DateTimeImmutable $now=null): array {
    $start=DateTimeImmutable::createFromFormat('!Y-m-d H:i',$date.' '.$time);
    if (!$start || $start->format('Y-m-d H:i')!==$date.' '.$time || $duration<1 || $duration>1440) throw new InvalidArgumentException('Choose a valid date, time and service duration.');
    $now=$now ?? new DateTimeImmutable();
    $seconds=$start->getTimestamp()-$now->getTimestamp();
    if ($seconds<=0 || $seconds<$lead) throw new InvalidArgumentException($lead ? 'Choose a time at least 30 minutes from now.' : 'Choose a future appointment time.');
    $end=$start->modify('+'.$duration.' minutes');
    if ($start->format('H:i:s')<$salon['opening_time'] || $end->format('Y-m-d')!==$date || $end->format('H:i:s')>$salon['closing_time']) throw new InvalidArgumentException('The whole appointment must fit within salon opening hours.');
    return [$start,$end];
}
function eligible(array $salon, int $serviceId, int $staffId): array {
    $owner=query('SELECT status FROM users WHERE id=? FOR UPDATE',[$salon['vendor_id']])->fetchColumn();
    $staff=query('SELECT * FROM staff WHERE id=? AND salon_id=? FOR UPDATE',[$staffId,$salon['id']])->fetch();
    $service=query('SELECT * FROM services WHERE id=? AND salon_id=? FOR UPDATE',[$serviceId,$salon['id']])->fetch();
    $assignment=query('SELECT 1 FROM staff_services WHERE staff_id=? AND service_id=? FOR UPDATE',[$staffId,$serviceId])->fetchColumn();
    if ($salon['status']!=='approved' || $owner!=='active' || !$staff || $staff['status']!=='active' || !$service || $service['status']!=='active' || !$assignment) throw new InvalidArgumentException('This salon, service or specialist is no longer available.');
    return [$service,$staff];
}
function ensure_free(int $staffId, DateTimeImmutable $start, DateTimeImmutable $end, int $exclude=0): void {
    $conflict=query("SELECT id FROM appointments WHERE staff_id=? AND appointment_date=? AND status IN ('pending','confirmed') AND start_time<? AND end_time>? AND id<>? LIMIT 1 FOR UPDATE",[$staffId,$start->format('Y-m-d'),$end->format('H:i:s'),$start->format('H:i:s'),$exclude])->fetchColumn();
    if ($conflict) throw new InvalidArgumentException('That time has just been booked. Please choose another slot.');
}
function notify_booking(array $salon, array $appointment, string $title, string $message): void {
    foreach ([(int)$appointment['customer_id'],(int)$salon['vendor_id']] as $userId) query('INSERT INTO notifications(user_id,title,message) VALUES (?,?,?)',[$userId,$title,$message]);
}
function appointment_event(int $id, int $actorId, string $action, ?string $old=null, ?string $new=null): void {
    query('INSERT INTO appointment_events(appointment_id,actor_id,action,old_schedule,new_schedule) VALUES (?,?,?,?,?)',[$id,$actorId,$action,$old,$new]);
}
function booking_service_ids(array $data): array {
    $values=$data['service_ids']??[$data['service_id']??0];
    if (!is_array($values) || !$values || count($values)>20) throw new InvalidArgumentException('Choose between 1 and 20 services.');
    $ids=[];
    foreach ($values as $value) {
        if ((!is_string($value) && !is_int($value)) || !ctype_digit((string)$value) || (int)$value<1) throw new InvalidArgumentException('Choose valid services.');
        $ids[]=(int)$value;
    }
    $ids=array_values(array_unique($ids)); sort($ids); return $ids;
}
function create_booking(int $customerId, array $data): int {
    return transaction(function () use ($customerId,$data): int {
        actor($customerId,'customer');
        $key=field($data,'request_key',64);
        if (!preg_match('/^[a-f0-9]{32,64}$/D',$key)) throw new InvalidArgumentException('Reload the booking page and try again.');
        $salon=salon_lock((int)($data['salon_id']??0));
        $existing=query('SELECT id FROM appointments WHERE customer_id=? AND request_key=? FOR UPDATE',[$customerId,$key])->fetchColumn();
        if ($existing) return (int)$existing;
        $serviceIds=booking_service_ids($data); $serviceId=$serviceIds[0]; $staffId=(int)($data['staff_id']??0);
        $items=[]; $duration=0; $cents=0;
        foreach ($serviceIds as $selectedId) {
            [$selected,$staff]=eligible($salon,$selectedId,$staffId);
            if ((int)$selected['duration_minutes']<1) throw new InvalidArgumentException('A selected service has an invalid duration.');
            $items[]=['id'=>$selectedId,'name'=>$selected['name'],'price'=>$selected['price'],'duration_minutes'=>(int)$selected['duration_minutes']];
            $duration+=(int)$selected['duration_minutes']; $cents+=(int)round((float)$selected['price']*100);
        }
        if ($cents>99999999) throw new InvalidArgumentException('The total appointment price is too high.');
        $service=['name'=>implode(' + ',array_column($items,'name')),'price'=>number_format($cents/100,2,'.',''),'duration_minutes'=>$duration];
        [$start,$end]=validate_interval($salon,field($data,'appointment_date',10),field($data,'start_time',5),(int)$service['duration_minutes']);
        ensure_free($staffId,$start,$end);
        $method=field($data,'payment_method',10);
        if (!in_array($method,['cash','upi','card'],true)) throw new InvalidArgumentException('Choose a valid payment method.');
        $code='TBC-'.date('ymd').'-'.strtoupper(bin2hex(random_bytes(5)));
        query("INSERT INTO appointments(booking_code,customer_id,salon_id,service_id,staff_id,appointment_date,start_time,end_time,amount,payment_method,status,service_name,staff_name,salon_name,duration_minutes,request_key) VALUES (?,?,?,?,?,?,?,?,?,?,'confirmed',?,?,?,?,?)",[$code,$customerId,$salon['id'],$serviceId,$staffId,$start->format('Y-m-d'),$start->format('H:i:s'),$end->format('H:i:s'),$service['price'],$method,$service['name'],$staff['name'],$salon['name'],$service['duration_minutes'],$key]);
        $id=(int)db()->lastInsertId();
        query('UPDATE appointments SET service_items=? WHERE id=?',[json_encode($items,JSON_THROW_ON_ERROR),$id]);
        query('INSERT INTO payments(appointment_id,customer_id,vendor_id,amount,method) VALUES (?,?,?,?,?)',[$id,$customerId,$salon['vendor_id'],$service['price'],$method]);
        appointment_event($id,$customerId,'booked',null,$start->format('Y-m-d H:i:s'));
        notify_booking($salon,['customer_id'=>$customerId],'Appointment confirmed',$code.': '.$service['name'].' on '.$start->format('d M Y H:i').'. No approval is needed.');
        return $id;
    });
}
function payment_state(array $payment, int $actorId, string $status, string $key): void {
    $reference='SIM-'.strtoupper(bin2hex(random_bytes(8)));
    query('UPDATE payments SET status=?,transaction_reference=?,paid_at=CASE WHEN ?=\'paid\' THEN NOW() ELSE paid_at END WHERE id=?',[$status,$reference,$status,$payment['id']]);
    query('UPDATE appointments SET payment_status=? WHERE id=?',[$status,$payment['appointment_id']]);
    query('INSERT INTO payment_events(payment_id,actor_id,request_key,status,reference) VALUES (?,?,?,?,?)',[$payment['id'],$actorId,$key,$status,$reference]);
}
function change_appointment(int $customerId,int $id,string $action,array $data=[]): void {
    transaction(function () use ($customerId,$id,$action,$data): void {
        actor($customerId,'customer'); [$salon,$appointment]=appointment_lock($id);
        if ((int)$appointment['customer_id']!==$customerId) throw new InvalidArgumentException('Appointment not found.');
        if (!in_array($action,['cancel','reschedule'],true)) throw new InvalidArgumentException('Invalid appointment action.');
        if ($action==='cancel' && $appointment['status']==='cancelled') return;
        if (!change_allowed($appointment)) throw new InvalidArgumentException('Changes require at least 30 minutes before the appointment starts.');
        $old=$appointment['appointment_date'].' '.$appointment['start_time'];
        if ($action==='cancel') {
            $payment=query('SELECT * FROM payments WHERE appointment_id=? FOR UPDATE',[$id])->fetch();
            if (!$payment) throw new RuntimeException('Missing payment for appointment '.$id);
            query("UPDATE appointments SET status='cancelled' WHERE id=?",[$id]);
            if ($payment['status']==='paid') payment_state($payment,$customerId,'refunded','cancel-'.$id);
            appointment_event($id,$customerId,'cancelled',$old);
            notify_booking($salon,$appointment,'Appointment cancelled',$appointment['booking_code'].' was cancelled. Any paid amount has been fully refunded in the simulation.');
        } else {
            $items=json_decode($appointment['service_items']??'null',true)??[['id'=>$appointment['service_id']]];
            foreach ($items as $item) eligible($salon,(int)$item['id'],(int)$appointment['staff_id']);
            [$start,$end]=validate_interval($salon,field($data,'appointment_date',10),field($data,'start_time',5),(int)$appointment['duration_minutes'],1800);
            if ($old===$start->format('Y-m-d H:i:s')) return;
            ensure_free((int)$appointment['staff_id'],$start,$end,$id);
            query('UPDATE appointments SET appointment_date=?,start_time=?,end_time=? WHERE id=?',[$start->format('Y-m-d'),$start->format('H:i:s'),$end->format('H:i:s'),$id]);
            appointment_event($id,$customerId,'rescheduled',$old,$start->format('Y-m-d H:i:s'));
            notify_booking($salon,$appointment,'Appointment rescheduled',$appointment['booking_code'].' moved to '.$start->format('d M Y H:i').'.');
        }
    });
}
function complete_appointment(int $vendorId,int $id): void {
    transaction(function () use ($vendorId,$id): void {
        actor($vendorId,'vendor'); [$salon,$appointment]=appointment_lock($id);
        if ((int)$salon['vendor_id']!==$vendorId) throw new InvalidArgumentException('Appointment not found.');
        if ($appointment['status']==='completed') return;
        if ($appointment['status']!=='confirmed' || new DateTimeImmutable($appointment['appointment_date'].' '.$appointment['end_time'])>new DateTimeImmutable()) throw new InvalidArgumentException('Only confirmed appointments that have ended can be completed.');
        query("UPDATE appointments SET status='completed' WHERE id=?",[$id]);
        appointment_event($id,$vendorId,'completed');
        notify_booking($salon,$appointment,'Appointment completed',$appointment['booking_code'].' is completed.');
    });
}
function simulate_payment(int $actorId,int $id,string $outcome,string $key,bool $cash=false): void {
    transaction(function () use ($actorId,$id,$outcome,$key,$cash): void {
        actor($actorId,$cash?'vendor':'customer'); [$salon,$appointment]=appointment_lock($id);
        if (($cash?(int)$salon['vendor_id']:(int)$appointment['customer_id'])!==$actorId) throw new InvalidArgumentException('Appointment not found.');
        if (!preg_match('/^[a-f0-9]{32,64}$/D',$key) || !in_array($outcome,['paid','failed'],true)) throw new InvalidArgumentException('Invalid payment attempt.');
        $payment=query('SELECT * FROM payments WHERE appointment_id=? FOR UPDATE',[$id])->fetch();
        if (!$payment) throw new RuntimeException('Missing payment for appointment '.$id);
        if (query('SELECT id FROM payment_events WHERE payment_id=? AND request_key=? FOR UPDATE',[$payment['id'],$key])->fetchColumn()) return;
        if (!in_array($appointment['status'],['confirmed','completed'],true) || $payment['status']==='refunded') throw new InvalidArgumentException('This appointment cannot be paid.');
        if ($cash && ($payment['method']!=='cash' || $outcome!=='paid' || appointment_start($appointment)>new DateTimeImmutable())) throw new InvalidArgumentException('Cash collection is available at or after the appointment start.');
        if (!$cash && !in_array($payment['method'],['upi','card'],true)) throw new InvalidArgumentException('Cash is collected by the salon.');
        if ($payment['status']==='paid') return;
        payment_state($payment,$actorId,$outcome,$key);
        notify_booking($salon,$appointment,'Simulated payment '.$outcome,$appointment['booking_code'].': simulated payment '.$outcome.'. No real money was moved.');
    });
}
function available_slots(array $salon,int $staffId,string $date,int $duration,int $exclude=0,int $lead=0): array {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$date) || $duration<1) return [];
    $busy=query("SELECT start_time,end_time FROM appointments WHERE staff_id=? AND appointment_date=? AND status IN ('pending','confirmed') AND id<>?",[$staffId,$date,$exclude])->fetchAll();
    $slots=[];
    try { $cursor=new DateTimeImmutable($date.' '.$salon['opening_time']); $close=new DateTimeImmutable($date.' '.$salon['closing_time']); } catch (Throwable) { return []; }
    for (; $cursor<$close; $cursor=$cursor->modify('+15 minutes')) {
        try { [$start,$end]=validate_interval($salon,$date,$cursor->format('H:i'),$duration,$lead); } catch (InvalidArgumentException) { continue; }
        foreach ($busy as $item) if ($item['start_time']<$end->format('H:i:s') && $item['end_time']>$start->format('H:i:s')) continue 2;
        $slots[]=$start->format('H:i');
    }
    return $slots;
}
