const { createRoot, StrictMode } = wp.element;
import App from './App';

const mount = document.getElementById('rcrocket-root');

if (mount) {
  createRoot(mount).render(
    <StrictMode>
      <App />
    </StrictMode>
  );
}
