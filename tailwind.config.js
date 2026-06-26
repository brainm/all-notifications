/** @type {import('tailwindcss').Config} */
export default {
  content: ["./src/**/*.{js,jsx,php}", "./public/**/*.html"],
  theme: {
    extend: {
      colors: {
        ink: {
          950: "#0f0f1a",
          900: "#1a1a2e",
          800: "#252542",
        },
        accent: {
          DEFAULT: "#4f7cff",
          hover: "#3d6ae8",
        },
      },
      fontFamily: {
        sans: ["system-ui", "-apple-system", "Segoe UI", "Roboto", "sans-serif"],
      },
    },
  },
  plugins: [],
};
