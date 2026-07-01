import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';
import flowbite from 'flowbite/plugin';

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        './node_modules/flowbite/**/*.js',
    ],

    theme: {
        extend: {
            fontFamily: {
                sans: ['Figtree', ...defaultTheme.fontFamily.sans],
            },
            colors: {
                // PX brand navy
                brand: {
                    50:  '#eef0fb',
                    100: '#d9ddf5',
                    200: '#b3baea',
                    300: '#8d97e0',
                    400: '#5a64c4',
                    500: '#3a3f9e',
                    600: '#2d31a6',
                    700: '#23267a',
                    800: '#1a1c5c',
                    900: '#13144a',
                    950: '#0d0e33',
                },
                // PX accent yellow (logo)
                accent: {
                    DEFAULT: '#ffd60a',
                    400: '#ffd60a',
                    500: '#f5c518',
                },
            },
        },
    },

    plugins: [forms, flowbite],
};
