<?php
declare(strict_types=1);
use App\Core\Uuid;
return function(PDO $db): void {
    $s=$db->prepare('INSERT INTO settings (`key`,`value`,updated_at) VALUES (?,?,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE `value`=`value`');
    $s->execute(['epp.aviso_dias','15']);
    $rules=[
        ['EPP por vencer','ppe.due_soon',[['type'=>'role','value'=>'responsable_hys'],['type'=>'role','value'=>'admin_empresa']],['app','email']],
        ['EPP vencido','ppe.overdue',[['type'=>'role','value'=>'responsable_hys'],['type'=>'role','value'=>'admin_empresa']],['app','email']],
        ['EPP pendiente de entrega','ppe.missing',[['type'=>'role','value'=>'responsable_hys'],['type'=>'role','value'=>'admin_empresa']],['app','email']],
    ];
    $q=$db->prepare('INSERT INTO notification_rules (uuid,name,event,min_severity_level,sector_id,recipients,channels,is_active,created_at,updated_at) SELECT ?,?,?,NULL,NULL,?,?,1,UTC_TIMESTAMP(),UTC_TIMESTAMP() FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM notification_rules WHERE event=?)');
    foreach($rules as [$name,$event,$recipients,$channels])$q->execute([Uuid::v4(),$name,$event,json_encode($recipients),json_encode($channels),$event]);
};
