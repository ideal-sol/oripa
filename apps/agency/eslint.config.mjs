import nextVitals from "eslint-config-next/core-web-vitals";
const config = [...nextVitals, { linterOptions: { reportUnusedDisableDirectives: "error" } }];
export default config;
