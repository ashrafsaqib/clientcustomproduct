(function () {
    var root = document.getElementById('wizardRoot');
    if (!root) {
        return;
    }

    var config = window.EXACT_WIZARD_CONFIG || {};
    var indicators = document.querySelectorAll('[data-step-indicator]');
    var panels = document.querySelectorAll('[data-step-panel]');
    var jumpButtons = document.querySelectorAll('[data-jump-step]');
    var settingsForm = document.getElementById('settingsForm');
    var STORAGE_KEY = 'exactWizardStep';

    function toInt(value, fallback) {
        var n = parseInt(String(value), 10);
        if (Number.isNaN(n)) {
            return fallback;
        }
        return n;
    }

    function getCurrentStep() {
        var saved = localStorage.getItem(STORAGE_KEY);
        if (saved !== null) {
            return Math.min(3, Math.max(1, toInt(saved, 1)));
        }

        return Math.min(3, Math.max(1, toInt(config.initialStep || root.dataset.initialStep, 1)));
    }

    function setCurrentStep(step) {
        var clamped = Math.min(3, Math.max(1, step));
        localStorage.setItem(STORAGE_KEY, String(clamped));

        indicators.forEach(function (el) {
            var itemStep = toInt(el.getAttribute('data-step-indicator'), 1);
            el.classList.toggle('active', itemStep === clamped);
        });

        panels.forEach(function (el) {
            var panelStep = toInt(el.getAttribute('data-step-panel'), 1);
            el.classList.toggle('active', panelStep === clamped);
        });
    }

    function settingsAreValid() {
        if (!settingsForm) {
            return false;
        }

        var required = settingsForm.querySelectorAll('input[required]');
        for (var i = 0; i < required.length; i += 1) {
            if (required[i].value.trim() === '') {
                required[i].focus();
                return false;
            }
        }

        return true;
    }

    jumpButtons.forEach(function (button) {
        button.addEventListener('click', function () {
            var target = toInt(button.getAttribute('data-jump-step'), 1);
            setCurrentStep(target);
        });
    });

    var toStep2 = document.getElementById('toStep2');
    if (toStep2) {
        toStep2.addEventListener('click', function () {
            if (!settingsAreValid()) {
                return;
            }

            setCurrentStep(2);
        });
    }

    var toStep3 = document.getElementById('toStep3');
    if (toStep3) {
        toStep3.addEventListener('click', function () {
            setCurrentStep(3);
        });
    }

    var backToStep1 = document.getElementById('backToStep1');
    if (backToStep1) {
        backToStep1.addEventListener('click', function () {
            setCurrentStep(1);
        });
    }

    var backToStep2 = document.getElementById('backToStep2');
    if (backToStep2) {
        backToStep2.addEventListener('click', function () {
            setCurrentStep(2);
        });
    }

    if (settingsForm) {
        settingsForm.addEventListener('submit', function () {
            setCurrentStep(2);
        });
    }

    setCurrentStep(getCurrentStep());
})();
