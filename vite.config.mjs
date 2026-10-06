import { defineConfig } from 'vite';

// Classic-script IIFE bundle: the Silverstripe CMS loads extra requirements as plain <script>, not type="module".
// jQuery / Entwine are runtime globals provided by the admin, never bundled.
export default defineConfig(({ mode }) => ({
  build: {
    outDir: 'client/dist',
    emptyOutDir: true,
    target: 'es2022',
    cssCodeSplit: false,
    minify: mode === 'development' ? false : 'esbuild',
    sourcemap: mode === 'development',
    lib: {
      entry: 'client/src/js/entry.js',
      name: 'YouWillLikeITGridFieldToolkit',
      formats: ['iife'],
      fileName: () => 'js/toolkit.js',
      cssFileName: 'styles/toolkit',
    },
  },
}));
