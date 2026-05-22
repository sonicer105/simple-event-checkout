import { registerIconLibrary, setBasePath, startLoader } from '/assets/vendor/webawesome/webawesome.js';
// Explicit imports for components we rely on in critical admin flows.
// This avoids autoload timing/caching issues across vendor updates.
// import '/assets/vendor/webawesome/components/toast/toast.js';
// import '/assets/vendor/webawesome/components/toast-item/toast-item.js';

setBasePath('/assets/vendor/webawesome/');

registerIconLibrary('default', {
  resolver: (name, family = 'classic', variant = 'solid') => {
    if (family === 'brands' || variant === 'brands') {
      return `/assets/vendor/fontawesome/svgs/brands/${name}.svg`;
    }
    return `/assets/vendor/fontawesome/svgs/solid/${name}.svg`;
  }
});

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', function () {
    startLoader();
  }, { once: true });
} else {
  startLoader();
}
