import nextVitals from "eslint-config-next/core-web-vitals";

const eslintConfig = [
  ...nextVitals,
  {
    linterOptions: {
      reportUnusedDisableDirectives: "error",
    },
  },
];

export default eslintConfig;
