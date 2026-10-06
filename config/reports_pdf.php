<?php
require_once dirname(__DIR__) . '/vendor/tfpdf/font/unifont/ttfonts.php';
require_once dirname(__DIR__) . '/vendor/tfpdf/tfpdf.php';

class SecureposReportPdf extends tFPDF
{
    public $reportTitle = '';
    private $tableWidths = [];
    private $tableLabels = [];

    public function Header()
    {
        $this->SetFont('DejaVu', 'B', 17);
        $this->SetTextColor(8, 110, 126);
        $this->Cell(160, 9, 'SecurePOS', 0, 0);
        $this->SetFont('DejaVu', '', 9);
        $this->SetTextColor(75, 92, 111);
        $this->Cell(113, 9, 'Restoran Kencana Sari', 0, 1, 'R');
        $this->SetDrawColor(70, 185, 205);
        $this->Line(12, 23, 285, 23);
        $this->SetY(27);
        $this->SetFont('DejaVu', 'B', 13);
        $this->SetTextColor(24, 43, 67);
        $this->Cell(273, 8, $this->reportTitle, 0, 1);
        $this->Ln(3);
    }

    public function Footer()
    {
        $this->SetY(-12);
        $this->SetDrawColor(215, 225, 236);
        $this->Line(12, $this->GetY()-2, 285, $this->GetY()-2);
        $this->SetFont('DejaVu', '', 8);
        $this->SetTextColor(82, 100, 122);
        $this->Cell(180, 6, 'SecurePOS | Restoran Kencana Sari | Management report', 0, 0);
        $this->Cell(93, 6, 'Page ' . $this->PageNo() . ' of {nb}', 0, 0, 'R');
    }

    private function wrappedLines(string $text, float $width): array
    {
        $lines = [];
        foreach (explode("\n", str_replace("\r", '', $text)) as $paragraph) {
            $line = '';
            foreach (preg_split('//u', $paragraph, -1, PREG_SPLIT_NO_EMPTY) as $character) {
                if ($line !== '' && $this->GetStringWidth($line . $character) > $width-4) {
                    $break = mb_strrpos($line, ' ', 0, 'UTF-8');
                    if ($break !== false && $break > 0) {
                        $lines[] = mb_substr($line, 0, $break, 'UTF-8');
                        $line = ltrim(mb_substr($line, $break+1, null, 'UTF-8'));
                    } else { $lines[] = $line; $line = ''; }
                }
                $line .= $character;
            }
            $lines[] = $line;
        }
        return $lines ?: [''];
    }

    private function tableHeader(): void
    {
        $this->SetFont('DejaVu', 'B', 8);
        $lines = [];
        foreach ($this->tableLabels as $i => $label) $lines[] = $this->wrappedLines($label, $this->tableWidths[$i]);
        $this->paintRow($lines, max(array_map('count', $lines)), true, false);
    }

    private function paintRow(array $lines, int $count, bool $header, bool $stripe): void
    {
        $height = $count*4.5 + 4;
        $x = 12; $y = $this->GetY();
        $this->SetFillColor(...($header ? [222, 240, 245] : ($stripe ? [246, 249, 252] : [255, 255, 255])));
        $this->SetDrawColor(218, 228, 237);
        $this->SetTextColor(24, 43, 67);
        foreach ($lines as $i => $cell) {
            $w = $this->tableWidths[$i];
            $this->Rect($x, $y, $w, $height, 'DF');
            foreach ($cell as $j => $line) {
                $this->SetXY($x+2, $y+2+$j*4.5);
                $this->Cell($w-4, 4.5, $line, 0, 0);
            }
            $x += $w;
        }
        $this->SetXY(12, $y+$height);
    }

