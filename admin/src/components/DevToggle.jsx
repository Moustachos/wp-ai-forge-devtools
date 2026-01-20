/**
 * Dev Toggle Component
 *
 * Toggle control for enabling/disabling dev mode (debug tools).
 *
 * @package AIForgeDevTools
 */

import { useState } from '@wordpress/element';
import { ToggleControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import api from '../api/client';

export default function DevToggle() {
    const [isDevMode, setIsDevMode] = useState(window.aiforgeDevData?.isDevMode ?? false);
    const [isToggling, setIsToggling] = useState(false);

    const handleToggle = async (enabled) => {
        setIsToggling(true);
        try {
            await api.setDevMode(enabled);
            window.location.reload();
        } catch (error) {
            console.error('Failed to toggle dev mode:', error);
            setIsToggling(false);
        }
    };

    return (
        <ToggleControl
            label={__('Mode dev', 'ai-forge-devtools')}
            checked={isDevMode}
            onChange={handleToggle}
            disabled={isToggling}
            __nextHasNoMarginBottom
        />
    );
}
