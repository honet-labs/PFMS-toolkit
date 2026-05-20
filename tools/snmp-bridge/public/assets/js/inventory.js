document.addEventListener('DOMContentLoaded', () => {
    const table = document.querySelector('#inventoryTable');

    if (table && window.DataTable) {
        new DataTable(table, {
            pageLength: 25,
            order: [[1, 'asc'], [2, 'asc']],
            columnDefs: [{ orderable: false, targets: 0 }]
        });
    }

    document.querySelector('#selectAll')?.addEventListener('click', () => {
        document.querySelectorAll('.sensor-check:not(:disabled)').forEach((input) => {
            input.checked = true;
        });
    });

    document.querySelector('#unselectAll')?.addEventListener('click', () => {
        document.querySelectorAll('.sensor-check').forEach((input) => {
            input.checked = false;
        });
    });
});