    public function reportTable(string $title, array $labels, array $widths, array $rows): void
    {
        if ($this->GetY()+30 > 192) $this->AddPage();
        $this->SetFont('DejaVu', 'B', 11);
        $this->SetTextColor(24, 43, 67);
        $this->Cell(273, 8, $title . ' (' . count($rows) . ' records)', 0, 1);
        $this->tableLabels = $labels; $this->tableWidths = $widths;
        $this->tableHeader();
        if (!$rows) {
            $this->SetFont('DejaVu', '', 9);
            $this->Cell(273, 10, 'No records found for the selected filters.', 0, 1);
        }
        foreach ($rows as $index => $row) {
            $this->SetFont('DejaVu', '', 8);
            $lines = [];
            foreach ($row as $i => $cell) $lines[] = $this->wrappedLines((string)$cell, $widths[$i]);
            $count = max(array_map('count', $lines));
            if ($this->GetY()+$count*4.5+4 > 192) {
                $this->AddPage(); $this->tableHeader(); $this->SetFont('DejaVu', '', 8);
            }
            // Split exceptionally long records across pages without clipping or truncation.
            while ($count > 0) {
                $available = max(1, (int)floor((192-$this->GetY()-4)/4.5));
                $take = min($count, $available);
                $chunk = array_map(static function ($cell) use ($take) { return array_slice($cell, 0, $take); }, $lines);
                $this->paintRow($chunk, $take, false, $index%2 === 1);
                $lines = array_map(static function ($cell) use ($take) { return array_slice($cell, $take); }, $lines);
                $count -= $take;
                if ($count > 0) { $this->AddPage(); $this->tableHeader(); $this->SetFont('DejaVu', '', 8); }
            }
        }
        $this->Ln(7);
    }
}

