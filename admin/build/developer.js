/******/ (() => { // webpackBootstrap
/******/ 	"use strict";
/******/ 	var __webpack_modules__ = ({

/***/ "./src/api/client.js"
/*!***************************!*\
  !*** ./src/api/client.js ***!
  \***************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   api: () => (/* binding */ api),
/* harmony export */   "default": () => (__WEBPACK_DEFAULT_EXPORT__)
/* harmony export */ });
/**
 * API Client for AI Forge Dev Tools
 *
 * @package AIForgeDevTools
 */

const getConfig = () => {
  return window.aiforgeDevData || {
    apiUrl: '/wp-json/aiforge-dev/v1',
    nonce: ''
  };
};
const request = async (endpoint, options = {}) => {
  const config = getConfig();
  const url = `${config.apiUrl}${endpoint}`;
  const headers = {
    'Content-Type': 'application/json',
    'X-WP-Nonce': config.nonce,
    ...options.headers
  };
  const response = await fetch(url, {
    ...options,
    headers
  });
  const data = await response.json();
  if (!response.ok || data.success === false) {
    const error = new Error(data.error?.message || 'Une erreur est survenue');
    error.response = {
      data
    };
    throw error;
  }
  return data;
};
const api = {
  getDemoMode: () => request('/demo-mode'),
  setDemoMode: enabled => request('/demo-mode', {
    method: 'POST',
    body: JSON.stringify({
      enabled
    })
  }),
  getDevMode: () => request('/dev-mode'),
  setDevMode: enabled => request('/dev-mode', {
    method: 'POST',
    body: JSON.stringify({
      enabled
    })
  }),
  testSanitizer: (markdown, content) => request('/sanitizer/test', {
    method: 'POST',
    body: JSON.stringify({
      markdown,
      content
    })
  })
};
/* harmony default export */ const __WEBPACK_DEFAULT_EXPORT__ = (api);

/***/ },

/***/ "./src/components/CopyButton.jsx"
/*!***************************************!*\
  !*** ./src/components/CopyButton.jsx ***!
  \***************************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   "default": () => (__WEBPACK_DEFAULT_EXPORT__)
/* harmony export */ });
/* harmony import */ var react__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! react */ "react");
/* harmony import */ var react__WEBPACK_IMPORTED_MODULE_0___default = /*#__PURE__*/__webpack_require__.n(react__WEBPACK_IMPORTED_MODULE_0__);
/* harmony import */ var _wordpress_element__WEBPACK_IMPORTED_MODULE_1__ = __webpack_require__(/*! @wordpress/element */ "@wordpress/element");
/* harmony import */ var _wordpress_element__WEBPACK_IMPORTED_MODULE_1___default = /*#__PURE__*/__webpack_require__.n(_wordpress_element__WEBPACK_IMPORTED_MODULE_1__);
/* harmony import */ var _wordpress_components__WEBPACK_IMPORTED_MODULE_2__ = __webpack_require__(/*! @wordpress/components */ "@wordpress/components");
/* harmony import */ var _wordpress_components__WEBPACK_IMPORTED_MODULE_2___default = /*#__PURE__*/__webpack_require__.n(_wordpress_components__WEBPACK_IMPORTED_MODULE_2__);
/* harmony import */ var _wordpress_i18n__WEBPACK_IMPORTED_MODULE_3__ = __webpack_require__(/*! @wordpress/i18n */ "@wordpress/i18n");
/* harmony import */ var _wordpress_i18n__WEBPACK_IMPORTED_MODULE_3___default = /*#__PURE__*/__webpack_require__.n(_wordpress_i18n__WEBPACK_IMPORTED_MODULE_3__);

/**
 * Copy Button Component (DevTools)
 *
 * Reusable copy button for code blocks
 *
 * @package AIForgeDevTools
 */





/**
 * Copy button component for code blocks
 *
 * @param {Object} props
 * @param {string} props.content - Content to copy
 * @param {string} props.className - Additional CSS class
 */
