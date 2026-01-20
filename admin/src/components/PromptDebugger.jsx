/**
 * Prompt Debugger Component
 *
 * Displays the last prompt sent to the LLM with a copy button.
 * Only visible when demo mode is enabled.
 *
 * @package AIForgeDevTools
 */

import { useState, useEffect } from '@wordpress/element';
import { addAction, removeAction } from '@wordpress/hooks';
import { Button } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

export default function PromptDebugger() {
    const [prompt, setPrompt] = useState(window.aiforgeDevState?.lastPrompt || null);
    const [copied, setCopied] = useState(false);

    useEffect(() => {
        const handleAgentResult = (data) => {
            if (data?.meta?.debug?.prompt) {
                setPrompt(data.meta.debug.prompt);
            }
        };

        addAction('aiforge.agent.result', 'aiforge-devtools/prompt-debugger-update', handleAgentResult);

        return () => {
            removeAction('aiforge.agent.result', 'aiforge-devtools/prompt-debugger-update');
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

    return (
        <div className="aiforge-prompt-debugger">
            <div className="aiforge-prompt-debugger__header">
                <h3>{__('Prompt envoyé', 'ai-forge-devtools')}</h3>
                <Button
                    variant="secondary"
                    size="small"
                    onClick={handleCopy}
                    icon={copied ? 'yes' : 'clipboard'}
                >
                    {copied ? __('Copié !', 'ai-forge-devtools') : __('Copier', 'ai-forge-devtools')}
                </Button>
            </div>
            <pre className="aiforge-prompt-debugger__code">
                <code>{prompt}</code>
            </pre>
        </div>
    );
}
