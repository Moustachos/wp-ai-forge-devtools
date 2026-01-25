/**
 * Copy Button Component (DevTools)
 *
 * Reusable copy button for code blocks
 *
 * @package AIForgeDevTools
 */

import { useState } from '@wordpress/element';
import { Button } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

/**
 * Copy button component for code blocks
 *
 * @param {Object} props
 * @param {string} props.content - Content to copy
 * @param {string} props.className - Additional CSS class
 */
function CopyButton({ content, className = '' }) {
	const [copied, setCopied] = useState(false);

	const handleCopy = async () => {
		try {
			await navigator.clipboard.writeText(content);
			setCopied(true);
			setTimeout(() => setCopied(false), 2000);
		} catch (error) {
			console.error('Failed to copy:', error);
		}
	};

	return (
		<Button
			variant="secondary"
			size="small"
			onClick={handleCopy}
			className={`copy-button ${className}`}
			icon={copied ? 'yes' : 'clipboard'}
		>
			{copied ? __('Copié !', 'ai-forge-devtools') : __('Copier', 'ai-forge-devtools')}
		</Button>
	);
}

export default CopyButton;
