import {defineConfig} from 'vite';
export default defineConfig({base:process.env.MEDIABOX_BASE||'/mediabox-staging/',build:{outDir:'../cpanel',emptyOutDir:false}});
