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

/***/ "./src/components/DemoNotice.jsx"
/*!***************************************!*\
  !*** ./src/components/DemoNotice.jsx ***!
  \***************************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   "default": () => (/* binding */ DemoNotice)
/* harmony export */ });
/* harmony import */ var react__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! react */ "react");
/* harmony import */ var react__WEBPACK_IMPORTED_MODULE_0___default = /*#__PURE__*/__webpack_require__.n(react__WEBPACK_IMPORTED_MODULE_0__);
/* harmony import */ var _wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__ = __webpack_require__(/*! @wordpress/i18n */ "@wordpress/i18n");
/* harmony import */ var _wordpress_i18n__WEBPACK_IMPORTED_MODULE_1___default = /*#__PURE__*/__webpack_require__.n(_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__);

/**
 * Demo Notice Component
 *
 * Notice displayed when demo mode is active.
 *
 * @package AIForgeDevTools
 */


function DemoNotice() {
  return (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("div", {
    className: "aiforge-demo-notice"
  }, (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("span", {
    className: "dashicons dashicons-info"
  }), (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Mode démo actif : les services sont simulés, aucun appel externe.', 'ai-forge-devtools'));
}

/***/ },

/***/ "./src/components/DemoToggle.jsx"
/*!***************************************!*\
  !*** ./src/components/DemoToggle.jsx ***!
  \***************************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   "default": () => (/* binding */ DemoToggle)
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

/**
 * Demo Toggle Component
 *
 * Toggle control for enabling/disabling demo mode.
 *
 * @package AIForgeDevTools
 */





function DemoToggle() {
  var _window$aiforgeDevDat;
  const [isDemoMode, setIsDemoMode] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_1__.useState)((_window$aiforgeDevDat = window.aiforgeDevData?.isDemoMode) !== null && _window$aiforgeDevDat !== void 0 ? _window$aiforgeDevDat : false);
  const [isToggling, setIsToggling] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_1__.useState)(false);
  const handleToggle = async enabled => {
    setIsToggling(true);
    try {
      await _api_client__WEBPACK_IMPORTED_MODULE_4__["default"].setDemoMode(enabled);
      window.location.reload();
    } catch (error) {
      console.error('Failed to toggle demo mode:', error);
      setIsToggling(false);
    }
  };
  return (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)(_wordpress_components__WEBPACK_IMPORTED_MODULE_2__.ToggleControl, {
    label: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_3__.__)('Mode démo', 'ai-forge-devtools'),
    checked: isDemoMode,
    onChange: handleToggle,
    disabled: isToggling,
    __nextHasNoMarginBottom: true
  });
}

/***/ },

/***/ "./src/components/DevToggle.jsx"
/*!**************************************!*\
  !*** ./src/components/DevToggle.jsx ***!
  \**************************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   "default": () => (/* binding */ DevToggle)
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

/**
 * Dev Toggle Component
 *
 * Toggle control for enabling/disabling dev mode (debug tools).
 *
 * @package AIForgeDevTools
 */





function DevToggle() {
  var _window$aiforgeDevDat;
  const [isDevMode, setIsDevMode] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_1__.useState)((_window$aiforgeDevDat = window.aiforgeDevData?.isDevMode) !== null && _window$aiforgeDevDat !== void 0 ? _window$aiforgeDevDat : false);
  const [isToggling, setIsToggling] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_1__.useState)(false);
  const handleToggle = async enabled => {
    setIsToggling(true);
    try {
      await _api_client__WEBPACK_IMPORTED_MODULE_4__["default"].setDevMode(enabled);
      window.location.reload();
    } catch (error) {
      console.error('Failed to toggle dev mode:', error);
      setIsToggling(false);
    }
  };
  return (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)(_wordpress_components__WEBPACK_IMPORTED_MODULE_2__.ToggleControl, {
    label: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_3__.__)('Mode dev', 'ai-forge-devtools'),
    checked: isDevMode,
    onChange: handleToggle,
    disabled: isToggling,
    __nextHasNoMarginBottom: true
  });
}

/***/ },

/***/ "./src/components/PromptDebugger.jsx"
/*!*******************************************!*\
  !*** ./src/components/PromptDebugger.jsx ***!
  \*******************************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   "default": () => (/* binding */ PromptDebugger)
