(function () {
    document.addEventListener('click', function (e) {
        var toggle = e.target.closest('.mlt-switcher-toggle');
        var open = document.querySelector('.mlt-switcher.is-open');
        if (toggle) {
            var widget = toggle.closest('.mlt-switcher');
            if (open && open !== widget) open.classList.remove('is-open');
            widget.classList.toggle('is-open');
            toggle.setAttribute('aria-expanded', widget.classList.contains('is-open') ? 'true' : 'false');
            return;
        }
        if (open && !e.target.closest('.mlt-switcher')) open.classList.remove('is-open');
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            var open = document.querySelector('.mlt-switcher.is-open');
            if (open) open.classList.remove('is-open');
        }
    });
})();
