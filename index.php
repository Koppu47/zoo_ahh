<?php
// 1. อ่านไฟล์ข้อมูลรายวัน (Historical Trend)
// --- 1. ส่วนการอ่านและรวมข้อมูลทุกกลุ่มรายวัน ---
$jsonData = file_get_contents('data.JSON'); 
$result = json_decode($jsonData, true);

if (isset($result['category'])) {
    $dailyTotals = [];
    // วนลูปทุกกลุ่ม (165, 166, 167, ...) เพื่อรวมยอด y ตามวันที่ x
    foreach ($result['category'] as $id => $entries) {
        foreach ($entries as $entry) {
            $date = $entry['x'];
            $val = $entry['y'];
            if (!isset($dailyTotals[$date])) {
                $dailyTotals[$date] = 0;
            }
            $dailyTotals[$date] += $val;
        }
    }
    // เรียงวันที่ให้ถูกต้อง
    ksort($dailyTotals);

    // ส่งค่าไปให้ตัวแปรเดิมของระบบพยากรณ์
    $visitors = array_values($dailyTotals); // ดึงค่า y รวม
    $dates = array_keys($dailyTotals);     // ดึงค่า x (วันที่)
} else {
    die("Error: ไม่พบข้อมูล category ใน data.JSON");
}

// 2. อ่านไฟล์ข้อมูลรายกลุ่ม (Customer Segments)
$jsonGroup = file_get_contents('group.JSON'); 
$groupResult = json_decode($jsonGroup, true); 

$groupData = isset($groupResult['sumcat']) ? $groupResult['sumcat'] : [];
$totalInGroup = isset($groupResult['sum']) ? $groupResult['sum'] : array_sum($groupData);

$categoryNames = [
    "165" => "เด็ก", "166" => "ผู้ใหญ่", "167" => "นักศึกษา/ครู/ทหาร/ตำรวจ",
    "168" => "ผู้ใหญ่ (ต่างชาติ)", "169" => "ยกเว้น", "170" => "โครงการทัวร์สวนสัตว์",
    "171" => "เด็ก (ต่างชาติ)", "172" => "ผู้เข้าชมสัตว์กลางคืน", "180" => "โครงการให้บริการความรู้"
];



// --- ส่วนรับค่าจากฟอร์ม ---
$method = isset($_GET['method']) ? $_GET['method'] : 'WMA';
$n_days = isset($_GET['n_days']) ? (int)$_GET['n_days'] : 3; // เพิ่มตัวแปร n_days
$forecastDays = isset($_GET['f_days']) ? (int)$_GET['f_days'] : 7;
$w1 = isset($_GET['w1']) ? (float)$_GET['w1'] : 0.5;
$w2 = isset($_GET['w2']) ? (float)$_GET['w2'] : 0.3;
$w3 = isset($_GET['w3']) ? (float)$_GET['w3'] : 0.2;
$alpha = isset($_GET['alpha']) ? (float)$_GET['alpha'] : 0.5;

// --- ฟังก์ชันพยากรณ์แบบต่างๆ ---

// 1. Simple Average (SA)
function getSA($data) {
    $count = count($data);
    if ($count == 0) return 0;
    return array_sum($data) / $count;
}

// 2. Moving Average (MA)
function getMA($data, $n = 3) { // กำหนดค่าเริ่มต้นเป็น 3 ป้องกัน Fatal Error
    if (empty($data)) return 0;
    
    // ตรวจสอบว่าถ้าข้อมูลมีน้อยกว่าค่า n ที่ User ต้องการ ให้ใช้ข้อมูลเท่าที่มี
    $actual_n = min(count($data), $n); 
    if ($actual_n <= 0) return 0;

    $last_n = array_slice($data, -$actual_n);
    return array_sum($last_n) / count($last_n);
}