/* harmony export */ });
/* harmony import */ var react__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! react */ "react");
/* harmony import */ var react__WEBPACK_IMPORTED_MODULE_0___default = /*#__PURE__*/__webpack_require__.n(react__WEBPACK_IMPORTED_MODULE_0__);
/* harmony import */ var _wordpress_element__WEBPACK_IMPORTED_MODULE_1__ = __webpack_require__(/*! @wordpress/element */ "@wordpress/element");
/* harmony import */ var _wordpress_element__WEBPACK_IMPORTED_MODULE_1___default = /*#__PURE__*/__webpack_require__.n(_wordpress_element__WEBPACK_IMPORTED_MODULE_1__);
/* harmony import */ var _wordpress_hooks__WEBPACK_IMPORTED_MODULE_2__ = __webpack_require__(/*! @wordpress/hooks */ "@wordpress/hooks");
/* harmony import */ var _wordpress_hooks__WEBPACK_IMPORTED_MODULE_2___default = /*#__PURE__*/__webpack_require__.n(_wordpress_hooks__WEBPACK_IMPORTED_MODULE_2__);
/* harmony import */ var _wordpress_components__WEBPACK_IMPORTED_MODULE_3__ = __webpack_require__(/*! @wordpress/components */ "@wordpress/components");
/* harmony import */ var _wordpress_components__WEBPACK_IMPORTED_MODULE_3___default = /*#__PURE__*/__webpack_require__.n(_wordpress_components__WEBPACK_IMPORTED_MODULE_3__);
/* harmony import */ var _wordpress_i18n__WEBPACK_IMPORTED_MODULE_4__ = __webpack_require__(/*! @wordpress/i18n */ "@wordpress/i18n");
/* harmony import */ var _wordpress_i18n__WEBPACK_IMPORTED_MODULE_4___default = /*#__PURE__*/__webpack_require__.n(_wordpress_i18n__WEBPACK_IMPORTED_MODULE_4__);

/**
 * Prompt Debugger Component
 *
 * Displays the last prompt sent to the LLM with a copy button.
 * Only visible when demo mode is enabled.
 *
 * @package AIForgeDevTools
 */





function PromptDebugger() {
  const [prompt, setPrompt] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_1__.useState)(window.aiforgeDevState?.lastPrompt || null);
  const [copied, setCopied] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_1__.useState)(false);
  (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_1__.useEffect)(() => {
    const handleAgentResult = data => {
      if (data?.meta?.debug?.prompt) {
        setPrompt(data.meta.debug.prompt);
      }
    };
    (0,_wordpress_hooks__WEBPACK_IMPORTED_MODULE_2__.addAction)('aiforge.agent.result', 'aiforge-devtools/prompt-debugger-update', handleAgentResult);
    return () => {
      (0,_wordpress_hooks__WEBPACK_IMPORTED_MODULE_2__.removeAction)('aiforge.agent.result', 'aiforge-devtools/prompt-debugger-update');
    };
  }, []);
  if (!prompt) {
    return null;
  }
  const handleCopy = async () => {
    try {
      await navigator.clipboard.writeText(prompt);
      setCopied(true);
      setTimeout(() => setCopied(false), 2000);
    } catch (err) {
      console.error('Failed to copy:', err);
    }
  };
  return (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("div", {
    className: "aiforge-prompt-debugger"
  }, (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("div", {
    className: "aiforge-prompt-debugger__header"
  }, (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("h3", null, (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_4__.__)('Prompt envoyé', 'ai-forge-devtools')), (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)(_wordpress_components__WEBPACK_IMPORTED_MODULE_3__.Button, {
    variant: "secondary",
    size: "small",
    onClick: handleCopy,
    icon: copied ? 'yes' : 'clipboard'
  }, copied ? (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_4__.__)('Copié !', 'ai-forge-devtools') : (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_4__.__)('Copier', 'ai-forge-devtools'))), (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("pre", {
    className: "aiforge-prompt-debugger__code"
  }, (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("code", null, prompt)));
}

/***/ },

/***/ "./src/components/TaskDetailExtension.jsx"
/*!************************************************!*\
  !*** ./src/components/TaskDetailExtension.jsx ***!
  \************************************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   addTaskDetailFooterActions: () => (/* binding */ addTaskDetailFooterActions),
/* harmony export */   addTaskDetailTabs: () => (/* binding */ addTaskDetailTabs)
/* harmony export */ });
/* harmony import */ var react__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! react */ "react");
/* harmony import */ var react__WEBPACK_IMPORTED_MODULE_0___default = /*#__PURE__*/__webpack_require__.n(react__WEBPACK_IMPORTED_MODULE_0__);
/* harmony import */ var _wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__ = __webpack_require__(/*! @wordpress/i18n */ "@wordpress/i18n");
/* harmony import */ var _wordpress_i18n__WEBPACK_IMPORTED_MODULE_1___default = /*#__PURE__*/__webpack_require__.n(_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__);
/* harmony import */ var _wordpress_components__WEBPACK_IMPORTED_MODULE_2__ = __webpack_require__(/*! @wordpress/components */ "@wordpress/components");
/* harmony import */ var _wordpress_components__WEBPACK_IMPORTED_MODULE_2___default = /*#__PURE__*/__webpack_require__.n(_wordpress_components__WEBPACK_IMPORTED_MODULE_2__);
/* harmony import */ var _CopyButton__WEBPACK_IMPORTED_MODULE_3__ = __webpack_require__(/*! ./CopyButton */ "./src/components/CopyButton.jsx");

