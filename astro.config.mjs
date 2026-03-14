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
							label: 'Events',
							collapsed: false,
							items: [
								{ label: 'Overview', link: '/api/events/' },
								{ label: 'Ready', link: '/api/events/#ready' },
								{ label: 'Messages', link: '/api/events/#message_create' },
								{ label: 'Channels', link: '/api/events/#channel_create' },
								{ label: 'Users', link: '/api/events/#user_create' },
								{ label: 'Roles', link: '/api/events/#role_create' },
								{ label: 'Categories', link: '/api/events/#category_create' },
								{ label: 'Server', link: '/api/events/#server_update' },
							],
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