// 3. Weighted Moving Average (WMA)
function getWMA($data, $w1, $w2, $w3) {
$n = count($data);
    if ($n < 3) return 0;
    
    $total_w = $w1 + $w2 + $w3;
    if ($total_w == 0) return 0; // ป้องกันการหารด้วยศูนย์
    
    $sum = ($data[$n-1] * $w1) + ($data[$n-2] * $w2) + ($data[$n-3] * $w3);
    return $sum / $total_w; // หารเพื่อ Normalize ให้กลับมาเป็นค่าเฉลี่ยที่ถูกต้อง
}
    

// 4. Exponential Smoothing (ES)
function getES($data, $alpha) {
    $forecast = $data[0];
    foreach ($data as $actual) {
        $forecast = ($alpha * $actual) + ((1 - $alpha) * $forecast);
    }
    return $forecast;
}

// ฟังก์ชันพยากรณ์ล่วงหน้าแบบยืดหยุ่น (Recursive)
function forecastFuture($data, $method, $days, $w1, $w2, $w3, $alpha, $n_days) {
    $series = $data;
    $results = [];
// คำนวณค่าพยากรณ์ "จุดแรก" ก่อน
    $firstVal = 0;
    switch($method) {
        case 'SA': $firstVal = getSA($series); break;
        case 'MA': $firstVal = getMA($series, $n_days); break;
        case 'ES': $firstVal = getES($series, $alpha); break;
        default:   $firstVal = getWMA($series, $w1, $w2, $w3); break;
    }

    // สำหรับ SA และ ES ค่าพยากรณ์จะเป็นค่าคงที่ (Static)
    // ใช้ลูปเติมค่าเดิมได้เลย ไม่ต้องคำนวณใหม่
    if ($method == 'SA' || $method == 'ES') {
        $val = round($firstVal);
        for ($i = 0; $i < $days; $i++) {
            $results[] = $val;
        }
    } else {
        // สำหรับ MA และ WMA ค่าจะเปลี่ยนไปตามหน้าต่างข้อมูล (Moving Window) 
        // จึงยังต้องใช้ Recursive ลูปเดิมที่คุณเขียนไว้ (ซึ่งถูกต้องแล้ว)
        for ($i = 0; $i < $days; $i++) {
            $val = 0;
            switch($method) {
                case 'MA': $val = getMA($series, $n_days); break;
                default:   $val = getWMA($series, $w1, $w2, $w3); break;
            }
            $results[] = round($val);
            $series[] = $val; // เติมค่าที่พยากรณ์ได้กลับเข้าไปเพื่อใช้หาจุดถัดไป
        }
    }
    return $results;
}

// --- คำนวณ Error (MAD: Mean Absolute Deviation) ---
function calculateMAD($data, $method, $w1, $w2, $w3, $alpha, $n_days) {
    $errors = [];
    $n = count($data);
    // ตรวจสอบย้อนหลัง 5 จุดล่าสุดเพื่อหาความแม่นยำ
    for ($i = $n - 5; $i < $n; $i++) {
        if ($i < 3) continue;
        $actual = $data[$i];
        $history = array_slice($data, 0, $i);
        $pred = 0;
        switch($method) {
            case 'SA': $pred = getSA($history); break;
            case 'MA': $pred = getMA($history, $n_days); break;
            case 'ES': $pred = getES($history, $alpha); break;
            default:   $pred = getWMA($history, $w1, $w2, $w3); break;
        }
        $errors[] = abs($actual - $pred);
    }
    return count($errors) > 0 ? array_sum($errors) / count($errors) : 0;
}

// คำนวณผลลัพธ์หลัก
$madValue = calculateMAD($visitors, $method, $w1, $w2, $w3, $alpha, $n_days);
$nextForecasts = forecastFuture($visitors, $method, $forecastDays, $w1, $w2, $w3, $alpha, $n_days);
$tomorrowForecast = $nextForecasts[0];

// --- เตรียมข้อมูลสำหรับกราฟ ---
$displayLimit = 15; // แสดงย้อนหลัง 15 วันเพื่อให้เห็นภาพรวม
$limitedVisitors = array_slice($visitors, -$displayLimit);
$limitedDates = array_slice($dates, -$displayLimit);

