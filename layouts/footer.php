</main>

<script>
// Initialize all DataTables
document.addEventListener('DOMContentLoaded', function() {
    // A colspan "no results" placeholder has one actual cell against many
    // header columns. DataTables cannot initialize that shape: suppressing its
    // alert still leaves an uncaught _DT_CellIndex error. Keep the server-
    // rendered empty state intact and skip enhancement for such tables.
    $.fn.dataTable.ext.errMode = 'none';
    document.querySelectorAll('.datatable').forEach(function(el) {
        const headerCells = el.querySelectorAll('thead tr:last-child th').length;
        const hasUnevenBodyRow = Array.from(el.querySelectorAll('tbody tr'))
            .some(function(row) { return row.cells.length !== headerCells; });
        if (hasUnevenBodyRow) return;

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
