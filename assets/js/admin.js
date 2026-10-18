(function () {
    'use strict';
    var form = document.getElementById('gl-ac-bulk-form');
    if (!form) return;
    var all = document.getElementById('gl-ac-select-all');
    var button = document.getElementById('gl-ac-delete-selected');
    var boxes = Array.from(form.querySelectorAll('input[name="cart_ids[]"]'));
    function update() {
        var count = boxes.filter(function (box) { return box.checked; }).length;
        all.checked = boxes.length > 0 && count === boxes.length;
        all.indeterminate = count > 0 && count < boxes.length;
        all.disabled = boxes.length === 0;
        button.disabled = count === 0;
    }
    all.addEventListener('change', function () {
        boxes.forEach(function (box) { box.checked = all.checked; });
        update();
    });
    boxes.forEach(function (box) { box.addEventListener('change', update); });
    form.addEventListener('submit', function (event) {
        var count = boxes.filter(function (box) { return box.checked; }).length;
        if (!count || !window.confirm('Удалить выбранные корзины (' + count + ')? Это действие нельзя отменить.')) {
            event.preventDefault();
        }
    });
    update();
})();
