import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import '@fontsource-variable/inter';
import '../design/global.css';
import '../design/components.css';
import '../design/screens.css';
import { AdminApp } from './AdminApp';

const root = document.getElementById('root');
if (!root) throw new Error('Root element #root not found');

createRoot(root).render(
  <StrictMode>
    <AdminApp />
  </StrictMode>,
);