function CopyButton({
  content,
  className = ''
}) {
  const [copied, setCopied] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_1__.useState)(false);
  const handleCopy = async () => {
    try {
      await navigator.clipboard.writeText(content);
      setCopied(true);
      setTimeout(() => setCopied(false), 2000);
    } catch (error) {
      console.error('Failed to copy:', error);
    }
  };
  return (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)(_wordpress_components__WEBPACK_IMPORTED_MODULE_2__.Button, {
    variant: "secondary",
    size: "small",
    onClick: handleCopy,
    className: `copy-button ${className}`,
    icon: copied ? 'yes' : 'clipboard'
  }, copied ? (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_3__.__)('Copié !', 'ai-forge-devtools') : (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_3__.__)('Copier', 'ai-forge-devtools'));
}
/* harmony default export */ const __WEBPACK_DEFAULT_EXPORT__ = (CopyButton);

/***/ },

/***/ "./src/components/SanitizerTester.jsx"
/*!********************************************!*\
  !*** ./src/components/SanitizerTester.jsx ***!
  \********************************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   "default": () => (__WEBPACK_DEFAULT_EXPORT__)
/* harmony export */ });
/* harmony import */ var react__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! react */ "react");
/* harmony import */ var react__WEBPACK_IMPORTED_MODULE_0___default = /*#__PURE__*/__webpack_require__.n(react__WEBPACK_IMPORTED_MODULE_0__);
/* harmony import */ var _wordpress_element__WEBPACK_IMPORTED_MODULE_1__ = __webpack_require__(/*! @wordpress/element */ "@wordpress/element");
/* harmony import */ var _wordpress_element__WEBPACK_IMPORTED_MODULE_1___default = /*#__PURE__*/__webpack_require__.n(_wordpress_element__WEBPACK_IMPORTED_MODULE_1__);
/* harmony import */ var _wordpress_components__WEBPACK_IMPORTED_MODULE_2__ = __webpack_require__(/*! @wordpress/components */ "@wordpress/components");
/* harmony import */ var _wordpress_components__WEBPACK_IMPORTED_MODULE_2___default = /*#__PURE__*/__webpack_require__.n(_wordpress_components__WEBPACK_IMPORTED_MODULE_2__);
/* harmony import */ var _wordpress_i18n__WEBPACK_IMPORTED_MODULE_3__ = __webpack_require__(/*! @wordpress/i18n */ "@wordpress/i18n");
/* harmony import */ var _wordpress_i18n__WEBPACK_IMPORTED_MODULE_3___default = /*#__PURE__*/__webpack_require__.n(_wordpress_i18n__WEBPACK_IMPORTED_MODULE_3__);
/* harmony import */ var _api_client__WEBPACK_IMPORTED_MODULE_4__ = __webpack_require__(/*! ../api/client */ "./src/api/client.js");
/* harmony import */ var _CopyButton__WEBPACK_IMPORTED_MODULE_5__ = __webpack_require__(/*! ./CopyButton */ "./src/components/CopyButton.jsx");