$lastDate = end($limitedDates);
$futureDates = [];
for ($i = 1; $i <= $forecastDays; $i++) { 
    $futureDates[] = date('Y-m-d', strtotime($lastDate . " + $i days")); 
}

$allLabels = array_merge($limitedDates, $futureDates);
$actualDataChart = array_merge($limitedVisitors, array_fill(0, $forecastDays, null));
$forecastDataChart = array_merge(array_fill(0, count($limitedVisitors) - 1, null), [end($limitedVisitors)], $nextForecasts);
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>Zoo Strategic Forecast Dashboard</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Sarabun', sans-serif; background: #f4f7f6; }
        .dashboard-container { max-width: 1200px; margin: 30px auto; background: white; padding: 30px; border-radius: 15px; box-shadow: 0 4px 20px rgba(0,0,0,0.08); }
        .card-stat { border: none; border-radius: 10px; color: white; padding: 20px; }
        .method-selector { background: #e9ecef; border-radius: 10px; padding: 20px; margin-bottom: 25px; }
        .table-custom { font-size: 0.9rem; }
    </style>
</head>
<body>

<div class="dashboard-container shadow">
    <h2 class="text-center mb-4 fw-bold text-dark">Zoo Analytics & Multi-Model Forecasting</h2>

    <div class="method-selector">
        <form method="GET" class="row g-3 align-items-end">
            <div class="col-md-3">
                <label class="form-label fw-bold">เลือกวิธีพยากรณ์</label>
                <select name="method" class="form-select" onchange="this.form.submit()">
                    <option value="SA" <?= $method=='SA'?'selected':'' ?>>Simple Average</option>
                    <option value="MA" <?= $method=='MA'?'selected':'' ?>>Moving Average</option>
                    <option value="WMA" <?= $method=='WMA'?'selected':'' ?>>WMA (Weighted)</option>
                    <option value="ES" <?= $method=='ES'?'selected':'' ?>>Exponential Smoothing</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label fw-bold">ระยะเวลา (วัน)</label>
                <select name="f_days" class="form-select" onchange="this.form.submit()">
                    <?php foreach([1, 7, 15, 30] as $d): ?>
                        <option value="<?= $d ?>" <?= $forecastDays == $d ? 'selected' : '' ?>><?= $d ?> วัน</option>
                    <?php endforeach; ?>
                </select>
            </div>

            <?php if($method == 'MA'): ?>
                <div class="col-md-2">
                    <label class="form-label fw-bold" onchange="this.form.submit()">ค่าเฉลี่ย (n วัน)</label>
                    <input type="number" name="n_days" min="1" max="30" value="<?= $n_days ?>" class="form-control">
                </div>
            <?php endif; ?>

            <?php if($method == 'WMA'): ?>
                <div class="col-md-1">W1: <input type="number" name="w1" step="0.1" value="<?= $w1 ?>" class="form-control"></div>
                <div class="col-md-1">W2: <input type="number" name="w2" step="0.1" value="<?= $w2 ?>" class="form-control"></div>
                <div class="col-md-1">W3: <input type="number" name="w3" step="0.1" value="<?= $w3 ?>" class="form-control"></div>
            <?php endif; ?>

            <?php if($method == 'ES'): ?>
                <div class="col-md-2">Alpha: <input type="number" name="alpha" step="0.1" min="0" max="1" value="<?= $alpha ?>" class="form-control"></div>
            <?php endif; ?>

            <div class="col-md-2">
                <button type="submit" class="btn btn-primary w-100">อัปเดตโมเดล</button>
            </div>
                <?php 
                    $total_w = $w1 + $w2 + $w3;
                    if(abs($total_w - 1.0) > 0.0001): ?>
                    <p style="color: red; text-align: left; margin-top: 5px; font-size: 0.8em;">* คำเตือน: ผลรวมน้ำหนักควรเท่ากับ 1.0 (ปัจจุบันคือ <?= $total_w ?>)</p>
                <?php endif; ?>
        </form>
    </div>

    <div class="row g-3 mb-4 text-center">
        <div class="col-md-4">
            <div class="card card-stat bg-secondary">
                <h6>ความแม่นยำ (MAD)</h6>
                <h3 class="fw-bold"><?= number_format($madValue, 2) ?></h3>
                <small>ยิ่งน้อยยิ่งแม่นยำ</small>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card card-stat bg-success">
                <h6>พยากรณ์วันพรุ่งนี้ (<?= $method ?>)</h6>
                <h3 class="fw-bold"><?= number_format($tomorrowForecast, 0) ?> คน</h3>
                <small>คาดการณ์รายกลุ่มด้านขวา</small>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card card-stat bg-info">
                <h6>Simple Average (ภาพรวม)</h6>
                <h3 class="fw-bold"><?= number_format(getSA($visitors), 0) ?> คน</h3>
                <small>ค่าเฉลี่ยประวัติศาสตร์ทั้งหมด</small>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-lg-8 mb-4">
            <div class="card shadow-sm p-3 border-0 h-100">
                <h5 class="fw-bold mb-3">กราฟแนวโน้มและการพยากรณ์ (<?= $method ?>)</h5>
                <canvas id="visitorChart"></canvas>
            </div>
        </div>

        <div class="col-lg-4 mb-4">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-header bg-dark text-white fw-bold">สัดส่วนพยากรณ์รายกลุ่ม (วันถัดไป)</div>
                <div class="card-body p-0">
                    <table class="table table-hover table-custom mb-0">
                        <thead class="table-light">
                            <tr><th>ประเภท</th><th>สัดส่วน</th><th>จำนวน</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach($groupData as $id => $count): 
                                if ($count <= 0) continue;
                                $typeName = isset($categoryNames[$id]) ? $categoryNames[$id] : "รหัส $id";
                                $ratio = ($totalInGroup > 0) ? ($count / $totalInGroup) : 0;
                                $predicted = $tomorrowForecast * $ratio;
                            ?>
                            <tr>
                                <td><?= $typeName ?></td>
                                <td><?= number_format($ratio * 100, 1) ?>%</td>
                                <td class="fw-bold text-primary"><?= number_format($predicted, 0) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot class="table-secondary fw-bold">
                            <tr><td colspan="2">ยอดพยากรณ์รวม</td><td><?= number_format($tomorrowForecast, 0) ?></td></tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>
    </div>
    <?php include 'overall_graph.php'; ?>
</div>

<script>
const ctx = document.getElementById('visitorChart').getContext('2d');
new Chart(ctx, {
    type: 'line',
    data: {
        labels: <?= json_encode($allLabels) ?>,
        datasets: [
            {
                label: 'ข้อมูลจริง (Actual)',
                data: <?= json_encode($actualDataChart) ?>,
                borderColor: '#2ecc71',
                backgroundColor: 'rgba(46, 204, 113, 0.1)',
                fill: true, tension: 0.3, pointRadius: 5
            },
            {
                label: 'พยากรณ์ล่วงหน้า (<?= $method ?>)',
                data: <?= json_encode($forecastDataChart) ?>,
                borderColor: '#e67e22',
                borderDash: [6, 4],
                backgroundColor: 'transparent',
                pointBackgroundColor: '#e67e22',
                fill: false, tension: 0.3, pointRadius: 5
            }
        ]
    },
    options: {
        responsive: true,
        plugins: {
            legend: { position: 'top' },
            tooltip: { mode: 'index', intersect: false }
        },
        scales: {
            y: { 
                beginAtZero: false,
                title: { display: true, text: 'จำนวนคน' }
            },
            x: { title: { display: true, text: 'วันที่' } }
        }
    }
});
</script>

</body>
</html>
