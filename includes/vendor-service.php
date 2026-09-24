<?php
declare(strict_types=1);
require_once __DIR__.'/booking-service.php';

function review_vendor(int $adminId,int $salonId,string $decision): void {
    transaction(function () use ($adminId,$salonId,$decision): void {
        actor($adminId,'admin'); $salon=salon_lock($salonId);
        if (!in_array($decision,['approved','rejected'],true)) throw new InvalidArgumentException('Invalid review decision.');
        if ($salon['status']!=='pending') return;
        $owner=query('SELECT status FROM users WHERE id=? FOR UPDATE',[$salon['vendor_id']])->fetchColumn();
        if ($owner!=='pending') throw new InvalidArgumentException('This vendor account is not pending. Review its account status first.');
        query('UPDATE salons SET status=? WHERE id=?',[$decision,$salonId]);
        query('UPDATE users SET status=? WHERE id=?',[$decision==='approved'?'active':'rejected',$salon['vendor_id']]);
        query('INSERT INTO notifications(user_id,title,message) VALUES (?,?,?)',[$salon['vendor_id'],'Partner application reviewed','Your salon application was '.$decision.'.']);
    });
}
function vendor_salon(int $vendorId): array {
    $salon=query('SELECT * FROM salons WHERE vendor_id=?',[$vendorId])->fetch();
    if (!$salon) throw new InvalidArgumentException('Salon not found.');
    return $salon;
}
function save_vendor(int $vendorId,string $section,array $data): void {
    transaction(function () use ($vendorId,$section,$data): void {
        actor($vendorId,'vendor'); $salon=salon_lock((int)vendor_salon($vendorId)['id']); $id=(int)($data['id']??0);
        if ($section==='profile') {
            $name=field($data,'name',160); $address=field($data,'address',255); $city=field($data,'city',100); $phone=field($data,'phone',30);
            $description=field($data,'description',5000,false); $image=field($data,'image_url',255,false);
            if ($image!=='' && (!filter_var($image,FILTER_VALIDATE_URL) || !in_array(strtolower(parse_url($image,PHP_URL_SCHEME) ?: ''),['http','https'],true))) throw new InvalidArgumentException('Use an HTTP or HTTPS image URL.');
            $open=field($data,'opening_time',5); $close=field($data,'closing_time',5);
            foreach ([$open,$close] as $time) if (!preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D',$time)) throw new InvalidArgumentException('Enter valid opening and closing times.');
            if ($open>=$close) throw new InvalidArgumentException('Closing time must be after opening time on the same day.');
            query('UPDATE salons SET name=?,address=?,city=?,phone=?,description=?,image_url=?,opening_time=?,closing_time=? WHERE id=?',[$name,$address,$city,$phone,$description,$image?:null,$open,$close,$salon['id']]);
            return;
        }
        if (!in_array($section,['services','staff'],true)) throw new InvalidArgumentException('Unknown management section.');
        if ($id && !query("SELECT id FROM $section WHERE id=? AND salon_id=? FOR UPDATE",[$id,$salon['id']])->fetchColumn()) throw new InvalidArgumentException('Record not found.');
        $name=field($data,'name',120); $status=field($data,'status',10);
        if (!in_array($status,['active','inactive'],true)) throw new InvalidArgumentException('Choose a valid status.');
        if ($section==='services') {
            $price=field($data,'price',12); $duration=field($data,'duration_minutes',4); $category=(int)($data['category_id']??0);
            if (!preg_match('/^\d{1,8}(\.\d{1,2})?$/D',$price) || (float)$price<=0) throw new InvalidArgumentException('Price must be a positive amount with at most two decimal places.');
            if (!ctype_digit($duration) || (int)$duration<1 || (int)$duration>1440) throw new InvalidArgumentException('Duration must be between 1 and 1440 minutes.');
            if ($category && !query('SELECT id FROM service_categories WHERE id=?',[$category])->fetchColumn()) throw new InvalidArgumentException('Choose a valid category.');
            $values=[$name,field($data,'description',5000,false),$price,(int)$duration,$category?:null,$status];
            if ($id) query('UPDATE services SET name=?,description=?,price=?,duration_minutes=?,category_id=?,status=? WHERE id=? AND salon_id=?',[...$values,$id,$salon['id']]);
            else query('INSERT INTO services(name,description,price,duration_minutes,category_id,status,salon_id) VALUES (?,?,?,?,?,?,?)',[...$values,$salon['id']]);
        } else {
            $experience=field($data,'experience_years',4,false) ?: '0';
            if (!is_numeric($experience) || (float)$experience<0 || (float)$experience>99.9) throw new InvalidArgumentException('Enter experience between 0 and 99.9 years.');
            $values=[$name,field($data,'phone',30,false),field($data,'specialization',180,false),field($data,'bio',5000,false),$experience,$status];
            if ($id) query('UPDATE staff SET name=?,phone=?,specialization=?,bio=?,experience_years=?,status=? WHERE id=? AND salon_id=?',[...$values,$id,$salon['id']]);
            else { query('INSERT INTO staff(name,phone,specialization,bio,experience_years,status,salon_id) VALUES (?,?,?,?,?,?,?)',[...$values,$salon['id']]); $id=(int)db()->lastInsertId(); }
            $services=$data['service_ids']??[];
            if (!is_array($services) || count($services)>100) throw new InvalidArgumentException('Invalid service assignments.');
            $services=array_unique(array_map(function($value) { if (!is_scalar($value) || !ctype_digit((string)$value)) throw new InvalidArgumentException('Invalid service assignment.'); return (int)$value; },$services));
            foreach ($services as $serviceId) if (!query('SELECT id FROM services WHERE id=? AND salon_id=?',[$serviceId,$salon['id']])->fetchColumn()) throw new InvalidArgumentException('Services must belong to your salon.');
            query('DELETE FROM staff_services WHERE staff_id=?',[$id]);
            foreach ($services as $serviceId) query('INSERT INTO staff_services(staff_id,service_id) VALUES (?,?)',[$id,$serviceId]);
        }
    });
}
