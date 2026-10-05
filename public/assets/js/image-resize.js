/*
 * Achica en el navegador las fotos grandes antes de subirlas (celulares sacan fotos de 4-12 MB).
 * Solo se tocan las que pesan más de 2,5 MB o miden más de 2400 px: las demás se suben tal cual,
 * con su metadata original. Las achicadas quedan en JPEG de 1600 px (la metadata EXIF se pierde;
 * la ubicación y la hora del hecho se registran aparte en el formulario).
 *
 * Uso: <input type="file" data-resize multiple accept="image/*">
 */
(function () {
    var MAX_SIDE = 1600, LIMIT_BYTES = 2.5 * 1024 * 1024, LIMIT_SIDE = 2400, QUALITY = 0.85;

    function loadImage(file) {
        return new Promise(function (resolve, reject) {
            var url = URL.createObjectURL(file);
            var img = new Image();
            img.onload = function () { resolve(img); };
            img.onerror = function () { URL.revokeObjectURL(url); reject(); };
            img.src = url;
        });
    }

    function shrink(file) {
        if (!/^image\/(jpeg|png|webp)$/.test(file.type)) return Promise.resolve(file);
        return loadImage(file).then(function (img) {
            var w = img.naturalWidth, h = img.naturalHeight;
            if (file.size <= LIMIT_BYTES && Math.max(w, h) <= LIMIT_SIDE) return file;
            var scale = Math.min(1, MAX_SIDE / Math.max(w, h));
            var canvas = document.createElement('canvas');
            canvas.width = Math.round(w * scale);
            canvas.height = Math.round(h * scale);
            canvas.getContext('2d').drawImage(img, 0, 0, canvas.width, canvas.height);
            return new Promise(function (resolve) {
                canvas.toBlob(function (blob) {
                    resolve(blob ? new File([blob], file.name.replace(/\.\w+$/, '') + '.jpg', { type: 'image/jpeg' }) : file);
                }, 'image/jpeg', QUALITY);
            });
        }).catch(function () { return file; });
    }

    document.addEventListener('change', function (ev) {
        var input = ev.target;
        if (!input.matches || !input.matches('input[type=file][data-resize]') || !window.DataTransfer) return;
        var files = Array.prototype.slice.call(input.files);
        if (!files.length) return;
        input.dispatchEvent(new CustomEvent('resize-start'));
        Promise.all(files.map(shrink)).then(function (out) {
            var dt = new DataTransfer();
            out.forEach(function (f) { dt.items.add(f); });
            input.files = dt.files;
            input.dispatchEvent(new CustomEvent('resize-done', { detail: out }));
        });
    });
})();
