/** @type {import('stylelint').Config} */
export default {
  rules: {
    "block-no-empty": true,
    "declaration-block-no-duplicate-custom-properties": true,
    "declaration-block-no-duplicate-properties": [
      true,
      { ignore: ["consecutive-duplicates-with-different-values"] },
    ],
    "declaration-no-important": true,
    "no-duplicate-at-import-rules": true,
    "selector-max-specificity": "1,6,3",
  },
};
