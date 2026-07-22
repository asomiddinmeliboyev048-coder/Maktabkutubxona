'use strict';

document.addEventListener('DOMContentLoaded', () => {
    const sidebarToggle = document.querySelector('[data-sidebar-toggle]');
    const sidebar = document.querySelector('.admin-sidebar');

    if (sidebarToggle && sidebar) {
        sidebarToggle.addEventListener('click', () => {
            document.body.classList.toggle('sidebar-open');
        });

        document.addEventListener('click', (event) => {
            const clickedOutside = !sidebar.contains(event.target) && !sidebarToggle.contains(event.target);
            if (window.innerWidth < 992 && clickedOutside && document.body.classList.contains('sidebar-open')) {
                document.body.classList.remove('sidebar-open');
            }
        });
    }

    document.querySelectorAll('[data-character-count]').forEach((counter) => {
        const inputId = counter.getAttribute('data-character-count');
        const input = document.getElementById(inputId);
        if (!input) return;

        const updateCounter = () => {
            counter.textContent = String(input.value.length);
        };

        input.addEventListener('input', updateCounter);
        updateCounter();
    });

    const imageInput = document.querySelector('[data-image-input]');
    const imagePreview = document.querySelector('[data-image-preview]');
    let previewUrl = null;

    if (imageInput && imagePreview) {
        imageInput.addEventListener('change', () => {
            const file = imageInput.files && imageInput.files[0];
            if (!file) return;

            const allowedTypes = ['image/jpeg', 'image/png', 'image/webp'];
            if (!allowedTypes.includes(file.type)) {
                window.alert('Faqat JPG, JPEG, PNG yoki WEBP rasm tanlang.');
                imageInput.value = '';
                return;
            }

            if (file.size > 5 * 1024 * 1024) {
                window.alert('Rasm hajmi 5 MB dan oshmasligi kerak.');
                imageInput.value = '';
                return;
            }

            if (previewUrl) URL.revokeObjectURL(previewUrl);
            previewUrl = URL.createObjectURL(file);
            imagePreview.src = previewUrl;
        });
    }

    document.querySelectorAll('[data-confirm-return]').forEach((form) => {
        form.addEventListener('submit', (event) => {
            const title = form.getAttribute('data-confirm-return') || 'Ushbu kitob';
            const confirmed = window.confirm(`“${title}” kitobi qaytarib olinganini tasdiqlaysizmi?`);
            if (!confirmed) event.preventDefault();
        });
    });

    document.querySelectorAll('[data-confirm-action]').forEach((formControl) => {
        const form = formControl.closest('form');
        if (!form) return;
        form.addEventListener('submit', (event) => {
            if (!window.confirm(formControl.getAttribute('data-confirm-action') || 'Amalni tasdiqlaysizmi?')) event.preventDefault();
        });
    });

    const borrowDateInput = document.getElementById('borrow_date');
    const dueDateInput = document.getElementById('due_date');
    if (borrowDateInput && dueDateInput) {
        borrowDateInput.addEventListener('change', () => {
            if (!borrowDateInput.value) return;
            const date = new Date(`${borrowDateInput.value}T00:00:00`);
            if (Number.isNaN(date.getTime())) return;
            date.setDate(date.getDate() + 14);
            const year = date.getFullYear();
            const month = String(date.getMonth() + 1).padStart(2, '0');
            const day = String(date.getDate()).padStart(2, '0');
            dueDateInput.value = `${year}-${month}-${day}`;
            dueDateInput.min = borrowDateInput.value;
        });
    }

    document.querySelectorAll('[data-interactive-footer]').forEach((footer) => {
        const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
        let frameId = 0;

        const resetFooter = () => {
            footer.style.setProperty('--footer-x', '0px');
            footer.style.setProperty('--footer-y', '0px');
        };

        footer.addEventListener('pointermove', (event) => {
            if (reduceMotion.matches || event.pointerType === 'touch') return;
            const bounds = footer.getBoundingClientRect();
            const x = ((event.clientX - bounds.left) / bounds.width - 0.5) * 18;
            const y = ((event.clientY - bounds.top) / bounds.height - 0.5) * 12;
            window.cancelAnimationFrame(frameId);
            frameId = window.requestAnimationFrame(() => {
                footer.style.setProperty('--footer-x', `${x.toFixed(2)}px`);
                footer.style.setProperty('--footer-y', `${y.toFixed(2)}px`);
            });
        });
        footer.addEventListener('pointerleave', resetFooter);
        reduceMotion.addEventListener('change', resetFooter);
    });

    window.setTimeout(() => {
        document.querySelectorAll('.alert.alert-success').forEach((alertElement) => {
            if (window.bootstrap && window.bootstrap.Alert) {
                window.bootstrap.Alert.getOrCreateInstance(alertElement).close();
            }
        });
    }, 6500);

    window.addEventListener('beforeunload', () => {
        if (previewUrl) URL.revokeObjectURL(previewUrl);
    });
});
