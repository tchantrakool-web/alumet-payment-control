</main>

<script>
// Initialize all DataTables
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.datatable').forEach(function(el) {
        $(el).DataTable({
            searching: el.dataset.searching !== 'false',
            language: {
                search: 'ค้นหา:',
                lengthMenu: 'แสดง _MENU_ รายการ',
                info: 'แสดง _START_ ถึง _END_ จาก _TOTAL_ รายการ',
                infoEmpty: 'ไม่พบข้อมูล',
                zeroRecords: 'ไม่พบข้อมูล',
                paginate: { first: 'แรก', last: 'สุดท้าย', next: 'ถัดไป', previous: 'ก่อนหน้า' }
            },
            pageLength: 25,
        });
    });
});
</script>
</body>
</html>