function buildSecureposReportPdf(array $d, string $name): SecureposReportPdf
{
    $titles = ['sales'=>'Sales Report', 'inventory'=>'Inventory Report', 'expiry'=>'Product Expiry Report', 'attendance'=>'Employee Attendance Report'];
    $pdf = new SecureposReportPdf('L', 'mm', 'A4');
    $pdf->SetMargins(12, 12, 12);
    $pdf->SetAutoPageBreak(true, 18);
    $pdf->AddFont('DejaVu', '', 'DejaVuSans.ttf', true);
    $pdf->AddFont('DejaVu', 'B', 'DejaVuSans-Bold.ttf', true);
    $pdf->AliasNbPages();
    $pdf->reportTitle = $titles[$d['activeReport']];
    $pdf->SetTitle('SecurePOS - ' . $pdf->reportTitle, true);
    $pdf->SetAuthor($name, true);
    $pdf->AddPage();
    $pdf->SetFont('DejaVu', '', 9);
    $generated = new DateTimeImmutable('now', $d['timezone']);
    $pdf->MultiCell(273, 5, 'Generated: ' . $generated->format($d['activeReport'] === 'sales' ? 'd F Y, g:i A' : 'd M Y, h:i A') . ($d['activeReport'] === 'sales' ? '' : ' (Asia/Kuala_Lumpur)') . "\n" . 'Generated by: ' . $name);
    $filters = reportExportFilters($d);
    $labels = ['start_date'=>'Start date','end_date'=>'End date','search'=>'Product search','category'=>'Category','employee_search'=>'Employee search'];
    foreach ($filters as $key => $value) {
        if ($key === 'report') continue;
        $options = ['payment'=>'paymentOptions','stock_status'=>'inventoryStatusOptions','expiry_status'=>'expiryStatusOptions','role'=>'attendanceRoleOptions','attendance_status'=>'attendanceStatusOptions'];
        if (isset($options[$key])) $value = $d[$options[$key]][$value];
        $pdf->MultiCell(273, 5, ($labels[$key] ?? ucwords(str_replace('_',' ',$key))) . ': ' . ($value === '' ? 'All' : $value));
    }
    if ($d['activeReport'] === 'expiry') $pdf->MultiCell(273, 5, 'Expiry status calculated as of: ' . $d['today']->format('d M Y'));
    $pdf->Ln(4);
    $number = static function ($v) { return rtrim(rtrim(number_format((float)$v, 2, '.', ''), '0'), '.'); };
    $date = static function ($v, $format) use ($d) { return $v ? (new DateTimeImmutable($v, $d['timezone']))->format($format) : '-'; };
    $summary = []; $tables = [];
    switch ($d['activeReport']) {
        case 'sales':
            $s=$d['summary'];
            $summary=['Total Revenue'=>reportMoney($s['total_revenue']), 'Total Transactions'=>$s['total_transactions'], 'Average Transaction'=>reportMoney($s['average_transaction']), 'Cash Sales'=>reportMoney($s['cash_sales']), 'QR Sales'=>reportMoney($s['qr_sales'])];
            $rows=[];
            foreach ($d['transactions'] as $r) $rows[]=[$r['receipt_no'],$date($r['created_at'],'d M Y, h:i A'),$r['payment_method'],$r['order_type'] ?: '-',$r['table_number'] ?: '-',reportMoney($r['total_amount'])];
            $tables[]=['Sales Transactions',['Receipt No.','Date & Time','Payment Method','Order Type','Table','Total Amount'],[65,57,40,40,21,50],$rows];
            $rows=[];
            foreach ($d['topItems'] as $r) $rows[]=[$r['item_name'],$r['quantity_sold'],reportMoney($r['revenue'])];
            $tables[]=['Top Selling Items',['Item Name','Quantity Sold','Revenue'],[153,60,60],$rows];
            break;
        case 'inventory':
            $s=$d['inventorySummary'];
            $summary=['Total Products'=>$s['total_products'],'In Stock'=>$s['in_stock'],'Low Stock'=>$s['low_stock'],'Out of Stock'=>$s['out_of_stock']];
            $rows=[];
            foreach ($d['inventoryRows'] as $r) $rows[]=[$r['product_id'],$r['product_name'],$r['category'],$number($r['current_stock']),$r['unit'],$r['stock_status']];
            $tables[]=['Inventory Products',['Product ID','Product Name','Category','Current Stock','Unit','Stock Status'],[35,80,55,35,25,43],$rows];
            break;
        case 'expiry':
            $s=$d['expirySummary'];
            $summary=['Total Batches'=>$s['total_batches'],'Expired'=>$s['expired'],'Expiring Within 7 Days'=>$s['within_7'],'Expiring Within 30 Days'=>$s['within_30'],'Valid'=>$s['valid']];
            $rows=[];
            foreach ($d['expiryRows'] as $r) $rows[]=[$r['product_id'],$r['product_name'],$r['category'],$r['batch_no'],$number($r['quantity']),$r['unit'],$date($r['expiry_date'],'d M Y'),$d['expiryStatusOptions'][array_search($r['expiry_status'],$d['expiryStatusValues'],true)]];
            $tables[]=['Product Expiry Batches',['Product ID','Product Name','Category','Batch Number','Quantity','Unit','Expiry Date','Expiry Status'],[28,48,40,35,22,20,33,47],$rows];
            break;
        case 'attendance':
            $s=$d['attendanceSummary'];
            $summary=['Attendance Records'=>$s['attendance_records'],'Completed Shifts'=>$s['completed_shifts'],'Missing Check-Out'=>$s['missing_checkout'],'Present Employees'=>$s['present_employees']];
            if ($d['attendanceSingleDate']) $summary['Absent Employees']=$d['absentEmployees'];
            $rows=[];
            foreach ($d['monthlyAttendanceRows'] as $r) $rows[]=[$r['employee_code'],$r['full_name'],$date($r['attendance_month'].'-01','F Y'),$r['days_present'],$r['total_working_minutes']===null ? '-' : reportWorkingMinutes($r['total_working_minutes'])];
            $tables[]=['Monthly Attendance Summary',['Employee Code','Employee Name','Month','Days Present','Total Working Hours'],[42,90,55,35,51],$rows];
            $rows=[];
            foreach ($d['attendanceRows'] as $r) $rows[]=[$r['employee_code'],$r['full_name'],$r['role'],$date($r['attendance_date'],'d M Y'),$date($r['check_in'],'h:i A'),$date($r['check_out'],'h:i A'),$r['working_minutes']===null ? '-' : reportWorkingMinutes($r['working_minutes']),$r['status']];
            $tables[]=['Employee Attendance',['Employee Code','Employee Name','Role','Attendance Date','Check In','Check Out','Working Hours','Status'],[32,62,35,34,25,25,32,28],$rows];
            break;
    }
    $pdf->SetFont('DejaVu','B',10);
    $pdf->Cell(273,7,'Report Summary',0,1);
    $pdf->SetFont('DejaVu','',9);
    foreach ($summary as $label=>$value) $pdf->MultiCell(273,5,$label . ': ' . $value);
    $pdf->Ln(5);
    foreach ($tables as $table) $pdf->reportTable(...$table);
    if ($d['activeReport'] === 'attendance') {
        $pdf->SetFont('DejaVu','',8);
        $pdf->MultiCell(273,5,'Working-hour calculations include completed attendance records only.');
    }
    return $pdf;
}