function SanitizerTester() {
  const [markdown, setMarkdown] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_1__.useState)('');
  const [content, setContent] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_1__.useState)('');
  const [result, setResult] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_1__.useState)(null);
  const [isLoading, setIsLoading] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_1__.useState)(false);
  const [error, setError] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_1__.useState)(null);
  const handleSubmit = async () => {
    if (!content.trim()) {
      setError((0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_3__.__)('Le contenu Gutenberg est requis.', 'ai-forge-devtools'));
      return;
    }
    if (!markdown.trim()) {
      setError((0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_3__.__)('Le contenu Markdown est requis.', 'ai-forge-devtools'));
      return;
    }
    setIsLoading(true);
    setError(null);
    setResult(null);
    try {
      const response = await _api_client__WEBPACK_IMPORTED_MODULE_4__["default"].testSanitizer(markdown, content);
      setResult(response.data);
    } catch (err) {
      setError(err.message || (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_3__.__)('Une erreur est survenue.', 'ai-forge-devtools'));
    } finally {
      setIsLoading(false);
    }
  };
  const handleClear = () => {
    setMarkdown('');
    setContent('');
    setResult(null);
    setError(null);
  };
  return (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("div", {
    className: "sanitizer-tester"
  }, (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("div", {
    className: "sanitizer-tester__section-title"
  }, (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("span", {
    className: "dashicons dashicons-admin-tools"
  }), (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_3__.__)('Test du GutenbergSanitizer', 'ai-forge-devtools')), error && (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)(_wordpress_components__WEBPACK_IMPORTED_MODULE_2__.Notice, {
    status: "error",
    isDismissible: true,
    onDismiss: () => setError(null)
  }, error), (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("div", {
    className: "sanitizer-tester__inputs"
  }, (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("div", {
    className: "sanitizer-tester__panel"
  }, (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("div", {
    className: "sanitizer-tester__panel-header"
  }, (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("span", {
    className: "dashicons dashicons-editor-code"
  }), (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("span", null, (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_3__.__)('Source Markdown', 'ai-forge-devtools'))), (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("div", {
    className: "sanitizer-tester__panel-body"
  }, (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("textarea", {
    value: markdown,
    onChange: e => setMarkdown(e.target.value),
    placeholder: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_3__.__)('Collez le markdown source ici...', 'ai-forge-devtools'),
    disabled: isLoading
  }))), (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("div", {
    className: "sanitizer-tester__panel"
  }, (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("div", {
    className: "sanitizer-tester__panel-header"
  }, (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("span", {
    className: "dashicons dashicons-html"
  }), (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("span", null, (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_3__.__)('Contenu Gutenberg', 'ai-forge-devtools'))), (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("div", {
    className: "sanitizer-tester__panel-body"
  }, (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("textarea", {
    value: content,
    onChange: e => setContent(e.target.value),
    placeholder: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_3__.__)('Collez le contenu Gutenberg généré ici...', 'ai-forge-devtools'),
    disabled: isLoading
  })))), (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("div", {
    className: "sanitizer-tester__actions"
  }, (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)(_wordpress_components__WEBPACK_IMPORTED_MODULE_2__.Button, {
    variant: "primary",
    onClick: handleSubmit,
    disabled: isLoading || !content.trim() || !markdown.trim()
  }, isLoading ? (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)(react__WEBPACK_IMPORTED_MODULE_0__.Fragment, null, (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)(_wordpress_components__WEBPACK_IMPORTED_MODULE_2__.Spinner, null), (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_3__.__)('Analyse en cours...', 'ai-forge-devtools')) : (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_3__.__)('Tester la sanitization', 'ai-forge-devtools')), (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)(_wordpress_components__WEBPACK_IMPORTED_MODULE_2__.Button, {
    variant: "secondary",
    onClick: handleClear,
    disabled: isLoading
  }, (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_3__.__)('Effacer', 'ai-forge-devtools'))), result && (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)(SanitizerResult, {
    result: result
  }));
}
function SanitizerResult({
  result
}) {
  const {
    sanitized,
    changes,
    has_changes
  } = result;
  const formatChange = change => {
    if (change.type === 'heading_level') {
      return `Heading "${change.text}" : level ${change.from} → ${change.to}`;
    }
    if (change.type === 'list_type') {
      return `List : type ${change.from} → ${change.to}`;
    }
    if (change.type === 'list_items_wrapped') {
      return `List : ${change.count} items wrapped in wp:list-item`;
    }
    if (change.type === 'preamble_stripped') {
      return `Preamble stripped: "${change.content}${change.content.length >= 100 ? '...' : ''}"`;
    }
    if (change.type === 'postamble_stripped') {
      return `Postamble stripped: "${change.content}${change.content.length >= 100 ? '...' : ''}"`;
    }
    if (change.type === 'empty_paragraphs_removed') {
      return `${change.count} empty paragraph${change.count > 1 ? 's' : ''} removed`;
    }
    if (change.type === 'lorem_ipsum_removed') {
      return `${change.count} lorem ipsum block${change.count > 1 ? 's' : ''} removed`;
    }
    if (change.type === 'quote_citation_synced') {
      return `Quote citation synced: "${change.citation}"`;
    }
    if (change.type === 'attribute_added') {
      return `${change.block} : ajout attribut "${change.attribute}"`;
    }
    return JSON.stringify(change);
  };
  return (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("div", {
    className: "sanitizer-tester__result"
  }, (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("div", {
    className: "sanitizer-tester__panel sanitizer-tester__panel--result"
  }, (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("div", {
    className: "sanitizer-tester__panel-header"
  }, (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("span", {
    className: "dashicons dashicons-yes-alt"
  }), (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("span", null, (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_3__.__)('Résultat', 'ai-forge-devtools')), (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)(_CopyButton__WEBPACK_IMPORTED_MODULE_5__["default"], {
    content: sanitized
  })), (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("div", {
    className: "sanitizer-tester__panel-body"
  }, has_changes ? (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)(react__WEBPACK_IMPORTED_MODULE_0__.Fragment, null, (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("div", {
    className: "sanitizer-tester__changes"
  }, (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)(_wordpress_components__WEBPACK_IMPORTED_MODULE_2__.Notice, {
    status: "warning",
    isDismissible: false
  }, changes.length, ' ', changes.length === 1 ? (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_3__.__)('correction effectuée', 'ai-forge-devtools') : (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_3__.__)('corrections effectuées', 'ai-forge-devtools')), (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("ul", {
    className: "sanitizer-tester__changes-list"
  }, changes.map((change, index) => (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("li", {
    key: index
  }, formatChange(change))))), (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("pre", {
    className: "sanitizer-tester__code"
  }, (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("code", null, sanitized))) : (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)(_wordpress_components__WEBPACK_IMPORTED_MODULE_2__.Notice, {
    status: "success",
    isDismissible: false
  }, (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_3__.__)('Aucune correction nécessaire - le contenu est valide.', 'ai-forge-devtools')))));
}
/* harmony default export */ const __WEBPACK_DEFAULT_EXPORT__ = (SanitizerTester);

/***/ },

/***/ "./src/pages/DeveloperPage.jsx"
/*!*************************************!*\
  !*** ./src/pages/DeveloperPage.jsx ***!
  \*************************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   "default": () => (__WEBPACK_DEFAULT_EXPORT__)
/* harmony export */ });
/* harmony import */ var react__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! react */ "react");
/* harmony import */ var react__WEBPACK_IMPORTED_MODULE_0___default = /*#__PURE__*/__webpack_require__.n(react__WEBPACK_IMPORTED_MODULE_0__);
/* harmony import */ var _wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__ = __webpack_require__(/*! @wordpress/i18n */ "@wordpress/i18n");
/* harmony import */ var _wordpress_i18n__WEBPACK_IMPORTED_MODULE_1___default = /*#__PURE__*/__webpack_require__.n(_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__);
/* harmony import */ var _components_SanitizerTester__WEBPACK_IMPORTED_MODULE_2__ = __webpack_require__(/*! ../components/SanitizerTester */ "./src/components/SanitizerTester.jsx");



function DeveloperPage() {
  return (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("div", {
    className: "aiforge-developer-page__content"
  }, (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("h1", null, (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Outils de développement', 'ai-forge-devtools')), (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)(_components_SanitizerTester__WEBPACK_IMPORTED_MODULE_2__["default"], null));
}
/* harmony default export */ const __WEBPACK_DEFAULT_EXPORT__ = (DeveloperPage);

/***/ },

/***/ "./src/styles/developer-page.scss"
/*!****************************************!*\
  !*** ./src/styles/developer-page.scss ***!
  \****************************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
// extracted by mini-css-extract-plugin


/***/ },

/***/ "@wordpress/components"
/*!************************************!*\
  !*** external ["wp","components"] ***!
  \************************************/
(module) {

module.exports = window["wp"]["components"];

/***/ },

/***/ "@wordpress/element"
/*!*********************************!*\
  !*** external ["wp","element"] ***!
  \*********************************/
(module) {

module.exports = window["wp"]["element"];

/***/ },

/***/ "@wordpress/i18n"
/*!******************************!*\
  !*** external ["wp","i18n"] ***!
  \******************************/
(module) {

module.exports = window["wp"]["i18n"];

/***/ },

/***/ "react"
/*!************************!*\
  !*** external "React" ***!
  \************************/
(module) {

module.exports = window["React"];

/***/ }

/******/ 	});
/************************************************************************/
/******/ 	// The module cache
/******/ 	var __webpack_module_cache__ = {};
/******/ 	
/******/ 	// The require function
/******/ 	function __webpack_require__(moduleId) {
/******/ 		// Check if module is in cache
/******/ 		var cachedModule = __webpack_module_cache__[moduleId];
/******/ 		if (cachedModule !== undefined) {
/******/ 			return cachedModule.exports;
/******/ 		}
/******/ 		// Check if module exists (development only)
/******/ 		if (__webpack_modules__[moduleId] === undefined) {
/******/ 			var e = new Error("Cannot find module '" + moduleId + "'");
/******/ 			e.code = 'MODULE_NOT_FOUND';
/******/ 			throw e;
/******/ 		}
/******/ 		// Create a new module (and put it into the cache)
/******/ 		var module = __webpack_module_cache__[moduleId] = {
/******/ 			// no module.id needed
/******/ 			// no module.loaded needed
/******/ 			exports: {}
/******/ 		};
/******/ 	
/******/ 		// Execute the module function
/******/ 		__webpack_modules__[moduleId](module, module.exports, __webpack_require__);
/******/ 	
/******/ 		// Return the exports of the module
/******/ 		return module.exports;
/******/ 	}
/******/ 	
/************************************************************************/
/******/ 	/* webpack/runtime/compat get default export */
/******/ 	(() => {
/******/ 		// getDefaultExport function for compatibility with non-harmony modules
/******/ 		__webpack_require__.n = (module) => {
/******/ 			var getter = module && module.__esModule ?
/******/ 				() => (module['default']) :
/******/ 				() => (module);
/******/ 			__webpack_require__.d(getter, { a: getter });
/******/ 			return getter;
/******/ 		};
/******/ 	})();
/******/ 	
/******/ 	/* webpack/runtime/define property getters */
/******/ 	(() => {
/******/ 		// define getter functions for harmony exports
/******/ 		__webpack_require__.d = (exports, definition) => {
/******/ 			for(var key in definition) {
/******/ 				if(__webpack_require__.o(definition, key) && !__webpack_require__.o(exports, key)) {
/******/ 					Object.defineProperty(exports, key, { enumerable: true, get: definition[key] });
/******/ 				}
/******/ 			}
/******/ 		};
/******/ 	})();
/******/ 	
/******/ 	/* webpack/runtime/hasOwnProperty shorthand */
/******/ 	(() => {
/******/ 		__webpack_require__.o = (obj, prop) => (Object.prototype.hasOwnProperty.call(obj, prop))
/******/ 	})();
/******/ 	
/******/ 	/* webpack/runtime/make namespace object */
/******/ 	(() => {
/******/ 		// define __esModule on exports
/******/ 		__webpack_require__.r = (exports) => {
/******/ 			if(typeof Symbol !== 'undefined' && Symbol.toStringTag) {
/******/ 				Object.defineProperty(exports, Symbol.toStringTag, { value: 'Module' });
/******/ 			}
/******/ 			Object.defineProperty(exports, '__esModule', { value: true });
/******/ 		};
/******/ 	})();
/******/ 	
/************************************************************************/
var __webpack_exports__ = {};
// This entry needs to be wrapped in an IIFE because it needs to be isolated against other modules in the chunk.
(() => {
/*!**************************!*\
  !*** ./src/developer.js ***!
  \**************************/
__webpack_require__.r(__webpack_exports__);
/* harmony import */ var react__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! react */ "react");
/* harmony import */ var react__WEBPACK_IMPORTED_MODULE_0___default = /*#__PURE__*/__webpack_require__.n(react__WEBPACK_IMPORTED_MODULE_0__);
/* harmony import */ var _wordpress_element__WEBPACK_IMPORTED_MODULE_1__ = __webpack_require__(/*! @wordpress/element */ "@wordpress/element");
/* harmony import */ var _wordpress_element__WEBPACK_IMPORTED_MODULE_1___default = /*#__PURE__*/__webpack_require__.n(_wordpress_element__WEBPACK_IMPORTED_MODULE_1__);
/* harmony import */ var _pages_DeveloperPage__WEBPACK_IMPORTED_MODULE_2__ = __webpack_require__(/*! ./pages/DeveloperPage */ "./src/pages/DeveloperPage.jsx");
/* harmony import */ var _styles_developer_page_scss__WEBPACK_IMPORTED_MODULE_3__ = __webpack_require__(/*! ./styles/developer-page.scss */ "./src/styles/developer-page.scss");




document.addEventListener('DOMContentLoaded', () => {
  const container = document.getElementById('aiforge-dev-root');
  if (container) {
    const root = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_1__.createRoot)(container);
    root.render((0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)(_pages_DeveloperPage__WEBPACK_IMPORTED_MODULE_2__["default"], null));
  }
});
})();

/******/ })()
;
//# sourceMappingURL=developer.js.map