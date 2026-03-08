<div class="card shadow-sm p-3 border-0 mb-4">
    <h5 class="fw-bold mb-3">สถิติผู้เข้าชมย้อนหลังทั้งหมด (Historical Overview)</h5>
    <div style="height: 300px;">
        <canvas id="historicalChart"></canvas>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const hCtx = document.getElementById('historicalChart').getContext('2d');
    new Chart(hCtx, {
        type: 'line',
        data: {
            labels: <?= json_encode($dates) ?>,
            datasets: [{
                label: 'จำนวนผู้เข้าชมจริง',
                data: <?= json_encode($visitors) ?>,
                borderColor: '#2c3e50',
                backgroundColor: 'rgba(44, 62, 80, 0.05)',
                fill: true,
                borderWidth: 2,
                pointRadius: 1,
                tension: 0.1
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: { x: { display: false }, y: { beginAtZero: false } }
        }
    });
});
</script>