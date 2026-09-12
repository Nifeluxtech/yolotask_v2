// A modal is a real, independent DOM subtree appended to <body> — it never
// depends on the current page's own render cycle, and closing it never
// touches anything else on the page.

export function openModal(title, bodyHtml) {
  const backdrop = document.createElement('div');
  backdrop.className = 'modal-backdrop';
  backdrop.innerHTML = `
    <div class="modal" role="dialog" aria-modal="true">
      <div class="modal-head">
        <h3>${title}</h3>
        <button type="button" class="close" aria-label="Close">&times;</button>
      </div>
      ${bodyHtml}
    </div>`;
  document.body.appendChild(backdrop);
  backdrop.querySelector('.close').addEventListener('click', () => backdrop.remove());
  backdrop.addEventListener('click', event => { if (event.target === backdrop) backdrop.remove(); });
  return backdrop;
}

export function closeModal() {
  document.querySelector('.modal-backdrop')?.remove();
}
