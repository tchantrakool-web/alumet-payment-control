</main>

<script>
// Initialize all DataTables
document.addEventListener('DOMContentLoaded', function() {
    // A table whose only <tbody> row is a colspan "no results" placeholder has
    // just 1 actual <td> against N <thead> columns, which DataTables reports as
    // an "Incorrect column count" warning. Its default errMode shows that as a
    // blocking native alert() on every empty list page, which freezes the page
    // until dismissed. Log warnings instead of alerting on them.
    $.fn.dataTable.ext.errMode = 'none';
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
