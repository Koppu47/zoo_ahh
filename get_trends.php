<?php
// get_trends.php
$analysisDays = isset($_GET['a_days']) ? (int)$_GET['a_days'] : 7;

// อ่านข้อมูลพื้นฐาน
$jsonData = file_get_contents('data.JSON');
$result = json_decode($jsonData, true);
$jsonGroup = file_get_contents('group.JSON');
$groupResult = json_decode($jsonGroup, true);

$groupData = $groupResult['sumcat'] ?? [];
$totalInGroup = $groupResult['sum'] ?? 1;
$categoryNames = [
    "165" => "เด็ก", "166" => "ผู้ใหญ่", "167" => "นักศึกษา/ครู/ทหาร/ตำรวจ",
    "168" => "ผู้ใหญ่ (ต่างชาติ)", "169" => "ยกเว้น", "170" => "โครงการทัวร์สวนสัตว์",
    "171" => "เด็ก (ต่างชาติ)", "172" => "ผู้เข้าชมสัตว์กลางคืน", "180" => "โครงการให้บริการความรู้"
];

// 1. คำนวณยอดรวมล่าสุดแยกกลุ่ม
$recentTotal = 0;
$recentStats = [];
foreach ($result['category'] as $id => $entries) {
    $slice = array_slice($entries, -$analysisDays);
    $sum = array_sum(array_column($slice, 'y'));
    $recentStats[$id] = $sum;
    $recentTotal += $sum;
}

// 2. วิเคราะห์และสร้างคำแนะนำ
$adviceList = [];
$trendHtml = "";

foreach ($categoryNames as $id => $name) {
    if (isset($groupData[$id]) && $groupData[$id] > 0) {
        $histRatio = $groupData[$id] / $totalInGroup;
        $currRatio = ($recentTotal > 0) ? ($recentStats[$id] / $recentTotal) : 0;
        $diff = ($currRatio - $histRatio) * 100;

        // เก็บข้อมูลสำหรับแสดง Trend
        if (abs($diff) > 0.1) {
            $color = $diff > 0.5 ? 'text-success' : ($diff < -0.5 ? 'text-danger' : 'text-muted');
            $icon = $diff > 0.5 ? 'เพิ่มขึ้น' : ($diff < -0.5 ? 'ลดลง' : 'คงที่');
            $trendHtml .= "<div class='col-6 mb-2'>
                            <div class='p-2 border rounded shadow-sm bg-white text-center h-100'>
                                <small class='d-block text-secondary' style='font-size: 0.75rem;'>$name</small>
                                <span class='fw-bold $color' style='font-size: 0.9rem;'>$icon ".number_format(abs($diff), 1)."%</span>
                            </div>
                          </div>";
            
            // เพิ่ม Logic คำแนะนำกลยุทธ์
            if ($diff > 2.0) { // ถ้าเพิ่มขึ้นมากกว่า 2%
                if ($id == "165") $adviceList[] = "<b>กลุ่มเด็กเพิ่มขึ้น:</b> ควรเพิ่มเจ้าหน้าที่โซนกิจกรรมสวนสนุกและจุดจำหน่ายขนม";
                if ($id == "166") $adviceList[] = "<b>กลุ่มผู้ใหญ่เพิ่มขึ้น:</b> ควรเพิ่มเจ้าหน้าที่ใส่ชุดบิกินี่วัว แฮ่กๆ🥵";
                if ($id == "168") $adviceList[] = "<b>ต่างชาติเพิ่มขึ้น:</b> เตรียมคู่มือภาษาอังกฤษและเจ้าหน้าที่จุดประชาสัมพันธ์";
            } elseif ($diff < -2.0) { // ถ้าลดลงมากกว่า 2%
                if ($id == "166") $adviceList[] = "<b>กลุ่มผู้ใหญ่ลดลง:</b> แนะนำจัดโปรโมชั่น 'มา 4 จ่าย 3' หรือส่วนลดร้านอาหาร";
                if ($id == "172") $adviceList[] = "<b>Night Safari เงียบเหงา:</b> ตรวจสอบการประชาสัมพันธ์รอบค่ำหรือจัดกิจกรรมพิเศษ";
            }
        }
    }
}

// 3. แสดงผล Trend
echo $trendHtml;

// 4. แสดงผล Advice (ถ้ามี)
if (!empty($adviceList)) {
    echo "<div class='col-12 mt-3'>
            <div class='alert alert-info p-2 mb-0' style='font-size: 0.85rem;'>
                <h6 class='fw-bold mb-1'><i class='bi bi-lightbulb'></i> คำแนะนำเชิงกลยุทธ์:</h6>
                <ul class='mb-0 ps-3'>" . implode("", array_map(fn($a) => "<li>$a</li>", $adviceList)) . "</ul>
            </div>
          </div>";
}
?>