/**
 * Task Detail Modal Extension (DevTools)
 *
 * Adds Logs/Payloads/Metadata tabs and "Copy Prompt" button to TaskDetailModal
 * Only visible when dev mode is enabled.
 *
 * @package AIForgeDevTools
 */

import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Button, Dropdown, MenuGroup, MenuItem } from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';
import CopyButton from './CopyButton';

/**
 * Provider options for prompt copy dropdown
 */
const PROVIDER_OPTIONS = [
	{ id: null, label: __('Sans addenda', 'ai-forge-devtools'), icon: 'editor-code' },
	{ id: 'gemini', label: 'Gemini', icon: 'cloud' },
	{ id: 'openai', label: 'OpenAI', icon: 'cloud' },
	{ id: 'anthropic', label: 'Claude', icon: 'cloud' },
];

/**
 * Get LLM child task for a markdown_to_gutenberg task
 */
function getLlmChildTask(task) {
	if (!task.children || task.children.length === 0) {
		return null;
	}
	return task.children.find((child) => child.task_type === 'llm_generate');
}

/**
 * Get prompt payload from LLM task
 */
function getPromptFromTask(task) {
	const llmTask = getLlmChildTask(task);
	if (!llmTask || !llmTask.payloads) {
		return null;
	}

	const promptPayload = llmTask.payloads.find((p) => p.type === 'prompt_snapshot_full');
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
		second: '2-digit',
	});
}

/**
 * Render Logs tab content
 */
function renderLogsTab(task) {
	const llmTask = getLlmChildTask(task);
	const logs = llmTask?.logs || task.logs || [];

	if (logs.length === 0) {
		return (
			<div className="task-detail-modal__empty">
				<p>{__('Aucun log disponible', 'ai-forge-devtools')}</p>
			</div>
		);
	}

	return (
		<div className="task-detail-modal__logs">
			{logs.map((log, index) => (
				<div key={index} className={`task-log task-log--${log.level}`}>
					<span className="task-log__level">[{log.level}]</span>
					<span className="task-log__message">{log.message}</span>
					<span className="task-log__time">{formatDate(log.created_at)}</span>
				</div>
			))}
		</div>
	);
}

/**
 * Render Payloads tab content
 */
function renderPayloadsTab(task) {
	const llmTask = getLlmChildTask(task);
	const payloads = llmTask?.payloads || task.payloads || [];

	if (payloads.length === 0) {
		return (
			<div className="task-detail-modal__empty">
				<p>{__('Aucun payload disponible', 'ai-forge-devtools')}</p>
			</div>
		);
	}

	return (
		<div className="task-detail-modal__payloads">
			{payloads.map((payload, index) => (
				<div key={index} className="task-detail-modal__code-block">
					<div className="task-detail-modal__code-header">
						<strong>{payload.type}</strong>
						<CopyButton content={payload.payload} />
					</div>
					<pre className="task-detail-modal__code">{payload.payload}</pre>
				</div>
			))}
		</div>
	);
}

/**
 * Render Metadata tab content
 */
function renderMetadataTab(task) {
	const llmTask = getLlmChildTask(task);
	const meta = llmTask?.meta || task.meta || {};

	if (Object.keys(meta).length === 0) {
		return (
			<div className="task-detail-modal__empty">
				<p>{__('Aucune métadonnée disponible', 'ai-forge-devtools')}</p>
			</div>
		);
	}

	return (
		<div className="task-detail-modal__meta">
			<table className="task-meta-table">
				<tbody>
					{Object.entries(meta).map(([key, value]) => (
						<tr key={key}>
							<td className="task-meta-table__key">{key}</td>
							<td className="task-meta-table__value">
								{typeof value === 'object' ? JSON.stringify(value, null, 2) : String(value)}
							</td>
						</tr>
					))}
				</tbody>
			</table>
		</div>
	);
}

/**
 * Add tabs to TaskDetailModal
 */
export function addTaskDetailTabs(tabs, context) {
	const isDevMode = window.aiforgeDevData?.isDevMode ?? false;
	if (!isDevMode) {
		return tabs;
	}

	const { task } = context;

	// Add Logs tab
	tabs.push({
		name: 'logs',
		title: __('Logs', 'ai-forge-devtools'),
		content: renderLogsTab(task),
	});

	// Add Payloads tab
	tabs.push({
		name: 'payloads',
		title: __('Payloads', 'ai-forge-devtools'),
		content: renderPayloadsTab(task),
	});

	// Add Metadata tab
	tabs.push({
		name: 'meta',
		title: __('Métadonnées', 'ai-forge-devtools'),
		content: renderMetadataTab(task),
	});

	return tabs;
}

/**
 * Copy Prompt Dropdown Component
 *
 * Allows copying the prompt with different provider addenda.
 */
function CopyPromptDropdown({ taskId }) {
	const [loading, setLoading] = useState(false);
	const [copied, setCopied] = useState(false);
	const [copiedProvider, setCopiedProvider] = useState(null);

	const handleCopyPrompt = async (providerId, onClose) => {
		setLoading(true);
		setCopiedProvider(providerId);

		try {
			const params = providerId ? `?provider=${providerId}` : '';
			const response = await apiFetch({
				path: `/aiforge/v1/tasks/${taskId}/prompt${params}`,
			});

			if (response.success && response.data?.prompt) {
				await navigator.clipboard.writeText(response.data.prompt);
				setCopied(true);
				setTimeout(() => {
					setCopied(false);
					setCopiedProvider(null);
				}, 2000);
			}
		} catch (error) {
			console.error('Failed to fetch/copy prompt:', error);
		} finally {
			setLoading(false);
			onClose();
		}
	};

	return (
		<Dropdown
			popoverProps={{ placement: 'bottom-end' }}
			renderToggle={({ isOpen, onToggle }) => (
				<Button
					variant="secondary"
					icon={copied ? 'yes' : 'clipboard'}
					onClick={onToggle}
					aria-expanded={isOpen}
					disabled={loading}
				>
					{copied
						? __('Copié !', 'ai-forge-devtools')
						: __('Copier le prompt', 'ai-forge-devtools')}
				</Button>
			)}
			renderContent={({ onClose }) => (
				<MenuGroup>
					{PROVIDER_OPTIONS.map((option) => (
						<MenuItem
							key={option.id || 'base'}
							icon={option.icon}
							onClick={() => handleCopyPrompt(option.id, onClose)}
							disabled={loading && copiedProvider === option.id}
						>
							{option.label}
						</MenuItem>
					))}
				</MenuGroup>
			)}
		/>
	);
}

/**
 * Add "Copy Prompt" dropdown to TaskDetailModal footer
 */
export function addTaskDetailFooterActions(actions, context) {
	const isDevMode = window.aiforgeDevData?.isDevMode ?? false;
	if (!isDevMode) {
		return actions;
	}

	const { task } = context;

	// Only for markdown_to_gutenberg tasks
	if (task.task_type !== 'markdown_to_gutenberg') {
		return actions;
	}

	// Check if we have the necessary payloads (via LLM child task)
	const llmTask = getLlmChildTask(task);
	if (!llmTask) {
		return actions;
	}

	// Add "Copy Prompt" dropdown
	actions.push(
		<CopyPromptDropdown key="copy-prompt" taskId={task.id} />
	);

	return actions;
}
