/**
 * Demo Notice Component
 *
 * Notice displayed when demo mode is active.
 *
 * @package AIForgeDevTools
 */

import { __ } from '@wordpress/i18n';

export default function DemoNotice() {
    return (
        <div className="aiforge-demo-notice">
            <span className="dashicons dashicons-info"></span>
            {__('Mode démo actif : les services sont simulés, aucun appel externe.', 'ai-forge-devtools')}
        </div>
    );
}
