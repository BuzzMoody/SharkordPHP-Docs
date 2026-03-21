// @ts-check
import { defineConfig } from 'astro/config';
import starlight from '@astrojs/starlight';

// When deploying to a custom domain, remove the `base` option and update `site`.
// When deploying to GitHub Pages without a custom domain, keep both as-is.
export default defineConfig({
    site: 'https://sharkordphp.xyz',
    integrations: [
        starlight({
            title: 'SharkordPHP',
			logo: {
                src: '/logo.png',
				alt: 'SharkordPHP',
            },
            favicon: '/favicon.ico',
            description: 'A ReactPHP Chatbot Framework for Sharkord',
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
                    link: '/',
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
							label: 'Scheduler', 
							link: '/api/scheduler/',
						},
                        {
							label: 'Events',
							collapsed: false,
							items: [
								{ label: 'Overview', link: '/api/events/' },
							],
						},
                        {
                            label: 'Models',
                            collapsed: true,
                            autogenerate: { directory: 'api/models' },
                        },
                        {
                            label: 'Managers',
                            collapsed: true,
                            autogenerate: { directory: 'api/managers' },
                        },
						{
							label: 'Builders',
							collapsed: true,
							autogenerate: { directory: 'api/builders' },
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
                './src/styles/theme.css',
            ],
        }),
    ],
});
