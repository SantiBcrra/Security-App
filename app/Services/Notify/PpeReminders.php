<?php
declare(strict_types=1);
namespace App\Services\Notify;
use App\Models\NotificationMarks;
use App\Services\PpeService;
final class PpeReminders
{
    public static function run(): array
    {
        $employees=PpeService::employeesForSystem(); $c=PpeService::compliance($employees); $sent=0; $today=PpeService::today();
        foreach($employees as $e){$s=$c['summaries'][(int)$e['id']]??null;if(!$s)continue;
            if($s['overall']==='vencido'&&NotificationMarks::claim('ppe.overdue:'.$e['uuid'].':'.$today)){Notifier::dispatch('ppe.overdue',['id'=>0,'uuid'=>$e['uuid'],'employee_name'=>$e['name'],'sector_id'=>$e['sector_id'],'sector_name'=>$e['sector_name']??'']);$sent++;}
            $oldEnough = empty($e['created_at']) || strtotime((string)$e['created_at']) <= time()-3*86400;
            if($s['overall']==='nunca' && $oldEnough && NotificationMarks::claim('ppe.missing:'.$e['uuid'])){Notifier::dispatch('ppe.missing',['id'=>0,'uuid'=>$e['uuid'],'employee_name'=>$e['name'],'sector_id'=>$e['sector_id'],'sector_name'=>$e['sector_name']??'']);$sent++;}}
        if((int)gmdate('N')===1&&NotificationMarks::claim('ppe.due_soon:'.$today))foreach($c['sectors'] as $s)if($s['por_vencer']>0){Notifier::dispatch('ppe.due_soon',['id'=>0,'uuid'=>'','sector_id'=>null,'sector_name'=>$s['name']],['count'=>$s['por_vencer']]);$sent++;}
        return ['sent'=>$sent];
    }
}
