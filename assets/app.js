(function () {
  'use strict';

  // Confirmación antes de acciones delicadas
  document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-confirmar]');
    if (b && !window.confirm(b.getAttribute('data-confirmar'))) e.preventDefault();
  });

  // Evitar doble envío de formularios normales (la práctica maneja los suyos)
  document.addEventListener('submit', function (e) {
    var f = e.target;
    if (f.id === 'form-pregunta' || e.defaultPrevented) return;
    if (f.dataset.enviado) { e.preventDefault(); return; }
    f.dataset.enviado = '1';
    setTimeout(function () { delete f.dataset.enviado; }, 4000);
  });

  document.addEventListener('DOMContentLoaded', function () {
    // Zona para arrastrar el archivo GIFT (admin)
    document.querySelectorAll('[data-soltar]').forEach(function (zona) {
      var input = zona.querySelector('input[type=file]');
      var txt = zona.querySelector('span');
      ['dragenter', 'dragover'].forEach(function (ev) {
        zona.addEventListener(ev, function (e) { e.preventDefault(); zona.classList.add('encima'); });
      });
      ['dragleave', 'drop'].forEach(function (ev) {
        zona.addEventListener(ev, function () { zona.classList.remove('encima'); });
      });
      zona.addEventListener('drop', function (e) {
        e.preventDefault();
        if (e.dataTransfer.files.length) { input.files = e.dataTransfer.files; input.dispatchEvent(new Event('change')); }
      });
      input.addEventListener('change', function () {
        if (input.files.length) txt.textContent = 'Archivo: ' + input.files[0].name;
      });
    });

    // Mostrar "tiempo límite" solo en modo examen
    var formEmpezar = document.getElementById('form-empezar');
    if (formEmpezar) {
      var soloExamen = formEmpezar.querySelectorAll('[data-solo-examen]');
      var actualizar = function () {
        var examen = formEmpezar.querySelector('input[name=modo]:checked').value === 'examen';
        soloExamen.forEach(function (el) { el.hidden = !examen; });
      };
      formEmpezar.addEventListener('change', actualizar);
      actualizar();
    }
  });
})();
