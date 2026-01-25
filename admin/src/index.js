/**
 * AI Forge Dev Tools - Admin Entry Point
 *
 * Registers WordPress hooks to inject devtools components
 * into the main AI Forge admin interface.
 *
 * @package AIForgeDevTools
 */

import { addFilter, addAction } from '@wordpress/hooks';
import DemoToggle from './components/DemoToggle';
import DevToggle from './components/DevToggle';
import DemoNotice from './components/DemoNotice';
import PromptDebugger from './components/PromptDebugger';
import { addTaskDetailTabs, addTaskDetailFooterActions } from './components/TaskDetailExtension';
import './styles/demo-mode.scss';
import './styles/prompt-debugger.scss';
import './styles/task-detail-extension.scss';

// Initialize global state for devtools
window.aiforgeDevState = window.aiforgeDevState || {};

/**
 * Listen for agent results and store debug info (prompt).
 */
addAction(
    'aiforge.agent.result',
    'aiforge-devtools/store-prompt',
    (data) => {
        if (data?.meta?.debug?.prompt) {
            window.aiforgeDevState.lastPrompt = data.meta.debug.prompt;
        }
    }
);

/**
 * Inject the toggles into the app header.
 */
addFilter(
    'aiforge.app.headerTools',
    'aiforge-devtools/header-toggles',
    () => (
        <div className="aiforge-devtools-toggles">
            <DemoToggle />
            <DevToggle />
        </div>
    )
);

/**
 * Inject the demo notice before services tab content.
 */
addFilter(
    'aiforge.settings.services.beforeContent',
    'aiforge-devtools/demo-notice',
    (content) => {
        const isDemoMode = window.aiforgeDevData?.isDemoMode ?? false;
        if (!isDemoMode) {
            return content;
        }
        return (
            <>
                <DemoNotice />
                {content}
            </>
        );
    }
);

/**
 * Inject the prompt debugger after screen content (only on content-integrator in dev mode).
 */
addFilter(
    'aiforge.screen.afterContent',
    'aiforge-devtools/prompt-debugger',
    (content, context) => {
        const isDevMode = window.aiforgeDevData?.isDevMode ?? false;
        if (!isDevMode || context?.screen !== 'content-integrator') {
            return content;
        }
        return (
            <>
                {content}
                <PromptDebugger />
            </>
        );
    }
);

/**
 * Add tabs (Logs/Payloads/Metadata) to TaskDetailModal (in dev mode).
 */
addFilter(
    'aiforge.taskDetailModal.tabs',
    'aiforge-devtools/task-detail-tabs',
    addTaskDetailTabs
);

/**
 * Add "Copy Prompt" button to TaskDetailModal footer (in dev mode).
 */
addFilter(
    'aiforge.taskDetailModal.footerActions',
    'aiforge-devtools/task-detail-footer-actions',
    addTaskDetailFooterActions
);
