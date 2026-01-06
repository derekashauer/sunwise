/** @type {import('tailwindcss').Config} */
export default {
  content: [
    "./index.html",
    "./src/**/*.{vue,js,ts,jsx,tsx}",
  ],
  theme: {
    extend: {
      colors: {
        // Warm cream background
        cream: {
          50: '#FFFDFB',
          100: '#FDF8F3',
          200: '#FAF0E6',
          300: '#F5E6D3',
        },
        // Soft sage green (primary)
        sage: {
          50: '#F4F9F4',
          100: '#E8F3E8',
          200: '#C5E0C5',
          300: '#A3CDA3',
          400: '#8BBF8B',
          500: '#7EB77F',
          600: '#6BA36C',
          700: '#588F59',
          800: '#457B46',
          900: '#326733',
        },
        // Warm terracotta/coral (secondary)
        terracotta: {
          50: '#FEF5F3',
          100: '#FCE8E4',
          200: '#F9D0C7',
          300: '#F3AEA0',
          400: '#E9887A',
          500: '#E07A5F',
          600: '#D15A3C',
          700: '#B04830',
          800: '#8F3B28',
          900: '#6E2E20',
        },
        // Soft sunny yellow (accent)
        sunny: {
          50: '#FFFCF5',
          100: '#FEF8EA',
          200: '#FCF0D0',
          300: '#F8E4AA',
          400: '#F5D88A',
          500: '#F2CC8F',
          600: '#E5B85A',
          700: '#D4A030',
          800: '#A87D25',
          900: '#7C5C1C',
        },
        // Warm charcoal for text
        charcoal: {
          50: '#F5F5F5',
          100: '#E8E8E8',
          200: '#D1D1D1',
          300: '#A3A3A3',
          400: '#757575',
          500: '#525252',
          600: '#3D3D3D',
          700: '#2E2E2E',
          800: '#1F1F1F',
          900: '#141414',
        },
        // Keep plant colors for backwards compatibility during transition
        plant: {
          50: '#F4F9F4',
          100: '#E8F3E8',
          200: '#C5E0C5',
          300: '#A3CDA3',
          400: '#8BBF8B',
          500: '#7EB77F',
          600: '#6BA36C',
          700: '#588F59',
          800: '#457B46',
          900: '#326733',
        }
      },
      fontFamily: {
        sans: ['Nunito', 'system-ui', '-apple-system', 'sans-serif'],
        hand: ['Patrick Hand', 'cursive'],
      },
      borderRadius: {
        'sketchy': '1rem',
      },
      boxShadow: {
        'warm': '0 4px 14px -2px rgba(224, 122, 95, 0.15)',
        'warm-lg': '0 10px 25px -5px rgba(224, 122, 95, 0.2)',
        'warm-xl': '0 20px 40px -10px rgba(224, 122, 95, 0.25)',
        'sage': '0 4px 14px -2px rgba(126, 183, 127, 0.2)',
        'sage-lg': '0 10px 25px -5px rgba(126, 183, 127, 0.25)',
        'lift': '0 8px 20px -4px rgba(61, 61, 61, 0.12)',
      },
      animation: {
        'bounce-subtle': 'bounce-subtle 0.3s ease-out',
        'wiggle': 'wiggle 0.3s ease-in-out',
        'sway': 'sway 3s ease-in-out infinite',
        'pop': 'pop 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275)',
        'fade-in': 'fade-in 0.3s ease-out',
        'slide-up': 'slide-up 0.3s ease-out',
        'pulse-warm': 'pulse-warm 2s ease-in-out infinite',
        'water-tip': 'water-tip 1s ease-in-out infinite',
      },
      keyframes: {
        'bounce-subtle': {
          '0%, 100%': { transform: 'scale(1)' },
          '50%': { transform: 'scale(1.05)' },
        },
        'wiggle': {
          '0%, 100%': { transform: 'rotate(0deg)' },
          '25%': { transform: 'rotate(-5deg)' },
          '75%': { transform: 'rotate(5deg)' },
        },
        'sway': {
          '0%, 100%': { transform: 'rotate(-1deg)' },
          '50%': { transform: 'rotate(1deg)' },
        },
        'pop': {
          '0%': { transform: 'scale(0.8)', opacity: '0' },
          '50%': { transform: 'scale(1.1)' },
          '100%': { transform: 'scale(1)', opacity: '1' },
        },
        'fade-in': {
          '0%': { opacity: '0' },
          '100%': { opacity: '1' },
        },
        'slide-up': {
          '0%': { transform: 'translateY(10px)', opacity: '0' },
          '100%': { transform: 'translateY(0)', opacity: '1' },
        },
        'pulse-warm': {
          '0%, 100%': { boxShadow: '0 0 0 0 rgba(224, 122, 95, 0.4)' },
          '50%': { boxShadow: '0 0 0 8px rgba(224, 122, 95, 0)' },
        },
        'water-tip': {
          '0%, 100%': { transform: 'rotate(0deg)' },
          '25%': { transform: 'rotate(-15deg)' },
          '50%': { transform: 'rotate(0deg)' },
          '75%': { transform: 'rotate(15deg)' },
        },
      },
    },
  },
  plugins: [],
}
