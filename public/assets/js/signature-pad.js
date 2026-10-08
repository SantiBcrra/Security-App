/*
 * Firma en pantalla con dedo o mouse (sin librerías). Uso:
 *   <div data-signature>
 *     <canvas></canvas>
 *     <input type="hidden" name="signature">
 *     <button type="button" data-clear>Borrar</button>
 *   </div>
 * Al terminar cada trazo, el input recibe la firma como "data:image/png;base64,…" (fondo transparente).
 * El canvas toma su tamaño la primera vez que se dibuja (sirve dentro de bloques que empiezan ocultos).
 */
(function () {
    function init(box) {
        if (box.dataset.signatureReady) return;
        box.dataset.signatureReady = '1';
        var canvas = box.querySelector('canvas');
        var input = box.querySelector('input[type=hidden]');
        var ctx = canvas.getContext('2d');
        var drawing = false;
        var dirty = false;
        var last = null;

        function size() {
            var ratio = window.devicePixelRatio || 1;
            var w = canvas.clientWidth;
            var h = canvas.clientHeight;
            if (!w || !h || (canvas.width === Math.round(w * ratio) && canvas.height === Math.round(h * ratio))) return;
            canvas.width = Math.round(w * ratio);
            canvas.height = Math.round(h * ratio);
            ctx.setTransform(ratio, 0, 0, ratio, 0, 0);
            ctx.lineWidth = 2.2;
            ctx.lineCap = 'round';
            ctx.lineJoin = 'round';
            ctx.strokeStyle = '#111827';
            dirty = false;
            input.value = '';
        }
        function point(e) {
            var r = canvas.getBoundingClientRect();
            return { x: e.clientX - r.left, y: e.clientY - r.top };
        }
        canvas.addEventListener('pointerdown', function (e) {
            if (!dirty) size();
            drawing = true;
            last = point(e);
            canvas.setPointerCapture(e.pointerId);
            ctx.beginPath();
            ctx.arc(last.x, last.y, 1, 0, Math.PI * 2);
            ctx.fillStyle = '#111827';
            ctx.fill();
            e.preventDefault();
        });
        canvas.addEventListener('pointermove', function (e) {
            if (!drawing) return;
            var p = point(e);
            ctx.beginPath();
            ctx.moveTo(last.x, last.y);
            ctx.lineTo(p.x, p.y);
            ctx.stroke();
            last = p;
            dirty = true;
            e.preventDefault();
        });
        function end() {
            if (!drawing) return;
            drawing = false;
            if (dirty) input.value = canvas.toDataURL('image/png');
        }
        canvas.addEventListener('pointerup', end);
        canvas.addEventListener('pointercancel', end);
        canvas.style.touchAction = 'none';
        var clear = box.querySelector('[data-clear]');
        if (clear) clear.addEventListener('click', function () {
            ctx.clearRect(0, 0, canvas.width, canvas.height);
            dirty = false;
            input.value = '';
        });
    }
    function initAll(root) {
        (root || document).querySelectorAll('[data-signature]').forEach(init);
    }
    window.SignaturePad = { init: initAll };
    if (document.readyState !== 'loading') initAll();
    else document.addEventListener('DOMContentLoaded', function () { initAll(); });
    // Firmas que aparecen después (Alpine x-if, listas dinámicas)
    new MutationObserver(function () { initAll(); }).observe(document.documentElement, { childList: true, subtree: true });
})();