/**
 * Task Detail Modal Extension (DevTools)
 *
 * Adds Logs/Payloads/Metadata tabs and "Copy Prompt" button to TaskDetailModal
 * Only visible when dev mode is enabled.
 *
 * @package AIForgeDevTools
 */





/**
 * Get LLM child task for a markdown_to_gutenberg task
 */
function getLlmChildTask(task) {
  if (!task.children || task.children.length === 0) {
    return null;
  }
  return task.children.find(child => child.task_type === 'llm_generate');
}

/**
 * Get prompt payload from LLM task
 */
function getPromptFromTask(task) {
  const llmTask = getLlmChildTask(task);
  if (!llmTask || !llmTask.payloads) {
    return null;
  }
  const promptPayload = llmTask.payloads.find(p => p.type === 'prompt_snapshot_full');
  return promptPayload ? promptPayload.payload : null;
}

/**
 * Format date for logs
 */
function formatDate(dateString) {
  if (!dateString) return '-';
  const date = new Date(dateString);
  return date.toLocaleString('fr-FR', {
    year: 'numeric',
    month: 'long',
    day: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
    second: '2-digit'
  });
}

/**
 * Render Logs tab content
 */
function renderLogsTab(task) {
  const llmTask = getLlmChildTask(task);
  const logs = llmTask?.logs || task.logs || [];
  if (logs.length === 0) {
    return (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("div", {
      className: "task-detail-modal__empty"
    }, (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("p", null, (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Aucun log disponible', 'ai-forge-devtools')));
  }
  return (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("div", {
    className: "task-detail-modal__logs"
  }, logs.map((log, index) => (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("div", {
    key: index,
    className: `task-log task-log--${log.level}`
  }, (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("span", {
    className: "task-log__level"
  }, "[", log.level, "]"), (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("span", {
    className: "task-log__message"
  }, log.message), (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("span", {
    className: "task-log__time"
  }, formatDate(log.created_at)))));
}

/**
 * Render Payloads tab content
 */
function renderPayloadsTab(task) {
  const llmTask = getLlmChildTask(task);
  const payloads = llmTask?.payloads || task.payloads || [];
  if (payloads.length === 0) {
    return (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("div", {
      className: "task-detail-modal__empty"
    }, (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("p", null, (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Aucun payload disponible', 'ai-forge-devtools')));
  }
  return (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("div", {
    className: "task-detail-modal__payloads"
  }, payloads.map((payload, index) => (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("div", {
    key: index,
    className: "task-detail-modal__code-block"
  }, (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("div", {
    className: "task-detail-modal__code-header"
  }, (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("strong", null, payload.type), (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)(_CopyButton__WEBPACK_IMPORTED_MODULE_3__["default"], {
    content: payload.payload
  })), (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("pre", {
    className: "task-detail-modal__code"
  }, payload.payload))));
}

/**
 * Render Metadata tab content
 */
function renderMetadataTab(task) {
  const llmTask = getLlmChildTask(task);
  const meta = llmTask?.meta || task.meta || {};
  if (Object.keys(meta).length === 0) {
    return (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("div", {
      className: "task-detail-modal__empty"
    }, (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("p", null, (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Aucune métadonnée disponible', 'ai-forge-devtools')));
  }
  return (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("div", {
    className: "task-detail-modal__meta"
  }, (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("table", {
    className: "task-meta-table"
  }, (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("tbody", null, Object.entries(meta).map(([key, value]) => (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("tr", {
    key: key
  }, (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("td", {
    className: "task-meta-table__key"
  }, key), (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("td", {
    className: "task-meta-table__value"
  }, typeof value === 'object' ? JSON.stringify(value, null, 2) : String(value)))))));
}

/**
 * Add tabs to TaskDetailModal
 */
function addTaskDetailTabs(tabs, context) {
  var _window$aiforgeDevDat;
  const isDevMode = (_window$aiforgeDevDat = window.aiforgeDevData?.isDevMode) !== null && _window$aiforgeDevDat !== void 0 ? _window$aiforgeDevDat : false;
  if (!isDevMode) {
    return tabs;
  }
  const {
    task
  } = context;

  // Add Logs tab
  tabs.push({
    name: 'logs',
    title: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Logs', 'ai-forge-devtools'),
    content: renderLogsTab(task)
  });

  // Add Payloads tab
  tabs.push({
    name: 'payloads',
    title: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Payloads', 'ai-forge-devtools'),
    content: renderPayloadsTab(task)
  });

  // Add Metadata tab
  tabs.push({
    name: 'meta',
    title: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Métadonnées', 'ai-forge-devtools'),
    content: renderMetadataTab(task)
  });
  return tabs;
}

/**
 * Add "Copy Prompt" button to TaskDetailModal footer
 */
function addTaskDetailFooterActions(actions, context) {
  var _window$aiforgeDevDat2;
  const isDevMode = (_window$aiforgeDevDat2 = window.aiforgeDevData?.isDevMode) !== null && _window$aiforgeDevDat2 !== void 0 ? _window$aiforgeDevDat2 : false;
  if (!isDevMode) {
    return actions;
  }
  const {
    task
  } = context;

  // Only for markdown_to_gutenberg tasks
  if (task.task_type !== 'markdown_to_gutenberg') {
    return actions;
  }
  const prompt = getPromptFromTask(task);
  if (!prompt) {
    return actions;
  }

  // Add "Copy Prompt" button
  actions.push((0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)(_CopyButton__WEBPACK_IMPORTED_MODULE_3__["default"], {
    key: "copy-prompt",
    content: prompt,
    className: "copy-prompt-button"
  }));
  return actions;
}

/***/ },

/***/ "./src/styles/demo-mode.scss"
/*!***********************************!*\
  !*** ./src/styles/demo-mode.scss ***!
  \***********************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
// extracted by mini-css-extract-plugin


/***/ },

/***/ "./src/styles/prompt-debugger.scss"
/*!*****************************************!*\
  !*** ./src/styles/prompt-debugger.scss ***!
  \*****************************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
// extracted by mini-css-extract-plugin


/***/ },

/***/ "./src/styles/task-detail-extension.scss"
/*!***********************************************!*\
  !*** ./src/styles/task-detail-extension.scss ***!
  \***********************************************/
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

/***/ "@wordpress/hooks"
/*!*******************************!*\
  !*** external ["wp","hooks"] ***!
  \*******************************/
(module) {

module.exports = window["wp"]["hooks"];

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
/*!**********************!*\
  !*** ./src/index.js ***!
  \**********************/
__webpack_require__.r(__webpack_exports__);
/* harmony import */ var react__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! react */ "react");
/* harmony import */ var react__WEBPACK_IMPORTED_MODULE_0___default = /*#__PURE__*/__webpack_require__.n(react__WEBPACK_IMPORTED_MODULE_0__);
/* harmony import */ var _wordpress_hooks__WEBPACK_IMPORTED_MODULE_1__ = __webpack_require__(/*! @wordpress/hooks */ "@wordpress/hooks");
/* harmony import */ var _wordpress_hooks__WEBPACK_IMPORTED_MODULE_1___default = /*#__PURE__*/__webpack_require__.n(_wordpress_hooks__WEBPACK_IMPORTED_MODULE_1__);
/* harmony import */ var _components_DemoToggle__WEBPACK_IMPORTED_MODULE_2__ = __webpack_require__(/*! ./components/DemoToggle */ "./src/components/DemoToggle.jsx");
/* harmony import */ var _components_DevToggle__WEBPACK_IMPORTED_MODULE_3__ = __webpack_require__(/*! ./components/DevToggle */ "./src/components/DevToggle.jsx");
/* harmony import */ var _components_DemoNotice__WEBPACK_IMPORTED_MODULE_4__ = __webpack_require__(/*! ./components/DemoNotice */ "./src/components/DemoNotice.jsx");
/* harmony import */ var _components_PromptDebugger__WEBPACK_IMPORTED_MODULE_5__ = __webpack_require__(/*! ./components/PromptDebugger */ "./src/components/PromptDebugger.jsx");
/* harmony import */ var _components_TaskDetailExtension__WEBPACK_IMPORTED_MODULE_6__ = __webpack_require__(/*! ./components/TaskDetailExtension */ "./src/components/TaskDetailExtension.jsx");
/* harmony import */ var _styles_demo_mode_scss__WEBPACK_IMPORTED_MODULE_7__ = __webpack_require__(/*! ./styles/demo-mode.scss */ "./src/styles/demo-mode.scss");
/* harmony import */ var _styles_prompt_debugger_scss__WEBPACK_IMPORTED_MODULE_8__ = __webpack_require__(/*! ./styles/prompt-debugger.scss */ "./src/styles/prompt-debugger.scss");
/* harmony import */ var _styles_task_detail_extension_scss__WEBPACK_IMPORTED_MODULE_9__ = __webpack_require__(/*! ./styles/task-detail-extension.scss */ "./src/styles/task-detail-extension.scss");

/**
 * AI Forge Dev Tools - Admin Entry Point
 *
 * Registers WordPress hooks to inject devtools components
 * into the main AI Forge admin interface.
 *
 * @package AIForgeDevTools
 */











// Initialize global state for devtools
window.aiforgeDevState = window.aiforgeDevState || {};

/**
 * Listen for agent results and store debug info (prompt).
 */
(0,_wordpress_hooks__WEBPACK_IMPORTED_MODULE_1__.addAction)('aiforge.agent.result', 'aiforge-devtools/store-prompt', data => {
  if (data?.meta?.debug?.prompt) {
    window.aiforgeDevState.lastPrompt = data.meta.debug.prompt;
  }
});

/**
 * Inject the toggles into the app header.
 */
(0,_wordpress_hooks__WEBPACK_IMPORTED_MODULE_1__.addFilter)('aiforge.app.headerTools', 'aiforge-devtools/header-toggles', () => (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)("div", {
  className: "aiforge-devtools-toggles"
}, (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)(_components_DemoToggle__WEBPACK_IMPORTED_MODULE_2__["default"], null), (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)(_components_DevToggle__WEBPACK_IMPORTED_MODULE_3__["default"], null)));

/**
 * Inject the demo notice before services tab content.
 */
(0,_wordpress_hooks__WEBPACK_IMPORTED_MODULE_1__.addFilter)('aiforge.settings.services.beforeContent', 'aiforge-devtools/demo-notice', content => {
  var _window$aiforgeDevDat;
  const isDemoMode = (_window$aiforgeDevDat = window.aiforgeDevData?.isDemoMode) !== null && _window$aiforgeDevDat !== void 0 ? _window$aiforgeDevDat : false;
  if (!isDemoMode) {
    return content;
  }
  return (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)(react__WEBPACK_IMPORTED_MODULE_0__.Fragment, null, (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)(_components_DemoNotice__WEBPACK_IMPORTED_MODULE_4__["default"], null), content);
});

/**
 * Inject the prompt debugger after screen content (only on content-integrator in dev mode).
 */
(0,_wordpress_hooks__WEBPACK_IMPORTED_MODULE_1__.addFilter)('aiforge.screen.afterContent', 'aiforge-devtools/prompt-debugger', (content, context) => {
  var _window$aiforgeDevDat2;
  const isDevMode = (_window$aiforgeDevDat2 = window.aiforgeDevData?.isDevMode) !== null && _window$aiforgeDevDat2 !== void 0 ? _window$aiforgeDevDat2 : false;
  if (!isDevMode || context?.screen !== 'content-integrator') {
    return content;
  }
  return (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)(react__WEBPACK_IMPORTED_MODULE_0__.Fragment, null, content, (0,react__WEBPACK_IMPORTED_MODULE_0__.createElement)(_components_PromptDebugger__WEBPACK_IMPORTED_MODULE_5__["default"], null));
});

/**
 * Add tabs (Logs/Payloads/Metadata) to TaskDetailModal (in dev mode).
 */
(0,_wordpress_hooks__WEBPACK_IMPORTED_MODULE_1__.addFilter)('aiforge.taskDetailModal.tabs', 'aiforge-devtools/task-detail-tabs', _components_TaskDetailExtension__WEBPACK_IMPORTED_MODULE_6__.addTaskDetailTabs);

/**
 * Add "Copy Prompt" button to TaskDetailModal footer (in dev mode).
 */
(0,_wordpress_hooks__WEBPACK_IMPORTED_MODULE_1__.addFilter)('aiforge.taskDetailModal.footerActions', 'aiforge-devtools/task-detail-footer-actions', _components_TaskDetailExtension__WEBPACK_IMPORTED_MODULE_6__.addTaskDetailFooterActions);
})();

/******/ })()
;
//# sourceMappingURL=index.js.map