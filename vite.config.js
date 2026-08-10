import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

// SEM pipeline de fonte remota. Duas razões:
//   1. O ADR-0008 escolheu a linhagem Helvetica como canônica do registro
//      suíço — não é substituto à espera de fonte de marca. A pilha está
//      em design/tokens.css.
//   2. Buscar fonte de CDN em tempo de BUILD cria dependência de rede na
//      implantação. Com uma VPS por cliente, um build que precisa de egresso
//      é um build que falha na hora errada.
export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
        }),
        tailwindcss(),
    ],
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
