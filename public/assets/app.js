/* Minimal vanilla JS: confirmations, tabs, copy buttons, file list preview. */
(function () {
    'use strict';

    // Confirmation prompts for destructive actions.
    document.addEventListener('submit', function (event) {
        var form = event.target;
        if (!(form instanceof HTMLFormElement)) {
            return;
        }
        var trigger = event.submitter || null;
        var message = trigger
            ? trigger.getAttribute('data-confirm')
            : form.getAttribute('data-confirm');
        if (message && !window.confirm(message)) {
            event.preventDefault();
            return;
        }
        // Prevent double submission.
        var buttons = form.querySelectorAll('button[type=submit]');
        window.setTimeout(function () {
            buttons.forEach(function (button) {
                button.disabled = true;
            });
        }, 50);
    });

    // Tabs inside the report preview.
    document.querySelectorAll('[data-tabs]').forEach(function (group) {
        var tabs = group.querySelectorAll('[data-tab]');
        var container = group.parentElement || document;
        var panels = container.querySelectorAll('[data-panel]');

        tabs.forEach(function (tab) {
            tab.addEventListener('click', function () {
                tabs.forEach(function (other) { other.classList.remove('is-active'); });
                tab.classList.add('is-active');
                var name = tab.getAttribute('data-tab');
                panels.forEach(function (panel) {
                    panel.classList.toggle('is-active', panel.getAttribute('data-panel') === name);
                });
            });
        });
    });

    // Copy-to-clipboard buttons (with a graceful fallback).
    document.querySelectorAll('[data-copy]').forEach(function (button) {
        button.addEventListener('click', function () {
            var source = document.querySelector('[data-copy-source]');
            if (!source) { return; }
            var text = source.value || source.textContent || '';

            var done = function () {
                var original = button.textContent;
                button.textContent = 'Copied';
                window.setTimeout(function () { button.textContent = original; }, 1600);
            };

            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text).then(done, function () {
                    source.select();
                    document.execCommand('copy');
                    done();
                });
            } else {
                source.select();
                document.execCommand('copy');
                done();
            }
        });
    });

    // Show the selected filenames before uploading.
    document.querySelectorAll('input[type=file]').forEach(function (input) {
        var list = input.parentElement && input.parentElement.querySelector('[data-file-list]');
        if (!list) { return; }
        input.addEventListener('change', function () {
            list.innerHTML = '';
            Array.prototype.forEach.call(input.files || [], function (file) {
                var item = document.createElement('li');
                item.textContent = file.name + ' — ' + Math.round(file.size / 1024) + ' KB';
                list.appendChild(item);
            });
        });
    });

    // Auto-submit filter dropdowns.
    document.querySelectorAll('.filters select').forEach(function (select) {
        select.addEventListener('change', function () {
            if (select.form) { select.form.submit(); }
        });
    });
})();
