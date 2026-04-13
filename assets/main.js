// Initialize Nette Forms on page load
import netteForms from 'nette-forms';
import './style.css';

netteForms.initOnLoad();

// ── File upload preview with remove button ──────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.upload-field').forEach(field => {
        const input = field.querySelector('input[type="file"]');
        const hint = field.querySelector('.upload-hint');
        if (!input || !hint) return;

        // Prvek pro zobrazení vybraného souboru
        const preview = document.createElement('div');
        preview.className = 'upload-preview';
        preview.hidden = true;
        preview.innerHTML = `
			<span class="upload-preview-name"></span>
			<button type="button" class="upload-preview-remove" title="Odebrat soubor">✕</button>
		`;
        field.appendChild(preview);

        const nameEl = preview.querySelector('.upload-preview-name');
        const removeBtn = preview.querySelector('.upload-preview-remove');

        input.addEventListener('change', () => {
            const file = input.files[0];
            if (file) {
                nameEl.textContent = file.name;
                preview.hidden = false;
                hint.hidden = true;
            } else {
                clearPreview();
            }
        });

        removeBtn.addEventListener('click', () => {
            input.value = '';
            clearPreview();
        });

        function clearPreview() {
            preview.hidden = true;
            hint.hidden = false;
            nameEl.textContent = '';
        }
    });
});
