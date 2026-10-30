// @ts-check
const eslint = require("@eslint/js");
const tseslint = require("typescript-eslint");
const angular = require("angular-eslint");

module.exports = tseslint.config(
  {
    files: ["**/*.ts"],
    extends: [
      eslint.configs.recommended,
      ...tseslint.configs.recommended,
      ...tseslint.configs.stylistic,
      ...angular.configs.tsRecommended,
    ],
    processor: angular.processInlineTemplates,
    rules: {
      // Префиксы по проекту: app — сайт, adm — админка, fg — общие компоненты.
      "@angular-eslint/directive-selector": [
        "error",
        {
          type: "attribute",
          prefix: ["app", "adm", "fg"],
          style: "camelCase",
        },
      ],
      // Страницы маршрутов в проекте называются …Page (HomePage), остальные — …Component.
      "@angular-eslint/component-class-suffix": ["error", { suffixes: ["Component", "Page"] }],
      "@angular-eslint/component-selector": [
        "error",
        {
          type: "element",
          prefix: ["app", "adm", "fg"],
          style: "kebab-case",
        },
      ],
    },
  },
  {
    files: ["**/*.html"],
    extends: [
      ...angular.configs.templateRecommended,
      ...angular.configs.templateAccessibility,
    ],
    rules: {},
  }
);
