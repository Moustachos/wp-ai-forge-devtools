/**
 * AI Forge Dev Tools - Admin Entry Point
 *
 * Registers WordPress hooks to inject demo mode components
 * into the main AI Forge admin interface.
 *
 * @package AIForgeDevTools
 */

import { addFilter } from '@wordpress/hooks';
import DemoToggle from './components/DemoToggle';
import DemoNotice from './components/DemoNotice';
import './styles/demo-mode.scss';

/**
 * Inject the demo toggle into the app header.
 */
addFilter(
    'aiforge.app.headerTools',
    'aiforge-devtools/demo-toggle',
    () => <DemoToggle />
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
