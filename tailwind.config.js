/** @type {import('tailwindcss').Config} */
export default {
  content: [
    "./index.html",
    "./src/**/*.{js,ts,jsx,tsx}",
  ],
  theme: {
    extend: {
      colors: {
        mimoo: {
          purple: '#7C3AED',
          'purple-dark': '#6D28D9',
          'purple-light': '#8B5CF6',
          'purple-tint': '#FAF5FF',
          'purple-soft': '#F3E8FF',
          pink: '#FF85B2',
          'pink-soft': '#FFD1E3',
          'pink-light': '#FFF0F6',
          'pink-dark': '#E0246A',
          yellow: '#FBBF24',
          'yellow-light': '#FEF9C3',
          mint: '#34D399',
          'mint-light': '#D1FAE5',
          sky: '#38BDF8',
          'sky-light': '#E0F2FE',
          dark: '#18181B',
          navy: '#1E1B4B',
          card: '#FFFFFF',
          bg: '#F8FAFC',
        }
      },
      fontFamily: {
        fredoka: ['Fredoka', 'sans-serif'],
        nunito: ['Nunito', 'sans-serif'],
        quicksand: ['Quicksand', 'sans-serif'],
      },
      boxShadow: {
        'chunky': '4px 4px 0px #18181B',
        'chunky-sm': '2px 2px 0px #18181B',
        'chunky-lg': '6px 6px 0px #18181B',
        'chunky-xl': '8px 8px 0px #18181B',
        'chunky-purple': '4px 4px 0px #6D28D9',
        'chunky-pink': '4px 4px 0px #DB2777',
        'soft-glow': '0 12px 32px -4px rgba(124, 58, 237, 0.15)',
        'pink-glow': '0 12px 32px -4px rgba(255, 133, 178, 0.25)',
      },
      borderRadius: {
        '3xl': '1.75rem',
        '4xl': '2.25rem',
      },
      animation: {
        'float': 'float 3s ease-in-out infinite',
        'pulse-subtle': 'pulseSubtle 2s cubic-bezier(0.4, 0, 0.6, 1) infinite',
        'wiggle': 'wiggle 1s ease-in-out infinite',
        'spin-slow': 'spin 12s linear infinite',
        'bounce-soft': 'bounceSoft 2s infinite',
        'print-scan': 'printScan 3s linear infinite',
      },
      keyframes: {
        float: {
          '0%, 100%': { transform: 'translateY(0px)' },
          '50%': { transform: 'translateY(-8px)' },
        },
        pulseSubtle: {
          '0%, 100%': { opacity: 1, transform: 'scale(1)' },
          '50%': { opacity: 0.92, transform: 'scale(1.03)' },
        },
        wiggle: {
          '0%, 100%': { transform: 'rotate(-3deg)' },
          '50%': { transform: 'rotate(3deg)' },
        },
        bounceSoft: {
          '0%, 100%': { transform: 'translateY(0)' },
          '50%': { transform: 'translateY(-5px)' },
        },
        printScan: {
          '0%': { transform: 'translateY(0%)' },
          '50%': { transform: 'translateY(100%)' },
          '100%': { transform: 'translateY(0%)' },
        }
      }
    },
  },
  plugins: [],
}
