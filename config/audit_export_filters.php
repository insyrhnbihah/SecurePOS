<?php
function auditExportFilters(array $data): array
{
    return ['start_date'=>$data['startInput'], 'end_date'=>$data['endInput'], 'actor'=>$data['actorSearch'], 'module'=>$data['moduleFilter'], 'result'=>$data['resultFilter'], 'risk'=>$data['riskFilter']];
}

function validateAuditExportInput(array $input): bool
{
    $lengths=['start_date'=>10,'end_date'=>10,'actor'=>400,'module'=>50,'result'=>20,'risk'=>10];
    foreach ($input as $key=>$value) {
        if (!isset($lengths[$key]) || !is_string($value) || strlen($value)>$lengths[$key] || !mb_check_encoding($value,'UTF-8') || preg_match('/[\x00-\x1f\x7f]/',$value)) return false;
    }
    if (isset($input['actor']) && mb_strlen(trim($input['actor']),'UTF-8')>100) return false;
    foreach (['start_date','end_date'] as $key) {
        if (isset($input[$key]) && trim($input[$key])!=='') {
            $value=trim($input[$key]);
            $date=DateTimeImmutable::createFromFormat('!Y-m-d',$value,new DateTimeZone('Asia/Kuala_Lumpur'));
            if (!$date || $date->format('Y-m-d')!==$value || $date->format('Y')<'1000') return false;
        }
    }
    return empty(trim($input['start_date'] ?? '')) || empty(trim($input['end_date'] ?? '')) || trim($input['start_date'])<=trim($input['end_date']);
}
