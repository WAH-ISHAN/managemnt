<?php
declare(strict_types=1);
function e(mixed $value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function money(mixed $value): string { return 'Rs. ' . number_format((float)$value,2); }
function csrf_field(): string { return '<input type="hidden" name="_csrf" value="' . e($_SESSION['csrf']) . '">'; }
function redirect(string $path, string $message = ''): string {
    if ($message !== '') $_SESSION['flash'] = $message;
    if (($_SERVER['HTTP_HX_REQUEST'] ?? '') === 'true') header('HX-Redirect: ' . $path);
    else { header('Location: ' . $path, true, 303); }
    return '';
}
function text_input(string $key, int $max = 200, bool $required = true): string {
    $value = $_POST[$key] ?? '';
    if (!is_string($value) || str_contains($value,"\0")) throw new InvalidArgumentException('Please enter a valid ' . str_replace('_',' ',$key) . '.');
    $value = trim($value);
    if (($required && $value === '') || mb_strlen($value) > $max) throw new InvalidArgumentException('Please enter a valid ' . str_replace('_',' ',$key) . '.');
    return $value;
}
function valid_date(string $date): string {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$date)) throw new InvalidArgumentException('Choose a valid date.');
    $d = DateTimeImmutable::createFromFormat('!Y-m-d',$date);
    if (!$d || $d->format('Y-m-d') !== $date) throw new InvalidArgumentException('Choose a valid date.'); return $date;
}
function cents(mixed $value, bool $zero = false): int {
    if (!is_scalar($value) || !preg_match('/^\d{1,10}(?:\.\d{1,2})?$/',(string)$value)) throw new InvalidArgumentException('Enter an amount with up to two decimal places.');
    $parts = explode('.',(string)$value); $n = (int)$parts[0] * 100 + (int)str_pad($parts[1] ?? '',2,'0');
    if (!$zero && $n <= 0) throw new InvalidArgumentException('Amount must be greater than zero.'); return $n;
}
function record_id(mixed $id): int {
    if (!is_scalar($id) || !ctype_digit((string)$id) || (int)$id < 1) throw new InvalidArgumentException('Invalid record.'); return (int)$id;
}
function flash(): string { $value = $_SESSION['flash'] ?? ''; unset($_SESSION['flash']); return $value; }
function optional_date(mixed $value): ?string {
    if ($value === '' || $value === null) return null;
    if (!is_string($value)) throw new InvalidArgumentException('Choose a valid date.');
    return valid_date($value);
}
function int_range(mixed $value,int $min,int $max): int {
    $n=filter_var($value,FILTER_VALIDATE_INT); if ($n===false || $n<$min || $n>$max) throw new InvalidArgumentException('Enter a whole number between '.$min.' and '.$max.'.'); return $n;
}
function optional_id(mixed $value): ?int { return $value === '' || $value === '0' || $value === null ? null : record_id($value); }
function valid_month(string $month): string { valid_date($month.'-01'); return $month; }
function percent_value(mixed $value, bool $optional = false): ?float {
    if ($optional && ($value === '' || $value === null)) return null;
    $n=cents($value,true); if ($n>10000) throw new InvalidArgumentException('Enter a percentage between 0 and 100.'); return $n/100;
}
function chart(string $type,array $labels,array $datasets,array $options=[]): string {
    if (!$labels || !array_filter($datasets,fn($d)=>!empty($d['data']))) return '<div class="empty chart-empty"><span aria-hidden="true">'.icon('trend').'</span><h3>No data yet</h3><p>Your chart appears when you add your own records.</p></div>';
    $data=['type'=>$type,'data'=>['labels'=>$labels,'datasets'=>$datasets],'options'=>array_replace_recursive(['responsive'=>true,'maintainAspectRatio'=>false,'animation'=>['duration'=>720,'easing'=>'easeOutQuart'],'plugins'=>['legend'=>['position'=>'bottom']]],$options)];
    return '<div class="chart-wrap"><canvas data-chart="'.e(json_encode($data,JSON_THROW_ON_ERROR)).'" role="img" aria-label="'.e(implode(', ',array_column($datasets,'label'))).' chart"></canvas></div>';
}
/** A raised doughnut with an exact, accessible percentage and a no-JS fallback. */
function progress_donut(float $percentage,string $label,string $detail='',bool $compact=false,string $tone='blue',string $filledLabel='Complete'): string {
    static $sequence=0;
    $value=is_finite($percentage) ? max(0,min(100,$percentage)) : 0;
    $display=rtrim(rtrim(number_format($value,1,'.',''),'0'),'.');
    $remaining=rtrim(rtrim(number_format(100-$value,1,'.',''),'0'),'.');
    $dash=number_format($value,3,'.','').' '.number_format(100-$value,3,'.','');
    $id='lifeos-progress-'.(++$sequence);
    $tone=in_array($tone,['blue','green','violet','amber'],true) ? $tone : 'blue';
    $description=$label.': '.$display.'% '.$filledLabel.', '.$remaining.'% remaining'.($detail!=='' ? '. '.$detail : '');
    return '<figure class="progress-donut progress-donut--'.$tone.($compact ? ' progress-donut--compact' : '').'" data-progress-donut data-progress-value="'.e($display).'">'
        .'<div class="progress-donut-stage" role="img" aria-label="'.e($description).'"><svg viewBox="0 0 160 168" aria-hidden="true" focusable="false">'
        .'<defs><linearGradient id="'.$id.'-fill" x1="0" y1="0" x2="1" y2="1"><stop class="progress-stop-start" offset="0"/><stop class="progress-stop-end" offset="1"/></linearGradient><linearGradient id="'.$id.'-gloss" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="white" stop-opacity=".6"/><stop offset="1" stop-color="white" stop-opacity="0"/></linearGradient></defs>'
        .'<ellipse class="progress-ring-shadow" cx="80" cy="145" rx="50" ry="7"/>'
        .'<circle class="progress-ring-depth" cx="80" cy="83" r="56"/>'
        .'<circle class="progress-ring-arc progress-ring-arc-depth" cx="80" cy="83" r="56" pathLength="100" stroke-dasharray="'.$dash.'" transform="rotate(-90 80 83)"/>'
        .'<circle class="progress-ring-track" cx="80" cy="77" r="56"/>'
        .'<circle class="progress-ring-arc progress-ring-arc-face" cx="80" cy="77" r="56" pathLength="100" stroke="url(#'.$id.'-fill)" stroke-dasharray="'.$dash.'" transform="rotate(-90 80 77)"/>'
        .'<circle class="progress-ring-arc progress-ring-gloss" cx="80" cy="76" r="54" pathLength="100" stroke="url(#'.$id.'-gloss)" stroke-dasharray="'.$dash.'" transform="rotate(-90 80 76)"/>'
        .'</svg><div class="progress-donut-value" aria-hidden="true"><strong>'.e($display).'<span>%</span></strong><small>'.e($filledLabel).'</small></div></div>'
        .'<figcaption><strong>'.e($label).'</strong>'.($detail!=='' ? '<p>'.e($detail).'</p>' : '').'<div class="progress-donut-legend"><span><i aria-hidden="true"></i>'.e($filledLabel).' <b>'.e($display).'%</b></span><span><i aria-hidden="true"></i>Remaining <b>'.e($remaining).'%</b></span></div></figcaption></figure>';
}
function icon(string $name): string {
    $paths = match ($name) {
        'arrow-right' => '<path d="M4 12h16m-6-6 6 6-6 6"/>',
        'check' => '<path d="m5 12 4 4L19 6"/>',
        'search' => '<circle cx="10.5" cy="10.5" r="6.5"/><path d="m16 16 5 5"/>',
        'grid' => '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>',
        'wallet' => '<path d="M20 8V6a2 2 0 0 0-2-2H6a3 3 0 0 0 0 6h14v10H6a3 3 0 0 1-3-3V7"/><path d="M20 12h-5v4h5"/>',
        'trend' => '<path d="m3 17 6-6 4 4 8-10M15 5h6v6"/>',
        'book' => '<path d="M12 5C8 2 3 4 3 4v16s5-2 9 1c4-3 9-1 9-1V4s-5-2-9 1Zm0 0v16"/>',
        'leaf' => '<path d="M20 3C8 1 2 8 5 15s15 4 15-12ZM4 21 15 10"/>',
        'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'calendar' => '<rect x="3" y="5" width="18" height="16" rx="3"/><path d="M7 3v4m10-4v4M3 11h18m-12 4h.01m4 0h.01"/>',
        'target' => '<circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="5"/><circle cx="12" cy="12" r="1"/>',
        'briefcase' => '<rect x="3" y="7" width="18" height="14" rx="3"/><path d="M8 7V4h8v3M3 12c6 3 12 3 18 0m-9 0v4"/>',
        'folder' => '<path d="M3 6a2 2 0 0 1 2-2h5l2 3h7a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2Z"/>',
        'insights' => '<path d="M4 20V10m8 10V4m8 16v-7M2 20h20"/>',
        'bell' => '<path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M9 21h6"/>',
        'settings' => '<path d="m9 3-1 3-3 1-2 5 2 5 3 1 1 3h6l1-3 3-1 2-5-2-5-3-1-1-3Z"/><circle cx="12" cy="12" r="3"/>',
        default => '<path d="m12 3 9 9-9 9-9-9Z"/>',
    };
    return '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'.$paths.'</svg>';
}
