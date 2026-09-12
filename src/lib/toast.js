// Every page includes <div id="toast-region" aria-live="polite"></div> in
// its HTML, but this creates it defensively if a page forgets, so a missing
// div never turns into a silent failure to show an error.

export function toast(message, type = 'success') {
  let region = document.querySelector('#toast-region');
  if (!region) {
    region = document.createElement('div');
    region.id = 'toast-region';
    region.className = 'toast-region';
    region.setAttribute('aria-live', 'polite');
    document.body.appendChild(region);
  }
  const node = document.createElement('div');
  node.className = `toast${type === 'error' ? ' error' : ''}`;
  node.textContent = message;
  region.appendChild(node);
  setTimeout(() => node.remove(), 4200);
}
