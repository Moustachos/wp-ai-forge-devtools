/**
 * Demo Toggle Component
 *
 * Toggle control for enabling/disabling demo mode.
 *
 * @package AIForgeDevTools
 */

import { useState } from '@wordpress/element';
import { ToggleControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import api from '../api/client';

export default function DemoToggle() {
    const [isDemoMode, setIsDemoMode] = useState(window.aiforgeDevData?.isDemoMode ?? false);
    const [isToggling, setIsToggling] = useState(false);

    const handleToggle = async (enabled) => {
        setIsToggling(true);
        try {
            await api.setDemoMode(enabled);
            window.location.reload();
        } catch (error) {
            console.error('Failed to toggle demo mode:', error);
            setIsToggling(false);
        }
    };

    return (
        <div className="aiforge-demo-toggle">
            <ToggleControl
                label={__('Mode démo', 'ai-forge-devtools')}
                checked={isDemoMode}
                onChange={handleToggle}
                disabled={isToggling}
                __nextHasNoMarginBottom
            />
        </div>
    );
}
