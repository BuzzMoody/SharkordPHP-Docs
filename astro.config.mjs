// @ts-check
import { defineConfig } from 'astro/config';
import starlight from '@astrojs/starlight';

// When deploying to a custom domain, remove the `base` option and update `site`.
// When deploying to GitHub Pages without a custom domain, keep both as-is.
export default defineConfig({
    site: 'https://buzzmoody.github.io',
    base: '/SharkordPHP-Docs',
    integrations: [
        starlight({
            title: 'SharkordPHP',
            description: 'A ReactPHP chatbot framework for the Sharkord platform.',
            social: [
                {
                    icon: 'github',
                    label: 'GitHub',
                    href: 'https://github.com/BuzzMoody/SharkordPHP',
                },
            ],
            editLink: {
                baseUrl: 'https://github.com/BuzzMoody/SharkordPHP-Docs/edit/main/',
            },
            sidebar: [
                {
                    label: 'Getting Started',
                    link: '/getting-started',
                },
                {
                    label: 'Guides',
                    autogenerate: { directory: 'guides' },
                },
                {
                    label: 'Examples',
                    autogenerate: { directory: 'examples' },
                },
                {
                    label: 'API Reference',
                    items: [
                        {
                            label: 'Overview',
                            link: '/api',
                        },
                        {
                            label: 'Models',
                            collapsed: false,
                            autogenerate: { directory: 'api/models' },
                        },
                        {
                            label: 'Managers',
                            collapsed: false,
                            autogenerate: { directory: 'api/managers' },
                        },
                        {
                            label: 'Collections',
                            collapsed: true,
                            autogenerate: { directory: 'api/collections' },
                        },
                        {
                            label: 'Commands',
                            collapsed: true,
                            autogenerate: { directory: 'api/commands' },
                        },
                    ],
                },
            ],
            customCss: [
                './src/styles/custom.css',
            ],
        }),
    ],
});
